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
	const SAMPLE_KEY  = 'codegenie_pulse_connector_non_fatal_samples';

	const CAPTURE_OFF        = 'off';
	const CAPTURE_PRODUCTION = 'production';
	const CAPTURE_EXTENDED   = 'extended';
	const CAPTURE_DEBUG      = 'debug';

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
			'connection_method'         => '',
			'pulse_origin'              => '',
			'pulse_site_id'             => '',
			'pulse_site_url'            => '',
			'pulse_dashboard_url'       => '',
			'pulse_plan'                => '',
			'pulse_plan_label'          => '',
			'pulse_capabilities'        => array(),
			'connected_at'              => '',
			'automatic_error_reporting' => 1,
			'error_capture_mode'         => self::CAPTURE_PRODUCTION,
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
		$value = is_array( $value ) ? $value : array();

		if ( ! array_key_exists( 'error_capture_mode', $value ) ) {
			$value['error_capture_mode'] = array_key_exists( 'automatic_error_reporting', $value ) && empty( $value['automatic_error_reporting'] )
				? self::CAPTURE_OFF
				: self::CAPTURE_PRODUCTION;
		}

		if ( ! in_array( $value['error_capture_mode'], self::capture_modes(), true ) ) {
			$value['error_capture_mode'] = self::CAPTURE_PRODUCTION;
		}

		return wp_parse_args( $value, self::defaults() );
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
	 * Whether WordPress has a platform-level connection, also on Starter without a DSN.
	 *
	 * @return bool
	 */
	public function has_platform_connection() {
		return ( 'automatic' === (string) $this->get( 'connection_method', '' )
			&& '' !== (string) $this->get( 'pulse_origin', '' )
			&& '' !== (string) $this->get( 'pulse_site_id', '' ) )
			|| $this->has_stored_dsn();
	}

	/**
	 * Return the normalized automatic PHP error capture mode.
	 *
	 * @return string
	 */
	public function capture_mode() {
		$mode = (string) $this->get( 'error_capture_mode', self::CAPTURE_PRODUCTION );

		return in_array( $mode, self::capture_modes(), true ) ? $mode : self::CAPTURE_PRODUCTION;
	}

	/**
	 * Supported capture modes.
	 *
	 * @return string[]
	 */
	public static function capture_modes() {
		return array(
			self::CAPTURE_OFF,
			self::CAPTURE_PRODUCTION,
			self::CAPTURE_EXTENDED,
			self::CAPTURE_DEBUG,
		);
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

		$capture_mode = isset( $input['error_capture_mode'] )
			? sanitize_key( (string) $input['error_capture_mode'] )
			: self::CAPTURE_PRODUCTION;

		if ( ! in_array( $capture_mode, self::capture_modes(), true ) ) {
			return new WP_Error(
				'codegenie_pulse_invalid_capture_mode',
				__( 'De gekozen PHP-foutcapturemodus is ongeldig.', 'codegenie-pulse-connector' )
			);
		}

		$next['verification_token']        = $verification_token;
		$next['error_capture_mode']         = $capture_mode;
		$next['automatic_error_reporting'] = self::CAPTURE_OFF === $capture_mode ? 0 : 1;
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
			$dsn_parts             = wp_parse_url( $validated_dsn );
			$dsn_port              = is_array( $dsn_parts ) && isset( $dsn_parts['port'] ) ? ':' . (int) $dsn_parts['port'] : '';

			$next['connection_method']   = 'manual';
			$next['pulse_origin']        = is_array( $dsn_parts ) && ! empty( $dsn_parts['scheme'] ) && ! empty( $dsn_parts['host'] )
				? strtolower( (string) $dsn_parts['scheme'] ) . '://' . strtolower( (string) $dsn_parts['host'] ) . $dsn_port
				: '';
			$next['pulse_site_id']       = '';
			$next['pulse_site_url']      = '';
			$next['pulse_dashboard_url'] = '';
			$next['pulse_plan']          = '';
			$next['pulse_plan_label']    = '';
			$next['pulse_capabilities']  = array(
				'website_monitoring'  => true,
				'error_monitoring'    => true,
				'deployment_tracking' => ! empty( $input['deployment_tracking'] ),
			);
			$next['connected_at']        = gmdate( 'c' );
		}

		update_option( self::OPTION_NAME, $next, false );

		if ( $next['error_capture_mode'] !== $current['error_capture_mode'] ) {
			delete_transient( self::SAMPLE_KEY );
		}

		if ( $next['encrypted_dsn'] !== $current['encrypted_dsn'] ) {
			delete_option( self::STATE_NAME );
			delete_transient( self::BACKOFF_KEY );
			delete_transient( self::BACKOFF_KEY . '_error' );
			delete_transient( self::BACKOFF_KEY . '_deployment' );
			delete_transient( self::SAMPLE_KEY );
		}

		return true;
	}

	/**
	 * Apply the one-time configuration returned by the secure Pulse exchange.
	 *
	 * @param array<string, mixed> $configuration Provisioning response.
	 * @return true|WP_Error
	 */
	public function provision( $configuration ) {
		$pulse_origin       = isset( $configuration['pulse_origin'] ) && is_string( $configuration['pulse_origin'] ) ? trim( $configuration['pulse_origin'] ) : '';
		$site_id            = isset( $configuration['site_id'] ) && is_string( $configuration['site_id'] ) ? trim( $configuration['site_id'] ) : '';
		$site_url           = isset( $configuration['site_url'] ) && is_string( $configuration['site_url'] ) ? untrailingslashit( $configuration['site_url'] ) : '';
		$verification_token = isset( $configuration['verification_token'] ) && is_string( $configuration['verification_token'] ) ? trim( $configuration['verification_token'] ) : '';
		$dsn                = isset( $configuration['dsn'] ) && is_string( $configuration['dsn'] ) ? trim( $configuration['dsn'] ) : '';
		$capabilities       = isset( $configuration['capabilities'] ) && is_array( $configuration['capabilities'] ) ? $configuration['capabilities'] : array();

		$validated_origin = $this->validate_pulse_origin( $pulse_origin );

		if ( is_wp_error( $validated_origin ) ) {
			return $validated_origin;
		}

		if ( 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $site_id ) ) {
			return new WP_Error( 'codegenie_pulse_invalid_site_id', __( 'Codegenie Pulse gaf geen geldige website-ID terug.', 'codegenie-pulse-connector' ) );
		}

		if ( strlen( $site_url ) > 2048 || $this->normalize_site_url( $site_url ) !== $this->normalize_site_url( home_url( '/' ) ) ) {
			return new WP_Error( 'codegenie_pulse_site_mismatch', __( 'De Codegenie Pulse-configuratie hoort niet bij deze WordPress-website.', 'codegenie-pulse-connector' ) );
		}

		if ( 1 !== preg_match( '/^[A-Za-z0-9]{32,128}$/', $verification_token ) ) {
			return new WP_Error( 'codegenie_pulse_invalid_verification_token', __( 'Codegenie Pulse gaf geen geldig verificatietoken terug.', 'codegenie-pulse-connector' ) );
		}

		$normalized_capabilities = array(
			'website_monitoring'  => ! empty( $capabilities['website_monitoring'] ),
			'error_monitoring'    => ! empty( $capabilities['error_monitoring'] ),
			'deployment_tracking' => ! empty( $capabilities['deployment_tracking'] ),
		);

		if ( ! $normalized_capabilities['website_monitoring'] ) {
			return new WP_Error( 'codegenie_pulse_missing_site_capability', __( 'De websitekoppeling bevat geen monitoringmogelijkheid.', 'codegenie-pulse-connector' ) );
		}

		if ( $normalized_capabilities['error_monitoring'] && '' === $dsn ) {
			return new WP_Error( 'codegenie_pulse_missing_dsn', __( 'De foutmonitoringconfiguratie bevat geen DSN.', 'codegenie-pulse-connector' ) );
		}

		if ( ! $normalized_capabilities['error_monitoring'] && '' !== $dsn ) {
			return new WP_Error( 'codegenie_pulse_unexpected_dsn', __( 'De websitekoppeling bevat een onverwachte fout-DSN.', 'codegenie-pulse-connector' ) );
		}

		$encrypted_dsn = '';

		if ( '' !== $dsn ) {
			$validated_dsn = $this->validate_dsn( $dsn );

			if ( is_wp_error( $validated_dsn ) ) {
				return $validated_dsn;
			}

			if ( ! $this->same_origin( $validated_dsn, $validated_origin ) ) {
				return new WP_Error( 'codegenie_pulse_dsn_origin_mismatch', __( 'De DSN hoort niet bij de bevestigde Codegenie Pulse-installatie.', 'codegenie-pulse-connector' ) );
			}

			$encrypted_dsn = $this->secret_store->encrypt( $validated_dsn );

			if ( is_wp_error( $encrypted_dsn ) ) {
				return $encrypted_dsn;
			}
		}

		$dashboard_url = isset( $configuration['dashboard_url'] ) && is_string( $configuration['dashboard_url'] ) ? trim( $configuration['dashboard_url'] ) : '';

		if ( strlen( $dashboard_url ) > 2048 || ( '' !== $dashboard_url && ! $this->same_origin( $dashboard_url, $validated_origin ) ) ) {
			return new WP_Error( 'codegenie_pulse_dashboard_origin_mismatch', __( 'De terugkeer-URL van Codegenie Pulse is ongeldig.', 'codegenie-pulse-connector' ) );
		}

		$current = $this->all();
		$next    = $current;

		$next['encrypted_dsn']             = $encrypted_dsn;
		$next['verification_token']        = $verification_token;
		$next['connection_method']         = 'automatic';
		$next['pulse_origin']              = $validated_origin;
		$next['pulse_site_id']             = $site_id;
		$next['pulse_site_url']            = $site_url;
		$next['pulse_dashboard_url']       = $dashboard_url;
		$next['pulse_plan']                = isset( $configuration['plan'] ) && is_string( $configuration['plan'] ) ? sanitize_key( $configuration['plan'] ) : '';
		$next['pulse_plan_label']          = isset( $configuration['plan_label'] ) && is_string( $configuration['plan_label'] ) ? substr( sanitize_text_field( $configuration['plan_label'] ), 0, 64 ) : '';
		$next['pulse_capabilities']        = $normalized_capabilities;
		$next['connected_at']              = gmdate( 'c' );
		$next['error_capture_mode']         = '' !== $dsn ? self::CAPTURE_PRODUCTION : self::CAPTURE_OFF;
		$next['automatic_error_reporting'] = '' !== $dsn ? 1 : 0;
		$next['deployment_tracking']       = '' !== $dsn && $normalized_capabilities['deployment_tracking'] ? 1 : 0;

		update_option( self::OPTION_NAME, $next, false );
		$this->reset_runtime_state();

		return true;
	}

	/**
	 * Remove the ingestion DSN and connection state.
	 *
	 * @return void
	 */
	public function disconnect() {
		$settings                              = $this->all();
		$settings['encrypted_dsn']             = '';
		$settings['verification_token']        = '';
		$settings['connection_method']         = '';
		$settings['pulse_origin']              = '';
		$settings['pulse_site_id']             = '';
		$settings['pulse_site_url']            = '';
		$settings['pulse_dashboard_url']       = '';
		$settings['pulse_plan']                = '';
		$settings['pulse_plan_label']          = '';
		$settings['pulse_capabilities']        = array();
		$settings['connected_at']              = '';
		$settings['error_capture_mode']         = self::CAPTURE_OFF;
		$settings['automatic_error_reporting'] = 0;
		$settings['deployment_tracking']       = 0;

		update_option( self::OPTION_NAME, $settings, false );
		$this->reset_runtime_state();
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

	/**
	 * @param string $origin Pulse origin.
	 * @return string|WP_Error
	 */
	private function validate_pulse_origin( $origin ) {
		if ( strlen( $origin ) > 2048 || false !== strpos( $origin, "\n" ) || false !== strpos( $origin, "\r" ) ) {
			return new WP_Error( 'codegenie_pulse_invalid_platform_origin', __( 'De Codegenie Pulse URL is ongeldig.', 'codegenie-pulse-connector' ) );
		}

		$parts = wp_parse_url( $origin );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return new WP_Error( 'codegenie_pulse_invalid_platform_origin', __( 'De Codegenie Pulse URL is ongeldig.', 'codegenie-pulse-connector' ) );
		}

		$scheme         = strtolower( (string) $parts['scheme'] );
		$allow_insecure = (bool) apply_filters( 'codegenie_pulse_allow_insecure_platform_origin', false, $origin );

		if ( 'https' !== $scheme && ! ( $allow_insecure && 'http' === $scheme ) ) {
			return new WP_Error( 'codegenie_pulse_invalid_platform_origin', __( 'Gebruik een veilige HTTPS Codegenie Pulse URL.', 'codegenie-pulse-connector' ) );
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return new WP_Error( 'codegenie_pulse_invalid_platform_origin', __( 'De Codegenie Pulse URL is ongeldig.', 'codegenie-pulse-connector' ) );
		}

		$path = isset( $parts['path'] ) ? trim( (string) $parts['path'] ) : '';

		if ( '' !== $path && '/' !== $path ) {
			return new WP_Error( 'codegenie_pulse_invalid_platform_origin', __( 'Gebruik alleen de basis-URL van Codegenie Pulse.', 'codegenie-pulse-connector' ) );
		}

		$port       = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$normalized = $scheme . '://' . strtolower( (string) $parts['host'] ) . $port;

		if ( false === wp_http_validate_url( $normalized . '/' ) ) {
			return new WP_Error( 'codegenie_pulse_invalid_platform_origin', __( 'De Codegenie Pulse URL is niet publiek en veilig bereikbaar.', 'codegenie-pulse-connector' ) );
		}

		return $normalized;
	}

	/**
	 * @param string $url First URL.
	 * @param string $origin Expected origin.
	 * @return bool
	 */
	private function same_origin( $url, $origin ) {
		$first  = wp_parse_url( $url );
		$second = wp_parse_url( $origin );

		if ( ! is_array( $first ) || ! is_array( $second ) ) {
			return false;
		}

		$first_port  = isset( $first['port'] ) ? (int) $first['port'] : ( 'https' === strtolower( (string) ( $first['scheme'] ?? '' ) ) ? 443 : 80 );
		$second_port = isset( $second['port'] ) ? (int) $second['port'] : ( 'https' === strtolower( (string) ( $second['scheme'] ?? '' ) ) ? 443 : 80 );

		return strtolower( (string) ( $first['scheme'] ?? '' ) ) === strtolower( (string) ( $second['scheme'] ?? '' ) )
			&& strtolower( (string) ( $first['host'] ?? '' ) ) === strtolower( (string) ( $second['host'] ?? '' ) )
			&& $first_port === $second_port;
	}

	/**
	 * @param string $url Site URL.
	 * @return string
	 */
	private function normalize_site_url( $url ) {
		$parts = wp_parse_url( trim( $url ) );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		$host   = rtrim( strtolower( (string) $parts['host'] ), '.' );
		$port   = isset( $parts['port'] ) && ! ( 'https' === $scheme && 443 === (int) $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path   = isset( $parts['path'] ) ? preg_replace( '#/+#', '/', '/' . ltrim( (string) $parts['path'], '/' ) ) : '';
		$path   = is_string( $path ) && '/' !== $path ? rtrim( $path, '/' ) : '';

		return $scheme . '://' . $host . $port . $path;
	}

	/**
	 * @return void
	 */
	private function reset_runtime_state() {
		delete_option( self::STATE_NAME );
		delete_transient( self::BACKOFF_KEY );
		delete_transient( self::BACKOFF_KEY . '_error' );
		delete_transient( self::BACKOFF_KEY . '_deployment' );
		delete_transient( self::SAMPLE_KEY );
	}
}
