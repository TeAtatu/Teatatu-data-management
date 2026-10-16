<?php
/**
 * Record storage and reads. Each data set's records are posts of its own
 * private post type on the Master Site: the title field is mirrored into
 * post_title (for search), every field is post meta (`_ttd_f_<field>`), and
 * the lifecycle status is a custom post status (SPEC.md §4, §11 Q2).
 *
 * Nothing here is public: the post types have no UI, no REST, no query vars
 * and no rewrites. Every read from another site goes through the Master
 * Site via teatatu_data_on_master().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', 'teatatu_data_register_storage', 20 );

/**
 * Registers the lifecycle post statuses, one private post type per data
 * set, and the change-log post type. Runs at init priority 20 so data set
 * modules registering on the filter at the default priority are included.
 */
function teatatu_data_register_storage() {
	foreach ( teatatu_data_lifecycle_statuses() as $status ) {
		register_post_status(
			$status['post_status'],
			array(
				'label'                     => $status['label'],
				'public'                    => false,
				'internal'                  => true,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => false,
				'show_in_admin_status_list' => false,
			)
		);
	}

	$private = array(
		'public'              => false,
		'publicly_queryable'  => false,
		'exclude_from_search' => true,
		'show_ui'             => false,
		'show_in_menu'        => false,
		'show_in_nav_menus'   => false,
		'show_in_admin_bar'   => false,
		'show_in_rest'        => false,
		'rewrite'             => false,
		'query_var'           => false,
		'can_export'          => false,
		'supports'            => array( 'title', 'author' ),
	);

	foreach ( teatatu_data_get_record_datasets() as $dataset ) {
		register_post_type( $dataset['post_type'], array_merge( $private, array( 'label' => $dataset['label'] ) ) );
	}

	register_post_type( 'teatatu_data_change', array_merge( $private, array( 'label' => __( 'Data changes', 'teatatu-data' ) ) ) );
}

/**
 * Post status for a lifecycle status.
 *
 * @param string $status Lifecycle status.
 * @return string
 */
function teatatu_data_post_status( $status ) {
	$statuses = teatatu_data_lifecycle_statuses();
	return isset( $statuses[ $status ] ) ? $statuses[ $status ]['post_status'] : 'ttd_active';
}

/**
 * Lifecycle status for a post status.
 *
 * @param string $post_status Post status.
 * @return string
 */
function teatatu_data_status_from_post_status( $post_status ) {
	foreach ( teatatu_data_lifecycle_statuses() as $status => $definition ) {
		if ( $definition['post_status'] === $post_status ) {
			return $status;
		}
	}
	return 'active';
}

/**
 * Every lifecycle post status.
 *
 * @return string[]
 */
function teatatu_data_all_post_statuses() {
	return wp_list_pluck( teatatu_data_lifecycle_statuses(), 'post_status' );
}

/**
 * Whether a string looks like a UUID.
 *
 * @param mixed $value Value.
 * @return bool
 */
function teatatu_data_is_uuid( $value ) {
	return is_string( $value ) && (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value );
}

/**
 * Post ID of a record on the current site (call on the Master Site).
 *
 * @param string $key  Data set key.
 * @param string $uuid Record UUID.
 * @return int 0 if not found.
 */
function teatatu_data_find_post_id( $key, $uuid ) {
	$dataset = teatatu_data_get_dataset( $key );
	if ( ! $dataset || ! teatatu_data_is_uuid( $uuid ) ) {
		return 0;
	}
	$ids = get_posts(
		array(
			'post_type'        => $dataset['post_type'],
			'post_status'      => teatatu_data_all_post_statuses(),
			'meta_key'         => '_ttd_uuid', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'       => strtolower( $uuid ), // phpcs:ignore WordPress.DB.SlowDBQuery
			'numberposts'      => 1,
			'fields'           => 'ids',
			'suppress_filters' => true,
		)
	);
	return $ids ? (int) $ids[0] : 0;
}

/**
 * Converts a stored meta value back to the field's type.
 *
 * @param array $field Field definition.
 * @param mixed $raw   Stored value.
 * @return mixed
 */
function teatatu_data_typed_value( $field, $raw ) {
	if ( '' === $raw || null === $raw ) {
		return null;
	}
	switch ( $field['type'] ) {
		case 'integer':
			return (int) $raw;
		case 'number':
			return (float) $raw;
		case 'boolean':
			return (bool) (int) $raw;
		case 'geo':
			return is_array( $raw ) ? array( 'lat' => (float) $raw['lat'], 'lng' => (float) $raw['lng'] ) : null;
		case 'reference':
			return $field['multiple'] ? array_values( (array) $raw ) : (string) $raw;
		default:
			return is_scalar( $raw ) ? (string) $raw : null;
	}
}

/**
 * Builds a record array from a post.
 *
 * @param WP_Post $post    Post.
 * @param array   $dataset Data set definition.
 * @return array
 */
function teatatu_data_record_from_post( $post, $dataset ) {
	$meta   = get_post_meta( $post->ID );
	$single = function ( $meta_key ) use ( $meta ) {
		return isset( $meta[ $meta_key ][0] ) ? maybe_unserialize( $meta[ $meta_key ][0] ) : null;
	};

	$record = array(
		'uuid'   => (string) $single( '_ttd_uuid' ),
		'status' => teatatu_data_status_from_post_status( $post->post_status ),
	);
	foreach ( $dataset['fields'] as $field_key => $field ) {
		$record[ $field_key ] = teatatu_data_typed_value( $field, $single( '_ttd_f_' . $field_key ) );
	}
	$external_ids = $single( '_ttd_external_ids' );

	$record['replaced_by']  = $single( '_ttd_replaced_by' ) ? (string) $single( '_ttd_replaced_by' ) : null;
	$record['external_ids'] = is_array( $external_ids ) ? $external_ids : array();
	$record['source']       = $single( '_ttd_source' ) ? (string) $single( '_ttd_source' ) : null;
	$record['verified_at']  = $single( '_ttd_verified_at' ) ? (string) $single( '_ttd_verified_at' ) : null;
	$record['created_at']   = mysql2date( 'c', $post->post_date_gmt, false );
	$record['created_by']   = (int) $post->post_author;
	$record['updated_at']   = mysql2date( 'c', $post->post_modified_gmt, false );
	$record['updated_by']   = (int) $single( '_ttd_updated_by' );
	return $record;
}

/**
 * One record, read from the Master Site.
 *
 * @param string $key  Data set key.
 * @param string $uuid Record UUID.
 * @return array|null
 */
function teatatu_data_get( $key, $uuid ) {
	return teatatu_data_on_master( 'teatatu_data_get_local', array( $key, $uuid ) );
}

/**
 * One record from the current site (call on the Master Site).
 *
 * @param string $key  Data set key.
 * @param string $uuid Record UUID.
 * @return array|null
 */
function teatatu_data_get_local( $key, $uuid ) {
	$dataset = teatatu_data_get_dataset( $key );
	$post_id = teatatu_data_find_post_id( $key, $uuid );
	if ( ! $dataset || ! $post_id ) {
		return null;
	}
	return teatatu_data_record_from_post( get_post( $post_id ), $dataset );
}

/**
 * Searches a data set, read from the Master Site.
 *
 * @param string $key  Data set key.
 * @param array  $args {
 *     @type string          $search   Words to match in the title field.
 *     @type string|string[] $status   Lifecycle status(es), or 'all'. Default 'active'.
 *     @type array           $where    Field => exact value.
 *     @type int             $per_page Default 50; -1 for all.
 *     @type int             $page     Default 1.
 * }
 * @return array {records: array[], total: int}
 */
function teatatu_data_find( $key, $args = array() ) {
	return teatatu_data_on_master( 'teatatu_data_find_local', array( $key, $args ) );
}

/**
 * Searches a data set on the current site (call on the Master Site).
 *
 * @param string $key  Data set key.
 * @param array  $args See teatatu_data_find().
 * @return array {records: array[], total: int}
 */
function teatatu_data_find_local( $key, $args = array() ) {
	$dataset = teatatu_data_get_dataset( $key );
	if ( ! $dataset || 'metadata' === $dataset['kind'] ) {
		return array( 'records' => array(), 'total' => 0 );
	}
	$args = wp_parse_args(
		$args,
		array(
			'search'   => '',
			'status'   => 'active',
			'where'    => array(),
			'per_page' => 50,
			'page'     => 1,
		)
	);

	if ( 'all' === $args['status'] ) {
		$post_statuses = teatatu_data_all_post_statuses();
	} else {
		$post_statuses = array_map( 'teatatu_data_post_status', array_intersect( (array) $args['status'], array_keys( teatatu_data_lifecycle_statuses() ) ) );
		if ( ! $post_statuses ) {
			$post_statuses = array( 'ttd_active' );
		}
	}

	$meta_query = array();
	foreach ( (array) $args['where'] as $field_key => $value ) {
		if ( isset( $dataset['fields'][ $field_key ] ) ) {
			$meta_query[] = array(
				'key'   => '_ttd_f_' . $field_key,
				'value' => is_bool( $value ) ? (int) $value : $value,
			);
		}
	}

	$query = new WP_Query(
		array(
			'post_type'              => $dataset['post_type'],
			'post_status'            => $post_statuses,
			's'                      => (string) $args['search'],
			'meta_query'             => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery
			'posts_per_page'         => (int) $args['per_page'],
			'paged'                  => max( 1, (int) $args['page'] ),
			'orderby'                => 'title',
			'order'                  => 'ASC',
			'suppress_filters'       => true,
			'update_post_term_cache' => false,
		)
	);

	$records = array();
	foreach ( $query->posts as $post ) {
		$records[] = teatatu_data_record_from_post( $post, $dataset );
	}
	return array(
		'records' => $records,
		'total'   => (int) $query->found_posts,
	);
}

/**
 * Display name of a record: its title field, or its UUID.
 *
 * @param string $key    Data set key.
 * @param array  $record Record.
 * @return string
 */
function teatatu_data_record_title( $key, $record ) {
	$dataset = teatatu_data_get_dataset( $key );
	if ( $dataset && ! empty( $record[ $dataset['title_field'] ] ) && is_scalar( $record[ $dataset['title_field'] ] ) ) {
		return (string) $record[ $dataset['title_field'] ];
	}
	return isset( $record['uuid'] ) ? (string) $record['uuid'] : '';
}
