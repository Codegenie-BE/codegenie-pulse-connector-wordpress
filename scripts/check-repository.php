<?php
/**
 * Enforce source-repository hygiene and catch common committed secret formats.
 */

require_once __DIR__ . '/lib/wordpress-org-asset-manifest.php';

$root = dirname( __DIR__ );
exec( 'git -C ' . escapeshellarg( $root ) . ' ls-files --cached --others --exclude-standard', $tracked, $status );

if ( 0 !== $status ) {
	fwrite( STDERR, "Unable to list tracked files.\n" );
	exit( 1 );
}

exec( 'git -C ' . escapeshellarg( $root ) . ' ls-files --error-unmatch wordpress-org/assets/manifest.json', $manifest_tracked, $manifest_status );
if ( 0 !== $manifest_status ) {
	fwrite( STDERR, "WordPress.org asset manifest must be tracked by Git.\n" );
	exit( 1 );
}

$errors = array();
$forbidden_paths = array(
	'#(^|/)vendor/#i',
	'#(^|/)dist/#i',
	'#(^|/)coverage/#i',
	'#(^|/)reports/#i',
	'#(^|/)\.phpunit\.result\.cache$#i',
	'#(^|/)\.phpcs-cache$#i',
	'#(^|/)\.env(?:\.|$)#i',
	'#\.(?:zip|tar|tgz|gz|key|pem|p12|pfx|log|tmp)$#i',
);
$secret_patterns = array(
	'private key'       => '/-----BEGIN (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----/',
	'AWS access key'    => '/\b(?:AKIA|ASIA)[A-Z0-9]{16}\b/',
	'GitHub token'      => '/\b(?:ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9]{30,}\b/',
	'GitHub fine token' => '/\bgithub_pat_[A-Za-z0-9_]{50,}\b/',
	'Slack token'       => '/\bxox[baprs]-[A-Za-z0-9-]{20,}\b/',
	'Google API key'    => '/\bAIza[0-9A-Za-z_-]{35}\b/',
);

foreach ( $tracked as $relative ) {
	$relative = str_replace( '\\', '/', $relative );

	foreach ( $forbidden_paths as $pattern ) {
		if ( preg_match( $pattern, $relative ) ) {
			$errors[] = 'Forbidden tracked file: ' . $relative;
		}
	}

	$path = $root . '/' . $relative;
	if ( ! is_file( $path ) || filesize( $path ) > 1048576 ) {
		continue;
	}

	$content = file_get_contents( $path );
	if ( false === $content || false !== strpos( $content, "\0" ) ) {
		continue;
	}

	foreach ( $secret_patterns as $label => $pattern ) {
		if ( preg_match( $pattern, $content ) ) {
			$errors[] = 'Possible ' . $label . ' in ' . $relative;
		}
	}
}

if ( $errors ) {
	fwrite( STDERR, implode( "\n", array_unique( $errors ) ) . "\n" );
	exit( 1 );
}

try {
	$asset_manifest = Codegenie_WordPress_Org_Asset_Manifest::from_directory(
		$root . '/wordpress-org/assets/manifest.json',
		$root . '/wordpress-org/assets',
		'1.2.1'
	);
} catch ( RuntimeException $exception ) {
	fwrite( STDERR, $exception->getMessage() . "\n" );
	exit( 1 );
}

echo 'Repository policy OK: ' . count( $tracked ) . " source files checked; no forbidden files or known secret formats.\n";
echo 'WordPress.org asset manifest OK; publication blockers: ' . count( $asset_manifest['publication_blockers'] ) . ".\n";
