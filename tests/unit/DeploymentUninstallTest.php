<?php

final class DeploymentUninstallTest extends Codegenie_Pulse_Test_Case {
	public function test_main_plugin_file_loads_and_activation_has_no_fatal_error() {
		$output = array();
		$status = 0;
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/fixtures/plugin-activation-runner.php' ) . ' 2>&1', $output, $status );
		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$result = json_decode( implode( "\n", $output ), true );
		$this->assertSame( '1.2.1', $result['version'] );
		$this->assertSame( 'no', $result['settings_autoload'] );
		$this->assertSame( 'no', $result['state_autoload'] );
	}

	public function test_deployment_idempotency_is_stable_for_the_same_minute() {
		$options = $this->configuredOptions();
		$tracker = new Codegenie_Pulse_Deployment_Tracker( new Codegenie_Pulse_Client( $options ), new Codegenie_Pulse_Redactor() );

		$tracker->track_plugin_activation( 'example/example.php', false );
		$tracker->track_plugin_activation( 'example/example.php', false );
		$this->assertCount( 2, $GLOBALS['codegenie_test']['remote_calls'] );
		$first = json_decode( $GLOBALS['codegenie_test']['remote_calls'][0]['args']['body'], true );
		$second = json_decode( $GLOBALS['codegenie_test']['remote_calls'][1]['args']['body'], true );
		$this->assertSame( $first['idempotency_key'], $second['idempotency_key'] );
		$this->assertMatchesRegularExpression( '/^wp-[a-f0-9]{40}$/', $first['idempotency_key'] );
	}

	public function test_network_activation_installs_defaults_on_every_multisite_site() {
		$GLOBALS['codegenie_test']['multisite'] = true;
		$GLOBALS['codegenie_test']['sites'] = array( 2, 4 );

		Codegenie_Pulse_Plugin::activate( true );

		$this->assertSame( array( array( 'to', 2 ), array( 'restore', 2 ), array( 'to', 4 ), array( 'restore', 4 ) ), $GLOBALS['codegenie_test']['switched'] );
		$added = array_values( array_filter( $GLOBALS['codegenie_test']['added'], function ( $row ) { return Codegenie_Pulse_Options::OPTION_NAME === $row[1] || Codegenie_Pulse_Options::STATE_NAME === $row[1]; } ) );
		$this->assertSame(
			array(
				array( 2, Codegenie_Pulse_Options::OPTION_NAME, 'no' ),
				array( 2, Codegenie_Pulse_Options::STATE_NAME, 'no' ),
				array( 4, Codegenie_Pulse_Options::OPTION_NAME, 'no' ),
				array( 4, Codegenie_Pulse_Options::STATE_NAME, 'no' ),
			),
			$added
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_multisite_uninstall_removes_secrets_and_runtime_state_on_every_site() {
		codegenie_test_reset();
		$GLOBALS['codegenie_test']['multisite'] = true;
		$GLOBALS['codegenie_test']['sites'] = array( 2, 4 );
		define( 'WP_UNINSTALL_PLUGIN', true );
		require dirname( __DIR__, 2 ) . '/uninstall.php';

		$expected_keys = array(
			'codegenie_pulse_connector_settings',
			'codegenie_pulse_connector_state',
			'codegenie_pulse_connector_backoff',
			'codegenie_pulse_connector_backoff_error',
			'codegenie_pulse_connector_backoff_deployment',
			'codegenie_pulse_connector_non_fatal_samples',
		);
		foreach ( array( 2, 4 ) as $site_id ) {
			$deleted_for_site = array_values( array_map( function ( $row ) { return $row[2]; }, array_filter( $GLOBALS['codegenie_test']['deleted'], function ( $row ) use ( $site_id ) { return $row[0] === $site_id; } ) ) );
			$this->assertSame( $expected_keys, $deleted_for_site );
		}
		$this->assertSame( array( array( 'to', 2 ), array( 'restore', 2 ), array( 'to', 4 ), array( 'restore', 4 ) ), $GLOBALS['codegenie_test']['switched'] );
	}
}
