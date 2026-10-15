<?php
/**
 * Data set registry: the single place every master or reference data set
 * declares its metadata and schema. The data dictionary, validation,
 * import/export, quality checks and REST all read from here, so a data set
 * is described once (SPEC.md §4).
 *
 * No data sets are registered yet; they arrive one per phase (SPEC.md §10).
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
 * Every registered data set, keyed by data set key.
 *
 * Each data set is an array:
 * - label        (string)  Human name, e.g. "Venues".
 * - description  (string)  What the data set is and what it is for.
 * - kind         (string)  'master' (entities: venues, organisations) or
 *                          'reference' (controlled vocabularies: tags, neighbourhoods).
 * - owner        (string)  Accountable role or person (data owner).
 * - steward      (string)  Who maintains it day to day (data steward).
 * - source       (string)  System of record / external source, if any.
 * - consumers    (string[]) Plugins that read it, e.g. teatatu-events.
 * - fields       (array)   Field key => definition: label, type, required,
 *                          description, enum (for type enum), max_length,
 *                          pattern, references (for type reference), example.
 *
 * @return array
 */
function teatatu_data_get_datasets() {
	/**
	 * Filters the registered data sets. Each data set module adds itself here.
	 *
	 * @param array $datasets Data set key => definition.
	 */
	return apply_filters( 'teatatu_data_datasets', array() );
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
