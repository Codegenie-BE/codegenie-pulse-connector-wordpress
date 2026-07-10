<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encrypts the ingestion DSN before WordPress stores it in the options table.
 */
final class Codegenie_Pulse_Secret_Store {
	const CIPHER = 'aes-256-gcm';
	const PREFIX = 'v1:';
	const AAD    = 'codegenie-pulse-connector:v1';

	/**
	 * Whether authenticated encryption is available.
	 *
	 * @return bool
	 */
	public function is_available() {
		return function_exists( 'openssl_encrypt' )
			&& function_exists( 'openssl_decrypt' )
			&& function_exists( 'random_bytes' )
			&& in_array( self::CIPHER, openssl_get_cipher_methods(), true );
	}

	/**
	 * Encrypt a secret.
	 *
	 * @param string $plaintext Secret value.
	 * @return string|WP_Error
	 */
	public function encrypt( $plaintext ) {
		if ( '' === $plaintext ) {
			return '';
		}

		if ( ! $this->is_available() ) {
			return new WP_Error(
				'codegenie_pulse_encryption_unavailable',
				__( 'De PHP OpenSSL-extensie is nodig om de DSN veilig op te slaan.', 'codegenie-pulse-connector' )
			);
		}

		try {
			$iv = random_bytes( 12 );
		} catch ( Exception $exception ) {
			return new WP_Error(
				'codegenie_pulse_random_bytes_failed',
				__( 'De DSN kon niet veilig worden versleuteld.', 'codegenie-pulse-connector' )
			);
		}

		$tag        = '';
		$ciphertext = openssl_encrypt(
			$plaintext,
			self::CIPHER,
			$this->key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			self::AAD,
			16
		);

		if ( false === $ciphertext || 16 !== strlen( $tag ) ) {
			return new WP_Error(
				'codegenie_pulse_encryption_failed',
				__( 'De DSN kon niet veilig worden versleuteld.', 'codegenie-pulse-connector' )
			);
		}

		return self::PREFIX . base64_encode( $iv . $tag . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a stored secret.
	 *
	 * @param string $encrypted Stored value.
	 * @return string
	 */
	public function decrypt( $encrypted ) {
		if ( '' === $encrypted || 0 !== strpos( $encrypted, self::PREFIX ) || ! $this->is_available() ) {
			return '';
		}

		$decoded = base64_decode( substr( $encrypted, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $decoded || strlen( $decoded ) <= 28 ) {
			return '';
		}

		$iv         = substr( $decoded, 0, 12 );
		$tag        = substr( $decoded, 12, 16 );
		$ciphertext = substr( $decoded, 28 );
		$plaintext  = openssl_decrypt(
			$ciphertext,
			self::CIPHER,
			$this->key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			self::AAD
		);

		return is_string( $plaintext ) ? $plaintext : '';
	}

	/**
	 * Derive an encryption key from WordPress salts.
	 *
	 * @return string
	 */
	private function key() {
		return hash_hmac( 'sha256', self::AAD, wp_salt( 'auth' ), true );
	}
}

