<?php
/**
 * Build a deterministic WordPress installation ZIP from a clean Git tree.
 */

require_once __DIR__ . '/lib/wordpress-org-asset-manifest.php';

$root        = dirname( __DIR__ );
$allow_dirty = in_array( '--allow-dirty', $argv, true );
$version     = '1.2.1';
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

/**
 * Remove a temporary bare Git directory created by this builder.
 *
 * @param string $path Temporary Git directory.
 * @return void
 */
function codegenie_remove_source_git_directory( $path ) {
	if ( '' === $path || ! is_dir( $path ) ) {
		return;
	}

	$resolved_path   = realpath( $path );
	$resolved_parent = realpath( sys_get_temp_dir() );

	if ( false === $resolved_path || false === $resolved_parent || 0 !== strcasecmp( dirname( $resolved_path ), $resolved_parent ) || 0 !== strpos( basename( $resolved_path ), 'codegenie-source-git-' ) ) {
		throw new RuntimeException(
			'Refusing to remove an unexpected source archive Git directory: '
			. ( false === $resolved_path ? 'unresolved path' : $resolved_path )
			. ' (expected parent '
			. ( false === $resolved_parent ? 'unresolved' : $resolved_parent )
			. ').'
		);
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
	$temp_index     = '';
	$source_git_dir = '';

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
		),
		array( 'TZ' => 'UTC' )
	);

	// Use a temporary Git directory so info/attributes can override export-ignore
	// while retaining the committed eol=lf rules on every source file.
	$source_git_dir = rtrim( sys_get_temp_dir(), '/\\' ) . DIRECTORY_SEPARATOR . 'codegenie-source-git-' . bin2hex( random_bytes( 12 ) );
	if ( ! mkdir( $source_git_dir, 0700 ) && ! is_dir( $source_git_dir ) ) {
		throw new RuntimeException( 'Unable to create source archive Git directory.' );
	}

	codegenie_run( array( 'git', 'init', '--bare', '--quiet', $source_git_dir ) );
	$common_git_dir = codegenie_run( array( 'git', '-C', $root, 'rev-parse', '--path-format=absolute', '--git-common-dir' ) );
	$object_dir     = rtrim( str_replace( '\\', '/', $common_git_dir ), '/' ) . '/objects';
	$alternates     = $source_git_dir . '/objects/info/alternates';
	$attributes     = $source_git_dir . '/info/attributes';
	$source_attribute_patterns = array(
		'.github -export-ignore',
		'.github/** -export-ignore',
		'docs -export-ignore',
		'docs/** -export-ignore',
		'scripts -export-ignore',
		'scripts/** -export-ignore',
		'tests -export-ignore',
		'tests/** -export-ignore',
		'wordpress-org -export-ignore',
		'wordpress-org/** -export-ignore',
		'.gitattributes -export-ignore',
		'.gitignore -export-ignore',
		'AGENTS.md -export-ignore',
		'CONTRIBUTING.md -export-ignore',
		'SECURITY.md -export-ignore',
		'README.md -export-ignore',
		'composer.json -export-ignore',
		'composer.lock -export-ignore',
		'phpcs.xml.dist -export-ignore',
		'phpunit.xml.dist -export-ignore',
	);

	if ( false === file_put_contents( $alternates, $object_dir . "\n" ) || false === file_put_contents( $attributes, implode( "\n", $source_attribute_patterns ) . "\n" ) ) {
		throw new RuntimeException( 'Unable to configure source archive Git attributes.' );
	}

	codegenie_run(
		array(
			'git',
			'--git-dir=' . $source_git_dir,
			'archive',
			'--format=zip',
			'--prefix=' . $source_slug . '/',
			'--mtime=2000-01-01T00:00:00Z',
			'--output=' . $source_zip_path,
			$tree,
		),
		array( 'TZ' => 'UTC' )
	);

	if ( $temp_index && is_file( $temp_index ) ) {
		unlink( $temp_index );
	}
	codegenie_remove_source_git_directory( $source_git_dir );
	$source_git_dir = '';

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
	if ( isset( $source_git_dir ) && $source_git_dir ) {
		try {
			codegenie_remove_source_git_directory( $source_git_dir );
		} catch ( Throwable $cleanup_error ) {
			unset( $cleanup_error );
		}
	}
	fwrite( STDERR, $throwable->getMessage() . "\n" );
	exit( 1 );
}
