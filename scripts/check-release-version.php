<?php
/**
 * Verify that an explicitly requested release version matches every contract.
 */

if ( ! isset( $argv[1] ) || 1 !== preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+$/D', $argv[1] ) ) {
	fwrite( STDERR, "Usage: php scripts/check-release-version.php <x.y.z>\n" );
	exit( 2 );
}

$root      = dirname( __DIR__ );
$version   = $argv[1];
$quoted    = preg_quote( $version, '/' );
$main      = file_get_contents( $root . '/codegenie-pulse-connector.php' );
$readme    = file_get_contents( $root . '/readme.txt' );
$builder   = file_get_contents( $root . '/scripts/build-release.php' );
$errors    = array();
$contracts = array(
	'plugin header'    => array( $main, '/^ \* Version:\s+' . $quoted . '\s*$/m' ),
	'version constant' => array( $main, "/define\( 'CODEGENIE_PULSE_CONNECTOR_VERSION', '" . $quoted . "' \);/" ),
	'stable tag'       => array( $readme, '/^Stable tag:\s+' . $quoted . '\s*$/m' ),
	'builder version'  => array( $builder, "/\\\$version\s+= '" . $quoted . "';/" ),
);

foreach ( $contracts as $label => $contract ) {
	if ( false === $contract[0] || 1 !== preg_match( $contract[1], $contract[0] ) ) {
		$errors[] = 'Release version mismatch: ' . $label . '.';
	}
}

if ( $errors ) {
	fwrite( STDERR, implode( "\n", $errors ) . "\n" );
	exit( 1 );
}

echo 'Explicit release version OK: ' . $version . ".\n";
