<?php

require_once dirname( __DIR__, 2 ) . '/scripts/lib/wordpress-org-asset-manifest.php';

final class WordPressOrgAssetManifestTest extends Codegenie_Pulse_Test_Case {
	private $temporary_directories = array();

	protected function tearDown(): void {
		foreach ( $this->temporary_directories as $directory ) {
			$this->removeDirectory( $directory );
		}

		parent::tearDown();
	}

	public function test_tracked_manifest_is_valid_and_matches_plugin_contracts() {
		$root     = dirname( __DIR__, 2 );
		$manifest = Codegenie_WordPress_Org_Asset_Manifest::from_directory(
			$root . '/wordpress-org/assets/manifest.json',
			$root . '/wordpress-org/assets',
			'1.2.2'
		);

		$this->assertSame( 1, $manifest['schema_version'] );
		$this->assertSame( 'codegenie-pulse-connector', $manifest['plugin_slug'] );
		$this->assertSame( '1.2.2', $manifest['plugin_version'] );
		$this->assertCount( 7, $manifest['assets'] );
		$this->assertCount( 7, $manifest['publication_blockers'] );

		$output = array();
		$status = 0;
		exec( 'git -C ' . escapeshellarg( $root ) . ' ls-files --error-unmatch wordpress-org/assets/manifest.json 2>&1', $output, $status );
		$this->assertSame( 0, $status, implode( "\n", $output ) );
	}

	public function test_duplicate_filenames_are_rejected() {
		$data             = $this->manifestData();
		$data['assets'][] = $data['assets'][0];

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Duplicate WordPress.org asset filename' );
		$this->validateData( $data );
	}

	/**
	 * @dataProvider invalidManifestEntryProvider
	 */
	public function test_unknown_or_mismatched_asset_contracts_are_rejected( $field, $value, $message ) {
		$data                         = $this->manifestData();
		$data['assets'][0][ $field ] = $value;

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( $message );
		$this->validateData( $data );
	}

	public function invalidManifestEntryProvider() {
		return array(
			'status'    => array( 'status', 'generated', 'Unknown WordPress.org asset status' ),
			'type'      => array( 'type', 'logo', 'Unknown or mismatched WordPress.org asset type' ),
			'extension' => array( 'filename', 'icon-128x128.jpg', 'Unsupported WordPress.org asset filename or extension' ),
			'width'     => array( 'width', 129, 'WordPress.org asset dimensions do not match' ),
			'height'    => array( 'height', 127, 'WordPress.org asset dimensions do not match' ),
			'source'    => array( 'source', 'automatic-generator', 'Unknown or mismatched WordPress.org asset source' ),
			'privacy'   => array( 'privacy_review', 'automatic', 'Unknown WordPress.org asset privacy_review' ),
		);
	}

	public function test_approved_asset_requires_a_real_file_and_privacy_approval() {
		$data                                = $this->manifestData();
		$data['assets'][0]['status']          = 'approved';
		$data['assets'][0]['privacy_review']  = 'pending';

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Approved WordPress.org asset file is missing' );
		$this->validateData( $data );
	}

	public function test_approved_asset_with_wrong_dimensions_is_rejected() {
		$data                               = $this->manifestData();
		$data['assets'][0]['status']         = 'approved';
		$data['assets'][0]['privacy_review'] = 'approved';
		$bytes                              = $this->png( 127, 128 );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'file dimensions do not match' );
		$this->validateData( $data, function ( $filename ) use ( $bytes ) {
			return 'icon-128x128.png' === $filename ? $bytes : null;
		} );
	}

	public function test_approved_asset_with_real_file_still_requires_human_privacy_approval() {
		$data                               = $this->manifestData();
		$data['assets'][0]['status']         = 'approved';
		$data['assets'][0]['privacy_review'] = 'pending';
		$bytes                              = $this->png( 128, 128 );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'lacks approved privacy review' );
		$this->validateData(
			$data,
			function ( $filename ) use ( $bytes ) {
				return 'icon-128x128.png' === $filename ? $bytes : null;
			}
		);
	}

	public function test_missing_required_asset_without_image_is_valid_but_blocks_publication() {
		$manifest = $this->validateData( $this->manifestData() );

		$this->assertCount( 7, $manifest['publication_blockers'] );
		$this->assertStringContainsString( 'icon-128x128.png', $manifest['publication_blockers'][0] );
	}

	public function test_directory_and_source_zip_readers_interpret_the_same_fixture() {
		$directory     = $this->temporaryDirectory();
		$manifest_json = json_encode( $this->manifestData(), JSON_PRETTY_PRINT );
		file_put_contents( $directory . '/manifest.json', $manifest_json );

		$from_directory = Codegenie_WordPress_Org_Asset_Manifest::from_directory(
			$directory . '/manifest.json',
			$directory,
			'1.2.2'
		);

		$zip_path = $directory . '/source.zip';
		$zip      = new ZipArchive();
		$this->assertTrue( $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) );
		$root = 'codegenie-pulse-connector-wordpress-1.2.2-source/';
		$zip->addFromString( $root . 'wordpress-org/assets/manifest.json', $manifest_json );
		$zip->close();

		$this->assertTrue( $zip->open( $zip_path, ZipArchive::CHECKCONS ) );
		$from_zip = Codegenie_WordPress_Org_Asset_Manifest::from_zip( $zip, $root, '1.2.2' );
		$zip->close();

		$this->assertSame( $from_directory, $from_zip );
	}

	public function test_all_release_scripts_delegate_to_the_shared_manifest_contract() {
		$root = dirname( __DIR__, 2 );
		foreach ( array( 'build-release.php', 'verify-source-package.php', 'prepare-wordpress-org.php' ) as $script ) {
			$content = file_get_contents( $root . '/scripts/' . $script );
			$this->assertStringContainsString( 'lib/wordpress-org-asset-manifest.php', $content, $script );
			$this->assertStringContainsString( 'Codegenie_WordPress_Org_Asset_Manifest::', $content, $script );
		}
	}

	private function validateData( $data, $asset_reader = null ) {
		if ( null === $asset_reader ) {
			$asset_reader = function () {
				return null;
			};
		}

		return Codegenie_WordPress_Org_Asset_Manifest::validate( json_encode( $data ), $asset_reader, '1.2.2' );
	}

	private function manifestData() {
		$json = file_get_contents( dirname( __DIR__, 2 ) . '/wordpress-org/assets/manifest.json' );

		return json_decode( $json, true );
	}

	private function png( $width, $height ) {
		$signature = "\x89PNG\r\n\x1a\n";
		$ihdr      = pack( 'NNCCCCC', $width, $height, 8, 2, 0, 0, 0 );

		return $signature . $this->pngChunk( 'IHDR', $ihdr ) . $this->pngChunk( 'IEND', '' );
	}

	private function pngChunk( $type, $data ) {
		return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
	}

	private function temporaryDirectory() {
		$directory = sys_get_temp_dir() . '/codegenie-wporg-assets-' . bin2hex( random_bytes( 8 ) );
		$this->assertTrue( mkdir( $directory, 0777, true ) );
		$this->temporary_directories[] = $directory;

		return $directory;
	}

	private function removeDirectory( $directory ) {
		if ( ! is_dir( $directory ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $directory );
	}
}
