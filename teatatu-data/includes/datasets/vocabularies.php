<?php
/**
 * Tags and categories (SPEC.md §5, §16): controlled vocabularies, each its
 * own scheme and its own data set, built from one definition here.
 *
 * - `event_tag`       Event tags (flat, with a badge colour) ← teatatu_events_tag
 * - `event_category`  Event categories (hierarchical)        ← teatatu_events_category
 * - `news_category`   News categories (hierarchical)         ← teatatu_news_category
 *
 * Other plugins can add a scheme on the `teatatu_data_vocabularies` filter.
 * A vocabulary is curated: teatatu_data_match_terms() maps incoming text
 * (feed categories, agent suggestions) to existing terms by name, slug or
 * synonym, and never creates one.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The vocabularies: data set key => definition.
 *
 * @return array {label, singular, description, hierarchical, color, taxonomy, plugin, meta_prefix, consumers, example}
 */
function teatatu_data_vocabularies() {
	/**
	 * Filters the controlled vocabularies.
	 *
	 * @param array $vocabularies Data set key => definition.
	 */
	return apply_filters(
		'teatatu_data_vocabularies',
		array(
			'event_tag'      => array(
				'label'        => __( 'Event tags', 'teatatu-data' ),
				'singular'     => __( 'event tag', 'teatatu-data' ),
				'description'  => __( 'What kind of event it is — Show, Garage sale, Community event, Fundraiser. Several per event.', 'teatatu-data' ),
				'hierarchical' => false,
				'color'        => true,
				'taxonomy'     => 'teatatu_events_tag',
				'plugin'       => 'events',
				'meta_prefix'  => 'teatatu_events_',
				'consumers'    => array( 'teatatu-events' ),
				'example'      => 'Garage sale',
			),
			'event_category' => array(
				'label'        => __( 'Event categories', 'teatatu-data' ),
				'singular'     => __( 'event category', 'teatatu-data' ),
				'description'  => __( 'Broad groupings of events, which can be nested.', 'teatatu-data' ),
				'hierarchical' => true,
				'color'        => false,
				'taxonomy'     => 'teatatu_events_category',
				'plugin'       => 'events',
				'meta_prefix'  => 'teatatu_events_',
				'consumers'    => array( 'teatatu-events' ),
				'example'      => 'Arts and culture',
			),
			'news_category'  => array(
				'label'        => __( 'News categories', 'teatatu-data' ),
				'singular'     => __( 'news category', 'teatatu-data' ),
				'description'  => __( 'Broad groupings of news items, which can be nested.', 'teatatu-data' ),
				'hierarchical' => true,
				'color'        => false,
				'taxonomy'     => 'teatatu_news_category',
				'plugin'       => 'news',
				'meta_prefix'  => 'teatatu_news_',
				'consumers'    => array( 'teatatu-news' ),
				'example'      => 'Community News',
			),
		)
	);
}

add_filter( 'teatatu_data_datasets', 'teatatu_data_register_vocabulary_datasets' );

/**
 * Registers one data set per vocabulary.
 *
 * @param array $datasets Data sets.
 * @return array
 */
function teatatu_data_register_vocabulary_datasets( $datasets ) {
	$slug_pattern = '[a-z0-9]+(?:-[a-z0-9]+)*';
	foreach ( teatatu_data_vocabularies() as $key => $vocab ) {
		$fields = array(
			'name'        => array(
				'label'       => __( 'Name', 'teatatu-data' ),
				'required'    => true,
				'unique'      => true,
				'max_length'  => 80,
				'description' => __( 'Shown to people.', 'teatatu-data' ),
				'example'     => $vocab['example'],
			),
			'slug'        => array(
				'label'       => __( 'Slug', 'teatatu-data' ),
				'required'    => true,
				'unique'      => true,
				'max_length'  => 80,
				'pattern'     => $slug_pattern,
				'description' => __( 'Lower-case identifier other plugins use in URLs, filters and shortcodes.', 'teatatu-data' ),
				'example'     => sanitize_title( $vocab['example'] ),
			),
			'synonyms'    => array(
				'label'       => __( 'Synonyms', 'teatatu-data' ),
				'max_length'  => 500,
				'description' => __( 'Other words that mean this, comma-separated. Incoming categories (from feeds or agents) that use them are matched to this term.', 'teatatu-data' ),
			),
			'description' => array(
				'label' => __( 'Description', 'teatatu-data' ),
				'type'  => 'text',
			),
		);
		$rules  = array(
			__( 'Names and slugs are unique within this vocabulary.', 'teatatu-data' ),
			__( 'Curated: imports and agents can only use terms that exist here; unknown words are ignored, never added.', 'teatatu-data' ),
		);
		if ( $vocab['hierarchical'] ) {
			$fields['parent'] = array(
				'label'       => __( 'Parent', 'teatatu-data' ),
				'type'        => 'reference',
				'references'  => $key,
				'description' => __( 'The broader term this one sits under.', 'teatatu-data' ),
			);
			$rules[]          = __( 'A term cannot sit under itself, directly or further up.', 'teatatu-data' );
		}
		if ( $vocab['color'] ) {
			$fields['color'] = array(
				'label'       => __( 'Badge colour', 'teatatu-data' ),
				'pattern'     => '#[0-9a-f]{6}',
				'case'        => 'lower',
				'description' => __( 'Hex colour for the badge, e.g. #2271b1.', 'teatatu-data' ),
				'example'     => '#2271b1',
			);
		}
		$datasets[ $key ] = array(
			'label'           => $vocab['label'],
			'description'     => $vocab['description'],
			'kind'            => 'reference',
			'owner'           => __( 'Administrator', 'teatatu-data' ),
			'steward'         => __( 'Editor', 'teatatu-data' ),
			/* translators: %s: plugin name */
			'source'          => sprintf( __( 'Stewards; first loaded from %s', 'teatatu-data' ), 'news' === $vocab['plugin'] ? 'Teatatu News' : 'Teatatu Events' ),
			'consumers'       => $vocab['consumers'],
			'review_interval' => 24,
			'list_columns'    => array_values( array_filter( array( 'name', 'slug', $vocab['hierarchical'] ? 'parent' : '', $vocab['color'] ? 'color' : '', 'synonyms' ) ) ),
			'rules'           => $rules,
			'validate'        => $vocab['hierarchical'] ? 'teatatu_data_validate_vocabulary_parent' : null,
			'duplicate_keys'  => 'teatatu_data_vocabulary_duplicate_keys',
			'merge_combine'   => array( 'synonyms' ),
			'fields'          => $fields,
		);
	}
	return $datasets;
}

/**
 * No loops in a hierarchical vocabulary. The data set is found from the
 * parent record, so one callback serves every vocabulary.
 *
 * @param array $merged Record after the change.
 * @return array field => message
 */
function teatatu_data_validate_vocabulary_parent( $merged ) {
	$own    = isset( $merged['uuid'] ) ? $merged['uuid'] : '';
	$parent = isset( $merged['parent'] ) ? $merged['parent'] : null;
	if ( ! $own || ! $parent ) {
		return array();
	}
	$key = teatatu_data_vocabulary_of( $own );
	if ( ! $key ) {
		return array();
	}
	$seen = array();
	while ( $parent ) {
		if ( $parent === $own ) {
			return array( 'parent' => __( 'That would put the term under itself.', 'teatatu-data' ) );
		}
		if ( isset( $seen[ $parent ] ) ) {
			break;
		}
		$seen[ $parent ] = true;
		$record          = teatatu_data_get_local( $key, $parent );
		$parent          = $record ? $record['parent'] : null;
	}
	return array();
}

/**
 * Which vocabulary a record UUID belongs to.
 *
 * @param string $uuid Record UUID.
 * @return string Data set key, or ''.
 */
function teatatu_data_vocabulary_of( $uuid ) {
	foreach ( array_keys( teatatu_data_vocabularies() ) as $key ) {
		if ( teatatu_data_find_post_id( $key, $uuid ) ) {
			return $key;
		}
	}
	return '';
}

/**
 * The words a term answers to: its name, its slug with spaces, its synonyms
 * — all folded.
 *
 * @param array $record Term record.
 * @return string[]
 */
function teatatu_data_term_words( $record ) {
	$words = array_merge(
		array( $record['name'], str_replace( '-', ' ', (string) $record['slug'] ) ),
		explode( ',', isset( $record['synonyms'] ) ? (string) $record['synonyms'] : '' )
	);
	return array_values( array_unique( array_filter( array_map( 'teatatu_data_fold_text', $words ) ) ) );
}

/**
 * Maps incoming words to existing terms of a vocabulary, by name, slug or
 * synonym (ignoring case, accents and punctuation). Unknown words are
 * ignored; nothing is created. A deprecated term resolves to its
 * replacement; retired terms never match.
 *
 * @param string          $key    Vocabulary (data set key).
 * @param string|string[] $values Words, or comma-separated lists of words.
 * @return array[] {record, word} for each matched term, without repeats.
 */
function teatatu_data_match_terms( $key, $values ) {
	return teatatu_data_on_master( 'teatatu_data_match_terms_local', array( $key, $values ) );
}

/**
 * Matches terms on the current site. See teatatu_data_match_terms().
 *
 * @param string          $key     Vocabulary.
 * @param string|string[] $values  Words.
 * @param array|null      $records Records to match against (default: active and deprecated).
 * @return array[]
 */
function teatatu_data_match_terms_local( $key, $values, $records = null ) {
	if ( ! isset( teatatu_data_vocabularies()[ $key ] ) ) {
		return array();
	}
	if ( null === $records ) {
		$records = teatatu_data_find_local( $key, array( 'status' => array( 'active', 'deprecated' ), 'per_page' => -1 ) )['records'];
	}
	$index = array();
	foreach ( $records as $record ) {
		foreach ( teatatu_data_term_words( $record ) as $word ) {
			if ( ! isset( $index[ $word ] ) ) {
				$index[ $word ] = $record;
			}
		}
	}
	$out = array();
	foreach ( (array) $values as $value ) {
		foreach ( explode( ',', (string) $value ) as $part ) {
			$word = teatatu_data_fold_text( $part );
			if ( '' === $word || ! isset( $index[ $word ] ) ) {
				continue;
			}
			$record = $index[ $word ];
			if ( isset( $record['status'] ) && 'deprecated' === $record['status'] && ! empty( $record['replaced_by'] ) ) {
				$replacement = teatatu_data_get_local( $key, $record['replaced_by'] );
				if ( $replacement && 'active' === $replacement['status'] ) {
					$record = $replacement;
				}
			}
			$out[ $record['uuid'] ] = array(
				'record' => $record,
				'word'   => trim( $part ),
			);
		}
	}
	return array_values( $out );
}

/* ---------------------------------------------------------------------
 * Bringing terms in from Teatatu Events and Teatatu News
 * ------------------------------------------------------------------- */

/**
 * Every term of the vocabulary's source taxonomy on every site.
 *
 * @param string $key Vocabulary.
 * @return array[] {system, term_id, parent_key, label, values}
 */
function teatatu_data_vocabulary_candidates( $key ) {
	$vocab = teatatu_data_vocabularies()[ $key ];
	$name  = 'news' === $vocab['plugin'] ? 'Teatatu News' : 'Teatatu Events';
	$out   = array();
	foreach ( teatatu_data_read_terms_everywhere( $vocab['taxonomy'], $vocab['meta_prefix'] ) as $term ) {
		$system = $vocab['plugin'] . '_s' . $term['site_id'];
		$values = array(
			'name'         => $term['name'],
			'slug'         => sanitize_title( $term['slug'] ),
			'description'  => wp_strip_all_tags( $term['description'] ),
			'external_ids' => array( $system => (string) $term['term_id'] ),
			'source'       => $name,
		);
		if ( $vocab['color'] && ! empty( $term['meta']['tag_color'] ) && preg_match( '/^#[0-9a-f]{6}$/i', $term['meta']['tag_color'] ) ) {
			$values['color'] = $term['meta']['tag_color'];
		}
		$out[] = array(
			'system'     => $system,
			'term_id'    => $term['term_id'],
			'parent_key' => $vocab['hierarchical'] && $term['parent'] ? $system . '=' . $term['parent'] : '',
			/* translators: 1: term name, 2: plugin, 3: site ID */
			'label'      => sprintf( __( '%1$s (%2$s, site %3$d)', 'teatatu-data' ), $term['name'], $name, $term['site_id'] ),
			'values'     => array_filter(
				$values,
				function ( $v ) {
					return '' !== $v;
				}
			),
		);
	}
	return $out;
}

/**
 * Proposes every source term of a vocabulary as one batch: terms already
 * here (same name, slug or synonym) get the source IDs added; the same term
 * on several sites becomes one. Parents are linked once both exist, so run
 * it again after approving to fill them in. Call on the Master Site.
 *
 * @param string       $key        Vocabulary.
 * @param array[]|null $candidates Candidates (default: read from the source plugin).
 * @return array {batch, created, updated, merged, unchanged, pending, parents_later, errors: string[]}
 */
function teatatu_data_import_vocabulary( $key, $candidates = null ) {
	$candidates = null === $candidates ? teatatu_data_vocabulary_candidates( $key ) : $candidates;
	$batch      = $key . '-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 4, false );
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
	foreach ( teatatu_data_get_changes_local( array( 'dataset' => $key, 'state' => 'pending', 'per_page' => -1 ) )['changes'] as $change ) {
		foreach ( isset( $change['values']['external_ids'] ) ? (array) $change['values']['external_ids'] : array() as $system => $id ) {
			$waiting[ $system . '=' . $id ] = true;
		}
	}
	$existing = teatatu_data_find_local( $key, array( 'status' => array( 'active', 'deprecated', 'proposed' ), 'per_page' => -1 ) )['records'];
	$by_id    = array();
	foreach ( $existing as $record ) {
		foreach ( $record['external_ids'] as $system => $id ) {
			$by_id[ $system . '=' . $id ] = $record['uuid'];
		}
	}
	$hierarchical = teatatu_data_vocabularies()[ $key ]['hierarchical'];

	$updates = array();
	$new     = array();
	foreach ( $candidates as $candidate ) {
		$values = $candidate['values'];
		if ( isset( $waiting[ $candidate['system'] . '=' . $candidate['term_id'] ] ) ) {
			$result['pending']++;
			continue;
		}
		$words = array( $values['name'], isset( $values['slug'] ) ? str_replace( '-', ' ', $values['slug'] ) : '' );

		$match = teatatu_data_match_terms_local( $key, $words, $existing );
		if ( $match ) {
			$record = $match[0]['record'];
			$uuid   = $record['uuid'];
			$base   = isset( $updates[ $uuid ] ) ? $updates[ $uuid ] : array(
				'external_ids' => $record['external_ids'],
				'synonyms'     => (string) $record['synonyms'],
			);
			$base['external_ids'] = array_merge( (array) $base['external_ids'], $values['external_ids'] );
			$base['synonyms']     = teatatu_data_merge_aliases( $base['synonyms'], array( $values['name'] ), $record['name'] );
			foreach ( array( 'description', 'color' ) as $field ) {
				if ( empty( $record[ $field ] ) && ! empty( $values[ $field ] ) && array_key_exists( $field, $record ) ) {
					$base[ $field ] = $values[ $field ];
				}
			}
			if ( $hierarchical && $candidate['parent_key'] && empty( $record['parent'] ) && isset( $by_id[ $candidate['parent_key'] ] ) && $by_id[ $candidate['parent_key'] ] !== $uuid ) {
				$base['parent'] = $by_id[ $candidate['parent_key'] ];
			}
			$updates[ $uuid ] = $base;
			continue;
		}

		$pending = array();
		foreach ( $new as $i => $item ) {
			$pending[] = array_merge( array( 'uuid' => (string) $i, 'synonyms' => '', 'slug' => '' ), $item['values'] );
		}
		$dupe = $pending ? teatatu_data_match_terms_local( $key, $words, $pending ) : array();
		if ( $dupe ) {
			$i    = (int) $dupe[0]['record']['uuid'];
			$item = &$new[ $i ];
			$item['values']['external_ids'] = array_merge( $item['values']['external_ids'], $values['external_ids'] );
			$item['values']['synonyms']     = teatatu_data_merge_aliases( isset( $item['values']['synonyms'] ) ? $item['values']['synonyms'] : '', array( $values['name'] ), $item['values']['name'] );
			foreach ( array( 'description', 'color' ) as $field ) {
				if ( empty( $item['values'][ $field ] ) && ! empty( $values[ $field ] ) ) {
					$item['values'][ $field ] = $values[ $field ];
				}
			}
			$item['sources'][] = $candidate['label'];
			if ( $candidate['parent_key'] ) {
				$item['parents'][] = $candidate['parent_key'];
			}
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

	$reason_link = __( 'Link to the matching term(s) in Teatatu Events / Teatatu News.', 'teatatu-data' );
	foreach ( $updates as $uuid => $values ) {
		$change = teatatu_data_propose_local( $key, $uuid, $values, array( 'channel' => 'migration', 'reason' => $reason_link, 'batch' => $batch ) );
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
		foreach ( $item['parents'] as $parent_key ) {
			if ( isset( $by_id[ $parent_key ] ) ) {
				$values['parent'] = $by_id[ $parent_key ];
				break;
			}
		}
		if ( $item['parents'] && empty( $values['parent'] ) ) {
			$result['parents_later']++;
		}
		/* translators: %s: where the term comes from */
		$change = teatatu_data_propose_local( $key, null, $values, array( 'channel' => 'migration', 'reason' => sprintf( __( 'From %s.', 'teatatu-data' ), implode( '; ', $item['sources'] ) ), 'batch' => $batch ) );
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

add_action( 'teatatu_data_records_list_before', 'teatatu_data_vocabulary_admin_tools' );

/**
 * Vocabulary tabs: "Bring in" and "Test matching".
 *
 * @param string $key Data set key.
 */
function teatatu_data_vocabulary_admin_tools( $key ) {
	$vocabularies = teatatu_data_vocabularies();
	if ( ! isset( $vocabularies[ $key ] ) ) {
		return;
	}
	$vocab = $vocabularies[ $key ];
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only lookup.
	$words = isset( $_GET['ttd_words'] ) ? sanitize_text_field( wp_unslash( $_GET['ttd_words'] ) ) : null;
	?>
	<div class="card" style="max-width:none;">
		<?php if ( teatatu_data_is_master_site() && current_user_can( 'import_teatatu_data' ) ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:8px 0;">
				<?php wp_nonce_field( 'teatatu_data_import_vocabulary' ); ?>
				<input type="hidden" name="action" value="teatatu_data_import_vocabulary" />
				<input type="hidden" name="dataset" value="<?php echo esc_attr( $key ); ?>" />
				<?php
				/* translators: %s: plugin name */
				submit_button( sprintf( __( 'Bring in from %s', 'teatatu-data' ), 'news' === $vocab['plugin'] ? 'Teatatu News' : 'Teatatu Events' ), 'secondary', 'submit', false );
				?>
				<span class="description"><?php esc_html_e( 'Gathers this list from every site, merges the same term from different sites, and sends it as one batch of proposed changes to review. Run it again after approving to link parent terms. Nothing is proposed twice.', 'teatatu-data' ); ?></span>
			</form>
		<?php endif; ?>
		<h3 style="margin-top:8px;"><?php esc_html_e( 'Test matching', 'teatatu-data' ); ?></h3>
		<form method="get">
			<input type="hidden" name="page" value="teatatu-data" />
			<input type="hidden" name="tab" value="records" />
			<input type="hidden" name="dataset" value="<?php echo esc_attr( $key ); ?>" />
			<input type="text" name="ttd_words" value="<?php echo esc_attr( (string) $words ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Words from a feed, comma-separated', 'teatatu-data' ); ?>" />
			<?php submit_button( __( 'Match', 'teatatu-data' ), 'secondary', '', false ); ?>
		</form>
		<?php if ( null !== $words ) : ?>
			<?php $matches = teatatu_data_match_terms( $key, $words ); ?>
			<p><strong>
				<?php
				if ( $matches ) {
					$parts = array();
					foreach ( $matches as $match ) {
						$parts[] = $match['word'] . ' → ' . $match['record']['name'];
					}
					echo esc_html( implode( ' · ', $parts ) );
				} else {
					esc_html_e( 'Nothing matches; these words would be ignored.', 'teatatu-data' );
				}
				?>
			</strong></p>
		<?php endif; ?>
	</div>
	<?php
}

add_action( 'admin_post_teatatu_data_import_vocabulary', 'teatatu_data_handle_import_vocabulary' );

/**
 * Handles "Bring in" for a vocabulary.
 */
function teatatu_data_handle_import_vocabulary() {
	check_admin_referer( 'teatatu_data_import_vocabulary' );
	if ( ! current_user_can( 'import_teatatu_data' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'teatatu-data' ), 403 );
	}
	teatatu_data_admin_require_master();
	$key = isset( $_POST['dataset'] ) ? sanitize_key( wp_unslash( $_POST['dataset'] ) ) : '';
	if ( ! isset( teatatu_data_vocabularies()[ $key ] ) ) {
		wp_die( esc_html__( 'Unknown data set.', 'teatatu-data' ), 404 );
	}
	$result = teatatu_data_import_vocabulary( $key );
	/* translators: 1: new, 2: to link, 3: merged, 4: up to date */
	$message = sprintf( __( '%1$d new terms, %2$d to link to existing ones, %3$d duplicates merged, %4$d already up to date.', 'teatatu-data' ), $result['created'], $result['updated'], $result['merged'], $result['unchanged'] );
	if ( $result['pending'] ) {
		/* translators: %d: skipped */
		$message .= ' ' . sprintf( __( '%d skipped because they are already waiting for review.', 'teatatu-data' ), $result['pending'] );
	}
	if ( $result['parents_later'] ) {
		/* translators: %d: terms */
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

add_action( 'rest_api_init', 'teatatu_data_register_vocabulary_rest_route' );

/**
 * GET /teatatu-data/v1/vocabularies/<key>/match?values=word,word
 */
function teatatu_data_register_vocabulary_rest_route() {
	register_rest_route(
		'teatatu-data/v1',
		'/vocabularies/(?P<dataset>[a-z][a-z0-9_]*)/match',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => function ( $request ) {
				$key = (string) $request['dataset'];
				if ( ! isset( teatatu_data_vocabularies()[ $key ] ) ) {
					return new WP_Error( 'teatatu_data_unknown_dataset', __( 'Unknown vocabulary.', 'teatatu-data' ), array( 'status' => 404 ) );
				}
				$out = array();
				foreach ( teatatu_data_match_terms( $key, sanitize_text_field( (string) $request['values'] ) ) as $match ) {
					$out[] = array_merge( $match['record'], array( 'matched_word' => $match['word'] ) );
				}
				return rest_ensure_response( $out );
			},
			'permission_callback' => function () {
				return current_user_can( 'read_teatatu_data' );
			},
			'args'                => array(
				'values' => array(
					'type'    => 'string',
					'default' => '',
				),
			),
		)
	);
}

/**
 * Terms that may be the same: any word (name, slug, synonym) in common —
 * an incoming word could then match either.
 *
 * @param array $record Term.
 * @return string[]
 */
function teatatu_data_vocabulary_duplicate_keys( $record ) {
	return array_map(
		function ( $word ) {
			return 'word:' . $word;
		},
		teatatu_data_term_words( $record )
	);
}
