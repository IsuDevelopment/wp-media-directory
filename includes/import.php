<?php
/**
 * Import folders from HappyFiles.
 *
 * Reads the `happyfiles_category` terms straight from the database, so it
 * works with HappyFiles deactivated. Each imported folder remembers its
 * source term, which makes the import safe to run again.
 *
 * @package IsuDevMediaDirectory
 */

declare( strict_types = 1 );

namespace IsuDevMediaDirectory\Import;

use IsuDevMediaDirectory\Folders;
use IsuDevMediaDirectory\Schema;

defined( 'ABSPATH' ) || exit;

const HAPPYFILES_TAXONOMY = 'happyfiles_category';
const REF_PREFIX          = 'happyfiles:';

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- One-off import from another plugin's terms and into custom tables.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names only; values are prepared.

/**
 * HappyFiles terms as term_taxonomy_id => row.
 *
 * @return array<int, array{term_id:int, parent:int, name:string}>
 */
function happyfiles_terms(): array {
	global $wpdb;

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT tt.term_taxonomy_id, t.term_id, tt.parent, t.name
			FROM {$wpdb->term_taxonomy} tt
			INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
			WHERE tt.taxonomy = %s",
			HAPPYFILES_TAXONOMY
		),
		ARRAY_A
	);

	$terms = array();

	foreach ( (array) $rows as $row ) {
		$terms[ (int) $row['term_taxonomy_id'] ] = array(
			'term_id' => (int) $row['term_id'],
			'parent'  => (int) $row['parent'],
			'name'    => html_entity_decode( (string) $row['name'], ENT_QUOTES, 'UTF-8' ),
		);
	}

	return $terms;
}

/**
 * Folder IDs already imported, keyed by HappyFiles term ID.
 *
 * @return array<int, int>
 */
function imported_map(): array {
	global $wpdb;

	$table = Schema\folders_table();
	$rows  = $wpdb->get_results(
		$wpdb->prepare( "SELECT id, import_ref FROM {$table} WHERE import_ref LIKE %s", $wpdb->esc_like( REF_PREFIX ) . '%' ),
		ARRAY_A
	);

	$map = array();

	foreach ( (array) $rows as $row ) {
		$map[ (int) substr( $row['import_ref'], strlen( REF_PREFIX ) ) ] = (int) $row['id'];
	}

	return $map;
}

/**
 * How many HappyFiles folders exist and how many are imported.
 *
 * @return array{folders:int, imported:int}
 */
function happyfiles_summary(): array {
	$terms    = happyfiles_terms();
	$imported = array_intersect_key( imported_map(), array_flip( wp_list_pluck( $terms, 'term_id' ) ) );

	return array(
		'folders'  => count( $terms ),
		'imported' => count( $imported ),
	);
}

/**
 * Copy the HappyFiles tree and file assignments.
 *
 * @return array{folders:int, files:int} Folders created and files filed.
 */
function happyfiles(): array {
	global $wpdb;

	$terms   = happyfiles_terms();
	$map     = imported_map();
	$by_term = array();
	$created = 0;

	foreach ( $terms as $term ) {
		$by_term[ $term['term_id'] ] = $term;
	}

	// Create parents before children; each pass files whatever has its parent ready.
	$pending = $by_term;

	while ( $pending ) {
		$progress = false;

		foreach ( $pending as $term_id => $term ) {
			$parent_ready = ! $term['parent'] || isset( $map[ $term['parent'] ] ) || ! isset( $by_term[ $term['parent'] ] );

			if ( ! $parent_ready ) {
				continue;
			}

			if ( ! isset( $map[ $term_id ] ) ) {
				$map[ $term_id ] = Folders\create( $term['name'], $map[ $term['parent'] ] ?? 0, REF_PREFIX . $term_id );
				++$created;
			}

			unset( $pending[ $term_id ] );
			$progress = true;
		}

		// A parent loop in the source data: put the rest at the root.
		if ( ! $progress ) {
			foreach ( $pending as $term_id => $term ) {
				$map[ $term_id ] = Folders\create( $term['name'], 0, REF_PREFIX . $term_id );
				++$created;
			}
			break;
		}
	}

	$tt_ids = array_keys( $terms );
	$pairs  = array();

	if ( $tt_ids ) {
		$in   = implode( ',', array_map( 'intval', $tt_ids ) );
		$rows = $wpdb->get_results(
			"SELECT tr.object_id, tr.term_taxonomy_id FROM {$wpdb->term_relationships} tr
			INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id AND p.post_type = 'attachment'
			WHERE tr.term_taxonomy_id IN ({$in})",
			ARRAY_N
		);

		foreach ( (array) $rows as $row ) {
			$term_id = $terms[ (int) $row[1] ]['term_id'];
			$pairs[] = array( $map[ $term_id ], (int) $row[0] );
		}
	}

	Folders\insert_items( $pairs );

	return array(
		'folders' => $created,
		'files'   => count( array_unique( wp_list_pluck( $pairs, 1 ) ) ),
	);
}
