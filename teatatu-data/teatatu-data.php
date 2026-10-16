<?php
/**
 * Plugin Name: Teatatu Data
 * Plugin URI: https://github.com/TeAtatu/Teatatu-data-management
 * Description: Central master and reference data for the Teatatu plugins: Neighbourhoods and street rules, Venues and places, Organisations and sources, and shared Tags and Categories. Every data set has a declared schema, a data dictionary, quality checks and change history, following DAMA-DMBOK practice. Edited on one Master Site and shared across a network. Uses only standard WordPress database tables.
 * Version: 0.8.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: TeAtatu
 * Author URI: https://github.com/TeAtatu
 * License: GPL-3.0
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: teatatu-data
 * Update URI: https://github.com/TeAtatu/Teatatu-data-management
 * Network: true
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TEATATU_DATA_VERSION', '0.8.0' );
define( 'TEATATU_DATA_FILE', __FILE__ );
define( 'TEATATU_DATA_DIR', plugin_dir_path( __FILE__ ) );
define( 'TEATATU_DATA_URL', plugin_dir_url( __FILE__ ) );
define( 'TEATATU_DATA_BASENAME', plugin_basename( __FILE__ ) );

require_once TEATATU_DATA_DIR . 'includes/capabilities.php';
require_once TEATATU_DATA_DIR . 'includes/settings.php';
require_once TEATATU_DATA_DIR . 'includes/multisite.php';
require_once TEATATU_DATA_DIR . 'includes/registry.php';
require_once TEATATU_DATA_DIR . 'includes/records.php';
require_once TEATATU_DATA_DIR . 'includes/validation.php';
require_once TEATATU_DATA_DIR . 'includes/changes.php';
require_once TEATATU_DATA_DIR . 'includes/rest-api.php';
require_once TEATATU_DATA_DIR . 'includes/import-export.php';
require_once TEATATU_DATA_DIR . 'includes/admin-page.php';
require_once TEATATU_DATA_DIR . 'includes/quality.php';
require_once TEATATU_DATA_DIR . 'includes/merge.php';
require_once TEATATU_DATA_DIR . 'includes/admin-quality.php';
require_once TEATATU_DATA_DIR . 'includes/connections.php';
require_once TEATATU_DATA_DIR . 'includes/address-core.php';
require_once TEATATU_DATA_DIR . 'includes/bring-in.php';
require_once TEATATU_DATA_DIR . 'includes/datasets/neighbourhoods.php';
require_once TEATATU_DATA_DIR . 'includes/datasets/venues.php';
require_once TEATATU_DATA_DIR . 'includes/datasets/organisations.php';
require_once TEATATU_DATA_DIR . 'includes/datasets/vocabularies.php';
require_once TEATATU_DATA_DIR . 'includes/updater.php';

/**
 * Fired on plugin activation. Handles both single-site and network activation.
 * Only a "Network Activate" provisions every site; a plain per-site
 * "Activate" on a multisite install provisions just the current site.
 *
 * @param bool $network_wide Whether the plugin is being network-activated.
 */
function teatatu_data_activate( $network_wide ) {
	if ( is_multisite() && $network_wide ) {
		$site_ids = get_sites( array( 'fields' => 'ids' ) );
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			teatatu_data_provision_site();
			restore_current_blog();
		}
	} else {
		teatatu_data_provision_site();
	}
}
register_activation_hook( TEATATU_DATA_FILE, 'teatatu_data_activate' );

/**
 * Runs the per-site provisioning routine: roles and capabilities, and on
 * the Master Site the one-off seeding of reference data (neighbourhoods
 * and street rules).
 */
function teatatu_data_provision_site() {
	teatatu_data_add_role_and_caps();
	update_option( 'teatatu_data_caps_version', TEATATU_DATA_VERSION );
	teatatu_data_maybe_seed_neighbourhoods();
}

/**
 * Fired on deactivation: clears the plugin's scheduled jobs (on every site
 * for a network deactivation). Data and settings are kept.
 *
 * @param bool $network_wide Whether the plugin was network-activated.
 */
function teatatu_data_deactivate( $network_wide ) {
	$clear = function () {
		wp_clear_scheduled_hook( 'teatatu_data_quality_scan' );
		wp_clear_scheduled_hook( 'teatatu_data_rederive_venues' );
		wp_clear_scheduled_hook( 'teatatu_data_sync' );
	};
	if ( is_multisite() && $network_wide ) {
		foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
			switch_to_blog( $site_id );
			$clear();
			restore_current_blog();
		}
	} else {
		$clear();
	}
}
register_deactivation_hook( TEATATU_DATA_FILE, 'teatatu_data_deactivate' );

/**
 * Provisions a brand-new site created after network activation.
 *
 * @param WP_Site $new_site Newly created site.
 */
function teatatu_data_new_site( $new_site ) {
	if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( ! is_plugin_active_for_network( TEATATU_DATA_BASENAME ) ) {
		return;
	}
	switch_to_blog( (int) $new_site->blog_id );
	teatatu_data_provision_site();
	restore_current_blog();
}
add_action( 'wp_initialize_site', 'teatatu_data_new_site' );
