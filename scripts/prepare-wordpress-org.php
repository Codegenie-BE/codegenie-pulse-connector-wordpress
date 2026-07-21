<?php
/**
 * Build a local, network-free WordPress.org SVN tree from the installation ZIP.
 */

require_once __DIR__ . '/lib/wordpress-org-asset-manifest.php';

if ( ! isset( $argv[1] ) || ! is_file( $argv[1] ) ) {
	fwrite( STDERR, "Usage: php scripts/prepare-wordpress-org.php <plugin.zip>\n" );
	exit( 2 );
}

$repository_root = dirname( __DIR__ );
$dist            = $repository_root . '/dist';
$output          = $dist . '/wordpress-org-svn-dry-run';
$files_path      = $dist . '/wordpress-org-svn-dry-run.files.txt';
$hashes_path     = $dist . '/wordpress-org-svn-dry-run.sha256';
$report_path     = $dist . '/wordpress-org-svn-dry-run.report.txt';
$zip_path        = realpath( $argv[1] );
$asset_root      = $repository_root . '/wordpress-org/assets';
$asset_manifest  = $asset_root . '/manifest.json';

/**
 * Remove only the fixed generated dry-run directory.
 *
 * @param string $path Generated directory.
 * @param string $expected_parent Expected parent directory.
 * @return void
 */
function codegenie_wporg_remove_tree( $path, $expected_parent ) {
	if ( ! is_dir( $path ) ) {
		return;
	}

	$resolved_path   = realpath( $path );
	$resolved_parent = realpath( $expected_parent );

	if ( false === $resolved_path || false === $resolved_parent || dirname( $resolved_path ) !== $resolved_parent || 'wordpress-org-svn-dry-run' !== basename( $resolved_path ) ) {
		throw new RuntimeException( 'Refusing to remove an unexpected dry-run directory.' );
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $resolved_path, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $iterator as $item ) {
		if ( $item->isDir() && ! $item->isLink() ) {
			rmdir( $item->getPathname() );
		} else {
			unlink( $item->getPathname() );
		}
	}

	rmdir( $resolved_path );
}

/**
 * Write a file and create its parent directories.
 *
 * @param string $path Destination.
 * @param string $content File bytes.
 * @return void
 */
function codegenie_wporg_write_file( $path, $content ) {
	$directory = dirname( $path );

	if ( ! is_dir( $directory ) && ! mkdir( $directory, 0777, true ) && ! is_dir( $directory ) ) {
		throw new RuntimeException( 'Unable to create directory: ' . $directory );
	}

	if ( false === file_put_contents( $path, $content ) ) {
		throw new RuntimeException( 'Unable to write file: ' . $path );
	}
}

try {
	if ( ! is_dir( $dist ) && ! mkdir( $dist, 0777, true ) && ! is_dir( $dist ) ) {
		throw new RuntimeException( 'Unable to create dist directory.' );
	}

	codegenie_wporg_remove_tree( $output, $dist );

	$zip    = new ZipArchive();
	$result = $zip->open( $zip_path, ZipArchive::CHECKCONS );

	if ( true !== $result ) {
		throw new RuntimeException( 'Unable to open installation ZIP; code ' . $result . '.' );
	}

	$plugin_root = 'codegenie-pulse-connector/';
	$runtime     = array();

	for ( $index = 0; $index < $zip->numFiles; ++$index ) {
		$name = $zip->getNameIndex( $index );

		if ( ! is_string( $name ) || 0 !== strpos( $name, $plugin_root ) || false !== strpos( $name, '../' ) || false !== strpos( $name, '\\' ) ) {
			throw new RuntimeException( 'Unsafe or unexpected installation ZIP entry.' );
		}

		if ( '/' === substr( $name, -1 ) ) {
			continue;
		}

		$relative = substr( $name, strlen( $plugin_root ) );
		$content  = $zip->getFromIndex( $index );

		if ( '' === $relative || ! is_string( $content ) || 1 === preg_match( '#(?:^|/)(?:\.git|\.github|tests?|scripts?|vendor|dist|wordpress-org)(?:/|$)|(?:^|/)(?:composer\.(?:json|lock)|\.phpunit\.result\.cache|\.phpcs-cache)$|\.(?:zip|log|tmp)$#i', $relative ) ) {
			throw new RuntimeException( 'Forbidden runtime entry: ' . $relative );
		}

		$runtime[ $relative ] = $content;
	}

	$main   = isset( $runtime['codegenie-pulse-connector.php'] ) ? $runtime['codegenie-pulse-connector.php'] : '';
	$readme = isset( $runtime['readme.txt'] ) ? $runtime['readme.txt'] : '';

	if ( 1 !== preg_match( '/^ \* Version:\s+([0-9]+\.[0-9]+\.[0-9]+)\s*$/m', $main, $version_match ) ) {
		throw new RuntimeException( 'Unable to determine plugin version from installation ZIP.' );
	}

	$version = $version_match[1];

	if ( 1 !== preg_match( '/^Stable tag:\s+' . preg_quote( $version, '/' ) . '\s*$/m', $readme ) ) {
		throw new RuntimeException( 'Stable tag does not match the installation ZIP version.' );
	}

	$zip->close();

	if ( ! $runtime ) {
		throw new RuntimeException( 'Installation ZIP has no runtime files.' );
	}

	ksort( $runtime, SORT_STRING );
	$trunk_root = $output . '/trunk';
	$tag_root   = $output . '/tags/' . $version;
	$svn_assets = $output . '/assets';

	foreach ( $runtime as $relative => $content ) {
		codegenie_wporg_write_file( $trunk_root . '/' . $relative, $content );
		codegenie_wporg_write_file( $tag_root . '/' . $relative, $content );

		if ( hash_file( 'sha256', $trunk_root . '/' . $relative ) !== hash_file( 'sha256', $tag_root . '/' . $relative ) ) {
			throw new RuntimeException( 'Trunk/tag mismatch: ' . $relative );
		}
	}

	if ( ! is_dir( $svn_assets ) && ! mkdir( $svn_assets, 0777, true ) && ! is_dir( $svn_assets ) ) {
		throw new RuntimeException( 'Unable to create assets dry-run directory.' );
	}

	$manifest_data = Codegenie_WordPress_Org_Asset_Manifest::from_directory( $asset_manifest, $asset_root, $version );
	$missing_assets = $manifest_data['publication_blockers'];
	$copied_assets  = array();

	foreach ( $manifest_data['assets'] as $asset ) {
		$filename = $asset['filename'];
		if ( 'approved' !== $asset['status'] ) {
			continue;
		}

		$source = $asset_root . '/' . $filename;
		if ( ! copy( $source, $svn_assets . '/' . $filename ) ) {
			throw new RuntimeException( 'Unable to copy approved WordPress.org asset: ' . $filename );
		}
		$copied_assets[] = $filename;
	}

	$tree_files = array();
	$iterator   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $output, FilesystemIterator::SKIP_DOTS ) );

	foreach ( $iterator as $item ) {
		if ( ! $item->isFile() ) {
			continue;
		}

		$relative = str_replace( '\\', '/', substr( $item->getPathname(), strlen( $output ) + 1 ) );

		if ( 1 === preg_match( '#(?:^|/)(?:\.git|\.github|tests?|scripts?|vendor)(?:/|$)|(?:^|/)(?:composer\.(?:json|lock))$|\.zip$#i', $relative ) ) {
			throw new RuntimeException( 'Forbidden planned SVN file: ' . $relative );
		}

		$tree_files[] = $relative;
	}

	sort( $tree_files, SORT_STRING );
	$hash_lines = array();

	foreach ( $tree_files as $relative ) {
		$hash_lines[] = hash_file( 'sha256', $output . '/' . str_replace( '/', DIRECTORY_SEPARATOR, $relative ) ) . '  ' . $relative;
	}

	file_put_contents( $files_path, implode( "\n", $tree_files ) . "\n" );
	file_put_contents( $hashes_path, implode( "\n", $hash_lines ) . "\n" );

	$report = array(
		'WordPress.org SVN dry-run (no network or SVN write)',
		'Version: ' . $version,
		'Runtime files in trunk: ' . count( $runtime ),
		'Runtime files in tags/' . $version . ': ' . count( $runtime ),
		'Approved assets copied: ' . count( $copied_assets ),
		'Missing required assets: ' . count( $missing_assets ),
	);

	if ( $missing_assets ) {
		$report[] = 'Human blockers:';
		foreach ( $missing_assets as $missing_asset ) {
			$report[] = '- ' . $missing_asset;
		}
	}

	file_put_contents( $report_path, implode( "\n", $report ) . "\n" );

	echo 'WordPress.org SVN dry-run prepared: trunk and tags/' . $version . ' each match ' . count( $runtime ) . " installation files.\n";
	echo 'Approved assets copied: ' . count( $copied_assets ) . '; missing required assets: ' . count( $missing_assets ) . ".\n";
	echo 'No network request or SVN command was used.' . "\n";

	if ( $missing_assets ) {
		fwrite( STDERR, "WordPress.org publication BLOCKED: required human-supplied assets are still missing or unapproved.\n" );
		exit( 3 );
	}
} catch ( Throwable $throwable ) {
	fwrite( STDERR, $throwable->getMessage() . "\n" );
	exit( 1 );
}
