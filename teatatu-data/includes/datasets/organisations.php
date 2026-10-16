<?php
/**
 * Organisations / sources (SPEC.md §5, §15): the groups, schools,
 * businesses, councils and media that organise events or publish news.
 * Replaces the `teatatu_events_source` and `teatatu_news_source` taxonomies
 * (name, description, parent, default image) as one shared list.
 *
 * Public organisation contacts only (SPEC.md §11 Q5): a general website,
 * email and phone — never a named person's details. NZBN and Charities
 * Register numbers are kept as external IDs (`nzbn`, `charity`), with their
 * formats checked, ready for the Phase 9 connectors.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Organisation types.
 *
 * @return array value => label
 */
function teatatu_data_organisation_types() {
	return array(
		'community_group'      => __( 'Community group', 'teatatu-data' ),
		'sports_club'          => __( 'Sports club', 'teatatu-data' ),
		'school'               => __( 'School', 'teatatu-data' ),
		'early_childhood'      => __( 'Early childhood centre', 'teatatu-data' ),
		'church'               => __( 'Church or faith group', 'teatatu-data' ),
		'marae'                => __( 'Marae', 'teatatu-data' ),
		'charity'              => __( 'Charity', 'teatatu-data' ),
		'business'             => __( 'Business', 'teatatu-data' ),
		'business_association' => __( 'Business association', 'teatatu-data' ),
		'council'              => __( 'Council or local board', 'teatatu-data' ),
		'government'           => __( 'Government agency', 'teatatu-data' ),
		'media'                => __( 'News or media', 'teatatu-data' ),
		'listings'             => __( 'Listings site', 'teatatu-data' ),
		'other'                => __( 'Other', 'teatatu-data' ),
	);
}

add_filter( 'teatatu_data_datasets', 'teatatu_data_register_organisation_dataset' );

/**
 * Registers the organisation data set.
 *
 * @param array $datasets Data sets.
 * @return array
 */
function teatatu_data_register_organisation_dataset( $datasets ) {
	$datasets['organisation'] = array(
		'label'           => __( 'Organisations', 'teatatu-data' ),
		'description'     => __( 'Groups, schools, businesses, councils and media that organise events or publish news — the "Source" of an event or news item.', 'teatatu-data' ),
		'kind'            => 'master',
		'owner'           => __( 'Administrator', 'teatatu-data' ),
		'steward'         => __( 'Editor', 'teatatu-data' ),
		'source'          => __( 'Stewards and imports; first loaded from Teatatu Events and Teatatu News sources', 'teatatu-data' ),
		'consumers'       => array( 'teatatu-events', 'teatatu-news' ),
		'review_interval' => 12,
		'list_columns'    => array( 'name', 'type', 'parent', 'website' ),
		'rules'           => array(
			__( 'Public organisation contacts only: a general website, email and phone. Never a named person or their personal email or phone.', 'teatatu-data' ),
			__( 'An organisation cannot be its own parent, directly or further up.', 'teatatu-data' ),
			__( 'External IDs: nzbn is the 13-digit NZ Business Number; charity is the Charities Register number (CC followed by digits).', 'teatatu-data' ),
			__( 'Images are links only; nothing is downloaded.', 'teatatu-data' ),
		),
		'validate'        => 'teatatu_data_validate_organisation',
		'duplicate_keys'  => 'teatatu_data_organisation_duplicate_keys',
		'merge_combine'   => array( 'aliases' ),
		'fields'          => array(
			'name'        => array(
				'label'       => __( 'Name', 'teatatu-data' ),
				'required'    => true,
				'max_length'  => 150,
				'description' => __( 'The organisation\'s name as it calls itself.', 'teatatu-data' ),
				'example'     => 'Matipo Primary School',
			),
			'aliases'     => array(
				'label'       => __( 'Also known as', 'teatatu-data' ),
				'max_length'  => 500,
				'description' => __( 'Other names and abbreviations, comma-separated. Used to recognise it in imports.', 'teatatu-data' ),
				'example'     => 'TAPBA, Te Atatu Business Association',
			),
			'type'        => array(
				'label'       => __( 'Type', 'teatatu-data' ),
				'type'        => 'enum',
				'enum'        => teatatu_data_organisation_types(),
				'recommended' => true,
			),
			'parent'      => array(
				'label'       => __( 'Part of', 'teatatu-data' ),
				'type'        => 'reference',
				'references'  => 'organisation',
				'description' => __( 'The larger organisation this one belongs to (e.g. a school\'s PTA belongs to the school).', 'teatatu-data' ),
			),
			'description' => array(
				'label' => __( 'Description', 'teatatu-data' ),
				'type'  => 'text',
			),
			'website'     => array(
				'label'       => __( 'Website', 'teatatu-data' ),
				'type'        => 'url',
				'recommended' => true,
				'description' => __( 'Also used to recognise the organisation in imports.', 'teatatu-data' ),
			),
			'email'       => array(
				'label'       => __( 'General email', 'teatatu-data' ),
				'type'        => 'email',
				'description' => __( 'The organisation\'s public address (e.g. office@…), never a person\'s.', 'teatatu-data' ),
			),
			'phone'       => array(
				'label'       => __( 'General phone', 'teatatu-data' ),
				'type'        => 'phone',
				'description' => __( 'The organisation\'s public number, never a person\'s mobile.', 'teatatu-data' ),
			),
			'venue'       => array(
				'label'       => __( 'Based at', 'teatatu-data' ),
				'type'        => 'reference',
				'references'  => 'venue',
				'description' => __( 'The venue where the organisation is based, if it has one.', 'teatatu-data' ),
			),
			'image_url'   => array(
				'label'       => __( 'Default image', 'teatatu-data' ),
				'type'        => 'url',
				'description' => __( 'Logo or picture used for its events and news when they have none (link only, never downloaded).', 'teatatu-data' ),
			),
		),
	);
	return $datasets;
}

/**
 * Cross-field rules for an organisation.
 *
 * @param array $merged Record after the change.
 * @return array field => message
 */
function teatatu_data_validate_organisation( $merged ) {
	$errors = array();

	// No loops in the parent chain.
	$own    = isset( $merged['uuid'] ) ? $merged['uuid'] : '';
	$parent = isset( $merged['parent'] ) ? $merged['parent'] : null;
	$seen   = array();
	while ( $parent && $own ) {
		if ( $parent === $own ) {
			$errors['parent'] = __( 'That would make the organisation part of itself.', 'teatatu-data' );
			break;
		}
		if ( isset( $seen[ $parent ] ) ) {
			break;
		}
		$seen[ $parent ] = true;
		$record          = teatatu_data_get_local( 'organisation', $parent );
		$parent          = $record ? $record['parent'] : null;
	}

	$ids = isset( $merged['external_ids'] ) ? (array) $merged['external_ids'] : array();
	if ( isset( $ids['nzbn'] ) && ! preg_match( '/^\d{13}$/', $ids['nzbn'] ) ) {
		$errors['external_ids'] = __( 'nzbn must be the 13-digit NZ Business Number.', 'teatatu-data' );
	} elseif ( isset( $ids['charity'] ) && ! preg_match( '/^CC\d{5,6}$/', $ids['charity'] ) ) {
		$errors['external_ids'] = __( 'charity must be a Charities Register number, e.g. CC12345.', 'teatatu-data' );
	}
	return $errors;
}

/**
 * Comparable form of a website: host without "www." plus path, lower-case,
 * no scheme, query or trailing slash ("https://www.Example.org/Club/" →
 * "example.org/club"). Keeping the path stops two groups on the same
 * social site (facebook.com/…) looking alike.
 *
 * @param string $url URL.
 * @return string '' when not a web address.
 */
function teatatu_data_website_key( $url ) {
	$parts = wp_parse_url( trim( (string) $url ) );
	if ( empty( $parts['host'] ) ) {
		return '';
	}
	$host = preg_replace( '/^www\./', '', strtolower( $parts['host'] ) );
	$path = isset( $parts['path'] ) ? rtrim( strtolower( $parts['path'] ), '/' ) : '';
	return $host . $path;
}

/**
 * Matches an organisation by name or alias, then by website. A deprecated
 * organisation that was replaced resolves to its replacement.
 *
 * @param string $name    Name.
 * @param string $website Website URL.
 * @return array|null {record, via: 'name'|'alias'|'website'}
 */
function teatatu_data_match_organisation( $name, $website = '' ) {
	return teatatu_data_on_master( 'teatatu_data_match_organisation_local', array( $name, $website ) );
}

/**
 * Matches an organisation on the current site. See teatatu_data_match_organisation().
 *
 * @param string     $name    Name.
 * @param string     $website Website URL.
 * @param array|null $records Records to match against (default: active and deprecated).
 * @return array|null
 */
function teatatu_data_match_organisation_local( $name, $website = '', $records = null ) {
	if ( null === $records ) {
		$records = teatatu_data_find_local( 'organisation', array( 'status' => array( 'active', 'deprecated' ), 'per_page' => -1 ) )['records'];
	}
	$found = function ( $record, $via ) {
		if ( isset( $record['status'] ) && 'deprecated' === $record['status'] && ! empty( $record['replaced_by'] ) ) {
			$replacement = teatatu_data_get_local( 'organisation', $record['replaced_by'] );
			if ( $replacement && 'active' === $replacement['status'] ) {
				$record = $replacement;
			}
		}
		return array(
			'record' => $record,
			'via'    => $via,
		);
	};
	$wanted = teatatu_data_fold_text( $name );
	if ( '' !== $wanted ) {
		foreach ( $records as $record ) {
			if ( teatatu_data_fold_text( $record['name'] ) === $wanted ) {
				return $found( $record, 'name' );
			}
		}
		foreach ( $records as $record ) {
			$aliases = array_filter( array_map( 'teatatu_data_fold_text', explode( ',', isset( $record['aliases'] ) ? (string) $record['aliases'] : '' ) ) );
			if ( in_array( $wanted, $aliases, true ) ) {
				return $found( $record, 'alias' );
			}
		}
	}
	$site = teatatu_data_website_key( $website );
	if ( '' !== $site ) {
		foreach ( $records as $record ) {
			if ( ! empty( $record['website'] ) && teatatu_data_website_key( $record['website'] ) === $site ) {
				return $found( $record, 'website' );
			}
		}
	}
	return null;
}

/* ---------------------------------------------------------------------
 * Bringing sources in from Teatatu Events and Teatatu News
 * ------------------------------------------------------------------- */

/**
 * Every Events and News source on every site, as organisation values.
 *
 * @return array[] {system, parent_key, label, values}
 */
function teatatu_data_source_candidates() {
	$candidates = array();
	$plugins    = array(
		'events' => array( 'teatatu_events_source', 'teatatu_events_', 'Teatatu Events' ),
		'news'   => array( 'teatatu_news_source', 'teatatu_news_', 'Teatatu News' ),
	);
	foreach ( $plugins as $plugin => $def ) {
		foreach ( teatatu_data_read_terms_everywhere( $def[0], $def[1] ) as $term ) {
			$system = $plugin . '_s' . $term['site_id'];
			$values = array(
				'name'         => $term['name'],
				'description'  => wp_strip_all_tags( $term['description'] ),
				'image_url'    => isset( $term['meta']['source_default_image'] ) ? trim( $term['meta']['source_default_image'] ) : '',
				'external_ids' => array( $system => (string) $term['term_id'] ),
				'source'       => $def[2],
			);
			$candidates[] = array(
				'system'     => $system,
				'term_id'    => $term['term_id'],
				'parent_key' => $term['parent'] ? $system . '=' . $term['parent'] : '',
				/* translators: 1: name, 2: plugin, 3: site ID */
				'label'      => sprintf( __( '%1$s (%2$s source, site %3$d)', 'teatatu-data' ), $term['name'], $def[2], $term['site_id'] ),
				'values'     => array_filter(
					$values,
					function ( $v ) {
						return '' !== $v;
					}
				),
			);
		}
	}
	return $candidates;
}

/**
 * Proposes every Events and News source as one batch of organisations:
 * those already here get the source IDs (and new names) added; the same
 * source in several plugins or sites becomes one organisation. A parent
 * source is linked once both are here, so run it again after approving the
 * first batch to fill in "Part of". Call on the Master Site.
 *
 * @param array[]|null $candidates Candidates (default: read from Events and News).
 * @return array {batch, created, updated, merged, unchanged, pending, parents_later, errors: string[]}
 */
function teatatu_data_import_source_organisations( $candidates = null ) {
	$candidates = null === $candidates ? teatatu_data_source_candidates() : $candidates;
	$batch      = 'sources-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 4, false );
	$result     = array(
		'batch'         => $batch,
		'created'       => 0,
		'updated'       => 0,
		'merged'        => 0,
		'unchanged'     => 0,
		'pending'       => 0,
		'parents_later' => 0,
		'errors'        => array(),
	);

	$waiting = array();
	foreach ( teatatu_data_get_changes_local( array( 'dataset' => 'organisation', 'state' => 'pending', 'per_page' => -1 ) )['changes'] as $change ) {
		foreach ( isset( $change['values']['external_ids'] ) ? (array) $change['values']['external_ids'] : array() as $system => $id ) {
			$waiting[ $system . '=' . $id ] = true;
		}
	}
	$existing = teatatu_data_find_local( 'organisation', array( 'status' => array( 'active', 'deprecated', 'proposed' ), 'per_page' => -1 ) )['records'];
	$by_id    = array(); // "system=term" => uuid, for linking parents.
	foreach ( $existing as $record ) {
		foreach ( $record['external_ids'] as $system => $id ) {
			$by_id[ $system . '=' . $id ] = $record['uuid'];
		}
	}

	$updates = array();
	$new     = array();
	foreach ( $candidates as $candidate ) {
		$values = $candidate['values'];
		$key    = $candidate['system'] . '=' . $candidate['term_id'];
		if ( isset( $waiting[ $key ] ) ) {
			$result['pending']++;
			continue;
		}

		$match = teatatu_data_match_organisation_local( $values['name'], isset( $values['website'] ) ? $values['website'] : '', $existing );
		if ( $match ) {
			$record = $match['record'];
			$uuid   = $record['uuid'];
			$base   = isset( $updates[ $uuid ] ) ? $updates[ $uuid ] : array(
				'external_ids' => $record['external_ids'],
				'aliases'      => (string) $record['aliases'],
			);
			$base['external_ids'] = array_merge( (array) $base['external_ids'], $values['external_ids'] );
			$base['aliases']      = teatatu_data_merge_aliases( $base['aliases'], array( $values['name'] ), $record['name'] );
			if ( empty( $record['image_url'] ) && ! empty( $values['image_url'] ) ) {
				$base['image_url'] = $values['image_url'];
			}
			if ( empty( $record['description'] ) && ! empty( $values['description'] ) ) {
				$base['description'] = $values['description'];
			}
			if ( $candidate['parent_key'] && empty( $record['parent'] ) && isset( $by_id[ $candidate['parent_key'] ] ) && $by_id[ $candidate['parent_key'] ] !== $uuid ) {
				$base['parent'] = $by_id[ $candidate['parent_key'] ];
			}
			$updates[ $uuid ] = $base;
			continue;
		}

		// The same source from another plugin or site?
		$pending = array();
		foreach ( $new as $i => $item ) {
			$pending[] = array_merge( array( 'uuid' => (string) $i, 'aliases' => '', 'website' => '' ), $item['values'] );
		}
		$dupe = $pending ? teatatu_data_match_organisation_local( $values['name'], isset( $values['website'] ) ? $values['website'] : '', $pending ) : null;
		if ( $dupe ) {
			$i    = (int) $dupe['record']['uuid'];
			$item = &$new[ $i ];
			$item['values']['external_ids'] = array_merge( $item['values']['external_ids'], $values['external_ids'] );
			$item['values']['aliases']      = teatatu_data_merge_aliases( isset( $item['values']['aliases'] ) ? $item['values']['aliases'] : '', array( $values['name'] ), $item['values']['name'] );
			foreach ( array( 'image_url', 'description' ) as $field ) {
				if ( empty( $item['values'][ $field ] ) && ! empty( $values[ $field ] ) ) {
					$item['values'][ $field ] = $values[ $field ];
				}
			}
			if ( false === strpos( $item['values']['source'], $values['source'] ) ) {
				$item['values']['source'] .= ', ' . $values['source'];
			}
			$item['sources'][] = $candidate['label'];
			$item['parents']   = array_merge( $item['parents'], $candidate['parent_key'] ? array( $candidate['parent_key'] ) : array() );
			unset( $item );
			$result['merged']++;
			continue;
		}
		$new[] = array(
			'values'  => $values,
			'sources' => array( $candidate['label'] ),
			'parents' => $candidate['parent_key'] ? array( $candidate['parent_key'] ) : array(),
		);
	}

	foreach ( $updates as $uuid => $values ) {
		$change = teatatu_data_propose_local(
			'organisation',
			$uuid,
			$values,
			array(
				'channel' => 'migration',
				'reason'  => __( 'Link to the matching Teatatu Events / Teatatu News source(s).', 'teatatu-data' ),
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
		$values = $item['values'];
		// Link a parent that is already here; otherwise the next run will.
		foreach ( $item['parents'] as $parent_key ) {
			if ( isset( $by_id[ $parent_key ] ) ) {
				$values['parent'] = $by_id[ $parent_key ];
				break;
			}
		}
		if ( $item['parents'] && empty( $values['parent'] ) ) {
			$result['parents_later']++;
		}
		$change = teatatu_data_propose_local(
			'organisation',
			null,
			$values,
			array(
				'channel' => 'migration',
				/* translators: %s: sources the record comes from */
				'reason'  => sprintf( __( 'From %s.', 'teatatu-data' ), implode( '; ', $item['sources'] ) ),
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

add_action( 'teatatu_data_records_list_before', 'teatatu_data_organisation_admin_tools' );

/**
 * Organisations tab: "Bring in sources" and "Find an organisation".
 *
 * @param string $key Data set key.
 */
function teatatu_data_organisation_admin_tools( $key ) {
	if ( 'organisation' !== $key ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only lookup.
	$find = isset( $_GET['ttd_find'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_GET['ttd_find'] ) ) : null;
	$find = wp_parse_args( (array) $find, array( 'name' => '', 'website' => '' ) );
	?>
	<div class="card" style="max-width:none;">
		<?php if ( teatatu_data_is_master_site() && current_user_can( 'import_teatatu_data' ) ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:8px 0;">
				<?php wp_nonce_field( 'teatatu_data_import_sources' ); ?>
				<input type="hidden" name="action" value="teatatu_data_import_sources" />
				<?php submit_button( __( 'Bring in sources from Teatatu Events and Teatatu News', 'teatatu-data' ), 'secondary', 'submit', false ); ?>
				<span class="description"><?php esc_html_e( 'Gathers the Sources on every site, merges the same organisation from different places, and sends them as one batch of proposed changes to review. Run it again after approving to link parent organisations. Nothing is proposed twice.', 'teatatu-data' ); ?></span>
			</form>
		<?php endif; ?>
		<h3 style="margin-top:8px;"><?php esc_html_e( 'Find an organisation', 'teatatu-data' ); ?></h3>
		<form method="get">
			<input type="hidden" name="page" value="teatatu-data" />
			<input type="hidden" name="tab" value="records" />
			<input type="hidden" name="dataset" value="organisation" />
			<input type="text" name="ttd_find[name]" value="<?php echo esc_attr( $find['name'] ); ?>" placeholder="<?php esc_attr_e( 'Name', 'teatatu-data' ); ?>" />
			<input type="text" name="ttd_find[website]" value="<?php echo esc_attr( $find['website'] ); ?>" placeholder="<?php esc_attr_e( 'Website', 'teatatu-data' ); ?>" class="regular-text" />
			<?php submit_button( __( 'Find', 'teatatu-data' ), 'secondary', '', false ); ?>
		</form>
		<?php if ( isset( $_GET['ttd_find'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<?php
			$match = teatatu_data_match_organisation( $find['name'], $find['website'] );
			$vias  = array(
				'name'    => __( 'by name', 'teatatu-data' ),
				'alias'   => __( 'by another name', 'teatatu-data' ),
				'website' => __( 'by website', 'teatatu-data' ),
			);
			?>
			<p><strong>
				<?php if ( $match ) : ?>
					<a href="<?php echo esc_url( teatatu_data_admin_url( array( 'tab' => 'records', 'dataset' => 'organisation', 'action' => 'edit', 'uuid' => $match['record']['uuid'] ) ) ); ?>"><?php echo esc_html( $match['record']['name'] ); ?></a>
					(<?php echo esc_html( $vias[ $match['via'] ] ); ?>)
				<?php else : ?>
					<?php esc_html_e( 'No organisation matches.', 'teatatu-data' ); ?>
				<?php endif; ?>
			</strong></p>
		<?php endif; ?>
	</div>
	<?php
}

add_action( 'admin_post_teatatu_data_import_sources', 'teatatu_data_handle_import_sources' );

/**
 * Handles "Bring in sources from Teatatu Events and Teatatu News".
 */
function teatatu_data_handle_import_sources() {
	check_admin_referer( 'teatatu_data_import_sources' );
	if ( ! current_user_can( 'import_teatatu_data' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-data' ), 403 );
	}
	teatatu_data_admin_require_master();
	$result = teatatu_data_import_source_organisations();
	/* translators: 1: new, 2: to link, 3: merged, 4: up to date */
	$message = sprintf( __( 'Sources: %1$d new organisations, %2$d to link to existing ones, %3$d duplicates merged, %4$d already up to date.', 'teatatu-data' ), $result['created'], $result['updated'], $result['merged'], $result['unchanged'] );
	if ( $result['pending'] ) {
		/* translators: %d: skipped */
		$message .= ' ' . sprintf( __( '%d skipped because they are already waiting for review.', 'teatatu-data' ), $result['pending'] );
	}
	if ( $result['parents_later'] ) {
		/* translators: %d: organisations */
		$message .= ' ' . sprintf( __( '%d have a parent that will be linked when you run this again after approving.', 'teatatu-data' ), $result['parents_later'] );
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

add_action( 'rest_api_init', 'teatatu_data_register_organisation_rest_route' );

/**
 * GET /teatatu-data/v1/organisations/match?name=&website=
 */
function teatatu_data_register_organisation_rest_route() {
	register_rest_route(
		'teatatu-data/v1',
		'/organisations/match',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => function ( $request ) {
				$match = teatatu_data_match_organisation( sanitize_text_field( (string) $request['name'] ), esc_url_raw( (string) $request['website'] ) );
				return rest_ensure_response(
					array(
						'organisation' => $match ? array_merge( $match['record'], array( 'matched' => $match['via'] ) ) : null,
					)
				);
			},
			'permission_callback' => function () {
				return current_user_can( 'read_teatatu_data' );
			},
			'args'                => array(
				'name'    => array(
					'type'    => 'string',
					'default' => '',
				),
				'website' => array(
					'type'    => 'string',
					'default' => '',
				),
			),
		)
	);
}

/**
 * Organisations that may be the same: same name or other name, same
 * website, same NZBN or charity number.
 *
 * @param array $record Organisation.
 * @return string[]
 */
function teatatu_data_organisation_duplicate_keys( $record ) {
	$keys = array();
	foreach ( array_merge( array( $record['name'] ), explode( ',', (string) $record['aliases'] ) ) as $name ) {
		$folded = teatatu_data_fold_text( $name );
		if ( '' !== $folded ) {
			$keys[] = 'name:' . $folded;
		}
	}
	if ( $record['website'] ) {
		$keys[] = 'web:' . teatatu_data_website_key( $record['website'] );
	}
	foreach ( array( 'nzbn', 'charity' ) as $system ) {
		if ( ! empty( $record['external_ids'][ $system ] ) ) {
			$keys[] = $system . ':' . $record['external_ids'][ $system ];
		}
	}
	return array_values( array_unique( $keys ) );
}
