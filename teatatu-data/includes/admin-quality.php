<?php
/**
 * The Data quality tab (scores, open issues and their fixes, dismissed
 * issues) and the side-by-side merge screen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether the current user can act on quality issues here.
 *
 * @return bool
 */
function teatatu_data_quality_can_fix() {
	return teatatu_data_is_master_site() && current_user_can( 'edit_teatatu_data' );
}

/**
 * Finds an open issue by ID.
 *
 * @param string $issue_id Issue ID.
 * @return array|null
 */
function teatatu_data_quality_find_issue( $issue_id ) {
	foreach ( teatatu_data_quality_results() as $result ) {
		foreach ( $result['issues'] as $issue ) {
			if ( $issue['id'] === $issue_id ) {
				return $issue;
			}
		}
	}
	return null;
}

/**
 * Renders the Data quality tab.
 */
function teatatu_data_render_quality_tab() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
	$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
	if ( 'merge' === $action ) {
		teatatu_data_render_merge_screen();
		return;
	}
	$filter_key = isset( $_GET['filter_dataset'] ) ? sanitize_key( wp_unslash( $_GET['filter_dataset'] ) ) : '';
	$filter_dim = isset( $_GET['dimension'] ) ? sanitize_key( wp_unslash( $_GET['dimension'] ) ) : '';
	$show       = isset( $_GET['show'] ) ? sanitize_key( wp_unslash( $_GET['show'] ) ) : 'open';
	// phpcs:enable
	$results  = teatatu_data_quality_results();
	$datasets = teatatu_data_get_record_datasets();
	$dims     = teatatu_data_quality_dimensions();
	$can_fix  = teatatu_data_quality_can_fix();
	$return   = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	$score    = function ( $value ) {
		if ( null === $value ) {
			return '<span class="description">—</span>';
		}
		$colour = $value >= 90 ? '#00a32a' : ( $value >= 70 ? '#dba617' : '#b32d2e' );
		return '<strong style="color:' . esc_attr( $colour ) . ';">' . (int) $value . '%</strong>';
	};
	?>
	<p><?php esc_html_e( 'Every active record is checked against the six DAMA-DMBOK quality dimensions. A score is the share of active records with no open issue in that dimension. Checks run daily; fixing or dismissing an issue rechecks its data set.', 'teatatu-data' ); ?></p>

	<?php if ( $can_fix ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:8px 0;">
			<?php wp_nonce_field( 'teatatu_data_quality_scan' ); ?>
			<input type="hidden" name="action" value="teatatu_data_quality_scan" />
			<?php submit_button( __( 'Check now', 'teatatu-data' ), 'secondary', 'submit', false ); ?>
		</form>
	<?php endif; ?>

	<table class="widefat striped" style="max-width:1200px;">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Data set', 'teatatu-data' ); ?></th>
				<th><?php esc_html_e( 'Overall', 'teatatu-data' ); ?></th>
				<?php foreach ( $dims as $label ) : ?>
					<th><?php echo esc_html( $label ); ?></th>
				<?php endforeach; ?>
				<th><?php esc_html_e( 'Open issues', 'teatatu-data' ); ?></th>
				<th><?php esc_html_e( 'Checked', 'teatatu-data' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $datasets as $key => $dataset ) : ?>
				<?php $result = isset( $results[ $key ] ) ? $results[ $key ] : null; ?>
				<tr>
					<td><a href="<?php echo esc_url( teatatu_data_admin_url( array( 'tab' => 'quality', 'filter_dataset' => $key ) ) ); ?>"><?php echo esc_html( $dataset['label'] ); ?></a>
						<?php if ( $result ) : ?><br /><span class="description"><?php /* translators: %d: records */ echo esc_html( sprintf( _n( '%d active record', '%d active records', $result['records'], 'teatatu-data' ), $result['records'] ) ); ?></span><?php endif; ?></td>
					<?php if ( $result ) : ?>
						<td><?php echo $score( $result['scores']['overall'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- built above. ?></td>
						<?php foreach ( array_keys( $dims ) as $dim ) : ?>
							<td><?php echo $score( $result['scores'][ $dim ] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<?php endforeach; ?>
						<td><?php echo (int) count( $result['issues'] ); ?><?php echo $result['dismissed'] ? ' <span class="description">(' . esc_html( sprintf( /* translators: %d: dismissed */ __( '+%d dismissed', 'teatatu-data' ), $result['dismissed'] ) ) . ')</span>' : ''; ?></td>
						<td><?php echo esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', strtotime( $result['scanned_at'] ) ), 'j M Y H:i' ) ); ?></td>
					<?php else : ?>
						<td colspan="<?php echo count( $dims ) + 3; ?>"><span class="description"><?php esc_html_e( 'Not checked yet.', 'teatatu-data' ); ?></span></td>
					<?php endif; ?>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<form method="get" style="margin:16px 0 8px;">
		<input type="hidden" name="page" value="teatatu-data" />
		<input type="hidden" name="tab" value="quality" />
		<select name="filter_dataset">
			<option value=""><?php esc_html_e( 'All data sets', 'teatatu-data' ); ?></option>
			<?php foreach ( $datasets as $key => $dataset ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filter_key, $key ); ?>><?php echo esc_html( $dataset['label'] ); ?></option>
			<?php endforeach; ?>
		</select>
		<select name="dimension">
			<option value=""><?php esc_html_e( 'All dimensions', 'teatatu-data' ); ?></option>
			<?php foreach ( $dims as $dim => $label ) : ?>
				<option value="<?php echo esc_attr( $dim ); ?>" <?php selected( $filter_dim, $dim ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<select name="show">
			<option value="open" <?php selected( $show, 'open' ); ?>><?php esc_html_e( 'Open issues', 'teatatu-data' ); ?></option>
			<option value="dismissed" <?php selected( $show, 'dismissed' ); ?>><?php esc_html_e( 'Dismissed issues', 'teatatu-data' ); ?></option>
		</select>
		<?php submit_button( __( 'Filter', 'teatatu-data' ), 'secondary', '', false ); ?>
	</form>

	<?php
	if ( 'dismissed' === $show ) {
		teatatu_data_render_dismissed_issues( $filter_key, $filter_dim, $return );
		return;
	}
	$issues = array();
	foreach ( $results as $key => $result ) {
		if ( '' !== $filter_key && $filter_key !== $key ) {
			continue;
		}
		foreach ( $result['issues'] as $issue ) {
			if ( '' === $filter_dim || $filter_dim === $issue['dimension'] ) {
				$issues[] = $issue;
			}
		}
	}
	if ( ! $issues ) {
		echo '<p><em>' . esc_html__( 'No open issues.', 'teatatu-data' ) . '</em></p>';
		return;
	}
	$shown = array_slice( $issues, 0, 200 );
	if ( $can_fix && '' !== $filter_key && 'timeliness' === $filter_dim && count( $issues ) > 1 ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:8px 0;">
			<?php wp_nonce_field( 'teatatu_data_quality_fix' ); ?>
			<input type="hidden" name="action" value="teatatu_data_quality_fix" />
			<input type="hidden" name="dataset" value="<?php echo esc_attr( $filter_key ); ?>" />
			<input type="hidden" name="return" value="<?php echo esc_attr( $return ); ?>" />
			<button type="submit" name="fix" value="verify_all" class="button" onclick="return confirm( <?php echo esc_attr( wp_json_encode( __( 'Mark every record listed as checked and correct today?', 'teatatu-data' ) ) ); ?> );">
				<?php /* translators: %d: records */ echo esc_html( sprintf( __( 'I have checked them: mark all %d as verified', 'teatatu-data' ), count( $issues ) ) ); ?>
			</button>
		</form>
		<?php
	}
	?>
	<?php if ( count( $issues ) > 200 ) : ?>
		<p class="description"><?php /* translators: %d: total */ echo esc_html( sprintf( __( 'Showing the first 200 of %d issues. Filter by data set or dimension to see the rest.', 'teatatu-data' ), count( $issues ) ) ); ?></p>
	<?php endif; ?>
	<table class="widefat striped">
		<thead>
			<tr>
				<th style="width:11%;"><?php esc_html_e( 'Dimension', 'teatatu-data' ); ?></th>
				<th style="width:12%;"><?php esc_html_e( 'Data set', 'teatatu-data' ); ?></th>
				<th><?php esc_html_e( 'Issue', 'teatatu-data' ); ?></th>
				<th style="width:34%;"><?php esc_html_e( 'Fix', 'teatatu-data' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $shown as $issue ) : ?>
				<?php $dataset = teatatu_data_get_dataset( $issue['dataset'] ); ?>
				<tr>
					<td><?php echo esc_html( $dims[ $issue['dimension'] ] ); ?></td>
					<td><?php echo esc_html( $dataset ? $dataset['label'] : $issue['dataset'] ); ?></td>
					<td>
						<?php echo esc_html( $issue['message'] ); ?>
						<br />
						<?php foreach ( $issue['records'] as $i => $uuid ) : ?>
							<a href="<?php echo esc_url( teatatu_data_admin_url( array( 'tab' => 'records', 'dataset' => $issue['dataset'], 'action' => 'edit', 'uuid' => $uuid ) ) ); ?>"><?php echo esc_html( count( $issue['records'] ) > 1 ? sprintf( /* translators: %s: letter */ __( 'Open %s', 'teatatu-data' ), chr( 65 + $i ) ) : __( 'Open', 'teatatu-data' ) ); ?></a>
						<?php endforeach; ?>
					</td>
					<td>
						<?php if ( $can_fix ) : ?>
							<?php teatatu_data_render_issue_actions( $issue, $return ); ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

/**
 * The fix buttons for one issue.
 *
 * @param array  $issue  Issue.
 * @param string $return URL to come back to.
 */
function teatatu_data_render_issue_actions( $issue, $return ) {
	$post = esc_url( admin_url( 'admin-post.php' ) );
	$type = $issue['fix']['type'];
	if ( 'merge' === $type && 2 === count( $issue['records'] ) ) {
		$url = teatatu_data_admin_url(
			array(
				'tab'     => 'quality',
				'action'  => 'merge',
				'dataset' => $issue['dataset'],
				'a'       => $issue['records'][0],
				'b'       => $issue['records'][1],
			)
		);
		echo '<a class="button button-small button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Compare and merge', 'teatatu-data' ) . '</a> ';
	}
	if ( in_array( $type, array( 'verify', 'repoint' ), true ) ) {
		?>
		<form method="post" action="<?php echo $post; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above. ?>" style="display:inline;">
			<?php wp_nonce_field( 'teatatu_data_quality_fix' ); ?>
			<input type="hidden" name="action" value="teatatu_data_quality_fix" />
			<input type="hidden" name="issue" value="<?php echo esc_attr( $issue['id'] ); ?>" />
			<input type="hidden" name="return" value="<?php echo esc_attr( $return ); ?>" />
			<button type="submit" name="fix" value="<?php echo esc_attr( $type ); ?>" class="button button-small button-primary"><?php echo 'verify' === $type ? esc_html__( 'It is correct — mark verified', 'teatatu-data' ) : esc_html__( 'Point to the replacement', 'teatatu-data' ); ?></button>
		</form>
		<?php
	}
	?>
	<form method="post" action="<?php echo $post; // phpcs:ignore WordPress.Security.EscapeOutput ?>" style="display:inline-block;margin-top:4px;">
		<?php wp_nonce_field( 'teatatu_data_quality_fix' ); ?>
		<input type="hidden" name="action" value="teatatu_data_quality_fix" />
		<input type="hidden" name="issue" value="<?php echo esc_attr( $issue['id'] ); ?>" />
		<input type="hidden" name="return" value="<?php echo esc_attr( $return ); ?>" />
		<input type="text" name="reason" required placeholder="<?php esc_attr_e( 'Why it is not a problem', 'teatatu-data' ); ?>" style="width:14em;" />
		<button type="submit" name="fix" value="dismiss" class="button button-small"><?php esc_html_e( 'Dismiss', 'teatatu-data' ); ?></button>
	</form>
	<?php
}

/**
 * Lists dismissed issues, with Reopen.
 *
 * @param string $filter_key Data set filter.
 * @param string $filter_dim Dimension filter.
 * @param string $return     Return URL.
 */
function teatatu_data_render_dismissed_issues( $filter_key, $filter_dim, $return ) {
	$dismissed = teatatu_data_get_option( 'teatatu_data_quality_dismissed', array() );
	$dims      = teatatu_data_quality_dimensions();
	$rows      = array();
	foreach ( (array) $dismissed as $id => $entry ) {
		$issue = isset( $entry['issue'] ) ? $entry['issue'] : array();
		if ( ( '' !== $filter_key && ( $issue['dataset'] ?? '' ) !== $filter_key ) || ( '' !== $filter_dim && ( $issue['dimension'] ?? '' ) !== $filter_dim ) ) {
			continue;
		}
		$rows[ $id ] = $entry;
	}
	if ( ! $rows ) {
		echo '<p><em>' . esc_html__( 'No dismissed issues.', 'teatatu-data' ) . '</em></p>';
		return;
	}
	?>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Issue', 'teatatu-data' ); ?></th>
				<th><?php esc_html_e( 'Why dismissed', 'teatatu-data' ); ?></th>
				<th><?php esc_html_e( 'By', 'teatatu-data' ); ?></th>
				<th></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $id => $entry ) : ?>
				<?php $user = get_userdata( (int) $entry['user_id'] ); ?>
				<tr>
					<td><?php echo esc_html( ( isset( $entry['issue']['dimension'], $dims[ $entry['issue']['dimension'] ] ) ? $dims[ $entry['issue']['dimension'] ] . ': ' : '' ) . ( $entry['issue']['message'] ?? $id ) ); ?></td>
					<td><?php echo esc_html( $entry['reason'] ); ?></td>
					<td><?php echo esc_html( ( $user ? $user->display_name : '?' ) . ', ' . get_date_from_gmt( gmdate( 'Y-m-d H:i:s', strtotime( $entry['at'] ) ), 'j M Y' ) ); ?></td>
					<td>
						<?php if ( teatatu_data_quality_can_fix() ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( 'teatatu_data_quality_fix' ); ?>
								<input type="hidden" name="action" value="teatatu_data_quality_fix" />
								<input type="hidden" name="issue" value="<?php echo esc_attr( $id ); ?>" />
								<input type="hidden" name="return" value="<?php echo esc_attr( $return ); ?>" />
								<button type="submit" name="fix" value="reopen" class="button button-small"><?php esc_html_e( 'Reopen', 'teatatu-data' ); ?></button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

/**
 * The side-by-side merge screen.
 */
function teatatu_data_render_merge_screen() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
	$key = isset( $_GET['dataset'] ) ? sanitize_key( wp_unslash( $_GET['dataset'] ) ) : '';
	$a   = isset( $_GET['a'] ) ? strtolower( sanitize_text_field( wp_unslash( $_GET['a'] ) ) ) : '';
	$b   = isset( $_GET['b'] ) ? strtolower( sanitize_text_field( wp_unslash( $_GET['b'] ) ) ) : '';
	// phpcs:enable
	$dataset = teatatu_data_get_dataset( $key );
	$ra      = $dataset ? teatatu_data_get( $key, $a ) : null;
	$rb      = $dataset ? teatatu_data_get( $key, $b ) : null;
	$back    = teatatu_data_admin_url( array( 'tab' => 'quality', 'filter_dataset' => $key ) );
	if ( ! $ra || ! $rb || $a === $b ) {
		echo '<p>' . esc_html__( 'Choose two different records to compare.', 'teatatu-data' ) . '</p>';
		return;
	}
	if ( ! $dataset['mergeable'] ) {
		echo '<p>' . esc_html__( 'Records in this data set cannot be merged.', 'teatatu-data' ) . '</p>';
		return;
	}
	$can     = teatatu_data_quality_can_fix();
	$fields  = array();
	foreach ( $dataset['fields'] as $field_key => $field ) {
		if ( ! $field['derived'] ) {
			$fields[ $field_key ] = $field['label'];
		}
	}
	// The older record is kept by default.
	$keep_a = strcmp( $ra['created_at'], $rb['created_at'] ) <= 0;
	?>
	<h2><?php echo esc_html( sprintf( /* translators: %s: data set */ __( 'Compare and merge: %s', 'teatatu-data' ), $dataset['label'] ) ); ?> <a class="page-title-action" href="<?php echo esc_url( $back ); ?>"><?php esc_html_e( 'Back to data quality', 'teatatu-data' ); ?></a></h2>
	<p><?php esc_html_e( 'Choose the record to keep and, where the two differ, which value it should have. The other record is marked deprecated and replaced by the one kept; everything that pointed to it is pointed to the kept record; other names and external IDs are combined. Every change is logged.', 'teatatu-data' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'teatatu_data_merge' ); ?>
		<input type="hidden" name="action" value="teatatu_data_merge" />
		<input type="hidden" name="dataset" value="<?php echo esc_attr( $key ); ?>" />
		<input type="hidden" name="a" value="<?php echo esc_attr( $a ); ?>" />
		<input type="hidden" name="b" value="<?php echo esc_attr( $b ); ?>" />
		<table class="widefat striped" style="max-width:1200px;">
			<thead>
				<tr>
					<th style="width:18%;"></th>
					<th style="width:41%;"><label><input type="radio" name="keep" value="a" <?php checked( $keep_a ); ?> /> <?php esc_html_e( 'Keep A', 'teatatu-data' ); ?></label></th>
					<th><label><input type="radio" name="keep" value="b" <?php checked( ! $keep_a ); ?> /> <?php esc_html_e( 'Keep B', 'teatatu-data' ); ?></label></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $fields as $field_key => $label ) : ?>
					<?php
					$va      = $ra[ $field_key ];
					$vb      = $rb[ $field_key ];
					$same    = teatatu_data_values_equal( $va, $vb );
					$combine = in_array( $field_key, $dataset['merge_combine'], true );
					$default = teatatu_data_is_empty( $va ) ? 'b' : ( teatatu_data_is_empty( $vb ) ? 'a' : ( $keep_a ? 'a' : 'b' ) );
					?>
					<tr>
						<th><?php echo esc_html( $label ); ?></th>
						<?php foreach ( array( 'a' => $va, 'b' => $vb ) as $side => $value ) : ?>
							<td>
								<?php if ( ! $same && ! $combine ) : ?>
									<label><input type="radio" name="use[<?php echo esc_attr( $field_key ); ?>]" value="<?php echo esc_attr( $side ); ?>" <?php checked( $default, $side ); ?> />
								<?php endif; ?>
								<?php echo esc_html( teatatu_data_display_value( $dataset, $field_key, $value ) ); ?>
								<?php if ( ! $same && ! $combine ) : ?></label><?php endif; ?>
							</td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
				<?php foreach ( array( 'status', 'external_ids', 'source', 'verified_at', 'created_at' ) as $field_key ) : ?>
					<tr>
						<th><?php echo esc_html( teatatu_data_field_label( $dataset, $field_key ) ); ?></th>
						<td><?php echo esc_html( teatatu_data_display_value( $dataset, $field_key, $ra[ $field_key ] ) ); ?></td>
						<td><?php echo esc_html( teatatu_data_display_value( $dataset, $field_key, $rb[ $field_key ] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">
			<?php
			$combined = array_map( function ( $f ) use ( $dataset ) { return teatatu_data_field_label( $dataset, $f ); }, $dataset['merge_combine'] );
			/* translators: %s: field names */
			echo esc_html( sprintf( __( 'Combined, not chosen: %s. The newer verified date is kept.', 'teatatu-data' ), implode( ', ', array_merge( $combined, array( __( 'External IDs', 'teatatu-data' ) ) ) ) ) );
			?>
		</p>
		<p><label for="ttd_merge_reason"><?php esc_html_e( 'Reason', 'teatatu-data' ); ?></label><br /><input type="text" id="ttd_merge_reason" name="reason" class="large-text" /></p>
		<?php
		if ( $can ) {
			submit_button( __( 'Merge', 'teatatu-data' ) );
		}
		?>
	</form>
	<?php
}

add_action( 'admin_post_teatatu_data_quality_scan', 'teatatu_data_handle_quality_scan' );

/**
 * Handles "Check now".
 */
function teatatu_data_handle_quality_scan() {
	check_admin_referer( 'teatatu_data_quality_scan' );
	if ( ! current_user_can( 'edit_teatatu_data' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-data' ), 403 );
	}
	teatatu_data_admin_require_master();
	teatatu_data_quality_scan_local();
	set_transient( 'teatatu_data_message_' . get_current_user_id(), __( 'All data sets checked.', 'teatatu-data' ), 5 * MINUTE_IN_SECONDS );
	wp_safe_redirect( add_query_arg( 'ttd_notice', 'message', teatatu_data_admin_url( array( 'tab' => 'quality' ) ) ) );
	exit;
}

add_action( 'admin_post_teatatu_data_quality_fix', 'teatatu_data_handle_quality_fix' );

/**
 * Handles an issue's fix: mark verified, point to the replacement, dismiss, reopen.
 */
function teatatu_data_handle_quality_fix() {
	check_admin_referer( 'teatatu_data_quality_fix' );
	if ( ! current_user_can( 'edit_teatatu_data' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-data' ), 403 );
	}
	teatatu_data_admin_require_master();
	$issue_id = isset( $_POST['issue'] ) ? sanitize_key( wp_unslash( $_POST['issue'] ) ) : '';
	$fix      = isset( $_POST['fix'] ) ? sanitize_key( wp_unslash( $_POST['fix'] ) ) : '';
	$return   = teatatu_data_admin_return_url();

	if ( 'reopen' === $fix ) {
		$dismissed = teatatu_data_get_option( 'teatatu_data_quality_dismissed', array() );
		$dataset   = isset( $dismissed[ $issue_id ]['issue']['dataset'] ) ? $dismissed[ $issue_id ]['issue']['dataset'] : null;
		teatatu_data_quality_dismiss( $issue_id, null );
		teatatu_data_quality_scan_local( $dataset );
		set_transient( 'teatatu_data_message_' . get_current_user_id(), __( 'Issue reopened.', 'teatatu-data' ), 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( 'ttd_notice', 'message', $return ) );
		exit;
	}

	if ( 'verify_all' === $fix ) {
		$key     = isset( $_POST['dataset'] ) ? sanitize_key( wp_unslash( $_POST['dataset'] ) ) : '';
		$results = teatatu_data_quality_results();
		$done    = 0;
		$failed  = array();
		foreach ( isset( $results[ $key ] ) ? $results[ $key ]['issues'] : array() as $issue ) {
			if ( 'verify' !== $issue['fix']['type'] ) {
				continue;
			}
			$applied = teatatu_data_apply( $key, $issue['records'][0], array( 'verified_at' => current_time( 'Y-m-d' ) ), array( 'channel' => 'admin', 'reason' => __( 'Verified in bulk from the data quality list.', 'teatatu-data' ) ) );
			if ( is_wp_error( $applied ) ) {
				$failed[] = teatatu_data_error_text( $applied );
			} else {
				$done++;
			}
		}
		teatatu_data_quality_scan_local( $key );
		/* translators: %d: records */
		$message = sprintf( _n( '%d record marked as verified.', '%d records marked as verified.', $done, 'teatatu-data' ), $done ) . ( $failed ? ' ' . implode( ' · ', array_unique( $failed ) ) : '' );
		set_transient( 'teatatu_data_message_' . get_current_user_id(), $message, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( 'ttd_notice', $failed ? 'error_message' : 'message', $return ) );
		exit;
	}

	$issue = teatatu_data_quality_find_issue( $issue_id );
	if ( ! $issue ) {
		teatatu_data_admin_fail( __( 'That issue is no longer open. The list has been refreshed.', 'teatatu-data' ), $return );
	}
	$key    = $issue['dataset'];
	$uuid   = $issue['records'][0];
	$result = true;
	if ( 'dismiss' === $fix ) {
		$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		if ( '' === $reason ) {
			teatatu_data_admin_fail( __( 'Say why the issue is not a problem.', 'teatatu-data' ), $return );
		}
		teatatu_data_quality_dismiss( $issue_id, $reason, $issue );
		$message = __( 'Issue dismissed.', 'teatatu-data' );
	} elseif ( 'verify' === $fix && 'verify' === $issue['fix']['type'] ) {
		$result  = teatatu_data_apply( $key, $uuid, array( 'verified_at' => current_time( 'Y-m-d' ) ), array( 'channel' => 'admin', 'reason' => __( 'Verified from the data quality list.', 'teatatu-data' ) ) );
		$message = __( 'Marked as verified today.', 'teatatu-data' );
	} elseif ( 'repoint' === $fix && 'repoint' === $issue['fix']['type'] ) {
		$result  = teatatu_data_repoint_reference( $key, $uuid, $issue['fix']['field'], $issue['fix']['from'], $issue['fix']['to'], __( 'Pointed to the replacement record, from the data quality list.', 'teatatu-data' ) );
		$message = __( 'Now points to the replacement record.', 'teatatu-data' );
	} else {
		wp_die( esc_html__( 'Unknown action.', 'teatatu-data' ), 400 );
	}
	if ( is_wp_error( $result ) ) {
		teatatu_data_admin_fail( teatatu_data_error_text( $result ), $return );
	}
	teatatu_data_quality_scan_local( $key );
	set_transient( 'teatatu_data_message_' . get_current_user_id(), $message, 5 * MINUTE_IN_SECONDS );
	wp_safe_redirect( add_query_arg( 'ttd_notice', 'message', $return ) );
	exit;
}

add_action( 'admin_post_teatatu_data_merge', 'teatatu_data_handle_merge' );

/**
 * Handles the merge form.
 */
function teatatu_data_handle_merge() {
	check_admin_referer( 'teatatu_data_merge' );
	if ( ! current_user_can( 'edit_teatatu_data' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-data' ), 403 );
	}
	teatatu_data_admin_require_master();
	$key    = isset( $_POST['dataset'] ) ? sanitize_key( wp_unslash( $_POST['dataset'] ) ) : '';
	$a      = isset( $_POST['a'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['a'] ) ) ) : '';
	$b      = isset( $_POST['b'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['b'] ) ) ) : '';
	$keep   = isset( $_POST['keep'] ) && 'b' === $_POST['keep'] ? 'b' : 'a';
	$use    = isset( $_POST['use'] ) ? array_map( 'sanitize_key', wp_unslash( (array) $_POST['use'] ) ) : array();
	$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';

	$choices = array();
	foreach ( $use as $field_key => $side ) {
		if ( $side !== $keep ) {
			$choices[ sanitize_key( $field_key ) ] = 'merge';
		}
	}
	$keep_uuid  = 'a' === $keep ? $a : $b;
	$merge_uuid = 'a' === $keep ? $b : $a;
	$result     = teatatu_data_merge( $key, $keep_uuid, $merge_uuid, $choices, $reason );
	$form       = teatatu_data_admin_url( array( 'tab' => 'quality', 'action' => 'merge', 'dataset' => $key, 'a' => $a, 'b' => $b ) );
	if ( is_wp_error( $result ) ) {
		teatatu_data_admin_fail( teatatu_data_error_text( $result ), $form );
	}
	teatatu_data_quality_scan_local();
	/* translators: %d: references updated */
	$message = sprintf( _n( 'Merged. %d reference elsewhere now points to the kept record.', 'Merged. %d references elsewhere now point to the kept record.', $result['repointed'], 'teatatu-data' ), $result['repointed'] );
	if ( $result['errors'] ) {
		$message .= ' ' . __( 'These could not be updated:', 'teatatu-data' ) . ' ' . implode( ' · ', $result['errors'] );
	}
	set_transient( 'teatatu_data_message_' . get_current_user_id(), $message, 5 * MINUTE_IN_SECONDS );
	wp_safe_redirect( add_query_arg( 'ttd_notice', $result['errors'] ? 'error_message' : 'message', teatatu_data_admin_url( array( 'tab' => 'records', 'dataset' => $key, 'action' => 'edit', 'uuid' => $keep_uuid ) ) ) );
	exit;
}
