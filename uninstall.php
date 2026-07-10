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
}

if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		codegenie_pulse_connector_delete_site_data();
		restore_current_blog();
	}
} else {
	codegenie_pulse_connector_delete_site_data();
}
