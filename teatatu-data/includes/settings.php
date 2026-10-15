<?php
/**
 * Settings: Network Admin > Settings > Teatatu Data on multisite, or
 * Settings > Teatatu Data on a single site. One shared render function
 * stores through get/update_site_option() or get/update_option() depending
 * on is_multisite().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads a plugin option, using the network options table on multisite.
 *
 * @param string $key     Option name.
 * @param mixed  $default Default value.
 * @return mixed
 */
function teatatu_data_get_option( $key, $default = false ) {
	return is_multisite() ? get_site_option( $key, $default ) : get_option( $key, $default );
}

/**
 * Writes a plugin option, using the network options table on multisite.
 *
 * @param string $key   Option name.
 * @param mixed  $value New value.
 * @return bool
 */
function teatatu_data_update_option( $key, $value ) {
	return is_multisite() ? update_site_option( $key, $value ) : update_option( $key, $value );
}

/**
 * Deletes a plugin option, using the network options table on multisite.
 *
 * @param string $key Option name.
 * @return bool
 */
function teatatu_data_delete_option( $key ) {
	return is_multisite() ? delete_site_option( $key ) : delete_option( $key );
}

add_action( 'network_admin_menu', 'teatatu_data_register_network_settings_menu' );

/**
 * Adds the settings page under Network Admin > Settings on multisite.
 */
function teatatu_data_register_network_settings_menu() {
	add_submenu_page(
		'settings.php',
		__( 'Teatatu Data', 'teatatu-data' ),
		__( 'Teatatu Data', 'teatatu-data' ),
		'manage_network_options',
		'teatatu-data-settings',
		'teatatu_data_render_settings_page'
	);
}

add_action( 'admin_menu', 'teatatu_data_register_site_settings_menu' );

/**
 * Adds the settings page under Settings on a single-site install.
 */
function teatatu_data_register_site_settings_menu() {
	if ( is_multisite() ) {
		return;
	}
	add_options_page(
		__( 'Teatatu Data', 'teatatu-data' ),
		__( 'Teatatu Data', 'teatatu-data' ),
		'manage_options',
		'teatatu-data-settings',
		'teatatu_data_render_settings_page'
	);
}

/**
 * Renders the settings form.
 */
function teatatu_data_render_settings_page() {
	if ( ! current_user_can( teatatu_data_structure_cap() ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'teatatu-data' ) );
	}

	$github_repo         = teatatu_data_get_option( 'teatatu_data_github_repo', 'TeAtatu/Teatatu-data-management' );
	$github_token_is_set = (bool) teatatu_data_get_option( 'teatatu_data_github_token', '' );
	$delete_on_uninstall = (bool) teatatu_data_get_option( 'teatatu_data_delete_data_on_uninstall', false );
	$master_site_id      = teatatu_data_get_master_site_id();
	// Always the plain (non-network) admin-post.php, even under Network
	// Admin: wp-admin/network/admin-post.php does not exist in core.
	$post_url = admin_url( 'admin-post.php' );

	if ( isset( $_GET['teatatu_data_settings_saved'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'teatatu-data' ) . '</p></div>';
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Teatatu Data Settings', 'teatatu-data' ); ?></h1>
		<form method="post" action="<?php echo esc_url( $post_url ); ?>">
			<?php wp_nonce_field( 'teatatu_data_save_settings', 'teatatu_data_settings_nonce' ); ?>
			<input type="hidden" name="action" value="teatatu_data_save_settings" />

			<?php if ( is_multisite() ) : ?>
				<h2><?php esc_html_e( 'Network', 'teatatu-data' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="ttd_master_site"><?php esc_html_e( 'Master Site', 'teatatu-data' ); ?></label></th>
						<td>
							<select id="ttd_master_site" name="master_site_id">
								<?php foreach ( get_sites( array( 'number' => 500 ) ) as $site ) : ?>
									<option value="<?php echo esc_attr( $site->blog_id ); ?>" <?php selected( (int) $site->blog_id, $master_site_id ); ?>>
										<?php echo esc_html( ( $site->blogname ? $site->blogname : $site->domain . $site->path ) . ' (ID ' . $site->blog_id . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'The site where master data is edited. Every other site reads it from here.', 'teatatu-data' ); ?></p>
						</td>
					</tr>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Plugin', 'teatatu-data' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><label for="ttd_github_repo"><?php esc_html_e( 'GitHub Repository', 'teatatu-data' ); ?></label></th>
					<td>
						<input type="text" id="ttd_github_repo" name="github_repo" class="regular-text" value="<?php echo esc_attr( $github_repo ); ?>" placeholder="owner/repo" />
						<p class="description"><?php esc_html_e( 'Used to check for plugin updates via GitHub Releases.', 'teatatu-data' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="ttd_github_token"><?php esc_html_e( 'GitHub Access Token', 'teatatu-data' ); ?></label></th>
					<td>
						<input type="password" id="ttd_github_token" name="github_token" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo $github_token_is_set ? esc_attr__( 'Token is set — leave blank to keep it', 'teatatu-data' ) : esc_attr__( 'Optional, only needed for a private repo', 'teatatu-data' ); ?>" />
						<?php if ( $github_token_is_set ) : ?>
							<label style="display:block;margin-top:6px;"><input type="checkbox" name="github_token_clear" value="1" /> <?php esc_html_e( 'Clear the saved token', 'teatatu-data' ); ?></label>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Data on Uninstall', 'teatatu-data' ); ?></th>
					<td>
						<label><input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( $delete_on_uninstall ); ?> /> <?php esc_html_e( 'Delete all Teatatu Data master data when this plugin is uninstalled', 'teatatu-data' ); ?></label>
						<p class="description"><?php esc_html_e( 'Unchecked by default. Master data is shared by other plugins; deleting it can leave their content without places, organisations or tags.', 'teatatu-data' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save Settings', 'teatatu-data' ) ); ?>
		</form>

		<hr />
		<h2><?php esc_html_e( 'Updates', 'teatatu-data' ); ?></h2>
		<p><?php esc_html_e( 'Current version:', 'teatatu-data' ); ?> <strong><?php echo esc_html( TEATATU_DATA_VERSION ); ?></strong></p>
		<form method="post" action="<?php echo esc_url( $post_url ); ?>">
			<?php wp_nonce_field( 'teatatu_data_check_updates', 'teatatu_data_check_updates_nonce' ); ?>
			<input type="hidden" name="action" value="teatatu_data_check_updates" />
			<?php submit_button( __( 'Check for Updates Now', 'teatatu-data' ), 'secondary' ); ?>
		</form>
	</div>
	<?php
}

add_action( 'admin_post_teatatu_data_save_settings', 'teatatu_data_handle_save_settings' );

/**
 * Handles the settings form submission.
 */
function teatatu_data_handle_save_settings() {
	if ( ! isset( $_POST['teatatu_data_settings_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['teatatu_data_settings_nonce'] ) ), 'teatatu_data_save_settings' ) ) {
		wp_die( esc_html__( 'Security check failed.', 'teatatu-data' ) );
	}
	if ( ! current_user_can( teatatu_data_structure_cap() ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-data' ), 403 );
	}

	if ( is_multisite() && isset( $_POST['master_site_id'] ) ) {
		$master = absint( $_POST['master_site_id'] );
		$valid  = array_map( 'intval', get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) );
		if ( in_array( $master, $valid, true ) ) {
			update_site_option( 'teatatu_data_master_site_id', $master );
		}
	}

	if ( isset( $_POST['github_repo'] ) ) {
		$repo = sanitize_text_field( wp_unslash( $_POST['github_repo'] ) );
		teatatu_data_update_option( 'teatatu_data_github_repo', $repo ? $repo : 'TeAtatu/Teatatu-data-management' );
	}
	if ( ! empty( $_POST['github_token_clear'] ) ) {
		teatatu_data_delete_option( 'teatatu_data_github_token' );
	} elseif ( ! empty( $_POST['github_token'] ) ) {
		teatatu_data_update_option( 'teatatu_data_github_token', sanitize_text_field( wp_unslash( $_POST['github_token'] ) ) );
	}
	teatatu_data_update_option( 'teatatu_data_delete_data_on_uninstall', ! empty( $_POST['delete_data_on_uninstall'] ) );

	teatatu_data_bump_cache_version();

	$redirect_base = is_multisite() ? network_admin_url( 'settings.php' ) : admin_url( 'options-general.php' );
	wp_safe_redirect( add_query_arg( array( 'page' => 'teatatu-data-settings', 'teatatu_data_settings_saved' => '1' ), $redirect_base ) );
	exit;
}
