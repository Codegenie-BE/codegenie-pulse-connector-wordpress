<?php
/**
 * Minimal deterministic WordPress surface for unit-level contract tests.
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', 'C:/wordpress/' );
}
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
}
if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' );
}
if ( ! defined( 'CODEGENIE_PULSE_CONNECTOR_VERSION' ) ) {
	define( 'CODEGENIE_PULSE_CONNECTOR_VERSION', '1.2.1' );
}
if ( ! defined( 'CODEGENIE_PULSE_CONNECTOR_FILE' ) ) {
	define( 'CODEGENIE_PULSE_CONNECTOR_FILE', dirname( __DIR__ ) . '/codegenie-pulse-connector.php' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

final class WP_Error {
	private $code;
	private $message;
	private $data;

	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code = $code;
		$this->message = $message;
		$this->data = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}

	public function get_error_codes() {
		return array( $this->code );
	}

	public function get_error_data() {
		return $this->data;
	}
}

final class WP_REST_Request {
	private $params;
	private $route;

	public function __construct( $params = array(), $route = '' ) {
		$this->params = $params;
		$this->route = $route;
	}

	public function get_param( $key ) {
		return isset( $this->params[ $key ] ) ? $this->params[ $key ] : null;
	}

	public function get_route() {
		return $this->route;
	}
}

final class WP_REST_Response {
	public $data;
	public $headers = array();
	private $status;

	public function __construct( $data = null, $status = 200 ) {
		$this->data = $data;
		$this->status = $status;
	}

	public function header( $name, $value ) {
		$this->headers[ $name ] = $value;
	}

	public function get_status() {
		return $this->status;
	}
}

final class Codegenie_Test_Wp_Die extends RuntimeException {
	public $response;
}

function codegenie_test_reset() {
	$GLOBALS['codegenie_test'] = array(
		'options'       => array(),
		'transients'    => array(),
		'filters'       => array(),
		'actions'       => array(),
		'remote_calls'  => array(),
		'remote_result' => array( 'response' => array( 'code' => 202 ), 'body' => '{}' ),
		'home_url'      => 'https://wordpress.example',
		'admin_url'     => 'https://wordpress.example/wp-admin/',
		'salt'          => 'unit-test-auth-salt-one',
		'bloginfo'      => array( 'version' => '7.0', 'name' => 'Synthetic Test Site' ),
		'multisite'     => false,
		'is_admin'      => false,
		'capability'    => true,
		'nonce_calls'   => array(),
		'nocache_calls' => 0,
		'redirects'     => array(),
		'routes'        => array(),
		'deleted'       => array(),
		'sites'         => array( 1 ),
		'blog_id'       => 1,
		'switched'      => array(),
		'added'         => array(),
		'update_failures' => array(),
		'update_mutator'  => null,
		'privacy_content' => array(),
		'environment'     => 'production',
	);
	$_GET = array();
	$_POST = array();
	$_SERVER = array(
		'REQUEST_URI'    => '/',
		'REQUEST_METHOD' => 'GET',
	);
}

codegenie_test_reset();

function __( $text, $domain = null ) {
	return $text;
}
function esc_html__( $text, $domain = null ) {
	return $text;
}
function wp_kses_post( $text ) {
	return strip_tags( $text, '<p><strong><em><a><code>' );
}
function wp_add_privacy_policy_content( $name, $content ) {
	$GLOBALS['codegenie_test']['privacy_content'][ $name ] = $content;
}
function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
function wp_http_validate_url( $url ) {
	$parts = parse_url( $url );
	if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
		return false;
	}
	$host = strtolower( rtrim( $parts['host'], '.' ) );
	if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) || preg_match( '/^(?:10\.|192\.168\.|172\.(?:1[6-9]|2\d|3[01])\.)/', $host ) ) {
		return false;
	}
	return $url;
}
function apply_filters( $tag, $value ) {
	$args = func_get_args();
	array_shift( $args );
	if ( isset( $GLOBALS['codegenie_test']['filters'][ $tag ] ) ) {
		foreach ( $GLOBALS['codegenie_test']['filters'][ $tag ] as $callback ) {
			$args[0] = call_user_func_array( $callback, $args );
		}
	}
	return $args[0];
}
function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['codegenie_test']['filters'][ $tag ][] = $callback;
	return true;
}
function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['codegenie_test']['actions'][ $tag ][] = $callback;
	return true;
}
function do_action( $tag ) {
	$GLOBALS['codegenie_test']['action_calls'][] = func_get_args();
}
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['codegenie_test']['options'] ) ? $GLOBALS['codegenie_test']['options'][ $key ] : $default;
}
function add_option( $key, $value, $deprecated = '', $autoload = 'yes' ) {
	$GLOBALS['codegenie_test']['added'][] = array( $GLOBALS['codegenie_test']['blog_id'], $key, $autoload );
	if ( ! array_key_exists( $key, $GLOBALS['codegenie_test']['options'] ) ) {
		$GLOBALS['codegenie_test']['options'][ $key ] = $value;
		return true;
	}
	return false;
}
function update_option( $key, $value, $autoload = null ) {
	if ( ! empty( $GLOBALS['codegenie_test']['update_failures'][ $key ] ) ) {
		--$GLOBALS['codegenie_test']['update_failures'][ $key ];
		return false;
	}
	if ( is_callable( $GLOBALS['codegenie_test']['update_mutator'] ) ) {
		$value = call_user_func( $GLOBALS['codegenie_test']['update_mutator'], $key, $value );
	}
	$GLOBALS['codegenie_test']['options'][ $key ] = $value;
	return true;
}
function delete_option( $key ) {
	$GLOBALS['codegenie_test']['deleted'][] = array( $GLOBALS['codegenie_test']['blog_id'], 'option', $key );
	unset( $GLOBALS['codegenie_test']['options'][ $key ] );
	return true;
}
function get_transient( $key ) {
	return isset( $GLOBALS['codegenie_test']['transients'][ $key ] ) ? $GLOBALS['codegenie_test']['transients'][ $key ]['value'] : false;
}
function set_transient( $key, $value, $expiration = 0 ) {
	$GLOBALS['codegenie_test']['transients'][ $key ] = array( 'value' => $value, 'expiration' => $expiration );
	return true;
}
function delete_transient( $key ) {
	$GLOBALS['codegenie_test']['deleted'][] = array( $GLOBALS['codegenie_test']['blog_id'], 'transient', $key );
	unset( $GLOBALS['codegenie_test']['transients'][ $key ] );
	return true;
}
function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( $defaults, is_array( $args ) ? $args : array() );
}
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
function sanitize_text_field( $value ) {
	return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( (string) $value ) ) );
}
function sanitize_title( $value ) {
	return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $value ) ), '-' );
}
function untrailingslashit( $value ) {
	return rtrim( $value, '/\\' );
}
function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}
function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags );
}
function wp_salt( $scheme = 'auth' ) {
	return $GLOBALS['codegenie_test']['salt'];
}
function home_url( $path = '' ) {
	return rtrim( $GLOBALS['codegenie_test']['home_url'], '/' ) . ( '' !== $path ? '/' . ltrim( $path, '/' ) : '' );
}
function admin_url( $path = '' ) {
	return rtrim( $GLOBALS['codegenie_test']['admin_url'], '/' ) . '/' . ltrim( $path, '/' );
}
function esc_url_raw( $url, $protocols = null ) {
	return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';
}
function wp_normalize_path( $path ) {
	return str_replace( '\\', '/', $path );
}
function wp_json_file_decode() {}
function get_bloginfo( $key ) {
	return isset( $GLOBALS['codegenie_test']['bloginfo'][ $key ] ) ? $GLOBALS['codegenie_test']['bloginfo'][ $key ] : '';
}
function wp_get_environment_type() {
	return $GLOBALS['codegenie_test']['environment'];
}
function wp_strip_all_tags( $value ) {
	return strip_tags( $value );
}
function is_multisite() {
	return $GLOBALS['codegenie_test']['multisite'];
}
function is_admin() {
	return $GLOBALS['codegenie_test']['is_admin'];
}
function wp_doing_cron() {
	return false;
}
function wp_doing_ajax() {
	return false;
}
function wp_safe_remote_post( $url, $args ) {
	$GLOBALS['codegenie_test']['remote_calls'][] = array( 'url' => $url, 'args' => $args );
	$result = $GLOBALS['codegenie_test']['remote_result'];
	return is_callable( $result ) ? $result( $url, $args ) : $result;
}
function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['response']['code'] ) ? $response['response']['code'] : 0;
}
function wp_remote_retrieve_body( $response ) {
	return isset( $response['body'] ) ? $response['body'] : '';
}
function rest_ensure_response( $data ) {
	return $data instanceof WP_REST_Response ? $data : new WP_REST_Response( $data );
}
function register_rest_route( $namespace, $route, $args ) {
	$GLOBALS['codegenie_test']['routes'][ $namespace . $route ] = $args;
	return true;
}
function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
	return substr( str_repeat( 'Ab3', $length ), 0, $length );
}
function current_user_can( $capability ) {
	return $GLOBALS['codegenie_test']['capability'];
}
function check_admin_referer( $action ) {
	$GLOBALS['codegenie_test']['nonce_calls'][] = $action;
	return true;
}
function wp_die( $message = '', $title = '', $args = array() ) {
	$exception = new Codegenie_Test_Wp_Die( (string) $message );
	$exception->response = isset( $args['response'] ) ? $args['response'] : 500;
	throw $exception;
}
function nocache_headers() {
	++$GLOBALS['codegenie_test']['nocache_calls'];
}
function wp_safe_redirect( $url ) {
	$GLOBALS['codegenie_test']['redirects'][] = $url;
	return true;
}
function get_current_user_id() {
	return 7;
}
function esc_html( $value ) {
	return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
}
function plugin_basename( $file ) {
	return basename( dirname( $file ) ) . '/' . basename( $file );
}
function get_sites( $args = array() ) {
	return $GLOBALS['codegenie_test']['sites'];
}
function switch_to_blog( $blog_id ) {
	$GLOBALS['codegenie_test']['switched'][] = array( 'to', $blog_id );
	$GLOBALS['codegenie_test']['blog_id'] = $blog_id;
}
function restore_current_blog() {
	$GLOBALS['codegenie_test']['switched'][] = array( 'restore', $GLOBALS['codegenie_test']['blog_id'] );
	$GLOBALS['codegenie_test']['blog_id'] = 1;
}
function get_file_data( $file, $headers, $context = '' ) {
	return array( 'version' => '1.0.0' );
}

require_once dirname( __DIR__ ) . '/includes/class-codegenie-pulse-secret-store.php';
require_once dirname( __DIR__ ) . '/includes/class-codegenie-pulse-options.php';
require_once dirname( __DIR__ ) . '/includes/class-codegenie-pulse-connection.php';
require_once dirname( __DIR__ ) . '/includes/class-codegenie-pulse-redactor.php';
require_once dirname( __DIR__ ) . '/includes/class-codegenie-pulse-client.php';
require_once dirname( __DIR__ ) . '/includes/class-codegenie-pulse-reporter.php';
require_once dirname( __DIR__ ) . '/includes/class-codegenie-pulse-deployment-tracker.php';
require_once dirname( __DIR__ ) . '/includes/class-codegenie-pulse-verification-endpoint.php';
require_once dirname( __DIR__ ) . '/includes/class-codegenie-pulse-admin.php';
require_once dirname( __DIR__ ) . '/includes/class-codegenie-pulse-plugin.php';

abstract class Codegenie_Pulse_Test_Case extends PHPUnit\Framework\TestCase {
	protected function setUp(): void {
		parent::setUp();
		codegenie_test_reset();
	}

	protected function options( $settings = array() ) {
		$GLOBALS['codegenie_test']['options'][ Codegenie_Pulse_Options::OPTION_NAME ] = array_merge( Codegenie_Pulse_Options::defaults(), $settings );
		return new Codegenie_Pulse_Options( new Codegenie_Pulse_Secret_Store() );
	}

	protected function configuredOptions( $settings = array() ) {
		$store = new Codegenie_Pulse_Secret_Store();
		$dsn = 'https://pulse.example/api/ingest/errors/' . str_repeat( 'A', 64 );
		$settings['encrypted_dsn'] = $store->encrypt( $dsn );
		$GLOBALS['codegenie_test']['options'][ Codegenie_Pulse_Options::OPTION_NAME ] = array_merge( Codegenie_Pulse_Options::defaults(), $settings );
		return new Codegenie_Pulse_Options( $store );
	}
}
