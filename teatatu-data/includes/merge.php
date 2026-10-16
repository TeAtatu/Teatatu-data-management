<?php
/**
 * Match and merge (SPEC.md §6, §17): two records that describe the same
 * thing become one "golden record".
 *
 * teatatu_data_merge() — every step through the write path, so each is
 * validated and logged:
 *  1. The record being merged is deprecated, with `replaced_by` pointing at
 *     the one kept (matchers then resolve it to the kept record, and it hands
 *     over its unique values).
 *  2. The kept record takes the field values the steward chose from the
 *     other; `merge_combine` fields (aliases, synonyms) and external IDs are
 *     combined, and the other's name becomes an alias where there is one.
 *  3. Every reference to the merged record anywhere in Teatatu Data (other
 *     data sets' reference fields, parents, `replaced_by`) is pointed at the
 *     kept record.
 *  4. `teatatu_data_records_merged` fires, for consumer plugins (Phase 8).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Merges one record into another.
 *
 * @param string $key     Data set key.
 * @param string $keep    UUID of the record that stays.
 * @param string $merge   UUID of the record merged into it.
 * @param array  $choices Field => 'merge' to take the merged record's value
 *                        for that field (fields not listed keep their value).
 * @param string $reason  Why (for the log).
 * @return array|WP_Error {keep, merged, repointed, errors: string[]}
 */
function teatatu_data_merge( $key, $keep, $merge, $choices = array(), $reason = '' ) {
	return teatatu_data_on_master( 'teatatu_data_merge_local', array( $key, $keep, $merge, $choices, $reason ) );
}

/**
 * Merges on the current site. See teatatu_data_merge().
 *
 * @param string $key     Data set key.
 * @param string $keep    Kept UUID.
 * @param string $merge   Merged UUID.
 * @param array  $choices Field choices.
 * @param string $reason  Reason.
 * @return array|WP_Error
 */
function teatatu_data_merge_local( $key, $keep, $merge, $choices = array(), $reason = '' ) {
	$dataset = teatatu_data_get_dataset( $key );
	if ( ! $dataset || 'metadata' === $dataset['kind'] ) {
		return new WP_Error( 'teatatu_data_unknown_dataset', __( 'Unknown data set.', 'teatatu-data' ), array( 'status' => 404 ) );
	}
	if ( ! $dataset['mergeable'] ) {
		return new WP_Error( 'teatatu_data_not_mergeable', __( 'Records in this data set cannot be merged.', 'teatatu-data' ), array( 'status' => 409 ) );
	}
	$kept   = teatatu_data_get_local( $key, $keep );
	$merged = teatatu_data_get_local( $key, $merge );
	if ( ! $kept || ! $merged ) {
		return new WP_Error( 'teatatu_data_not_found', __( 'No such record.', 'teatatu-data' ), array( 'status' => 404 ) );
	}
	if ( $kept['uuid'] === $merged['uuid'] ) {
		return new WP_Error( 'teatatu_data_merge_self', __( 'A record cannot be merged into itself.', 'teatatu-data' ), array( 'status' => 400 ) );
	}
	if ( 'active' !== $kept['status'] && 'proposed' !== $kept['status'] ) {
		return new WP_Error( 'teatatu_data_merge_inactive', __( 'The record you keep must be active or proposed.', 'teatatu-data' ), array( 'status' => 409 ) );
	}
	if ( 'retired' === $merged['status'] ) {
		return new WP_Error( 'teatatu_data_merge_retired', __( 'A retired record cannot be merged.', 'teatatu-data' ), array( 'status' => 409 ) );
	}

	$kept_name   = teatatu_data_record_title( $key, $kept );
	$merged_name = teatatu_data_record_title( $key, $merged );
	/* translators: 1: merged record, 2: kept record */
	$log    = trim( sprintf( __( 'Merged "%1$s" into "%2$s".', 'teatatu-data' ), $merged_name, $kept_name ) . ' ' . $reason );
	$result = array(
		'keep'      => $kept['uuid'],
		'merged'    => $merged['uuid'],
		'repointed' => 0,
		'errors'    => array(),
	);

	// 1. Withdraw the merged record, pointing at the one kept.
	$step = teatatu_data_apply_local( $key, $merged['uuid'], array( 'status' => 'deprecated', 'replaced_by' => $kept['uuid'] ), array( 'channel' => 'admin', 'reason' => $log ) );
	if ( is_wp_error( $step ) ) {
		return $step;
	}

	// 2. The kept record takes the chosen values and combined lists.
	$values = array();
	foreach ( (array) $choices as $field_key => $choice ) {
		if ( 'merge' === $choice && isset( $dataset['fields'][ $field_key ] ) && ! $dataset['fields'][ $field_key ]['derived'] ) {
			$values[ $field_key ] = $merged[ $field_key ];
		}
	}
	$title_field = $dataset['title_field'];
	$new_title   = array_key_exists( $title_field, $values ) ? (string) $values[ $title_field ] : (string) $kept[ $title_field ];
	foreach ( $dataset['merge_combine'] as $field_key ) {
		if ( ! isset( $dataset['fields'][ $field_key ] ) ) {
			continue;
		}
		$names = array( (string) $merged[ $field_key ] );
		// The name that is not kept becomes another name for the record.
		foreach ( array( (string) $kept[ $title_field ], (string) $merged[ $title_field ] ) as $name ) {
			if ( teatatu_data_fold_text( $name ) !== teatatu_data_fold_text( $new_title ) ) {
				$names[] = $name;
			}
		}
		$values[ $field_key ] = teatatu_data_merge_aliases( (string) $kept[ $field_key ], $names, $new_title );
	}
	$values['external_ids'] = $kept['external_ids'] + $merged['external_ids'];
	if ( $merged['verified_at'] && ( ! $kept['verified_at'] || $merged['verified_at'] > $kept['verified_at'] ) && ! isset( $choices['verified_at'] ) ) {
		$values['verified_at'] = $merged['verified_at'];
	}
	$step = teatatu_data_apply_local( $key, $kept['uuid'], $values, array( 'channel' => 'admin', 'reason' => $log ) );
	if ( is_wp_error( $step ) ) {
		// Put the merged record back as it was.
		teatatu_data_apply_local( $key, $merged['uuid'], array( 'status' => $merged['status'], 'replaced_by' => $merged['replaced_by'] ), array( 'channel' => 'admin', 'reason' => __( 'Merge undone: the kept record could not take the chosen values.', 'teatatu-data' ) ) );
		return $step;
	}

	// 3. Repoint every reference to the merged record.
	foreach ( teatatu_data_get_record_datasets() as $other_key => $other ) {
		$fields = array();
		foreach ( $other['fields'] as $field_key => $field ) {
			if ( 'reference' === $field['type'] && $field['references'] === $key && ! $field['derived'] ) {
				$fields[] = $field_key;
			}
		}
		if ( $other_key === $key ) {
			$fields[] = 'replaced_by';
		}
		if ( ! $fields ) {
			continue;
		}
		foreach ( teatatu_data_find_local( $other_key, array( 'status' => 'all', 'per_page' => -1 ) )['records'] as $record ) {
			if ( $record['uuid'] === $merged['uuid'] ) {
				continue;
			}
			foreach ( $fields as $field_key ) {
				if ( ! in_array( $merged['uuid'], (array) $record[ $field_key ], true ) ) {
					continue;
				}
				$step = teatatu_data_repoint_reference_local( $other_key, $record['uuid'], $field_key, $merged['uuid'], $kept['uuid'], $log );
				if ( is_wp_error( $step ) ) {
					$result['errors'][] = teatatu_data_record_title( $other_key, $record ) . ': ' . teatatu_data_error_text( $step );
				} elseif ( $step['changed'] ) {
					$result['repointed']++;
				}
			}
		}
	}

	/**
	 * Fires after two records were merged, for consumer plugins to follow.
	 *
	 * @param string $key    Data set key.
	 * @param string $keep   UUID kept.
	 * @param string $merged UUID merged into it (now deprecated).
	 */
	do_action( 'teatatu_data_records_merged', $key, $kept['uuid'], $merged['uuid'] );
	return $result;
}
