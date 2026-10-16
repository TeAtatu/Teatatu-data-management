<?php
/**
 * CSV and JSON export and import (SPEC.md §2, DMBOK Data Integration).
 *
 * Export writes every record of a data set, in every status, with its
 * standard fields. Import reads the same shape back: each row becomes a
 * *proposed* change through teatatu_data_propose() (so it is validated and
 * logged), all in one batch a steward can approve together. A steward may
 * tick "Apply now" to approve the batch straight away.
 *
 * Row convention: a column or key that is absent leaves the field
 * unchanged; a present but empty cell clears it. A row with a uuid that
 * exists updates that record; a row with an unknown uuid creates a record
 * with that uuid; a row without one creates a record with a new uuid.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Column order for a data set: uuid, status, schema fields, then the
 * writable standard fields.
 *
 * @param array $dataset Data set definition.
 * @return string[]
 */
function teatatu_data_export_columns( $dataset ) {
	return array_merge( array( 'uuid', 'status' ), array_keys( $dataset['fields'] ), array( 'replaced_by', 'external_ids', 'source', 'verified_at' ) );
}

/**
 * A value as one CSV cell.
 *
 * @param string $column Column key.
 * @param mixed  $value  Value.
 * @return string
 */
function teatatu_data_csv_cell( $column, $value ) {
	if ( teatatu_data_is_empty( $value ) ) {
		return '';
	}
	if ( 'external_ids' === $column ) {
		return wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
	if ( is_bool( $value ) ) {
		return $value ? '1' : '0';
	}
	if ( is_array( $value ) ) {
		return isset( $value['lat'], $value['lng'] ) ? $value['lat'] . ',' . $value['lng'] : implode( ';', $value );
	}
	return (string) $value;
}

add_action( 'admin_post_teatatu_data_export', 'teatatu_data_handle_export' );

/**
 * Streams an export file.
 */
function teatatu_data_handle_export() {
	check_admin_referer( 'teatatu_data_export' );
	if ( ! current_user_can( 'read_teatatu_data' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-data' ), 403 );
	}
	$key     = isset( $_REQUEST['dataset'] ) ? sanitize_key( wp_unslash( $_REQUEST['dataset'] ) ) : '';
	$format  = isset( $_REQUEST['format'] ) && 'csv' === $_REQUEST['format'] ? 'csv' : 'json';
	$dataset = teatatu_data_get_dataset( $key );
	if ( ! $dataset || 'metadata' === $dataset['kind'] ) {
		wp_die( esc_html__( 'Unknown data set.', 'teatatu-data' ), 404 );
	}

	$result   = teatatu_data_find(
		$key,
		array(
			'status'   => 'all',
			'per_page' => -1,
		)
	);
	$columns  = teatatu_data_export_columns( $dataset );
	$filename = sprintf( 'teatatu-data-%s-%s.%s', $key, gmdate( 'Ymd-His' ), $format );

	nocache_headers();
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

	if ( 'json' === $format ) {
		header( 'Content-Type: application/json; charset=utf-8' );
		$records = array();
		foreach ( $result['records'] as $record ) {
			$records[] = array_intersect_key( $record, array_flip( $columns ) );
		}
		echo wp_json_encode(
			array(
				'dataset'     => $key,
				'exported_at' => gmdate( 'c' ),
				'plugin'      => 'teatatu-data ' . TEATATU_DATA_VERSION,
				'records'     => $records,
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		exit;
	}

	header( 'Content-Type: text/csv; charset=utf-8' );
	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM so spreadsheet apps read macrons correctly.
	fputcsv( $out, $columns, ',', '"', '\\' );
	foreach ( $result['records'] as $record ) {
		$row = array();
		foreach ( $columns as $column ) {
			$row[] = teatatu_data_csv_cell( $column, isset( $record[ $column ] ) ? $record[ $column ] : null );
		}
		fputcsv( $out, $row, ',', '"', '\\' );
	}
	fclose( $out );
	exit;
}

/**
 * Reads an import file into rows of column => value.
 *
 * @param string $path      File path.
 * @param string $extension 'csv' or 'json'.
 * @param array  $dataset   Data set definition.
 * @return array|WP_Error Rows.
 */
function teatatu_data_read_import_file( $path, $extension, $dataset ) {
	$allowed = teatatu_data_export_columns( $dataset );

	if ( 'json' === $extension ) {
		$data = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'teatatu_data_import', __( 'The file is not valid JSON.', 'teatatu-data' ) );
		}
		if ( isset( $data['dataset'] ) && $data['dataset'] !== $dataset['key'] ) {
			/* translators: %s: data set key in the file */
			return new WP_Error( 'teatatu_data_import', sprintf( __( 'The file is an export of the "%s" data set.', 'teatatu-data' ), $data['dataset'] ) );
		}
		$rows = isset( $data['records'] ) ? $data['records'] : $data;
		if ( ! is_array( $rows ) || ( $rows && ! wp_is_numeric_array( $rows ) ) ) {
			return new WP_Error( 'teatatu_data_import', __( 'Expected a list of records.', 'teatatu-data' ) );
		}
		foreach ( $rows as $index => $row ) {
			if ( ! is_array( $row ) ) {
				/* translators: %d: record number */
				return new WP_Error( 'teatatu_data_import', sprintf( __( 'Record %d is not an object.', 'teatatu-data' ), $index + 1 ) );
			}
			$unknown = array_diff( array_keys( $row ), $allowed );
			if ( $unknown ) {
				/* translators: %s: unknown keys */
				return new WP_Error( 'teatatu_data_import', sprintf( __( 'Unknown fields: %s.', 'teatatu-data' ), implode( ', ', $unknown ) ) );
			}
		}
		return $rows;
	}

	$handle = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( ! $handle ) {
		return new WP_Error( 'teatatu_data_import', __( 'The file could not be read.', 'teatatu-data' ) );
	}
	$header = fgetcsv( $handle, 0, ',', '"', '\\' );
	if ( ! $header ) {
		fclose( $handle );
		return new WP_Error( 'teatatu_data_import', __( 'The file is empty.', 'teatatu-data' ) );
	}
	$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );
	$header    = array_map( 'trim', $header );
	$unknown   = array_diff( $header, $allowed );
	if ( $unknown ) {
		fclose( $handle );
		/* translators: %s: unknown columns */
		return new WP_Error( 'teatatu_data_import', sprintf( __( 'Unknown columns: %s.', 'teatatu-data' ), implode( ', ', $unknown ) ) );
	}
	$rows = array();
	while ( false !== ( $cells = fgetcsv( $handle, 0, ',', '"', '\\' ) ) ) {
		if ( array( null ) === $cells ) {
			continue; // Blank line.
		}
		$row = array();
		foreach ( $header as $index => $column ) {
			$row[ $column ] = isset( $cells[ $index ] ) ? $cells[ $index ] : '';
		}
		$rows[] = $row;
	}
	fclose( $handle );
	return $rows;
}

/**
 * Proposes every row of an import as one batch.
 *
 * @param string $key      Data set key.
 * @param array  $rows     Rows of column => value.
 * @param string $filename Original file name (for the reason).
 * @return array {batch, proposed, unchanged, errors: [row => message]}
 */
function teatatu_data_import_rows( $key, $rows, $filename ) {
	$batch  = 'import-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false );
	$result = array(
		'batch'     => $batch,
		'proposed'  => 0,
		'unchanged' => 0,
		'errors'    => array(),
	);
	foreach ( $rows as $index => $row ) {
		$number = $index + 1;
		$uuid   = isset( $row['uuid'] ) ? strtolower( trim( (string) $row['uuid'] ) ) : '';
		unset( $row['uuid'] );
		// Derived columns are in exports for reference; the plugin works them out again.
		foreach ( teatatu_data_get_dataset( $key )['fields'] as $field_key => $field ) {
			if ( $field['derived'] ) {
				unset( $row[ $field_key ] );
			}
		}
		if ( '' !== $uuid && ! teatatu_data_is_uuid( $uuid ) ) {
			$result['errors'][ $number ] = __( 'uuid: Must be a record UUID.', 'teatatu-data' );
			continue;
		}
		$exists = '' !== $uuid && teatatu_data_get( $key, $uuid );
		if ( isset( $row['status'] ) && '' === $row['status'] ) {
			unset( $row['status'] ); // A blank status keeps the current one (or "active" for new records).
		}
		$change_id = teatatu_data_propose(
			$key,
			$exists ? $uuid : null,
			$row,
			array(
				'channel'  => 'import',
				/* translators: 1: file name, 2: row number */
				'reason'   => sprintf( __( 'Import of %1$s, row %2$d.', 'teatatu-data' ), $filename, $number ),
				'batch'    => $batch,
				'new_uuid' => $exists ? '' : $uuid,
			)
		);
		if ( is_wp_error( $change_id ) ) {
			if ( 'teatatu_data_no_change' === $change_id->get_error_code() ) {
				$result['unchanged']++;
				continue;
			}
			$data    = $change_id->get_error_data();
			$message = $change_id->get_error_message();
			if ( ! empty( $data['fields'] ) ) {
				$parts = array();
				foreach ( $data['fields'] as $field => $field_message ) {
					$parts[] = $field . ': ' . $field_message;
				}
				$message = implode( ' ', $parts );
			}
			$result['errors'][ $number ] = $message;
			continue;
		}
		$result['proposed']++;
	}
	return $result;
}

add_action( 'admin_post_teatatu_data_import', 'teatatu_data_handle_import' );

/**
 * Handles the import form.
 */
function teatatu_data_handle_import() {
	check_admin_referer( 'teatatu_data_import' );
	if ( ! current_user_can( 'import_teatatu_data' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-data' ), 403 );
	}
	$redirect = teatatu_data_admin_url( array( 'tab' => 'transfer' ) );
	if ( ! teatatu_data_is_master_site() ) {
		wp_die( esc_html__( 'Imports are made on the Master Site.', 'teatatu-data' ), 409 );
	}

	$key     = isset( $_POST['dataset'] ) ? sanitize_key( wp_unslash( $_POST['dataset'] ) ) : '';
	$dataset = teatatu_data_get_dataset( $key );
	$file    = isset( $_FILES['import_file'] ) ? $_FILES['import_file'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$outcome = array( 'dataset' => $key );

	if ( ! $dataset || 'metadata' === $dataset['kind'] ) {
		$outcome['error'] = __( 'Choose a data set.', 'teatatu-data' );
	} elseif ( ! $file || UPLOAD_ERR_OK !== $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
		$outcome['error'] = __( 'The file did not upload.', 'teatatu-data' );
	} elseif ( $file['size'] > 5 * MB_IN_BYTES ) {
		$outcome['error'] = __( 'The file is larger than 5 MB.', 'teatatu-data' );
	} else {
		$filename  = sanitize_file_name( wp_unslash( $file['name'] ) );
		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, array( 'csv', 'json' ), true ) ) {
			$outcome['error'] = __( 'The file must be .csv or .json.', 'teatatu-data' );
		} else {
			$rows = teatatu_data_read_import_file( $file['tmp_name'], $extension, $dataset );
			if ( is_wp_error( $rows ) ) {
				$outcome['error'] = $rows->get_error_message();
			} else {
				$outcome = array_merge( $outcome, teatatu_data_import_rows( $key, $rows, $filename ) );
				if ( ! empty( $_POST['apply_now'] ) && current_user_can( 'approve_teatatu_data' ) && $outcome['proposed'] ) {
					$outcome = array_merge( $outcome, teatatu_data_approve_batch( $outcome['batch'] ) );
				}
			}
		}
	}

	set_transient( 'teatatu_data_import_result_' . get_current_user_id(), $outcome, 30 * MINUTE_IN_SECONDS );
	wp_safe_redirect( $redirect );
	exit;
}

/**
 * Approves every pending change in a batch.
 *
 * @param string $batch Batch ID.
 * @return array {applied: int, failed: [change ID => message]}
 */
function teatatu_data_approve_batch( $batch ) {
	$result  = array(
		'applied' => 0,
		'failed'  => array(),
	);
	$changes = teatatu_data_get_changes(
		array(
			'batch'    => $batch,
			'state'    => 'pending',
			'per_page' => -1,
		)
	);
	// Oldest first, so rows apply in file order.
	foreach ( array_reverse( $changes['changes'] ) as $change ) {
		$applied = teatatu_data_approve_change( $change['id'] );
		if ( is_wp_error( $applied ) ) {
			$result['failed'][ $change['id'] ] = $applied->get_error_message();
		} else {
			$result['applied']++;
		}
	}
	return $result;
}
