<?php
/**
 * Shared validation for the source-only WordPress.org asset manifest.
 */

final class Codegenie_WordPress_Org_Asset_Manifest {
	const SCHEMA_VERSION = 1;
	const PLUGIN_SLUG    = 'codegenie-pulse-connector';

	/**
	 * Load and validate an asset manifest and its files from a directory.
	 *
	 * @param string $manifest_path   Manifest path.
	 * @param string $asset_root      Asset directory.
	 * @param string $expected_version Expected plugin version.
	 * @return array<string,mixed>
	 */
	public static function from_directory( $manifest_path, $asset_root, $expected_version ) {
		if ( ! is_file( $manifest_path ) ) {
			throw new RuntimeException( 'WordPress.org asset manifest is missing.' );
		}

		$json = file_get_contents( $manifest_path );
		if ( false === $json ) {
			throw new RuntimeException( 'WordPress.org asset manifest cannot be read.' );
		}

		return self::validate(
			$json,
			function ( $filename ) use ( $asset_root ) {
				$path = rtrim( $asset_root, '/\\' ) . DIRECTORY_SEPARATOR . $filename;

				return is_file( $path ) ? file_get_contents( $path ) : null;
			},
			$expected_version
		);
	}

	/**
	 * Load and validate an asset manifest and its files from a source ZIP.
	 *
	 * @param ZipArchive $zip              Open source ZIP.
	 * @param string     $source_root      Source ZIP root including trailing slash.
	 * @param string     $expected_version Expected plugin version.
	 * @return array<string,mixed>
	 */
	public static function from_zip( $zip, $source_root, $expected_version ) {
		$asset_prefix  = $source_root . 'wordpress-org/assets/';
		$manifest_path = $asset_prefix . 'manifest.json';
		$json          = $zip->getFromName( $manifest_path );

		if ( ! is_string( $json ) ) {
			throw new RuntimeException( 'WordPress.org asset manifest is missing from the source ZIP.' );
		}

		return self::validate(
			$json,
			function ( $filename ) use ( $zip, $asset_prefix ) {
				$content = $zip->getFromName( $asset_prefix . $filename );

				return is_string( $content ) ? $content : null;
			},
			$expected_version
		);
	}

	/**
	 * Validate manifest JSON with a caller-provided asset reader.
	 *
	 * @param string   $json             Manifest JSON.
	 * @param callable $asset_reader     Returns asset bytes or null.
	 * @param string   $expected_version Expected plugin version.
	 * @return array<string,mixed>
	 */
	public static function validate( $json, $asset_reader, $expected_version ) {
		$data = json_decode( $json, true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			throw new RuntimeException( 'WordPress.org asset manifest contains invalid JSON.' );
		}

		if ( ! isset( $data['schema_version'] ) || self::SCHEMA_VERSION !== $data['schema_version'] ) {
			throw new RuntimeException( 'WordPress.org asset manifest has an unsupported schema_version.' );
		}

		if ( ! isset( $data['plugin_slug'] ) || self::PLUGIN_SLUG !== $data['plugin_slug'] ) {
			throw new RuntimeException( 'WordPress.org asset manifest plugin_slug does not match the plugin contract.' );
		}

		if ( ! isset( $data['plugin_version'] ) || $expected_version !== $data['plugin_version'] ) {
			throw new RuntimeException( 'WordPress.org asset manifest plugin_version does not match the release.' );
		}

		if ( ! isset( $data['assets'] ) || ! is_array( $data['assets'] ) ) {
			throw new RuntimeException( 'WordPress.org asset manifest assets must be an array.' );
		}

		$definitions = self::definitions();
		$seen        = array();
		$assets      = array();
		$blockers    = array();

		foreach ( $data['assets'] as $asset ) {
			$required_fields = array( 'filename', 'type', 'width', 'height', 'required', 'status', 'source', 'privacy_review' );

			if ( ! is_array( $asset ) ) {
				throw new RuntimeException( 'WordPress.org asset manifest contains a non-object asset entry.' );
			}

			foreach ( $required_fields as $field ) {
				if ( ! array_key_exists( $field, $asset ) ) {
					throw new RuntimeException( 'WordPress.org asset entry is missing field: ' . $field . '.' );
				}
			}

			$filename = $asset['filename'];
			if ( ! is_string( $filename ) || ! isset( $definitions[ $filename ] ) ) {
				throw new RuntimeException( 'Unsupported WordPress.org asset filename or extension.' );
			}

			if ( isset( $seen[ $filename ] ) ) {
				throw new RuntimeException( 'Duplicate WordPress.org asset filename: ' . $filename . '.' );
			}
			$seen[ $filename ] = true;

			$definition = $definitions[ $filename ];
			if ( ! is_string( $asset['type'] ) || $definition['type'] !== $asset['type'] ) {
				throw new RuntimeException( 'Unknown or mismatched WordPress.org asset type: ' . $filename . '.' );
			}

			if ( ! is_int( $asset['width'] ) || ! is_int( $asset['height'] ) || $definition['width'] !== $asset['width'] || $definition['height'] !== $asset['height'] ) {
				throw new RuntimeException( 'WordPress.org asset dimensions do not match the supported contract: ' . $filename . '.' );
			}

			if ( ! is_bool( $asset['required'] ) ) {
				throw new RuntimeException( 'WordPress.org asset required must be boolean: ' . $filename . '.' );
			}

			if ( ! is_string( $asset['status'] ) || ! in_array( $asset['status'], array( 'approved', 'missing' ), true ) ) {
				throw new RuntimeException( 'Unknown WordPress.org asset status: ' . $filename . '.' );
			}

			if ( ! is_string( $asset['source'] ) || $definition['source'] !== $asset['source'] ) {
				throw new RuntimeException( 'Unknown or mismatched WordPress.org asset source: ' . $filename . '.' );
			}

			if ( ! is_string( $asset['privacy_review'] ) || ! in_array( $asset['privacy_review'], array( 'approved', 'pending' ), true ) ) {
				throw new RuntimeException( 'Unknown WordPress.org asset privacy_review: ' . $filename . '.' );
			}

			$content = call_user_func( $asset_reader, $filename );
			if ( 'approved' === $asset['status'] && ! is_string( $content ) ) {
				throw new RuntimeException( 'Approved WordPress.org asset file is missing: ' . $filename . '.' );
			}

			if ( 'approved' === $asset['status'] && 'approved' !== $asset['privacy_review'] ) {
				throw new RuntimeException( 'Approved WordPress.org asset lacks approved privacy review: ' . $filename . '.' );
			}

			if ( is_string( $content ) ) {
				self::validate_image( $filename, $content, $definition );
			}

			if ( 'missing' === $asset['status'] && $asset['required'] ) {
				$blockers[] = $filename . ' requires a human-supplied file plus brand and privacy approval.';
			}

			$assets[] = $asset;
		}

		if ( count( $assets ) !== count( $definitions ) ) {
			throw new RuntimeException( 'WordPress.org asset manifest does not list every supported release asset.' );
		}

		return array(
			'schema_version'       => self::SCHEMA_VERSION,
			'plugin_slug'          => self::PLUGIN_SLUG,
			'plugin_version'       => $expected_version,
			'assets'               => $assets,
			'publication_blockers' => $blockers,
		);
	}

	/**
	 * Supported WordPress.org release assets.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function definitions() {
		return array(
			'icon-128x128.png'  => array( 'type' => 'icon', 'width' => 128, 'height' => 128, 'maximum_size' => 1048576, 'source' => 'human-codegenie-brand-artwork' ),
			'icon-256x256.png'  => array( 'type' => 'icon', 'width' => 256, 'height' => 256, 'maximum_size' => 1048576, 'source' => 'human-codegenie-brand-artwork' ),
			'banner-772x250.png' => array( 'type' => 'banner', 'width' => 772, 'height' => 250, 'maximum_size' => 4194304, 'source' => 'human-codegenie-brand-artwork' ),
			'banner-1544x500.png' => array( 'type' => 'banner', 'width' => 1544, 'height' => 500, 'maximum_size' => 4194304, 'source' => 'human-codegenie-brand-artwork' ),
			'screenshot-1.png' => array( 'type' => 'screenshot', 'width' => 1440, 'height' => 1080, 'maximum_size' => 10485760, 'source' => 'synthetic-local-wordpress-capture' ),
			'screenshot-2.png' => array( 'type' => 'screenshot', 'width' => 1440, 'height' => 1080, 'maximum_size' => 10485760, 'source' => 'synthetic-local-wordpress-capture' ),
			'screenshot-3.png' => array( 'type' => 'screenshot', 'width' => 1440, 'height' => 1080, 'maximum_size' => 10485760, 'source' => 'synthetic-local-wordpress-capture' ),
		);
	}

	/**
	 * Validate image bytes against dimensions, MIME type, and size.
	 *
	 * @param string              $filename   Asset filename.
	 * @param string              $content    Image bytes.
	 * @param array<string,mixed> $definition Asset definition.
	 * @return void
	 */
	private static function validate_image( $filename, $content, $definition ) {
		$dimensions = getimagesizefromstring( $content );

		if ( false === $dimensions || $definition['width'] !== $dimensions[0] || $definition['height'] !== $dimensions[1] ) {
			throw new RuntimeException( 'WordPress.org asset file dimensions do not match the manifest: ' . $filename . '.' );
		}

		if ( ! isset( $dimensions['mime'] ) || 'image/png' !== $dimensions['mime'] ) {
			throw new RuntimeException( 'WordPress.org asset file must be a PNG image: ' . $filename . '.' );
		}

		if ( strlen( $content ) > $definition['maximum_size'] ) {
			throw new RuntimeException( 'WordPress.org asset exceeds the supported size limit: ' . $filename . '.' );
		}
	}
}
