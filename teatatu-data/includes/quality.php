<?php
/**
 * Data quality (SPEC.md §6, §17): checks every active record against the
 * six DAMA-DMBOK dimensions, scores each data set, and lists open issues
 * with a fix for each.
 *
 * Built-in checks (every data set):
 * - Validity:     the record still passes the current schema and rules.
 * - Completeness: recommended fields are filled in.
 * - Consistency:  references point to records that exist, aren't retired
 *                 and weren't replaced; a replaced record's replacement is active.
 * - Timeliness:   verified within the data set's review interval.
 * - Uniqueness:   no two active records share a duplicate key (the title by
 *                 default; data sets add their own keys).
 * Accuracy and further checks come from each data set's `quality_checks`.
 *
 * Results are kept in the Master Site option `teatatu_data_quality`;
 * dismissed issues (with who, when and why) in `teatatu_data_quality_dismissed`.
 * A daily WP-Cron job rescans everything; actions on the Data quality tab
 * rescan the data set they touch.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The DMBOK quality dimensions.
 *
 * @return array key => label
 */
function teatatu_data_quality_dimensions() {
	return array(
		'completeness' => __( 'Completeness', 'teatatu-data' ),
		'validity'     => __( 'Validity', 'teatatu-data' ),
		'uniqueness'   => __( 'Uniqueness', 'teatatu-data' ),
		'consistency'  => __( 'Consistency', 'teatatu-data' ),
		'timeliness'   => __( 'Timeliness', 'teatatu-data' ),
		'accuracy'     => __( 'Accuracy', 'teatatu-data' ),
	);
}

/**
 * Builds an issue. Its ID is stable across scans, so a dismissal sticks.
 *
 * @param string   $check     Check name, e.g. 'timeliness'.
 * @param string   $dimension One of teatatu_data_quality_dimensions().
 * @param string[] $records   UUIDs involved (the first is the one to edit).
 * @param string   $message   What is wrong, in plain words.
 * @param array    $fix       {type: 'edit'|'verify'|'merge'|'repoint', …data}.
 * @param string   $detail    Distinguishes several issues of one check on one record (e.g. a field).
 * @return array
 */
function teatatu_data_quality_issue( $check, $dimension, $records, $message, $fix = array( 'type' => 'edit' ), $detail = '' ) {
	$records = array_values( $records );
	$sorted  = $records;
	sort( $sorted );
	return array(
		'id'        => substr( md5( $check . '|' . implode( ',', $sorted ) . '|' . $detail ), 0, 16 ),
		'check'     => $check,
		'dimension' => $dimension,
		'records'   => $records,
		'message'   => $message,
		'fix'       => $fix,
	);
}

/**
 * Duplicate keys of a record: the data set's own, or its folded title.
 *
 * @param array $dataset Data set.
 * @param array $record  Record.
 * @return string[]
 */
function teatatu_data_duplicate_keys( $dataset, $record ) {
	if ( $dataset['duplicate_keys'] ) {
		return array_values( array_filter( (array) call_user_func( $dataset['duplicate_keys'], $record ) ) );
	}
	$title = teatatu_data_fold_text( isset( $record[ $dataset['title_field'] ] ) ? (string) $record[ $dataset['title_field'] ] : '' );
	return '' === $title ? array() : array( 'title:' . $title );
}

/**
 * Scans one data set on the current site (call on the Master Site).
 *
 * @param string $key Data set key.
 * @return array {scanned_at, records, issues, dismissed, scores}
 */
function teatatu_data_quality_scan_dataset( $key ) {
	$dataset   = teatatu_data_get_dataset( $key );
	$all       = teatatu_data_find_local( $key, array( 'status' => 'all', 'per_page' => -1 ) )['records'];
	$active    = array_values(
		array_filter(
			$all,
			function ( $r ) {
				return 'active' === $r['status'];
			}
		)
	);
	$dims      = teatatu_data_quality_dimensions();
	$issues    = array();
	$title     = function ( $record ) use ( $key ) {
		return teatatu_data_record_title( $key, $record );
	};
	$lookups   = array();
	$lookup    = function ( $target, $uuid ) use ( &$lookups ) {
		if ( ! isset( $lookups[ $target ][ $uuid ] ) ) {
			$lookups[ $target ][ $uuid ] = teatatu_data_get_local( $target, $uuid );
		}
		return $lookups[ $target ][ $uuid ];
	};
	$settings  = teatatu_data_dataset_settings( $key );
	$cutoff    = gmdate( 'Y-m-d', strtotime( '-' . $settings['review_interval'] . ' months' ) );
	$writable  = array( 'status', 'replaced_by', 'external_ids', 'source', 'verified_at' );

	foreach ( $active as $record ) {
		$name = $title( $record );

		// Validity: still passes today's schema and rules.
		$values = array();
		foreach ( $dataset['fields'] as $field_key => $field ) {
			if ( ! $field['derived'] ) {
				$values[ $field_key ] = $record[ $field_key ];
			}
		}
		foreach ( $writable as $field_key ) {
			$values[ $field_key ] = $record[ $field_key ];
		}
		$valid = teatatu_data_validate( $key, $values, $record );
		if ( is_wp_error( $valid ) ) {
			$data = $valid->get_error_data();
			foreach ( isset( $data['fields'] ) ? $data['fields'] : array( '' => $valid->get_error_message() ) as $field_key => $message ) {
				/* translators: 1: record, 2: field, 3: problem */
				$issues[] = teatatu_data_quality_issue( 'validity', 'validity', array( $record['uuid'] ), sprintf( __( '%1$s — %2$s: %3$s', 'teatatu-data' ), $name, teatatu_data_field_label( $dataset, $field_key ), $message ), array( 'type' => 'edit' ), $field_key );
			}
		}

		// Completeness: recommended fields.
		$missing = array();
		foreach ( $dataset['fields'] as $field_key => $field ) {
			if ( $field['recommended'] && teatatu_data_is_empty( $record[ $field_key ] ) ) {
				$missing[] = $field['label'];
			}
		}
		if ( $missing ) {
			/* translators: 1: record, 2: field names */
			$issues[] = teatatu_data_quality_issue( 'recommended', 'completeness', array( $record['uuid'] ), sprintf( __( '%1$s has no %2$s.', 'teatatu-data' ), $name, implode( ', ', $missing ) ) );
		}

		// Consistency: references.
		foreach ( $dataset['fields'] as $field_key => $field ) {
			if ( 'reference' !== $field['type'] || $field['derived'] || teatatu_data_is_empty( $record[ $field_key ] ) ) {
				continue;
			}
			foreach ( (array) $record[ $field_key ] as $uuid ) {
				$target = $lookup( $field['references'], $uuid );
				if ( ! $target ) {
					/* translators: 1: record, 2: field */
					$message = sprintf( __( '%1$s — %2$s points to a record that no longer exists.', 'teatatu-data' ), $name, $field['label'] );
					$fix     = array( 'type' => 'edit' );
				} elseif ( 'retired' === $target['status'] ) {
					/* translators: 1: record, 2: field, 3: target */
					$message = sprintf( __( '%1$s — %2$s points to "%3$s", which is retired.', 'teatatu-data' ), $name, $field['label'], teatatu_data_record_title( $field['references'], $target ) );
					$fix     = array( 'type' => 'edit' );
				} elseif ( 'deprecated' === $target['status'] && $target['replaced_by'] && ( $replacement = $lookup( $field['references'], $target['replaced_by'] ) ) && 'active' === $replacement['status'] ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
					/* translators: 1: record, 2: field, 3: old target, 4: replacement */
					$message = sprintf( __( '%1$s — %2$s points to "%3$s", which was replaced by "%4$s".', 'teatatu-data' ), $name, $field['label'], teatatu_data_record_title( $field['references'], $target ), teatatu_data_record_title( $field['references'], $replacement ) );
					$fix     = array(
						'type'  => 'repoint',
						'field' => $field_key,
						'from'  => $uuid,
						'to'    => $replacement['uuid'],
					);
				} else {
					continue;
				}
				$issues[] = teatatu_data_quality_issue( 'reference', 'consistency', array( $record['uuid'] ), $message, $fix, $field_key . ':' . $uuid );
			}
		}

		// Timeliness.
		if ( ! $record['verified_at'] || $record['verified_at'] < $cutoff ) {
			$message  = $record['verified_at']
				/* translators: 1: record, 2: date */
				? sprintf( __( '%1$s was last verified on %2$s.', 'teatatu-data' ), $name, $record['verified_at'] )
				/* translators: %s: record */
				: sprintf( __( '%s has never been verified.', 'teatatu-data' ), $name );
			$issues[] = teatatu_data_quality_issue( 'timeliness', 'timeliness', array( $record['uuid'] ), $message, array( 'type' => 'verify' ) );
		}
	}

	// Consistency: a replaced record's replacement must be active.
	foreach ( $all as $record ) {
		if ( 'deprecated' !== $record['status'] || ! $record['replaced_by'] ) {
			continue;
		}
		$replacement = $lookup( $key, $record['replaced_by'] );
		if ( ! $replacement || 'active' !== $replacement['status'] ) {
			/* translators: %s: record */
			$issues[] = teatatu_data_quality_issue( 'replacement', 'consistency', array( $record['uuid'] ), sprintf( __( '%s is replaced by a record that is not active.', 'teatatu-data' ), $title( $record ) ) );
		}
	}

	// Uniqueness: possible duplicates.
	$groups = array();
	foreach ( $active as $record ) {
		foreach ( teatatu_data_duplicate_keys( $dataset, $record ) as $dup_key ) {
			$groups[ $dup_key ][] = $record;
		}
	}
	$pairs = array();
	foreach ( $groups as $members ) {
		$members = array_slice( $members, 0, 10 );
		for ( $i = 0; $i < count( $members ); $i++ ) {
			for ( $j = $i + 1; $j < count( $members ); $j++ ) {
				$pair = array( $members[ $i ]['uuid'], $members[ $j ]['uuid'] );
				sort( $pair );
				$pairs[ implode( ',', $pair ) ] = array( $members[ $i ], $members[ $j ] );
			}
		}
	}
	foreach ( $pairs as $pair ) {
		/* translators: 1: record, 2: record */
		$issues[] = teatatu_data_quality_issue( 'duplicate', 'uniqueness', array( $pair[0]['uuid'], $pair[1]['uuid'] ), sprintf( __( '"%1$s" and "%2$s" may be the same.', 'teatatu-data' ), $title( $pair[0] ), $title( $pair[1] ) ), array( 'type' => $dataset['mergeable'] ? 'merge' : 'edit' ) );
	}

	// Data-set-specific checks.
	if ( $dataset['quality_checks'] ) {
		$issues = array_merge( $issues, (array) call_user_func( $dataset['quality_checks'], $active, $dataset, $all ) );
	}

	// Leave out dismissed issues; score what is left.
	$dismissed = teatatu_data_get_option( 'teatatu_data_quality_dismissed', array() );
	$open      = array();
	$hidden    = 0;
	foreach ( $issues as $issue ) {
		if ( isset( $dismissed[ $issue['id'] ] ) ) {
			$hidden++;
		} else {
			$issue['dataset']      = $key;
			$open[ $issue['id'] ] = $issue;
		}
	}
	$scores   = array();
	$affected = array_fill_keys( array_keys( $dims ), array() );
	foreach ( $open as $issue ) {
		foreach ( $issue['records'] as $uuid ) {
			$affected[ $issue['dimension'] ][ $uuid ] = true;
		}
	}
	$active_ids = array_flip( wp_list_pluck( $active, 'uuid' ) );
	foreach ( array_keys( $dims ) as $dim ) {
		$scores[ $dim ] = $active ? (int) round( 100 * ( 1 - count( array_intersect_key( $affected[ $dim ], $active_ids ) ) / count( $active ) ) ) : null;
	}
	$present          = array_filter(
		$scores,
		function ( $s ) {
			return null !== $s;
		}
	);
	$scores['overall'] = $present ? (int) round( array_sum( $present ) / count( $present ) ) : null;

	return array(
		'scanned_at' => gmdate( 'c' ),
		'records'    => count( $active ),
		'issues'     => array_values( $open ),
		'dismissed'  => $hidden,
		'scores'     => $scores,
	);
}

/**
 * Scans one data set, or all, and stores the results (on the Master Site).
 *
 * @param string|null $key Data set key, or null for all.
 * @return array Stored results.
 */
function teatatu_data_quality_scan( $key = null ) {
	return teatatu_data_on_master( 'teatatu_data_quality_scan_local', array( $key ) );
}

/**
 * Scans on the current site. See teatatu_data_quality_scan().
 *
 * @param string|null $key Data set key, or null for all.
 * @return array
 */
function teatatu_data_quality_scan_local( $key = null ) {
	$results = get_option( 'teatatu_data_quality', array() );
	$results = is_array( $results ) ? $results : array();
	$keys    = $key ? array( $key ) : array_keys( teatatu_data_get_record_datasets() );
	foreach ( $keys as $dataset_key ) {
		if ( teatatu_data_get_dataset( $dataset_key ) ) {
			$results[ $dataset_key ] = teatatu_data_quality_scan_dataset( $dataset_key );
		}
	}
	// Forget data sets that are no longer registered.
	$results = array_intersect_key( $results, teatatu_data_get_record_datasets() );
	update_option( 'teatatu_data_quality', $results, false );
	return $results;
}

/**
 * The stored results, read from the Master Site.
 *
 * @return array data set key => {scanned_at, records, issues, dismissed, scores}
 */
function teatatu_data_quality_results() {
	return (array) teatatu_data_on_master(
		function () {
			$results = get_option( 'teatatu_data_quality', array() );
			return is_array( $results ) ? $results : array();
		}
	);
}

/**
 * Number of open issues across all data sets.
 *
 * @return int
 */
function teatatu_data_quality_open_count() {
	$count = 0;
	foreach ( teatatu_data_quality_results() as $result ) {
		$count += count( $result['issues'] );
	}
	return $count;
}

/**
 * Dismisses an issue (it stays hidden while the same problem remains), or
 * reopens it when $reason is null.
 *
 * @param string      $issue_id Issue ID.
 * @param string|null $reason   Why it is not a problem; null to reopen.
 * @param array       $issue    The issue (kept so dismissed issues can be listed).
 */
function teatatu_data_quality_dismiss( $issue_id, $reason, $issue = array() ) {
	$dismissed = teatatu_data_get_option( 'teatatu_data_quality_dismissed', array() );
	$dismissed = is_array( $dismissed ) ? $dismissed : array();
	if ( null === $reason ) {
		unset( $dismissed[ $issue_id ] );
	} else {
		$dismissed[ $issue_id ] = array(
			'reason'  => sanitize_textarea_field( $reason ),
			'user_id' => get_current_user_id(),
			'at'      => gmdate( 'c' ),
			'issue'   => array_intersect_key( $issue, array_flip( array( 'dataset', 'dimension', 'message', 'records' ) ) ),
		);
	}
	teatatu_data_update_option( 'teatatu_data_quality_dismissed', $dismissed );
}

/**
 * Points one reference of a record at another record (fix for a reference
 * to a replaced record), through the write path.
 *
 * @param string $key   Data set key.
 * @param string $uuid  Record UUID.
 * @param string $field Reference field.
 * @param string $from  Old target UUID.
 * @param string $to    New target UUID.
 * @param string $reason Reason for the log.
 * @return array|WP_Error
 */
function teatatu_data_repoint_reference( $key, $uuid, $field, $from, $to, $reason = '' ) {
	return teatatu_data_on_master( 'teatatu_data_repoint_reference_local', array( $key, $uuid, $field, $from, $to, $reason ) );
}

/**
 * Repoints a reference on the current site. See teatatu_data_repoint_reference().
 *
 * @param string $key    Data set key.
 * @param string $uuid   Record UUID.
 * @param string $field  Field.
 * @param string $from   Old target.
 * @param string $to     New target.
 * @param string $reason Reason.
 * @return array|WP_Error
 */
function teatatu_data_repoint_reference_local( $key, $uuid, $field, $from, $to, $reason = '' ) {
	$record  = teatatu_data_get_local( $key, $uuid );
	$dataset = teatatu_data_get_dataset( $key );
	if ( ! $record || ! isset( $dataset['fields'][ $field ] ) && 'replaced_by' !== $field ) {
		return new WP_Error( 'teatatu_data_not_found', __( 'No such record or field.', 'teatatu-data' ), array( 'status' => 404 ) );
	}
	$value = $record[ $field ];
	if ( is_array( $value ) ) {
		$value = array_values( array_unique( array_map( function ( $v ) use ( $from, $to ) { return $v === $from ? $to : $v; }, $value ) ) );
	} elseif ( $value === $from ) {
		$value = $to;
	} else {
		return array( 'uuid' => $uuid, 'changed' => false, 'change_id' => 0, 'operation' => 'update' );
	}
	return teatatu_data_apply_local( $key, $uuid, array( $field => $value ), array( 'channel' => 'admin', 'reason' => $reason ) );
}

/* ---------------------------------------------------------------------
 * Scheduling
 * ------------------------------------------------------------------- */

add_action( 'admin_init', 'teatatu_data_quality_schedule' );

/**
 * Schedules the daily scan on the Master Site.
 */
function teatatu_data_quality_schedule() {
	if ( teatatu_data_is_master_site() && ! wp_next_scheduled( 'teatatu_data_quality_scan' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'teatatu_data_quality_scan' );
	}
}

add_action( 'teatatu_data_quality_scan', 'teatatu_data_quality_run_scheduled' );

/**
 * Runs the scheduled scan.
 */
function teatatu_data_quality_run_scheduled() {
	if ( teatatu_data_is_master_site() ) {
		teatatu_data_quality_scan_local();
	}
}

/* ---------------------------------------------------------------------
 * REST
 * ------------------------------------------------------------------- */

add_action( 'rest_api_init', 'teatatu_data_register_quality_rest_route' );

/**
 * GET /teatatu-data/v1/quality?dataset=
 */
function teatatu_data_register_quality_rest_route() {
	register_rest_route(
		'teatatu-data/v1',
		'/quality',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => function ( $request ) {
				$results = teatatu_data_quality_results();
				$key     = (string) $request['dataset'];
				if ( '' !== $key ) {
					$results = isset( $results[ $key ] ) ? array( $key => $results[ $key ] ) : array();
				}
				return rest_ensure_response( $results );
			},
			'permission_callback' => function () {
				return current_user_can( 'read_teatatu_data' );
			},
			'args'                => array(
				'dataset' => array(
					'type'    => 'string',
					'default' => '',
				),
			),
		)
	);
}
