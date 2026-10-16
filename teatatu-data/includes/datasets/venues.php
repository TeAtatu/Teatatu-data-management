<?php
/**
 * Venues / places (SPEC.md §5, §14): reusable places where things happen,
 * each with a Location name and a structured NZ address that maps one-to-one
 * onto schema.org PostalAddress. Moved here from Teatatu Events (the
 * `teatatu_events_venue` taxonomy and its term meta).
 *
 * - One formatter, teatatu_data_format_address(); nothing else joins
 *   address fields.
 * - One matcher, teatatu_data_match_venue(): by name or alias, then by
 *   normalised street address, then by coordinates.
 * - The neighbourhood is worked out on every save (override, else the
 *   resolver) and again for every venue when the street rules change.
 * - Venues from Teatatu Events are brought in as one batch of proposals,
 *   with duplicates across sites merged and each source term kept as an
 *   external ID (events_s<site>) for Phase 8 to link back.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'teatatu_data_datasets', 'teatatu_data_register_venue_dataset' );

/**
 * Registers the venue data set.
 *
 * @param array $datasets Data sets.
 * @return array
 */
function teatatu_data_register_venue_dataset( $datasets ) {
	$datasets['venue'] = array(
		'label'           => __( 'Venues', 'teatatu-data' ),
		'description'     => __( 'Places where things happen: halls, parks, cafés, churches, marae. Each has a Location name and a structured New Zealand address (NZ Post conventions, schema.org PostalAddress).', 'teatatu-data' ),
		'kind'            => 'master',
		'owner'           => __( 'Administrator', 'teatatu-data' ),
		'steward'         => __( 'Editor', 'teatatu-data' ),
		'source'          => __( 'Stewards and imports; first loaded from Teatatu Events venues', 'teatatu-data' ),
		'consumers'       => array( 'teatatu-events' ),
		'review_interval' => 12,
		'list_columns'    => array( 'name', 'number', 'street', 'suburb', 'neighbourhood' ),
		'rules'           => array(
			__( 'A venue needs a suburb or a town/city.', 'teatatu-data' ),
			__( 'A street number needs a street. Places without one (parks, reserves, marae) leave both blank.', 'teatatu-data' ),
			__( 'The neighbourhood is the override if one is set, otherwise it is worked out from the address by the street rules or the coordinates.', 'teatatu-data' ),
			__( 'Images are links only; nothing is downloaded.', 'teatatu-data' ),
		),
		'validate'        => 'teatatu_data_validate_venue',
		'duplicate_keys'  => 'teatatu_data_venue_duplicate_keys',
		'quality_checks'  => 'teatatu_data_venue_quality_checks',
		'merge_combine'   => array( 'aliases' ),
		'derive'          => 'teatatu_data_derive_venue',
		'fields'          => array(
			'name'                   => array(
				'label'       => __( 'Location', 'teatatu-data' ),
				'required'    => true,
				'max_length'  => 150,
				'description' => __( 'The place\'s name as people know it.', 'teatatu-data' ),
				'example'     => 'Te Atatū Peninsula Community Centre',
			),
			'aliases'                => array(
				'label'       => __( 'Also known as', 'teatatu-data' ),
				'max_length'  => 500,
				'description' => __( 'Other names, comma-separated. Used to recognise the place in imports.', 'teatatu-data' ),
				'example'     => 'Te Atatu RSA, Te Atatū RSA',
			),
			'unit'                   => array(
				'label'       => __( 'Unit / level', 'teatatu-data' ),
				'max_length'  => 30,
				'description' => __( 'Part of schema.org streetAddress.', 'teatatu-data' ),
				'example'     => 'Level 1',
			),
			'number'                 => array(
				'label'       => __( 'Street number', 'teatatu-data' ),
				'max_length'  => 20,
				'pattern'     => '\d+[A-Za-z]?(?:\s*-\s*\d+[A-Za-z]?)?',
				'description' => __( 'House number, with a letter or a range if needed. Part of schema.org streetAddress.', 'teatatu-data' ),
				'example'     => '12A',
			),
			'street'                 => array(
				'label'       => __( 'Street', 'teatatu-data' ),
				'max_length'  => 100,
				'description' => __( 'Street name in full. Part of schema.org streetAddress.', 'teatatu-data' ),
				'example'     => 'Te Atatū Road',
			),
			'suburb'                 => array(
				'label'       => __( 'Suburb', 'teatatu-data' ),
				'max_length'  => 80,
				'example'     => 'Te Atatū Peninsula',
			),
			'city'                   => array(
				'label'       => __( 'Town / city', 'teatatu-data' ),
				'max_length'  => 80,
				'recommended' => true,
				'description' => __( 'schema.org addressLocality.', 'teatatu-data' ),
				'example'     => 'Auckland',
			),
			'region'                 => array(
				'label'       => __( 'Region', 'teatatu-data' ),
				'max_length'  => 80,
				'description' => __( 'schema.org addressRegion.', 'teatatu-data' ),
				'example'     => 'Auckland',
			),
			'postcode'               => array(
				'label'       => __( 'Postcode', 'teatatu-data' ),
				'pattern'     => '\d{4}',
				'recommended' => true,
				'description' => __( 'Four digits. schema.org postalCode.', 'teatatu-data' ),
				'example'     => '0610',
			),
			'country'                => array(
				'label'       => __( 'Country', 'teatatu-data' ),
				'required'    => true,
				'default'     => 'NZ',
				'case'        => 'upper',
				'pattern'     => '[A-Z]{2}',
				'description' => __( 'ISO 3166-1 alpha-2 code. schema.org addressCountry.', 'teatatu-data' ),
				'example'     => 'NZ',
			),
			'location'               => array(
				'label'       => __( 'Coordinates', 'teatatu-data' ),
				'type'        => 'geo',
				'recommended' => true,
				'description' => __( 'Latitude and longitude, from a map or the source. Never looked up automatically.', 'teatatu-data' ),
			),
			'website'                => array(
				'label' => __( 'Website', 'teatatu-data' ),
				'type'  => 'url',
			),
			'map_url'                => array(
				'label'       => __( 'Map link', 'teatatu-data' ),
				'type'        => 'url',
				'description' => __( 'Leave blank to use a map search for the address.', 'teatatu-data' ),
			),
			'image_url'              => array(
				'label'       => __( 'Default image', 'teatatu-data' ),
				'type'        => 'url',
				'description' => __( 'Link to an image of the place (link only, never downloaded).', 'teatatu-data' ),
			),
			'neighbourhood_override' => array(
				'label'       => __( 'Neighbourhood override', 'teatatu-data' ),
				'type'        => 'reference',
				'references'  => 'neighbourhood',
				'description' => __( 'Only when the worked-out neighbourhood is wrong for this place.', 'teatatu-data' ),
			),
			'neighbourhood'          => array(
				'label'       => __( 'Neighbourhood', 'teatatu-data' ),
				'type'        => 'reference',
				'references'  => 'neighbourhood',
				'derived'     => true,
				'description' => __( 'Te Atatū Peninsula neighbourhood; blank when off the peninsula.', 'teatatu-data' ),
			),
			'neighbourhood_via'      => array(
				'label'       => __( 'Neighbourhood from', 'teatatu-data' ),
				'type'        => 'enum',
				'enum'        => array(
					'override' => __( 'the override', 'teatatu-data' ),
					'street'   => __( 'the street', 'teatatu-data' ),
					'road'     => __( 'the street number', 'teatatu-data' ),
					'coords'   => __( 'the coordinates', 'teatatu-data' ),
				),
				'derived'     => true,
				'description' => __( 'How the neighbourhood was worked out.', 'teatatu-data' ),
			),
			'needs_neighbourhood'    => array(
				'label'       => __( 'Needs neighbourhood', 'teatatu-data' ),
				'type'        => 'boolean',
				'derived'     => true,
				'description' => __( 'On the peninsula, but no rule places it: set an override or fix the address.', 'teatatu-data' ),
			),
			'match_key'              => array(
				'label'       => __( 'Match key', 'teatatu-data' ),
				'max_length'  => 300,
				'derived'     => true,
				'description' => __( 'Normalised number|street|suburb|city|postcode, used to recognise the address in imports.', 'teatatu-data' ),
			),
		),
	);
	return $datasets;
}

/**
 * Cross-field rules for a venue.
 *
 * @param array $merged Record after the change.
 * @return array field => message
 */
function teatatu_data_validate_venue( $merged ) {
	$errors = array();
	if ( empty( $merged['suburb'] ) && empty( $merged['city'] ) ) {
		$errors['city'] = __( 'Give a suburb or a town/city.', 'teatatu-data' );
	}
	if ( ! empty( $merged['number'] ) && empty( $merged['street'] ) ) {
		$errors['street'] = __( 'A street number needs a street.', 'teatatu-data' );
	}
	return $errors;
}

/**
 * Works out a venue's match key and neighbourhood.
 *
 * @param array $merged Record after the change.
 * @return array Derived field => value.
 */
function teatatu_data_derive_venue( $merged ) {
	$parts  = teatatu_data_venue_parts( $merged );
	$result = array(
		'match_key'           => teatatu_data_address_match_key( $parts ),
		'neighbourhood'       => null,
		'neighbourhood_via'   => null,
		'needs_neighbourhood' => false,
	);
	$index = teatatu_data_neighbourhood_index();
	if ( ! empty( $merged['neighbourhood_override'] ) && isset( $index['by_uuid'][ $merged['neighbourhood_override'] ] ) ) {
		$result['neighbourhood']     = $merged['neighbourhood_override'];
		$result['neighbourhood_via'] = 'override';
		return $result;
	}
	$resolved = teatatu_data_resolve_neighbourhood( $parts );
	if ( $resolved['uuid'] ) {
		$result['neighbourhood']     = $resolved['uuid'];
		$result['neighbourhood_via'] = $resolved['via'];
	}
	$result['needs_neighbourhood'] = (bool) $resolved['needs'];
	return $result;
}

/**
 * A venue record's address as plain parts (unit, number, street, suburb,
 * city, region, postcode, country, lat, lng).
 *
 * @param array $record Venue record.
 * @return array
 */
function teatatu_data_venue_parts( $record ) {
	$parts = array();
	foreach ( array( 'unit', 'number', 'street', 'suburb', 'city', 'region', 'postcode', 'country' ) as $field ) {
		$parts[ $field ] = isset( $record[ $field ] ) ? (string) $record[ $field ] : '';
	}
	$parts['lat'] = isset( $record['location']['lat'] ) ? (string) $record['location']['lat'] : '';
	$parts['lng'] = isset( $record['location']['lng'] ) ? (string) $record['location']['lng'] : '';
	return $parts;
}

/* ---------------------------------------------------------------------
 * Formatting
 * ------------------------------------------------------------------- */

/**
 * Formats address parts. The only place address fields are joined.
 *
 * @param array  $parts Address parts (or a venue record).
 * @param string $style 'single_line', 'multi_line' or 'ics'.
 * @return string
 */
function teatatu_data_format_address( $parts, $style = 'single_line' ) {
	$unit        = isset( $parts['unit'] ) ? (string) $parts['unit'] : '';
	$street_line = trim(
		implode(
			' ',
			array_filter(
				array(
					'' !== $unit ? ( ctype_digit( $unit ) ? $unit . '/' : $unit . ',' ) : '',
					isset( $parts['number'] ) ? (string) $parts['number'] : '',
					isset( $parts['street'] ) ? (string) $parts['street'] : '',
				)
			)
		)
	);
	$street_line = str_replace( '/ ', '/', $street_line );
	$city_line   = trim( ( isset( $parts['city'] ) ? $parts['city'] : '' ) . ' ' . ( isset( $parts['postcode'] ) ? $parts['postcode'] : '' ) );
	$lines       = array_filter( array( $street_line, isset( $parts['suburb'] ) ? (string) $parts['suburb'] : '', $city_line ) );
	return implode( 'multi_line' === $style ? "\n" : ', ', $lines );
}

/**
 * schema.org PostalAddress properties for a venue.
 *
 * @param array $record Venue record.
 * @return array
 */
function teatatu_data_venue_postal_address( $record ) {
	$street = teatatu_data_format_address( array_intersect_key( $record, array_flip( array( 'unit', 'number', 'street' ) ) ) );
	if ( ! empty( $record['suburb'] ) ) {
		$street = trim( $street . ', ' . $record['suburb'], ', ' );
	}
	return array_filter(
		array(
			'@type'           => 'PostalAddress',
			'streetAddress'   => $street,
			'addressLocality' => isset( $record['city'] ) ? (string) $record['city'] : '',
			'addressRegion'   => isset( $record['region'] ) ? (string) $record['region'] : '',
			'postalCode'      => isset( $record['postcode'] ) ? (string) $record['postcode'] : '',
			'addressCountry'  => isset( $record['country'] ) ? (string) $record['country'] : '',
		)
	);
}

/**
 * A venue's map link: its own, or a map search for its address.
 *
 * @param array $record Venue record.
 * @return string
 */
function teatatu_data_venue_map_url( $record ) {
	if ( ! empty( $record['map_url'] ) ) {
		return (string) $record['map_url'];
	}
	if ( ! empty( $record['location']['lat'] ) && ! empty( $record['location']['lng'] ) ) {
		$query = $record['location']['lat'] . ',' . $record['location']['lng'];
	} else {
		$query = trim( $record['name'] . ', ' . teatatu_data_format_address( $record ), ', ' );
	}
	return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $query );
}

/* ---------------------------------------------------------------------
 * Matching
 * ------------------------------------------------------------------- */

/**
 * Matches a place to a venue: by Location name or alias, then by normalised
 * street address (number + street, and suburb, city or postcode), then by
 * coordinates within teatatu_data_venue_match_distance metres. A deprecated
 * venue that was replaced resolves to its replacement.
 *
 * @param string       $location Location name (may be '').
 * @param array|string $address  Address parts, or free text.
 * @param float|null   $lat      Latitude.
 * @param float|null   $lng      Longitude.
 * @return array|null {record, via: 'name'|'alias'|'address'|'coords'}
 */
function teatatu_data_match_venue( $location, $address = array(), $lat = null, $lng = null ) {
	return teatatu_data_on_master( 'teatatu_data_match_venue_local', array( $location, $address, $lat, $lng ) );
}

/**
 * Matches a place on the current site. See teatatu_data_match_venue().
 *
 * @param string       $location Location name.
 * @param array|string $address  Address parts or text.
 * @param float|null   $lat      Latitude.
 * @param float|null   $lng      Longitude.
 * @param array|null   $venues   Records to match against (default: active and deprecated venues).
 * @return array|null
 */
function teatatu_data_match_venue_local( $location, $address = array(), $lat = null, $lng = null, $venues = null ) {
	if ( null === $venues ) {
		$venues = teatatu_data_find_local( 'venue', array( 'status' => array( 'active', 'deprecated' ), 'per_page' => -1 ) )['records'];
	}
	if ( ! $venues ) {
		return null;
	}
	$parts = is_array( $address ) ? $address : teatatu_data_parse_address_text( (string) $address );
	if ( '' === trim( (string) $location ) && ! empty( $parts['name'] ) ) {
		$location = $parts['name'];
	}
	$found = function ( $record, $via ) {
		// Follow a merge to the record that replaced this one.
		if ( 'deprecated' === $record['status'] && ! empty( $record['replaced_by'] ) ) {
			$replacement = teatatu_data_get_local( 'venue', $record['replaced_by'] );
			if ( $replacement && 'active' === $replacement['status'] ) {
				$record = $replacement;
			}
		}
		return array(
			'record' => $record,
			'via'    => $via,
		);
	};

	// 1. Location name, then alias.
	$wanted = teatatu_data_fold_text( $location );
	if ( '' !== $wanted ) {
		foreach ( $venues as $venue ) {
			if ( teatatu_data_fold_text( $venue['name'] ) === $wanted ) {
				return $found( $venue, 'name' );
			}
		}
		foreach ( $venues as $venue ) {
			$aliases = array_filter( array_map( 'teatatu_data_fold_text', explode( ',', (string) $venue['aliases'] ) ) );
			if ( in_array( $wanted, $aliases, true ) ) {
				return $found( $venue, 'alias' );
			}
		}
	}

	// 2. Street address: number + street, and suburb or city or postcode.
	$key = teatatu_data_address_match_key( $parts );
	if ( $key ) {
		list( $num, $street, $suburb, $city, $postcode ) = explode( '|', $key );
		foreach ( $venues as $venue ) {
			if ( empty( $venue['match_key'] ) ) {
				continue;
			}
			list( $vnum, $vstreet, $vsuburb, $vcity, $vpostcode ) = array_pad( explode( '|', $venue['match_key'] ), 5, '' );
			if ( $vstreet !== $street ) {
				continue;
			}
			$locality = ( $suburb && $suburb === $vsuburb ) || ( $city && $city === $vcity ) || ( $postcode && $postcode === $vpostcode ) || ( ! $suburb && ! $city && ! $postcode );
			if ( ! $locality ) {
				continue;
			}
			if ( $num && $vnum && $num === $vnum ) {
				return $found( $venue, 'address' );
			}
			if ( ! $vnum && $wanted && false !== strpos( teatatu_data_fold_text( $venue['name'] ), $wanted ) ) {
				return $found( $venue, 'address' );
			}
		}
	}

	// 3. Coordinates.
	/**
	 * Filters how close (metres) imported coordinates must be to a venue to match it.
	 *
	 * @param float $metres Default 50.
	 */
	$distance = (float) apply_filters( 'teatatu_data_venue_match_distance', 50 );
	if ( is_numeric( $lat ) && is_numeric( $lng ) && $distance > 0 ) {
		$best = null;
		foreach ( $venues as $venue ) {
			if ( empty( $venue['location'] ) ) {
				continue;
			}
			$d = teatatu_data_distance_m( (float) $lat, (float) $lng, $venue['location']['lat'], $venue['location']['lng'] );
			if ( $d <= $distance && ( ! $best || $d < $best[1] ) ) {
				$best = array( $venue, $d );
			}
		}
		if ( $best ) {
			return $found( $best[0], 'coords' );
		}
	}
	return null;
}

/* ---------------------------------------------------------------------
 * Keeping neighbourhoods current
 * ------------------------------------------------------------------- */

add_action( 'teatatu_data_record_changed', 'teatatu_data_queue_venue_rederive', 10, 2 );

/**
 * When street rules or neighbourhoods change, queue a one-off job to work
 * out every venue's neighbourhood again.
 *
 * @param string $key Data set key.
 */
function teatatu_data_queue_venue_rederive( $key ) {
	if ( in_array( $key, array( 'street_rule', 'neighbourhood' ), true ) && ! wp_next_scheduled( 'teatatu_data_rederive_venues' ) ) {
		wp_schedule_single_event( time() + 30, 'teatatu_data_rederive_venues' );
	}
}

add_action( 'teatatu_data_rederive_venues', 'teatatu_data_run_venue_rederive' );

/**
 * Re-works out every venue's neighbourhood.
 *
 * @return int Venues that changed.
 */
function teatatu_data_run_venue_rederive() {
	return teatatu_data_rederive( 'venue', __( 'Neighbourhood worked out again after the street rules or neighbourhoods changed.', 'teatatu-data' ) );
}

/* ---------------------------------------------------------------------
 * Bringing venues in from Teatatu Events
 * ------------------------------------------------------------------- */

/**
 * Every Teatatu Events venue on every site, as Teatatu Data values. Read
 * straight from the tables, so it works whether or not Events is active.
 *
 * @return array[] {site_id, term_id, values}
 */
function teatatu_data_events_venue_candidates() {
	$candidates = array();
	foreach ( teatatu_data_read_terms_everywhere( 'teatatu_events_venue', 'teatatu_events_' ) as $term ) {
		$meta   = $term['meta'];
		$get    = function ( $k ) use ( $meta ) {
			return isset( $meta[ $k ] ) ? trim( $meta[ $k ] ) : '';
		};
		$values = array(
			'name'      => $term['name'],
			'aliases'   => $get( 'venue_aliases' ),
			'unit'      => $get( 'addr_unit' ),
			'number'    => $get( 'addr_number' ),
			'street'    => $get( 'addr_street' ),
			'suburb'    => $get( 'addr_suburb' ),
			'city'      => $get( 'addr_city' ),
			'region'    => $get( 'addr_region' ),
			'postcode'  => $get( 'addr_postcode' ),
			'country'   => $get( 'addr_country' ),
			'website'   => $get( 'venue_website_url' ),
			'map_url'   => $get( 'venue_map_url' ),
			'image_url' => $get( 'venue_default_image' ),
		);
		if ( is_numeric( $get( 'addr_lat' ) ) && is_numeric( $get( 'addr_lng' ) ) ) {
			$values['location'] = array(
				'lat' => $get( 'addr_lat' ),
				'lng' => $get( 'addr_lng' ),
			);
		}
		$override = $get( 'addr_neighbourhood_override' );
		if ( '' !== $override ) {
			$neighbourhood = teatatu_data_neighbourhood_by_slug( $override );
			if ( $neighbourhood ) {
				$values['neighbourhood_override'] = $neighbourhood['uuid'];
			}
		}
		$values['external_ids'] = array( 'events_s' . $term['site_id'] => (string) $term['term_id'] );
		$values['source']       = 'Teatatu Events';
		$candidates[]           = array(
			'site_id' => $term['site_id'],
			'term_id' => $term['term_id'],
			'values'  => array_filter(
				$values,
				function ( $v ) {
					return '' !== $v;
				}
			),
		);
	}
	return $candidates;
}

/**
 * Proposes every Teatatu Events venue as one batch: venues already here
 * get their Events IDs (and any new names) added; duplicates across sites
 * become one new venue. Call on the Master Site.
 *
 * Events venues already waiting in a pending proposal are skipped, so it is
 * safe to run again before the batch is approved.
 *
 * @return array {batch, created, updated, merged, unchanged, pending, errors: string[]}
 */
function teatatu_data_import_events_venues() {
	$batch    = 'events-venues-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 4, false );
	$result   = array(
		'batch'     => $batch,
		'created'   => 0,
		'updated'   => 0,
		'merged'    => 0,
		'unchanged' => 0,
		'pending'   => 0,
		'errors'    => array(),
	);
	$waiting = array();
	foreach ( teatatu_data_get_changes_local( array( 'dataset' => 'venue', 'state' => 'pending', 'per_page' => -1 ) )['changes'] as $change ) {
		foreach ( isset( $change['values']['external_ids'] ) ? (array) $change['values']['external_ids'] : array() as $system => $id ) {
			$waiting[ $system . '=' . $id ] = true;
		}
	}
	$existing = teatatu_data_find_local( 'venue', array( 'status' => array( 'active', 'deprecated', 'proposed' ), 'per_page' => -1 ) )['records'];
	$updates  = array(); // uuid => values.
	$new      = array(); // list of {values, sources, record-like for matching}.

	foreach ( teatatu_data_events_venue_candidates() as $candidate ) {
		$values = $candidate['values'];
		$parts  = teatatu_data_venue_parts( $values );
		$lat    = isset( $values['location'] ) ? $values['location']['lat'] : null;
		$lng    = isset( $values['location'] ) ? $values['location']['lng'] : null;
		$label  = sprintf( '%s (site %d, venue %d)', $values['name'], $candidate['site_id'], $candidate['term_id'] );
		if ( isset( $waiting[ 'events_s' . $candidate['site_id'] . '=' . $candidate['term_id'] ] ) ) {
			$result['pending']++;
			continue;
		}

		// Already a venue here?
		$match = teatatu_data_match_venue_local( $values['name'], $parts, $lat, $lng, $existing );
		if ( $match ) {
			$record = $match['record'];
			$uuid   = $record['uuid'];
			$base   = isset( $updates[ $uuid ] ) ? $updates[ $uuid ] : array(
				'external_ids' => $record['external_ids'],
				'aliases'      => (string) $record['aliases'],
			);
			$base['external_ids'] = array_merge( (array) $base['external_ids'], $values['external_ids'] );
			$base['aliases']      = teatatu_data_merge_aliases( $base['aliases'], array( $values['name'] ), $record['name'] );
			$updates[ $uuid ]     = $base;
			continue;
		}

		// A duplicate of one already gathered from another site?
		$pending = array();
		foreach ( $new as $i => $item ) {
			$pending[ $i ] = array_merge(
				$item['values'],
				array(
					'uuid'      => (string) $i,
					'status'    => 'active',
					'aliases'   => isset( $item['values']['aliases'] ) ? $item['values']['aliases'] : '',
					'match_key' => teatatu_data_address_match_key( teatatu_data_venue_parts( $item['values'] ) ),
					'location'  => isset( $item['values']['location'] ) ? array( 'lat' => (float) $item['values']['location']['lat'], 'lng' => (float) $item['values']['location']['lng'] ) : null,
				)
			);
		}
		$dupe = $pending ? teatatu_data_match_venue_local( $values['name'], $parts, $lat, $lng, array_values( $pending ) ) : null;
		if ( $dupe ) {
			$i                                 = (int) $dupe['record']['uuid'];
			$new[ $i ]['values']['external_ids'] = array_merge( $new[ $i ]['values']['external_ids'], $values['external_ids'] );
			$new[ $i ]['values']['aliases']      = teatatu_data_merge_aliases( isset( $new[ $i ]['values']['aliases'] ) ? $new[ $i ]['values']['aliases'] : '', array( $values['name'], isset( $values['aliases'] ) ? $values['aliases'] : '' ), $new[ $i ]['values']['name'] );
			$new[ $i ]['sources'][]              = $label;
			$result['merged']++;
			continue;
		}
		$values['aliases'] = teatatu_data_merge_aliases( isset( $values['aliases'] ) ? $values['aliases'] : '', array(), $values['name'] );
		$new[]             = array(
			'values'  => $values,
			'sources' => array( $label ),
		);
	}

	foreach ( $updates as $uuid => $values ) {
		$change = teatatu_data_propose_local(
			'venue',
			$uuid,
			$values,
			array(
				'channel' => 'migration',
				'reason'  => __( 'Link to the matching Teatatu Events venue(s).', 'teatatu-data' ),
				'batch'   => $batch,
			)
		);
		if ( is_wp_error( $change ) ) {
			if ( 'teatatu_data_no_change' === $change->get_error_code() ) {
				$result['unchanged']++;
			} else {
				$result['errors'][] = $uuid . ': ' . teatatu_data_error_text( $change );
			}
		} else {
			$result['updated']++;
		}
	}
	foreach ( $new as $item ) {
		$change = teatatu_data_propose_local(
			'venue',
			null,
			$item['values'],
			array(
				'channel' => 'migration',
				/* translators: %s: Events venues the record comes from */
				'reason'  => sprintf( __( 'From Teatatu Events: %s.', 'teatatu-data' ), implode( '; ', $item['sources'] ) ),
				'batch'   => $batch,
			)
		);
		if ( is_wp_error( $change ) ) {
			$result['errors'][] = implode( '; ', $item['sources'] ) . ': ' . teatatu_data_error_text( $change );
		} else {
			$result['created']++;
		}
	}
	return $result;
}

/* ---------------------------------------------------------------------
 * Admin helpers
 * ------------------------------------------------------------------- */

add_action( 'teatatu_data_records_list_before', 'teatatu_data_venue_admin_tools' );

/**
 * Venues tab: "Bring in venues from Teatatu Events" and "Find a venue".
 *
 * @param string $key Data set key.
 */
function teatatu_data_venue_admin_tools( $key ) {
	if ( 'venue' !== $key ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only lookup.
	$find = isset( $_GET['ttd_find'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_GET['ttd_find'] ) ) : null;
	$find = wp_parse_args( (array) $find, array( 'location' => '', 'address' => '', 'lat' => '', 'lng' => '' ) );
	?>
	<div class="card" style="max-width:none;">
		<?php if ( teatatu_data_is_master_site() && current_user_can( 'import_teatatu_data' ) ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:8px 0;">
				<?php wp_nonce_field( 'teatatu_data_import_events_venues' ); ?>
				<input type="hidden" name="action" value="teatatu_data_import_events_venues" />
				<?php submit_button( __( 'Bring in venues from Teatatu Events', 'teatatu-data' ), 'secondary', 'submit', false ); ?>
				<span class="description"><?php esc_html_e( 'Gathers the venues on every site, merges duplicates, and sends them as one batch of proposed changes to review. Venues already here get the Events IDs added. Safe to run again: venues already here or waiting for review are not proposed twice.', 'teatatu-data' ); ?></span>
			</form>
		<?php endif; ?>
		<h3 style="margin-top:8px;"><?php esc_html_e( 'Find a venue', 'teatatu-data' ); ?></h3>
		<form method="get">
			<input type="hidden" name="page" value="teatatu-data" />
			<input type="hidden" name="tab" value="records" />
			<input type="hidden" name="dataset" value="venue" />
			<input type="text" name="ttd_find[location]" value="<?php echo esc_attr( $find['location'] ); ?>" placeholder="<?php esc_attr_e( 'Name', 'teatatu-data' ); ?>" />
			<input type="text" name="ttd_find[address]" value="<?php echo esc_attr( $find['address'] ); ?>" placeholder="<?php esc_attr_e( 'Address', 'teatatu-data' ); ?>" class="regular-text" />
			<input type="text" name="ttd_find[lat]" value="<?php echo esc_attr( $find['lat'] ); ?>" placeholder="<?php esc_attr_e( 'Latitude', 'teatatu-data' ); ?>" style="width:8em;" />
			<input type="text" name="ttd_find[lng]" value="<?php echo esc_attr( $find['lng'] ); ?>" placeholder="<?php esc_attr_e( 'Longitude', 'teatatu-data' ); ?>" style="width:8em;" />
			<?php submit_button( __( 'Find', 'teatatu-data' ), 'secondary', '', false ); ?>
		</form>
		<?php if ( isset( $_GET['ttd_find'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<?php
			$match = teatatu_data_match_venue( $find['location'], $find['address'], '' === $find['lat'] ? null : $find['lat'], '' === $find['lng'] ? null : $find['lng'] );
			$vias  = array(
				'name'    => __( 'by name', 'teatatu-data' ),
				'alias'   => __( 'by another name', 'teatatu-data' ),
				'address' => __( 'by address', 'teatatu-data' ),
				'coords'  => __( 'by coordinates', 'teatatu-data' ),
			);
			?>
			<p><strong>
				<?php if ( $match ) : ?>
					<a href="<?php echo esc_url( teatatu_data_admin_url( array( 'tab' => 'records', 'dataset' => 'venue', 'action' => 'edit', 'uuid' => $match['record']['uuid'] ) ) ); ?>"><?php echo esc_html( $match['record']['name'] ); ?></a>
					— <?php echo esc_html( teatatu_data_format_address( $match['record'] ) ); ?> (<?php echo esc_html( $vias[ $match['via'] ] ); ?>)
				<?php else : ?>
					<?php esc_html_e( 'No venue matches.', 'teatatu-data' ); ?>
				<?php endif; ?>
			</strong></p>
		<?php endif; ?>
	</div>
	<?php
}

add_action( 'teatatu_data_record_form_before', 'teatatu_data_venue_paste_address', 10, 3 );

/**
 * "Paste address" helper above the venue form: splits a one-line address
 * into the fields (the same parser imports use) for the editor to check.
 *
 * @param string     $key      Data set key.
 * @param array|null $record   Record.
 * @param bool       $writable Whether the form can be submitted.
 */
function teatatu_data_venue_paste_address( $key, $record, $writable ) {
	if ( 'venue' !== $key || ! $writable ) {
		return;
	}
	?>
	<div class="card" style="max-width:none;">
		<p><label for="ttd_paste_address"><strong><?php esc_html_e( 'Paste address', 'teatatu-data' ); ?></strong></label>
		<span class="description"><?php esc_html_e( 'Splits a one-line address into the fields below. Check them before saving.', 'teatatu-data' ); ?></span></p>
		<input type="text" id="ttd_paste_address" class="large-text" placeholder="<?php esc_attr_e( 'e.g. Unit 2, 15 Smith St, Te Atatū Peninsula, Auckland 0610', 'teatatu-data' ); ?>" />
		<p><button type="button" class="button" id="ttd_paste_go"><?php esc_html_e( 'Fill in the fields', 'teatatu-data' ); ?></button> <span id="ttd_paste_msg" class="description"></span></p>
	</div>
	<script>
	( function () {
		var button = document.getElementById( 'ttd_paste_go' );
		button.addEventListener( 'click', function () {
			var text = document.getElementById( 'ttd_paste_address' ).value;
			var msg  = document.getElementById( 'ttd_paste_msg' );
			msg.textContent = '';
			fetch( <?php echo wp_json_encode( rest_url( 'teatatu-data/v1/venues/parse' ) ); ?> + '?text=' + encodeURIComponent( text ), {
				headers: { 'X-WP-Nonce': <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?> },
				credentials: 'same-origin'
			} ).then( function ( r ) { return r.json(); } ).then( function ( parts ) {
				var filled = 0;
				[ 'unit', 'number', 'street', 'suburb', 'city', 'region', 'postcode', 'country' ].forEach( function ( f ) {
					var input = document.getElementById( 'ttd_f_' + f );
					if ( input && parts[ f ] ) { input.value = parts[ f ]; filled++; }
				} );
				var name = document.getElementById( 'ttd_f_name' );
				if ( name && ! name.value && parts.name ) { name.value = parts.name; filled++; }
				msg.textContent = filled ? <?php echo wp_json_encode( __( 'Filled in — check the fields.', 'teatatu-data' ) ); ?> : <?php echo wp_json_encode( __( 'Could not recognise that address.', 'teatatu-data' ) ); ?>;
			} ).catch( function () {
				msg.textContent = <?php echo wp_json_encode( __( 'Could not reach the server.', 'teatatu-data' ) ); ?>;
			} );
		} );
	} )();
	</script>
	<?php
}

add_action( 'admin_post_teatatu_data_import_events_venues', 'teatatu_data_handle_import_events_venues' );

/**
 * Handles "Bring in venues from Teatatu Events".
 */
function teatatu_data_handle_import_events_venues() {
	check_admin_referer( 'teatatu_data_import_events_venues' );
	if ( ! current_user_can( 'import_teatatu_data' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-data' ), 403 );
	}
	teatatu_data_admin_require_master();
	$result = teatatu_data_import_events_venues();
	/* translators: 1: new venues, 2: venues to update, 3: duplicates merged, 4: already up to date */
	$message = sprintf( __( 'Teatatu Events venues: %1$d new, %2$d to link to existing venues, %3$d duplicates merged, %4$d already up to date.', 'teatatu-data' ), $result['created'], $result['updated'], $result['merged'], $result['unchanged'] );
	if ( $result['pending'] ) {
		/* translators: %d: venues skipped */
		$message .= ' ' . sprintf( __( '%d skipped because they are already waiting for review.', 'teatatu-data' ), $result['pending'] );
	}
	if ( $result['errors'] ) {
		$message .= ' ' . __( 'Not proposed:', 'teatatu-data' ) . ' ' . implode( ' · ', $result['errors'] );
	}
	set_transient( 'teatatu_data_message_' . get_current_user_id(), $message, 5 * MINUTE_IN_SECONDS );
	$args = array( 'tab' => 'proposals' );
	if ( $result['created'] || $result['updated'] ) {
		$args['batch'] = $result['batch'];
	}
	wp_safe_redirect( add_query_arg( 'ttd_notice', $result['errors'] ? 'error_message' : 'message', teatatu_data_admin_url( $args ) ) );
	exit;
}

/* ---------------------------------------------------------------------
 * REST
 * ------------------------------------------------------------------- */

add_action( 'rest_api_init', 'teatatu_data_register_venue_rest_routes' );

/**
 * GET /teatatu-data/v1/venues/match and /venues/parse.
 */
function teatatu_data_register_venue_rest_routes() {
	$text = array(
		'type'    => 'string',
		'default' => '',
	);
	$can  = function () {
		return current_user_can( 'read_teatatu_data' );
	};
	register_rest_route(
		'teatatu-data/v1',
		'/venues/match',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'teatatu_data_rest_venue_match',
			'permission_callback' => $can,
			'args'                => array(
				'location' => $text,
				'address'  => $text,
				'lat'      => $text,
				'lng'      => $text,
			),
		)
	);
	register_rest_route(
		'teatatu-data/v1',
		'/venues/parse',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => function ( $request ) {
				return rest_ensure_response( teatatu_data_parse_address_text( sanitize_text_field( (string) $request['text'] ) ) );
			},
			'permission_callback' => $can,
			'args'                => array( 'text' => $text ),
		)
	);
}

/**
 * GET /venues/match: the matching venue (if any), the parsed address, and
 * the neighbourhood (the venue's, or worked out from the address).
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function teatatu_data_rest_venue_match( $request ) {
	$location = sanitize_text_field( (string) $request['location'] );
	$parts    = teatatu_data_parse_address_text( sanitize_text_field( (string) $request['address'] ) );
	$lat      = is_numeric( $request['lat'] ) ? (float) $request['lat'] : null;
	$lng      = is_numeric( $request['lng'] ) ? (float) $request['lng'] : null;
	$match    = teatatu_data_match_venue( $location, $parts, $lat, $lng );
	$venue    = null;
	$nbhd     = null;
	if ( $match ) {
		$venue = array_merge(
			$match['record'],
			array(
				'address' => teatatu_data_format_address( $match['record'] ),
				'matched' => $match['via'],
			)
		);
		if ( $match['record']['neighbourhood'] ) {
			$record = teatatu_data_get( 'neighbourhood', $match['record']['neighbourhood'] );
			$nbhd   = $record ? array( 'uuid' => $record['uuid'], 'slug' => $record['slug'], 'name' => $record['name'], 'via' => 'venue' ) : null;
		}
	} else {
		$resolved = teatatu_data_resolve_neighbourhood( array_merge( $parts, array( 'lat' => null === $lat ? '' : $lat, 'lng' => null === $lng ? '' : $lng ) ) );
		if ( $resolved['uuid'] ) {
			$nbhd = array( 'uuid' => $resolved['uuid'], 'slug' => $resolved['slug'], 'name' => $resolved['name'], 'via' => $resolved['via'] );
		}
	}
	unset( $parts['name'] );
	return rest_ensure_response(
		array(
			'venue'         => $venue,
			'parsed'        => $parts,
			'neighbourhood' => $nbhd,
		)
	);
}

/* ---------------------------------------------------------------------
 * Quality
 * ------------------------------------------------------------------- */

/**
 * Venues that may be the same: same name or other name, same street
 * address (with a number), or within about 10 m of each other.
 *
 * @param array $record Venue.
 * @return string[]
 */
function teatatu_data_venue_duplicate_keys( $record ) {
	$keys = array();
	foreach ( array_merge( array( $record['name'] ), explode( ',', (string) $record['aliases'] ) ) as $name ) {
		$folded = teatatu_data_fold_text( $name );
		if ( '' !== $folded ) {
			$keys[] = 'name:' . $folded;
		}
	}
	if ( $record['match_key'] && 0 !== strpos( $record['match_key'], '|' ) ) {
		// Number + street + suburb (or city); a missing postcode shouldn't hide a duplicate.
		$bits     = array_pad( explode( '|', $record['match_key'] ), 5, '' );
		$locality = '' !== $bits[2] ? $bits[2] : $bits[3];
		$keys[]   = 'address:' . $bits[0] . '|' . $bits[1] . '|' . $locality;
	}
	if ( $record['location'] ) {
		$keys[] = 'geo:' . round( $record['location']['lat'], 4 ) . ',' . round( $record['location']['lng'], 4 );
	}
	return array_values( array_unique( $keys ) );
}

/**
 * Venue checks: a peninsula address no rule places (completeness), and
 * coordinates that disagree with the address (accuracy).
 *
 * @param array[] $active Active venues.
 * @return array[] Issues.
 */
function teatatu_data_venue_quality_checks( $active ) {
	$issues   = array();
	$geometry = teatatu_data_neighbourhood_geometry();
	foreach ( $active as $venue ) {
		if ( $venue['needs_neighbourhood'] ) {
			/* translators: %s: venue */
			$issues[] = teatatu_data_quality_issue( 'needs_neighbourhood', 'completeness', array( $venue['uuid'] ), sprintf( __( '%s is on Te Atatū Peninsula but no street rule places it: fix the address or set a neighbourhood override.', 'teatatu-data' ), $venue['name'] ) );
		}
		if ( ! $venue['location'] ) {
			continue;
		}
		$lat = $venue['location']['lat'];
		$lng = $venue['location']['lng'];
		if ( 'NZ' === $venue['country'] && ( $lat > -34 || $lat < -48 || $lng < 166 || $lng > 179 ) ) {
			/* translators: %s: venue */
			$issues[] = teatatu_data_quality_issue( 'coords_country', 'accuracy', array( $venue['uuid'] ), sprintf( __( '%s has coordinates outside New Zealand (latitude and longitude may be swapped).', 'teatatu-data' ), $venue['name'] ) );
			continue;
		}
		if ( empty( $geometry['peninsula'] ) ) {
			continue;
		}
		$inside = teatatu_data_point_in_polygon( $lng, $lat, $geometry['peninsula'] );
		$says   = teatatu_data_suburb_is_peninsula( $venue['suburb'] );
		if ( true === $says && ! $inside ) {
			/* translators: %s: venue */
			$issues[] = teatatu_data_quality_issue( 'coords_suburb', 'accuracy', array( $venue['uuid'] ), sprintf( __( '%s says Te Atatū Peninsula, but its coordinates are outside the peninsula.', 'teatatu-data' ), $venue['name'] ) );
		} elseif ( false === $says && $inside ) {
			/* translators: 1: venue, 2: suburb */
			$issues[] = teatatu_data_quality_issue( 'coords_suburb', 'accuracy', array( $venue['uuid'] ), sprintf( __( '%1$s says %2$s, but its coordinates are on Te Atatū Peninsula.', 'teatatu-data' ), $venue['name'], $venue['suburb'] ) );
		}
	}
	return $issues;
}
