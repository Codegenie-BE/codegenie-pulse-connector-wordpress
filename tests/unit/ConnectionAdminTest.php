<?php

final class ConnectionAdminTest extends Codegenie_Pulse_Test_Case {
	public function test_discovery_contract_method_payload_and_no_cache_headers() {
		$connection = new Codegenie_Pulse_Connection( $this->options() );
		$connection->register_rest_route();
		$route = $GLOBALS['codegenie_test']['routes']['codegenie-pulse/v1/discovery'];
		$this->assertSame( 'GET', $route['methods'] );

		$response = $connection->discovery( new WP_REST_Request( array( 'pulse_origin' => 'https://pulse.example' ) ) );
		$this->assertSame( 'codegenie-pulse-connector-wordpress', $response->data['connector'] );
		$this->assertSame( 1, $response->data['protocol_version'] );
		$this->assertSame( 'no-store, private', $response->headers['Cache-Control'] );
		$this->assertSame( 'no-cache', $response->headers['Pragma'] );
		$this->assertArrayNotHasKey( 'dsn', $response->data );
	}

	public function test_exchange_payload_and_dashboard_url_stay_on_validated_origin() {
		$options = $this->options();
		$connection = new Codegenie_Pulse_Connection( $options );
		$GLOBALS['codegenie_test']['remote_result'] = function ( $url, $args ) {
			$connection = array(
				'pulse_origin'       => 'https://pulse.example',
				'site_id'            => '12345678-1234-1234-1234-123456789abc',
				'site_url'           => 'https://wordpress.example',
				'verification_token' => str_repeat( 'V', 32 ),
				'dsn'                => 'https://pulse.example/api/ingest/errors/' . str_repeat( 'D', 64 ),
				'dashboard_url'      => 'https://pulse.example/websites/123',
				'capabilities'       => array( 'website_monitoring' => true, 'error_monitoring' => true, 'deployment_tracking' => true ),
			);
			return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'connection' => $connection ) ) );
		};

		$result = $connection->exchange( 'https://pulse.example', str_repeat( 'R', 64 ), str_repeat( 'C', 48 ) );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'https://pulse.example/websites/123', $result['dashboard_url'] );
		$call = $GLOBALS['codegenie_test']['remote_calls'][0];
		$this->assertSame( 'https://pulse.example/api/connectors/wordpress/exchange', $call['url'] );
		$this->assertSame( 0, $call['args']['redirection'] );
		$payload = json_decode( $call['args']['body'], true );
		$this->assertSame( 'codegenie-pulse-connector-wordpress', $payload['connector'] );
		$this->assertSame( 'https://wordpress.example', $payload['site_url'] );
		$this->assertSame( 'Synthetic Test Site', $payload['site_name'] );
		$this->assertSame( 'production', $payload['environment'] );
		$this->assertFalse( $payload['is_multisite'] );
		$this->assertArrayNotHasKey( 'plugins', $payload );
		$this->assertArrayNotHasKey( 'dsn', $payload );
	}

	public function test_exchange_handles_timeout_non_json_and_timeout_bounds_without_leaking_tokens() {
		$connection = new Codegenie_Pulse_Connection( $this->options() );
		$request_token = str_repeat( 'S', 64 );

		$GLOBALS['codegenie_test']['remote_result'] = new WP_Error( 'http_request_failed', 'timeout ' . $request_token );
		$timeout = $connection->exchange( 'https://pulse.example', $request_token, str_repeat( 'C', 48 ) );
		$this->assertSame( 'codegenie_pulse_network_error', $timeout->get_error_code() );
		$this->assertStringNotContainsString( $request_token, $timeout->get_error_message() );

		codegenie_test_reset();
		add_filter( 'codegenie_pulse_connection_timeout', function () { return 99; } );
		$GLOBALS['codegenie_test']['remote_result'] = array( 'response' => array( 'code' => 200 ), 'body' => '<html>not json</html>' );
		$invalid = $connection->exchange( 'https://pulse.example', $request_token, str_repeat( 'C', 48 ) );
		$this->assertSame( 'codegenie_pulse_invalid_response', $invalid->get_error_code() );
		$this->assertSame( 15.0, $GLOBALS['codegenie_test']['remote_calls'][0]['args']['timeout'] );
	}

	public function test_privacy_policy_suggestion_is_admin_only_translatable_and_exact() {
		$options = $this->configuredOptions(
			array( 'verification_token' => str_repeat( 'V', 32 ) )
		);
		$reporter = new Codegenie_Pulse_Reporter( new Codegenie_Pulse_Client( $options ), $options, new Codegenie_Pulse_Redactor() );
		$admin = new Codegenie_Pulse_Admin( $options, $reporter, new Codegenie_Pulse_Secret_Store(), new Codegenie_Pulse_Connection( $options ) );

		$admin->add_privacy_policy_content();
		$this->assertSame( array(), $GLOBALS['codegenie_test']['privacy_content'] );

		$GLOBALS['codegenie_test']['is_admin'] = true;
		$admin->register_hooks();
		$registered = array_map( function ( $callback ) { return is_array( $callback ) ? $callback[1] : ''; }, $GLOBALS['codegenie_test']['actions']['admin_init'] );
		$this->assertContains( 'add_privacy_policy_content', $registered );
		$admin->add_privacy_policy_content();
		$content = $GLOBALS['codegenie_test']['privacy_content']['Codegenie Pulse Connector'];
		$this->assertStringContainsString( 'site-URL, sitenaam, WordPress-versie, connectorversie, PHP-versie', $content );
		$this->assertStringContainsString( 'geen inventaris van geïnstalleerde plugins', $content );
		$this->assertStringContainsString( 'geen cookies, autorisatieheaders, binnenkomende formulierdata of request bodies', $content );
		$this->assertStringContainsString( 'verantwoordelijk voor de verwerking', $content );
		$this->assertStringContainsString( 'Bewaartermijnen', $content );
		$this->assertStringNotContainsString( '<script', $content );

		$diagnostics = wp_json_encode( $admin->add_debug_information( array() ) );
		$this->assertStringNotContainsString( $options->dsn(), $diagnostics );
		$this->assertStringNotContainsString( str_repeat( 'V', 32 ), $diagnostics );
	}

	public function test_admin_actions_require_capability_and_nonce() {
		$options = $this->configuredOptions();
		$reporter = new Codegenie_Pulse_Reporter( new Codegenie_Pulse_Client( $options ), $options, new Codegenie_Pulse_Redactor() );
		$connection = new Codegenie_Pulse_Connection( $options );
		$admin = new Codegenie_Pulse_Admin( $options, $reporter, new Codegenie_Pulse_Secret_Store(), $connection );
		$method = new ReflectionMethod( $admin, 'authorize_action' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$GLOBALS['codegenie_test']['capability'] = false;
		try {
			$method->invoke( $admin, 'codegenie_pulse_save' );
			$this->fail( 'Missing capability must terminate the action.' );
		} catch ( Codegenie_Test_Wp_Die $exception ) {
			$this->assertSame( 403, $exception->response );
		}
		$this->assertSame( array(), $GLOBALS['codegenie_test']['nonce_calls'] );

		$GLOBALS['codegenie_test']['capability'] = true;
		$method->invoke( $admin, 'codegenie_pulse_save' );
		$this->assertSame( array( 'codegenie_pulse_save' ), $GLOBALS['codegenie_test']['nonce_calls'] );
	}

	public function test_verification_endpoint_declares_methods_no_cache_and_noindex() {
		$get = $this->runVerificationEndpoint( 'GET', str_repeat( 'V', 32 ) );
		$this->assertSame( 200, $get['status'] );
		$this->assertSame( str_repeat( 'V', 32 ), $get['body'] );

		$head = $this->runVerificationEndpoint( 'HEAD', str_repeat( 'V', 32 ) );
		$this->assertSame( 200, $head['status'] );
		$this->assertSame( '', $head['body'] );

		$method_not_allowed = $this->runVerificationEndpoint( 'POST', str_repeat( 'V', 32 ) );
		$this->assertSame( 405, $method_not_allowed['status'] );
		$this->assertSame( '', $method_not_allowed['body'] );

		$missing = $this->runVerificationEndpoint( 'GET', '' );
		$this->assertSame( 404, $missing['status'] );
		$this->assertSame( '', $missing['body'] );

		$source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-codegenie-pulse-verification-endpoint.php' );
		$this->assertStringContainsString( "array( 'GET', 'HEAD' )", $source );
		$this->assertStringContainsString( "status_header( 405 )", $source );
		$this->assertStringContainsString( "header( 'Allow: GET, HEAD' )", $source );
		$this->assertStringContainsString( "header( 'Cache-Control: no-store, max-age=0' )", $source );
		$this->assertStringContainsString( "header( 'X-Robots-Tag: noindex, nofollow', true )", $source );
	}

	private function runVerificationEndpoint( $method, $token ) {
		$command = array( PHP_BINARY, dirname( __DIR__ ) . '/fixtures/verification-endpoint-runner.php' );
		$descriptor = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$environment = array_merge(
			(array) getenv(),
			array(
				'CODEGENIE_TEST_METHOD' => $method,
				'CODEGENIE_TEST_TOKEN'  => $token,
			)
		);
		$process = proc_open( $command, $descriptor, $pipes, null, $environment );
		$this->assertIsResource( $process );
		fclose( $pipes[0] );
		$body = stream_get_contents( $pipes[1] );
		$metadata = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$this->assertSame( 0, proc_close( $process ) );
		$this->assertStringStartsWith( 'CODEGENIE_META:', $metadata );
		$decoded = json_decode( substr( $metadata, strlen( 'CODEGENIE_META:' ) ), true );

		return array( 'status' => $decoded['status'], 'body' => $body );
	}
}
