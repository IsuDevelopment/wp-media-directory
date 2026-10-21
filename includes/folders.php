<?php
/**
 * Folder repository: the only code that talks to the folder tables.
 *
 * @package IsuDevMediaDirectory
 */

declare( strict_types = 1 );

namespace IsuDevMediaDirectory\Folders;

use IsuDevMediaDirectory\Schema;

defined( 'ABSPATH' ) || exit;

const UNASSIGNED = 'unassigned';

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom tables; there is no API for them.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from Schema, values are prepared or cast.

/**
 * Every folder as id => row, cached for the request.
 *
 * @param bool $refresh Drop the cache first.
 * @return array<int, array{id:int, parent:int, name:string}>
 */
function all( bool $refresh = false ): array {
	static $cache = null;

	if ( null !== $cache && ! $refresh ) {
		return $cache;
	}

	global $wpdb;

	$table = Schema\folders_table();
	$rows  = $wpdb->get_results( "SELECT id, parent_id, name FROM {$table}", ARRAY_A );
	$cache = array();

	foreach ( (array) $rows as $row ) {
		$cache[ (int) $row['id'] ] = array(
			'id'     => (int) $row['id'],
			'parent' => (int) $row['parent_id'],
			'name'   => (string) $row['name'],
		);
	}

	return $cache;
}

/**
 * Whether a folder exists.
 *
 * @param int $id Folder ID.
 * @return bool
 */
function exists( int $id ): bool {
	return isset( all()[ $id ] );
}

/**
 * IDs of a folder and everything below it.
 *
 * @param int $id Folder ID.
 * @return int[]
 */
function subtree( int $id ): array {
	$children = array();

	foreach ( all() as $folder ) {
		$children[ $folder['parent'] ][] = $folder['id'];
	}

	$ids   = array();
	$stack = array( $id );

	while ( $stack ) {
		$current = array_pop( $stack );

		if ( in_array( $current, $ids, true ) ) {
			continue;
		}

		$ids[] = $current;

		foreach ( $children[ $current ] ?? array() as $child ) {
			$stack[] = $child;
		}
	}

	return $ids;
}

/**
 * Folder IDs a view of `$folder` covers.
 *
 * @param int  $folder Folder ID.
 * @param bool $nested Include subfolders.
 * @return int[]
 */
function scope( int $folder, bool $nested ): array {
	return $nested ? subtree( $folder ) : array( $folder );
}

/**
 * Create a folder.
 *
 * @param string $name   Folder name.
 * @param int    $parent_id Parent folder ID, 0 for the root.
 * @param string $ref    Import reference.
 * @return int New folder ID.
 */
function create( string $name, int $parent_id = 0, string $ref = '' ): int {
	global $wpdb;

	$wpdb->insert(
		Schema\folders_table(),
		array(
			'parent_id'  => $parent_id,
			'name'       => $name,
			'import_ref' => $ref,
			'created_at' => current_time( 'mysql', true ),
		),
		array( '%d', '%s', '%s', '%s' )
	);

	all( true );

	return (int) $wpdb->insert_id;
}

/**
 * Rename and/or move a folder.
 *
 * @param int                              $id     Folder ID.
 * @param array{name?:string, parent?:int} $fields Fields to change.
 * @return void
 */
function update( int $id, array $fields ): void {
	global $wpdb;

	$data = array();

	if ( isset( $fields['name'] ) ) {
		$data['name'] = $fields['name'];
	}

	if ( isset( $fields['parent'] ) ) {
		$data['parent_id'] = $fields['parent'];
	}

	if ( $data ) {
		$wpdb->update( Schema\folders_table(), $data, array( 'id' => $id ) );
		all( true );
	}
}

/**
 * Remember where a folder was imported from, unless it already says so.
 *
 * @param int    $id  Folder ID.
 * @param string $ref `<source>:<source id>`.
 * @return void
 */
function set_ref( int $id, string $ref ): void {
	global $wpdb;

	$table = Schema\folders_table();

	$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET import_ref = %s WHERE id = %d AND import_ref = ''", $ref, $id ) );
}

/**
 * Delete a folder with its subfolders. Files are kept, only unfiled.
 *
 * @param int $id Folder ID.
 * @return array{folders: array<int, array<string, mixed>>, items: array<int, int[]>} Snapshot for restore().
 */
function delete( int $id ): array {
	global $wpdb;

	$ids          = subtree( $id );
	$in           = implode( ',', array_map( 'intval', $ids ) );
	$folder_table = Schema\folders_table();
	$items_table  = Schema\items_table();

	$snapshot = array(
		'folders' => $wpdb->get_results( "SELECT id, parent_id, name, import_ref, created_at FROM {$folder_table} WHERE id IN ({$in})", ARRAY_A ),
		'items'   => array_map(
			static function ( $row ) {
				return array( (int) $row[0], (int) $row[1] );
			},
			$wpdb->get_results( "SELECT folder_id, attachment_id FROM {$items_table} WHERE folder_id IN ({$in})", ARRAY_N )
		),
	);

	$wpdb->query( "DELETE FROM {$items_table} WHERE folder_id IN ({$in})" );
	$wpdb->query( "DELETE FROM {$folder_table} WHERE id IN ({$in})" );
	all( true );

	return $snapshot;
}

/**
 * Put back what delete() removed, under the same IDs.
 *
 * @param array<string, mixed> $snapshot Snapshot from delete().
 * @return void
 */
function restore( array $snapshot ): void {
	global $wpdb;

	foreach ( (array) ( $snapshot['folders'] ?? array() ) as $row ) {
		$id     = (int) ( $row['id'] ?? 0 );
		$parent = (int) ( $row['parent_id'] ?? 0 );

		if ( ! $id || exists( $id ) ) {
			continue;
		}

		$wpdb->insert(
			Schema\folders_table(),
			array(
				'id'         => $id,
				'parent_id'  => $parent,
				'name'       => sanitize_text_field( (string) ( $row['name'] ?? '' ) ),
				'import_ref' => sanitize_text_field( (string) ( $row['import_ref'] ?? '' ) ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);
	}

	all( true );

	$items = array();

	foreach ( (array) ( $snapshot['items'] ?? array() ) as $pair ) {
		$folder     = (int) ( $pair[0] ?? 0 );
		$attachment = (int) ( $pair[1] ?? 0 );

		if ( exists( $folder ) && current_user_can( 'edit_post', $attachment ) ) {
			$items[] = array( $folder, $attachment );
		}
	}

	insert_items( $items );
}

/**
 * Insert folder/attachment pairs, ignoring ones already there.
 *
 * @param array<int, array{0:int, 1:int}> $pairs Pairs of folder ID and attachment ID.
 * @return void
 */
function insert_items( array $pairs ): void {
	global $wpdb;

	$table = Schema\items_table();

	foreach ( array_chunk( $pairs, 500 ) as $chunk ) {
		$values = implode(
			',',
			array_map(
				static function ( $pair ) {
					return sprintf( '(%d,%d)', $pair[0], $pair[1] );
				},
				$chunk
			)
		);

		$wpdb->query( "INSERT IGNORE INTO {$table} (folder_id, attachment_id) VALUES {$values}" );
	}
}

/**
 * Attachments filed in any of the given folders.
 *
 * @param int[] $folder_ids Folder IDs.
 * @return int[]
 */
function attachments_in( array $folder_ids ): array {
	global $wpdb;

	$folder_ids = array_filter( array_map( 'intval', $folder_ids ) );

	if ( ! $folder_ids ) {
		return array();
	}

	$table = Schema\items_table();
	$in    = implode( ',', $folder_ids );

	return array_map( 'intval', $wpdb->get_col( "SELECT DISTINCT attachment_id FROM {$table} WHERE folder_id IN ({$in})" ) );
}

/**
 * Folder IDs per attachment.
 *
 * @param int[] $attachment_ids Attachment IDs.
 * @return array<int, int[]>
 */
function of( array $attachment_ids ): array {
	global $wpdb;

	$attachment_ids = array_filter( array_map( 'intval', $attachment_ids ) );
	$map            = array_fill_keys( $attachment_ids, array() );

	if ( ! $attachment_ids ) {
		return $map;
	}

	$table = Schema\items_table();
	$in    = implode( ',', $attachment_ids );
	$rows  = $wpdb->get_results( "SELECT attachment_id, folder_id FROM {$table} WHERE attachment_id IN ({$in})", ARRAY_N );

	foreach ( (array) $rows as $row ) {
		$map[ (int) $row[0] ][] = (int) $row[1];
	}

	return $map;
}

/**
 * Replace the folders of each attachment.
 *
 * @param array<int, int[]> $map Attachment ID => folder IDs.
 * @return void
 */
function set( array $map ): void {
	global $wpdb;

	if ( ! $map ) {
		return;
	}

	$table = Schema\items_table();
	$in    = implode( ',', array_map( 'intval', array_keys( $map ) ) );
	$pairs = array();

	$wpdb->query( "DELETE FROM {$table} WHERE attachment_id IN ({$in})" );

	foreach ( $map as $attachment => $folders ) {
		foreach ( array_unique( array_map( 'intval', $folders ) ) as $folder ) {
			if ( exists( $folder ) ) {
				$pairs[] = array( $folder, (int) $attachment );
			}
		}
	}

	insert_items( $pairs );
}

/**
 * Remove an attachment from every folder.
 *
 * @param int $attachment_id Attachment ID.
 * @return void
 */
function forget( int $attachment_id ): void {
	global $wpdb;

	$wpdb->delete( Schema\items_table(), array( 'attachment_id' => $attachment_id ), array( '%d' ) );
}

/**
 * The tree as the UI needs it: folders with direct and subtree counts.
 *
 * `total` counts distinct files anywhere below a folder, so a file filed in
 * two subfolders is counted once.
 *
 * @return array{folders: array<int, array<string, mixed>>, all: int, unassigned: int}
 */
function tree(): array {
	global $wpdb;

	$folders = all();
	$items   = Schema\items_table();
	$pairs   = $wpdb->get_results(
		"SELECT i.folder_id, i.attachment_id FROM {$items} i
		INNER JOIN {$wpdb->posts} p ON p.ID = i.attachment_id AND p.post_type = 'attachment' AND p.post_status <> 'trash'",
		ARRAY_N
	);

	$direct = array();
	$below  = array();

	foreach ( (array) $pairs as $pair ) {
		$folder     = (int) $pair[0];
		$attachment = (int) $pair[1];

		if ( ! isset( $folders[ $folder ] ) ) {
			continue;
		}

		$direct[ $folder ] = ( $direct[ $folder ] ?? 0 ) + 1;

		// Walk up the ancestors; the guard stops a corrupt parent loop.
		for ( $current = $folder, $guard = 0; $current && isset( $folders[ $current ] ) && $guard < 100; $current = $folders[ $current ]['parent'], $guard++ ) {
			$below[ $current ][ $attachment ] = true;
		}
	}

	$out = array();

	foreach ( $folders as $id => $folder ) {
		$out[] = array(
			'id'     => $id,
			'parent' => $folder['parent'],
			'name'   => $folder['name'],
			'count'  => $direct[ $id ] ?? 0,
			'total'  => isset( $below[ $id ] ) ? count( $below[ $id ] ) : 0,
		);
	}

	$all = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status <> 'trash'" );

	$unassigned = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->posts} p
		WHERE p.post_type = 'attachment' AND p.post_status <> 'trash'
		AND NOT EXISTS ( SELECT 1 FROM {$items} i WHERE i.attachment_id = p.ID )"
	);

	return array(
		'folders'    => $out,
		'all'        => $all,
		'unassigned' => $unassigned,
	);
}
