<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies a privacy-first allowlist before payloads leave WordPress.
 */
final class Codegenie_Pulse_Redactor {
	/** @var string[] */
	private $sensitive_keys = array(
		'authorization',
		'cookie',
		'headers',
		'token',
		'password',
		'passwd',
		'secret',
		'apikey',
		'api_key',
		'accesskey',
		'accesstoken',
		'refreshtoken',
		'session',
		'csrf',
		'xsrf',
		'creditcard',
		'cardnumber',
		'iban',
		'body',
		'payload',
		'formdata',
		'postdata',
		'remoteaddr',
		'ipaddress',
	);

	/**
	 * Sanitize a message or stack trace.
	 *
	 * @param mixed $value      Input value.
	 * @param int   $max_length Maximum length.
	 * @return string
	 */
	public function text( $value, $max_length = 2000 ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$text = (string) $value;
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text );

		$patterns = array(
			'#(/api/ingest/(?:errors|deployments)/)[A-Za-z0-9]{64}#i' => '$1[redacted]',
			'/\b(Bearer\s+)[A-Za-z0-9._~+\/-]+=*/i'                 => '$1[redacted]',
			'/\b(password|passwd|secret|token|api[_-]?key|authorization)\b\s*[:=]\s*([^\s,;&]+)/i' => '$1=[redacted]',
			'/([?&](?:password|passwd|secret|token|api[_-]?key|signature|authorization)=)[^&#\s]*/i' => '$1[redacted]',
			'/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i'              => '[redacted-email]',
			'/\b[A-Za-z0-9_-]{48,}\b/'                               => '[redacted-long-value]',
		);

		foreach ( $patterns as $pattern => $replacement ) {
			$redacted = preg_replace( $pattern, $replacement, $text );
			$text     = is_string( $redacted ) ? $redacted : $text;
		}

		return $this->limit( $text, max( 1, (int) $max_length ) );
	}

	/**
	 * Sanitize a filesystem path while retaining useful code location context.
	 *
	 * @param mixed $path Path value.
	 * @return string
	 */
	public function path( $path ) {
		$path = $this->text( $path, 1024 );

		$roots = array();

		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$roots[ wp_normalize_path( WP_CONTENT_DIR ) ] = '[WP_CONTENT]/';
		}

		if ( defined( 'ABSPATH' ) ) {
			$roots[ wp_normalize_path( ABSPATH ) ] = '[ABSPATH]/';
		}

		$normalized = wp_normalize_path( $path );

		foreach ( $roots as $root => $replacement ) {
			if ( '' !== $root && 0 === strpos( $normalized, untrailingslashit( $root ) ) ) {
				$normalized = $replacement . ltrim( substr( $normalized, strlen( untrailingslashit( $root ) ) ), '/' );
				break;
			}
		}

		return $this->limit( $normalized, 1024 );
	}

	/**
	 * Build a query-free URL for the current request.
	 *
	 * @return string|null
	 */
	public function current_url() {
		$home = wp_parse_url( home_url( '/' ) );

		if ( ! is_array( $home ) || empty( $home['scheme'] ) || empty( $home['host'] ) ) {
			return null;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$request_path = wp_parse_url( (string) $request_uri, PHP_URL_PATH );
		$request_path = is_string( $request_path ) && 0 === strpos( $request_path, '/' ) ? $request_path : '/';
		$request_path = preg_replace( '/[A-Z0-9._%+-]+(?:@|%40)[A-Z0-9.-]+\.[A-Z]{2,}/i', 'redacted-email', $request_path );
		$request_path = preg_replace( '#/([A-Za-z0-9_-]{24,})(?=/|$)#', '/redacted', (string) $request_path );
		$port         = isset( $home['port'] ) ? ':' . (int) $home['port'] : '';

		$url = $this->limit(
			(string) $home['scheme'] . '://' . (string) $home['host'] . $port . $request_path,
			2048
		);
		$url = esc_url_raw( $url, array( 'http', 'https' ) );

		return '' !== $url ? $url : null;
	}

	/**
	 * Sanitize developer-provided context recursively.
	 *
	 * @param mixed $context Context input.
	 * @return array<string, mixed>
	 */
	public function context( $context ) {
		if ( ! is_array( $context ) ) {
			return array();
		}

		$clean = $this->context_level( $context, 0 );

		while ( strlen( (string) wp_json_encode( $clean ) ) > 7000 && count( $clean ) > 0 ) {
			unset( $clean['_truncated'] );

			if ( empty( $clean ) ) {
				break;
			}

			array_pop( $clean );
			$clean['_truncated'] = true;
		}

		return $clean;
	}

	/**
	 * @param array<mixed, mixed> $context Context level.
	 * @param int                 $depth   Current depth.
	 * @return array<string, mixed>
	 */
	private function context_level( $context, $depth ) {
		if ( $depth >= 4 ) {
			return array( '_truncated' => true );
		}

		$clean = array();
		$count = 0;

		foreach ( $context as $key => $value ) {
			if ( $count >= 50 ) {
				$clean['_truncated'] = true;
				break;
			}

			$key = sanitize_key( (string) $key );

			if ( '' === $key ) {
				$key = 'field_' . $count;
			}

			if ( $this->is_sensitive_key( $key ) ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$clean[ $key ] = $this->context_level( $value, $depth + 1 );
			} elseif ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
				$clean[ $key ] = $value;
			} elseif ( is_scalar( $value ) ) {
				$clean[ $key ] = $this->text( $value, 1000 );
			} else {
				$clean[ $key ] = '[unsupported]';
			}

			++$count;
		}

		return $clean;
	}

	/**
	 * @param string $key Context key.
	 * @return bool
	 */
	private function is_sensitive_key( $key ) {
		$normalized = preg_replace( '/[^a-z0-9]/', '', strtolower( $key ) );

		if ( ! is_string( $normalized ) || '' === $normalized ) {
			return false;
		}

		foreach ( $this->sensitive_keys as $sensitive_key ) {
			$sensitive_key = preg_replace( '/[^a-z0-9]/', '', $sensitive_key );

			if ( $normalized === $sensitive_key || false !== strpos( $normalized, $sensitive_key ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Multibyte-safe string limit without requiring mbstring.
	 *
	 * @param string $value      Value.
	 * @param int    $max_length Maximum length.
	 * @return string
	 */
	private function limit( $value, $max_length ) {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $max_length );
		}

		return substr( $value, 0, $max_length );
	}
}
