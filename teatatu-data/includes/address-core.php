<?php
/**
 * Pure-PHP address and neighbourhood helpers with no WordPress dependency,
 * so the same code runs inside the plugin and in the command-line build
 * tool (tools/build-neighbourhood-streets.php). Ported from Teatatu Events
 * (includes/address-core.php): text folding, street normalising, the
 * free-text address parser, the address match key, street rules, and the
 * boundary geometry.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'TEATATU_DATA_CLI' ) ) {
	exit;
}

/**
 * Lower-cases, strips macrons/accents, punctuation and repeated spaces.
 *
 * @param string $text Text.
 * @return string
 */
function teatatu_data_fold_text( $text ) {
	$text = (string) $text;
	$map  = array(
		'ā' => 'a', 'ē' => 'e', 'ī' => 'i', 'ō' => 'o', 'ū' => 'u',
		'Ā' => 'a', 'Ē' => 'e', 'Ī' => 'i', 'Ō' => 'o', 'Ū' => 'u',
		'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
		'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o',
		'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
		'’' => "'", '‘' => "'", '–' => '-', '—' => '-',
	);
	$text = strtr( $text, $map );
	$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	$text = preg_replace( "/[^a-z0-9\\s\\/-]+/u", ' ', $text );
	$text = preg_replace( '/\s+/', ' ', $text );
	return trim( $text );
}

/**
 * Street-type abbreviations, expanded when they are the last word of a
 * street name ("St" at the start of a name is usually "Saint", so only the
 * final word is ever expanded).
 *
 * @return string[]
 */
function teatatu_data_street_type_abbreviations() {
	return array(
		'st'   => 'street',
		'rd'   => 'road',
		'ave'  => 'avenue',
		'av'   => 'avenue',
		'dr'   => 'drive',
		'pl'   => 'place',
		'cres' => 'crescent',
		'cr'   => 'crescent',
		'tce'  => 'terrace',
		'hwy'  => 'highway',
		'ln'   => 'lane',
		'ct'   => 'court',
		'cl'   => 'close',
		'gr'   => 'grove',
		'pde'  => 'parade',
		'esp'  => 'esplanade',
		'sq'   => 'square',
		'blvd' => 'boulevard',
		'wy'   => 'way',
		'rise' => 'rise',
	);
}

/**
 * Normalises a street name for comparison: folded, with the final street
 * type expanded ("Te Atatū Rd" → "te atatu road").
 *
 * @param string $street Street name.
 * @return string
 */
function teatatu_data_normalize_street( $street ) {
	$folded = teatatu_data_fold_text( $street );
	if ( '' === $folded ) {
		return '';
	}
	$words = explode( ' ', $folded );
	$last  = end( $words );
	$abbr  = teatatu_data_street_type_abbreviations();
	if ( isset( $abbr[ $last ] ) ) {
		$words[ count( $words ) - 1 ] = $abbr[ $last ];
	}
	return implode( ' ', $words );
}

/**
 * Display name for a normalised street ("te atatu road" → "Te Atatū Road").
 *
 * @param string $key Normalised street.
 * @return string
 */
function teatatu_data_street_display_name( $key ) {
	$name = ucwords( (string) $key );
	return preg_replace( '/\bTe Atatu\b/', 'Te Atatū', $name );
}

/**
 * Parses a house number string into its unit and numeric street number:
 * "2/15" → unit 2, number 15; "12A" → 12; "12-14" → 12.
 *
 * @param string $raw Raw house number.
 * @return array {unit: string, number: string, int: int|null}
 */
function teatatu_data_parse_house_number( $raw ) {
	$raw  = trim( (string) $raw );
	$unit = '';
	if ( false !== strpos( $raw, '/' ) ) {
		list( $unit, $raw ) = array_map( 'trim', explode( '/', $raw, 2 ) );
	}
	$int = null;
	if ( preg_match( '/^\s*(\d+)/', $raw, $m ) ) {
		$int = (int) $m[1];
	}
	return array(
		'unit'   => $unit,
		'number' => $raw,
		'int'    => $int,
	);
}

/**
 * Whether a suburb name means Te Atatū Peninsula: true, false (another
 * suburb) or null (unknown/blank).
 *
 * @param string $suburb Suburb.
 * @return bool|null
 */
function teatatu_data_suburb_is_peninsula( $suburb ) {
	$folded = teatatu_data_fold_text( $suburb );
	if ( '' === $folded ) {
		return null;
	}
	return false !== strpos( $folded, 'te atatu peninsula' ) || in_array( $folded, array( 'te atatu', 'te atatu north', 'tat pen' ), true );
}

/**
 * Applies a street's rules to a house number.
 *
 * A rule is either an area (the whole street is in one area) or
 * {rules: [{parity, min, max, area}], below: area|null} where each range
 * covers house numbers by side of the road. Areas are neighbourhood keys,
 * or 'outside' (not on the peninsula).
 *
 * @param string|array $rule   Street rule.
 * @param int|null     $number Integer house number, or null when unknown.
 * @return string Area, 'outside', or '' (unresolved).
 */
function teatatu_data_apply_street_rule( $rule, $number ) {
	if ( is_string( $rule ) ) {
		return $rule;
	}
	if ( ! is_array( $rule ) || empty( $rule['rules'] ) || null === $number ) {
		return '';
	}
	foreach ( $rule['rules'] as $range ) {
		$parity = isset( $range['parity'] ) ? $range['parity'] : 'any';
		if ( 'odd' === $parity && 0 === $number % 2 ) {
			continue;
		}
		if ( 'even' === $parity && 1 === $number % 2 ) {
			continue;
		}
		$min = isset( $range['min'] ) ? (int) $range['min'] : 0;
		$max = isset( $range['max'] ) && null !== $range['max'] ? (int) $range['max'] : PHP_INT_MAX;
		if ( $number >= $min && $number <= $max ) {
			return (string) $range['area'];
		}
	}
	if ( ! empty( $rule['below'] ) ) {
		$lowest = PHP_INT_MAX;
		foreach ( $rule['rules'] as $range ) {
			$lowest = min( $lowest, isset( $range['min'] ) ? (int) $range['min'] : 0 );
		}
		if ( $number < $lowest ) {
			return (string) $rule['below'];
		}
	}
	return '';
}

/**
 * Interpolates along a polyline sorted by one axis.
 *
 * @param array[] $line Points as [lon, lat], sorted ascending by $axis.
 * @param float   $x    Value on the sorting axis.
 * @param int     $axis 0 = sorted by lon (returns lat), 1 = sorted by lat (returns lon).
 * @return float
 */
function teatatu_data_polyline_interpolate( $line, $x, $axis ) {
	$other = 1 - $axis;
	$n     = count( $line );
	if ( $x <= $line[0][ $axis ] ) {
		return $line[0][ $other ];
	}
	if ( $x >= $line[ $n - 1 ][ $axis ] ) {
		return $line[ $n - 1 ][ $other ];
	}
	for ( $i = 0; $i < $n - 1; $i++ ) {
		$a = $line[ $i ];
		$b = $line[ $i + 1 ];
		if ( $x >= $a[ $axis ] && $x <= $b[ $axis ] ) {
			$span = $b[ $axis ] - $a[ $axis ];
			$t    = $span ? ( $x - $a[ $axis ] ) / $span : 0;
			return $a[ $other ] + $t * ( $b[ $other ] - $a[ $other ] );
		}
	}
	return $line[ $n - 1 ][ $other ];
}

/**
 * Whether a point is inside a polygon (ray casting).
 *
 * @param float   $lon     Longitude.
 * @param float   $lat     Latitude.
 * @param array[] $polygon Ring of [lon, lat] points.
 * @return bool
 */
function teatatu_data_point_in_polygon( $lon, $lat, $polygon ) {
	$inside = false;
	$n      = count( $polygon );
	for ( $i = 0, $j = $n - 1; $i < $n; $j = $i++ ) {
		$xi = $polygon[ $i ][0];
		$yi = $polygon[ $i ][1];
		$xj = $polygon[ $j ][0];
		$yj = $polygon[ $j ][1];
		if ( ( ( $yi > $lat ) !== ( $yj > $lat ) ) && ( $lon < ( $xj - $xi ) * ( $lat - $yi ) / ( ( $yj - $yi ) ?: 1e-12 ) + $xi ) ) {
			$inside = ! $inside;
		}
	}
	return $inside;
}

/**
 * Classifies a coordinate into a neighbourhood using the boundary geometry:
 * south of the Taikata/Harbour View line is Harbourview; north of it, west
 * of Te Atatū Road is Matipo and east is Beach. Points outside the
 * peninsula outline are 'outside'.
 *
 * @param float $lon      Longitude.
 * @param float $lat      Latitude.
 * @param array $geometry {peninsula: ring, east_west: line sorted by lon, te_atatu_road: line sorted by lat}.
 * @return string 'matipo' | 'beach' | 'harbourview' | 'outside' | ''.
 */
function teatatu_data_classify_point( $lon, $lat, $geometry ) {
	if ( empty( $geometry['east_west'] ) || empty( $geometry['te_atatu_road'] ) ) {
		return '';
	}
	if ( ! empty( $geometry['peninsula'] ) && ! teatatu_data_point_in_polygon( $lon, $lat, $geometry['peninsula'] ) ) {
		return 'outside';
	}
	$line_lat = teatatu_data_polyline_interpolate( $geometry['east_west'], $lon, 0 );
	if ( $lat < $line_lat ) {
		return 'harbourview';
	}
	$road_lon = teatatu_data_polyline_interpolate( $geometry['te_atatu_road'], $lat, 1 );
	return $lon < $road_lon ? 'matipo' : 'beach';
}

/**
 * Words that mark the end of a street name (used to recognise a street with
 * no number, and to tell a street from a place name).
 *
 * @return string[]
 */
function teatatu_data_street_type_words() {
	return array_unique(
		array_merge(
			array_values( teatatu_data_street_type_abbreviations() ),
			array( 'road', 'street', 'avenue', 'drive', 'place', 'crescent', 'terrace', 'highway', 'lane', 'court', 'close', 'grove', 'parade', 'esplanade', 'square', 'boulevard', 'way', 'rise', 'mews', 'walk', 'track', 'glade', 'heights', 'view', 'loop', 'quay', 'circle', 'green', 'row' )
		)
	);
}

/**
 * Main NZ towns/cities, used to tell a city from a suburb in a free-text
 * address.
 *
 * @return string[] Folded names.
 */
function teatatu_data_known_cities() {
	return array(
		'auckland', 'wellington', 'christchurch', 'hamilton', 'tauranga', 'dunedin', 'palmerston north',
		'napier', 'nelson', 'rotorua', 'new plymouth', 'whangarei', 'invercargill', 'whanganui',
		'gisborne', 'hastings', 'porirua', 'lower hutt', 'upper hutt', 'queenstown', 'taupo', 'timaru',
		'masterton', 'blenheim', 'kapiti', 'pukekohe', 'waiheke island', 'west auckland',
	);
}

/**
 * Splits a free-text address into segments. It never guesses a segment it
 * cannot identify; those parts are left empty. A leading part that isn't a
 * street (e.g. a venue name) is returned as `name`.
 *
 * @param string $text            Free-text address, one line or several.
 * @param string $default_country ISO country code to assume.
 * @return array {name, unit, number, street, suburb, city, region, postcode, country}
 */
function teatatu_data_parse_address_text( $text, $default_country = 'NZ' ) {
	$out = array(
		'name'     => '',
		'unit'     => '',
		'number'   => '',
		'street'   => '',
		'suburb'   => '',
		'city'     => '',
		'region'   => '',
		'postcode' => '',
		'country'  => strtoupper( (string) $default_country ),
	);

	$text  = trim( preg_replace( '/\s+/u', ' ', str_replace( array( "\r", "\n" ), ', ', (string) $text ) ) );
	$parts = array_values( array_filter( array_map( 'trim', explode( ',', $text ) ), 'strlen' ) );
	if ( empty( $parts ) ) {
		return $out;
	}

	// Country (last part).
	$last = teatatu_data_fold_text( end( $parts ) );
	if ( in_array( $last, array( 'new zealand', 'nz', 'aotearoa', 'aotearoa new zealand' ), true ) ) {
		$out['country'] = 'NZ';
		array_pop( $parts );
	}

	// Postcode: a 4-digit token, often attached to the city ("Auckland 0610").
	foreach ( $parts as $i => $part ) {
		if ( preg_match( '/(?:^|\s)(\d{4})$/', $part, $m ) && ! preg_match( '/^\d+\s*[a-z]?\s+\S/i', $part ) ) {
			$out['postcode'] = $m[1];
			$parts[ $i ]     = trim( substr( $part, 0, -strlen( $m[1] ) ) );
			if ( '' === $parts[ $i ] ) {
				unset( $parts[ $i ] );
			}
		}
	}
	$parts = array_values( $parts );

	// "Unit 2, 15 Smith St": a unit/level part on its own joins the number
	// part after it (fixes the Events parser, which took it as a place name).
	foreach ( $parts as $i => $part ) {
		if ( isset( $parts[ $i + 1 ] ) && preg_match( '/^(?:unit|flat|apt|apartment|level|lvl|suite|shop)\s*[\w-]+$/iu', $part ) && preg_match( '/^\d/', $parts[ $i + 1 ] ) ) {
			$parts[ $i + 1 ] = $part . ' ' . $parts[ $i + 1 ];
			unset( $parts[ $i ] );
		}
	}
	$parts = array_values( $parts );

	$street_types = teatatu_data_street_type_words();
	$abbr_keys    = array_keys( teatatu_data_street_type_abbreviations() );
	$street_index = -1;

	foreach ( $parts as $i => $part ) {
		// "Unit 2, 15 Smith St" / "Level 1 15 Smith St" / "2/15 Smith St" / "15A Smith St".
		if ( preg_match( '/^(?:(unit|flat|apt|apartment|level|lvl|suite|shop)\s*([\w-]+)\s*,?\s+)?(\d+[a-z]?(?:\s*[-\/]\s*\d+[a-z]?)?)\s+(.+)$/iu', $part, $m ) ) {
			$hn = teatatu_data_parse_house_number( preg_replace( '/\s+/', '', $m[3] ) );
			if ( '' !== $m[2] ) {
				// A unit/flat is just its number ("2/15"); a level, suite or shop keeps its word ("Level 1, 15").
				$word        = strtolower( $m[1] );
				$out['unit'] = in_array( $word, array( 'unit', 'flat', 'apt', 'apartment' ), true ) ? $m[2] : ( 'lvl' === $word ? 'Level' : ucfirst( $word ) ) . ' ' . $m[2];
			} else {
				$out['unit'] = $hn['unit'];
			}
			$out['number']  = $hn['number'];
			$out['street']  = trim( $m[4] );
			$street_index   = $i;
			break;
		}
		$folded = teatatu_data_fold_text( $part );
		$words  = explode( ' ', $folded );
		$tail   = end( $words );
		if ( count( $words ) >= 2 && ( in_array( $tail, $street_types, true ) || in_array( $tail, $abbr_keys, true ) ) ) {
			$out['street'] = $part;
			$street_index  = $i;
			break;
		}
	}

	if ( $street_index > 0 ) {
		$out['name'] = implode( ', ', array_slice( $parts, 0, $street_index ) );
	}
	$rest = $street_index >= 0 ? array_slice( $parts, $street_index + 1 ) : $parts;
	if ( $street_index < 0 && count( $rest ) > 1 ) {
		// No street at all: treat the first part as a place name.
		$out['name'] = array_shift( $rest );
	}

	$cities = teatatu_data_known_cities();
	foreach ( $rest as $part ) {
		$folded = teatatu_data_fold_text( $part );
		if ( '' === $out['city'] && in_array( $folded, $cities, true ) ) {
			$out['city'] = $part;
		} elseif ( '' === $out['suburb'] && '' === $out['city'] ) {
			$out['suburb'] = $part;
		} elseif ( '' === $out['city'] ) {
			$out['city'] = $part;
		} elseif ( '' === $out['region'] ) {
			$out['region'] = $part;
		}
	}
	if ( $street_index < 0 && '' === $out['name'] && '' === $out['suburb'] && '' === $out['city'] && 1 === count( $parts ) ) {
		$out['name'] = $parts[0];
	}

	return $out;
}

/**
 * Builds the normalised comparison key used to match addresses: street
 * number + street name + suburb/city/postcode, folded and abbreviation-
 * expanded, with unit/level and country dropped.
 *
 * @param array $parts Address segments.
 * @return string
 */
function teatatu_data_address_match_key( $parts ) {
	$hn     = teatatu_data_parse_house_number( isset( $parts['number'] ) ? $parts['number'] : '' );
	$street = teatatu_data_normalize_street( isset( $parts['street'] ) ? $parts['street'] : '' );
	if ( '' === $street ) {
		return '';
	}
	$bits = array(
		null !== $hn['int'] ? (string) $hn['int'] . preg_replace( '/^\d+/', '', strtolower( $hn['number'] ) ) : '',
		$street,
		teatatu_data_fold_text( isset( $parts['suburb'] ) ? $parts['suburb'] : '' ),
		teatatu_data_fold_text( isset( $parts['city'] ) ? $parts['city'] : '' ),
		preg_replace( '/\D/', '', isset( $parts['postcode'] ) ? $parts['postcode'] : '' ),
	);
	return implode( '|', $bits );
}

/**
 * Great-circle distance in metres.
 *
 * @param float $lat1 Latitude 1.
 * @param float $lon1 Longitude 1.
 * @param float $lat2 Latitude 2.
 * @param float $lon2 Longitude 2.
 * @return float
 */
function teatatu_data_distance_m( $lat1, $lon1, $lat2, $lon2 ) {
	$r    = 6371000;
	$dlat = deg2rad( $lat2 - $lat1 );
	$dlon = deg2rad( $lon2 - $lon1 );
	$a    = sin( $dlat / 2 ) ** 2 + cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $dlon / 2 ) ** 2;
	return 2 * $r * asin( min( 1, sqrt( $a ) ) );
}
