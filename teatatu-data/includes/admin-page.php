<?php
/**
 * The "Teatatu Data" admin screen. Tabs: Dictionary · one per data set ·
 * Proposed changes · Import / Export · Data set settings.
 *
 * On the Master Site this is where data is maintained. On every other site
 * it is read-only: it shows the Master Site's data, with no forms. Nothing
 * here is shown outside wp-admin (SPEC.md §11 Q6).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * URL of the Teatatu Data screen on the current site.
 *
 * @param array $args Query arguments.
 * @return string
 */
function teatatu_data_admin_url( $args = array() ) {
	return add_query_arg( array_merge( array( 'page' => 'teatatu-data' ), $args ), admin_url( 'admin.php' ) );
}

add_action( 'admin_menu', 'teatatu_data_register_admin_page' );

/**
 * Adds the top-level Teatatu Data menu, with a count of pending changes
 * for stewards.
 */
function teatatu_data_register_admin_page() {
	$title = __( 'Teatatu Data', 'teatatu-data' );
	if ( current_user_can( 'approve_teatatu_data' ) && teatatu_data_get_record_datasets() ) {
		$pending = teatatu_data_pending_count();
		if ( $pending ) {
			$title .= ' <span class="awaiting-mod"><span class="pending-count">' . (int) $pending . '</span></span>';
		}
	}
	add_menu_page(
		__( 'Teatatu Data', 'teatatu-data' ),
		$title,
		'read_teatatu_data',
		'teatatu-data',
		'teatatu_data_render_admin_page',
		'dashicons-database',
		30
	);
}

/**
 * Whether this screen may change data: the Master Site, and a user who can
 * at least propose.
 *
 * @return bool
 */
function teatatu_data_admin_can_write() {
	return teatatu_data_is_master_site() && current_user_can( 'propose_teatatu_data' );
}

/**
 * Renders the admin screen.
 */
function teatatu_data_render_admin_page() {
	if ( ! current_user_can( 'read_teatatu_data' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'teatatu-data' ) );
	}
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
	$datasets = teatatu_data_get_record_datasets();
	$tab      = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dictionary';
	$key      = isset( $_GET['dataset'] ) ? sanitize_key( wp_unslash( $_GET['dataset'] ) ) : '';
	$action   = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
	$notice   = isset( $_GET['ttd_notice'] ) ? sanitize_key( wp_unslash( $_GET['ttd_notice'] ) ) : '';
	// phpcs:enable

	$tabs = array( 'dictionary' => __( 'Dictionary', 'teatatu-data' ) );
	foreach ( $datasets as $dataset_key => $dataset ) {
		$tabs[ 'records:' . $dataset_key ] = $dataset['label'];
	}
	if ( $datasets ) {
		$pending = current_user_can( 'propose_teatatu_data' ) ? teatatu_data_pending_count() : 0;
		/* translators: %d: number of pending changes */
		$tabs['proposals'] = $pending ? sprintf( __( 'Proposed changes (%d)', 'teatatu-data' ), $pending ) : __( 'Proposed changes', 'teatatu-data' );
		$open_issues      = teatatu_data_quality_open_count();
		/* translators: %d: number of open quality issues */
		$tabs['quality']  = $open_issues ? sprintf( __( 'Data quality (%d)', 'teatatu-data' ), $open_issues ) : __( 'Data quality', 'teatatu-data' );
		$tabs['transfer'] = __( 'Import / Export', 'teatatu-data' );
		if ( current_user_can( 'manage_teatatu_data' ) ) {
			$tabs['governance']  = __( 'Data set settings', 'teatatu-data' );
			$tabs['connections'] = __( 'Connections', 'teatatu-data' );
		}
	}
	$current_tab = 'records' === $tab ? 'records:' . $key : $tab;
	if ( ! isset( $tabs[ $current_tab ] ) ) {
		$current_tab = 'dictionary';
		$tab         = 'dictionary';
	}
	?>
	<div class="wrap teatatu-data">
		<h1><?php esc_html_e( 'Teatatu Data', 'teatatu-data' ); ?></h1>
		<?php if ( ! teatatu_data_is_master_site() ) : ?>
			<div class="notice notice-info"><p>
				<?php esc_html_e( 'Master data is maintained on the Master Site. This site shows it read-only.', 'teatatu-data' ); ?>
				<?php if ( is_multisite() ) : ?>
					<a href="<?php echo esc_url( get_admin_url( teatatu_data_get_master_site_id(), 'admin.php?page=teatatu-data' ) ); ?>"><?php esc_html_e( 'Go to the Master Site', 'teatatu-data' ); ?></a>
				<?php endif; ?>
			</p></div>
		<?php endif; ?>
		<?php teatatu_data_render_notice( $notice ); ?>

		<nav class="nav-tab-wrapper">
			<?php foreach ( $tabs as $tab_key => $label ) : ?>
				<?php
				$parts = explode( ':', $tab_key );
				$url   = 'records' === $parts[0] ? teatatu_data_admin_url( array( 'tab' => 'records', 'dataset' => $parts[1] ) ) : teatatu_data_admin_url( array( 'tab' => $tab_key ) );
				?>
				<a href="<?php echo esc_url( $url ); ?>" class="nav-tab <?php echo $current_tab === $tab_key ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>

		<?php
		switch ( $tab ) {
			case 'records':
				if ( 'edit' === $action || 'new' === $action ) {
					teatatu_data_render_record_screen( $key );
				} else {
					teatatu_data_render_records_list( $key );
				}
				break;
			case 'proposals':
				teatatu_data_render_proposals_tab();
				break;
			case 'transfer':
				teatatu_data_render_transfer_tab();
				break;
			case 'quality':
				teatatu_data_render_quality_tab();
				break;
			case 'governance':
				teatatu_data_render_governance_tab();
				break;
			case 'connections':
				teatatu_data_render_connections_tab();
				break;
			default:
				teatatu_data_render_dictionary_tab();
		}
		?>
	</div>
	<?php
}

/**
 * Shows a notice for a ttd_notice code.
 *
 * @param string $code Notice code.
 */
function teatatu_data_render_notice( $code ) {
	$notices = array(
		'saved'     => array( 'success', __( 'Record saved.', 'teatatu-data' ) ),
		'unchanged' => array( 'info', __( 'Nothing changed.', 'teatatu-data' ) ),
		'proposed'  => array( 'success', __( 'Change proposed. A steward will review it.', 'teatatu-data' ) ),
		'approved'  => array( 'success', __( 'Change approved and applied.', 'teatatu-data' ) ),
		'rejected'  => array( 'success', __( 'Change rejected.', 'teatatu-data' ) ),
		'reverted'  => array( 'success', __( 'Change reverted.', 'teatatu-data' ) ),
		'batch'     => array( 'success', __( 'Batch approved.', 'teatatu-data' ) ),
		'settings'  => array( 'success', __( 'Data set settings saved.', 'teatatu-data' ) ),
		'error'     => array( 'error', '' ),
		'message'   => array( 'success', '' ),
		'error_message' => array( 'warning', '' ),
	);
	if ( ! isset( $notices[ $code ] ) ) {
		return;
	}
	$message = $notices[ $code ][1];
	if ( 'error' === $code ) {
		$stored  = get_transient( 'teatatu_data_error_' . get_current_user_id() );
		$message = $stored ? $stored : __( 'Something went wrong.', 'teatatu-data' );
		delete_transient( 'teatatu_data_error_' . get_current_user_id() );
	} elseif ( '' === $message ) {
		$message = (string) get_transient( 'teatatu_data_message_' . get_current_user_id() );
		delete_transient( 'teatatu_data_message_' . get_current_user_id() );
		if ( '' === $message ) {
			return;
		}
	}
	printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $notices[ $code ][0] ), esc_html( $message ) );
}

/**
 * Formats a stored value for display.
 *
 * @param array  $dataset   Data set definition.
 * @param string $field_key Field key (schema or standard).
 * @param mixed  $value     Value.
 * @return string Plain text.
 */
function teatatu_data_display_value( $dataset, $field_key, $value ) {
	if ( teatatu_data_is_empty( $value ) ) {
		return '—';
	}
	$field = isset( $dataset['fields'][ $field_key ] ) ? $dataset['fields'][ $field_key ] : null;
	if ( 'replaced_by' === $field_key || ( $field && 'reference' === $field['type'] ) ) {
		$target = 'replaced_by' === $field_key ? $dataset['key'] : $field['references'];
		$names  = array();
		foreach ( (array) $value as $uuid ) {
			$record  = teatatu_data_get( $target, $uuid );
			$names[] = $record ? teatatu_data_record_title( $target, $record ) : $uuid;
		}
		return implode( ', ', $names );
	}
	if ( 'status' === $field_key ) {
		$statuses = teatatu_data_lifecycle_statuses();
		return isset( $statuses[ $value ] ) ? $statuses[ $value ]['label'] : (string) $value;
	}
	if ( is_bool( $value ) ) {
		return $value ? __( 'Yes', 'teatatu-data' ) : __( 'No', 'teatatu-data' );
	}
	if ( $field && 'enum' === $field['type'] && isset( $field['enum'][ $value ] ) ) {
		return $field['enum'][ $value ];
	}
	if ( is_array( $value ) ) {
		if ( isset( $value['lat'], $value['lng'] ) && 2 === count( $value ) ) {
			return $value['lat'] . ', ' . $value['lng'];
		}
		$parts = array();
		foreach ( $value as $k => $v ) {
			$parts[] = is_int( $k ) ? (string) $v : $k . ': ' . $v;
		}
		return implode( ', ', $parts );
	}
	return (string) $value;
}

/**
 * Label of a field (schema or standard).
 *
 * @param array  $dataset   Data set definition.
 * @param string $field_key Field key.
 * @return string
 */
function teatatu_data_field_label( $dataset, $field_key ) {
	if ( isset( $dataset['fields'][ $field_key ] ) ) {
		return $dataset['fields'][ $field_key ]['label'];
	}
	$standard = teatatu_data_standard_fields();
	return isset( $standard[ $field_key ] ) ? $standard[ $field_key ]['label'] : $field_key;
}

/* ---------------------------------------------------------------------
 * Dictionary
 * ------------------------------------------------------------------- */

/**
 * Renders the data dictionary.
 */
function teatatu_data_render_dictionary_tab() {
	$dictionary = teatatu_data_on_master( 'teatatu_data_get_dictionary' );
	$kinds      = array(
		'master'    => __( 'Master data', 'teatatu-data' ),
		'reference' => __( 'Reference data', 'teatatu-data' ),
		'metadata'  => __( 'Structure only', 'teatatu-data' ),
	);
	?>
	<p><?php esc_html_e( 'What every data set is, who looks after it, where it comes from, and what each field means.', 'teatatu-data' ); ?></p>
	<?php if ( ! $dictionary['datasets'] ) : ?>
		<p><em><?php esc_html_e( 'No data sets are registered yet.', 'teatatu-data' ); ?></em></p>
	<?php endif; ?>

	<?php foreach ( $dictionary['datasets'] as $key => $dataset ) : ?>
		<h2><?php echo esc_html( $dataset['label'] ); ?> <code><?php echo esc_html( $key ); ?></code></h2>
		<p><?php echo esc_html( $dataset['description'] ); ?></p>
		<table class="widefat fixed" style="max-width:900px;margin-bottom:12px;">
			<tbody>
				<tr><th style="width:180px;"><?php esc_html_e( 'Kind', 'teatatu-data' ); ?></th><td><?php echo esc_html( $kinds[ $dataset['kind'] ] ); ?></td></tr>
				<?php foreach ( array( 'owner' => __( 'Data owner', 'teatatu-data' ), 'steward' => __( 'Data steward', 'teatatu-data' ) ) as $role => $role_label ) : ?>
					<tr>
						<th><?php echo esc_html( $role_label ); ?></th>
						<td>
							<?php
							if ( $dataset[ $role ]['name'] ) {
								echo esc_html( $dataset[ $role ]['name'] );
							} else {
								/* translators: %s: default role */
								echo '<em>' . esc_html( sprintf( __( 'Not chosen (default: %s)', 'teatatu-data' ), $dataset[ $role ]['default_role'] ? $dataset[ $role ]['default_role'] : '—' ) ) . '</em>';
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
				<?php if ( 'metadata' !== $dataset['kind'] ) : ?>
					<tr><th><?php esc_html_e( 'Review interval', 'teatatu-data' ); ?></th><td><?php /* translators: %d: months */ echo esc_html( sprintf( _n( '%d month', '%d months', $dataset['review_interval'], 'teatatu-data' ), $dataset['review_interval'] ) ); ?></td></tr>
				<?php endif; ?>
				<tr><th><?php esc_html_e( 'Source', 'teatatu-data' ); ?></th><td><?php echo esc_html( $dataset['source'] ? $dataset['source'] : '—' ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Used by', 'teatatu-data' ); ?></th><td><?php echo esc_html( $dataset['consumers'] ? implode( ', ', $dataset['consumers'] ) : '—' ); ?></td></tr>
				<?php if ( $dataset['rules'] ) : ?>
					<tr><th><?php esc_html_e( 'Rules', 'teatatu-data' ); ?></th><td><ul class="ul-disc" style="margin:0 0 0 1em;"><?php foreach ( $dataset['rules'] as $rule ) : ?><li><?php echo esc_html( $rule ); ?></li><?php endforeach; ?></ul></td></tr>
				<?php endif; ?>
			</tbody>
		</table>
		<table class="widefat striped" style="max-width:1200px;">
			<thead>
				<tr>
					<th style="width:22%;"><?php esc_html_e( 'Field', 'teatatu-data' ); ?></th>
					<th style="width:18%;"><?php esc_html_e( 'Type', 'teatatu-data' ); ?></th>
					<th style="width:10%;"><?php esc_html_e( 'Rules', 'teatatu-data' ); ?></th>
					<th><?php esc_html_e( 'Meaning', 'teatatu-data' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $dataset['fields'] as $field_key => $field ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $field['label'] ); ?></strong><br /><code><?php echo esc_html( $field_key ); ?></code></td>
						<td>
							<?php echo esc_html( $field['type'] ); ?>
							<?php if ( $field['references'] ) : ?>
								→ <code><?php echo esc_html( $field['references'] ); ?></code><?php echo $field['multiple'] ? esc_html__( ' (several)', 'teatatu-data' ) : ''; ?>
							<?php endif; ?>
							<?php if ( $field['enum'] ) : ?>
								<br /><small><?php echo esc_html( implode( ', ', array_keys( $field['enum'] ) ) ); ?></small>
							<?php endif; ?>
						</td>
						<td>
							<?php
							$rules = array();
							if ( $field['required'] ) {
								$rules[] = __( 'required', 'teatatu-data' );
							}
							if ( $field['unique'] ) {
								$rules[] = __( 'unique', 'teatatu-data' );
							}
							if ( $field['pattern'] ) {
								$rules[] = __( 'pattern', 'teatatu-data' );
							}
							if ( null !== $field['default'] && '' !== (string) $field['default'] ) {
								/* translators: %s: default value */
								$rules[] = sprintf( __( 'default %s', 'teatatu-data' ), is_bool( $field['default'] ) ? ( $field['default'] ? 'yes' : 'no' ) : $field['default'] );
							}
							if ( $field['derived'] ) {
								$rules[] = __( 'worked out by the plugin', 'teatatu-data' );
							}
							if ( ! empty( $field['recommended'] ) ) {
								$rules[] = __( 'recommended', 'teatatu-data' );
							}
							echo esc_html( $rules ? implode( ', ', $rules ) : '—' );
							?>
						</td>
						<td>
							<?php echo esc_html( $field['description'] ); ?>
							<?php if ( '' !== (string) $field['example'] ) : ?>
								<br /><small><?php esc_html_e( 'Example:', 'teatatu-data' ); ?> <code><?php echo esc_html( $field['example'] ); ?></code></small>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endforeach; ?>

	<h2><?php esc_html_e( 'Standard fields', 'teatatu-data' ); ?></h2>
	<p><?php esc_html_e( 'Every record in every data set also has these.', 'teatatu-data' ); ?></p>
	<table class="widefat striped" style="max-width:1200px;">
		<thead>
			<tr>
				<th style="width:22%;"><?php esc_html_e( 'Field', 'teatatu-data' ); ?></th>
				<th style="width:18%;"><?php esc_html_e( 'Type', 'teatatu-data' ); ?></th>
				<th style="width:10%;"><?php esc_html_e( 'Set by', 'teatatu-data' ); ?></th>
				<th><?php esc_html_e( 'Meaning', 'teatatu-data' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $dictionary['standard_fields'] as $field_key => $field ) : ?>
				<tr>
					<td><strong><?php echo esc_html( $field['label'] ); ?></strong><br /><code><?php echo esc_html( $field_key ); ?></code></td>
					<td><?php echo esc_html( $field['type'] ); ?></td>
					<td><?php echo $field['writable'] ? esc_html__( 'editors', 'teatatu-data' ) : esc_html__( 'plugin', 'teatatu-data' ); ?></td>
					<td><?php echo esc_html( $field['description'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

/* ---------------------------------------------------------------------
 * Records
 * ------------------------------------------------------------------- */

/**
 * Renders a data set's record list.
 *
 * @param string $key Data set key.
 */
function teatatu_data_render_records_list( $key ) {
	$dataset = teatatu_data_get_dataset( $key );
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
	$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'active';
	$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
	// phpcs:enable
	$statuses = teatatu_data_lifecycle_statuses();
	if ( 'all' !== $status && ! isset( $statuses[ $status ] ) ) {
		$status = 'active';
	}
	$result = teatatu_data_find(
		$key,
		array(
			'search'   => $search,
			'status'   => $status,
			'page'     => $paged,
			'per_page' => 50,
		)
	);

	// The data set's chosen columns, or the title field plus up to three short fields.
	$columns = $dataset['list_columns'] ? $dataset['list_columns'] : array( $dataset['title_field'] );
	foreach ( $dataset['list_columns'] ? array() : $dataset['fields'] as $field_key => $field ) {
		if ( count( $columns ) >= 4 ) {
			break;
		}
		if ( $field_key !== $dataset['title_field'] && 'text' !== $field['type'] ) {
			$columns[] = $field_key;
		}
	}
	$stale_before = gmdate( 'Y-m-d', strtotime( '-' . teatatu_data_dataset_settings( $key )['review_interval'] . ' months' ) );
	?>
	<p><?php echo esc_html( $dataset['description'] ); ?></p>
	<?php
	/**
	 * Fires above a data set's record list, for data-set-specific tools.
	 *
	 * @param string $key Data set key.
	 */
	do_action( 'teatatu_data_records_list_before', $key );
	?>
	<form method="get" style="margin:12px 0;">
		<input type="hidden" name="page" value="teatatu-data" />
		<input type="hidden" name="tab" value="records" />
		<input type="hidden" name="dataset" value="<?php echo esc_attr( $key ); ?>" />
		<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search names', 'teatatu-data' ); ?>" />
		<select name="status">
			<?php foreach ( $statuses as $status_key => $definition ) : ?>
				<option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( $status, $status_key ); ?>><?php echo esc_html( $definition['label'] ); ?></option>
			<?php endforeach; ?>
			<option value="all" <?php selected( $status, 'all' ); ?>><?php esc_html_e( 'All statuses', 'teatatu-data' ); ?></option>
		</select>
		<?php submit_button( __( 'Filter', 'teatatu-data' ), 'secondary', '', false ); ?>
		<?php if ( teatatu_data_admin_can_write() ) : ?>
			<a class="button button-primary" href="<?php echo esc_url( teatatu_data_admin_url( array( 'tab' => 'records', 'dataset' => $key, 'action' => 'new' ) ) ); ?>"><?php esc_html_e( 'Add new', 'teatatu-data' ); ?></a>
		<?php endif; ?>
	</form>

	<table class="widefat striped">
		<thead>
			<tr>
				<?php foreach ( $columns as $column ) : ?>
					<th><?php echo esc_html( teatatu_data_field_label( $dataset, $column ) ); ?></th>
				<?php endforeach; ?>
				<th><?php esc_html_e( 'Status', 'teatatu-data' ); ?></th>
				<th><?php esc_html_e( 'Verified', 'teatatu-data' ); ?></th>
				<th><?php esc_html_e( 'Updated', 'teatatu-data' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( ! $result['records'] ) : ?>
				<tr><td colspan="<?php echo count( $columns ) + 3; ?>"><?php esc_html_e( 'No records found.', 'teatatu-data' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $result['records'] as $record ) : ?>
				<tr>
					<?php foreach ( $columns as $index => $column ) : ?>
						<td>
							<?php if ( 0 === $index ) : ?>
								<strong><a href="<?php echo esc_url( teatatu_data_admin_url( array( 'tab' => 'records', 'dataset' => $key, 'action' => 'edit', 'uuid' => $record['uuid'] ) ) ); ?>"><?php echo esc_html( teatatu_data_display_value( $dataset, $column, $record[ $column ] ) ); ?></a></strong>
							<?php else : ?>
								<?php echo esc_html( teatatu_data_display_value( $dataset, $column, $record[ $column ] ) ); ?>
							<?php endif; ?>
						</td>
					<?php endforeach; ?>
					<td><?php echo esc_html( teatatu_data_display_value( $dataset, 'status', $record['status'] ) ); ?></td>
					<td>
						<?php echo esc_html( $record['verified_at'] ? $record['verified_at'] : '—' ); ?>
						<?php if ( 'active' === $record['status'] && ( ! $record['verified_at'] || $record['verified_at'] < $stale_before ) ) : ?>
							<span class="dashicons dashicons-warning" title="<?php esc_attr_e( 'Due for review', 'teatatu-data' ); ?>"></span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), get_date_from_gmt( gmdate( 'Y-m-d H:i:s', strtotime( $record['updated_at'] ) ) ) ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
	$pages = (int) ceil( $result['total'] / 50 );
	if ( $pages > 1 ) {
		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo wp_kses_post(
			paginate_links(
				array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => $paged,
					'total'   => $pages,
				)
			)
		);
		echo '</div></div>';
	}
}

/**
 * Renders the create/edit screen for one record, with its history.
 *
 * @param string $key Data set key.
 */
function teatatu_data_render_record_screen( $key ) {
	$dataset = teatatu_data_get_dataset( $key );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
	$uuid   = isset( $_GET['uuid'] ) ? strtolower( sanitize_text_field( wp_unslash( $_GET['uuid'] ) ) ) : '';
	$record = $uuid ? teatatu_data_get( $key, $uuid ) : null;
	if ( $uuid && ! $record ) {
		echo '<p>' . esc_html__( 'No such record.', 'teatatu-data' ) . '</p>';
		return;
	}

	$writable = teatatu_data_admin_can_write();
	$applies  = $writable && current_user_can( 'edit_teatatu_data' );
	$stash    = get_transient( 'teatatu_data_form_' . get_current_user_id() );
	$errors   = array();
	$values   = $record ? $record : array( 'status' => 'active' );
	if ( is_array( $stash ) && $stash['dataset'] === $key && $stash['uuid'] === $uuid ) {
		delete_transient( 'teatatu_data_form_' . get_current_user_id() );
		$errors = $stash['errors'];
		$values = array_merge( $values, $stash['values'] );
		echo '<div class="notice notice-error"><p>' . esc_html( $stash['message'] ) . '</p></div>';
	}
	$back = teatatu_data_admin_url( array( 'tab' => 'records', 'dataset' => $key ) );
	?>
	<h2>
		<?php echo esc_html( $record ? teatatu_data_record_title( $key, $record ) : sprintf( /* translators: %s: data set name */ __( 'New record: %s', 'teatatu-data' ), $dataset['label'] ) ); ?>
		<a class="page-title-action" href="<?php echo esc_url( $back ); ?>"><?php esc_html_e( 'Back to list', 'teatatu-data' ); ?></a>
	</h2>
	<?php if ( $record ) : ?>
		<p><code><?php echo esc_html( $record['uuid'] ); ?></code></p>
	<?php endif; ?>
	<?php if ( $writable && ! $applies ) : ?>
		<div class="notice notice-info inline"><p><?php esc_html_e( 'Your changes are sent as a proposal for a steward to approve.', 'teatatu-data' ); ?></p></div>
	<?php endif; ?>

	<?php
	/**
	 * Fires above a record's edit form, for data-set-specific helpers.
	 *
	 * @param string     $key    Data set key.
	 * @param array|null $record The record, or null when adding one.
	 * @param bool       $writable Whether the form can be submitted.
	 */
	do_action( 'teatatu_data_record_form_before', $key, $record, $writable );
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'teatatu_data_save_record' ); ?>
		<input type="hidden" name="action" value="teatatu_data_save_record" />
		<input type="hidden" name="dataset" value="<?php echo esc_attr( $key ); ?>" />
		<input type="hidden" name="uuid" value="<?php echo esc_attr( $uuid ); ?>" />
		<fieldset <?php disabled( ! $writable ); ?>>
		<table class="form-table">
			<?php foreach ( $dataset['fields'] as $field_key => $field ) : ?>
				<tr>
					<th scope="row">
						<label for="ttd_f_<?php echo esc_attr( $field_key ); ?>"><?php echo esc_html( $field['label'] ); ?><?php echo $field['required'] ? ' <span class="required">*</span>' : ''; ?></label>
					</th>
					<td>
						<?php if ( $field['derived'] ) : ?>
							<?php echo esc_html( teatatu_data_display_value( $dataset, $field_key, isset( $values[ $field_key ] ) ? $values[ $field_key ] : null ) ); ?>
							<span class="description"><?php esc_html_e( '(worked out by the plugin)', 'teatatu-data' ); ?></span>
						<?php else : ?>
						<input type="hidden" name="f_present[<?php echo esc_attr( $field_key ); ?>]" value="1" />
						<?php teatatu_data_render_field_input( $field, isset( $values[ $field_key ] ) ? $values[ $field_key ] : null ); ?>
						<?php endif; ?>
						<?php if ( isset( $errors[ $field_key ] ) ) : ?>
							<p class="description" style="color:#b32d2e;"><?php echo esc_html( $errors[ $field_key ] ); ?></p>
						<?php endif; ?>
						<?php if ( $field['description'] ) : ?>
							<p class="description"><?php echo esc_html( $field['description'] ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>

		<h3><?php esc_html_e( 'Record management', 'teatatu-data' ); ?></h3>
		<table class="form-table">
			<tr>
				<th scope="row"><label for="ttd_s_status"><?php esc_html_e( 'Status', 'teatatu-data' ); ?></label></th>
				<td>
					<select id="ttd_s_status" name="s[status]">
						<?php foreach ( teatatu_data_lifecycle_statuses() as $status_key => $definition ) : ?>
							<option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( isset( $values['status'] ) ? $values['status'] : 'active', $status_key ); ?>><?php echo esc_html( $definition['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php if ( isset( $errors['status'] ) ) : ?>
						<p class="description" style="color:#b32d2e;"><?php echo esc_html( $errors['status'] ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ttd_s_replaced_by"><?php esc_html_e( 'Replaced by', 'teatatu-data' ); ?></label></th>
				<td>
					<?php
					teatatu_data_render_field_input(
						teatatu_data_normalize_field(
							'replaced_by',
							array(
								'type'       => 'reference',
								'references' => $key,
							)
						),
						isset( $values['replaced_by'] ) ? $values['replaced_by'] : null,
						's[replaced_by]',
						'ttd_s_replaced_by',
						$uuid
					);
					?>
					<?php if ( isset( $errors['replaced_by'] ) ) : ?>
						<p class="description" style="color:#b32d2e;"><?php echo esc_html( $errors['replaced_by'] ); ?></p>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'For a deprecated or merged record: the record to use instead.', 'teatatu-data' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ttd_s_external_ids"><?php esc_html_e( 'External IDs', 'teatatu-data' ); ?></label></th>
				<td>
					<?php
					$lines = array();
					foreach ( isset( $values['external_ids'] ) ? (array) $values['external_ids'] : array() as $system => $id ) {
						$lines[] = $system . '=' . $id;
					}
					?>
					<textarea id="ttd_s_external_ids" name="s[external_ids]" rows="3" class="regular-text code"><?php echo esc_textarea( implode( "\n", $lines ) ); ?></textarea>
					<?php if ( isset( $errors['external_ids'] ) ) : ?>
						<p class="description" style="color:#b32d2e;"><?php echo esc_html( $errors['external_ids'] ); ?></p>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'One per line, system=ID, e.g. osm=node/123 or nzbn=9429041234567.', 'teatatu-data' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ttd_s_source"><?php esc_html_e( 'Source', 'teatatu-data' ); ?></label></th>
				<td><input type="text" id="ttd_s_source" name="s[source]" class="regular-text" value="<?php echo esc_attr( isset( $values['source'] ) ? $values['source'] : '' ); ?>" />
				<p class="description"><?php esc_html_e( 'Where this record came from.', 'teatatu-data' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><label for="ttd_s_verified_at"><?php esc_html_e( 'Verified', 'teatatu-data' ); ?></label></th>
				<td>
					<input type="date" id="ttd_s_verified_at" name="s[verified_at]" value="<?php echo esc_attr( isset( $values['verified_at'] ) ? $values['verified_at'] : '' ); ?>" />
					<label style="margin-left:8px;"><input type="checkbox" name="s[verify_today]" value="1" /> <?php esc_html_e( 'I have checked this record is correct today', 'teatatu-data' ); ?></label>
					<?php if ( isset( $errors['verified_at'] ) ) : ?>
						<p class="description" style="color:#b32d2e;"><?php echo esc_html( $errors['verified_at'] ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ttd_reason"><?php esc_html_e( 'Reason for change', 'teatatu-data' ); ?></label></th>
				<td><textarea id="ttd_reason" name="reason" rows="2" class="large-text"></textarea>
				<p class="description"><?php esc_html_e( 'Recorded in the change history.', 'teatatu-data' ); ?></p></td>
			</tr>
		</table>
		</fieldset>
		<?php
		if ( $writable ) {
			submit_button( $applies ? __( 'Save', 'teatatu-data' ) : __( 'Propose change', 'teatatu-data' ) );
		}
		?>
	</form>

	<?php if ( $record ) : ?>
		<?php
		$history = teatatu_data_get_changes(
			array(
				'dataset'  => $key,
				'record'   => $uuid,
				'per_page' => 100,
			)
		);
		?>
		<h2><?php esc_html_e( 'History', 'teatatu-data' ); ?></h2>
		<p class="description">
			<?php
			/* translators: 1: created date, 2: updated date */
			echo esc_html( sprintf( __( 'Created %1$s · last updated %2$s (UTC).', 'teatatu-data' ), $record['created_at'], $record['updated_at'] ) );
			?>
		</p>
		<?php
		foreach ( $history['changes'] as $change ) {
			teatatu_data_render_change( $change, $dataset );
		}
		?>
	<?php endif; ?>
	<?php
}

/**
 * Renders a form input for a field.
 *
 * @param array       $field   Field definition.
 * @param mixed       $value   Current value.
 * @param string|null $name    Input name (default f[<key>]).
 * @param string|null $id      Input ID (default ttd_f_<key>).
 * @param string      $exclude UUID to leave out of reference choices.
 */
function teatatu_data_render_field_input( $field, $value, $name = null, $id = null, $exclude = '' ) {
	$name = $name ? $name : 'f[' . $field['key'] . ']';
	$id   = $id ? $id : 'ttd_f_' . $field['key'];
	$text = is_scalar( $value ) && ! is_bool( $value ) ? (string) $value : '';

	switch ( $field['type'] ) {
		case 'text':
			printf( '<textarea id="%1$s" name="%2$s" rows="4" class="large-text">%3$s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( $text ) );
			return;

		case 'boolean':
			printf( '<input type="hidden" name="%1$s" value="0" /><label><input type="checkbox" id="%2$s" name="%1$s" value="1" %3$s /> %4$s</label>', esc_attr( $name ), esc_attr( $id ), checked( (bool) $value, true, false ), esc_html__( 'Yes', 'teatatu-data' ) );
			return;

		case 'enum':
			printf( '<select id="%1$s" name="%2$s"><option value="">—</option>', esc_attr( $id ), esc_attr( $name ) );
			foreach ( $field['enum'] as $option => $label ) {
				printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $option ), selected( $text, (string) $option, false ), esc_html( $label ) );
			}
			echo '</select>';
			return;

		case 'reference':
			$choices  = teatatu_data_find(
				$field['references'],
				array(
					'status'   => array( 'proposed', 'active', 'deprecated' ),
					'per_page' => 500,
				)
			);
			$selected = array_map( 'strval', (array) $value );
			$multiple = $field['multiple'];
			printf( '<select id="%1$s" name="%2$s" %3$s>', esc_attr( $id ), esc_attr( $name . ( $multiple ? '[]' : '' ) ), $multiple ? 'multiple size="6" style="min-width:300px;"' : '' );
			if ( ! $multiple ) {
				echo '<option value="">—</option>';
			}
			$listed = array();
			foreach ( $choices['records'] as $choice ) {
				if ( $choice['uuid'] === $exclude ) {
					continue;
				}
				$listed[] = $choice['uuid'];
				printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $choice['uuid'] ), selected( in_array( $choice['uuid'], $selected, true ), true, false ), esc_html( teatatu_data_record_title( $field['references'], $choice ) ) );
			}
			// Keep a current value that is no longer offered (e.g. retired).
			foreach ( array_diff( array_filter( $selected ), $listed ) as $uuid ) {
				$record = teatatu_data_get( $field['references'], $uuid );
				printf( '<option value="%1$s" selected>%2$s</option>', esc_attr( $uuid ), esc_html( ( $record ? teatatu_data_record_title( $field['references'], $record ) : $uuid ) . ' ' . __( '(not available)', 'teatatu-data' ) ) );
			}
			echo '</select>';
			return;

		case 'geo':
			$lat = is_array( $value ) && isset( $value['lat'] ) ? $value['lat'] : '';
			$lng = is_array( $value ) && isset( $value['lng'] ) ? $value['lng'] : '';
			printf(
				'<input type="text" inputmode="decimal" id="%1$s" name="%2$s[lat]" value="%3$s" placeholder="%5$s" class="regular-text" style="width:12em;" /> <input type="text" inputmode="decimal" name="%2$s[lng]" value="%4$s" placeholder="%6$s" class="regular-text" style="width:12em;" />',
				esc_attr( $id ),
				esc_attr( $name ),
				esc_attr( (string) $lat ),
				esc_attr( (string) $lng ),
				esc_attr__( 'Latitude', 'teatatu-data' ),
				esc_attr__( 'Longitude', 'teatatu-data' )
			);
			return;
	}

	$types = array(
		'url'     => 'url',
		'email'   => 'email',
		'phone'   => 'tel',
		'date'    => 'date',
		'integer' => 'number',
		'number'  => 'number',
	);
	$type  = isset( $types[ $field['type'] ] ) ? $types[ $field['type'] ] : 'text';
	$extra = 'number' === $field['type'] ? ' step="any"' : '';
	printf(
		'<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" class="regular-text"%5$s%6$s />',
		esc_attr( $type ),
		esc_attr( $id ),
		esc_attr( $name ),
		esc_attr( $text ),
		$extra, // phpcs:ignore WordPress.Security.EscapeOutput -- fixed string.
		$field['example'] ? ' placeholder="' . esc_attr( $field['example'] ) . '"' : ''
	);
}

/**
 * Renders one change: header, reason, field table and actions.
 *
 * @param array $change  Change.
 * @param array $dataset Data set definition.
 */
function teatatu_data_render_change( $change, $dataset ) {
	$states   = array(
		'pending'  => __( 'Pending', 'teatatu-data' ),
		'applied'  => __( 'Applied', 'teatatu-data' ),
		'rejected' => __( 'Rejected', 'teatatu-data' ),
	);
	$colours  = array(
		'pending'  => '#dba617',
		'applied'  => '#00a32a',
		'rejected' => '#b32d2e',
	);
	$is_pending = 'pending' === $change['state'];
	$current    = ( 'update' === $change['operation'] && $change['record'] ) ? teatatu_data_get( $change['dataset'], $change['record'] ) : null;
	$rows       = $is_pending ? $change['values'] : $change['diff'];
	$master     = teatatu_data_is_master_site();
	$return     = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	?>
	<div class="card" style="max-width:none;margin:12px 0;padding:8px 16px;">
		<p style="margin:4px 0;">
			<strong style="color:<?php echo esc_attr( $colours[ $change['state'] ] ?? '#50575e' ); ?>;"><?php echo esc_html( $states[ $change['state'] ] ?? $change['state'] ); ?></strong>
			· <?php echo esc_html( 'create' === $change['operation'] ? __( 'New record', 'teatatu-data' ) : __( 'Update', 'teatatu-data' ) ); ?>
			<?php if ( $change['record'] && ( 'update' === $change['operation'] || ! $is_pending ) ) : ?>
				· <a href="<?php echo esc_url( teatatu_data_admin_url( array( 'tab' => 'records', 'dataset' => $change['dataset'], 'action' => 'edit', 'uuid' => $change['record'] ) ) ); ?>"><?php echo esc_html( $dataset['label'] ); ?></a>
			<?php else : ?>
				· <?php echo esc_html( $dataset['label'] ); ?>
			<?php endif; ?>
			· #<?php echo (int) $change['id']; ?>
			· <?php echo esc_html( $change['channel'] ); ?>
			·
			<?php
			/* translators: 1: user name, 2: date */
			echo esc_html( sprintf( __( 'by %1$s on %2$s', 'teatatu-data' ), $change['author_name'] ? $change['author_name'] : __( 'unknown', 'teatatu-data' ), get_date_from_gmt( gmdate( 'Y-m-d H:i:s', strtotime( $change['created_at'] ) ), 'j M Y H:i' ) ) );
			?>
			<?php if ( $change['batch'] ) : ?>
				· <a href="<?php echo esc_url( teatatu_data_admin_url( array( 'tab' => 'proposals', 'batch' => $change['batch'] ) ) ); ?>"><?php echo esc_html( $change['batch'] ); ?></a>
			<?php endif; ?>
		</p>
		<?php if ( $change['reason'] ) : ?>
			<p style="margin:4px 0;"><em><?php echo esc_html( $change['reason'] ); ?></em></p>
		<?php endif; ?>
		<?php if ( ! $is_pending && $change['decided_by_name'] && $change['decided_by'] !== $change['author_id'] ) : ?>
			<p style="margin:4px 0;">
				<?php
				/* translators: 1: state, 2: user, 3: date */
				echo esc_html( sprintf( __( '%1$s by %2$s on %3$s', 'teatatu-data' ), $states[ $change['state'] ] ?? '', $change['decided_by_name'], $change['decided_at'] ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', strtotime( $change['decided_at'] ) ), 'j M Y H:i' ) : '' ) );
				echo $change['note'] ? esc_html( ': ' . $change['note'] ) : '';
				?>
			</p>
		<?php endif; ?>

		<?php if ( $rows ) : ?>
			<table class="widefat striped" style="margin:8px 0;">
				<thead>
					<tr>
						<th style="width:20%;"><?php esc_html_e( 'Field', 'teatatu-data' ); ?></th>
						<th><?php echo $is_pending ? esc_html__( 'Now', 'teatatu-data' ) : esc_html__( 'Before', 'teatatu-data' ); ?></th>
						<th><?php echo $is_pending ? esc_html__( 'Proposed', 'teatatu-data' ) : esc_html__( 'After', 'teatatu-data' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $field_key => $row ) : ?>
						<?php
						if ( $is_pending ) {
							$before   = $current && array_key_exists( $field_key, $current ) ? $current[ $field_key ] : null;
							$after    = $row;
							$conflict = $current && array_key_exists( $field_key, $change['base'] ) && ! teatatu_data_values_equal( $change['base'][ $field_key ], $before );
						} else {
							$before   = $row['before'];
							$after    = $row['after'];
							$conflict = false;
						}
						?>
						<tr>
							<td><?php echo esc_html( teatatu_data_field_label( $dataset, $field_key ) ); ?></td>
							<td>
								<?php echo esc_html( teatatu_data_display_value( $dataset, $field_key, $before ) ); ?>
								<?php if ( $conflict ) : ?>
									<br /><small style="color:#b32d2e;"><?php esc_html_e( 'Changed since this was proposed.', 'teatatu-data' ); ?></small>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( teatatu_data_display_value( $dataset, $field_key, $after ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( $master && $is_pending && current_user_can( 'approve_teatatu_data' ) ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:8px 0;">
				<?php wp_nonce_field( 'teatatu_data_decide_change' ); ?>
				<input type="hidden" name="action" value="teatatu_data_decide_change" />
				<input type="hidden" name="change_id" value="<?php echo (int) $change['id']; ?>" />
				<input type="hidden" name="return" value="<?php echo esc_attr( $return ); ?>" />
				<input type="text" name="note" class="regular-text" placeholder="<?php esc_attr_e( 'Note (optional)', 'teatatu-data' ); ?>" />
				<button type="submit" name="decision" value="approve" class="button button-primary"><?php esc_html_e( 'Approve', 'teatatu-data' ); ?></button>
				<button type="submit" name="decision" value="reject" class="button"><?php esc_html_e( 'Reject', 'teatatu-data' ); ?></button>
			</form>
		<?php elseif ( $master && 'applied' === $change['state'] && 'update' === $change['operation'] && $change['diff'] && current_user_can( 'edit_teatatu_data' ) ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:8px 0;">
				<?php wp_nonce_field( 'teatatu_data_decide_change' ); ?>
				<input type="hidden" name="action" value="teatatu_data_decide_change" />
				<input type="hidden" name="change_id" value="<?php echo (int) $change['id']; ?>" />
				<input type="hidden" name="return" value="<?php echo esc_attr( $return ); ?>" />
				<button type="submit" name="decision" value="revert" class="button button-small"><?php esc_html_e( 'Revert this change', 'teatatu-data' ); ?></button>
			</form>
		<?php endif; ?>
	</div>
	<?php
}

/* ---------------------------------------------------------------------
 * Proposed changes
 * ------------------------------------------------------------------- */

/**
 * Renders the pending changes, optionally filtered by data set or batch.
 */
function teatatu_data_render_proposals_tab() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
	$filter_key = isset( $_GET['filter_dataset'] ) ? sanitize_key( wp_unslash( $_GET['filter_dataset'] ) ) : '';
	$batch      = isset( $_GET['batch'] ) ? sanitize_text_field( wp_unslash( $_GET['batch'] ) ) : '';
	// phpcs:enable
	$sees_all = current_user_can( 'approve_teatatu_data' );
	$result   = teatatu_data_get_changes(
		array(
			'state'    => 'pending',
			'dataset'  => $filter_key,
			'batch'    => $batch,
			'author'   => $sees_all ? 0 : get_current_user_id(),
			'per_page' => 100,
		)
	);
	?>
	<p><?php echo $sees_all ? esc_html__( 'Changes from agents, imports and contributors wait here for a steward to approve or reject.', 'teatatu-data' ) : esc_html__( 'Your proposed changes waiting for a steward.', 'teatatu-data' ); ?></p>
	<form method="get" style="margin:12px 0;">
		<input type="hidden" name="page" value="teatatu-data" />
		<input type="hidden" name="tab" value="proposals" />
		<select name="filter_dataset">
			<option value=""><?php esc_html_e( 'All data sets', 'teatatu-data' ); ?></option>
			<?php foreach ( teatatu_data_get_record_datasets() as $dataset_key => $dataset ) : ?>
				<option value="<?php echo esc_attr( $dataset_key ); ?>" <?php selected( $filter_key, $dataset_key ); ?>><?php echo esc_html( $dataset['label'] ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php if ( $batch ) : ?>
			<input type="hidden" name="batch" value="<?php echo esc_attr( $batch ); ?>" />
		<?php endif; ?>
		<?php submit_button( __( 'Filter', 'teatatu-data' ), 'secondary', '', false ); ?>
	</form>

	<?php if ( $batch ) : ?>
		<p>
			<?php
			/* translators: 1: batch ID, 2: number of pending changes */
			echo esc_html( sprintf( __( 'Showing import batch %1$s: %2$d pending.', 'teatatu-data' ), $batch, $result['total'] ) );
			?>
			<a href="<?php echo esc_url( teatatu_data_admin_url( array( 'tab' => 'proposals' ) ) ); ?>"><?php esc_html_e( 'Show all', 'teatatu-data' ); ?></a>
		</p>
		<?php if ( $result['total'] && $sees_all && teatatu_data_is_master_site() ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'teatatu_data_approve_batch' ); ?>
				<input type="hidden" name="action" value="teatatu_data_approve_batch" />
				<input type="hidden" name="batch" value="<?php echo esc_attr( $batch ); ?>" />
				<?php submit_button( __( 'Approve every change in this batch', 'teatatu-data' ), 'primary', 'submit', false ); ?>
			</form>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( ! $result['changes'] ) : ?>
		<p><em><?php esc_html_e( 'Nothing is waiting for review.', 'teatatu-data' ); ?></em></p>
	<?php endif; ?>
	<?php
	foreach ( $result['changes'] as $change ) {
		$dataset = teatatu_data_get_dataset( $change['dataset'] );
		if ( $dataset ) {
			teatatu_data_render_change( $change, $dataset );
		}
	}
}

/* ---------------------------------------------------------------------
 * Import / Export
 * ------------------------------------------------------------------- */

/**
 * Renders the import and export forms and the last import's result.
 */
function teatatu_data_render_transfer_tab() {
	$datasets = teatatu_data_get_record_datasets();
	$result   = get_transient( 'teatatu_data_import_result_' . get_current_user_id() );
	if ( is_array( $result ) ) {
		delete_transient( 'teatatu_data_import_result_' . get_current_user_id() );
		teatatu_data_render_import_result( $result );
	}
	?>
	<h2><?php esc_html_e( 'Export', 'teatatu-data' ); ?></h2>
	<p><?php esc_html_e( 'Every record of a data set, in every status, with its standard fields. Use it as a backup or to edit in a spreadsheet and import again.', 'teatatu-data' ); ?></p>
	<form method="get" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="teatatu_data_export" />
		<?php wp_nonce_field( 'teatatu_data_export', '_wpnonce', false ); ?>
		<select name="dataset">
			<?php foreach ( $datasets as $key => $dataset ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $dataset['label'] ); ?></option>
			<?php endforeach; ?>
		</select>
		<select name="format">
			<option value="csv">CSV</option>
			<option value="json">JSON</option>
		</select>
		<?php submit_button( __( 'Download', 'teatatu-data' ), 'secondary', '', false ); ?>
	</form>

	<?php if ( current_user_can( 'import_teatatu_data' ) && teatatu_data_is_master_site() ) : ?>
		<h2><?php esc_html_e( 'Import', 'teatatu-data' ); ?></h2>
		<p><?php esc_html_e( 'A CSV or JSON file in the same shape as an export (5 MB maximum). Each row is checked against the data set\'s rules and becomes a proposed change in one batch.', 'teatatu-data' ); ?></p>
		<ul class="ul-disc">
			<li><?php esc_html_e( 'A row with a uuid that exists updates that record; without one it adds a new record.', 'teatatu-data' ); ?></li>
			<li><?php esc_html_e( 'A column you leave out is not changed. An empty cell clears the value.', 'teatatu-data' ); ?></li>
			<li><?php esc_html_e( 'Several values in one cell (e.g. several references) are separated by semicolons. Coordinates are "latitude,longitude". External IDs are JSON.', 'teatatu-data' ); ?></li>
		</ul>
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'teatatu_data_import' ); ?>
			<input type="hidden" name="action" value="teatatu_data_import" />
			<p>
				<select name="dataset">
					<?php foreach ( $datasets as $key => $dataset ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $dataset['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="file" name="import_file" accept=".csv,.json" required />
			</p>
			<?php if ( current_user_can( 'approve_teatatu_data' ) ) : ?>
				<p><label><input type="checkbox" name="apply_now" value="1" /> <?php esc_html_e( 'Apply now (approve the whole batch straight away)', 'teatatu-data' ); ?></label></p>
			<?php endif; ?>
			<?php submit_button( __( 'Import', 'teatatu-data' ), 'primary', 'submit', false ); ?>
		</form>
	<?php endif; ?>
	<?php
}

/**
 * Shows the outcome of the last import.
 *
 * @param array $result Import result.
 */
function teatatu_data_render_import_result( $result ) {
	if ( ! empty( $result['error'] ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html( $result['error'] ) . '</p></div>';
		return;
	}
	$class = $result['errors'] ? 'warning' : 'success';
	echo '<div class="notice notice-' . esc_attr( $class ) . '"><p>';
	/* translators: 1: proposed, 2: unchanged, 3: errors */
	echo esc_html( sprintf( __( 'Import finished: %1$d proposed, %2$d unchanged, %3$d with errors.', 'teatatu-data' ), $result['proposed'], $result['unchanged'], count( $result['errors'] ) ) );
	if ( isset( $result['applied'] ) ) {
		/* translators: 1: applied, 2: failed */
		echo ' ' . esc_html( sprintf( __( '%1$d applied, %2$d could not be applied.', 'teatatu-data' ), $result['applied'], count( $result['failed'] ) ) );
	}
	if ( $result['proposed'] ) {
		echo ' <a href="' . esc_url( teatatu_data_admin_url( array( 'tab' => 'proposals', 'batch' => $result['batch'] ) ) ) . '">' . esc_html__( 'Review the batch', 'teatatu-data' ) . '</a>';
	}
	echo '</p>';
	if ( $result['errors'] ) {
		echo '<ul class="ul-disc">';
		foreach ( $result['errors'] as $row => $message ) {
			/* translators: 1: row number, 2: message */
			echo '<li>' . esc_html( sprintf( __( 'Row %1$d: %2$s', 'teatatu-data' ), $row, $message ) ) . '</li>';
		}
		echo '</ul>';
	}
	if ( ! empty( $result['failed'] ) ) {
		echo '<ul class="ul-disc">';
		foreach ( $result['failed'] as $change_id => $message ) {
			/* translators: 1: change ID, 2: message */
			echo '<li>' . esc_html( sprintf( __( 'Change #%1$d: %2$s', 'teatatu-data' ), $change_id, $message ) ) . '</li>';
		}
		echo '</ul>';
	}
	echo '</div>';
}

/* ---------------------------------------------------------------------
 * Data set settings (governance)
 * ------------------------------------------------------------------- */

/**
 * Renders the owner / steward / review interval settings.
 */
function teatatu_data_render_governance_tab() {
	$master = teatatu_data_is_master_site();
	?>
	<p><?php esc_html_e( 'Name the person accountable for each data set (owner) and the person who looks after it day to day (steward), and how often records must be re-checked.', 'teatatu-data' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'teatatu_data_save_governance' ); ?>
		<input type="hidden" name="action" value="teatatu_data_save_governance" />
		<fieldset <?php disabled( ! $master ); ?>>
		<table class="widefat striped" style="max-width:1000px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Data set', 'teatatu-data' ); ?></th>
					<th><?php esc_html_e( 'Owner', 'teatatu-data' ); ?></th>
					<th><?php esc_html_e( 'Steward', 'teatatu-data' ); ?></th>
					<th><?php esc_html_e( 'Review every (months)', 'teatatu-data' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( teatatu_data_get_record_datasets() as $key => $dataset ) : ?>
					<?php $settings = teatatu_data_dataset_settings( $key ); ?>
					<tr>
						<td><strong><?php echo esc_html( $dataset['label'] ); ?></strong></td>
						<?php foreach ( array( 'owner', 'steward' ) as $role ) : ?>
							<td>
								<?php
								wp_dropdown_users(
									array(
										'name'              => 'people[' . $key . '][' . $role . '_id]',
										'selected'          => $settings[ $role . '_id' ],
										'show_option_none'  => $dataset[ $role ] ? sprintf( /* translators: %s: default role */ __( '— Not chosen (%s) —', 'teatatu-data' ), $dataset[ $role ] ) : __( '— Not chosen —', 'teatatu-data' ),
										'option_none_value' => 0,
									)
								);
								?>
							</td>
						<?php endforeach; ?>
						<td><input type="number" min="1" max="120" name="people[<?php echo esc_attr( $key ); ?>][review_interval]" value="<?php echo esc_attr( $settings['review_interval'] ); ?>" class="small-text" /></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</fieldset>
		<?php
		if ( $master ) {
			submit_button( __( 'Save data set settings', 'teatatu-data' ) );
		}
		?>
	</form>
	<?php
}

/* ---------------------------------------------------------------------
 * Form handlers
 * ------------------------------------------------------------------- */

/**
 * Stops unless the request is on the Master Site.
 */
function teatatu_data_admin_require_master() {
	if ( ! teatatu_data_is_master_site() ) {
		wp_die( esc_html__( 'Master data can only be changed on the Master Site.', 'teatatu-data' ), 409 );
	}
}

/**
 * Redirects with an error message.
 *
 * @param string $message Message.
 * @param string $url     Where to go.
 */
function teatatu_data_admin_fail( $message, $url ) {
	set_transient( 'teatatu_data_error_' . get_current_user_id(), $message, 5 * MINUTE_IN_SECONDS );
	wp_safe_redirect( add_query_arg( 'ttd_notice', 'error', $url ) );
	exit;
}

/**
 * The "return" URL posted by a form, if it is a safe admin URL.
 *
 * @return string
 */
function teatatu_data_admin_return_url() {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers verify the nonce first.
	$return = isset( $_POST['return'] ) ? esc_url_raw( wp_unslash( $_POST['return'] ) ) : '';
	$return = remove_query_arg( 'ttd_notice', wp_validate_redirect( $return, '' ) );
	return $return ? $return : teatatu_data_admin_url( array( 'tab' => 'proposals' ) );
}

/**
 * Builds the values array from the record form.
 *
 * @param array $dataset Data set definition.
 * @return array Raw values (the validator cleans them).
 */
function teatatu_data_values_from_form( $dataset ) {
	// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- nonce checked by the caller; the validator sanitises every value.
	$posted  = isset( $_POST['f'] ) ? wp_unslash( (array) $_POST['f'] ) : array();
	$present = isset( $_POST['f_present'] ) ? (array) $_POST['f_present'] : array();
	$s       = isset( $_POST['s'] ) ? wp_unslash( (array) $_POST['s'] ) : array();
	// phpcs:enable

	$values = array();
	foreach ( $dataset['fields'] as $field_key => $field ) {
		if ( isset( $posted[ $field_key ] ) ) {
			$values[ $field_key ] = $posted[ $field_key ];
		} elseif ( isset( $present[ $field_key ] ) ) {
			$values[ $field_key ] = null; // e.g. a multiple select with nothing chosen.
		}
	}

	$values['status']      = isset( $s['status'] ) ? $s['status'] : 'active';
	$values['replaced_by'] = isset( $s['replaced_by'] ) ? $s['replaced_by'] : '';
	$values['source']      = isset( $s['source'] ) ? $s['source'] : '';
	$values['verified_at'] = ! empty( $s['verify_today'] ) ? current_time( 'Y-m-d' ) : ( isset( $s['verified_at'] ) ? $s['verified_at'] : '' );

	$ids = array();
	foreach ( preg_split( '/\R/', isset( $s['external_ids'] ) ? (string) $s['external_ids'] : '' ) as $line ) {
		$parts = array_map( 'trim', explode( '=', $line, 2 ) );
		if ( 2 === count( $parts ) && '' !== $parts[0] ) {
			$ids[ $parts[0] ] = $parts[1];
		}
	}
	$values['external_ids'] = $ids;
	return $values;
}

add_action( 'admin_post_teatatu_data_save_record', 'teatatu_data_handle_save_record' );

/**
 * Saves (stewards) or proposes (contributors and agents) a record from the form.
 */
function teatatu_data_handle_save_record() {
	check_admin_referer( 'teatatu_data_save_record' );
	if ( ! current_user_can( 'propose_teatatu_data' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-data' ), 403 );
	}
	teatatu_data_admin_require_master();

	$key     = isset( $_POST['dataset'] ) ? sanitize_key( wp_unslash( $_POST['dataset'] ) ) : '';
	$uuid    = isset( $_POST['uuid'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['uuid'] ) ) ) : '';
	$reason  = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
	$dataset = teatatu_data_get_dataset( $key );
	if ( ! $dataset || 'metadata' === $dataset['kind'] ) {
		wp_die( esc_html__( 'Unknown data set.', 'teatatu-data' ), 404 );
	}
	if ( '' !== $uuid && ! teatatu_data_get( $key, $uuid ) ) {
		wp_die( esc_html__( 'No such record.', 'teatatu-data' ), 404 );
	}

	$values  = teatatu_data_values_from_form( $dataset );
	$form    = teatatu_data_admin_url( array( 'tab' => 'records', 'dataset' => $key, 'action' => $uuid ? 'edit' : 'new', 'uuid' => $uuid ? $uuid : null ) );
	$applies = current_user_can( 'edit_teatatu_data' );

	if ( $applies ) {
		$result = teatatu_data_apply(
			$key,
			$uuid ? $uuid : null,
			$values,
			array(
				'channel' => 'admin',
				'reason'  => $reason,
			)
		);
	} else {
		$result = teatatu_data_propose(
			$key,
			$uuid ? $uuid : null,
			$values,
			array(
				'channel' => 'admin',
				'reason'  => $reason,
			)
		);
	}

	if ( is_wp_error( $result ) ) {
		if ( 'teatatu_data_no_change' === $result->get_error_code() ) {
			wp_safe_redirect( add_query_arg( 'ttd_notice', 'unchanged', $form ) );
			exit;
		}
		$data = $result->get_error_data();
		set_transient(
			'teatatu_data_form_' . get_current_user_id(),
			array(
				'dataset' => $key,
				'uuid'    => $uuid,
				'message' => $result->get_error_message(),
				'errors'  => isset( $data['fields'] ) ? $data['fields'] : array(),
				'values'  => $values,
			),
			5 * MINUTE_IN_SECONDS
		);
		wp_safe_redirect( $form );
		exit;
	}

	if ( $applies ) {
		$target = teatatu_data_admin_url( array( 'tab' => 'records', 'dataset' => $key, 'action' => 'edit', 'uuid' => $result['uuid'] ) );
		wp_safe_redirect( add_query_arg( 'ttd_notice', $result['changed'] ? 'saved' : 'unchanged', $target ) );
	} else {
		wp_safe_redirect( add_query_arg( 'ttd_notice', 'proposed', teatatu_data_admin_url( array( 'tab' => 'proposals' ) ) ) );
	}
	exit;
}

add_action( 'admin_post_teatatu_data_decide_change', 'teatatu_data_handle_decide_change' );

/**
 * Approves, rejects or reverts a change.
 */
function teatatu_data_handle_decide_change() {
	check_admin_referer( 'teatatu_data_decide_change' );
	teatatu_data_admin_require_master();
	$change_id = isset( $_POST['change_id'] ) ? absint( $_POST['change_id'] ) : 0;
	$decision  = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
	$note      = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
	$return    = teatatu_data_admin_return_url();

	if ( 'revert' === $decision ) {
		if ( ! current_user_can( 'edit_teatatu_data' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-data' ), 403 );
		}
		$result = teatatu_data_revert_change( $change_id );
		$notice = 'reverted';
	} elseif ( in_array( $decision, array( 'approve', 'reject' ), true ) ) {
		if ( ! current_user_can( 'approve_teatatu_data' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-data' ), 403 );
		}
		$result = 'approve' === $decision ? teatatu_data_approve_change( $change_id, $note ) : teatatu_data_reject_change( $change_id, $note );
		$notice = 'approve' === $decision ? 'approved' : 'rejected';
	} else {
		wp_die( esc_html__( 'Unknown action.', 'teatatu-data' ), 400 );
	}

	if ( is_wp_error( $result ) ) {
		$message = $result->get_error_message();
		$data    = $result->get_error_data();
		if ( ! empty( $data['fields'] ) ) {
			foreach ( $data['fields'] as $field => $field_message ) {
				$message .= ' ' . $field . ': ' . $field_message;
			}
		}
		teatatu_data_admin_fail( $message, $return );
	}
	wp_safe_redirect( add_query_arg( 'ttd_notice', $notice, $return ) );
	exit;
}

add_action( 'admin_post_teatatu_data_approve_batch', 'teatatu_data_handle_approve_batch' );

/**
 * Approves every pending change in an import batch.
 */
function teatatu_data_handle_approve_batch() {
	check_admin_referer( 'teatatu_data_approve_batch' );
	if ( ! current_user_can( 'approve_teatatu_data' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-data' ), 403 );
	}
	teatatu_data_admin_require_master();
	$batch  = isset( $_POST['batch'] ) ? sanitize_text_field( wp_unslash( $_POST['batch'] ) ) : '';
	$url    = teatatu_data_admin_url( array( 'tab' => 'proposals', 'batch' => $batch ) );
	$result = teatatu_data_approve_batch( $batch );
	if ( $result['failed'] ) {
		$messages = array();
		foreach ( $result['failed'] as $change_id => $message ) {
			$messages[] = '#' . $change_id . ': ' . $message;
		}
		/* translators: 1: applied count, 2: failures */
		teatatu_data_admin_fail( sprintf( __( '%1$d applied. These could not be applied: %2$s', 'teatatu-data' ), $result['applied'], implode( ' ', $messages ) ), $url );
	}
	wp_safe_redirect( add_query_arg( 'ttd_notice', 'batch', $url ) );
	exit;
}

add_action( 'admin_post_teatatu_data_save_governance', 'teatatu_data_handle_save_governance' );

/**
 * Saves the named owner, steward and review interval of each data set.
 */
function teatatu_data_handle_save_governance() {
	check_admin_referer( 'teatatu_data_save_governance' );
	if ( ! current_user_can( 'manage_teatatu_data' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-data' ), 403 );
	}
	teatatu_data_admin_require_master();
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- every value is cast below.
	$posted = isset( $_POST['people'] ) ? wp_unslash( (array) $_POST['people'] ) : array();
	$all    = teatatu_data_get_option( 'teatatu_data_dataset_settings', array() );
	$all    = is_array( $all ) ? $all : array();
	foreach ( teatatu_data_get_record_datasets() as $key => $dataset ) {
		if ( ! isset( $posted[ $key ] ) || ! is_array( $posted[ $key ] ) ) {
			continue;
		}
		$row = $posted[ $key ];
		$ids = array();
		foreach ( array( 'owner_id', 'steward_id' ) as $role ) {
			$user_id      = isset( $row[ $role ] ) ? absint( $row[ $role ] ) : 0;
			$ids[ $role ] = ( $user_id && get_userdata( $user_id ) ) ? $user_id : 0;
		}
		$all[ $key ] = array(
			'owner_id'        => $ids['owner_id'],
			'steward_id'      => $ids['steward_id'],
			'review_interval' => isset( $row['review_interval'] ) ? max( 1, min( 120, absint( $row['review_interval'] ) ) ) : $dataset['review_interval'],
		);
	}
	teatatu_data_update_option( 'teatatu_data_dataset_settings', $all );
	wp_safe_redirect( add_query_arg( 'ttd_notice', 'settings', teatatu_data_admin_url( array( 'tab' => 'governance' ) ) ) );
	exit;
}
