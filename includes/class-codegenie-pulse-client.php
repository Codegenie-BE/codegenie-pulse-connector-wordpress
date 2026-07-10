<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small HTTP client for the existing Codegenie Pulse ingestion contract.
 */
final class Codegenie_Pulse_Client {
	/** @var Codegenie_Pulse_Options */
	private $options;

	/**
	 * @param Codegenie_Pulse_Options $options Plugin options.
	 */
	public function __construct( Codegenie_Pulse_Options $options ) {
		$this->options = $options;
	}

	/**
	 * Send an application error event.
	 *
	 * @param array<string, mixed> $payload Payload.
	 * @param bool                 $bypass_backoff Whether to bypass local backoff.
	 * @return array<string, mixed>
	 */
	public function send_error( $payload, $bypass_backoff = false ) {
		return $this->send( $this->options->dsn(), $payload, 'error', $bypass_backoff );
	}

	/**
	 * Send a WordPress deployment event using the same source token.
	 *
	 * @param array<string, mixed> $payload Payload.
	 * @return array<string, mixed>
	 */
	public function send_deployment( $payload ) {
		$dsn = $this->options->dsn();

		if ( '' === $dsn ) {
			return $this->result( false, 'not_configured', __( 'De connector is nog niet geconfigureerd.', 'codegenie-pulse-connector' ) );
		}

		$endpoint = preg_replace( '#/api/ingest/errors/#', '/api/ingest/deployments/', $dsn, 1 );

		if ( ! is_string( $endpoint ) || $endpoint === $dsn ) {
			return $this->result( false, 'invalid_dsn', __( 'De opgeslagen DSN is ongeldig.', 'codegenie-pulse-connector' ) );
		}

		return $this->send( $endpoint, $payload, 'deployment', false );
	}

	/**
	 * @param string               $endpoint       Ingestion endpoint.
	 * @param array<string, mixed> $payload        JSON payload.
	 * @param string               $kind           Event kind.
	 * @param bool                 $bypass_backoff Whether to bypass local backoff.
	 * @return array<string, mixed>
	 */
	private function send( $endpoint, $payload, $kind, $bypass_backoff ) {
		if ( '' === $endpoint ) {
			return $this->result( false, 'not_configured', __( 'De connector is nog niet geconfigureerd.', 'codegenie-pulse-connector' ) );
		}

		if ( ! $bypass_backoff && $this->is_backing_off( $kind ) ) {
			return $this->result( false, 'backoff', __( 'De connector wacht kort na een eerdere leveringsfout.', 'codegenie-pulse-connector' ) );
		}

		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( ! is_string( $json ) ) {
			return $this->record_failure(
				$kind,
				0,
				'json_encode_failed',
				__( 'De foutmelding kon niet als JSON worden voorbereid.', 'codegenie-pulse-connector' )
			);
		}

		$timeout = (float) apply_filters( 'codegenie_pulse_http_timeout', 3.0 );
		$timeout = max( 1.0, min( 10.0, $timeout ) );

		$response = wp_safe_remote_post(
			$endpoint,
			array(
				'timeout'     => $timeout,
				'redirection' => 0,
				'headers'     => array(
					'Accept'                     => 'application/json',
					'Content-Type'               => 'application/json; charset=utf-8',
					'Content-Length'             => (string) strlen( $json ),
					'X-Codegenie-Pulse-Client'   => 'wordpress/' . CODEGENIE_PULSE_CONNECTOR_VERSION,
				),
				'body'        => $json,
				'data_format' => 'body',
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->start_backoff( $kind, MINUTE_IN_SECONDS );

			return $this->record_failure(
				$kind,
				0,
				'network_error',
				__( 'Codegenie Pulse kon niet veilig worden bereikt.', 'codegenie-pulse-connector' )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( $status >= 200 && $status < 300 ) {
			delete_transient( $this->backoff_key( $kind ) );

			return $this->record_success( $kind, $status );
		}

		if ( 429 === $status ) {
			$this->start_backoff( $kind, MINUTE_IN_SECONDS );
		} elseif ( in_array( $status, array( 401, 403, 404, 410 ), true ) ) {
			$this->start_backoff( $kind, 15 * MINUTE_IN_SECONDS );
		} elseif ( $status >= 500 || 0 === $status ) {
			$this->start_backoff( $kind, MINUTE_IN_SECONDS );
		}

		return $this->record_failure( $kind, $status, 'http_' . $status, $this->http_error_message( $status ) );
	}

	/**
	 * @return bool
	 */
	private function is_backing_off( $kind ) {
		$until = get_transient( $this->backoff_key( $kind ) );

		return is_numeric( $until ) && (int) $until > time();
	}

	/**
	 * @param string $kind    Event kind.
	 * @param int    $seconds Backoff seconds.
	 * @return void
	 */
	private function start_backoff( $kind, $seconds ) {
		set_transient(
			$this->backoff_key( $kind ),
			time() + (int) $seconds,
			(int) $seconds
		);
	}

	/**
	 * @param string $kind Event kind.
	 * @return string
	 */
	private function backoff_key( $kind ) {
		return Codegenie_Pulse_Options::BACKOFF_KEY . '_' . sanitize_key( $kind );
	}

	/**
	 * @param string $kind   Event kind.
	 * @param int    $status HTTP status.
	 * @return array<string, mixed>
	 */
	private function record_success( $kind, $status ) {
		$state                          = $this->options->state();
		$state['last_success_at']       = gmdate( 'c' );
		$state['last_success_kind']     = $kind;
		$state['last_http_status']      = $status;
		$state[ 'last_' . $kind . '_success_at' ]      = $state['last_success_at'];
		$state[ 'last_' . $kind . '_failure_message' ] = '';
		$state[ 'last_' . $kind . '_failure_code' ]    = '';

		if ( isset( $state['last_failure_kind'] ) && $kind === $state['last_failure_kind'] ) {
			$state['last_failure_message'] = '';
			$state['last_failure_code']    = '';
		}

		$this->options->update_state( $state );

		do_action( 'codegenie_pulse_delivery_succeeded', $kind, $status );

		return $this->result( true, 'accepted', __( 'De gebeurtenis werd door Codegenie Pulse aanvaard.', 'codegenie-pulse-connector' ), $status );
	}

	/**
	 * @param string $kind    Event kind.
	 * @param int    $status  HTTP status.
	 * @param string $code    Safe error code.
	 * @param string $message Safe user message.
	 * @return array<string, mixed>
	 */
	private function record_failure( $kind, $status, $code, $message ) {
		$state                         = $this->options->state();
		$state['last_failure_at']      = gmdate( 'c' );
		$state['last_failure_kind']    = $kind;
		$state['last_failure_code']    = $code;
		$state['last_failure_message'] = $message;
		$state['last_http_status']     = $status;
		$state[ 'last_' . $kind . '_failure_at' ]      = $state['last_failure_at'];
		$state[ 'last_' . $kind . '_failure_code' ]    = $code;
		$state[ 'last_' . $kind . '_failure_message' ] = $message;

		$this->options->update_state( $state );

		do_action( 'codegenie_pulse_delivery_failed', $kind, $status, $code );

		return $this->result( false, $code, $message, $status );
	}

	/**
	 * @param bool   $success Success state.
	 * @param string $code    Result code.
	 * @param string $message Safe message.
	 * @param int    $status  HTTP status.
	 * @return array<string, mixed>
	 */
	private function result( $success, $code, $message, $status = 0 ) {
		return array(
			'success'     => (bool) $success,
			'code'        => (string) $code,
			'message'     => (string) $message,
			'http_status' => (int) $status,
		);
	}

	/**
	 * @param int $status HTTP status.
	 * @return string
	 */
	private function http_error_message( $status ) {
		switch ( $status ) {
			case 401:
				return __( 'De DSN-token is ongeldig of niet meer actief.', 'codegenie-pulse-connector' );
			case 403:
				return __( 'Het account of plan laat deze koppeling niet toe.', 'codegenie-pulse-connector' );
			case 404:
				return __( 'Het ingestie-endpoint of de token werd niet gevonden.', 'codegenie-pulse-connector' );
			case 410:
				return __( 'De gekoppelde website of foutbron is gearchiveerd.', 'codegenie-pulse-connector' );
			case 411:
				return __( 'Het platform weigerde de aanvraag omdat de berichtlengte ontbrak.', 'codegenie-pulse-connector' );
			case 413:
				return __( 'De gebeurtenis is groter dan de toegestane platformlimiet.', 'codegenie-pulse-connector' );
			case 422:
				return __( 'Het platform heeft de gebeurtenis inhoudelijk geweigerd.', 'codegenie-pulse-connector' );
			case 429:
				return __( 'De ingestie- of maandlimiet is bereikt. De connector probeert later opnieuw.', 'codegenie-pulse-connector' );
			default:
				return $status >= 500
					? __( 'Codegenie Pulse is tijdelijk niet beschikbaar.', 'codegenie-pulse-connector' )
					: __( 'Codegenie Pulse heeft de gebeurtenis geweigerd.', 'codegenie-pulse-connector' );
		}
	}
}
