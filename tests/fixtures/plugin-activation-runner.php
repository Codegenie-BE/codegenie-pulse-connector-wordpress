<?php

define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['activation_options'] = array();
$GLOBALS['activation_callback'] = null;
$GLOBALS['activation_remote_calls'] = array();

function plugin_dir_path( $file ) {
	return dirname( $file ) . '/';
}
function register_activation_hook( $file, $callback ) {
	$GLOBALS['activation_callback'] = $callback;
}
function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	return true;
}
function is_multisite() {
	return false;
}
function add_option( $key, $value, $deprecated = '', $autoload = 'yes' ) {
	$GLOBALS['activation_options'][ $key ] = array( 'value' => $value, 'autoload' => $autoload );
	return true;
}
function wp_safe_remote_post( $url, $args ) {
	$GLOBALS['activation_remote_calls'][] = array( $url, $args );

	return array( 'response' => array( 'code' => 202 ) );
}

require dirname( __DIR__, 2 ) . '/codegenie-pulse-connector.php';
call_user_func( $GLOBALS['activation_callback'] );

echo json_encode(
	array(
		'version' => CODEGENIE_PULSE_CONNECTOR_VERSION,
		'settings_autoload' => $GLOBALS['activation_options']['codegenie_pulse_connector_settings']['autoload'],
		'state_autoload' => $GLOBALS['activation_options']['codegenie_pulse_connector_state']['autoload'],
		'remote_calls' => count( $GLOBALS['activation_remote_calls'] ),
	)
);
