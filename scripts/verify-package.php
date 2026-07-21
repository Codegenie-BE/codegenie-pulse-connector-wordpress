<?php
/**
 * Verify the install ZIP structure, contents, version, secrets, and integrity.
 */

if ( ! isset( $argv[1] ) || ! is_file( $argv[1] ) ) {
	fwrite( STDERR, "Usage: php scripts/verify-package.php <plugin.zip>\n" );
	exit( 2 );
}

$zip_path = realpath( $argv[1] );
$zip      = new ZipArchive();
$result   = $zip->open( $zip_path, ZipArchive::CHECKCONS );

if ( true !== $result ) {
	fwrite( STDERR, 'ZIP integrity failed with code ' . $result . ".\n" );
	exit( 1 );
}

$root      = 'codegenie-pulse-connector/';
$files     = array();
$errors    = array();
$forbidden = '#(?:^|/)(?:\.git|\.github|tests?|scripts?|vendor|node_modules|dist|coverage|reports|wordpress-org)(?:/|$)|(?:^|/)(?:composer\.(?:json|lock)|phpcs\.xml(?:\.dist)?|phpunit\.xml(?:\.dist)?|AGENTS\.md|CONTRIBUTING\.md|SECURITY\.md|README\.md|\.gitattributes|\.gitignore|\.phpunit\.result\.cache|\.phpcs-cache)$|\.(?:log|tmp)$#i';
$secret_patterns = array(
	'/-----BEGIN (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----/',
	'/\b(?:AKIA|ASIA)[A-Z0-9]{16}\b/',
	'/\b(?:ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9]{30,}\b/',
	'/\bgithub_pat_[A-Za-z0-9_]{50,}\b/',
	'/\bxox[baprs]-[A-Za-z0-9-]{20,}\b/',
	'/\bAIza[0-9A-Za-z_-]{35}\b/',
);

for ( $index = 0; $index < $zip->numFiles; ++$index ) {
	$name = $zip->getNameIndex( $index );
	if ( false === $name ) {
		$errors[] = 'Unreadable ZIP entry at index ' . $index;
		continue;
	}

	if ( 0 !== strpos( $name, $root ) || false !== strpos( $name, '../' ) || false !== strpos( $name, '\\' ) ) {
		$errors[] = 'Unsafe or unexpected root entry: ' . $name;
	}

	if ( preg_match( $forbidden, $name ) ) {
		$errors[] = 'Forbidden package entry: ' . $name;
	}

	if ( '/' !== substr( $name, -1 ) ) {
		$files[] = $name;
		$content = $zip->getFromIndex( $index );
		if ( is_string( $content ) && false === strpos( $content, "\0" ) ) {
			foreach ( $secret_patterns as $pattern ) {
				if ( preg_match( $pattern, $content ) ) {
					$errors[] = 'Possible secret in package entry: ' . $name;
				}
			}
		}
	}
}

$required = array(
	$root . 'LICENSE',
	$root . 'license.txt',
	$root . 'readme.txt',
	$root . 'codegenie-pulse-connector.php',
	$root . 'index.php',
	$root . 'includes/index.php',
	$root . 'uninstall.php',
);

foreach ( $required as $required_file ) {
	if ( ! in_array( $required_file, $files, true ) ) {
		$errors[] = 'Required package file missing: ' . $required_file;
	}
}

$main       = $zip->getFromName( $root . 'codegenie-pulse-connector.php' );
$readme     = $zip->getFromName( $root . 'readme.txt' );
$connection = $zip->getFromName( $root . 'includes/class-codegenie-pulse-connection.php' );

if ( ! is_string( $main ) || 1 !== preg_match( '/^ \* Version:\s+1\.2\.1\s*$/m', $main ) || false === strpos( $main, "define( 'CODEGENIE_PULSE_CONNECTOR_VERSION', '1.2.1' );" ) ) {
	$errors[] = 'Plugin header or version constant is not 1.2.1.';
}

if ( ! is_string( $readme ) || 1 !== preg_match( '/^Stable tag:\s+1\.2\.1\s*$/m', $readme ) ) {
	$errors[] = 'Stable tag is not 1.2.1.';
}

if ( ! is_string( $connection ) || false === strpos( $connection, "const CONNECTOR_ID     = 'codegenie-pulse-connector-wordpress';" ) ) {
	$errors[] = 'Protocol connector ID changed.';
}

$zip->close();

if ( $errors ) {
	fwrite( STDERR, implode( "\n", array_unique( $errors ) ) . "\n" );
	exit( 1 );
}

sort( $files, SORT_STRING );
echo 'ZIP integrity OK: one ' . $root . ' root with ' . count( $files ) . " runtime files.\n";
