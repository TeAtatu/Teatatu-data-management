<?php
/**
 * Data set registry: the single place every master or reference data set
 * declares its metadata and schema. The data dictionary, validation,
 * import/export, quality checks and REST all read from here, so a data set
 * is described once (SPEC.md §4).
 *
 * Data set modules add themselves through the `teatatu_data_datasets`
 * filter (SPEC.md §10 phases 3–6).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Field types a schema may use.
 *
 * @return string[]
 */
function teatatu_data_field_types() {
	return array( 'string', 'text', 'integer', 'number', 'boolean', 'date', 'url', 'email', 'phone', 'enum', 'reference', 'geo' );
}

/**
 * Every registered data set, keyed by data set key, with defaults filled in.
 *
 * A data set definition:
 * - label           (string)   Human name, e.g. "Venues".
 * - description     (string)   Business definition: what it is and what it is for.
 * - kind            (string)   'master' (entities), 'reference' (controlled
 *                              vocabularies) or 'metadata' (structure-only
 *                              entries such as Users, which hold no records).
 * - owner           (string)   Default role of the accountable data owner,
 *                              shown until a named user is chosen.
 * - steward         (string)   Default role of the day-to-day data steward.
 * - post_type       (string)   Private post type holding the records (max 20
 *                              characters; default 'ttd_' . key).
 * - title_field     (string)   Field used as the record's display name (default 'name').
 * - review_interval (int)      Months before a record must be re-verified (default 12).
 * - source          (string)   System of record / external source, if any.
 * - consumers       (string[]) Plugins that read it, e.g. teatatu-events.
 * - fields          (array)    Field key => definition (see teatatu_data_normalize_field()).
 * - rules           (string[]) Plain-language rules that involve several fields,
 *                              shown in the dictionary.
 * - validate        (callable) Checks those rules: ( $merged, $clean, $current )
 *                              => field => message. $merged is the record as it
 *                              would be after the change.
 * - list_columns    (string[]) Fields shown in the admin list (default: the
 *                              title field and the next three short fields).
 * - quality_checks  (callable) Data-set-specific quality checks: ( $records,
 *                              $dataset ) => issues (see includes/quality.php).
 * - duplicate_keys  (callable) ( $record ) => string[]: records sharing a key
 *                              are listed as possible duplicates. Default: the
 *                              title, ignoring case, accents and punctuation.
 * - mergeable       (bool)     Whether records can be merged (default true).
 * - merge_combine   (string[]) Comma-list fields whose values are combined,
 *                              not chosen, when records merge (e.g. aliases).
 * - derive          (callable) Computes the `derived` fields: ( $merged ) =>
 *                              field => value. Runs on every write, after
 *                              validation, so derived values are logged too.
 *
 * @return array
 */
function teatatu_data_get_datasets() {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}

	/**
	 * Filters the registered data sets. Each data set module adds itself here.
	 *
	 * @param array $datasets Data set key => definition.
	 */
	$raw      = apply_filters( 'teatatu_data_datasets', array() );
	$datasets = array();
	foreach ( (array) $raw as $key => $definition ) {
		$dataset = teatatu_data_normalize_dataset( $key, $definition );
		if ( $dataset ) {
			$datasets[ $key ] = $dataset;
		}
	}

	// Data sets register on init; only cache once registration is complete.
	if ( did_action( 'init' ) ) {
		$cache = $datasets;
	}
	return $datasets;
}

/**
 * One registered data set.
 *
 * @param string $key Data set key.
 * @return array|null
 */
function teatatu_data_get_dataset( $key ) {
	$datasets = teatatu_data_get_datasets();
	return isset( $datasets[ $key ] ) ? $datasets[ $key ] : null;
}

/**
 * Registered data sets that hold records (master and reference, not metadata).
 *
 * @return array
 */
function teatatu_data_get_record_datasets() {
	return array_filter(
		teatatu_data_get_datasets(),
		function ( $dataset ) {
			return 'metadata' !== $dataset['kind'];
		}
	);
}

/**
 * Fills in defaults for a data set definition and checks its identity.
 *
 * @param string $key        Data set key.
 * @param array  $definition Definition as registered.
 * @return array|null Null when the definition is unusable.
 */
function teatatu_data_normalize_dataset( $key, $definition ) {
	if ( ! is_string( $key ) || ! preg_match( '/^[a-z][a-z0-9_]{0,31}$/', $key ) || ! is_array( $definition ) ) {
		_doing_it_wrong( __FUNCTION__, esc_html( sprintf( 'Teatatu Data: data set key "%s" must be lower-case letters, digits and underscores.', (string) $key ) ), '0.2.0' );
		return null;
	}

	$dataset = wp_parse_args(
		$definition,
		array(
			'label'           => $key,
			'description'     => '',
			'kind'            => 'master',
			'owner'           => '',
			'steward'         => '',
			'post_type'       => 'ttd_' . substr( $key, 0, 16 ),
			'title_field'     => 'name',
			'review_interval' => 12,
			'source'          => '',
			'consumers'       => array(),
			'fields'          => array(),
			'rules'           => array(),
			'validate'        => null,
			'list_columns'    => array(),
			'derive'          => null,
			'quality_checks'  => null,
			'duplicate_keys'  => null,
			'mergeable'       => true,
			'merge_combine'   => array(),
		)
	);

	$dataset['key']             = $key;
	$dataset['kind']            = in_array( $dataset['kind'], array( 'master', 'reference', 'metadata' ), true ) ? $dataset['kind'] : 'master';
	$dataset['review_interval'] = max( 1, (int) $dataset['review_interval'] );
	$dataset['consumers']       = array_values( (array) $dataset['consumers'] );
	$dataset['rules']           = array_values( (array) $dataset['rules'] );
	$dataset['validate']        = is_callable( $dataset['validate'] ) ? $dataset['validate'] : null;
	$dataset['derive']          = is_callable( $dataset['derive'] ) ? $dataset['derive'] : null;
	$dataset['quality_checks']  = is_callable( $dataset['quality_checks'] ) ? $dataset['quality_checks'] : null;
	$dataset['duplicate_keys']  = is_callable( $dataset['duplicate_keys'] ) ? $dataset['duplicate_keys'] : null;
	$dataset['mergeable']       = (bool) $dataset['mergeable'];
	$dataset['merge_combine']   = array_values( (array) $dataset['merge_combine'] );

	if ( strlen( $dataset['post_type'] ) > 20 ) {
		_doing_it_wrong( __FUNCTION__, esc_html( sprintf( 'Teatatu Data: post type "%s" is longer than 20 characters.', $dataset['post_type'] ) ), '0.2.0' );
		return null;
	}

	$fields = array();
	foreach ( (array) $dataset['fields'] as $field_key => $field ) {
		if ( ! preg_match( '/^[a-z][a-z0-9_]{0,47}$/', (string) $field_key ) || isset( teatatu_data_standard_fields()[ $field_key ] ) ) {
			_doing_it_wrong( __FUNCTION__, esc_html( sprintf( 'Teatatu Data: field "%s" in data set "%s" has an invalid or reserved key.', (string) $field_key, $key ) ), '0.2.0' );
			continue;
		}
		$fields[ $field_key ] = teatatu_data_normalize_field( $field_key, (array) $field );
	}
	$dataset['fields'] = $fields;

	$dataset['list_columns'] = array_values( array_intersect( (array) $dataset['list_columns'], array_keys( $fields ) ) );

	if ( 'metadata' !== $dataset['kind'] && ! isset( $fields[ $dataset['title_field'] ] ) ) {
		_doing_it_wrong( __FUNCTION__, esc_html( sprintf( 'Teatatu Data: data set "%s" has no title field "%s".', $key, $dataset['title_field'] ) ), '0.2.0' );
		return null;
	}

	return $dataset;
}

/**
 * Fills in defaults for a field definition.
 *
 * - label, description, example (string)
 * - type        (string)  One of teatatu_data_field_types().
 * - required    (bool)    Must have a value.
 * - unique      (bool)    No two non-retired records may share the value.
 * - enum        (array)   Allowed values (type enum): a list, or value => label.
 * - references  (string)  Data set key the value points to (type reference).
 * - multiple    (bool)    A list of references rather than one (type reference).
 * - max_length  (int)     Maximum characters (string, text). 0 = type default.
 * - pattern     (string)  Regular expression, without delimiters (string).
 * - min, max    (number)  Range (integer, number).
 * - default     (mixed)   Value used when a record is created without one.
 * - case        (string)  'upper' or 'lower': applied to string values.
 * - recommended (bool)    Not required, but its absence is a completeness issue.
 * - derived     (bool)    Computed by the data set's `derive` callback; never
 *                         set by people, imports or agents.
 *
 * @param string $field_key Field key.
 * @param array  $field     Definition as registered.
 * @return array
 */
function teatatu_data_normalize_field( $field_key, $field ) {
	$field = wp_parse_args(
		$field,
		array(
			'label'       => $field_key,
			'type'        => 'string',
			'required'    => false,
			'unique'      => false,
			'description' => '',
			'example'     => '',
			'enum'        => array(),
			'references'  => '',
			'multiple'    => false,
			'max_length'  => 0,
			'pattern'     => '',
			'min'         => null,
			'max'         => null,
			'default'     => null,
			'case'        => '',
			'derived'     => false,
			'recommended' => false,
		)
	);
	if ( ! in_array( $field['type'], teatatu_data_field_types(), true ) ) {
		$field['type'] = 'string';
	}
	// Enum: accept a plain list or value => label, store value => label.
	$enum = array();
	foreach ( (array) $field['enum'] as $value => $label ) {
		if ( is_int( $value ) ) {
			$enum[ (string) $label ] = (string) $label;
		} else {
			$enum[ (string) $value ] = (string) $label;
		}
	}
	$field['enum']     = $enum;
	$field['required'] = (bool) $field['required'];
	$field['unique']   = (bool) $field['unique'];
	$field['multiple'] = (bool) $field['multiple'];
	$field['derived']  = (bool) $field['derived'];
	$field['recommended'] = (bool) $field['recommended'];
	if ( ! $field['max_length'] ) {
		$field['max_length'] = 'text' === $field['type'] ? 5000 : 200;
	}
	$field['key'] = $field_key;
	return $field;
}

/**
 * Fields every record carries, whatever its data set (SPEC.md §4).
 * `writable` fields can be set through the write path; the rest are kept
 * by the plugin.
 *
 * @return array
 */
function teatatu_data_standard_fields() {
	return array(
		'uuid'         => array( 'label' => __( 'UUID', 'teatatu-data' ), 'type' => 'string', 'writable' => false, 'description' => __( 'Stable identifier, never reused, the same on every site. Other plugins store this.', 'teatatu-data' ) ),
		'status'       => array( 'label' => __( 'Status', 'teatatu-data' ), 'type' => 'enum', 'writable' => true, 'description' => __( 'Lifecycle: proposed, active, deprecated or retired.', 'teatatu-data' ) ),
		'replaced_by'  => array( 'label' => __( 'Replaced by', 'teatatu-data' ), 'type' => 'reference', 'writable' => true, 'description' => __( 'For a merged or deprecated record, the UUID of the record that replaces it.', 'teatatu-data' ) ),
		'external_ids' => array( 'label' => __( 'External IDs', 'teatatu-data' ), 'type' => 'map', 'writable' => true, 'description' => __( 'Keys in external systems, e.g. osm, linz, nzbn, charity.', 'teatatu-data' ) ),
		'source'       => array( 'label' => __( 'Source', 'teatatu-data' ), 'type' => 'string', 'writable' => true, 'description' => __( 'Where the record came from.', 'teatatu-data' ) ),
		'verified_at'  => array( 'label' => __( 'Verified', 'teatatu-data' ), 'type' => 'date', 'writable' => true, 'description' => __( 'When a steward last confirmed the record is correct.', 'teatatu-data' ) ),
		'created_at'   => array( 'label' => __( 'Created', 'teatatu-data' ), 'type' => 'date', 'writable' => false, 'description' => __( 'When the record was created (UTC).', 'teatatu-data' ) ),
		'created_by'   => array( 'label' => __( 'Created by', 'teatatu-data' ), 'type' => 'integer', 'writable' => false, 'description' => __( 'User who created the record.', 'teatatu-data' ) ),
		'updated_at'   => array( 'label' => __( 'Updated', 'teatatu-data' ), 'type' => 'date', 'writable' => false, 'description' => __( 'When the record last changed (UTC).', 'teatatu-data' ) ),
		'updated_by'   => array( 'label' => __( 'Updated by', 'teatatu-data' ), 'type' => 'integer', 'writable' => false, 'description' => __( 'User who last changed the record.', 'teatatu-data' ) ),
	);
}

/**
 * Lifecycle statuses: status => post status and label.
 *
 * @return array
 */
function teatatu_data_lifecycle_statuses() {
	return array(
		'proposed'   => array( 'post_status' => 'ttd_proposed', 'label' => __( 'Proposed', 'teatatu-data' ) ),
		'active'     => array( 'post_status' => 'ttd_active', 'label' => __( 'Active', 'teatatu-data' ) ),
		'deprecated' => array( 'post_status' => 'ttd_deprecated', 'label' => __( 'Deprecated', 'teatatu-data' ) ),
		'retired'    => array( 'post_status' => 'ttd_retired', 'label' => __( 'Retired', 'teatatu-data' ) ),
	);
}

/**
 * Per-data-set governance settings (named owner and steward, review
 * interval), stored network-wide in `teatatu_data_dataset_settings`.
 *
 * @param string $key Data set key.
 * @return array {owner_id, steward_id, review_interval}
 */
function teatatu_data_dataset_settings( $key ) {
	$all     = teatatu_data_get_option( 'teatatu_data_dataset_settings', array() );
	$stored  = isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? $all[ $key ] : array();
	$dataset = teatatu_data_get_dataset( $key );
	return array(
		'owner_id'        => isset( $stored['owner_id'] ) ? (int) $stored['owner_id'] : 0,
		'steward_id'      => isset( $stored['steward_id'] ) ? (int) $stored['steward_id'] : 0,
		'review_interval' => ! empty( $stored['review_interval'] ) ? (int) $stored['review_interval'] : ( $dataset ? $dataset['review_interval'] : 12 ),
	);
}

/**
 * The data dictionary: every data set with its governance, lineage and
 * field definitions, plus the standard fields. Shared by the admin screen
 * and the REST route so both describe the data identically.
 *
 * @return array
 */
function teatatu_data_get_dictionary() {
	$person = function ( $user_id, $default_role ) {
		$user = $user_id ? get_userdata( $user_id ) : false;
		return array(
			'user_id'      => $user ? (int) $user->ID : 0,
			'name'         => $user ? $user->display_name : '',
			'default_role' => $default_role,
		);
	};

	$datasets = array();
	foreach ( teatatu_data_get_datasets() as $key => $dataset ) {
		$settings = teatatu_data_dataset_settings( $key );
		$fields   = array();
		foreach ( $dataset['fields'] as $field_key => $field ) {
			$fields[ $field_key ] = array(
				'label'       => $field['label'],
				'type'        => $field['type'],
				'required'    => $field['required'],
				'unique'      => $field['unique'],
				'description' => $field['description'],
				'example'     => $field['example'],
				'enum'        => $field['enum'] ? $field['enum'] : null,
				'references'  => $field['references'] ? $field['references'] : null,
				'multiple'    => $field['multiple'],
				'max_length'  => in_array( $field['type'], array( 'string', 'text' ), true ) ? $field['max_length'] : null,
				'pattern'     => $field['pattern'] ? $field['pattern'] : null,
				'min'         => $field['min'],
				'max'         => $field['max'],
				'default'     => $field['default'],
				'derived'     => $field['derived'],
				'recommended' => $field['recommended'],
			);
		}
		$datasets[ $key ] = array(
			'key'             => $key,
			'label'           => $dataset['label'],
			'description'     => $dataset['description'],
			'kind'            => $dataset['kind'],
			'owner'           => $person( $settings['owner_id'], $dataset['owner'] ),
			'steward'         => $person( $settings['steward_id'], $dataset['steward'] ),
			'review_interval' => $settings['review_interval'],
			'title_field'     => 'metadata' === $dataset['kind'] ? null : $dataset['title_field'],
			'source'          => $dataset['source'],
			'consumers'       => $dataset['consumers'],
			'rules'           => $dataset['rules'],
			'fields'          => $fields,
		);
	}

	$standard = array();
	foreach ( teatatu_data_standard_fields() as $key => $field ) {
		$standard[ $key ] = array(
			'label'       => $field['label'],
			'type'        => $field['type'],
			'writable'    => $field['writable'],
			'description' => $field['description'],
		);
	}
	$standard['status']['enum'] = array_map(
		function ( $status ) {
			return $status['label'];
		},
		teatatu_data_lifecycle_statuses()
	);

	return array(
		'version'         => TEATATU_DATA_VERSION,
		'standard_fields' => $standard,
		'datasets'        => $datasets,
	);
}
