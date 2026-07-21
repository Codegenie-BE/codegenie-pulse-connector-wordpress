<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captures a configurable and bounded set of WordPress and PHP failures.
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

	/** @var callable|null */
	private $previous_error_handler;

	/** @var int */
	private $non_fatal_events = 0;

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

		if ( in_array( $this->options->capture_mode(), array( Codegenie_Pulse_Options::CAPTURE_EXTENDED, Codegenie_Pulse_Options::CAPTURE_DEBUG ), true ) ) {
			// The configured error-capture feature deliberately chains PHP's existing handler.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
			$this->previous_error_handler = set_error_handler(
				array( $this, 'capture_php_error' ),
				E_ALL
			);
		}

		if ( $this->options->get( 'capture_mail_failures', 1 ) ) {
			add_action( 'wp_mail_failed', array( $this, 'capture_mail_failure' ) );
		}

		if ( $this->options->get( 'capture_rest_errors', 1 ) ) {
			add_filter( 'rest_post_dispatch', array( $this, 'capture_rest_failure' ), 10, 3 );
		}
	}

	/**
	 * Capture selected non-fatal PHP errors while preserving the existing handler.
	 *
	 * @param int    $severity PHP error constant.
	 * @param string $message  Error message.
	 * @param string $file     Source file.
	 * @param int    $line     Source line.
	 * @return bool
	 */
	public function capture_php_error( $severity, $message, $file = '', $line = 0 ) {
		$arguments = func_get_args();
		$severity  = (int) $severity;

		if ( ! $this->should_capture_non_fatal( $severity ) ) {
			return $this->delegate_to_previous_handler( $arguments );
		}

		$level   = $this->level_for_php_error( $severity );
		$message = $this->redactor->text( $message, 2000 );
		$payload = array(
			'level'           => $level,
			'message'         => '' !== $message ? $message : 'WordPress PHP error',
			'exception_class' => $this->class_for_php_error( $severity ),
			'file'            => $this->redactor->path( $file ),
			'line'            => max( 1, (int) $line ),
			'url'             => $this->redactor->current_url(),
			'method'          => $this->request_method(),
			'context'         => $this->base_context(
				array(
					'capture_source' => 'php_error_handler',
					'php_error_type' => $severity,
					'php_error_name' => $this->name_for_php_error( $severity ),
				)
			),
		);

		try {
			$this->send_payload( $payload, false, true );
		} catch ( Throwable $throwable ) {
			// Monitoring must never change the website's existing error behavior.
			unset( $throwable );
		}

		return $this->delegate_to_previous_handler( $arguments );
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

		$route       = is_object( $request ) && method_exists( $request, 'get_route' )
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
	private function send_payload( $payload, $bypass_backoff = false, $non_fatal = false ) {
		if ( $this->reporting || ! $this->options->has_readable_dsn() ) {
			return $this->skipped( 'not_available' );
		}

		$fingerprint = $this->payload_fingerprint( $payload );

		if ( isset( $this->request_fingerprints[ $fingerprint ] ) ) {
			return $this->skipped( 'duplicate_in_request' );
		}

		if ( $non_fatal && $this->was_recently_sampled( $fingerprint ) ) {
			return $this->skipped( 'recently_sampled' );
		}

		$this->request_fingerprints[ $fingerprint ] = true;

		if ( $non_fatal ) {
			++$this->non_fatal_events;
		}

		$this->reporting = true;

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
			'capture_mode'      => $this->options->capture_mode(),
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
	 * @param int $severity PHP error constant.
	 * @return bool
	 */
	private function should_capture_non_fatal( $severity ) {
		if ( $this->reporting || ! $this->options->has_readable_dsn() ) {
			return false;
		}

		// Read the configured mask without changing it; suppressed errors must retain existing behavior.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting,WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting
		if ( 0 === ( error_reporting() & $severity ) ) {
			return false;
		}

		if ( ! in_array( $severity, $this->non_fatal_error_types(), true ) ) {
			return false;
		}

		$limit = (int) apply_filters( 'codegenie_pulse_non_fatal_per_request_limit', 10 );
		$limit = max( 1, min( 50, $limit ) );

		return $this->non_fatal_events < $limit;
	}

	/**
	 * @return int[]
	 */
	private function non_fatal_error_types() {
		$warnings = array( E_WARNING, E_USER_WARNING );

		if ( Codegenie_Pulse_Options::CAPTURE_EXTENDED === $this->options->capture_mode() ) {
			return $warnings;
		}

		if ( Codegenie_Pulse_Options::CAPTURE_DEBUG === $this->options->capture_mode() ) {
			$debug_types = array_merge(
				$warnings,
				array( E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED )
			);

			// E_STRICT (2048) was deprecated and removed as a distinct level in PHP 8.4.
			if ( PHP_VERSION_ID < 80400 ) {
				$debug_types[] = 2048;
			}

			return $debug_types;
		}

		return array();
	}

	/**
	 * @param int $severity PHP error constant.
	 * @return string
	 */
	private function level_for_php_error( $severity ) {
		return in_array( $severity, array( E_WARNING, E_USER_WARNING ), true ) ? 'warning' : 'notice';
	}

	/**
	 * @param int $severity PHP error constant.
	 * @return string
	 */
	private function class_for_php_error( $severity ) {
		switch ( $severity ) {
			case E_WARNING:
				return 'PHPWarning';
			case E_USER_WARNING:
				return 'PHPUserWarning';
			case E_NOTICE:
				return 'PHPNotice';
			case E_USER_NOTICE:
				return 'PHPUserNotice';
			case E_DEPRECATED:
				return 'PHPDeprecated';
			case E_USER_DEPRECATED:
				return 'PHPUserDeprecated';
			case 2048: // E_STRICT on PHP versions before 8.4.
				return 'PHPStrict';
			default:
				return 'PHPError';
		}
	}

	/**
	 * @param int $severity PHP error constant.
	 * @return string
	 */
	private function name_for_php_error( $severity ) {
		switch ( $severity ) {
			case E_WARNING:
				return 'E_WARNING';
			case E_USER_WARNING:
				return 'E_USER_WARNING';
			case E_NOTICE:
				return 'E_NOTICE';
			case E_USER_NOTICE:
				return 'E_USER_NOTICE';
			case E_DEPRECATED:
				return 'E_DEPRECATED';
			case E_USER_DEPRECATED:
				return 'E_USER_DEPRECATED';
			case 2048: // E_STRICT on PHP versions before 8.4.
				return 'E_STRICT';
			default:
				return 'E_UNKNOWN';
		}
	}

	/**
	 * @param array<int, mixed> $arguments Original error-handler arguments.
	 * @return bool
	 */
	private function delegate_to_previous_handler( $arguments ) {
		if ( is_callable( $this->previous_error_handler ) ) {
			return (bool) call_user_func_array( $this->previous_error_handler, $arguments );
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $payload Event payload.
	 * @return string
	 */
	private function payload_fingerprint( $payload ) {
		return hash(
			'sha256',
			(string) $payload['level'] . '|' . (string) $payload['message'] . '|' . (string) ( isset( $payload['file'] ) ? $payload['file'] : '' ) . '|' . (string) ( isset( $payload['line'] ) ? $payload['line'] : '' )
		);
	}

	/**
	 * Sample identical non-fatal errors across requests to prevent eventstorms.
	 *
	 * @param string $fingerprint Event fingerprint.
	 * @return bool True when the event was already sampled recently.
	 */
	private function was_recently_sampled( $fingerprint ) {
		$window  = (int) apply_filters( 'codegenie_pulse_non_fatal_sample_seconds', 60 );
		$window  = max( 1, min( 3600, $window ) );
		$now     = time();
		$stored  = get_transient( Codegenie_Pulse_Options::SAMPLE_KEY );
		$samples = is_array( $stored ) ? $stored : array();

		foreach ( $samples as $key => $timestamp ) {
			if ( ! is_numeric( $timestamp ) || (int) $timestamp < $now - $window ) {
				unset( $samples[ $key ] );
			}
		}

		if ( isset( $samples[ $fingerprint ] ) ) {
			return true;
		}

		$samples[ $fingerprint ] = $now;
		arsort( $samples );
		$samples = array_slice( $samples, 0, 50, true );

		set_transient( Codegenie_Pulse_Options::SAMPLE_KEY, $samples, max( 60, $window * 2 ) );

		return false;
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
