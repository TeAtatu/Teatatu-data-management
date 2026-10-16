<?php
/**
 * Uninstall routine. Runs standalone — the main plugin file is not loaded.
 * Always removes the `teatatu_data_agent` role/capabilities and the
 * plugin's own settings on every site in the network (or the single site).
 *
 * Master data itself is only deleted if the admin opted in with "Delete all
 * Teatatu Data master data when this plugin is uninstalled". Each data set
 * phase adds its own storage to the cleanup below. Only `teatatu_data_*`
 * data is ever touched — never anything belonging to Teatatu Events or News.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/capabilities.php';

/**
 * Cleans up the current site.
 *
 * @param bool $delete_data Whether to also delete all master data.
 */
function teatatu_data_uninstall_cleanup_site( $delete_data ) {
	teatatu_data_remove_role_and_caps();
	delete_option( 'teatatu_data_caps_version' );
	wp_clear_scheduled_hook( 'teatatu_data_rederive_venues' );
	wp_clear_scheduled_hook( 'teatatu_data_quality_scan' );
	wp_clear_scheduled_hook( 'teatatu_data_sync' );
	delete_option( 'teatatu_data_quality' );

	if ( ! $delete_data ) {
		return;
	}

	// Every data set's records (post types ttd_*) and the change log. The
	// data set registry isn't loaded during uninstall, so match by prefix.
	global $wpdb;
	$post_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type LIKE 'ttd\\_%' OR post_type = 'teatatu_data_change'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	foreach ( $post_ids as $post_id ) {
		wp_delete_post( (int) $post_id, true );
	}
}

$teatatu_data_network_options = array(
	'teatatu_data_dataset_settings',
	'teatatu_data_master_site_id',
	'teatatu_data_github_repo',
	'teatatu_data_github_token',
	'teatatu_data_delete_data_on_uninstall',
	'teatatu_data_cache_ver',
	'teatatu_data_nbhd_seeded',
	'teatatu_data_nbhd_seed_errors',
	'teatatu_data_quality_dismissed',
	'teatatu_data_managed',
	'teatatu_data_sync_report',
);

if ( is_multisite() ) {
	$delete_data = (bool) get_site_option( 'teatatu_data_delete_data_on_uninstall', false );
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
		switch_to_blog( $site_id );
		teatatu_data_uninstall_cleanup_site( $delete_data );
		restore_current_blog();
	}
	foreach ( $teatatu_data_network_options as $option ) {
		delete_site_option( $option );
	}
	delete_site_transient( 'teatatu_data_github_release' );
} else {
	$delete_data = (bool) get_option( 'teatatu_data_delete_data_on_uninstall', false );
	teatatu_data_uninstall_cleanup_site( $delete_data );
	foreach ( $teatatu_data_network_options as $option ) {
		delete_option( $option );
	}
	delete_transient( 'teatatu_data_github_release' );
}
