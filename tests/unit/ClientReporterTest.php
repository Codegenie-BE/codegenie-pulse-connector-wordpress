<?php

final class ClientReporterTest extends Codegenie_Pulse_Test_Case {
	public function test_ingestion_and_platform_requests_never_follow_redirects() {
		$options = $this->configuredOptions();
		$client = new Codegenie_Pulse_Client( $options );

		$client->send_error( array( 'message' => 'synthetic' ) );
		$client->send_deployment( array( 'version' => '1.0.0' ) );

		$this->assertCount( 2, $GLOBALS['codegenie_test']['remote_calls'] );
		foreach ( $GLOBALS['codegenie_test']['remote_calls'] as $call ) {
			$this->assertSame( 0, $call['args']['redirection'] );
			$this->assertSame( (string) strlen( $call['args']['body'] ), $call['args']['headers']['Content-Length'] );
			$this->assertGreaterThanOrEqual( 1.0, $call['args']['timeout'] );
			$this->assertLessThanOrEqual( 10.0, $call['args']['timeout'] );
		}
		$this->assertStringContainsString( '/api/ingest/errors/', $GLOBALS['codegenie_test']['remote_calls'][0]['url'] );
		$this->assertStringContainsString( '/api/ingest/deployments/', $GLOBALS['codegenie_test']['remote_calls'][1]['url'] );
	}

	public function test_backoff_blocks_followup_delivery_but_manual_bypass_remains_available() {
		$options = $this->configuredOptions();
		$client = new Codegenie_Pulse_Client( $options );
		$GLOBALS['codegenie_test']['remote_result'] = array( 'response' => array( 'code' => 429 ), 'body' => '{}' );

		$this->assertSame( 'http_429', $client->send_error( array( 'message' => 'first' ) )['code'] );
		$this->assertSame( 'backoff', $client->send_error( array( 'message' => 'second' ) )['code'] );
		$this->assertSame( 'http_429', $client->send_error( array( 'message' => 'manual' ), true )['code'] );
		$this->assertCount( 2, $GLOBALS['codegenie_test']['remote_calls'] );
	}

	/**
	 * @dataProvider successfulStatusProvider
	 */
	public function test_all_success_statuses_are_accepted_and_clear_backoff( $status ) {
		$options = $this->configuredOptions();
		$client  = new Codegenie_Pulse_Client( $options );
		set_transient( Codegenie_Pulse_Options::BACKOFF_KEY . '_error', time() - 1, 60 );
		$GLOBALS['codegenie_test']['remote_result'] = array( 'response' => array( 'code' => $status ), 'body' => '{}' );

		$result = $client->send_error( array( 'message' => 'synthetic' ), true );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'accepted', $result['code'] );
		$this->assertSame( $status, $result['http_status'] );
		$this->assertFalse( get_transient( Codegenie_Pulse_Options::BACKOFF_KEY . '_error' ) );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $options->state()['last_success_at'] );
	}

	public function successfulStatusProvider() {
		return array(
			'OK'         => array( 200 ),
			'Accepted'   => array( 202 ),
			'No content' => array( 204 ),
			'Last 2xx'   => array( 299 ),
		);
	}

	public function test_4xx_429_5xx_and_timeout_backoff_are_bounded_and_token_safe() {
		$cases = array( 401 => 900, 403 => 900, 404 => 900, 409 => 900, 422 => 900, 429 => 60, 503 => 60 );

		foreach ( $cases as $status => $expiration ) {
			codegenie_test_reset();
			$options = $this->configuredOptions();
			$client = new Codegenie_Pulse_Client( $options );
			$GLOBALS['codegenie_test']['remote_result'] = array( 'response' => array( 'code' => $status ), 'body' => '{}' );
			$result = $client->send_error( array( 'message' => 'synthetic' ) );
			$backoff = $GLOBALS['codegenie_test']['transients'][ Codegenie_Pulse_Options::BACKOFF_KEY . '_error' ];

			$this->assertSame( 'http_' . $status, $result['code'] );
			$this->assertSame( $expiration, $backoff['expiration'] );
			$this->assertStringNotContainsString( str_repeat( 'A', 64 ), wp_json_encode( $result ) );
		}

		codegenie_test_reset();
		$options = $this->configuredOptions();
		$client = new Codegenie_Pulse_Client( $options );
		add_filter( 'codegenie_pulse_http_timeout', function () { return -1; } );
		$GLOBALS['codegenie_test']['remote_result'] = new WP_Error( 'http_request_failed', 'timeout ' . str_repeat( 'A', 64 ) );
		$result = $client->send_error( array( 'message' => 'synthetic' ) );
		$this->assertSame( 'network_error', $result['code'] );
		$this->assertSame( 1.0, $GLOBALS['codegenie_test']['remote_calls'][0]['args']['timeout'] );
		$this->assertStringNotContainsString( str_repeat( 'A', 64 ), wp_json_encode( $result ) );
	}

	public function test_reporting_recursion_is_skipped_and_exception_handler_is_untouched() {
		$options = $this->configuredOptions( array( 'error_capture_mode' => Codegenie_Pulse_Options::CAPTURE_EXTENDED ) );
		$reporter = new Codegenie_Pulse_Reporter( new Codegenie_Pulse_Client( $options ), $options, new Codegenie_Pulse_Redactor() );
		$nested = null;
		$GLOBALS['codegenie_test']['remote_result'] = function () use ( $reporter, &$nested ) {
			$nested = $reporter->report_message( 'recursive event' );
			return array( 'response' => array( 'code' => 202 ), 'body' => '{}' );
		};

		$result = $reporter->report_message( 'outer event' );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'not_available', $nested['code'] );
		$this->assertCount( 1, $GLOBALS['codegenie_test']['remote_calls'] );

		$exception_handler       = function () {};
		$probe                   = function () {};
		$probe_registered        = false;
		$error_handler_registered = false;
		set_exception_handler( $exception_handler );

		try {
			$reporter->register_hooks();
			$error_handler_registered = true;
			$active                   = set_exception_handler( $probe );
			$probe_registered         = true;
			$this->assertSame( $exception_handler, $active );
		} finally {
			if ( $probe_registered ) {
				restore_exception_handler();
			}
			restore_exception_handler();
			if ( $error_handler_registered ) {
				restore_error_handler();
			}
		}
	}

	public function test_previous_error_handler_exception_is_not_swallowed() {
		$options = $this->configuredOptions( array( 'error_capture_mode' => Codegenie_Pulse_Options::CAPTURE_EXTENDED ) );
		$reporter = new Codegenie_Pulse_Reporter( new Codegenie_Pulse_Client( $options ), $options, new Codegenie_Pulse_Redactor() );
		$property = new ReflectionProperty( $reporter, 'previous_error_handler' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( $reporter, function () { throw new RuntimeException( 'previous handler' ); } );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'previous handler' );
		$reporter->capture_php_error( E_NOTICE, 'not captured in extended mode', __FILE__, 1 );
	}

	public function test_strict_level_is_captured_only_before_php_84_when_enabled() {
		$previous_mask = error_reporting();

		try {
			// PHPUnit and CI may exclude the legacy E_STRICT bit from their process mask.
			// Use its numeric value because referencing E_STRICT is deprecated on PHP 8.4+.
			error_reporting( $previous_mask | 2048 );
			$this->assertSame( 2048, error_reporting() & 2048 );

			$options = $this->configuredOptions( array( 'error_capture_mode' => Codegenie_Pulse_Options::CAPTURE_DEBUG ) );
			$reporter = new Codegenie_Pulse_Reporter( new Codegenie_Pulse_Client( $options ), $options, new Codegenie_Pulse_Redactor() );
			$reporter->capture_php_error( 2048, 'legacy strict level', __FILE__, 1 );

			$this->assertCount( PHP_VERSION_ID < 80400 ? 1 : 0, $GLOBALS['codegenie_test']['remote_calls'] );
		} finally {
			error_reporting( $previous_mask );
		}

		$this->assertSame( $previous_mask, error_reporting() );
	}

	public function test_suppressed_non_fatal_level_is_not_sent() {
		$previous_mask = error_reporting();

		try {
			error_reporting( $previous_mask & ~E_WARNING );
			$this->assertSame( 0, error_reporting() & E_WARNING );

			$options = $this->configuredOptions( array( 'error_capture_mode' => Codegenie_Pulse_Options::CAPTURE_EXTENDED ) );
			$reporter = new Codegenie_Pulse_Reporter( new Codegenie_Pulse_Client( $options ), $options, new Codegenie_Pulse_Redactor() );
			$reporter->capture_php_error( E_WARNING, 'suppressed warning', __FILE__, 1 );

			$this->assertCount( 0, $GLOBALS['codegenie_test']['remote_calls'] );
		} finally {
			error_reporting( $previous_mask );
		}

		$this->assertSame( $previous_mask, error_reporting() );
	}

	public function test_capture_modes_sampling_deduplication_limit_and_previous_handler_delegation() {
		$options = $this->configuredOptions( array( 'error_capture_mode' => Codegenie_Pulse_Options::CAPTURE_EXTENDED ) );
		$reporter = new Codegenie_Pulse_Reporter( new Codegenie_Pulse_Client( $options ), $options, new Codegenie_Pulse_Redactor() );
		$delegated = array();
		$property = new ReflectionProperty( $reporter, 'previous_error_handler' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( $reporter, function () use ( &$delegated ) { $delegated[] = func_get_args(); return true; } );

		$this->assertTrue( $reporter->capture_php_error( E_NOTICE, 'ignored notice', __FILE__, 10 ) );
		$this->assertTrue( $reporter->capture_php_error( E_WARNING, 'sampled warning', __FILE__, 11 ) );
		$this->assertTrue( $reporter->capture_php_error( E_WARNING, 'sampled warning', __FILE__, 11 ) );
		$this->assertCount( 3, $delegated );
		$this->assertCount( 1, $GLOBALS['codegenie_test']['remote_calls'] );

		$fresh = new Codegenie_Pulse_Reporter( new Codegenie_Pulse_Client( $options ), $options, new Codegenie_Pulse_Redactor() );
		$fresh->capture_php_error( E_WARNING, 'sampled warning', __FILE__, 11 );
		$this->assertCount( 1, $GLOBALS['codegenie_test']['remote_calls'], 'Cross-request sampling should suppress the duplicate.' );

		add_filter( 'codegenie_pulse_non_fatal_per_request_limit', function () { return 1; } );
		$limited = new Codegenie_Pulse_Reporter( new Codegenie_Pulse_Client( $options ), $options, new Codegenie_Pulse_Redactor() );
		$limited->capture_php_error( E_WARNING, 'unique warning one', __FILE__, 20 );
		$limited->capture_php_error( E_WARNING, 'unique warning two', __FILE__, 21 );
		$this->assertCount( 2, $GLOBALS['codegenie_test']['remote_calls'] );

		$debug_options = $this->configuredOptions( array( 'error_capture_mode' => Codegenie_Pulse_Options::CAPTURE_DEBUG ) );
		$debug = new Codegenie_Pulse_Reporter( new Codegenie_Pulse_Client( $debug_options ), $debug_options, new Codegenie_Pulse_Redactor() );
		$debug->capture_php_error( E_NOTICE, 'debug notice', __FILE__, 30 );
		$this->assertCount( 3, $GLOBALS['codegenie_test']['remote_calls'] );
	}
}
