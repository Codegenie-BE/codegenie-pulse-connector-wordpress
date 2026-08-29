<?php

final class ClientTransientBackoffTest extends Codegenie_Pulse_Test_Case {
	public function test_transient_http_responses_start_bounded_backoff() {
		foreach ( array( 408, 425 ) as $status ) {
			codegenie_test_reset();
			$options = $this->configuredOptions();
			$client  = new Codegenie_Pulse_Client( $options );
			$GLOBALS['codegenie_test']['remote_result'] = array(
				'response' => array( 'code' => $status ),
				'body'     => '{}',
			);

			$result = $client->send_error( array( 'message' => 'synthetic' ) );
			$key    = Codegenie_Pulse_Options::BACKOFF_KEY . '_error';

			$this->assertSame( 'http_' . $status, $result['code'] );
			$this->assertArrayHasKey( $key, $GLOBALS['codegenie_test']['transients'] );
			$this->assertSame( MINUTE_IN_SECONDS, $GLOBALS['codegenie_test']['transients'][ $key ]['expiration'] );
			$this->assertSame( 'backoff', $client->send_error( array( 'message' => 'followup' ) )['code'] );
			$this->assertCount( 1, $GLOBALS['codegenie_test']['remote_calls'] );
		}
	}
}
