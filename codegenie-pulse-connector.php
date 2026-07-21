<?php
/**
 * Plugin Name:       Codegenie Pulse Connector
 * Description:       Verbind WordPress veilig met Codegenie Pulse voor foutmonitoring, websiteverificatie en deployment tracking.
 * Version:           1.2.1
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Codegenie
 * Author URI:        https://www.codegenie.be/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       codegenie-pulse-connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CODEGENIE_PULSE_CONNECTOR_VERSION', '1.2.1' );
define( 'CODEGENIE_PULSE_CONNECTOR_FILE', __FILE__ );
define( 'CODEGENIE_PULSE_CONNECTOR_DIR', plugin_dir_path( __FILE__ ) );

require_once CODEGENIE_PULSE_CONNECTOR_DIR . 'includes/class-codegenie-pulse-secret-store.php';
require_once CODEGENIE_PULSE_CONNECTOR_DIR . 'includes/class-codegenie-pulse-options.php';
require_once CODEGENIE_PULSE_CONNECTOR_DIR . 'includes/class-codegenie-pulse-connection.php';
require_once CODEGENIE_PULSE_CONNECTOR_DIR . 'includes/class-codegenie-pulse-redactor.php';
require_once CODEGENIE_PULSE_CONNECTOR_DIR . 'includes/class-codegenie-pulse-client.php';
require_once CODEGENIE_PULSE_CONNECTOR_DIR . 'includes/class-codegenie-pulse-reporter.php';
require_once CODEGENIE_PULSE_CONNECTOR_DIR . 'includes/class-codegenie-pulse-deployment-tracker.php';
require_once CODEGENIE_PULSE_CONNECTOR_DIR . 'includes/class-codegenie-pulse-verification-endpoint.php';
require_once CODEGENIE_PULSE_CONNECTOR_DIR . 'includes/class-codegenie-pulse-admin.php';
require_once CODEGENIE_PULSE_CONNECTOR_DIR . 'includes/class-codegenie-pulse-plugin.php';

/**
 * Return the booted plugin instance.
 *
 * @return Codegenie_Pulse_Plugin
 */
function codegenie_pulse_connector() {
	return Codegenie_Pulse_Plugin::instance();
}

/**
 * Report a caught exception to Codegenie Pulse.
 *
 * @param Throwable            $throwable The exception or error to report.
 * @param array<string, mixed> $context   Optional privacy-safe context.
 * @return array<string, mixed>
 */
function codegenie_pulse_report_exception( $throwable, $context = array() ) {
	if ( ! $throwable instanceof Throwable ) {
		return array(
			'success' => false,
			'code'    => 'invalid_exception',
			'message' => __( 'Alleen een Throwable kan worden gerapporteerd.', 'codegenie-pulse-connector' ),
		);
	}

	return codegenie_pulse_connector()->reporter()->report_exception( $throwable, (array) $context );
}

/**
 * Report an application message to Codegenie Pulse.
 *
 * @param string               $message The message to report.
 * @param string               $level   PSR-style level such as error or warning.
 * @param array<string, mixed> $context Optional privacy-safe context.
 * @return array<string, mixed>
 */
function codegenie_pulse_report_message( $message, $level = 'error', $context = array() ) {
	return codegenie_pulse_connector()->reporter()->report_message(
		(string) $message,
		(string) $level,
		(array) $context
	);
}

register_activation_hook( __FILE__, array( 'Codegenie_Pulse_Plugin', 'activate' ) );

add_action(
	'plugins_loaded',
	static function () {
		codegenie_pulse_connector()->boot();
	},
	PHP_INT_MAX
);
