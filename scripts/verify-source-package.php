<?php
/**
 * Verify the source ZIP root, contents, secrets, and integrity.
 */

require_once __DIR__ . '/lib/wordpress-org-asset-manifest.php';

if ( ! isset( $argv[1], $argv[2] ) || ! is_file( $argv[1] ) || 1 !== preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+$/D', $argv[2] ) ) {
	fwrite( STDERR, "Usage: php scripts/verify-source-package.php <source.zip> <x.y.z>\n" );
	exit( 2 );
}

$zip_path = realpath( $argv[1] );
$version  = $argv[2];
$root     = 'codegenie-pulse-connector-wordpress-' . $version . '-source/';
$zip      = new ZipArchive();
$result   = $zip->open( $zip_path, ZipArchive::CHECKCONS );

if ( true !== $result ) {
	fwrite( STDERR, 'Source ZIP integrity failed with code ' . $result . ".\n" );
	exit( 1 );
}

$files     = array();
$errors    = array();
$forbidden = '#(?:^|/)(?:\.git|vendor|node_modules|dist|coverage|reports)(?:/|$)|(?:^|/)(?:\.phpunit\.result\.cache|\.phpcs-cache|\.env(?:\..*)?)$|\.(?:zip|tar|tgz|gz|log|tmp|key|pem|p12|pfx)$#i';
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

	if ( ! is_string( $name ) || 0 !== strpos( $name, $root ) || false !== strpos( $name, '../' ) || false !== strpos( $name, '\\' ) ) {
		$errors[] = 'Unsafe or unexpected source ZIP entry at index ' . $index . '.';
		continue;
	}

	if ( preg_match( $forbidden, $name ) ) {
		$errors[] = 'Forbidden source ZIP entry: ' . $name;
	}

	if ( '/' === substr( $name, -1 ) ) {
		continue;
	}

	$files[] = $name;
	$content = $zip->getFromIndex( $index );

	if ( is_string( $content ) && false === strpos( $content, "\0" ) ) {
		if ( preg_match( '#(?:\.(?:json|lock|md|php|sh|txt|xml|ya?ml)|(?:^|/)(?:\.gitattributes|\.gitignore|LICENSE))$#i', $name ) && false !== strpos( $content, "\r\n" ) ) {
			$errors[] = 'Source ZIP text entry does not use canonical LF line endings: ' . $name;
		}

		foreach ( $secret_patterns as $pattern ) {
			if ( preg_match( $pattern, $content ) ) {
				$errors[] = 'Possible secret in source ZIP entry: ' . $name;
			}
		}
	}
}

$required = array(
	$root . '.gitattributes',
	$root . '.github/workflows/qa.yml',
	$root . '.github/workflows/release-prepare.yml',
	$root . 'AGENTS.md',
	$root . 'SECURITY.md',
	$root . 'README.md',
	$root . 'composer.json',
	$root . 'docs/releases/' . $version . '.md',
	$root . 'docs/WORDPRESS_ORG_RELEASE.md',
	$root . 'scripts/build-release.php',
	$root . 'scripts/lib/wordpress-org-asset-manifest.php',
	$root . 'scripts/prepare-wordpress-org.php',
	$root . 'tests/bootstrap.php',
	$root . 'wordpress-org/assets/manifest.json',
	$root . 'codegenie-pulse-connector.php',
);

foreach ( $required as $required_file ) {
	if ( ! in_array( $required_file, $files, true ) ) {
		$errors[] = 'Required source ZIP file missing: ' . $required_file;
	}
}

try {
	Codegenie_WordPress_Org_Asset_Manifest::from_zip( $zip, $root, $version );
} catch ( RuntimeException $exception ) {
	$errors[] = $exception->getMessage();
}

$zip->close();

if ( $errors ) {
	fwrite( STDERR, implode( "\n", array_unique( $errors ) ) . "\n" );
	exit( 1 );
}

echo 'Source ZIP integrity OK: one ' . $root . ' root with ' . count( $files ) . " source files.\n";
