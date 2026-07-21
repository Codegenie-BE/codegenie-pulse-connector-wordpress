<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts WordPress software changes into existing Pulse deployment events.
 */
final class Codegenie_Pulse_Deployment_Tracker {
	/** @var Codegenie_Pulse_Client */
	private $client;

	/** @var Codegenie_Pulse_Redactor */
	private $redactor;

	/**
	 * @param Codegenie_Pulse_Client   $client   HTTP client.
	 * @param Codegenie_Pulse_Redactor $redactor Privacy redactor.
	 */
	public function __construct( Codegenie_Pulse_Client $client, Codegenie_Pulse_Redactor $redactor ) {
		$this->client   = $client;
		$this->redactor = $redactor;
	}

	/**
	 * Register supported WordPress update hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'upgrader_process_complete', array( $this, 'track_upgrade' ), 10, 2 );
		add_action( 'activated_plugin', array( $this, 'track_plugin_activation' ), 10, 2 );
		add_action( 'deactivated_plugin', array( $this, 'track_plugin_deactivation' ), 10, 2 );
		add_action( 'switch_theme', array( $this, 'track_theme_switch' ), 10, 3 );
	}

	/**
	 * @param WP_Upgrader         $upgrader   Upgrader instance.
	 * @param array<string,mixed> $hook_extra Update metadata.
	 * @return void
	 */
	public function track_upgrade( $upgrader, $hook_extra ) {
		unset( $upgrader );

		if ( ! is_array( $hook_extra ) || 'update' !== ( isset( $hook_extra['action'] ) ? $hook_extra['action'] : '' ) ) {
			return;
		}

		$type = isset( $hook_extra['type'] ) ? sanitize_key( (string) $hook_extra['type'] ) : '';

		if ( 'core' === $type ) {
			global $wp_version;

			$this->send_event( 'core_update', array( 'wordpress' ), sanitize_text_field( (string) $wp_version ) );

			return;
		}

		$items = array();

		if ( 'plugin' === $type ) {
			$items = isset( $hook_extra['plugins'] ) ? (array) $hook_extra['plugins'] : array();

			if ( isset( $hook_extra['plugin'] ) ) {
				$items[] = $hook_extra['plugin'];
			}
		} elseif ( 'theme' === $type ) {
			$items = isset( $hook_extra['themes'] ) ? (array) $hook_extra['themes'] : array();

			if ( isset( $hook_extra['theme'] ) ) {
				$items[] = $hook_extra['theme'];
			}
		}

		$items = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $items ) ) ) );

		if ( empty( $items ) ) {
			return;
		}

		$this->send_event( $type . '_update', $items, $this->versions_for( $type, $items ) );
	}

	/**
	 * @param string $plugin        Plugin basename.
	 * @param bool   $network_wide  Network activation flag.
	 * @return void
	 */
	public function track_plugin_activation( $plugin, $network_wide ) {
		$this->send_event(
			'plugin_activation',
			array( sanitize_text_field( $plugin ) ),
			$this->versions_for( 'plugin', array( $plugin ) ),
			array( 'network_wide' => (bool) $network_wide )
		);
	}

	/**
	 * @param string $plugin        Plugin basename.
	 * @param bool   $network_wide  Network deactivation flag.
	 * @return void
	 */
	public function track_plugin_deactivation( $plugin, $network_wide ) {
		$this->send_event(
			'plugin_deactivation',
			array( sanitize_text_field( $plugin ) ),
			$this->versions_for( 'plugin', array( $plugin ) ),
			array( 'network_wide' => (bool) $network_wide )
		);
	}

	/**
	 * @param string   $new_name  New theme name.
	 * @param WP_Theme $new_theme New theme object.
	 * @param WP_Theme $old_theme Previous theme object.
	 * @return void
	 */
	public function track_theme_switch( $new_name, $new_theme, $old_theme ) {
		unset( $old_theme );
		$slug    = is_object( $new_theme ) && method_exists( $new_theme, 'get_stylesheet' ) ? $new_theme->get_stylesheet() : sanitize_title( $new_name );
		$version = is_object( $new_theme ) && method_exists( $new_theme, 'get' ) ? (string) $new_theme->get( 'Version' ) : '';

		$this->send_event( 'theme_switch', array( $slug ), $version );
	}

	/**
	 * @param string               $event   Event name.
	 * @param string[]             $items   Changed items.
	 * @param string               $version Version summary.
	 * @param array<string, mixed> $extra   Extra idempotency metadata.
	 * @return void
	 */
	private function send_event( $event, $items, $version = '', $extra = array() ) {
		$event      = substr( sanitize_key( $event ), 0, 60 );
		$repository = $this->redactor->text( implode( ', ', array_slice( $items, 0, 20 ) ), 255 );
		$version    = $this->redactor->text( $version, 120 );
		$seed       = home_url( '/' ) . '|' . $event . '|' . $repository . '|' . $version . '|' . wp_json_encode( $extra ) . '|' . gmdate( 'YmdHi' );

		$this->client->send_deployment(
			array_filter(
				array(
					'idempotency_key' => 'wp-' . substr( hash( 'sha256', $seed ), 0, 40 ),
					'version'         => $version,
					'repository'      => $repository,
					'deployed_by'     => 'WordPress',
					'source'          => 'wordpress_' . $event,
				)
			)
		);
	}

	/**
	 * @param string   $type  plugin or theme.
	 * @param string[] $items Updated items.
	 * @return string
	 */
	private function versions_for( $type, $items ) {
		$versions = array();

		foreach ( array_slice( $items, 0, 10 ) as $item ) {
			if ( 'plugin' === $type ) {
				$file = WP_PLUGIN_DIR . '/' . ltrim( $item, '/' );

				if ( is_readable( $file ) ) {
					$data       = get_file_data( $file, array( 'version' => 'Version' ), 'plugin' );
					$slug       = dirname( $item );
					$slug       = '.' === $slug ? basename( $item, '.php' ) : $slug;
					$versions[] = sanitize_key( $slug ) . '@' . sanitize_text_field( isset( $data['version'] ) ? $data['version'] : '' );
				}
			} elseif ( 'theme' === $type ) {
				$theme      = wp_get_theme( $item );
				$versions[] = sanitize_key( $item ) . '@' . sanitize_text_field( (string) $theme->get( 'Version' ) );
			}
		}

		return $this->redactor->text( implode( ', ', array_filter( $versions ) ), 120 );
	}
}
