<?php
/**
 * Roles and capabilities for data stewardship.
 *
 * - Administrator and Editor are Data Stewards: they edit master data,
 *   approve proposed changes, run imports and resolve quality issues.
 * - The `teatatu_data_agent` role can read every data set and *propose*
 *   changes, which wait for a steward to approve. It can never change
 *   master data directly. That boundary is permanent, not a temporary
 *   restriction.
 *
 * Network-wide structure (schemas, Master Site, external source settings)
 * needs manage_network_options on multisite, manage_options otherwise.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capabilities granted to the restricted AI-agent role.
 *
 * @return bool[]
 */
function teatatu_data_agent_capabilities() {
	return array(
		'read'                  => true,
		'read_teatatu_data'     => true,
		'propose_teatatu_data'  => true,
	);
}

/**
 * Capabilities granted to Administrator and Editor (Data Stewards).
 *
 * @return bool[]
 */
function teatatu_data_steward_capabilities() {
	return array(
		'read_teatatu_data'     => true,
		'propose_teatatu_data'  => true,
		'edit_teatatu_data'     => true,
		'approve_teatatu_data'  => true,
		'import_teatatu_data'   => true,
		'manage_teatatu_data'   => true,
	);
}

/**
 * Adds the `teatatu_data_agent` role and grants steward capabilities to
 * Administrator/Editor on the current site. Safe to call repeatedly.
 */
function teatatu_data_add_role_and_caps() {
	add_role(
		'teatatu_data_agent',
		__( 'Data Agent', 'teatatu-data' ),
		teatatu_data_agent_capabilities()
	);

	// Re-adding an existing role with add_role() is a no-op, so refresh caps explicitly.
	$agent_role = get_role( 'teatatu_data_agent' );
	if ( $agent_role ) {
		foreach ( teatatu_data_agent_capabilities() as $cap => $grant ) {
			$agent_role->add_cap( $cap, $grant );
		}
	}

	foreach ( array( 'administrator', 'editor' ) as $role_name ) {
		$role = get_role( $role_name );
		if ( ! $role ) {
			continue;
		}
		foreach ( teatatu_data_steward_capabilities() as $cap => $grant ) {
			$role->add_cap( $cap, $grant );
		}
	}
}

/**
 * Re-syncs roles/capabilities whenever the plugin's version has changed
 * since they were last provisioned on this site. Capabilities are database
 * state: a site whose plugin files were replaced in place never otherwise
 * receives capabilities added in a later version.
 */
function teatatu_data_maybe_upgrade_caps() {
	if ( get_option( 'teatatu_data_caps_version' ) !== TEATATU_DATA_VERSION ) {
		teatatu_data_add_role_and_caps();
		update_option( 'teatatu_data_caps_version', TEATATU_DATA_VERSION );
	}
}
add_action( 'admin_init', 'teatatu_data_maybe_upgrade_caps' );

/**
 * Removes the `teatatu_data_agent` role and strips steward capabilities
 * from Administrator/Editor on the current site.
 */
function teatatu_data_remove_role_and_caps() {
	remove_role( 'teatatu_data_agent' );

	foreach ( array( 'administrator', 'editor' ) as $role_name ) {
		$role = get_role( $role_name );
		if ( ! $role ) {
			continue;
		}
		foreach ( array_keys( teatatu_data_steward_capabilities() ) as $cap ) {
			$role->remove_cap( $cap );
		}
	}
}

/**
 * Capability needed to change network-wide structure (schemas, Master
 * Site, external source settings).
 *
 * @return string
 */
function teatatu_data_structure_cap() {
	return is_multisite() ? 'manage_network_options' : 'manage_options';
}
