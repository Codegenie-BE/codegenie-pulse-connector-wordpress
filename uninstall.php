<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove connector settings and delivery state from the current site.
 *
 * @return void
 */
function codegenie_pulse_connector_delete_site_data() {
	delete_option( 'codegenie_pulse_connector_settings' );
	delete_option( 'codegenie_pulse_connector_state' );
	delete_transient( 'codegenie_pulse_connector_backoff' );
	delete_transient( 'codegenie_pulse_connector_backoff_error' );
	delete_transient( 'codegenie_pulse_connector_backoff_deployment' );
	delete_transient( 'codegenie_pulse_connector_non_fatal_samples' );
}

if ( is_multisite() ) {
	$codegenie_pulse_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $codegenie_pulse_site_ids as $codegenie_pulse_site_id ) {
		switch_to_blog( (int) $codegenie_pulse_site_id );
		codegenie_pulse_connector_delete_site_data();
		restore_current_blog();
	}
} else {
	codegenie_pulse_connector_delete_site_data();
}
