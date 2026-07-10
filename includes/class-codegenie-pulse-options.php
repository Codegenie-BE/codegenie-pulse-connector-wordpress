<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns connector settings and strict DSN validation.
 */
final class Codegenie_Pulse_Options {
	const OPTION_NAME = 'codegenie_pulse_connector_settings';
	const STATE_NAME  = 'codegenie_pulse_connector_state';
	const BACKOFF_KEY = 'codegenie_pulse_connector_backoff';

	/** @var Codegenie_Pulse_Secret_Store */
	private $secret_store;

	/**
	 * @param Codegenie_Pulse_Secret_Store $secret_store Secret store.
	 */
	public function __construct( Codegenie_Pulse_Secret_Store $secret_store ) {
		$this->secret_store = $secret_store;
	}

	/**
	 * Default plugin settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'encrypted_dsn'             => '',
			'verification_token'        => '',
			'automatic_error_reporting' => 1,
			'capture_mail_failures'     => 1,
			'capture_rest_errors'       => 1,
			'deployment_tracking'       => 1,
		);
	}

	/**
	 * Add defaults without autoloading the secret on every request.
	 *
	 * @return void
	 */
	public static function install_defaults() {
		add_option( self::OPTION_NAME, self::defaults(), '', 'no' );
		add_option( self::STATE_NAME, array(), '', 'no' );
	}

	/**
	 * Get all settings.
	 *
	 * @return array<string, mixed>
	 */
	public function all() {
		$value = get_option( self::OPTION_NAME, array() );

		return wp_parse_args( is_array( $value ) ? $value : array(), self::defaults() );
	}

	/**
	 * Get a setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$settings = $this->all();

		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/**
	 * Get the decrypted ingestion DSN.
	 *
	 * @return string
	 */
	public function dsn() {
		return $this->secret_store->decrypt( (string) $this->get( 'encrypted_dsn', '' ) );
	}

	/**
	 * Whether a DSN value is stored.
	 *
	 * @return bool
	 */
	public function has_stored_dsn() {
		return '' !== (string) $this->get( 'encrypted_dsn', '' );
	}

	/**
	 * Whether the stored DSN can currently be decrypted.
	 *
	 * @return bool
	 */
	public function has_readable_dsn() {
		return '' !== $this->dsn();
	}

	/**
	 * Save settings from the admin form.
	 *
	 * @param array<string, mixed> $input Settings input.
	 * @return true|WP_Error
	 */
	public function save( $input ) {
		$current = $this->all();
		$next    = $current;

		$verification_token = isset( $input['verification_token'] )
			? trim( (string) $input['verification_token'] )
			: '';

		if ( '' !== $verification_token && 1 !== preg_match( '/^[A-Za-z0-9]{32,128}$/', $verification_token ) ) {
			return new WP_Error(
				'codegenie_pulse_invalid_verification_token',
				__( 'Het websiteverificatietoken heeft geen geldig formaat.', 'codegenie-pulse-connector' )
			);
		}

		$next['verification_token']        = $verification_token;
		$next['automatic_error_reporting'] = empty( $input['automatic_error_reporting'] ) ? 0 : 1;
		$next['capture_mail_failures']     = empty( $input['capture_mail_failures'] ) ? 0 : 1;
		$next['capture_rest_errors']       = empty( $input['capture_rest_errors'] ) ? 0 : 1;
		$next['deployment_tracking']       = empty( $input['deployment_tracking'] ) ? 0 : 1;

		$new_dsn = isset( $input['dsn'] ) ? trim( (string) $input['dsn'] ) : '';

		if ( '' !== $new_dsn ) {
			$validated_dsn = $this->validate_dsn( $new_dsn );

			if ( is_wp_error( $validated_dsn ) ) {
				return $validated_dsn;
			}

			$encrypted = $this->secret_store->encrypt( $validated_dsn );

			if ( is_wp_error( $encrypted ) ) {
				return $encrypted;
			}

			$next['encrypted_dsn'] = $encrypted;
		}

		update_option( self::OPTION_NAME, $next, false );

		if ( $next['encrypted_dsn'] !== $current['encrypted_dsn'] ) {
			delete_option( self::STATE_NAME );
			delete_transient( self::BACKOFF_KEY );
			delete_transient( self::BACKOFF_KEY . '_error' );
			delete_transient( self::BACKOFF_KEY . '_deployment' );
		}

		return true;
	}

	/**
	 * Remove the ingestion DSN and connection state.
	 *
	 * @return void
	 */
	public function disconnect() {
		$settings                  = $this->all();
		$settings['encrypted_dsn'] = '';

		update_option( self::OPTION_NAME, $settings, false );
		delete_option( self::STATE_NAME );
		delete_transient( self::BACKOFF_KEY );
		delete_transient( self::BACKOFF_KEY . '_error' );
		delete_transient( self::BACKOFF_KEY . '_deployment' );
	}

	/**
	 * Validate and normalize the platform ingestion DSN.
	 *
	 * @param string $dsn DSN from Codegenie Pulse.
	 * @return string|WP_Error
	 */
	public function validate_dsn( $dsn ) {
		if ( strlen( $dsn ) > 2048 || false !== strpos( $dsn, "\n" ) || false !== strpos( $dsn, "\r" ) ) {
			return $this->invalid_dsn();
		}

		$parts = wp_parse_url( $dsn );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || empty( $parts['path'] ) ) {
			return $this->invalid_dsn();
		}

		$scheme         = strtolower( (string) $parts['scheme'] );
		$allow_insecure = (bool) apply_filters( 'codegenie_pulse_allow_insecure_dsn', false, $dsn );

		if ( 'https' !== $scheme && ! ( $allow_insecure && 'http' === $scheme ) ) {
			return new WP_Error(
				'codegenie_pulse_https_required',
				__( 'Gebruik een HTTPS-DSN uit Codegenie Pulse.', 'codegenie-pulse-connector' )
			);
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return $this->invalid_dsn();
		}

		if ( 1 !== preg_match( '#/api/ingest/errors/[A-Za-z0-9]{64}/?$#D', (string) $parts['path'] ) ) {
			return $this->invalid_dsn();
		}

		if ( false === wp_http_validate_url( $dsn ) ) {
			return new WP_Error(
				'codegenie_pulse_unsafe_dsn',
				__( 'Deze DSN wijst niet naar een veilige publieke URL.', 'codegenie-pulse-connector' )
			);
		}

		return untrailingslashit( $dsn );
	}

	/**
	 * Return a token-safe DSN label for the admin UI.
	 *
	 * @return string
	 */
	public function masked_dsn() {
		$dsn = $this->dsn();

		if ( '' === $dsn ) {
			return $this->has_stored_dsn()
				? __( 'Opnieuw koppelen vereist', 'codegenie-pulse-connector' )
				: __( 'Niet geconfigureerd', 'codegenie-pulse-connector' );
		}

		$parts = wp_parse_url( $dsn );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['path'] ) ) {
			return __( 'Geconfigureerd', 'codegenie-pulse-connector' );
		}

		$token_suffix = '';

		if ( preg_match( '#/([A-Za-z0-9]{64})/?$#D', (string) $parts['path'], $matches ) ) {
			$token_suffix = substr( $matches[1], -6 );
		}

		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';

		return sprintf(
			/* translators: 1: platform host, 2: port, 3: final six token characters. */
			__( '%1$s%2$s, token eindigt op %3$s', 'codegenie-pulse-connector' ),
			(string) $parts['host'],
			$port,
			$token_suffix
		);
	}

	/**
	 * Get connection state.
	 *
	 * @return array<string, mixed>
	 */
	public function state() {
		$state = get_option( self::STATE_NAME, array() );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * Update connection state.
	 *
	 * @param array<string, mixed> $state State value.
	 * @return void
	 */
	public function update_state( $state ) {
		update_option( self::STATE_NAME, $state, false );
	}

	/**
	 * Standard invalid DSN response.
	 *
	 * @return WP_Error
	 */
	private function invalid_dsn() {
		return new WP_Error(
			'codegenie_pulse_invalid_dsn',
			__( 'Plak de volledige DSN uit Codegenie Pulse. Deze moet eindigen op /api/ingest/errors/{token}.', 'codegenie-pulse-connector' )
		);
	}
}
