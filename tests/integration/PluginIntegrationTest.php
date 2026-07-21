<?php

final class PluginIntegrationTest extends WP_UnitTestCase {
	public function test_plugin_loads_and_preserves_public_contracts() {
		$this->assertSame( '1.2.1', CODEGENIE_PULSE_CONNECTOR_VERSION );
		$this->assertSame( 'codegenie-pulse-connector-wordpress', Codegenie_Pulse_Connection::CONNECTOR_ID );
		$this->assertTrue( function_exists( 'codegenie_pulse_connector' ) );
		$this->assertTrue( function_exists( 'codegenie_pulse_report_exception' ) );
		$this->assertTrue( function_exists( 'codegenie_pulse_report_message' ) );
		$this->assertInstanceOf( Codegenie_Pulse_Plugin::class, codegenie_pulse_connector() );
	}

	public function test_activation_installs_non_autoloaded_defaults_without_fatal_error() {
		delete_option( Codegenie_Pulse_Options::OPTION_NAME );
		delete_option( Codegenie_Pulse_Options::STATE_NAME );
		Codegenie_Pulse_Plugin::activate();

		$this->assertIsArray( get_option( Codegenie_Pulse_Options::OPTION_NAME ) );
		$this->assertSame( array(), get_option( Codegenie_Pulse_Options::STATE_NAME ) );
	}

	public function test_discovery_route_is_registered_as_public_get_endpoint() {
		do_action( 'rest_api_init' );
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/codegenie-pulse/v1/discovery', $routes );
		$this->assertArrayHasKey( WP_REST_Server::READABLE, $routes['/codegenie-pulse/v1/discovery'][0]['methods'] );
		$this->assertSame( '__return_true', $routes['/codegenie-pulse/v1/discovery'][0]['permission_callback'] );
	}

	public function test_not_configured_helper_call_is_safe() {
		$result = codegenie_pulse_report_message( 'Synthetic integration event.' );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 'not_available', $result['code'] );
	}
}
