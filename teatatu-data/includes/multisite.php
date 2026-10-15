<?php
/**
 * Multisite helpers. Master data lives on one Master Site and is read
 * network-wide through switch_to_blog(); every other site is a consumer.
 * On a single site install that site is the Master Site.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The site that holds the master copy of every data set.
 *
 * @return int Site ID (the current site on a single-site install, the main
 *             site on multisite until a Master Site is chosen).
 */
function teatatu_data_get_master_site_id() {
	if ( ! is_multisite() ) {
		return get_current_blog_id();
	}
	$id = (int) get_site_option( 'teatatu_data_master_site_id', 0 );
	return $id ? $id : (int) get_main_site_id();
}

/**
 * Whether the current site is the Master Site.
 *
 * @return bool
 */
function teatatu_data_is_master_site() {
	return get_current_blog_id() === teatatu_data_get_master_site_id();
}

/**
 * Runs a callback in the Master Site's context and returns its result.
 * Every read of master data from a consumer site goes through here.
 *
 * @param callable $callback Callback to run.
 * @param array    $args     Arguments for the callback.
 * @return mixed
 */
function teatatu_data_on_master( $callback, $args = array() ) {
	$master = teatatu_data_get_master_site_id();
	if ( ! is_multisite() || get_current_blog_id() === $master ) {
		return call_user_func_array( $callback, $args );
	}
	switch_to_blog( $master );
	try {
		return call_user_func_array( $callback, $args );
	} finally {
		restore_current_blog();
	}
}

/**
 * Current cache version for master data. Any change to any data set bumps
 * it, which orphans every cached read across the network.
 *
 * @return int
 */
function teatatu_data_cache_version() {
	return (int) teatatu_data_get_option( 'teatatu_data_cache_ver', 1 );
}

/**
 * Invalidates every cached master-data read.
 */
function teatatu_data_bump_cache_version() {
	teatatu_data_update_option( 'teatatu_data_cache_ver', teatatu_data_cache_version() + 1 );
}
