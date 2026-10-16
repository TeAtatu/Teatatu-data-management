<?php
/**
 * Self-hosted GitHub-release-based auto-updater. Teatatu Data isn't on
 * WordPress.org, so this plugs into the normal wp-admin "update available"
 * UI (and the native auto-update opt-in) using only wp_remote_get() and
 * core update-related filters — no bundled update library.
 *
 * How a release is installed:
 *  - the latest GitHub Release of the configured repo is compared with the
 *    installed version (tag "v0.8.1" or "0.8.1");
 *  - the package is the release's attached `teatatu-data*.zip` asset (built
 *    by tools/build-release.sh), else GitHub's source zip of the tag;
 *  - the source zip holds the whole repository with the plugin in its
 *    `teatatu-data/` subfolder, so only that subfolder is installed; a
 *    package without the plugin is refused before anything is replaced;
 *  - with a GitHub token set (private repos), the token is sent with the
 *    release lookup and the download.
 *
 * A development copy — the plugin folder is a symlink, or a git checkout —
 * is never updated: WordPress replaces the plugin folder by deleting it
 * recursively, which follows a symlink and would empty the repository it
 * points to.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads a header from this plugin's own file, with a tiny static cache.
 *
 * @param string $key 'Name', 'Description', 'RequiresWP', or 'RequiresPHP'.
 * @return string
 */
function teatatu_data_get_plugin_header( $key ) {
	static $headers = null;
	if ( null === $headers ) {
		$headers = get_file_data(
			TEATATU_DATA_FILE,
			array(
				'Name'        => 'Plugin Name',
				'Description' => 'Description',
				'RequiresWP'  => 'Requires at least',
				'RequiresPHP' => 'Requires PHP',
			)
		);
	}
	return isset( $headers[ $key ] ) ? $headers[ $key ] : '';
}

/**
 * Gets the configured `owner/repo` GitHub slug.
 *
 * @return string
 */
function teatatu_data_get_github_repo() {
	$repo = teatatu_data_get_option( 'teatatu_data_github_repo', 'TeAtatu/Teatatu-data-management' );
	return $repo ? $repo : 'TeAtatu/Teatatu-data-management';
}

/**
 * Whether this install is a development copy that must never be updated
 * in place: the plugin folder is a symlink (e.g. to a git working copy) or
 * is itself a git checkout.
 *
 * @return bool
 */
function teatatu_data_is_dev_install() {
	$dir = WP_PLUGIN_DIR . '/' . dirname( TEATATU_DATA_BASENAME );
	$dev = is_link( $dir ) || file_exists( $dir . '/.git' );
	/**
	 * Filters whether this install counts as a development copy (no updates).
	 *
	 * @param bool   $dev Whether it is.
	 * @param string $dir Plugin folder.
	 */
	return (bool) apply_filters( 'teatatu_data_is_dev_install', $dev, $dir );
}

/**
 * Fetches (and caches) the latest GitHub release for the configured repo.
 * A failed lookup (no release yet, GitHub down or rate-limited) is cached
 * for an hour so update checks don't keep asking.
 *
 * @param bool $force Bypass the cache.
 * @return array|false Decoded release data, or false.
 */
function teatatu_data_fetch_latest_release( $force = false ) {
	$cache_key = 'teatatu_data_github_release';

	if ( ! $force ) {
		$cached = get_site_transient( $cache_key );
		if ( false !== $cached ) {
			return ( is_array( $cached ) && ! empty( $cached['ttd_failed'] ) ) ? false : $cached;
		}
	}

	$headers = array(
		'User-Agent' => 'Teatatu-Data-Plugin/' . TEATATU_DATA_VERSION . ' (' . home_url() . ')',
		'Accept'     => 'application/vnd.github+json',
	);
	$token   = teatatu_data_get_option( 'teatatu_data_github_token', '' );
	if ( $token ) {
		$headers['Authorization'] = 'token ' . $token;
	}

	$response = wp_remote_get(
		'https://api.github.com/repos/' . teatatu_data_get_github_repo() . '/releases/latest',
		array(
			'headers' => $headers,
			'timeout' => 15,
		)
	);

	$body = ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) ? json_decode( wp_remote_retrieve_body( $response ), true ) : null;
	if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
		set_site_transient( $cache_key, array( 'ttd_failed' => 1 ), HOUR_IN_SECONDS );
		return false;
	}

	set_site_transient( $cache_key, $body, 12 * HOUR_IN_SECONDS );
	return $body;
}

/**
 * Download URL for a release: its attached teatatu-data*.zip asset when
 * there is one (with a token: the API asset URL, which accepts the token),
 * else GitHub's source zip of the tag.
 *
 * @param array $release Release data.
 * @return string
 */
function teatatu_data_release_package( $release ) {
	$token = teatatu_data_get_option( 'teatatu_data_github_token', '' );
	foreach ( isset( $release['assets'] ) ? (array) $release['assets'] : array() as $asset ) {
		$name = isset( $asset['name'] ) ? (string) $asset['name'] : '';
		if ( preg_match( '/^teatatu-data.*\.zip$/i', $name ) ) {
			if ( $token && ! empty( $asset['url'] ) ) {
				return (string) $asset['url'];
			}
			if ( ! empty( $asset['browser_download_url'] ) ) {
				return (string) $asset['browser_download_url'];
			}
		}
	}
	return isset( $release['zipball_url'] ) ? (string) $release['zipball_url'] : '';
}

/**
 * The plugin's entry for the update_plugins transient.
 *
 * @param string $version Version offered.
 * @param string $package Download URL ('' when up to date or not updatable).
 * @return object
 */
function teatatu_data_update_item( $version, $package ) {
	return (object) array(
		'id'           => 'https://github.com/' . teatatu_data_get_github_repo(),
		'slug'         => 'teatatu-data',
		'plugin'       => TEATATU_DATA_BASENAME,
		'new_version'  => $version,
		'url'          => 'https://github.com/' . teatatu_data_get_github_repo(),
		'package'      => $package,
		'tested'       => get_bloginfo( 'version' ),
		'requires'     => teatatu_data_get_plugin_header( 'RequiresWP' ),
		'requires_php' => teatatu_data_get_plugin_header( 'RequiresPHP' ),
		'icons'        => array(),
		'banners'      => array(),
	);
}

add_filter( 'pre_set_site_transient_update_plugins', 'teatatu_data_check_for_update' );

/**
 * Populates the update_plugins transient: an update when a newer GitHub
 * release exists, otherwise a "no update" entry — WordPress only offers the
 * "Enable auto-updates" toggle for plugins listed in one or the other.
 * A development copy is always listed as up to date.
 *
 * @param object $transient Existing update_plugins transient.
 * @return object
 */
function teatatu_data_check_for_update( $transient ) {
	if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
		return $transient;
	}

	$release        = teatatu_data_is_dev_install() ? false : teatatu_data_fetch_latest_release();
	$remote_version = $release ? ltrim( (string) $release['tag_name'], 'vV' ) : '';
	$package        = $release ? teatatu_data_release_package( $release ) : '';

	if ( $remote_version && $package && version_compare( $remote_version, TEATATU_DATA_VERSION, '>' ) ) {
		$transient->response[ TEATATU_DATA_BASENAME ] = teatatu_data_update_item( $remote_version, $package );
		unset( $transient->no_update[ TEATATU_DATA_BASENAME ] );
	} else {
		$transient->no_update[ TEATATU_DATA_BASENAME ] = teatatu_data_update_item( TEATATU_DATA_VERSION, '' );
		unset( $transient->response[ TEATATU_DATA_BASENAME ] );
	}
	return $transient;
}

add_filter( 'auto_update_plugin', 'teatatu_data_no_auto_update_for_dev', 10, 2 );

/**
 * Never auto-updates a development copy.
 *
 * @param bool|null $update Whether to update.
 * @param object    $item   Update item.
 * @return bool|null
 */
function teatatu_data_no_auto_update_for_dev( $update, $item ) {
	if ( isset( $item->plugin ) && TEATATU_DATA_BASENAME === $item->plugin && teatatu_data_is_dev_install() ) {
		return false;
	}
	return $update;
}

add_filter( 'plugin_row_meta', 'teatatu_data_dev_install_row_meta', 10, 2 );

/**
 * Says on the Plugins screen why a development copy gets no updates.
 *
 * @param string[] $meta Row meta.
 * @param string   $file Plugin basename.
 * @return string[]
 */
function teatatu_data_dev_install_row_meta( $meta, $file ) {
	if ( TEATATU_DATA_BASENAME === $file && teatatu_data_is_dev_install() ) {
		$meta[] = esc_html__( 'Development copy (symlink or git checkout): updates from GitHub are switched off.', 'teatatu-data' );
	}
	return $meta;
}

add_filter( 'http_request_args', 'teatatu_data_github_download_auth', 10, 2 );

/**
 * Sends the GitHub token with requests to GitHub's API and source-zip host
 * (private repos), and asks the API for the file itself when downloading a
 * release asset. Nothing is added without a token or for other hosts.
 *
 * @param array  $args Request arguments.
 * @param string $url  URL.
 * @return array
 */
function teatatu_data_github_download_auth( $args, $url ) {
	$token = teatatu_data_get_option( 'teatatu_data_github_token', '' );
	$host  = wp_parse_url( $url, PHP_URL_HOST );
	if ( ! $token || ! in_array( $host, array( 'api.github.com', 'codeload.github.com' ), true ) ) {
		return $args;
	}
	$repo = teatatu_data_get_github_repo();
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );
	if ( 'api.github.com' === $host && 0 !== stripos( $path, '/repos/' . $repo . '/' ) ) {
		return $args;
	}
	if ( 'codeload.github.com' === $host && 0 !== stripos( $path, '/' . $repo . '/' ) ) {
		return $args;
	}
	$args['headers']                  = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();
	$args['headers']['Authorization'] = 'token ' . $token;
	if ( preg_match( '#/releases/assets/\d+$#', $path ) ) {
		$args['headers']['Accept'] = 'application/octet-stream';
	}
	return $args;
}

add_filter( 'plugins_api', 'teatatu_data_plugins_api', 10, 3 );

/**
 * Powers the "View version details" popup from the latest GitHub release.
 *
 * @param false|object|array $result The result object/array (default false).
 * @param string              $action  The type of information being requested.
 * @param object              $args    Plugin API arguments.
 * @return false|object
 */
function teatatu_data_plugins_api( $result, $action, $args ) {
	if ( 'plugin_information' !== $action || empty( $args->slug ) || 'teatatu-data' !== $args->slug ) {
		return $result;
	}

	$release        = teatatu_data_fetch_latest_release();
	$remote_version = $release ? ltrim( (string) $release['tag_name'], 'vV' ) : TEATATU_DATA_VERSION;
	$changelog      = ( $release && ! empty( $release['body'] ) ) ? $release['body'] : '';

	$info = array(
		'name'          => teatatu_data_get_plugin_header( 'Name' ),
		'slug'          => 'teatatu-data',
		'version'       => $remote_version,
		'author'        => '<a href="https://github.com/TeAtatu">TeAtatu</a>',
		'homepage'      => 'https://github.com/' . teatatu_data_get_github_repo(),
		'requires'      => teatatu_data_get_plugin_header( 'RequiresWP' ),
		'requires_php'  => teatatu_data_get_plugin_header( 'RequiresPHP' ),
		'download_link' => ( $release && ! teatatu_data_is_dev_install() ) ? teatatu_data_release_package( $release ) : '',
		'sections'      => array(
			'description' => wp_kses_post( teatatu_data_get_plugin_header( 'Description' ) ),
			'changelog'   => $changelog ? wpautop( wp_kses_post( $changelog ) ) : '<p>' . esc_html__( 'No changelog available.', 'teatatu-data' ) . '</p>',
		),
	);

	return (object) $info;
}

add_filter( 'upgrader_source_selection', 'teatatu_data_fix_source_dir', 10, 4 );

/**
 * Makes the extracted package install as `teatatu-data/`, or refuses it
 * (before WordPress removes the installed copy):
 *  - a development copy is never replaced;
 *  - a built release zip already holds `teatatu-data/` (left as is);
 *  - GitHub's source zip holds `owner-repo-<sha>/` with the whole
 *    repository and the plugin in its `teatatu-data/` subfolder — only that
 *    subfolder is installed (README, SPEC etc. are left behind);
 *  - any other folder whose top level is the plugin is renamed;
 *  - a package without teatatu-data.php is refused.
 *
 * @param string|WP_Error $source        Path to the extracted source.
 * @param string          $remote_source Path to the parent temp directory.
 * @param WP_Upgrader     $upgrader      Upgrader instance.
 * @param array           $hook_extra    Extra arguments, including 'plugin'.
 * @return string|WP_Error
 */
function teatatu_data_fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
	global $wp_filesystem;

	if ( is_wp_error( $source ) || empty( $hook_extra['plugin'] ) || TEATATU_DATA_BASENAME !== $hook_extra['plugin'] || ! $wp_filesystem ) {
		return $source;
	}
	if ( teatatu_data_is_dev_install() ) {
		return new WP_Error( 'teatatu_data_dev_install', __( 'This copy of Teatatu Data is a symlink or git checkout, so it is not updated from GitHub. Update it with git instead. Nothing was changed.', 'teatatu-data' ) );
	}

	$source  = trailingslashit( $source );
	$desired = trailingslashit( $remote_source ) . 'teatatu-data/';

	if ( $wp_filesystem->exists( $source . 'teatatu-data.php' ) ) {
		$plugin_dir = $source;
	} elseif ( $wp_filesystem->exists( $source . 'teatatu-data/teatatu-data.php' ) ) {
		$plugin_dir = $source . 'teatatu-data/';
	} else {
		return new WP_Error( 'teatatu_data_bad_package', __( 'The downloaded update does not contain teatatu-data/teatatu-data.php. Nothing was changed.', 'teatatu-data' ) );
	}
	if ( $plugin_dir === $desired ) {
		return $desired;
	}
	// Move the plugin folder out to remote_source/teatatu-data/ (via a
	// temporary name, as it may sit inside a folder of the same name).
	$staging = trailingslashit( $remote_source ) . 'ttd-staging-' . wp_generate_password( 6, false ) . '/';
	if ( ! $wp_filesystem->move( $plugin_dir, $staging, true ) ) {
		return new WP_Error( 'teatatu_data_move_failed', __( 'Could not prepare the downloaded update.', 'teatatu-data' ) );
	}
	if ( $wp_filesystem->exists( $desired ) ) {
		$wp_filesystem->delete( $desired, true );
	}
	if ( ! $wp_filesystem->move( $staging, $desired, true ) ) {
		return new WP_Error( 'teatatu_data_move_failed', __( 'Could not prepare the downloaded update.', 'teatatu-data' ) );
	}
	return $desired;
}

add_action( 'admin_post_teatatu_data_check_updates', 'teatatu_data_handle_check_updates' );

/**
 * Handles the "Check for Updates Now" button: clears cached GitHub data and
 * the core update_plugins transient so the next admin page load re-checks.
 */
function teatatu_data_handle_check_updates() {
	if ( ! isset( $_POST['teatatu_data_check_updates_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['teatatu_data_check_updates_nonce'] ) ), 'teatatu_data_check_updates' ) ) {
		wp_die( esc_html__( 'Security check failed.', 'teatatu-data' ) );
	}
	$cap = is_multisite() ? 'manage_network_options' : 'manage_options';
	if ( ! current_user_can( $cap ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-data' ), 403 );
	}

	delete_site_transient( 'teatatu_data_github_release' );
	delete_site_transient( 'update_plugins' );

	$redirect_base = is_multisite() ? network_admin_url( 'settings.php' ) : admin_url( 'options-general.php' );
	wp_safe_redirect( add_query_arg( array( 'page' => 'teatatu-data-settings', 'teatatu_data_settings_saved' => '1' ), $redirect_base ) );
	exit;
}
