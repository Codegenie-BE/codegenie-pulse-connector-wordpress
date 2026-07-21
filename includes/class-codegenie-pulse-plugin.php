<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Composes the connector without runtime packages or background workers.
 */
final class Codegenie_Pulse_Plugin {
	/** @var self|null */
	private static $instance;

	/** @var bool */
	private $booted = false;

	/** @var Codegenie_Pulse_Options */
	private $options;

	/** @var Codegenie_Pulse_Reporter */
	private $reporter;

	/**
	 * @return self
	 */
	public static function instance() {
		if ( ! self::$instance instanceof self ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * @param bool $network_wide Whether this is a multisite network activation.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				Codegenie_Pulse_Options::install_defaults();
				restore_current_blog();
			}

			return;
		}

		Codegenie_Pulse_Options::install_defaults();
	}

	/**
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		$secret_store       = new Codegenie_Pulse_Secret_Store();
		$this->options      = new Codegenie_Pulse_Options( $secret_store );
		$redactor           = new Codegenie_Pulse_Redactor();
		$client             = new Codegenie_Pulse_Client( $this->options );
		$this->reporter     = new Codegenie_Pulse_Reporter( $client, $this->options, $redactor );
		$connection         = new Codegenie_Pulse_Connection( $this->options );
		$verification       = new Codegenie_Pulse_Verification_Endpoint( $this->options );
		$admin              = new Codegenie_Pulse_Admin( $this->options, $this->reporter, $secret_store, $connection );
		$deployment_tracker = new Codegenie_Pulse_Deployment_Tracker( $client, $redactor );

		$connection->register_hooks();
		$verification->register_hooks();

		if ( is_admin() ) {
			$admin->register_hooks();
		}

		if ( $this->options->has_readable_dsn() && Codegenie_Pulse_Options::CAPTURE_OFF !== $this->options->capture_mode() ) {
			$this->reporter->register_hooks();
		}

		if ( $this->options->has_readable_dsn() && $this->options->get( 'deployment_tracking', 1 ) ) {
			$deployment_tracker->register_hooks();
		}

		do_action( 'codegenie_pulse_connector_loaded', $this );
	}

	/**
	 * @return Codegenie_Pulse_Reporter
	 */
	public function reporter() {
		if ( ! $this->booted ) {
			$this->boot();
		}

		return $this->reporter;
	}

	/** Prevent direct construction. */
	private function __construct() {}

	/** Prevent cloning. */
	private function __clone() {}
}
