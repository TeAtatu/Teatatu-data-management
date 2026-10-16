<?php
/**
 * The single write path, the change log, and proposed-change approval
 * (SPEC.md §7).
 *
 * - teatatu_data_apply() is the only function that writes a record. It
 *   validates, stores, and logs a field-by-field diff.
 * - teatatu_data_propose() validates and stores a *pending* change for a
 *   steward to approve; nothing changes in the record until then.
 * - Every change, applied or proposed, is one `teatatu_data_change` post:
 *   proposals become the log entry for their own change when approved.
 *
 * None of these functions check capabilities: callers (admin handlers,
 * REST, import) do, with the narrowest capability that fits.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Channels a change can arrive through.
 *
 * @return string[]
 */
function teatatu_data_change_channels() {
	return array( 'admin', 'import', 'rest', 'migration', 'connector', 'system' );
}

/**
 * Whether two cleaned values are the same.
 *
 * @param mixed $a Value.
 * @param mixed $b Value.
 * @return bool
 */
function teatatu_data_values_equal( $a, $b ) {
	if ( teatatu_data_is_empty( $a ) || teatatu_data_is_empty( $b ) ) {
		return teatatu_data_is_empty( $a ) && teatatu_data_is_empty( $b );
	}
	if ( is_array( $a ) && is_array( $b ) ) {
		return wp_json_encode( $a ) === wp_json_encode( $b );
	}
	if ( is_numeric( $a ) && is_numeric( $b ) ) {
		return (float) $a === (float) $b;
	}
	return $a === $b;
}

/**
 * Meta key a field is stored under.
 *
 * @param array  $dataset   Data set definition.
 * @param string $field_key Field key.
 * @return string
 */
function teatatu_data_meta_key( $dataset, $field_key ) {
	return isset( $dataset['fields'][ $field_key ] ) ? '_ttd_f_' . $field_key : '_ttd_' . $field_key;
}

/**
 * Creates or updates a record. Runs on the Master Site.
 *
 * @param string      $key    Data set key.
 * @param string|null $uuid   Record UUID to update, or null to create.
 * @param array       $values Field => raw value (see teatatu_data_validate()).
 * @param array       $args {
 *     @type string $channel   One of teatatu_data_change_channels(). Default 'admin'.
 *     @type string $reason    Why the change was made.
 *     @type int    $change_id Pending change being approved (it becomes the log entry).
 *     @type int    $user_id   Who is making the change. Default current user.
 *     @type int    $author_id Who created the record (creates only). Default $user_id.
 *     @type string $new_uuid  UUID to give a new record (imports between installs).
 * }
 * @return array|WP_Error {uuid, changed, change_id, operation}
 */
function teatatu_data_apply( $key, $uuid, $values, $args = array() ) {
	return teatatu_data_on_master( 'teatatu_data_apply_local', array( $key, $uuid, $values, $args ) );
}

/**
 * Creates or updates a record on the current site. See teatatu_data_apply().
 *
 * @param string      $key    Data set key.
 * @param string|null $uuid   Record UUID, or null to create.
 * @param array       $values Values.
 * @param array       $args   Arguments.
 * @return array|WP_Error
 */
function teatatu_data_apply_local( $key, $uuid, $values, $args = array() ) {
	$args = wp_parse_args(
		$args,
		array(
			'channel'   => 'admin',
			'reason'    => '',
			'change_id' => 0,
			'user_id'   => get_current_user_id(),
			'author_id' => 0,
			'new_uuid'  => '',
		)
	);

	$dataset = teatatu_data_get_dataset( $key );
	if ( ! $dataset || 'metadata' === $dataset['kind'] ) {
		return new WP_Error( 'teatatu_data_unknown_dataset', __( 'Unknown data set.', 'teatatu-data' ), array( 'status' => 404 ) );
	}

	$post_id = 0;
	$current = null;
	if ( $uuid ) {
		$post_id = teatatu_data_find_post_id( $key, $uuid );
		if ( ! $post_id ) {
			return new WP_Error( 'teatatu_data_not_found', __( 'No such record.', 'teatatu-data' ), array( 'status' => 404 ) );
		}
		$current = teatatu_data_record_from_post( get_post( $post_id ), $dataset );
	}

	$clean = teatatu_data_validate( $key, $values, $current );
	if ( is_wp_error( $clean ) ) {
		return $clean;
	}

	// Derived fields are worked out from the record as it will be.
	if ( $dataset['derive'] ) {
		$merged = array_merge( $current ? $current : array(), $clean );
		foreach ( (array) call_user_func( $dataset['derive'], $merged ) as $field_key => $value ) {
			if ( isset( $dataset['fields'][ $field_key ] ) && $dataset['fields'][ $field_key ]['derived'] ) {
				$clean[ $field_key ] = $value;
			}
		}
	}

	$diff = array();
	foreach ( $clean as $field_key => $after ) {
		$before = ( $current && array_key_exists( $field_key, $current ) ) ? $current[ $field_key ] : null;
		if ( ! teatatu_data_values_equal( $before, $after ) ) {
			$diff[ $field_key ] = array(
				'before' => $before,
				'after'  => $after,
			);
		}
	}
	if ( ! $current && ! isset( $diff['status'] ) ) {
		$diff['status'] = array(
			'before' => null,
			'after'  => 'active',
		);
	}

	if ( $current && ! $diff ) {
		if ( $args['change_id'] ) {
			teatatu_data_finish_change( $args['change_id'], 'applied', array(), $current['uuid'], $args['user_id'] );
		}
		return array(
			'uuid'      => $current['uuid'],
			'changed'   => false,
			'change_id' => (int) $args['change_id'],
			'operation' => 'update',
		);
	}

	$title_field = $dataset['title_field'];
	$title       = array_key_exists( $title_field, $clean ) ? $clean[ $title_field ] : ( $current ? $current[ $title_field ] : '' );
	$status      = isset( $diff['status'] ) ? $diff['status']['after'] : $current['status'];

	if ( $current ) {
		$operation = 'update';
		$result    = wp_update_post(
			wp_slash(
				array(
					'ID'          => $post_id,
					'post_title'  => (string) $title,
					'post_status' => teatatu_data_post_status( $status ),
				)
			),
			true
		);
		$uuid = $current['uuid'];
	} else {
		$operation = 'create';
		$uuid      = ( teatatu_data_is_uuid( $args['new_uuid'] ) && ! teatatu_data_find_post_id( $key, $args['new_uuid'] ) ) ? strtolower( $args['new_uuid'] ) : wp_generate_uuid4();
		$result    = wp_insert_post(
			wp_slash(
				array(
					'post_type'   => $dataset['post_type'],
					'post_status' => teatatu_data_post_status( $status ),
					'post_title'  => (string) $title,
					'post_author' => $args['author_id'] ? (int) $args['author_id'] : (int) $args['user_id'],
				)
			),
			true
		);
	}
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	$post_id = (int) $result;

	if ( 'create' === $operation ) {
		update_post_meta( $post_id, '_ttd_uuid', $uuid );
	}
	foreach ( $diff as $field_key => $change ) {
		if ( 'status' === $field_key ) {
			continue;
		}
		$meta_key = teatatu_data_meta_key( $dataset, $field_key );
		$after    = $change['after'];
		if ( teatatu_data_is_empty( $after ) ) {
			delete_post_meta( $post_id, $meta_key );
		} else {
			update_post_meta( $post_id, $meta_key, wp_slash( is_bool( $after ) ? (int) $after : $after ) );
		}
	}
	update_post_meta( $post_id, '_ttd_updated_by', (int) $args['user_id'] );

	if ( $args['change_id'] ) {
		$change_id = (int) $args['change_id'];
		teatatu_data_finish_change( $change_id, 'applied', $diff, $uuid, $args['user_id'] );
	} else {
		$change_id = teatatu_data_insert_change(
			array(
				'dataset'   => $key,
				'record'    => $uuid,
				'operation' => $operation,
				'state'     => 'applied',
				'channel'   => $args['channel'],
				'reason'    => $args['reason'],
				'user_id'   => $args['user_id'],
				'title'     => $title,
				'diff'      => $diff,
			)
		);
		update_post_meta( $change_id, '_ttd_decided_by', (int) $args['user_id'] );
		update_post_meta( $change_id, '_ttd_decided_at', gmdate( 'c' ) );
	}

	teatatu_data_bump_cache_version();

	/**
	 * Fires after a record has been created or changed on the Master Site.
	 * Consumers (term sync, quality checks) hook here.
	 *
	 * @param string $key       Data set key.
	 * @param string $uuid      Record UUID.
	 * @param array  $diff      Field => {before, after}.
	 * @param string $operation 'create' or 'update'.
	 */
	do_action( 'teatatu_data_record_changed', $key, $uuid, $diff, $operation );

	return array(
		'uuid'      => $uuid,
		'changed'   => true,
		'change_id' => $change_id,
		'operation' => $operation,
	);
}

/**
 * Stores a change for a steward to approve. Runs on the Master Site.
 *
 * @param string      $key    Data set key.
 * @param string|null $uuid   Record UUID to change, or null for a new record.
 * @param array       $values Field => raw value.
 * @param array       $args {
 *     @type string $channel  Default 'rest'.
 *     @type string $reason   Why.
 *     @type string $batch    Import batch ID.
 *     @type int    $user_id  Proposer. Default current user.
 *     @type string $new_uuid UUID to give the new record when approved.
 * }
 * @return int|WP_Error Change ID.
 */
function teatatu_data_propose( $key, $uuid, $values, $args = array() ) {
	return teatatu_data_on_master( 'teatatu_data_propose_local', array( $key, $uuid, $values, $args ) );
}

/**
 * Stores a proposed change on the current site. See teatatu_data_propose().
 *
 * @param string      $key    Data set key.
 * @param string|null $uuid   Record UUID, or null.
 * @param array       $values Values.
 * @param array       $args   Arguments.
 * @return int|WP_Error
 */
function teatatu_data_propose_local( $key, $uuid, $values, $args = array() ) {
	$args = wp_parse_args(
		$args,
		array(
			'channel'  => 'rest',
			'reason'   => '',
			'batch'    => '',
			'user_id'  => get_current_user_id(),
			'new_uuid' => '',
		)
	);

	$dataset = teatatu_data_get_dataset( $key );
	if ( ! $dataset || 'metadata' === $dataset['kind'] ) {
		return new WP_Error( 'teatatu_data_unknown_dataset', __( 'Unknown data set.', 'teatatu-data' ), array( 'status' => 404 ) );
	}

	$current = null;
	if ( $uuid ) {
		$current = teatatu_data_get_local( $key, $uuid );
		if ( ! $current ) {
			return new WP_Error( 'teatatu_data_not_found', __( 'No such record.', 'teatatu-data' ), array( 'status' => 404 ) );
		}
	}

	$clean = teatatu_data_validate( $key, $values, $current );
	if ( is_wp_error( $clean ) ) {
		return $clean;
	}

	$base = array();
	if ( $current ) {
		foreach ( $clean as $field_key => $value ) {
			if ( teatatu_data_values_equal( $current[ $field_key ], $value ) ) {
				unset( $clean[ $field_key ] );
			} else {
				$base[ $field_key ] = $current[ $field_key ];
			}
		}
		if ( ! $clean ) {
			return new WP_Error( 'teatatu_data_no_change', __( 'The proposal does not change anything.', 'teatatu-data' ), array( 'status' => 400 ) );
		}
	}

	$title_field = $dataset['title_field'];
	$record      = $current ? $current['uuid'] : ( teatatu_data_is_uuid( $args['new_uuid'] ) ? strtolower( $args['new_uuid'] ) : '' );

	$change_id = teatatu_data_insert_change(
		array(
			'dataset'   => $key,
			'record'    => $record,
			'operation' => $current ? 'update' : 'create',
			'state'     => 'pending',
			'channel'   => $args['channel'],
			'reason'    => $args['reason'],
			'user_id'   => $args['user_id'],
			'title'     => isset( $clean[ $title_field ] ) ? $clean[ $title_field ] : ( $current ? $current[ $title_field ] : '' ),
			'values'    => $clean,
			'base'      => $base,
			'batch'     => $args['batch'],
		)
	);
	if ( is_wp_error( $change_id ) ) {
		return $change_id;
	}

	/**
	 * Fires after a change has been proposed.
	 *
	 * @param int    $change_id Change ID.
	 * @param string $key       Data set key.
	 */
	do_action( 'teatatu_data_change_proposed', $change_id, $key );

	return $change_id;
}

/**
 * Writes a change-log entry.
 *
 * @param array $change dataset, record, operation, state, channel, reason,
 *                      user_id, title, and optionally values, base, diff, batch.
 * @return int|WP_Error Change ID.
 */
function teatatu_data_insert_change( $change ) {
	$channel = in_array( $change['channel'], teatatu_data_change_channels(), true ) ? $change['channel'] : 'admin';
	$post_id = wp_insert_post(
		wp_slash(
			array(
				'post_type'    => 'teatatu_data_change',
				'post_status'  => 'private',
				'post_title'   => sprintf( '%s %s: %s', $change['dataset'], $change['operation'], (string) $change['title'] ),
				'post_content' => sanitize_textarea_field( (string) $change['reason'] ),
				'post_author'  => (int) $change['user_id'],
			)
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}
	$meta = array(
		'_ttd_dataset'   => $change['dataset'],
		'_ttd_record'    => (string) $change['record'],
		'_ttd_operation' => $change['operation'],
		'_ttd_state'     => $change['state'],
		'_ttd_channel'   => $channel,
	);
	foreach ( array( 'values', 'base', 'diff', 'batch' ) as $optional ) {
		if ( ! empty( $change[ $optional ] ) ) {
			$meta[ '_ttd_' . $optional ] = $change[ $optional ];
		}
	}
	foreach ( $meta as $meta_key => $value ) {
		update_post_meta( $post_id, $meta_key, wp_slash( $value ) );
	}
	return (int) $post_id;
}

/**
 * Marks a pending change as applied or rejected.
 *
 * @param int    $change_id Change ID.
 * @param string $state     'applied' or 'rejected'.
 * @param array  $diff      Field => {before, after} (applied only).
 * @param string $uuid      Record UUID (applied only).
 * @param int    $user_id   Who decided.
 * @param string $note      Decision note.
 */
function teatatu_data_finish_change( $change_id, $state, $diff, $uuid, $user_id, $note = '' ) {
	update_post_meta( $change_id, '_ttd_state', $state );
	update_post_meta( $change_id, '_ttd_decided_by', (int) $user_id );
	update_post_meta( $change_id, '_ttd_decided_at', gmdate( 'c' ) );
	if ( 'applied' === $state ) {
		update_post_meta( $change_id, '_ttd_diff', wp_slash( $diff ) );
		update_post_meta( $change_id, '_ttd_record', $uuid );
	}
	if ( '' !== $note ) {
		update_post_meta( $change_id, '_ttd_note', wp_slash( sanitize_textarea_field( $note ) ) );
	}
}

/**
 * Approves a pending change: applies it through the write path.
 *
 * @param int    $change_id Change ID.
 * @param string $note      Decision note.
 * @return array|WP_Error Result of teatatu_data_apply().
 */
function teatatu_data_approve_change( $change_id, $note = '' ) {
	return teatatu_data_on_master( 'teatatu_data_approve_change_local', array( $change_id, $note ) );
}

/**
 * Approves a pending change on the current site.
 *
 * @param int    $change_id Change ID.
 * @param string $note      Decision note.
 * @return array|WP_Error
 */
function teatatu_data_approve_change_local( $change_id, $note = '' ) {
	$change = teatatu_data_get_change_local( $change_id );
	if ( ! $change ) {
		return new WP_Error( 'teatatu_data_not_found', __( 'No such change.', 'teatatu-data' ), array( 'status' => 404 ) );
	}
	if ( 'pending' !== $change['state'] ) {
		return new WP_Error( 'teatatu_data_not_pending', __( 'This change has already been decided.', 'teatatu-data' ), array( 'status' => 409 ) );
	}
	$is_update = 'update' === $change['operation'];
	$result    = teatatu_data_apply_local(
		$change['dataset'],
		$is_update ? $change['record'] : null,
		$change['values'],
		array(
			'channel'   => $change['channel'],
			'reason'    => $change['reason'],
			'change_id' => $change['id'],
			'author_id' => $change['author_id'],
			'new_uuid'  => $is_update ? '' : $change['record'],
		)
	);
	if ( ! is_wp_error( $result ) && '' !== $note ) {
		update_post_meta( $change['id'], '_ttd_note', wp_slash( sanitize_textarea_field( $note ) ) );
	}
	return $result;
}

/**
 * Rejects a pending change.
 *
 * @param int    $change_id Change ID.
 * @param string $note      Why it was rejected.
 * @return true|WP_Error
 */
function teatatu_data_reject_change( $change_id, $note = '' ) {
	return teatatu_data_on_master( 'teatatu_data_reject_change_local', array( $change_id, $note ) );
}

/**
 * Rejects a pending change on the current site.
 *
 * @param int    $change_id Change ID.
 * @param string $note      Note.
 * @return true|WP_Error
 */
function teatatu_data_reject_change_local( $change_id, $note = '' ) {
	$change = teatatu_data_get_change_local( $change_id );
	if ( ! $change ) {
		return new WP_Error( 'teatatu_data_not_found', __( 'No such change.', 'teatatu-data' ), array( 'status' => 404 ) );
	}
	if ( 'pending' !== $change['state'] ) {
		return new WP_Error( 'teatatu_data_not_pending', __( 'This change has already been decided.', 'teatatu-data' ), array( 'status' => 409 ) );
	}
	teatatu_data_finish_change( $change['id'], 'rejected', array(), $change['record'], get_current_user_id(), $note );
	return true;
}

/**
 * Reverts an applied update: sets each changed field back to its earlier
 * value, through the write path (so it is validated and logged too).
 *
 * @param int $change_id Change ID.
 * @return array|WP_Error
 */
function teatatu_data_revert_change( $change_id ) {
	return teatatu_data_on_master( 'teatatu_data_revert_change_local', array( $change_id ) );
}

/**
 * Reverts an applied update on the current site.
 *
 * @param int $change_id Change ID.
 * @return array|WP_Error
 */
function teatatu_data_revert_change_local( $change_id ) {
	$change = teatatu_data_get_change_local( $change_id );
	if ( ! $change || 'applied' !== $change['state'] || 'update' !== $change['operation'] || ! $change['diff'] ) {
		return new WP_Error( 'teatatu_data_cannot_revert', __( 'Only an applied change to an existing record can be reverted.', 'teatatu-data' ), array( 'status' => 409 ) );
	}
	$values = array();
	foreach ( $change['diff'] as $field_key => $values_pair ) {
		$values[ $field_key ] = $values_pair['before'];
	}
	return teatatu_data_apply_local(
		$change['dataset'],
		$change['record'],
		$values,
		array(
			'channel' => 'admin',
			/* translators: %d: change ID */
			'reason'  => sprintf( __( 'Reverts change #%d.', 'teatatu-data' ), $change['id'] ),
		)
	);
}

/**
 * One change, read from the Master Site.
 *
 * @param int $change_id Change ID.
 * @return array|null
 */
function teatatu_data_get_change( $change_id ) {
	return teatatu_data_on_master( 'teatatu_data_get_change_local', array( $change_id ) );
}

/**
 * One change from the current site.
 *
 * @param int $change_id Change ID.
 * @return array|null
 */
function teatatu_data_get_change_local( $change_id ) {
	$post = get_post( (int) $change_id );
	if ( ! $post || 'teatatu_data_change' !== $post->post_type ) {
		return null;
	}
	return teatatu_data_change_from_post( $post );
}

/**
 * Builds a change array from its post.
 *
 * @param WP_Post $post Change post.
 * @return array
 */
function teatatu_data_change_from_post( $post ) {
	$get = function ( $meta_key, $default = '' ) use ( $post ) {
		$value = get_post_meta( $post->ID, $meta_key, true );
		return '' === $value ? $default : $value;
	};
	$author     = get_userdata( (int) $post->post_author );
	$decided_by = (int) $get( '_ttd_decided_by', 0 );
	$decider    = $decided_by ? get_userdata( $decided_by ) : false;
	return array(
		'id'              => (int) $post->ID,
		'dataset'         => (string) $get( '_ttd_dataset' ),
		'record'          => (string) $get( '_ttd_record' ),
		'operation'       => (string) $get( '_ttd_operation' ),
		'state'           => (string) $get( '_ttd_state' ),
		'channel'         => (string) $get( '_ttd_channel' ),
		'reason'          => (string) $post->post_content,
		'values'          => (array) $get( '_ttd_values', array() ),
		'base'            => (array) $get( '_ttd_base', array() ),
		'diff'            => (array) $get( '_ttd_diff', array() ),
		'batch'           => (string) $get( '_ttd_batch' ),
		'title'           => (string) $post->post_title,
		'author_id'       => (int) $post->post_author,
		'author_name'     => $author ? $author->display_name : '',
		'created_at'      => mysql2date( 'c', $post->post_date_gmt, false ),
		'decided_by'      => $decided_by,
		'decided_by_name' => $decider ? $decider->display_name : '',
		'decided_at'      => (string) $get( '_ttd_decided_at' ),
		'note'            => (string) $get( '_ttd_note' ),
	);
}

/**
 * Lists changes, read from the Master Site.
 *
 * @param array $args {
 *     @type string $dataset  Data set key.
 *     @type string $record   Record UUID.
 *     @type string $state    'pending', 'applied' or 'rejected'.
 *     @type string $batch    Import batch ID.
 *     @type int    $author   Proposer user ID.
 *     @type int    $per_page Default 50; -1 for all.
 *     @type int    $page     Default 1.
 * }
 * @return array {changes: array[], total: int}
 */
function teatatu_data_get_changes( $args = array() ) {
	return teatatu_data_on_master( 'teatatu_data_get_changes_local', array( $args ) );
}

/**
 * Lists changes on the current site. See teatatu_data_get_changes().
 *
 * @param array $args Arguments.
 * @return array
 */
function teatatu_data_get_changes_local( $args = array() ) {
	$args = wp_parse_args(
		$args,
		array(
			'dataset'  => '',
			'record'   => '',
			'state'    => '',
			'batch'    => '',
			'author'   => 0,
			'per_page' => 50,
			'page'     => 1,
		)
	);
	$meta_query = array();
	foreach ( array( 'dataset', 'record', 'state', 'batch' ) as $filter ) {
		if ( '' !== (string) $args[ $filter ] ) {
			$meta_query[] = array(
				'key'   => '_ttd_' . $filter,
				'value' => (string) $args[ $filter ],
			);
		}
	}
	$query_args = array(
		'post_type'        => 'teatatu_data_change',
		'post_status'      => 'private',
		'meta_query'       => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery
		'posts_per_page'   => (int) $args['per_page'],
		'paged'            => max( 1, (int) $args['page'] ),
		'orderby'          => array( 'date' => 'DESC', 'ID' => 'DESC' ),
		'suppress_filters' => true,
	);
	if ( $args['author'] ) {
		$query_args['author'] = (int) $args['author'];
	}
	$query   = new WP_Query( $query_args );
	$changes = array();
	foreach ( $query->posts as $post ) {
		$changes[] = teatatu_data_change_from_post( $post );
	}
	return array(
		'changes' => $changes,
		'total'   => (int) $query->found_posts,
	);
}

/**
 * Number of pending changes (for the admin menu badge).
 *
 * @return int
 */
function teatatu_data_pending_count() {
	$result = teatatu_data_get_changes(
		array(
			'state'    => 'pending',
			'per_page' => 1,
		)
	);
	return $result['total'];
}

/**
 * Re-works out the derived fields of every record in a data set (e.g. after
 * the rules they depend on changed), logging each change on the `system`
 * channel.
 *
 * @param string $key    Data set key.
 * @param string $reason Why.
 * @return int Number of records that changed.
 */
function teatatu_data_rederive( $key, $reason = '' ) {
	return teatatu_data_on_master( 'teatatu_data_rederive_local', array( $key, $reason ) );
}

/**
 * Re-works out derived fields on the current site. See teatatu_data_rederive().
 *
 * @param string $key    Data set key.
 * @param string $reason Why.
 * @return int
 */
function teatatu_data_rederive_local( $key, $reason = '' ) {
	$dataset = teatatu_data_get_dataset( $key );
	if ( ! $dataset || ! $dataset['derive'] ) {
		return 0;
	}
	$changed = 0;
	foreach ( teatatu_data_find_local( $key, array( 'status' => 'all', 'per_page' => -1 ) )['records'] as $record ) {
		$result = teatatu_data_apply_local(
			$key,
			$record['uuid'],
			array(),
			array(
				'channel' => 'system',
				'reason'  => $reason,
				'user_id' => 0,
			)
		);
		if ( ! is_wp_error( $result ) && $result['changed'] ) {
			$changed++;
		}
	}
	return $changed;
}
