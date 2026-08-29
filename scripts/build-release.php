<?php
/**
 * Build a deterministic WordPress installation ZIP from a clean Git tree.
 */

require_once __DIR__ . '/lib/wordpress-org-asset-manifest.php';

$root        = dirname( __DIR__ );
$allow_dirty = in_array( '--allow-dirty', $argv, true );
$version     = '1.2.2';
$slug        = 'codegenie-pulse-connector';
$source_slug = 'codegenie-pulse-connector-wordpress-' . $version . '-source';
$dist        = $root . '/dist';
$zip_path    = $dist . '/' . $slug . '-' . $version . '.zip';
$list_path   = $dist . '/' . $slug . '-' . $version . '.files.txt';
$hash_path   = $dist . '/' . $slug . '-' . $version . '.sha256';
$source_zip_path  = $dist . '/' . $source_slug . '.zip';
$source_list_path = $dist . '/' . $source_slug . '.files.txt';
$source_hash_path = $dist . '/' . $source_slug . '.sha256';

/**
 * Run a command without invoking a shell.
 *
 * @param string[]             $command Command and arguments.
 * @param array<string,string> $environment Extra environment.
 * @return string
 */
function codegenie_run( $command, $environment = array() ) {
	$descriptor = array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);
	$env = array_merge( (array) getenv(), $environment );
	$process = proc_open( $command, $descriptor, $pipes, null, $env );

	if ( ! is_resource( $process ) ) {
		throw new RuntimeException( 'Unable to start: ' . implode( ' ', $command ) );
	}

	fclose( $pipes[0] );
	$stdout = stream_get_contents( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$status = proc_close( $process );

	if ( 0 !== $status ) {
		throw new RuntimeException( trim( $stderr ) ?: 'Command failed: ' . implode( ' ', $command ) );
	}

	return trim( $stdout );
}

try {
	codegenie_run( array( PHP_BINARY, $root . '/scripts/check-versions.php' ) );
	Codegenie_WordPress_Org_Asset_Manifest::from_directory(
		$root . '/wordpress-org/assets/manifest.json',
		$root . '/wordpress-org/assets',
		$version
	);
	$status = codegenie_run( array( 'git', '-C', $root, 'status', '--porcelain', '--untracked-files=all' ) );

	if ( '' !== $status && ! $allow_dirty ) {
		throw new RuntimeException( "Release builds require a clean Git commit. Commit or stash reviewed work first.\n" . $status );
	}

	$tree = codegenie_run( array( 'git', '-C', $root, 'rev-parse', 'HEAD^{tree}' ) );
	$temp_index = '';
	$source_index = '';

	if ( $allow_dirty ) {
		$temp_index = tempnam( sys_get_temp_dir(), 'codegenie-index-' );
		if ( false === $temp_index ) {
			throw new RuntimeException( 'Unable to create temporary Git index.' );
		}
		unlink( $temp_index );
		$environment = array( 'GIT_INDEX_FILE' => $temp_index );
		codegenie_run( array( 'git', '-C', $root, 'read-tree', 'HEAD' ), $environment );
		codegenie_run( array( 'git', '-C', $root, 'add', '--all' ), $environment );
		$tree = codegenie_run( array( 'git', '-C', $root, 'write-tree' ), $environment );
	}

	if ( ! is_dir( $dist ) && ! mkdir( $dist, 0777, true ) && ! is_dir( $dist ) ) {
		throw new RuntimeException( 'Unable to create dist directory.' );
	}

	if ( is_file( $zip_path ) ) {
		unlink( $zip_path );
	}
	if ( is_file( $source_zip_path ) ) {
		unlink( $source_zip_path );
	}

	codegenie_run(
		array(
			'git',
			'-C',
			$root,
			'archive',
			'--format=zip',
			'--prefix=' . $slug . '/',
			'--mtime=2000-01-01T00:00:00Z',
			'--output=' . $zip_path,
			$tree,
		)
	);

	// Build a reviewable source archive from the same tree without applying export-ignore.
	$source_index = tempnam( sys_get_temp_dir(), 'codegenie-source-index-' );
	if ( false === $source_index ) {
		throw new RuntimeException( 'Unable to create source archive Git index.' );
	}
	unlink( $source_index );
	$source_environment = array( 'GIT_INDEX_FILE' => $source_index );
	codegenie_run( array( 'git', '-C', $root, 'read-tree', $tree ), $source_environment );
	codegenie_run( array( 'git', '-C', $root, 'update-index', '--force-remove', '.gitattributes' ), $source_environment );
	$source_tree = codegenie_run( array( 'git', '-C', $root, 'write-tree' ), $source_environment );
	codegenie_run(
		array(
			'git',
			'-C',
			$root,
			'archive',
			'--format=zip',
			'--prefix=' . $source_slug . '/',
			'--mtime=2000-01-01T00:00:00Z',
			'--add-file=' . $root . '/.gitattributes',
			'--output=' . $source_zip_path,
			$source_tree,
		)
	);

	if ( $temp_index && is_file( $temp_index ) ) {
		unlink( $temp_index );
	}
	if ( $source_index && is_file( $source_index ) ) {
		unlink( $source_index );
	}

	codegenie_run( array( PHP_BINARY, $root . '/scripts/verify-package.php', $zip_path ) );

	$zip = new ZipArchive();
	if ( true !== $zip->open( $zip_path, ZipArchive::CHECKCONS ) ) {
		throw new RuntimeException( 'Unable to reopen built ZIP.' );
	}
	$files = array();
	for ( $index = 0; $index < $zip->numFiles; ++$index ) {
		$name = $zip->getNameIndex( $index );
		if ( is_string( $name ) && '/' !== substr( $name, -1 ) ) {
			$files[] = $name;
		}
	}
	$zip->close();
	sort( $files, SORT_STRING );
	file_put_contents( $list_path, implode( "\n", $files ) . "\n" );
	$hash = hash_file( 'sha256', $zip_path );
	file_put_contents( $hash_path, $hash . '  ' . basename( $zip_path ) . "\n" );

	$source_zip = new ZipArchive();
	if ( true !== $source_zip->open( $source_zip_path, ZipArchive::CHECKCONS ) ) {
		throw new RuntimeException( 'Unable to open built source ZIP.' );
	}
	$source_prefix = $source_slug . '/';
	$source_files  = array();
	for ( $index = 0; $index < $source_zip->numFiles; ++$index ) {
		$name = $source_zip->getNameIndex( $index );
		if ( ! is_string( $name ) || 0 !== strpos( $name, $source_prefix ) || false !== strpos( $name, '../' ) || false !== strpos( $name, '\\' ) ) {
			throw new RuntimeException( 'Unsafe source ZIP entry.' );
		}
		if ( preg_match( '#(?:^|/)(?:\.git|vendor|node_modules|dist|coverage|reports)(?:/|$)|(?:^|/)(?:\.phpunit\.result\.cache|\.phpcs-cache)$|\.(?:log|tmp)$#i', $name ) ) {
			throw new RuntimeException( 'Forbidden source ZIP entry: ' . $name );
		}
		if ( '/' !== substr( $name, -1 ) ) {
			$source_files[] = $name;
		}
	}
	$source_zip->close();
	$source_required = array(
		$source_prefix . '.gitattributes',
		$source_prefix . '.github/workflows/qa.yml',
		$source_prefix . '.github/workflows/release-prepare.yml',
		$source_prefix . 'AGENTS.md',
		$source_prefix . 'README.md',
		$source_prefix . 'composer.json',
		$source_prefix . 'docs/releases/' . $version . '.md',
		$source_prefix . 'docs/WORDPRESS_ORG_RELEASE.md',
		$source_prefix . 'scripts/build-release.php',
		$source_prefix . 'scripts/lib/wordpress-org-asset-manifest.php',
		$source_prefix . 'scripts/prepare-wordpress-org.php',
		$source_prefix . 'tests/bootstrap.php',
		$source_prefix . 'wordpress-org/assets/manifest.json',
		$source_prefix . 'codegenie-pulse-connector.php',
	);
	foreach ( $source_required as $required_file ) {
		if ( ! in_array( $required_file, $source_files, true ) ) {
			throw new RuntimeException( 'Required source archive file missing: ' . $required_file );
		}
	}
	sort( $source_files, SORT_STRING );
	codegenie_run( array( PHP_BINARY, $root . '/scripts/verify-source-package.php', $source_zip_path, $version ) );
	file_put_contents( $source_list_path, implode( "\n", $source_files ) . "\n" );
	$source_hash = hash_file( 'sha256', $source_zip_path );
	file_put_contents( $source_hash_path, $source_hash . '  ' . basename( $source_zip_path ) . "\n" );

	echo 'Built installation: ' . $zip_path . "\n";
	echo 'Installation files: ' . count( $files ) . ' (manifest: ' . $list_path . ")\n";
	echo 'Installation SHA-256: ' . $hash . "\n";
	echo 'Built source: ' . $source_zip_path . "\n";
	echo 'Source files: ' . count( $source_files ) . ' (manifest: ' . $source_list_path . ")\n";
	echo 'Source SHA-256: ' . $source_hash . "\n";
} catch ( Throwable $throwable ) {
	if ( isset( $temp_index ) && $temp_index && is_file( $temp_index ) ) {
		unlink( $temp_index );
	}
	if ( isset( $source_index ) && $source_index && is_file( $source_index ) ) {
		unlink( $source_index );
	}
	fwrite( STDERR, $throwable->getMessage() . "\n" );
	exit( 1 );
}
