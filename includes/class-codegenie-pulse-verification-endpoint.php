<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves the platform ownership token through WordPress.
 */
final class Codegenie_Pulse_Verification_Endpoint {
	/** @var Codegenie_Pulse_Options */
	private $options;

	/**
	 * @param Codegenie_Pulse_Options $options Plugin options.
	 */
	public function __construct( Codegenie_Pulse_Options $options ) {
		$this->options = $options;
	}

	/**
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'parse_request', array( $this, 'maybe_serve_token' ), 0 );
	}

	/**
	 * Serve /.well-known/codegenie-pulse.txt without a rewrite flush.
	 *
	 * @return void
	 */
	public function maybe_serve_token() {
		// The raw URI is parsed to an exact, constant path before any response is served.
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$path        = wp_parse_url( (string) $request_uri, PHP_URL_PATH );

		if ( '/.well-known/codegenie-pulse.txt' !== $path ) {
			return;
		}

		$token  = (string) $this->options->get( 'verification_token', '' );
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			status_header( 405 );
			header( 'Allow: GET, HEAD' );
			exit;
		}

		if ( '' === $token ) {
			status_header( 404 );
			exit;
		}

		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Cache-Control: no-store, max-age=0' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow', true );

		if ( 'HEAD' !== $method ) {
			echo esc_html( $token );
		}

		exit;
	}
}
