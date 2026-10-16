<?php
/**
 * Neighbourhoods and street rules (SPEC.md §5, §13): every Te Atatū
 * Peninsula address falls into exactly one of three neighbourhoods —
 * Matipo, Beach or Harbourview — split along Te Atatū Road, Taikata Road
 * and Harbour View Road. Moved here from Teatatu Events.
 *
 * Two data sets:
 * - `neighbourhood` (reference): the three neighbourhoods. Each has a fixed
 *   boundary key (matipo, beach, harbourview) that the classifier and the
 *   bundled street data use, and an editable slug that consumers store.
 * - `street_rule` (reference): street → neighbourhood rules, by side of the
 *   road and house-number range where a street crosses a boundary.
 *
 * teatatu_data_resolve_neighbourhood() applies them. Resolution order
 * (first that applies wins; a consumer's own manual override comes first):
 *  1. Street rules (active records only).
 *  2. Coordinates, classified against assets/data/neighbourhoods.geojson.
 *  3. Off the peninsula → no neighbourhood (normal, not an error).
 *  4. A peninsula address nothing resolved → none, flagged `needs`.
 *
 * Both data sets are seeded once on the Master Site: from Teatatu Events'
 * current state when it is installed (so editors' changes there carry
 * over), otherwise from the bundled data. No external geocoding service is
 * ever called.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The three fixed boundary keys with their default name and description.
 *
 * @return array key => {name, description}
 */
function teatatu_data_neighbourhood_defaults() {
	return array(
		'matipo'      => array(
			'name'        => __( 'Matipo', 'teatatu-data' ),
			'description' => __( 'North of Taikata Road and west of Te Atatū Road. Named after Matipo Road.', 'teatatu-data' ),
		),
		'beach'       => array(
			'name'        => __( 'Beach', 'teatatu-data' ),
			'description' => __( 'North of Harbour View Road and east of Te Atatū Road. Named after Beach Road.', 'teatatu-data' ),
		),
		'harbourview' => array(
			'name'        => __( 'Harbourview', 'teatatu-data' ),
			'description' => __( 'South of Taikata Road and Harbour View Road, down to the North-Western Motorway (SH16). Named after Harbourview–Orangihina Park near SH16 — not Harbour View Road (even-numbered houses on Harbour View Road are in Beach).', 'teatatu-data' ),
		),
	);
}

add_filter( 'teatatu_data_datasets', 'teatatu_data_register_neighbourhood_datasets' );

/**
 * Registers the two data sets.
 *
 * @param array $datasets Data sets.
 * @return array
 */
function teatatu_data_register_neighbourhood_datasets( $datasets ) {
	$slug_pattern = '[a-z0-9]+(?:-[a-z0-9]+)*';

	$datasets['neighbourhood'] = array(
		'label'           => __( 'Neighbourhoods', 'teatatu-data' ),
		'description'     => __( 'The three neighbourhoods of Te Atatū Peninsula. Every peninsula address is in exactly one of them; the boundaries follow Te Atatū Road, Taikata Road and Harbour View Road.', 'teatatu-data' ),
		'kind'            => 'reference',
		'owner'           => __( 'Administrator', 'teatatu-data' ),
		'steward'         => __( 'Editor', 'teatatu-data' ),
		'source'          => __( 'Teatatu (community decision); boundaries from OpenStreetMap, ODbL', 'teatatu-data' ),
		'consumers'       => array( 'teatatu-events' ),
		'review_interval' => 24,
		'list_columns'    => array( 'name', 'key', 'slug', 'aliases' ),
		'rules'           => array(
			__( 'There are exactly three: one per boundary key. A fourth cannot be added.', 'teatatu-data' ),
			__( 'Renaming a slug keeps the old one working: add it to Old slugs.', 'teatatu-data' ),
			__( 'A slug cannot be "outside", which means "not on the peninsula".', 'teatatu-data' ),
		),
		'validate'        => 'teatatu_data_validate_neighbourhood',
		'mergeable'       => false,
		'fields'          => array(
			'name'        => array(
				'label'       => __( 'Name', 'teatatu-data' ),
				'required'    => true,
				'unique'      => true,
				'max_length'  => 60,
				'description' => __( 'Shown to people.', 'teatatu-data' ),
				'example'     => 'Matipo',
			),
			'key'         => array(
				'label'       => __( 'Boundary key', 'teatatu-data' ),
				'type'        => 'enum',
				'enum'        => array(
					'matipo'      => 'matipo',
					'beach'       => 'beach',
					'harbourview' => 'harbourview',
				),
				'required'    => true,
				'unique'      => true,
				'description' => __( 'Fixed internal key used by the boundary classifier and the bundled street data. Never changes.', 'teatatu-data' ),
			),
			'slug'        => array(
				'label'       => __( 'Slug', 'teatatu-data' ),
				'required'    => true,
				'unique'      => true,
				'max_length'  => 50,
				'pattern'     => $slug_pattern,
				'description' => __( 'Lower-case identifier other plugins store and use in URLs and shortcodes.', 'teatatu-data' ),
				'example'     => 'matipo',
			),
			'aliases'     => array(
				'label'       => __( 'Old slugs', 'teatatu-data' ),
				'max_length'  => 200,
				'pattern'     => $slug_pattern . '(?:\s*,\s*' . $slug_pattern . ')*',
				'description' => __( 'Earlier slugs, comma-separated, so old links and shortcodes still find this neighbourhood.', 'teatatu-data' ),
			),
			'description' => array(
				'label'       => __( 'Description', 'teatatu-data' ),
				'type'        => 'text',
				'recommended' => true,
				'description' => __( 'Where it is and where the name comes from.', 'teatatu-data' ),
			),
		),
	);

	$datasets['street_rule'] = array(
		'label'           => __( 'Street rules', 'teatatu-data' ),
		'description'     => __( 'Which neighbourhood each Te Atatū Peninsula street is in. Most streets are wholly in one neighbourhood; streets that cross a boundary are split by side of the road and house number.', 'teatatu-data' ),
		'kind'            => 'reference',
		'owner'           => __( 'Administrator', 'teatatu-data' ),
		'steward'         => __( 'Editor', 'teatatu-data' ),
		'source'          => __( 'OpenStreetMap address points (addr:suburb = Te Atatū Peninsula), ODbL, built by tools/build-neighbourhood-streets.php; then edited by stewards', 'teatatu-data' ),
		'consumers'       => array( 'teatatu-events' ),
		'review_interval' => 24,
		'list_columns'    => array( 'street', 'side', 'min', 'max', 'neighbourhood', 'outside', 'position' ),
		'rules'           => array(
			__( 'Each rule points to a neighbourhood or is marked "not on the peninsula" — one or the other.', 'teatatu-data' ),
			__( 'A street\'s active rules are checked in Order; the first whose side and number range match wins.', 'teatatu-data' ),
			__( 'A rule with no side and no numbers covers the whole street.', 'teatatu-data' ),
			__( 'From number must not be greater than To number.', 'teatatu-data' ),
		),
		'validate'        => 'teatatu_data_validate_street_rule',
		'duplicate_keys'  => 'teatatu_data_street_rule_duplicate_keys',
		'quality_checks'  => 'teatatu_data_street_rule_quality_checks',
		'title_field'     => 'street',
		'fields'          => array(
			'street'        => array(
				'label'       => __( 'Street', 'teatatu-data' ),
				'required'    => true,
				'max_length'  => 100,
				'description' => __( 'Street name as people write it. Abbreviations (Rd, St, Ave) and macrons don\'t matter when matching.', 'teatatu-data' ),
				'example'     => 'Te Atatū Road',
			),
			'side'          => array(
				'label'       => __( 'Side', 'teatatu-data' ),
				'type'        => 'enum',
				'enum'        => array(
					'any'  => __( 'Both sides', 'teatatu-data' ),
					'odd'  => __( 'Odd numbers', 'teatatu-data' ),
					'even' => __( 'Even numbers', 'teatatu-data' ),
				),
				'required'    => true,
				'description' => __( 'Which side of the road (by house number) the rule covers.', 'teatatu-data' ),
			),
			'min'           => array(
				'label'       => __( 'From number', 'teatatu-data' ),
				'type'        => 'integer',
				'min'         => 0,
				'max'         => 99999,
				'description' => __( 'Lowest house number covered. Blank = from the start.', 'teatatu-data' ),
			),
			'max'           => array(
				'label'       => __( 'To number', 'teatatu-data' ),
				'type'        => 'integer',
				'min'         => 0,
				'max'         => 99999,
				'description' => __( 'Highest house number covered. Blank = to the end.', 'teatatu-data' ),
			),
			'neighbourhood' => array(
				'label'       => __( 'Neighbourhood', 'teatatu-data' ),
				'type'        => 'reference',
				'references'  => 'neighbourhood',
				'description' => __( 'The neighbourhood these addresses are in.', 'teatatu-data' ),
			),
			'outside'       => array(
				'label'       => __( 'Not on the peninsula', 'teatatu-data' ),
				'type'        => 'boolean',
				'description' => __( 'These addresses are outside Te Atatū Peninsula (e.g. the Te Atatū South end of Te Atatū Road).', 'teatatu-data' ),
			),
			'position'      => array(
				'label'       => __( 'Order', 'teatatu-data' ),
				'type'        => 'integer',
				'min'         => 0,
				'max'         => 999,
				'description' => __( 'Order in which this street\'s rules are checked; lower first.', 'teatatu-data' ),
			),
		),
	);

	return $datasets;
}

/**
 * Cross-field rules for a neighbourhood.
 *
 * @param array $merged Record after the change.
 * @return array field => message
 */
function teatatu_data_validate_neighbourhood( $merged ) {
	$errors = array();
	if ( isset( $merged['slug'] ) && 'outside' === $merged['slug'] ) {
		$errors['slug'] = __( '"outside" is reserved for addresses not on the peninsula.', 'teatatu-data' );
	}
	$aliases = teatatu_data_split_aliases( isset( $merged['aliases'] ) ? $merged['aliases'] : '' );
	if ( in_array( 'outside', $aliases, true ) ) {
		$errors['aliases'] = __( '"outside" is reserved for addresses not on the peninsula.', 'teatatu-data' );
	} elseif ( isset( $merged['slug'] ) && in_array( $merged['slug'], $aliases, true ) ) {
		$errors['aliases'] = __( 'The current slug does not need to be listed as an old slug.', 'teatatu-data' );
	}
	return $errors;
}

/**
 * Cross-field rules for a street rule.
 *
 * @param array $merged Record after the change.
 * @return array field => message
 */
function teatatu_data_validate_street_rule( $merged ) {
	$errors        = array();
	$neighbourhood = ! empty( $merged['neighbourhood'] );
	$outside       = ! empty( $merged['outside'] );
	if ( ! $neighbourhood && ! $outside ) {
		$errors['neighbourhood'] = __( 'Choose a neighbourhood, or tick "Not on the peninsula".', 'teatatu-data' );
	} elseif ( $neighbourhood && $outside ) {
		$errors['outside'] = __( 'A rule cannot have a neighbourhood and be off the peninsula.', 'teatatu-data' );
	}
	if ( isset( $merged['min'], $merged['max'] ) && $merged['min'] > $merged['max'] ) {
		$errors['max'] = __( 'To number is lower than From number.', 'teatatu-data' );
	}
	return $errors;
}

/**
 * Splits a comma-separated alias list.
 *
 * @param string $aliases Aliases.
 * @return string[]
 */
function teatatu_data_split_aliases( $aliases ) {
	return array_values( array_filter( array_map( 'trim', explode( ',', (string) $aliases ) ) ) );
}

/* ---------------------------------------------------------------------
 * Reading the rules
 * ------------------------------------------------------------------- */

/**
 * The shipped boundary geometry.
 *
 * @return array {peninsula: ring, east_west: line, te_atatu_road: line}
 */
function teatatu_data_neighbourhood_geometry() {
	static $geometry = null;
	if ( null === $geometry ) {
		$geometry = array();
		$file     = TEATATU_DATA_DIR . 'assets/data/neighbourhoods.geojson';
		$json     = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions
		foreach ( isset( $json['features'] ) ? $json['features'] : array() as $feature ) {
			$id = isset( $feature['properties']['id'] ) ? $feature['properties']['id'] : '';
			if ( 'peninsula' === $id ) {
				$geometry['peninsula'] = $feature['geometry']['coordinates'][0];
			} elseif ( in_array( $id, array( 'east_west', 'te_atatu_road' ), true ) ) {
				$geometry[ $id ] = $feature['geometry']['coordinates'];
			}
		}
	}
	return $geometry;
}

/**
 * Active neighbourhoods and street rules, prepared for resolving: cached
 * network-wide and rebuilt whenever any master data changes.
 *
 * @return array {by_key: key => record, by_uuid: uuid => key, streets: normalised street => rows}
 */
function teatatu_data_neighbourhood_index() {
	static $memo = array();
	$version     = teatatu_data_cache_version();
	if ( isset( $memo[ $version ] ) ) {
		return $memo[ $version ];
	}
	$cache_key = 'ttd_nbhd_index_' . $version;
	$index     = is_multisite() ? get_site_transient( $cache_key ) : get_transient( $cache_key );
	if ( ! is_array( $index ) ) {
		$index = teatatu_data_on_master( 'teatatu_data_build_neighbourhood_index' );
		if ( is_multisite() ) {
			set_site_transient( $cache_key, $index, DAY_IN_SECONDS );
		} else {
			set_transient( $cache_key, $index, DAY_IN_SECONDS );
		}
	}
	$memo = array( $version => $index );
	return $index;
}

/**
 * Builds the resolver index (call on the Master Site).
 *
 * @return array
 */
function teatatu_data_build_neighbourhood_index() {
	$index = array(
		'by_key'  => array(),
		'by_uuid' => array(),
		'streets' => array(),
	);
	foreach ( teatatu_data_find_local( 'neighbourhood', array( 'per_page' => -1 ) )['records'] as $record ) {
		$index['by_key'][ $record['key'] ]   = array_intersect_key( $record, array_flip( array( 'uuid', 'name', 'key', 'slug', 'aliases' ) ) );
		$index['by_uuid'][ $record['uuid'] ] = $record['key'];
	}
	$rules = teatatu_data_find_local( 'street_rule', array( 'per_page' => -1 ) )['records'];
	usort(
		$rules,
		function ( $a, $b ) {
			return ( (int) $a['position'] <=> (int) $b['position'] ) ?: strcmp( $a['created_at'], $b['created_at'] );
		}
	);
	foreach ( $rules as $rule ) {
		if ( $rule['outside'] ) {
			$area = 'outside';
		} elseif ( $rule['neighbourhood'] && isset( $index['by_uuid'][ $rule['neighbourhood'] ] ) ) {
			$area = $index['by_uuid'][ $rule['neighbourhood'] ];
		} else {
			continue; // Points at a neighbourhood that is not active.
		}
		$index['streets'][ teatatu_data_normalize_street( $rule['street'] ) ][] = array(
			'parity' => $rule['side'] ? $rule['side'] : 'any',
			'min'    => $rule['min'],
			'max'    => $rule['max'],
			'area'   => $area,
		);
	}
	return $index;
}

/**
 * Finds a neighbourhood by its current slug or any old slug.
 *
 * @param string $slug Slug.
 * @return array|null {uuid, name, key, slug, aliases}
 */
function teatatu_data_neighbourhood_by_slug( $slug ) {
	$slug = sanitize_title( (string) $slug );
	foreach ( teatatu_data_neighbourhood_index()['by_key'] as $record ) {
		if ( $record['slug'] === $slug || in_array( $slug, teatatu_data_split_aliases( $record['aliases'] ), true ) ) {
			return $record;
		}
	}
	return null;
}

/**
 * Resolves the neighbourhood of an address.
 *
 * @param array $parts Address parts: number, street, suburb, lat, lng.
 * @return array {
 *     @type string $key   Boundary key, '' if none.
 *     @type string $slug  Current slug, '' if none.
 *     @type string $uuid  Neighbourhood record UUID, '' if none.
 *     @type string $name  Neighbourhood name, '' if none.
 *     @type string $via   'street' (whole street), 'road' (number range), 'coords', or ''.
 *     @type bool   $needs True when the address is on the peninsula but nothing placed it.
 * }
 */
function teatatu_data_resolve_neighbourhood( $parts ) {
	$none  = array(
		'key'   => '',
		'slug'  => '',
		'uuid'  => '',
		'name'  => '',
		'via'   => '',
		'needs' => false,
	);
	$found = function ( $key, $via ) use ( $none ) {
		$index = teatatu_data_neighbourhood_index();
		if ( ! isset( $index['by_key'][ $key ] ) ) {
			return $none; // Neighbourhood retired or missing.
		}
		$record = $index['by_key'][ $key ];
		return array(
			'key'   => $key,
			'slug'  => $record['slug'],
			'uuid'  => $record['uuid'],
			'name'  => $record['name'],
			'via'   => $via,
			'needs' => false,
		);
	};

	$on = teatatu_data_suburb_is_peninsula( isset( $parts['suburb'] ) ? $parts['suburb'] : '' );
	if ( false === $on ) {
		return $none;
	}
	$street = teatatu_data_normalize_street( isset( $parts['street'] ) ? $parts['street'] : '' );
	$number = teatatu_data_parse_house_number( isset( $parts['number'] ) ? $parts['number'] : '' );
	$number = $number['int'];
	$known  = false;

	if ( '' !== $street ) {
		$streets = teatatu_data_neighbourhood_index()['streets'];
		if ( ! empty( $streets[ $street ] ) ) {
			$known     = true;
			$rows      = $streets[ $street ];
			$all_whole = true;
			foreach ( $rows as $row ) {
				if ( 'any' !== $row['parity'] || null !== $row['min'] || null !== $row['max'] ) {
					$all_whole = false;
				}
			}
			$area = teatatu_data_apply_street_rule( array( 'rules' => $rows ), null === $number ? ( $all_whole ? 0 : null ) : $number );
			if ( 'outside' === $area ) {
				return $none;
			}
			if ( $area ) {
				return $found( $area, $all_whole ? 'street' : 'road' );
			}
		}
	}

	$lat = isset( $parts['lat'] ) ? $parts['lat'] : '';
	$lng = isset( $parts['lng'] ) ? $parts['lng'] : '';
	if ( is_numeric( $lat ) && is_numeric( $lng ) ) {
		$area = teatatu_data_classify_point( (float) $lng, (float) $lat, teatatu_data_neighbourhood_geometry() );
		if ( 'outside' === $area ) {
			return $none;
		}
		if ( $area ) {
			return $found( $area, 'coords' );
		}
	}

	$none['needs'] = true === $on || $known;
	return $none;
}

/* ---------------------------------------------------------------------
 * Seeding
 * ------------------------------------------------------------------- */

add_action( 'admin_init', 'teatatu_data_maybe_seed_neighbourhoods' );

/**
 * Seeds both data sets once, on the Master Site.
 */
function teatatu_data_maybe_seed_neighbourhoods() {
	if ( ! teatatu_data_is_master_site() || teatatu_data_get_option( 'teatatu_data_nbhd_seeded', false ) ) {
		return;
	}
	teatatu_data_seed_neighbourhoods();
}

/**
 * Teatatu Events' current neighbourhood state, when it is installed:
 * slugs, old slugs and term names by key, and its stored street rules.
 *
 * @return array {slugs: key => slug, aliases: key => slug[], names: key => name, rules: rows|null}
 */
function teatatu_data_events_neighbourhood_state() {
	$state   = array(
		'slugs'   => array(),
		'aliases' => array(),
		'names'   => array(),
		'rules'   => null,
	);
	$slugs   = teatatu_data_get_option( 'teatatu_events_nbhd_slugs', array() );
	$aliases = teatatu_data_get_option( 'teatatu_events_nbhd_slug_aliases', array() );
	foreach ( array_keys( teatatu_data_neighbourhood_defaults() ) as $key ) {
		if ( is_array( $slugs ) && ! empty( $slugs[ $key ] ) ) {
			$state['slugs'][ $key ] = sanitize_title( $slugs[ $key ] );
		}
	}
	foreach ( is_array( $aliases ) ? $aliases : array() as $alias => $key ) {
		$state['aliases'][ $key ][] = sanitize_title( $alias );
	}
	$term_ids = get_option( 'teatatu_events_nbhd_term_ids', array() );
	if ( is_array( $term_ids ) && taxonomy_exists( 'teatatu_events_neighbourhood' ) ) {
		foreach ( $term_ids as $key => $term_id ) {
			$term = get_term( (int) $term_id, 'teatatu_events_neighbourhood' );
			if ( $term && ! is_wp_error( $term ) ) {
				$state['names'][ $key ] = $term->name;
			}
		}
	}
	$rules = teatatu_data_get_option( 'teatatu_events_nbhd_street_rules', null );
	if ( is_array( $rules ) && $rules ) {
		$state['rules'] = $rules;
	}
	return $state;
}

/**
 * Street rules from the bundled data file, as rows of {street, parity,
 * min, max, area} where area is a boundary key or 'outside'.
 *
 * @return array[]
 */
function teatatu_data_bundled_street_rules() {
	$file = TEATATU_DATA_DIR . 'assets/data/neighbourhood-streets.json';
	$json = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( ! isset( $json['streets'] ) || ! is_array( $json['streets'] ) ) {
		return array();
	}
	$rows = array();
	foreach ( $json['streets'] as $key => $rule ) {
		$street = teatatu_data_street_display_name( $key );
		if ( is_string( $rule ) ) {
			$rows[] = array( 'street' => $street, 'parity' => 'any', 'min' => null, 'max' => null, 'area' => $rule );
			continue;
		}
		$lowest = PHP_INT_MAX;
		foreach ( $rule['rules'] as $range ) {
			$min    = isset( $range['min'] ) ? (int) $range['min'] : 0;
			$lowest = min( $lowest, $min );
			$rows[] = array(
				'street' => $street,
				'parity' => isset( $range['parity'] ) ? $range['parity'] : 'any',
				'min'    => $min > 0 ? $min : null,
				'max'    => isset( $range['max'] ) ? (int) $range['max'] : null,
				'area'   => $range['area'],
			);
		}
		// "below": numbers lower than every range (the street continues off the peninsula).
		if ( ! empty( $rule['below'] ) && $lowest > 0 && PHP_INT_MAX !== $lowest ) {
			$rows[] = array( 'street' => $street, 'parity' => 'any', 'min' => null, 'max' => $lowest - 1, 'area' => $rule['below'] );
		}
	}
	return $rows;
}

/**
 * Creates street rule records from rows of {street, parity, min, max, area}.
 *
 * @param array[]  $rows        Rows; area is a boundary key or 'outside'.
 * @param string   $source      Source recorded on each rule.
 * @param string   $reason      Change-log reason.
 * @param string[] $skip_streets Normalised streets to leave out.
 * @return array {added: int, errors: string[]}
 */
function teatatu_data_create_street_rules( $rows, $source, $reason, $skip_streets = array() ) {
	$by_key   = array();
	foreach ( teatatu_data_find_local( 'neighbourhood', array( 'status' => 'all', 'per_page' => -1 ) )['records'] as $record ) {
		$by_key[ $record['key'] ] = $record['uuid'];
	}
	$result   = array(
		'added'  => 0,
		'errors' => array(),
	);
	$position = array();
	foreach ( $rows as $row ) {
		$street = teatatu_data_normalize_street( $row['street'] );
		if ( '' === $street || in_array( $street, $skip_streets, true ) ) {
			continue;
		}
		$position[ $street ] = isset( $position[ $street ] ) ? $position[ $street ] + 1 : 1;
		$area                = (string) $row['area'];
		$values              = array(
			'street'   => $row['street'],
			'side'     => in_array( $row['parity'], array( 'any', 'odd', 'even' ), true ) ? $row['parity'] : 'any',
			'min'      => '' === (string) $row['min'] ? null : (int) $row['min'],
			'max'      => '' === (string) $row['max'] ? null : (int) $row['max'],
			'position' => $position[ $street ],
			'source'   => $source,
		);
		if ( 'outside' === $area ) {
			$values['outside'] = true;
		} elseif ( isset( $by_key[ $area ] ) ) {
			$values['neighbourhood'] = $by_key[ $area ];
		} else {
			/* translators: 1: street, 2: area */
			$result['errors'][] = sprintf( __( '%1$s: unknown neighbourhood "%2$s".', 'teatatu-data' ), $row['street'], $area );
			continue;
		}
		$applied = teatatu_data_apply_local(
			'street_rule',
			null,
			$values,
			array(
				'channel' => 'migration',
				'reason'  => $reason,
			)
		);
		if ( is_wp_error( $applied ) ) {
			$result['errors'][] = $row['street'] . ': ' . $applied->get_error_message() . ' ' . wp_json_encode( isset( $applied->get_error_data()['fields'] ) ? $applied->get_error_data()['fields'] : array() );
		} else {
			$result['added']++;
		}
	}
	return $result;
}

/**
 * Seeds the neighbourhoods and street rules on the Master Site (call
 * there). Neighbourhoods missing by key are created; street rules are
 * loaded only when there are none at all. Teatatu Events' current state
 * is used when present, so its renamed slugs, names and edited street
 * rules carry over; otherwise the bundled data.
 *
 * @return array {neighbourhoods: int, rules: int, errors: string[], source: string}
 */
function teatatu_data_seed_neighbourhoods() {
	if ( ! post_type_exists( 'ttd_neighbourhood' ) ) {
		teatatu_data_register_storage(); // Activation runs after init.
	}
	$events = teatatu_data_events_neighbourhood_state();
	$result = array(
		'neighbourhoods' => 0,
		'rules'          => 0,
		'errors'         => array(),
		'source'         => '',
	);

	$existing = array();
	foreach ( teatatu_data_find_local( 'neighbourhood', array( 'status' => 'all', 'per_page' => -1 ) )['records'] as $record ) {
		$existing[ $record['key'] ] = true;
	}
	foreach ( teatatu_data_neighbourhood_defaults() as $key => $default ) {
		if ( isset( $existing[ $key ] ) ) {
			continue;
		}
		$from_events = isset( $events['slugs'][ $key ] ) || isset( $events['names'][ $key ] );
		$applied     = teatatu_data_apply_local(
			'neighbourhood',
			null,
			array(
				'name'        => isset( $events['names'][ $key ] ) ? $events['names'][ $key ] : $default['name'],
				'key'         => $key,
				'slug'        => isset( $events['slugs'][ $key ] ) ? $events['slugs'][ $key ] : $key,
				'aliases'     => isset( $events['aliases'][ $key ] ) ? implode( ', ', array_diff( array_unique( $events['aliases'][ $key ] ), array( isset( $events['slugs'][ $key ] ) ? $events['slugs'][ $key ] : $key, 'outside', '' ) ) ) : '',
				'description' => $default['description'],
				'source'      => $from_events ? 'Teatatu Events' : 'Teatatu Data defaults',
				'verified_at' => current_time( 'Y-m-d' ),
			),
			array(
				'channel' => 'migration',
				'reason'  => $from_events ? __( 'Moved from Teatatu Events.', 'teatatu-data' ) : __( 'Seeded from the built-in neighbourhood list.', 'teatatu-data' ),
			)
		);
		if ( is_wp_error( $applied ) ) {
			$result['errors'][] = $key . ': ' . $applied->get_error_message();
		} else {
			$result['neighbourhoods']++;
		}
	}

	$any_rules = teatatu_data_find_local( 'street_rule', array( 'status' => 'all', 'per_page' => 1 ) )['total'];
	if ( ! $any_rules ) {
		if ( null !== $events['rules'] ) {
			// Events stores slugs; map them back to keys.
			$slug_to_key = array();
			foreach ( array_keys( teatatu_data_neighbourhood_defaults() ) as $key ) {
				$slug_to_key[ isset( $events['slugs'][ $key ] ) ? $events['slugs'][ $key ] : $key ] = $key;
				$slug_to_key[ $key ] = $key;
				foreach ( isset( $events['aliases'][ $key ] ) ? $events['aliases'][ $key ] : array() as $alias ) {
					$slug_to_key[ $alias ] = $key;
				}
			}
			$rows = array();
			foreach ( $events['rules'] as $row ) {
				$area   = isset( $row['area'] ) ? (string) $row['area'] : '';
				$rows[] = array(
					'street' => isset( $row['street'] ) ? $row['street'] : '',
					'parity' => isset( $row['parity'] ) ? $row['parity'] : 'any',
					'min'    => isset( $row['min'] ) ? $row['min'] : '',
					'max'    => isset( $row['max'] ) ? $row['max'] : '',
					'area'   => 'outside' === $area ? 'outside' : ( isset( $slug_to_key[ $area ] ) ? $slug_to_key[ $area ] : $area ),
				);
			}
			$result['source'] = 'events';
			$created          = teatatu_data_create_street_rules( $rows, 'Teatatu Events street rules', __( 'Moved from Teatatu Events.', 'teatatu-data' ) );
		} else {
			$result['source'] = 'bundled';
			$created          = teatatu_data_create_street_rules( teatatu_data_bundled_street_rules(), teatatu_data_bundled_source(), __( 'Seeded from the bundled street list.', 'teatatu-data' ) );
		}
		$result['rules']  = $created['added'];
		$result['errors'] = array_merge( $result['errors'], $created['errors'] );
	}

	teatatu_data_update_option( 'teatatu_data_nbhd_seeded', TEATATU_DATA_VERSION );
	if ( $result['errors'] ) {
		teatatu_data_update_option( 'teatatu_data_nbhd_seed_errors', $result['errors'] );
	}
	return $result;
}

/**
 * Source line for rules loaded from the bundled file.
 *
 * @return string
 */
function teatatu_data_bundled_source() {
	$file = TEATATU_DATA_DIR . 'assets/data/neighbourhood-streets.json';
	$json = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions
	/* translators: %s: date the bundled file was generated */
	return sprintf( __( 'OpenStreetMap (bundled list, generated %s)', 'teatatu-data' ), isset( $json['generated'] ) ? $json['generated'] : '?' );
}

/**
 * Adds bundled rules for streets that have no rules at all (in any
 * status, so a street a steward retired is not brought back).
 *
 * @return array {added: int, errors: string[]}
 */
function teatatu_data_top_up_street_rules() {
	$have = array();
	foreach ( teatatu_data_find_local( 'street_rule', array( 'status' => 'all', 'per_page' => -1 ) )['records'] as $record ) {
		$have[] = teatatu_data_normalize_street( $record['street'] );
	}
	return teatatu_data_create_street_rules( teatatu_data_bundled_street_rules(), teatatu_data_bundled_source(), __( 'Added from the bundled street list.', 'teatatu-data' ), array_unique( $have ) );
}

/* ---------------------------------------------------------------------
 * Admin tools on the two data set tabs
 * ------------------------------------------------------------------- */

add_action( 'teatatu_data_records_list_before', 'teatatu_data_neighbourhood_admin_tools' );

/**
 * Street rules: the resolution order and "Add missing streets".
 * Neighbourhoods: "Test an address".
 *
 * @param string $key Data set key.
 */
function teatatu_data_neighbourhood_admin_tools( $key ) {
	if ( 'street_rule' === $key ) {
		$errors = teatatu_data_get_option( 'teatatu_data_nbhd_seed_errors', array() );
		?>
		<div class="card" style="max-width:none;">
			<p><?php esc_html_e( 'How an address is placed: a consumer\'s own manual override first; then these street rules (a street\'s rules are checked in Order, first match wins); then the address\'s coordinates against the boundary lines; otherwise an address on the peninsula is flagged "needs neighbourhood". Retired or proposed rules are ignored.', 'teatatu-data' ); ?></p>
			<?php if ( $errors ) : ?>
				<p style="color:#b32d2e;"><?php esc_html_e( 'Some rules could not be loaded when this data set was first filled:', 'teatatu-data' ); ?> <?php echo esc_html( implode( ' · ', (array) $errors ) ); ?></p>
			<?php endif; ?>
			<?php if ( teatatu_data_is_master_site() && current_user_can( 'edit_teatatu_data' ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'teatatu_data_street_top_up' ); ?>
					<input type="hidden" name="action" value="teatatu_data_street_top_up" />
					<?php submit_button( __( 'Add missing streets from the bundled list', 'teatatu-data' ), 'secondary', 'submit', false ); ?>
					<span class="description"><?php esc_html_e( 'Adds the bundled OpenStreetMap rules for streets that have no rules here yet. Existing rules are never changed.', 'teatatu-data' ); ?></span>
				</form>
			<?php endif; ?>
		</div>
		<?php
		return;
	}
	if ( 'neighbourhood' !== $key ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only lookup.
	$test = isset( $_GET['ttd_test'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_GET['ttd_test'] ) ) : null;
	$test = wp_parse_args( (array) $test, array( 'number' => '', 'street' => '', 'suburb' => 'Te Atatū Peninsula', 'lat' => '', 'lng' => '' ) );
	?>
	<div class="card" style="max-width:none;">
		<h3 style="margin-top:8px;"><?php esc_html_e( 'Test an address', 'teatatu-data' ); ?></h3>
		<form method="get">
			<input type="hidden" name="page" value="teatatu-data" />
			<input type="hidden" name="tab" value="records" />
			<input type="hidden" name="dataset" value="neighbourhood" />
			<input type="text" name="ttd_test[number]" value="<?php echo esc_attr( $test['number'] ); ?>" placeholder="<?php esc_attr_e( 'Number', 'teatatu-data' ); ?>" style="width:6em;" />
			<input type="text" name="ttd_test[street]" value="<?php echo esc_attr( $test['street'] ); ?>" placeholder="<?php esc_attr_e( 'Street', 'teatatu-data' ); ?>" />
			<input type="text" name="ttd_test[suburb]" value="<?php echo esc_attr( $test['suburb'] ); ?>" placeholder="<?php esc_attr_e( 'Suburb', 'teatatu-data' ); ?>" />
			<input type="text" name="ttd_test[lat]" value="<?php echo esc_attr( $test['lat'] ); ?>" placeholder="<?php esc_attr_e( 'Latitude', 'teatatu-data' ); ?>" style="width:8em;" />
			<input type="text" name="ttd_test[lng]" value="<?php echo esc_attr( $test['lng'] ); ?>" placeholder="<?php esc_attr_e( 'Longitude', 'teatatu-data' ); ?>" style="width:8em;" />
			<?php submit_button( __( 'Find neighbourhood', 'teatatu-data' ), 'secondary', '', false ); ?>
		</form>
		<?php if ( isset( $_GET['ttd_test'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<?php
			$result = teatatu_data_resolve_neighbourhood( $test );
			$vias   = array(
				'street' => __( 'the whole street is in it', 'teatatu-data' ),
				'road'   => __( 'from the street\'s number ranges', 'teatatu-data' ),
				'coords' => __( 'from the coordinates', 'teatatu-data' ),
			);
			?>
			<p><strong>
				<?php
				if ( $result['key'] ) {
					/* translators: 1: neighbourhood, 2: how it was found */
					echo esc_html( sprintf( __( '%1$s — %2$s.', 'teatatu-data' ), $result['name'], $vias[ $result['via'] ] ) );
				} elseif ( $result['needs'] ) {
					esc_html_e( 'On the peninsula, but no rule places it: it would be flagged "needs neighbourhood".', 'teatatu-data' );
				} else {
					esc_html_e( 'Not on Te Atatū Peninsula: no neighbourhood.', 'teatatu-data' );
				}
				?>
			</strong></p>
		<?php endif; ?>
	</div>
	<?php
}

add_action( 'admin_post_teatatu_data_street_top_up', 'teatatu_data_handle_street_top_up' );

/**
 * Handles "Add missing streets from the bundled list".
 */
function teatatu_data_handle_street_top_up() {
	check_admin_referer( 'teatatu_data_street_top_up' );
	if ( ! current_user_can( 'edit_teatatu_data' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-data' ), 403 );
	}
	teatatu_data_admin_require_master();
	$result = teatatu_data_top_up_street_rules();
	$url    = teatatu_data_admin_url( array( 'tab' => 'records', 'dataset' => 'street_rule' ) );
	/* translators: %d: number of rules added */
	$message = sprintf( _n( '%d street rule added.', '%d street rules added.', $result['added'], 'teatatu-data' ), $result['added'] );
	if ( $result['errors'] ) {
		$message .= ' ' . implode( ' · ', $result['errors'] );
	}
	set_transient( 'teatatu_data_message_' . get_current_user_id(), $message, 5 * MINUTE_IN_SECONDS );
	wp_safe_redirect( add_query_arg( 'ttd_notice', $result['errors'] ? 'error_message' : 'message', $url ) );
	exit;
}

/* ---------------------------------------------------------------------
 * REST: resolve an address
 * ------------------------------------------------------------------- */

add_action( 'rest_api_init', 'teatatu_data_register_neighbourhood_rest_route' );

/**
 * GET /teatatu-data/v1/neighbourhoods/resolve?number=&street=&suburb=&lat=&lng=
 */
function teatatu_data_register_neighbourhood_rest_route() {
	$text = array(
		'type'    => 'string',
		'default' => '',
	);
	register_rest_route(
		'teatatu-data/v1',
		'/neighbourhoods/resolve',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => function ( $request ) {
				return rest_ensure_response(
					teatatu_data_resolve_neighbourhood(
						array(
							'number' => (string) $request['number'],
							'street' => (string) $request['street'],
							'suburb' => (string) $request['suburb'],
							'lat'    => (string) $request['lat'],
							'lng'    => (string) $request['lng'],
						)
					)
				);
			},
			'permission_callback' => function () {
				return current_user_can( 'read_teatatu_data' );
			},
			'args'                => array(
				'number' => $text,
				'street' => $text,
				'suburb' => $text,
				'lat'    => $text,
				'lng'    => $text,
			),
		)
	);
}

/* ---------------------------------------------------------------------
 * Quality
 * ------------------------------------------------------------------- */

/**
 * Two rules are duplicates when they cover exactly the same addresses.
 *
 * @param array $record Street rule.
 * @return string[]
 */
function teatatu_data_street_rule_duplicate_keys( $record ) {
	return array( 'rule:' . teatatu_data_normalize_street( $record['street'] ) . '|' . $record['side'] . '|' . $record['min'] . '|' . $record['max'] );
}

/**
 * Consistency: active rules on the same street whose number ranges overlap
 * on the same side but send addresses to different places (the first rule
 * by Order silently wins, which is rarely what was meant).
 *
 * @param array[] $active Active street rules.
 * @return array[] Issues.
 */
function teatatu_data_street_rule_quality_checks( $active ) {
	$issues  = array();
	$streets = array();
	foreach ( $active as $rule ) {
		$streets[ teatatu_data_normalize_street( $rule['street'] ) ][] = $rule;
	}
	foreach ( $streets as $rules ) {
		$n = count( $rules );
		for ( $i = 0; $i < $n; $i++ ) {
			for ( $j = $i + 1; $j < $n; $j++ ) {
				$a = $rules[ $i ];
				$b = $rules[ $j ];
				if ( 'any' !== $a['side'] && 'any' !== $b['side'] && $a['side'] !== $b['side'] ) {
					continue;
				}
				$a_min = null === $a['min'] ? 0 : $a['min'];
				$b_min = null === $b['min'] ? 0 : $b['min'];
				$a_max = null === $a['max'] ? PHP_INT_MAX : $a['max'];
				$b_max = null === $b['max'] ? PHP_INT_MAX : $b['max'];
				if ( $a_min > $b_max || $b_min > $a_max ) {
					continue;
				}
				if ( $a['outside'] === $b['outside'] && $a['neighbourhood'] === $b['neighbourhood'] ) {
					continue; // Overlapping but agreeing: harmless (exact repeats are flagged as duplicates).
				}
				/* translators: %s: street */
				$issues[] = teatatu_data_quality_issue( 'rule_overlap', 'consistency', array( $a['uuid'], $b['uuid'] ), sprintf( __( 'Two rules for %s cover some of the same house numbers but give different neighbourhoods; the one with the lower Order wins.', 'teatatu-data' ), $a['street'] ) );
			}
		}
	}
	return $issues;
}
