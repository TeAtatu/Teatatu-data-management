<?php
/**
 * The one validator. Every write path — admin forms, imports, REST,
 * migrations, external connectors — passes its values through
 * teatatu_data_validate() before anything is stored or proposed
 * (SPEC.md §4). It checks the values against the data set's registered
 * schema and returns them cleaned and typed.
 *
 * Values convention: a key that is absent is left unchanged; a key set to
 * null, '' or an empty array clears the field.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates and cleans values for a record. Call on the Master Site (the
 * write path already runs there), since references and uniqueness are
 * checked against the stored records.
 *
 * @param string     $key     Data set key.
 * @param array      $values  Field => raw value. May include the writable
 *                            standard fields (status, replaced_by,
 *                            external_ids, source, verified_at).
 * @param array|null $current The record as it is now, or null when creating.
 * @return array|WP_Error Cleaned values (only the keys given), or an error
 *                        whose data holds 'fields' => field => message.
 */
function teatatu_data_validate( $key, $values, $current = null ) {
	$dataset = teatatu_data_get_dataset( $key );
	if ( ! $dataset || 'metadata' === $dataset['kind'] ) {
		return new WP_Error( 'teatatu_data_unknown_dataset', __( 'Unknown data set.', 'teatatu-data' ), array( 'status' => 404 ) );
	}
	if ( ! is_array( $values ) ) {
		return new WP_Error( 'teatatu_data_invalid', __( 'Values must be an object of field => value.', 'teatatu-data' ), array( 'status' => 400 ) );
	}

	$standard = teatatu_data_standard_fields();
	$errors   = array();
	$clean    = array();

	// Defaults for a new record.
	if ( null === $current ) {
		foreach ( $dataset['fields'] as $field_key => $field ) {
			if ( null !== $field['default'] && ( ! array_key_exists( $field_key, $values ) || teatatu_data_is_empty( $values[ $field_key ] ) ) ) {
				$values[ $field_key ] = $field['default'];
			}
		}
	}

	foreach ( $values as $field_key => $raw ) {
		if ( isset( $dataset['fields'][ $field_key ] ) && $dataset['fields'][ $field_key ]['derived'] ) {
			$errors[ $field_key ] = __( 'This field is worked out by the plugin and cannot be set.', 'teatatu-data' );
			continue;
		}
		if ( isset( $dataset['fields'][ $field_key ] ) ) {
			$result = teatatu_data_clean_value( $dataset['fields'][ $field_key ], $raw );
		} elseif ( isset( $standard[ $field_key ] ) && $standard[ $field_key ]['writable'] ) {
			$result = teatatu_data_clean_standard_value( $field_key, $raw );
		} elseif ( isset( $standard[ $field_key ] ) ) {
			$errors[ $field_key ] = __( 'This field is kept by the plugin and cannot be set.', 'teatatu-data' );
			continue;
		} else {
			$errors[ $field_key ] = __( 'Unknown field.', 'teatatu-data' );
			continue;
		}
		if ( is_wp_error( $result ) ) {
			$errors[ $field_key ] = $result->get_error_message();
		} else {
			$clean[ $field_key ] = $result;
		}
	}

	// Required: on create every required field needs a value; on update a
	// required field may not be cleared.
	foreach ( $dataset['fields'] as $field_key => $field ) {
		if ( ! $field['required'] || isset( $errors[ $field_key ] ) ) {
			continue;
		}
		$given = array_key_exists( $field_key, $clean );
		if ( ( null === $current && ( ! $given || teatatu_data_is_empty( $clean[ $field_key ] ) ) )
			|| ( null !== $current && $given && teatatu_data_is_empty( $clean[ $field_key ] ) ) ) {
			$errors[ $field_key ] = __( 'Required.', 'teatatu-data' );
		}
	}

	$own_uuid = $current ? $current['uuid'] : '';

	// References must point to records that exist and are not retired.
	// Only new or changed references are checked, so a record that points
	// at a since-retired record can still be edited (quality checks flag it).
	foreach ( $clean as $field_key => $value ) {
		if ( teatatu_data_is_empty( $value ) ) {
			continue;
		}
		if ( $current && array_key_exists( $field_key, $current ) && wp_json_encode( $current[ $field_key ] ) === wp_json_encode( $value ) ) {
			continue;
		}
		if ( 'replaced_by' === $field_key ) {
			$target = $key;
		} elseif ( isset( $dataset['fields'][ $field_key ] ) && 'reference' === $dataset['fields'][ $field_key ]['type'] ) {
			$target = $dataset['fields'][ $field_key ]['references'];
		} else {
			continue;
		}
		if ( ! teatatu_data_get_dataset( $target ) ) {
			$errors[ $field_key ] = __( 'This field refers to a data set that is not registered.', 'teatatu-data' );
			continue;
		}
		foreach ( (array) $value as $uuid ) {
			if ( $uuid === $own_uuid ) {
				$errors[ $field_key ] = __( 'A record cannot refer to itself.', 'teatatu-data' );
				break;
			}
			$referenced = teatatu_data_get_local( $target, $uuid );
			if ( ! $referenced ) {
				/* translators: %s: UUID */
				$errors[ $field_key ] = sprintf( __( 'No record with UUID %s.', 'teatatu-data' ), $uuid );
				break;
			}
			if ( 'retired' === $referenced['status'] ) {
				/* translators: %s: record name */
				$errors[ $field_key ] = sprintf( __( '"%s" is retired and cannot be referred to.', 'teatatu-data' ), teatatu_data_record_title( $target, $referenced ) );
				break;
			}
		}
	}

	// Unique fields: no other non-retired record may hold the same value.
	foreach ( $dataset['fields'] as $field_key => $field ) {
		if ( ! $field['unique'] || ! isset( $clean[ $field_key ] ) || teatatu_data_is_empty( $clean[ $field_key ] ) || isset( $errors[ $field_key ] ) ) {
			continue;
		}
		$clash = get_posts(
			array(
				'post_type'        => $dataset['post_type'],
				'post_status'      => array( 'ttd_proposed', 'ttd_active', 'ttd_deprecated' ),
				'meta_key'         => '_ttd_f_' . $field_key, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'       => is_bool( $clean[ $field_key ] ) ? (int) $clean[ $field_key ] : $clean[ $field_key ], // phpcs:ignore WordPress.DB.SlowDBQuery
				'numberposts'      => 2,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);
		foreach ( $clash as $post_id ) {
			// A record merged into this one has handed over its values.
			if ( $own_uuid && 'ttd_deprecated' === get_post_status( $post_id ) && get_post_meta( $post_id, '_ttd_replaced_by', true ) === $own_uuid ) {
				continue;
			}
			if ( get_post_meta( $post_id, '_ttd_uuid', true ) !== $own_uuid ) {
				$errors[ $field_key ] = __( 'Another record already has this value.', 'teatatu-data' );
				break;
			}
		}
	}

	// Rules across several fields, checked on the record as it would be.
	// A record being withdrawn (deprecated or retired) isn't held to them.
	$withdrawing = isset( $clean['status'] ) && in_array( $clean['status'], array( 'deprecated', 'retired' ), true );
	if ( ! $errors && $dataset['validate'] && ! $withdrawing ) {
		$merged = array_merge( $current ? $current : array(), $clean );
		$extra  = call_user_func( $dataset['validate'], $merged, $clean, $current );
		if ( is_array( $extra ) ) {
			$errors = $extra;
		}
	}

	if ( $errors ) {
		return new WP_Error(
			'teatatu_data_invalid',
			__( 'Some values are not valid.', 'teatatu-data' ),
			array(
				'status' => 400,
				'fields' => $errors,
			)
		);
	}
	return $clean;
}

/**
 * Whether a cleaned value counts as empty (cleared).
 *
 * @param mixed $value Value.
 * @return bool
 */
function teatatu_data_is_empty( $value ) {
	return null === $value || '' === $value || array() === $value;
}

/**
 * Cleans one schema field value.
 *
 * @param array $field Field definition.
 * @param mixed $raw   Raw value.
 * @return mixed|WP_Error Cleaned value (null = clear).
 */
function teatatu_data_clean_value( $field, $raw ) {
	if ( is_string( $raw ) ) {
		$raw = trim( $raw );
	}
	if ( teatatu_data_is_empty( $raw ) ) {
		return null;
	}
	$invalid = function ( $message ) {
		return new WP_Error( 'teatatu_data_invalid_value', $message );
	};

	switch ( $field['type'] ) {
		case 'string':
		case 'text':
			if ( ! is_scalar( $raw ) ) {
				return $invalid( __( 'Must be text.', 'teatatu-data' ) );
			}
			$value = 'text' === $field['type'] ? sanitize_textarea_field( (string) $raw ) : sanitize_text_field( (string) $raw );
			if ( 'upper' === $field['case'] ) {
				$value = mb_strtoupper( $value );
			} elseif ( 'lower' === $field['case'] ) {
				$value = mb_strtolower( $value );
			}
			if ( mb_strlen( $value ) > $field['max_length'] ) {
				/* translators: %d: maximum length */
				return $invalid( sprintf( __( 'At most %d characters.', 'teatatu-data' ), $field['max_length'] ) );
			}
			if ( $field['pattern'] && ! preg_match( '~^(?:' . str_replace( '~', '\~', $field['pattern'] ) . ')$~u', $value ) ) {
				return $invalid( __( 'Not in the expected format.', 'teatatu-data' ) );
			}
			return $value;

		case 'integer':
		case 'number':
			if ( ! is_numeric( $raw ) || ( 'integer' === $field['type'] && (string) (int) $raw !== (string) $raw && ! is_int( $raw ) ) ) {
				return $invalid( 'integer' === $field['type'] ? __( 'Must be a whole number.', 'teatatu-data' ) : __( 'Must be a number.', 'teatatu-data' ) );
			}
			$value = 'integer' === $field['type'] ? (int) $raw : (float) $raw;
			if ( ( null !== $field['min'] && $value < $field['min'] ) || ( null !== $field['max'] && $value > $field['max'] ) ) {
				/* translators: 1: minimum, 2: maximum */
				return $invalid( sprintf( __( 'Must be between %1$s and %2$s.', 'teatatu-data' ), null === $field['min'] ? '…' : $field['min'], null === $field['max'] ? '…' : $field['max'] ) );
			}
			return $value;

		case 'boolean':
			$value = is_bool( $raw ) ? $raw : filter_var( $raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
			if ( null === $value ) {
				return $invalid( __( 'Must be yes or no.', 'teatatu-data' ) );
			}
			return $value;

		case 'date':
			return teatatu_data_clean_date( $raw );

		case 'url':
			$value = is_string( $raw ) ? esc_url_raw( $raw, array( 'http', 'https' ) ) : '';
			if ( ! $value || ! filter_var( $value, FILTER_VALIDATE_URL ) ) {
				return $invalid( __( 'Must be a web address starting with http:// or https://.', 'teatatu-data' ) );
			}
			return $value;

		case 'email':
			$value = is_string( $raw ) ? sanitize_email( $raw ) : '';
			if ( ! $value || ! is_email( $value ) ) {
				return $invalid( __( 'Must be an email address.', 'teatatu-data' ) );
			}
			return $value;

		case 'phone':
			if ( ! is_scalar( $raw ) || ! preg_match( '/^\+?[0-9 ()\-]{5,20}$/', (string) $raw ) ) {
				return $invalid( __( 'Must be a phone number (digits, spaces, brackets, + and -).', 'teatatu-data' ) );
			}
			return (string) $raw;

		case 'enum':
			if ( ! is_scalar( $raw ) || ! isset( $field['enum'][ (string) $raw ] ) ) {
				/* translators: %s: allowed values */
				return $invalid( sprintf( __( 'Must be one of: %s.', 'teatatu-data' ), implode( ', ', array_keys( $field['enum'] ) ) ) );
			}
			return (string) $raw;

		case 'reference':
			$list = is_array( $raw ) ? $raw : preg_split( '/[;,]/', (string) $raw );
			$list = array_values( array_unique( array_filter( array_map( 'trim', array_map( 'strval', $list ) ) ) ) );
			foreach ( $list as $uuid ) {
				if ( ! teatatu_data_is_uuid( $uuid ) ) {
					return $invalid( __( 'Must be a record UUID.', 'teatatu-data' ) );
				}
			}
			$list = array_map( 'strtolower', $list );
			if ( ! $field['multiple'] ) {
				if ( count( $list ) > 1 ) {
					return $invalid( __( 'Only one record may be chosen.', 'teatatu-data' ) );
				}
				return $list ? $list[0] : null;
			}
			return $list ? $list : null;

		case 'geo':
			if ( is_string( $raw ) ) {
				$parts = array_map( 'trim', explode( ',', $raw ) );
				$raw   = 2 === count( $parts ) ? array( 'lat' => $parts[0], 'lng' => $parts[1] ) : array();
			}
			if ( ! is_array( $raw ) || ! isset( $raw['lat'], $raw['lng'] ) ) {
				return $invalid( __( 'Must be a latitude and longitude.', 'teatatu-data' ) );
			}
			if ( '' === trim( $raw['lat'] . $raw['lng'] ) ) {
				return null; // Both boxes left blank in a form.
			}
			if ( ! is_numeric( $raw['lat'] ) || ! is_numeric( $raw['lng'] ) || abs( (float) $raw['lat'] ) > 90 || abs( (float) $raw['lng'] ) > 180 ) {
				return $invalid( __( 'Latitude must be -90 to 90 and longitude -180 to 180.', 'teatatu-data' ) );
			}
			return array(
				'lat' => round( (float) $raw['lat'], 7 ),
				'lng' => round( (float) $raw['lng'], 7 ),
			);
	}
	return $invalid( __( 'Unknown field type.', 'teatatu-data' ) );
}

/**
 * Cleans a date (YYYY-MM-DD).
 *
 * @param mixed $raw Raw value.
 * @return string|null|WP_Error
 */
function teatatu_data_clean_date( $raw ) {
	if ( teatatu_data_is_empty( $raw ) ) {
		return null;
	}
	if ( ! is_string( $raw ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
		return new WP_Error( 'teatatu_data_invalid_value', __( 'Must be a date as YYYY-MM-DD.', 'teatatu-data' ) );
	}
	return $raw;
}

/**
 * Cleans one writable standard field value.
 *
 * @param string $field_key Standard field key.
 * @param mixed  $raw       Raw value.
 * @return mixed|WP_Error
 */
function teatatu_data_clean_standard_value( $field_key, $raw ) {
	if ( is_string( $raw ) ) {
		$raw = trim( $raw );
	}
	switch ( $field_key ) {
		case 'status':
			if ( ! is_string( $raw ) || ! isset( teatatu_data_lifecycle_statuses()[ $raw ] ) ) {
				return new WP_Error( 'teatatu_data_invalid_value', __( 'Must be proposed, active, deprecated or retired.', 'teatatu-data' ) );
			}
			return $raw;

		case 'replaced_by':
			if ( teatatu_data_is_empty( $raw ) ) {
				return null;
			}
			if ( ! teatatu_data_is_uuid( $raw ) ) {
				return new WP_Error( 'teatatu_data_invalid_value', __( 'Must be a record UUID.', 'teatatu-data' ) );
			}
			return strtolower( $raw );

		case 'external_ids':
			if ( teatatu_data_is_empty( $raw ) ) {
				return array();
			}
			if ( is_string( $raw ) ) {
				$decoded = json_decode( $raw, true );
				$raw     = is_array( $decoded ) ? $decoded : null;
			}
			if ( ! is_array( $raw ) ) {
				return new WP_Error( 'teatatu_data_invalid_value', __( 'Must be pairs of system => ID, e.g. {"osm":"123"}.', 'teatatu-data' ) );
			}
			$ids = array();
			foreach ( $raw as $system => $id ) {
				$system = sanitize_key( (string) $system );
				$id     = is_scalar( $id ) ? sanitize_text_field( (string) $id ) : '';
				if ( '' !== $system && '' !== $id ) {
					$ids[ $system ] = $id;
				}
			}
			ksort( $ids );
			return $ids;

		case 'source':
			if ( teatatu_data_is_empty( $raw ) ) {
				return null;
			}
			if ( ! is_scalar( $raw ) ) {
				return new WP_Error( 'teatatu_data_invalid_value', __( 'Must be text.', 'teatatu-data' ) );
			}
			return mb_substr( sanitize_text_field( (string) $raw ), 0, 200 );

		case 'verified_at':
			return teatatu_data_clean_date( $raw );
	}
	return new WP_Error( 'teatatu_data_invalid_value', __( 'Unknown field.', 'teatatu-data' ) );
}
