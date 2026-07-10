<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captures a deliberately small set of high-value WordPress failures.
 */
final class Codegenie_Pulse_Reporter {
	/** @var Codegenie_Pulse_Client */
	private $client;

	/** @var Codegenie_Pulse_Options */
	private $options;

	/** @var Codegenie_Pulse_Redactor */
	private $redactor;

	/** @var bool */
	private $reporting = false;

	/** @var array<string, bool> */
	private $request_fingerprints = array();

	/** @var string|null */
	private $reserved_memory;

	/**
	 * @param Codegenie_Pulse_Client   $client   HTTP client.
	 * @param Codegenie_Pulse_Options  $options  Plugin options.
	 * @param Codegenie_Pulse_Redactor $redactor Privacy redactor.
	 */
	public function __construct( Codegenie_Pulse_Client $client, Codegenie_Pulse_Options $options, Codegenie_Pulse_Redactor $redactor ) {
		$this->client   = $client;
		$this->options  = $options;
		$this->redactor = $redactor;
	}

	/**
	 * Register automatic capture hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		$this->reserved_memory = str_repeat( 'x', 262144 );
		register_shutdown_function( array( $this, 'capture_shutdown_error' ) );

		if ( $this->options->get( 'capture_mail_failures', 1 ) ) {
			add_action( 'wp_mail_failed', array( $this, 'capture_mail_failure' ) );
		}

		if ( $this->options->get( 'capture_rest_errors', 1 ) ) {
			add_filter( 'rest_post_dispatch', array( $this, 'capture_rest_failure' ), 10, 3 );
		}
	}

	/**
	 * Report a caught Throwable.
	 *
	 * @param Throwable            $throwable Throwable.
	 * @param array<string, mixed> $context   Context.
	 * @return array<string, mixed>
	 */
	public function report_exception( $throwable, $context = array() ) {
		if ( ! $throwable instanceof Throwable ) {
			return $this->skipped( 'invalid_exception' );
		}

		$message = $this->redactor->text( $throwable->getMessage(), 2000 );

		return $this->send_payload(
			array(
				'level'           => 'error',
				'message'         => '' !== $message ? $message : 'Unhandled WordPress exception',
				'exception_class' => $this->redactor->text( get_class( $throwable ), 255 ),
				'file'            => $this->redactor->path( $throwable->getFile() ),
				'line'            => max( 1, (int) $throwable->getLine() ),
				'url'             => $this->redactor->current_url(),
				'method'          => $this->request_method(),
				'status_code'     => 500,
				'stacktrace'      => $this->redactor->text( $throwable->getTraceAsString(), 12000 ),
				'context'         => $this->base_context( $context ),
			)
		);
	}

	/**
	 * Report a custom application message.
	 *
	 * @param string               $message        Message.
	 * @param string               $level          Severity.
	 * @param array<string, mixed> $context        Context.
	 * @param bool                 $bypass_backoff Bypass local backoff for a manual test.
	 * @return array<string, mixed>
	 */
	public function report_message( $message, $level = 'error', $context = array(), $bypass_backoff = false ) {
		$allowed_levels = array( 'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency' );
		$level          = strtolower( $level );
		$level          = in_array( $level, $allowed_levels, true ) ? $level : 'error';

		$message = $this->redactor->text( $message, 2000 );

		return $this->send_payload(
			array(
				'level'           => $level,
				'message'         => '' !== $message ? $message : 'WordPress application event',
				'exception_class' => 'WordPressEvent',
				'url'             => $this->redactor->current_url(),
				'method'          => $this->request_method(),
				'context'         => $this->base_context( $context ),
			),
			$bypass_backoff
		);
	}

	/**
	 * Capture fatal PHP shutdown errors.
	 *
	 * @return void
	 */
	public function capture_shutdown_error() {
		$this->reserved_memory = null;

		if ( $this->reporting || ! $this->options->has_readable_dsn() ) {
			return;
		}

		$error = error_get_last();

		if ( ! is_array( $error ) || ! in_array( (int) $error['type'], $this->fatal_error_types(), true ) ) {
			return;
		}

		$this->send_payload(
			array(
				'level'           => 'critical',
				'message'         => $this->redactor->text( isset( $error['message'] ) ? $error['message'] : 'Fatal PHP error', 2000 ),
				'exception_class' => 'PHPFatalError',
				'file'            => $this->redactor->path( isset( $error['file'] ) ? $error['file'] : '' ),
				'line'            => max( 1, isset( $error['line'] ) ? (int) $error['line'] : 1 ),
				'url'             => $this->redactor->current_url(),
				'method'          => $this->request_method(),
				'status_code'     => 500,
				'context'         => $this->base_context(
					array(
						'capture_source' => 'php_shutdown',
						'php_error_type' => (int) $error['type'],
					)
				),
			)
		);
	}

	/**
	 * Capture WordPress mail failures without transmitting recipients or bodies.
	 *
	 * @param WP_Error $error WordPress mail error.
	 * @return void
	 */
	public function capture_mail_failure( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return;
		}

		$this->report_message(
			__( 'WordPress kon een e-mail niet verzenden.', 'codegenie-pulse-connector' ),
			'error',
			array(
				'capture_source' => 'wp_mail_failed',
				'error_codes'    => array_map( 'sanitize_key', $error->get_error_codes() ),
			)
		);
	}

	/**
	 * Capture REST responses with a server-error status.
	 *
	 * @param mixed           $response REST response.
	 * @param WP_REST_Server  $server   REST server.
	 * @param WP_REST_Request $request  REST request.
	 * @return mixed
	 */
	public function capture_rest_failure( $response, $server, $request ) {
		unset( $server );
		$status = 0;

		if ( is_wp_error( $response ) ) {
			$data = $response->get_error_data();

			if ( is_array( $data ) && isset( $data['status'] ) ) {
				$status = (int) $data['status'];
			}
		} elseif ( is_object( $response ) && method_exists( $response, 'get_status' ) ) {
			$status = (int) $response->get_status();
		}

		if ( $status < 500 || $status > 599 ) {
			return $response;
		}

		$route = is_object( $request ) && method_exists( $request, 'get_route' )
			? (string) $request->get_route()
			: '';
		$route_parts = array_values( array_filter( explode( '/', trim( $route, '/' ) ) ) );
		$namespace   = implode( '/', array_slice( $route_parts, 0, 2 ) );

		$this->send_payload(
			array(
				'level'           => 'error',
				'message'         => sprintf(
					/* translators: %d: HTTP status code. */
					__( 'De WordPress REST API gaf HTTP-status %d terug.', 'codegenie-pulse-connector' ),
					$status
				),
				'exception_class' => 'WordPressRestServerError',
				'url'             => $this->redactor->current_url(),
				'method'          => $this->request_method(),
				'status_code'     => $status,
				'context'         => $this->base_context(
					array(
						'capture_source' => 'rest_post_dispatch',
						'rest_namespace' => $namespace,
					)
				),
			)
		);

		return $response;
	}

	/**
	 * @param array<string, mixed> $payload        Payload.
	 * @param bool                 $bypass_backoff Bypass local backoff.
	 * @return array<string, mixed>
	 */
	private function send_payload( $payload, $bypass_backoff = false ) {
		if ( $this->reporting || ! $this->options->has_readable_dsn() ) {
			return $this->skipped( 'not_available' );
		}

		$fingerprint = hash(
			'sha256',
			(string) $payload['level'] . '|' . (string) $payload['message'] . '|' . (string) ( isset( $payload['file'] ) ? $payload['file'] : '' ) . '|' . (string) ( isset( $payload['line'] ) ? $payload['line'] : '' )
		);

		if ( isset( $this->request_fingerprints[ $fingerprint ] ) ) {
			return $this->skipped( 'duplicate_in_request' );
		}

		$this->request_fingerprints[ $fingerprint ] = true;
		$this->reporting                            = true;

		try {
			return $this->client->send_error( $this->without_empty_values( $payload ), $bypass_backoff );
		} finally {
			$this->reporting = false;
		}
	}

	/**
	 * @param array<string, mixed> $context Extra context.
	 * @return array<string, mixed>
	 */
	private function base_context( $context ) {
		$base = array(
			'wordpress_version' => get_bloginfo( 'version' ),
			'php_version'       => PHP_VERSION,
			'connector_version' => CODEGENIE_PULSE_CONNECTOR_VERSION,
			'is_multisite'      => is_multisite(),
			'request_type'      => $this->request_type(),
		);

		return $this->redactor->context( array_merge( $base, (array) $context ) );
	}

	/**
	 * @return string|null
	 */
	private function request_method() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';

		return preg_match( '/^[A-Z]{3,16}$/', $method ) ? $method : null;
	}

	/**
	 * @return string
	 */
	private function request_type() {
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return 'cron';
		}

		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return 'ajax';
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'rest';
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}

		return is_admin() ? 'admin' : 'frontend';
	}

	/**
	 * @return int[]
	 */
	private function fatal_error_types() {
		return array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR );
	}

	/**
	 * Remove null and empty-string optional fields.
	 *
	 * @param array<string, mixed> $payload Payload.
	 * @return array<string, mixed>
	 */
	private function without_empty_values( $payload ) {
		return array_filter(
			$payload,
			static function ( $value ) {
				return null !== $value && '' !== $value && array() !== $value;
			}
		);
	}

	/**
	 * @param string $code Skip reason.
	 * @return array<string, mixed>
	 */
	private function skipped( $code ) {
		return array(
			'success'     => false,
			'code'        => $code,
			'message'     => __( 'De gebeurtenis werd niet verzonden.', 'codegenie-pulse-connector' ),
			'http_status' => 0,
		);
	}
}
