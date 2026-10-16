<?php
/**
 * Connections to the consumer plugins (SPEC.md §9, §18): Teatatu Data
 * pushes its records into Teatatu Events' and Teatatu News' own storage —
 * taxonomy terms (linked by `teatatu_data_ref` term meta = record UUID),
 * their term meta, and Events' neighbourhood options — on every site where
 * the plugin is active. Events and News keep reading their own tables, so
 * their queries, URLs, shortcodes and REST are unchanged, and they keep
 * working on the last synced data if Teatatu Data is switched off.
 *
 * Each plugin's lists are only managed once a steward switches them on
 * (Connections tab). While managed, Events and News refuse direct edits to
 * those lists (their bridge files check teatatu_data_manages_taxonomy()),
 * except from the sync itself (teatatu_data_is_syncing()).
 *
 * Linking ("write-back"): an existing term is linked to its record by its
 * `teatatu_data_ref`, else by the `events_s<site>` / `news_s<site>` external
 * ID the bring-ins recorded, else by slug or name. Terms that can't be
 * linked are left alone and reported.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The consumer plugins and what Teatatu Data can manage in each.
 *
 * Groups are what a steward switches on; each lists the data sets it syncs.
 *
 * @return array consumer => {label, plugin, prefix, groups: group => {label, datasets: dataset => mapping}}
 */
function teatatu_data_connections() {
	return array(
		'events' => array(
			'label'  => 'Teatatu Events',
			'plugin' => 'teatatu-events/teatatu-events.php',
			'groups' => array(
				'neighbourhood'  => array(
					'label'    => __( 'Neighbourhoods and street rules', 'teatatu-data' ),
					'datasets' => array(
						'neighbourhood' => array( 'mode' => 'neighbourhood', 'taxonomy' => 'teatatu_events_neighbourhood', 'hierarchical' => false ),
						'street_rule'   => array( 'mode' => 'street_rules' ),
					),
				),
				'venue'          => array(
					'label'    => __( 'Venues', 'teatatu-data' ),
					'datasets' => array(
						'venue' => array( 'mode' => 'terms', 'taxonomy' => 'teatatu_events_venue', 'hierarchical' => false, 'meta' => 'teatatu_data_events_venue_meta', 'after' => 'teatatu_data_events_venue_after' ),
					),
				),
				'organisation'   => array(
					'label'    => __( 'Sources (Organisations)', 'teatatu-data' ),
					'datasets' => array(
						'organisation' => array( 'mode' => 'terms', 'taxonomy' => 'teatatu_events_source', 'hierarchical' => true, 'parent' => 'parent', 'meta' => 'teatatu_data_events_source_meta' ),
					),
				),
				'event_tag'      => array(
					'label'    => __( 'Event tags', 'teatatu-data' ),
					'datasets' => array(
						'event_tag' => array( 'mode' => 'terms', 'taxonomy' => 'teatatu_events_tag', 'hierarchical' => false, 'slug' => 'slug', 'meta' => 'teatatu_data_events_tag_meta' ),
					),
				),
				'event_category' => array(
					'label'    => __( 'Event categories', 'teatatu-data' ),
					'datasets' => array(
						'event_category' => array( 'mode' => 'terms', 'taxonomy' => 'teatatu_events_category', 'hierarchical' => true, 'parent' => 'parent', 'slug' => 'slug' ),
					),
				),
			),
		),
		'news'   => array(
			'label'  => 'Teatatu News',
			'plugin' => 'teatatu-news/teatatu-news.php',
			'groups' => array(
				'organisation'  => array(
					'label'    => __( 'Sources (Organisations)', 'teatatu-data' ),
					'datasets' => array(
						'organisation' => array( 'mode' => 'terms', 'taxonomy' => 'teatatu_news_source', 'hierarchical' => true, 'parent' => 'parent', 'meta' => 'teatatu_data_news_source_meta' ),
					),
				),
				'news_category' => array(
					'label'    => __( 'News categories', 'teatatu-data' ),
					'datasets' => array(
						'news_category' => array( 'mode' => 'terms', 'taxonomy' => 'teatatu_news_category', 'hierarchical' => true, 'parent' => 'parent', 'slug' => 'slug' ),
					),
				),
			),
		),
	);
}

/**
 * Which groups are managed: consumer => group keys.
 *
 * @return array
 */
function teatatu_data_managed_groups() {
	$managed = teatatu_data_get_option( 'teatatu_data_managed', array() );
	return is_array( $managed ) ? $managed : array();
}

/**
 * Whether Teatatu Data manages a consumer taxonomy right now. Events and
 * News call this (when it exists) to make their own lists read-only.
 *
 * @param string $taxonomy Taxonomy name.
 * @return bool
 */
function teatatu_data_manages_taxonomy( $taxonomy ) {
	$managed = teatatu_data_managed_groups();
	foreach ( teatatu_data_connections() as $consumer => $connection ) {
		foreach ( $connection['groups'] as $group => $def ) {
			if ( ! in_array( $group, isset( $managed[ $consumer ] ) ? (array) $managed[ $consumer ] : array(), true ) ) {
				continue;
			}
			foreach ( $def['datasets'] as $mapping ) {
				if ( isset( $mapping['taxonomy'] ) && $mapping['taxonomy'] === $taxonomy ) {
					return true;
				}
			}
		}
	}
	return false;
}

/**
 * Whether the network-level Events neighbourhood options (street rules,
 * slugs) are managed.
 *
 * @return bool
 */
function teatatu_data_manages_events_neighbourhoods() {
	$managed = teatatu_data_managed_groups();
	return isset( $managed['events'] ) && in_array( 'neighbourhood', (array) $managed['events'], true );
}

/**
 * Whether a sync is writing right now (consumers let these writes through).
 *
 * @return bool
 */
function teatatu_data_is_syncing() {
	return ! empty( $GLOBALS['teatatu_data_syncing'] );
}

/**
 * The URL of the Teatatu Data screen for a data set on the Master Site, for
 * consumers' "managed in Teatatu Data" notices.
 *
 * @param string $key Data set key ('' for the main screen).
 * @return string
 */
function teatatu_data_manage_url( $key = '' ) {
	$args = $key ? array( 'page' => 'teatatu-data', 'tab' => 'records', 'dataset' => $key ) : array( 'page' => 'teatatu-data' );
	$base = is_multisite() ? get_admin_url( teatatu_data_get_master_site_id(), 'admin.php' ) : admin_url( 'admin.php' );
	return add_query_arg( $args, $base );
}

/**
 * The data set behind a consumer taxonomy.
 *
 * @param string $taxonomy Taxonomy.
 * @return string Data set key, or ''.
 */
function teatatu_data_dataset_for_taxonomy( $taxonomy ) {
	foreach ( teatatu_data_connections() as $connection ) {
		foreach ( $connection['groups'] as $def ) {
			foreach ( $def['datasets'] as $key => $mapping ) {
				if ( isset( $mapping['taxonomy'] ) && $mapping['taxonomy'] === $taxonomy ) {
					return $key;
				}
			}
		}
	}
	return '';
}

/**
 * Sites where a plugin is active (network-activated: all sites).
 *
 * @param string $plugin Plugin basename.
 * @return int[]
 */
function teatatu_data_consumer_sites( $plugin ) {
	if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( ! is_multisite() ) {
		return in_array( $plugin, (array) get_option( 'active_plugins', array() ), true ) ? array( get_current_blog_id() ) : array();
	}
	$sites = array_map( 'intval', get_sites( array( 'fields' => 'ids', 'number' => 0, 'deleted' => 0, 'archived' => 0 ) ) );
	if ( is_plugin_active_for_network( $plugin ) ) {
		return $sites;
	}
	return array_values(
		array_filter(
			$sites,
			function ( $site_id ) use ( $plugin ) {
				return in_array( $plugin, (array) get_blog_option( $site_id, 'active_plugins', array() ), true );
			}
		)
	);
}

/* ---------------------------------------------------------------------
 * Term meta for each mapping
 * ------------------------------------------------------------------- */

/**
 * Events venue term meta from a venue record.
 *
 * @param array $record Venue.
 * @param array $ctx    {slugs: neighbourhood uuid => current Events slug}.
 * @return array meta key => value
 */
function teatatu_data_events_venue_meta( $record, $ctx ) {
	$override = $record['neighbourhood_override'] && isset( $ctx['slugs'][ $record['neighbourhood_override'] ] ) ? $ctx['slugs'][ $record['neighbourhood_override'] ] : '';
	$meta     = array(
		'teatatu_events_addr_unit'                    => (string) $record['unit'],
		'teatatu_events_addr_number'                  => (string) $record['number'],
		'teatatu_events_addr_street'                  => (string) $record['street'],
		'teatatu_events_addr_suburb'                  => (string) $record['suburb'],
		'teatatu_events_addr_city'                    => (string) $record['city'],
		'teatatu_events_addr_region'                  => (string) $record['region'],
		'teatatu_events_addr_postcode'                => (string) $record['postcode'],
		'teatatu_events_addr_country'                 => (string) $record['country'],
		'teatatu_events_addr_lat'                     => $record['location'] ? (string) $record['location']['lat'] : '',
		'teatatu_events_addr_lng'                     => $record['location'] ? (string) $record['location']['lng'] : '',
		'teatatu_events_venue_map_url'                => (string) $record['map_url'],
		'teatatu_events_venue_website_url'            => (string) $record['website'],
		'teatatu_events_venue_aliases'                => (string) $record['aliases'],
		'teatatu_events_venue_default_image'          => (string) $record['image_url'],
		'teatatu_events_addr_neighbourhood_override'  => $override,
		// Worked out here too, in case Events' own refresh isn't loaded.
		'teatatu_events_addr_normalized'              => (string) $record['match_key'],
		'teatatu_events_addr_neighbourhood'           => $record['neighbourhood'] && isset( $ctx['slugs'][ $record['neighbourhood'] ] ) ? $ctx['slugs'][ $record['neighbourhood'] ] : '',
		'teatatu_events_addr_neighbourhood_via'       => (string) $record['neighbourhood_via'],
	);
	return $meta;
}

/**
 * After a venue term is written: let Events recompute its derived values
 * and re-resolve the events held there, when Events is loaded.
 *
 * @param int $term_id Term ID.
 */
function teatatu_data_events_venue_after( $term_id ) {
	if ( function_exists( 'teatatu_events_refresh_venue' ) ) {
		teatatu_events_refresh_venue( $term_id );
	}
}

/**
 * Events source term meta.
 *
 * @param array $record Organisation.
 * @return array
 */
function teatatu_data_events_source_meta( $record ) {
	return array( 'teatatu_events_source_default_image' => (string) $record['image_url'] );
}

/**
 * Events tag term meta.
 *
 * @param array $record Event tag.
 * @return array
 */
function teatatu_data_events_tag_meta( $record ) {
	return array( 'teatatu_events_tag_color' => (string) $record['color'] );
}

/**
 * News source term meta.
 *
 * @param array $record Organisation.
 * @return array
 */
function teatatu_data_news_source_meta( $record ) {
	return array( 'teatatu_news_source_default_image' => (string) $record['image_url'] );
}

/* ---------------------------------------------------------------------
 * The sync
 * ------------------------------------------------------------------- */

/**
 * Syncs every managed group into the consumer plugins. Call from any site;
 * it runs from the Master Site.
 *
 * @return array Report.
 */
function teatatu_data_sync() {
	return teatatu_data_on_master( 'teatatu_data_sync_local' );
}

/**
 * Runs the sync (on the Master Site).
 *
 * @return array Report: {at, consumers: consumer => {network: [...], sites: site => dataset => counts}}
 */
function teatatu_data_sync_local() {
	$GLOBALS['teatatu_data_syncing'] = true;
	$managed                         = teatatu_data_managed_groups();
	$report                          = array(
		'at'        => gmdate( 'c' ),
		'consumers' => array(),
	);
	$records                         = array();
	$read                            = function ( $key ) use ( &$records ) {
		if ( ! isset( $records[ $key ] ) ) {
			$records[ $key ] = array();
			foreach ( teatatu_data_find_local( $key, array( 'status' => array( 'active', 'deprecated', 'retired' ), 'per_page' => -1 ) )['records'] as $record ) {
				$records[ $key ][ $record['uuid'] ] = $record;
			}
		}
		return $records[ $key ];
	};

	foreach ( teatatu_data_connections() as $consumer => $connection ) {
		$groups = isset( $managed[ $consumer ] ) ? array_intersect( (array) $managed[ $consumer ], array_keys( $connection['groups'] ) ) : array();
		if ( ! $groups ) {
			continue;
		}
		$entry = array(
			'network' => array(),
			'sites'   => array(),
		);
		$sites = teatatu_data_consumer_sites( $connection['plugin'] );

		// Network level: Events' neighbourhood slugs and street rules.
		$slugs = array(); // neighbourhood uuid => Events slug, for venue meta.
		if ( 'events' === $consumer && in_array( 'neighbourhood', $groups, true ) ) {
			$entry['network'] = teatatu_data_sync_events_neighbourhood_options( $read( 'neighbourhood' ), $read( 'street_rule' ) );
		}
		foreach ( $read( 'neighbourhood' ) as $uuid => $record ) {
			$slugs[ $uuid ] = $record['slug'];
		}

		foreach ( $sites as $site_id ) {
			if ( is_multisite() ) {
				switch_to_blog( $site_id );
			}
			foreach ( $groups as $group ) {
				foreach ( $connection['groups'][ $group ]['datasets'] as $key => $mapping ) {
					if ( 'terms' === $mapping['mode'] ) {
						$entry['sites'][ $site_id ][ $key ] = teatatu_data_sync_terms( $consumer, $site_id, $key, $mapping, $read( $key ), array( 'slugs' => $slugs ) );
					} elseif ( 'neighbourhood' === $mapping['mode'] ) {
						$entry['sites'][ $site_id ][ $key ] = teatatu_data_sync_events_neighbourhood_terms( $read( 'neighbourhood' ) );
					}
				}
			}
			if ( is_multisite() ) {
				restore_current_blog();
			}
		}
		$report['consumers'][ $consumer ] = $entry;
	}

	$GLOBALS['teatatu_data_syncing'] = false;
	teatatu_data_update_option( 'teatatu_data_sync_report', $report );
	return $report;
}

/**
 * Makes sure a consumer taxonomy is registered for this request (it isn't
 * when the consumer is active on the site being synced but not on the
 * Master Site), so wp_insert_term() and get_terms() accept it.
 *
 * @param array $mapping Mapping.
 */
function teatatu_data_ensure_taxonomy( $mapping ) {
	if ( ! taxonomy_exists( $mapping['taxonomy'] ) ) {
		register_taxonomy(
			$mapping['taxonomy'],
			array(),
			array(
				'public'       => false,
				'hierarchical' => ! empty( $mapping['hierarchical'] ),
				'rewrite'      => false,
				'query_var'    => false,
			)
		);
	}
}

/**
 * Syncs one data set into one consumer taxonomy on the current site.
 *
 * @param string $consumer 'events' or 'news'.
 * @param int    $site_id  Site ID (for the external-ID link).
 * @param string $key      Data set key.
 * @param array  $mapping  Mapping.
 * @param array  $records  uuid => record (active, deprecated, retired).
 * @param array  $ctx      Extra context for meta callbacks.
 * @return array {linked, created, updated, merged, retired, unlinked: string[], orphaned: string[], errors: string[]}
 */
function teatatu_data_sync_terms( $consumer, $site_id, $key, $mapping, $records, $ctx = array() ) {
	teatatu_data_ensure_taxonomy( $mapping );
	$tax     = $mapping['taxonomy'];
	$dataset = teatatu_data_get_dataset( $key );
	$ext     = $consumer . '_s' . $site_id;
	$out     = array(
		'linked'   => 0,
		'created'  => 0,
		'updated'  => 0,
		'merged'   => 0,
		'retired'  => 0,
		'unlinked' => array(),
		'orphaned' => array(),
		'errors'   => array(),
	);

	$terms = get_terms(
		array(
			'taxonomy'   => $tax,
			'hide_empty' => false,
		)
	);
	$terms = is_wp_error( $terms ) ? array() : $terms;
	$by_id = array();
	$ref   = array(); // uuid => term_id.
	foreach ( $terms as $term ) {
		$by_id[ $term->term_id ] = $term;
		$uuid                    = (string) get_term_meta( $term->term_id, 'teatatu_data_ref', true );
		if ( '' !== $uuid ) {
			$ref[ $uuid ] = (int) $term->term_id;
		}
	}
	$linked_terms = array_flip( $ref );
	$link         = function ( $uuid, $term_id ) use ( &$ref, &$linked_terms, &$out ) {
		update_term_meta( $term_id, 'teatatu_data_ref', $uuid );
		$ref[ $uuid ]             = (int) $term_id;
		$linked_terms[ $term_id ] = $uuid;
		$out['linked']++;
	};
	$slug_of = function ( $record ) use ( $mapping, $key ) {
		return isset( $mapping['slug'] ) ? (string) $record[ $mapping['slug'] ] : sanitize_title( teatatu_data_record_title( $key, $record ) );
	};

	// 1. Link existing terms: by recorded external ID, then (active records) by slug or name.
	foreach ( $records as $uuid => $record ) {
		if ( isset( $ref[ $uuid ] ) ) {
			continue;
		}
		$id = isset( $record['external_ids'][ $ext ] ) ? (int) $record['external_ids'][ $ext ] : 0;
		if ( $id && isset( $by_id[ $id ] ) && ! isset( $linked_terms[ $id ] ) ) {
			$link( $uuid, $id );
			continue;
		}
		if ( 'active' !== $record['status'] ) {
			continue;
		}
		$title  = teatatu_data_fold_text( teatatu_data_record_title( $key, $record ) );
		$wanted = $slug_of( $record );
		foreach ( $terms as $term ) {
			if ( isset( $linked_terms[ $term->term_id ] ) ) {
				continue;
			}
			if ( $term->slug === $wanted || teatatu_data_fold_text( $term->name ) === $title ) {
				$link( $uuid, $term->term_id );
				break;
			}
		}
	}

	// 2. Create missing terms and bring linked ones up to date (active records).
	foreach ( $records as $uuid => $record ) {
		if ( 'active' !== $record['status'] ) {
			continue;
		}
		$name        = teatatu_data_record_title( $key, $record );
		$description = isset( $record['description'] ) ? (string) $record['description'] : '';
		if ( ! isset( $ref[ $uuid ] ) ) {
			$created = wp_insert_term( $name, $tax, array( 'slug' => $slug_of( $record ), 'description' => $description ) );
			if ( is_wp_error( $created ) ) {
				$out['errors'][] = $name . ': ' . $created->get_error_message();
				continue;
			}
			$link( $uuid, (int) $created['term_id'] );
			$out['linked']--;
			$out['created']++;
			$term = get_term( (int) $created['term_id'], $tax );
		} else {
			$term = get_term( $ref[ $uuid ], $tax );
		}
		if ( ! $term || is_wp_error( $term ) ) {
			continue;
		}
		$changed = ! empty( $created );
		unset( $created );
		$args    = array();
		if ( $term->name !== $name ) {
			$args['name'] = $name;
		}
		if ( isset( $mapping['slug'] ) && $term->slug !== $record[ $mapping['slug'] ] ) {
			$args['slug'] = $record[ $mapping['slug'] ];
		}
		if ( $term->description !== $description ) {
			$args['description'] = $description;
		}
		if ( $args ) {
			$updated = wp_update_term( $term->term_id, $tax, $args );
			if ( is_wp_error( $updated ) ) {
				$out['errors'][] = $name . ': ' . $updated->get_error_message();
			} else {
				$out['updated']++;
				$changed = true;
			}
		}
		if ( ! empty( $mapping['meta'] ) ) {
			$meta_changed = false;
			foreach ( (array) call_user_func( $mapping['meta'], $record, $ctx ) as $meta_key => $value ) {
				if ( (string) get_term_meta( $term->term_id, $meta_key, true ) !== (string) $value ) {
					$meta_changed = true;
					if ( '' === (string) $value ) {
						delete_term_meta( $term->term_id, $meta_key );
					} else {
						update_term_meta( $term->term_id, $meta_key, $value );
					}
				}
			}
			if ( $meta_changed && ! $args ) {
				$out['updated']++;
			}
			$changed = $changed || $meta_changed;
		}
		update_term_meta( $term->term_id, 'teatatu_data_status', 'active' );
		if ( $changed && ! empty( $mapping['after'] ) ) {
			call_user_func( $mapping['after'], $term->term_id );
		}
	}

	// 3. Parents.
	if ( ! empty( $mapping['parent'] ) ) {
		foreach ( $records as $uuid => $record ) {
			if ( 'active' !== $record['status'] || ! isset( $ref[ $uuid ] ) ) {
				continue;
			}
			$parent_uuid = $record[ $mapping['parent'] ];
			$parent_id   = $parent_uuid && isset( $ref[ $parent_uuid ] ) ? $ref[ $parent_uuid ] : 0;
			$term        = get_term( $ref[ $uuid ], $tax );
			if ( $term && ! is_wp_error( $term ) && (int) $term->parent !== $parent_id ) {
				$updated = wp_update_term( $term->term_id, $tax, array( 'parent' => $parent_id ) );
				if ( is_wp_error( $updated ) ) {
					$out['errors'][] = $term->name . ': ' . $updated->get_error_message();
				}
			}
		}
	}

	// 4. Merged records: move their posts to the replacement's term, then remove the term.
	foreach ( $records as $uuid => $record ) {
		if ( ! isset( $ref[ $uuid ] ) ) {
			continue;
		}
		$term_id = $ref[ $uuid ];
		if ( 'deprecated' === $record['status'] && $record['replaced_by'] && isset( $ref[ $record['replaced_by'] ] ) && $ref[ $record['replaced_by'] ] !== $term_id ) {
			$target = $ref[ $record['replaced_by'] ];
			$ids    = get_objects_in_term( $term_id, $tax );
			foreach ( is_wp_error( $ids ) ? array() : $ids as $object_id ) {
				wp_set_object_terms( (int) $object_id, array( (int) $target ), $tax, true );
				wp_remove_object_terms( (int) $object_id, array( (int) $term_id ), $tax );
			}
			wp_delete_term( $term_id, $tax );
			unset( $linked_terms[ $term_id ] );
			$out['merged']++;
		} elseif ( 'active' !== $record['status'] ) {
			update_term_meta( $term_id, 'teatatu_data_status', $record['status'] );
			$out['retired']++;
		}
	}

	// 5. Report terms that are not (or no longer) linked.
	foreach ( get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) ) as $term ) {
		$uuid = (string) get_term_meta( $term->term_id, 'teatatu_data_ref', true );
		if ( '' === $uuid ) {
			$out['unlinked'][] = $term->name;
		} elseif ( ! isset( $records[ $uuid ] ) ) {
			$out['orphaned'][] = $term->name;
		}
	}
	if ( $dataset && function_exists( 'teatatu_events_bump_cache_version' ) && 'events' === $consumer ) {
		teatatu_events_bump_cache_version();
	}
	return $out;
}

/**
 * Network level for Events: neighbourhood slugs (renamed with Events' own
 * routine when it is loaded, so shortcodes, filters and URLs follow) and
 * the street rules option.
 *
 * @param array $neighbourhoods uuid => record.
 * @param array $rules          uuid => street rule record.
 * @return array {slugs_renamed, rules: 'unchanged'|'updated', rows, errors}
 */
function teatatu_data_sync_events_neighbourhood_options( $neighbourhoods, $rules ) {
	$out   = array(
		'slugs_renamed' => 0,
		'rules'         => 'unchanged',
		'rows'          => 0,
		'errors'        => array(),
	);
	$by_key = array();
	foreach ( $neighbourhoods as $record ) {
		if ( 'active' === $record['status'] ) {
			$by_key[ $record['key'] ] = $record;
		}
	}

	// Slugs and old slugs.
	$map     = teatatu_data_get_option( 'teatatu_events_nbhd_slugs', array() );
	$map     = is_array( $map ) ? $map : array();
	$aliases = teatatu_data_get_option( 'teatatu_events_nbhd_slug_aliases', array() );
	$aliases = is_array( $aliases ) ? $aliases : array();
	foreach ( $by_key as $key => $record ) {
		$current = ! empty( $map[ $key ] ) ? $map[ $key ] : $key;
		if ( $current !== $record['slug'] ) {
			if ( function_exists( 'teatatu_events_rename_neighbourhood_slug' ) ) {
				$renamed = teatatu_events_rename_neighbourhood_slug( $key, $record['slug'] );
				if ( is_wp_error( $renamed ) ) {
					$out['errors'][] = $key . ': ' . $renamed->get_error_message();
					continue;
				}
			} else {
				$map[ $key ]          = $record['slug'];
				$aliases[ $current ]  = $key;
				teatatu_data_update_option( 'teatatu_events_nbhd_slugs', $map );
			}
			$out['slugs_renamed']++;
		}
		$aliases = teatatu_data_get_option( 'teatatu_events_nbhd_slug_aliases', $aliases );
		foreach ( teatatu_data_split_aliases( $record['aliases'] ) as $alias ) {
			if ( $alias !== $record['slug'] && ! isset( $aliases[ $alias ] ) ) {
				$aliases[ $alias ] = $key;
			}
		}
		teatatu_data_update_option( 'teatatu_events_nbhd_slug_aliases', $aliases );
	}

	// Street rules, in Events' row format, ordered by street then Order.
	$uuid_to_slug = array();
	foreach ( $by_key as $record ) {
		$uuid_to_slug[ $record['uuid'] ] = $record['slug'];
	}
	$active = array_values(
		array_filter(
			$rules,
			function ( $r ) {
				return 'active' === $r['status'];
			}
		)
	);
	usort(
		$active,
		function ( $a, $b ) {
			return strcmp( teatatu_data_normalize_street( $a['street'] ), teatatu_data_normalize_street( $b['street'] ) ) ?: ( (int) $a['position'] <=> (int) $b['position'] );
		}
	);
	$rows = array();
	foreach ( $active as $rule ) {
		if ( ! $rule['outside'] && ! isset( $uuid_to_slug[ $rule['neighbourhood'] ] ) ) {
			continue;
		}
		$rows[] = array(
			'street' => $rule['street'],
			'parity' => $rule['side'],
			'min'    => null === $rule['min'] ? '' : (int) $rule['min'],
			'max'    => null === $rule['max'] ? '' : (int) $rule['max'],
			'area'   => $rule['outside'] ? 'outside' : $uuid_to_slug[ $rule['neighbourhood'] ],
		);
	}
	$out['rows'] = count( $rows );
	if ( teatatu_data_get_option( 'teatatu_events_nbhd_street_rules', null ) !== $rows ) {
		teatatu_data_update_option( 'teatatu_events_nbhd_street_rules', $rows );
		teatatu_data_update_option( 'teatatu_events_nbhd_rules_seeded', 'teatatu-data' );
		// Every site re-resolves its venues and events on its next admin page load.
		teatatu_data_update_option( 'teatatu_events_nbhd_rules_ver', time() );
		if ( function_exists( 'teatatu_events_queue_neighbourhood_reresolve' ) ) {
			teatatu_events_queue_neighbourhood_reresolve();
		}
		$out['rules'] = 'updated';
	}
	return $out;
}

/**
 * Links Events' three neighbourhood terms on the current site to their
 * records (by boundary key) and keeps their names and descriptions in step.
 * Slugs are handled at network level.
 *
 * @param array $neighbourhoods uuid => record.
 * @return array Counts like teatatu_data_sync_terms().
 */
function teatatu_data_sync_events_neighbourhood_terms( $neighbourhoods ) {
	$mapping = array( 'taxonomy' => 'teatatu_events_neighbourhood', 'hierarchical' => false );
	teatatu_data_ensure_taxonomy( $mapping );
	$out     = array(
		'linked'   => 0,
		'created'  => 0,
		'updated'  => 0,
		'merged'   => 0,
		'retired'  => 0,
		'unlinked' => array(),
		'orphaned' => array(),
		'errors'   => array(),
	);
	$ids     = get_option( 'teatatu_events_nbhd_term_ids', array() );
	foreach ( $neighbourhoods as $record ) {
		$term_id = isset( $ids[ $record['key'] ] ) ? (int) $ids[ $record['key'] ] : 0;
		$term    = $term_id ? get_term( $term_id, 'teatatu_events_neighbourhood' ) : null;
		if ( ! $term || is_wp_error( $term ) ) {
			$term = get_term_by( 'slug', $record['slug'], 'teatatu_events_neighbourhood' );
		}
		if ( ! $term ) {
			/* translators: %s: neighbourhood */
			$out['errors'][] = sprintf( __( '%s: Teatatu Events has no term for it on this site yet (it is created when Events is activated).', 'teatatu-data' ), $record['name'] );
			continue;
		}
		if ( (string) get_term_meta( $term->term_id, 'teatatu_data_ref', true ) !== $record['uuid'] ) {
			update_term_meta( $term->term_id, 'teatatu_data_ref', $record['uuid'] );
			$out['linked']++;
		}
		$args = array();
		if ( $term->name !== $record['name'] ) {
			$args['name'] = $record['name'];
		}
		if ( $term->description !== (string) $record['description'] ) {
			$args['description'] = (string) $record['description'];
		}
		if ( $args && ! is_wp_error( wp_update_term( $term->term_id, 'teatatu_events_neighbourhood', $args ) ) ) {
			$out['updated']++;
		}
		update_term_meta( $term->term_id, 'teatatu_data_status', $record['status'] );
	}
	return $out;
}

/* ---------------------------------------------------------------------
 * Keeping consumers current
 * ------------------------------------------------------------------- */

add_action( 'teatatu_data_record_changed', 'teatatu_data_queue_sync' );

/**
 * Queues a sync shortly after a managed data set changes (one job for a
 * burst of changes).
 *
 * @param string $key Data set key.
 */
function teatatu_data_queue_sync( $key ) {
	if ( teatatu_data_is_syncing() ) {
		return;
	}
	$managed = teatatu_data_managed_groups();
	foreach ( teatatu_data_connections() as $consumer => $connection ) {
		foreach ( isset( $managed[ $consumer ] ) ? (array) $managed[ $consumer ] : array() as $group ) {
			if ( isset( $connection['groups'][ $group ]['datasets'][ $key ] ) ) {
				if ( ! wp_next_scheduled( 'teatatu_data_sync' ) ) {
					wp_schedule_single_event( time() + 15, 'teatatu_data_sync' );
				}
				return;
			}
		}
	}
}

add_action( 'teatatu_data_sync', 'teatatu_data_sync' );

/* ---------------------------------------------------------------------
 * Connections tab
 * ------------------------------------------------------------------- */

/**
 * Renders the Connections tab: what is managed, Sync now, the last report.
 */
function teatatu_data_render_connections_tab() {
	$managed = teatatu_data_managed_groups();
	$report  = teatatu_data_get_option( 'teatatu_data_sync_report', array() );
	$can     = teatatu_data_is_master_site() && current_user_can( 'manage_teatatu_data' );
	$counts  = array();
	foreach ( teatatu_data_get_record_datasets() as $key => $dataset ) {
		$counts[ $key ] = teatatu_data_find( $key, array( 'per_page' => 1 ) )['total'];
	}
	?>
	<p><?php esc_html_e( 'Teatatu Data can keep these lists in Teatatu Events and Teatatu News up to date on every site. While a list is managed here, it is read-only in Events or News: changes are made here and copied there within a minute. Events and News go on working with the last copy if Teatatu Data is switched off.', 'teatatu-data' ); ?></p>
	<p class="description"><?php esc_html_e( 'Before switching a list on, bring its data in (each data set\'s tab) and approve it, so existing terms can be linked. Terms that cannot be linked are left as they are and listed below.', 'teatatu-data' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'teatatu_data_save_connections' ); ?>
		<input type="hidden" name="action" value="teatatu_data_save_connections" />
		<fieldset <?php disabled( ! $can ); ?>>
		<table class="widefat striped" style="max-width:900px;">
			<thead><tr><th><?php esc_html_e( 'Plugin', 'teatatu-data' ); ?></th><th><?php esc_html_e( 'Managed by Teatatu Data', 'teatatu-data' ); ?></th><th><?php esc_html_e( 'Active on', 'teatatu-data' ); ?></th></tr></thead>
			<tbody>
				<?php foreach ( teatatu_data_connections() as $consumer => $connection ) : ?>
					<?php $sites = teatatu_data_consumer_sites( $connection['plugin'] ); ?>
					<tr>
						<td><strong><?php echo esc_html( $connection['label'] ); ?></strong></td>
						<td>
							<?php foreach ( $connection['groups'] as $group => $def ) : ?>
								<?php
								$keys  = array_keys( $def['datasets'] );
								$total = array_sum( array_intersect_key( $counts, array_flip( $keys ) ) );
								?>
								<label style="display:block;"><input type="checkbox" name="managed[<?php echo esc_attr( $consumer ); ?>][]" value="<?php echo esc_attr( $group ); ?>" <?php checked( in_array( $group, isset( $managed[ $consumer ] ) ? (array) $managed[ $consumer ] : array(), true ) ); ?> />
								<?php echo esc_html( $def['label'] ); ?> <span class="description">(<?php /* translators: %d: active records */ echo esc_html( sprintf( _n( '%d active record here', '%d active records here', $total, 'teatatu-data' ), $total ) ); ?>)</span></label>
							<?php endforeach; ?>
						</td>
						<td><?php echo $sites ? esc_html( implode( ', ', array_map( function ( $id ) { $d = get_blog_details( $id ); return is_multisite() && $d ? $d->blogname . ' (' . $id . ')' : get_bloginfo( 'name' ); }, $sites ) ) ) : '<em>' . esc_html__( 'Not active', 'teatatu-data' ) . '</em>'; ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</fieldset>
		<?php
		if ( $can ) {
			submit_button( __( 'Save and sync', 'teatatu-data' ) );
		}
		?>
	</form>
	<?php if ( $can ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'teatatu_data_sync_now' ); ?>
			<input type="hidden" name="action" value="teatatu_data_sync_now" />
			<?php submit_button( __( 'Sync now', 'teatatu-data' ), 'secondary', 'submit', false ); ?>
		</form>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Last sync', 'teatatu-data' ); ?></h2>
	<?php if ( empty( $report['at'] ) ) : ?>
		<p><em><?php esc_html_e( 'Nothing has been synced yet.', 'teatatu-data' ); ?></em></p>
		<?php
		return;
	endif;
	?>
	<p><?php echo esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', strtotime( $report['at'] ) ), 'j M Y H:i' ) ); ?></p>
	<table class="widefat striped">
		<thead><tr>
			<th><?php esc_html_e( 'Plugin / site', 'teatatu-data' ); ?></th><th><?php esc_html_e( 'List', 'teatatu-data' ); ?></th>
			<th><?php esc_html_e( 'Linked', 'teatatu-data' ); ?></th><th><?php esc_html_e( 'Created', 'teatatu-data' ); ?></th><th><?php esc_html_e( 'Updated', 'teatatu-data' ); ?></th><th><?php esc_html_e( 'Merged', 'teatatu-data' ); ?></th>
			<th><?php esc_html_e( 'Not linked (left as they are)', 'teatatu-data' ); ?></th><th><?php esc_html_e( 'Problems', 'teatatu-data' ); ?></th>
		</tr></thead>
		<tbody>
			<?php foreach ( $report['consumers'] as $consumer => $entry ) : ?>
				<?php if ( ! empty( $entry['network'] ) ) : ?>
					<tr>
						<td><?php echo esc_html( teatatu_data_connections()[ $consumer ]['label'] ); ?> — <?php esc_html_e( 'network', 'teatatu-data' ); ?></td>
						<td><?php esc_html_e( 'Street rules and slugs', 'teatatu-data' ); ?></td>
						<td colspan="4">
							<?php
							/* translators: 1: rule rows, 2: updated/unchanged, 3: slugs renamed */
							echo esc_html( sprintf( __( '%1$d street rules (%2$s), %3$d slugs renamed', 'teatatu-data' ), $entry['network']['rows'], 'updated' === $entry['network']['rules'] ? __( 'updated', 'teatatu-data' ) : __( 'unchanged', 'teatatu-data' ), $entry['network']['slugs_renamed'] ) );
							?>
						</td>
						<td></td>
						<td><?php echo esc_html( implode( ' · ', $entry['network']['errors'] ) ); ?></td>
					</tr>
				<?php endif; ?>
				<?php foreach ( $entry['sites'] as $site_id => $lists ) : ?>
					<?php foreach ( $lists as $key => $c ) : ?>
						<?php $dataset = teatatu_data_get_dataset( $key ); ?>
						<tr>
							<td><?php echo esc_html( teatatu_data_connections()[ $consumer ]['label'] . ' — ' . ( is_multisite() && get_blog_details( $site_id ) ? get_blog_details( $site_id )->blogname : '' ) . ' (' . $site_id . ')' ); ?></td>
							<td><?php echo esc_html( $dataset ? $dataset['label'] : $key ); ?></td>
							<td><?php echo (int) $c['linked']; ?></td>
							<td><?php echo (int) $c['created']; ?></td>
							<td><?php echo (int) $c['updated']; ?></td>
							<td><?php echo (int) $c['merged']; ?></td>
							<td><?php echo esc_html( implode( ', ', array_merge( $c['unlinked'], array_map( function ( $n ) { return $n . ' ' . __( '(record gone)', 'teatatu-data' ); }, $c['orphaned'] ) ) ) ); ?></td>
							<td><?php echo esc_html( implode( ' · ', $c['errors'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endforeach; ?>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

add_action( 'admin_post_teatatu_data_save_connections', 'teatatu_data_handle_save_connections' );

/**
 * Saves which lists are managed, then syncs.
 */
function teatatu_data_handle_save_connections() {
	check_admin_referer( 'teatatu_data_save_connections' );
	if ( ! current_user_can( 'manage_teatatu_data' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-data' ), 403 );
	}
	teatatu_data_admin_require_master();
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each value is checked against the known groups below.
	$posted  = isset( $_POST['managed'] ) ? wp_unslash( (array) $_POST['managed'] ) : array();
	$managed = array();
	foreach ( teatatu_data_connections() as $consumer => $connection ) {
		$managed[ $consumer ] = array_values( array_intersect( array_map( 'sanitize_key', isset( $posted[ $consumer ] ) ? (array) $posted[ $consumer ] : array() ), array_keys( $connection['groups'] ) ) );
	}
	teatatu_data_update_option( 'teatatu_data_managed', $managed );
	teatatu_data_sync_local();
	set_transient( 'teatatu_data_message_' . get_current_user_id(), __( 'Saved and synced. See the report below.', 'teatatu-data' ), 5 * MINUTE_IN_SECONDS );
	wp_safe_redirect( add_query_arg( 'ttd_notice', 'message', teatatu_data_admin_url( array( 'tab' => 'connections' ) ) ) );
	exit;
}

add_action( 'admin_post_teatatu_data_sync_now', 'teatatu_data_handle_sync_now' );

/**
 * Handles "Sync now".
 */
function teatatu_data_handle_sync_now() {
	check_admin_referer( 'teatatu_data_sync_now' );
	if ( ! current_user_can( 'manage_teatatu_data' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-data' ), 403 );
	}
	teatatu_data_admin_require_master();
	teatatu_data_sync_local();
	set_transient( 'teatatu_data_message_' . get_current_user_id(), __( 'Synced. See the report below.', 'teatatu-data' ), 5 * MINUTE_IN_SECONDS );
	wp_safe_redirect( add_query_arg( 'ttd_notice', 'message', teatatu_data_admin_url( array( 'tab' => 'connections' ) ) ) );
	exit;
}
