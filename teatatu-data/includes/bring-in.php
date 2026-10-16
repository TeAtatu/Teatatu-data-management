<?php
/**
 * Shared helpers for bringing data in from the consumer plugins (Teatatu
 * Events, Teatatu News): reading their taxonomy terms on every site straight
 * from the tables (so it works whether or not the plugin is active), merging
 * alias lists, and turning an error into one line for a report.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every term of a taxonomy on every site (or this site on a single install).
 *
 * @param string $taxonomy    Taxonomy name.
 * @param string $meta_prefix Prefix stripped from meta keys (e.g. 'teatatu_events_').
 * @return array[] {site_id, term_id, name, slug, description, parent, meta: key => value}
 */
function teatatu_data_read_terms_everywhere( $taxonomy, $meta_prefix = '' ) {
	global $wpdb;
	$site_ids = is_multisite() ? array_map( 'intval', get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) ) : array( get_current_blog_id() );
	$out      = array();
	foreach ( $site_ids as $site_id ) {
		$prefix = $wpdb->get_blog_prefix( $site_id );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb.
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $prefix . 'termmeta' ) ) ) {
			continue;
		}
		$terms = $wpdb->get_results( $wpdb->prepare( "SELECT t.term_id, t.name, t.slug, tt.description, tt.parent FROM {$prefix}terms t JOIN {$prefix}term_taxonomy tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s ORDER BY tt.parent, t.term_id", $taxonomy ) );
		foreach ( $terms as $term ) {
			$meta = array();
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$prefix}termmeta WHERE term_id = %d", $term->term_id ) ) as $row ) {
				$key          = '' !== $meta_prefix && 0 === strpos( $row->meta_key, $meta_prefix ) ? substr( $row->meta_key, strlen( $meta_prefix ) ) : $row->meta_key;
				$meta[ $key ] = (string) $row->meta_value;
			}
			$out[] = array(
				'site_id'     => $site_id,
				'term_id'     => (int) $term->term_id,
				'name'        => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
				'slug'        => (string) $term->slug,
				'description' => (string) $term->description,
				'parent'      => (int) $term->parent,
				'meta'        => $meta,
			);
		}
		// phpcs:enable
	}
	return $out;
}

/**
 * Merges a comma-separated alias list with more names, leaving out the
 * record's own name and names that differ only by case, accents or
 * punctuation (matching ignores those anyway).
 *
 * @param string   $aliases Existing aliases.
 * @param string[] $more    More names (each may itself be a comma list).
 * @param string   $name    The record's own name.
 * @return string
 */
function teatatu_data_merge_aliases( $aliases, $more, $name ) {
	$out  = array();
	$seen = array( teatatu_data_fold_text( $name ) => true );
	foreach ( array_merge( explode( ',', (string) $aliases ), explode( ',', implode( ',', (array) $more ) ) ) as $alias ) {
		$alias  = trim( (string) $alias );
		$folded = teatatu_data_fold_text( $alias );
		if ( '' !== $folded && ! isset( $seen[ $folded ] ) ) {
			$seen[ $folded ] = true;
			$out[]           = $alias;
		}
	}
	return implode( ', ', $out );
}

/**
 * An error's message with its field messages, on one line.
 *
 * @param WP_Error $error Error.
 * @return string
 */
function teatatu_data_error_text( $error ) {
	$message = $error->get_error_message();
	$data    = $error->get_error_data();
	if ( ! empty( $data['fields'] ) ) {
		foreach ( $data['fields'] as $field => $field_message ) {
			$message .= ' ' . $field . ': ' . $field_message;
		}
	}
	return $message;
}
