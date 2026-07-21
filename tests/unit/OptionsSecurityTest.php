<?php

final class OptionsSecurityTest extends Codegenie_Pulse_Test_Case {
	public function test_dsn_and_origin_validation_are_strict_and_normalized() {
		$options = $this->options();
		$valid = 'https://Pulse.Example/api/ingest/errors/' . str_repeat( 'A', 64 ) . '/';

		$this->assertSame( rtrim( $valid, '/' ), $options->validate_dsn( $valid ) );
		$this->assertTrue( is_wp_error( $options->validate_dsn( 'http://pulse.example/api/ingest/errors/' . str_repeat( 'A', 64 ) ) ) );
		$this->assertTrue( is_wp_error( $options->validate_dsn( 'https://user:pass@pulse.example/api/ingest/errors/' . str_repeat( 'A', 64 ) ) ) );
		$this->assertTrue( is_wp_error( $options->validate_dsn( 'https://pulse.example/api/ingest/errors/' . str_repeat( 'A', 64 ) . '?leak=yes' ) ) );
		$this->assertTrue( is_wp_error( $options->validate_dsn( 'https://127.0.0.1/api/ingest/errors/' . str_repeat( 'A', 64 ) ) ) );

		$connection = new Codegenie_Pulse_Connection( $options );
		$this->assertSame( 'https://pulse.example:8443', $connection->normalize_origin( 'https://Pulse.Example:8443/' ) );
		$this->assertTrue( is_wp_error( $connection->normalize_origin( 'https://pulse.example/path' ) ) );
		$this->assertTrue( is_wp_error( $connection->normalize_origin( "https://pulse.example\r\nInjected: yes" ) ) );
	}

	public function test_secret_encryption_round_trip_and_salt_rotation_failure() {
		$store = new Codegenie_Pulse_Secret_Store();
		$secret = 'https://pulse.example/api/ingest/errors/' . str_repeat( 'B', 64 );
		$encrypted = $store->encrypt( $secret );

		$this->assertIsString( $encrypted );
		$this->assertStringStartsWith( 'v1:', $encrypted );
		$this->assertStringNotContainsString( $secret, $encrypted );
		$this->assertSame( $secret, $store->decrypt( $encrypted ) );

		$GLOBALS['codegenie_test']['salt'] = 'rotated-unit-test-auth-salt';
		$this->assertSame( '', $store->decrypt( $encrypted ) );
	}

	public function test_provision_requires_same_origin_dashboard_and_dsn() {
		$options = $this->options();
		$configuration = array(
			'pulse_origin'      => 'https://pulse.example',
			'site_id'           => '12345678-1234-1234-1234-123456789abc',
			'site_url'          => 'https://wordpress.example/',
			'verification_token'=> str_repeat( 'V', 32 ),
			'dsn'               => 'https://pulse.example/api/ingest/errors/' . str_repeat( 'C', 64 ),
			'dashboard_url'     => 'https://pulse.example/websites/123',
			'capabilities'      => array( 'website_monitoring' => true, 'error_monitoring' => true, 'deployment_tracking' => true ),
		);

		$this->assertTrue( $options->provision( $configuration ) );
		$this->assertSame( 'https://pulse.example/websites/123', $options->get( 'pulse_dashboard_url' ) );
		$this->assertSame( $configuration['dsn'], $options->dsn() );

		$configuration['dashboard_url'] = 'https://attacker.example/return';
		$this->assertSame( 'codegenie_pulse_dashboard_origin_mismatch', $options->provision( $configuration )->get_error_code() );
		$configuration['dashboard_url'] = '';
		$configuration['dsn'] = 'https://other.example/api/ingest/errors/' . str_repeat( 'C', 64 );
		$this->assertSame( 'codegenie_pulse_dsn_origin_mismatch', $options->provision( $configuration )->get_error_code() );
	}

	public function test_failed_or_partial_provisioning_write_preserves_existing_settings() {
		$options = $this->configuredOptions(
			array(
				'verification_token' => str_repeat( 'O', 32 ),
				'error_capture_mode' => Codegenie_Pulse_Options::CAPTURE_EXTENDED,
			)
		);
		$before = get_option( Codegenie_Pulse_Options::OPTION_NAME );
		$configuration = array(
			'pulse_origin'       => 'https://pulse.example',
			'site_id'            => '12345678-1234-1234-1234-123456789abc',
			'site_url'           => 'https://wordpress.example',
			'verification_token' => str_repeat( 'N', 32 ),
			'dsn'                => 'https://pulse.example/api/ingest/errors/' . str_repeat( 'N', 64 ),
			'dashboard_url'      => 'https://pulse.example/websites/new',
			'capabilities'       => array( 'website_monitoring' => true, 'error_monitoring' => true, 'deployment_tracking' => true ),
		);

		$GLOBALS['codegenie_test']['update_failures'][ Codegenie_Pulse_Options::OPTION_NAME ] = 1;
		$result = $options->provision( $configuration );
		$this->assertSame( 'codegenie_pulse_storage_failed', $result->get_error_code() );
		$this->assertSame( $before, get_option( Codegenie_Pulse_Options::OPTION_NAME ) );

		$mutated = false;
		$GLOBALS['codegenie_test']['update_mutator'] = function ( $key, $value ) use ( &$mutated ) {
			if ( Codegenie_Pulse_Options::OPTION_NAME === $key && ! $mutated ) {
				$mutated = true;
				unset( $value['encrypted_dsn'] );
			}

			return $value;
		};
		$result = $options->provision( $configuration );
		$this->assertSame( 'codegenie_pulse_storage_failed', $result->get_error_code() );
		$this->assertSame( $before, get_option( Codegenie_Pulse_Options::OPTION_NAME ) );
	}

	public function test_existing_120_settings_keep_dsn_capture_mode_and_verification_token() {
		$store = new Codegenie_Pulse_Secret_Store();
		$dsn = 'https://pulse.example/api/ingest/errors/' . str_repeat( 'M', 64 );
		$GLOBALS['codegenie_test']['options'][ Codegenie_Pulse_Options::OPTION_NAME ] = array(
			'encrypted_dsn'      => $store->encrypt( $dsn ),
			'verification_token' => str_repeat( 'V', 32 ),
			'error_capture_mode' => Codegenie_Pulse_Options::CAPTURE_DEBUG,
		);
		$options = new Codegenie_Pulse_Options( $store );

		$this->assertSame( $dsn, $options->dsn() );
		$this->assertSame( Codegenie_Pulse_Options::CAPTURE_DEBUG, $options->capture_mode() );
		$this->assertSame( str_repeat( 'V', 32 ), $options->get( 'verification_token' ) );
	}
}
