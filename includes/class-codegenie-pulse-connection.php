<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Implements discovery and the one-time Pulse-to-WordPress authorization flow.
 */
final class Codegenie_Pulse_Connection {
	const CONNECTOR_ID     = 'codegenie-pulse-connector-wordpress';
	const PROTOCOL_VERSION = 1;
	const REST_NAMESPACE   = 'codegenie-pulse/v1';
	const REST_ROUTE       = '/discovery';

	/** @var Codegenie_Pulse_Options */
	private $options;

	/**
	 * @param Codegenie_Pulse_Options $options Plugin options.
	 */
	public function __construct( Codegenie_Pulse_Options $options ) {
		$this->options = $options;
	}

	/**
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_rest_route' ) );
	}

	/**
	 * @return void
	 */
	public function register_rest_route() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'discovery' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'pulse_origin' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * Return a secret-free connector contract plus an out-of-band site proof.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function discovery( $request ) {
		$pulse_origin = $this->normalize_origin( (string) $request->get_param( 'pulse_origin' ) );

		if ( is_wp_error( $pulse_origin ) ) {
			return $pulse_origin;
		}

		$challenge_id = wp_generate_password( 48, false, false );
		$site_proof   = $this->site_proof( $challenge_id, $pulse_origin );

		if ( is_wp_error( $site_proof ) ) {
			return $site_proof;
		}

		$response = rest_ensure_response(
			array(
				'connector'         => self::CONNECTOR_ID,
				'protocol_version'  => self::PROTOCOL_VERSION,
				'connector_version' => CODEGENIE_PULSE_CONNECTOR_VERSION,
				'site_url'          => untrailingslashit( home_url( '/' ) ),
				'authorize_url'     => admin_url( 'options-general.php?page=codegenie-pulse-connector' ),
				'challenge_id'      => $challenge_id,
				'site_proof'        => $site_proof,
				'capabilities'      => array(
					'website_verification' => true,
					'error_monitoring'     => true,
					'deployment_tracking'  => true,
				),
			)
		);

		$response->header( 'Cache-Control', 'no-store, private' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );

		return $response;
	}

	/**
	 * Exchange the one-time request after a WordPress administrator consents.
	 *
	 * @param string $pulse_origin Pulse origin.
	 * @param string $request_token One-time request token.
	 * @param string $challenge_id Discovery challenge.
	 * @return array<string, mixed>|WP_Error
	 */
	public function exchange( $pulse_origin, $request_token, $challenge_id ) {
		$pulse_origin = $this->normalize_origin( $pulse_origin );

		if ( is_wp_error( $pulse_origin ) ) {
			return $pulse_origin;
		}

		if ( 1 !== preg_match( '/^[A-Za-z0-9]{64}$/', $request_token ) || 1 !== preg_match( '/^[A-Za-z0-9]{48}$/', $challenge_id ) ) {
			return $this->error( 'invalid_authorization', __( 'De eenmalige koppelingsaanvraag is ongeldig. Start opnieuw in Codegenie Pulse.', 'codegenie-pulse-connector' ) );
		}

		$site_proof = $this->site_proof( $challenge_id, $pulse_origin );

		if ( is_wp_error( $site_proof ) ) {
			return $site_proof;
		}

		$payload = array(
			'connector'         => self::CONNECTOR_ID,
			'protocol_version'  => self::PROTOCOL_VERSION,
			'request_token'     => $request_token,
			'challenge_id'      => $challenge_id,
			'site_proof'        => $site_proof,
			'site_url'          => untrailingslashit( home_url( '/' ) ),
			'site_name'         => $this->site_name(),
			'connector_version' => CODEGENIE_PULSE_CONNECTOR_VERSION,
			'wordpress_version' => (string) get_bloginfo( 'version' ),
			'php_version'       => PHP_VERSION,
			'environment'       => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
			'is_multisite'      => is_multisite(),
		);
		$json    = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( ! is_string( $json ) ) {
			return $this->error( 'json_encode_failed', __( 'De koppelingsaanvraag kon niet veilig worden voorbereid.', 'codegenie-pulse-connector' ) );
		}

		$timeout  = (float) apply_filters( 'codegenie_pulse_connection_timeout', 10.0 );
		$timeout  = max( 3.0, min( 15.0, $timeout ) );
		$endpoint = $pulse_origin . '/api/connectors/wordpress/exchange';
		$response = wp_safe_remote_post(
			$endpoint,
			array(
				'timeout'     => $timeout,
				'redirection' => 0,
				'headers'     => array(
					'Accept'                   => 'application/json',
					'Content-Type'             => 'application/json; charset=utf-8',
					'Content-Length'           => (string) strlen( $json ),
					'X-Codegenie-Pulse-Client' => 'wordpress/' . CODEGENIE_PULSE_CONNECTOR_VERSION,
				),
				'body'        => $json,
				'data_format' => 'body',
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->error( 'network_error', __( 'Codegenie Pulse kon niet veilig worden bereikt. Probeer de koppeling opnieuw.', 'codegenie-pulse-connector' ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );

		if ( strlen( $body ) > 32768 ) {
			return $this->error( 'response_too_large', __( 'Codegenie Pulse gaf een onverwacht grote response terug.', 'codegenie-pulse-connector' ) );
		}

		$data = json_decode( $body, true );

		if ( $status < 200 || $status >= 300 ) {
			return $this->error( 'exchange_rejected', $this->exchange_error_message( $status ) );
		}

		if ( ! is_array( $data ) || ! isset( $data['connection'] ) || ! is_array( $data['connection'] ) ) {
			return $this->error( 'invalid_response', __( 'Codegenie Pulse gaf geen geldige configuratie terug.', 'codegenie-pulse-connector' ) );
		}

		$connection = $data['connection'];

		if ( ! isset( $connection['pulse_origin'] ) || ! is_string( $connection['pulse_origin'] ) || ! $this->same_origin( $connection['pulse_origin'], $pulse_origin ) ) {
			return $this->error( 'exchange_origin_mismatch', __( 'De ontvangen configuratie hoort niet bij de gekozen Codegenie Pulse-installatie. Start de koppeling opnieuw.', 'codegenie-pulse-connector' ) );
		}

		$provision = $this->options->provision( $connection );

		if ( is_wp_error( $provision ) ) {
			return $provision;
		}

		$dashboard_url = isset( $connection['dashboard_url'] ) && is_string( $connection['dashboard_url'] )
			? $connection['dashboard_url']
			: '';

		if ( '' !== $dashboard_url && ! $this->same_origin( $dashboard_url, $pulse_origin ) ) {
			$dashboard_url = '';
		}

		return array(
			'success'       => true,
			'dashboard_url' => $dashboard_url,
			'message'       => __( 'WordPress is gekoppeld met Codegenie Pulse.', 'codegenie-pulse-connector' ),
		);
	}

	/**
	 * Return a local, token-safe message for a rejected one-time exchange.
	 *
	 * Remote response text is deliberately not surfaced because it can reflect
	 * the request token or other one-time authorization material.
	 *
	 * @param int $status HTTP status.
	 * @return string
	 */
	private function exchange_error_message( $status ) {
		switch ( (int) $status ) {
			case 401:
			case 403:
			case 404:
			case 409:
			case 410:
			case 422:
				return __( 'De eenmalige koppelingsaanvraag is ongeldig, verlopen of al gebruikt. Start de koppeling opnieuw in Codegenie Pulse.', 'codegenie-pulse-connector' );
			case 429:
				return __( 'Codegenie Pulse verwerkt tijdelijk te veel aanvragen. Wacht even en start de koppeling daarna opnieuw.', 'codegenie-pulse-connector' );
			default:
				return (int) $status >= 500
					? __( 'Codegenie Pulse is tijdelijk niet beschikbaar. Probeer de koppeling later opnieuw.', 'codegenie-pulse-connector' )
					: __( 'Codegenie Pulse heeft de koppelingsaanvraag geweigerd. Start de koppeling opnieuw.', 'codegenie-pulse-connector' );
		}
	}

	/**
	 * Compute the proof that stays outside the browser redirect.
	 *
	 * @param string $challenge_id Challenge.
	 * @param string $pulse_origin Pulse origin.
	 * @return string|WP_Error
	 */
	public function site_proof( $challenge_id, $pulse_origin ) {
		if ( 1 !== preg_match( '/^[A-Za-z0-9]{48}$/', $challenge_id ) ) {
			return $this->error( 'invalid_challenge', __( 'De WordPress-koppelingschallenge is ongeldig.', 'codegenie-pulse-connector' ) );
		}

		$normalized_origin = $this->normalize_origin( $pulse_origin );

		if ( is_wp_error( $normalized_origin ) ) {
			return $normalized_origin;
		}

		$input = implode(
			"\n",
			array(
				'v1',
				$challenge_id,
				$normalized_origin,
				$this->site_origin(),
			)
		);

		return hash_hmac( 'sha256', $input, wp_salt( 'auth' ) );
	}

	/**
	 * Validate and canonicalize a platform origin.
	 *
	 * @param string $origin Origin.
	 * @return string|WP_Error
	 */
	public function normalize_origin( $origin ) {
		if ( strlen( $origin ) > 2048 || false !== strpos( $origin, "\n" ) || false !== strpos( $origin, "\r" ) ) {
			return $this->invalid_origin();
		}

		$parts = wp_parse_url( trim( $origin ) );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return $this->invalid_origin();
		}

		$scheme         = strtolower( (string) $parts['scheme'] );
		$allow_insecure = (bool) apply_filters( 'codegenie_pulse_allow_insecure_platform_origin', false, $origin );

		if ( 'https' !== $scheme && ! ( $allow_insecure && 'http' === $scheme ) ) {
			return $this->invalid_origin();
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return $this->invalid_origin();
		}

		$path = isset( $parts['path'] ) ? trim( (string) $parts['path'] ) : '';

		if ( '' !== $path && '/' !== $path ) {
			return $this->invalid_origin();
		}

		$port       = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$normalized = $scheme . '://' . strtolower( (string) $parts['host'] ) . $port;

		if ( false === wp_http_validate_url( $normalized . '/' ) ) {
			return $this->invalid_origin();
		}

		return $normalized;
	}

	/**
	 * @return string
	 */
	private function site_origin() {
		$parts = wp_parse_url( home_url( '/' ) );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';

		return strtolower( (string) $parts['scheme'] ) . '://' . strtolower( (string) $parts['host'] ) . $port;
	}

	/**
	 * @return string
	 */
	private function site_name() {
		$name = wp_strip_all_tags( (string) get_bloginfo( 'name' ) );

		return substr( trim( $name ), 0, 191 );
	}

	/**
	 * @param string $first_url First URL.
	 * @param string $second_url Second URL or origin.
	 * @return bool
	 */
	private function same_origin( $first_url, $second_url ) {
		$first  = wp_parse_url( $first_url );
		$second = wp_parse_url( $second_url );

		if ( ! is_array( $first ) || ! is_array( $second ) ) {
			return false;
		}

		$first_port  = isset( $first['port'] ) ? (int) $first['port'] : ( 'https' === strtolower( (string) $first['scheme'] ) ? 443 : 80 );
		$second_port = isset( $second['port'] ) ? (int) $second['port'] : ( 'https' === strtolower( (string) $second['scheme'] ) ? 443 : 80 );

		return strtolower( (string) ( $first['scheme'] ?? '' ) ) === strtolower( (string) ( $second['scheme'] ?? '' ) )
			&& strtolower( (string) ( $first['host'] ?? '' ) ) === strtolower( (string) ( $second['host'] ?? '' ) )
			&& $first_port === $second_port;
	}

	/**
	 * @return WP_Error
	 */
	private function invalid_origin() {
		return $this->error( 'invalid_platform_origin', __( 'De Codegenie Pulse URL is ongeldig of niet veilig.', 'codegenie-pulse-connector' ) );
	}

	/**
	 * @param string $code Error code.
	 * @param string $message Safe message.
	 * @return WP_Error
	 */
	private function error( $code, $message ) {
		return new WP_Error( 'codegenie_pulse_' . sanitize_key( $code ), $message );
	}
}
