<?php

define( 'ABSPATH', __DIR__ . '/' );

final class Codegenie_Pulse_Options {
	public function get( $key, $default = null ) {
		return 'verification_token' === $key ? (string) getenv( 'CODEGENIE_TEST_TOKEN' ) : $default;
	}
}

function wp_unslash( $value ) {
	return $value;
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
function sanitize_text_field( $value ) {
	return trim( (string) $value );
}
function status_header( $status ) {
	$GLOBALS['verification_status'] = $status;
}
function esc_html( $value ) {
	return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
}

register_shutdown_function(
	static function () {
		fwrite( STDERR, 'CODEGENIE_META:' . json_encode( array( 'status' => isset( $GLOBALS['verification_status'] ) ? $GLOBALS['verification_status'] : null ) ) );
	}
);

$_SERVER['REQUEST_URI'] = '/.well-known/codegenie-pulse.txt?ignored=yes';
$_SERVER['REQUEST_METHOD'] = (string) getenv( 'CODEGENIE_TEST_METHOD' );

require dirname( __DIR__, 2 ) . '/includes/class-codegenie-pulse-verification-endpoint.php';
$endpoint = new Codegenie_Pulse_Verification_Endpoint( new Codegenie_Pulse_Options() );
$endpoint->maybe_serve_token();

