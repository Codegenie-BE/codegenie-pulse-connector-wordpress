<?php
/**
 * Verify release identifiers and compatibility headers.
 */

$root   = dirname( __DIR__ );
$main   = file_get_contents( $root . '/codegenie-pulse-connector.php' );
$readme = file_get_contents( $root . '/readme.txt' );
$connection = file_get_contents( $root . '/includes/class-codegenie-pulse-connection.php' );
$errors = array();

$checks = array(
	'plugin name'        => array( $main, '/^ \* Plugin Name:\s+Codegenie Pulse Connector\s*$/m' ),
	'plugin version'     => array( $main, '/^ \* Version:\s+1\.2\.1\s*$/m' ),
	'version constant'   => array( $main, "/define\( 'CODEGENIE_PULSE_CONNECTOR_VERSION', '1\.2\.1' \);/" ),
	'minimum WordPress'  => array( $main, '/^ \* Requires at least:\s+6\.2\s*$/m' ),
	'minimum PHP'        => array( $main, '/^ \* Requires PHP:\s+7\.4\s*$/m' ),
	'text domain'        => array( $main, '/^ \* Text Domain:\s+codegenie-pulse-connector\s*$/m' ),
	'stable tag'         => array( $readme, '/^Stable tag:\s+1\.2\.1\s*$/m' ),
	'readme WordPress'   => array( $readme, '/^Requires at least:\s+6\.2\s*$/m' ),
	'readme PHP'         => array( $readme, '/^Requires PHP:\s+7\.4\s*$/m' ),
	'tested WordPress'   => array( $readme, '/^Tested up to:\s+7\.0\s*$/m' ),
	'official terms URL' => array( $readme, '#https://pulse\.codegenie\.be/terms#' ),
	'official privacy URL' => array( $readme, '#https://pulse\.codegenie\.be/privacy#' ),
	'protocol connector' => array( $connection, "/const CONNECTOR_ID\s+= 'codegenie-pulse-connector-wordpress';/" ),
);

foreach ( $checks as $label => $check ) {
	if ( false === $check[0] || 1 !== preg_match( $check[1], $check[0] ) ) {
		$errors[] = 'Missing or inconsistent ' . $label . '.';
	}
}

$legacy_service_origin = 'https://' . 'monitor' . '.codegenie.be/';
if ( false !== strpos( $readme, $legacy_service_origin ) ) {
	$errors[] = 'Legacy Codegenie Pulse service URL remains in readme.txt.';
}

if ( $errors ) {
	fwrite( STDERR, implode( "\n", $errors ) . "\n" );
	exit( 1 );
}

echo "Version contracts OK: 1.2.1, WordPress 6.2-7.0.2, PHP 7.4+, connector ID unchanged.\n";
