<?php
/**
 * Lint every repository PHP file outside generated directories.
 */

$root      = dirname( __DIR__ );
$iterator  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
$failures  = array();
$file_count = 0;

foreach ( $iterator as $file ) {
	if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}

	$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );

	if ( preg_match( '#^(vendor|dist)/#', $relative ) ) {
		continue;
	}

	++$file_count;
	$output = array();
	$status = 0;
	exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $file->getPathname() ) . ' 2>&1', $output, $status );

	if ( 0 !== $status ) {
		$failures[] = $relative . ': ' . implode( "\n", $output );
	}
}

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'Syntax OK: ' . $file_count . " PHP files.\n";

