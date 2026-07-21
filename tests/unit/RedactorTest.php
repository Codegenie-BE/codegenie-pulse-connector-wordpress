<?php

final class RedactorTest extends Codegenie_Pulse_Test_Case {
	public function test_text_path_url_context_and_stacktrace_are_redacted_and_bounded() {
		$redactor = new Codegenie_Pulse_Redactor();
		$text = 'token=visible-secret user@example.invalid Bearer abc.def.ghi ' . str_repeat( 'x', 3000 );
		$clean = $redactor->text( $text, 120 );

		$this->assertLessThanOrEqual( 120, strlen( $clean ) );
		$this->assertStringContainsString( 'token=[redacted]', $clean );
		$this->assertStringContainsString( '[redacted-email]', $clean );
		$this->assertStringNotContainsString( 'visible-secret', $clean );

		$path = $redactor->path( ABSPATH . 'wp-content/plugins/example/plugin.php' );
		$this->assertStringStartsWith( '[WP_CONTENT]/', $path );

		$_SERVER['REQUEST_URI'] = '/account/user%40example.invalid/' . str_repeat( 'z', 30 ) . '?token=visible-secret';
		$url = $redactor->current_url();
		$this->assertSame( 'https://wordpress.example/account/redacted-email/redacted', $url );

		$context = $redactor->context( array(
			'feature' => str_repeat( 'a', 1500 ),
			'password' => 'must disappear',
			'nested' => array( 'api_key' => 'must disappear', 'safe' => 'kept' ),
		) );
		$this->assertArrayNotHasKey( 'password', $context );
		$this->assertArrayNotHasKey( 'api_key', $context['nested'] );
		$this->assertLessThanOrEqual( 1000, strlen( $context['feature'] ) );

		$options = $this->configuredOptions();
		$reporter = new Codegenie_Pulse_Reporter( new Codegenie_Pulse_Client( $options ), $options, $redactor );
		$reporter->report_exception( new RuntimeException( 'secret=visible-secret user@example.invalid' ), array( 'token' => 'drop' ) );
		$payload = json_decode( $GLOBALS['codegenie_test']['remote_calls'][0]['args']['body'], true );
		$this->assertStringNotContainsString( 'visible-secret', $payload['message'] );
		$this->assertArrayNotHasKey( 'token', $payload['context'] );
		$this->assertLessThanOrEqual( 12000, strlen( $payload['stacktrace'] ) );
	}
}

