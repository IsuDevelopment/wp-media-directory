<?php
/**
 * Narrow attachment queries to a folder.
 *
 * Grid view and every media modal load files through admin-ajax
 * `query-attachments`, which drops unknown query keys, so the folder is read
 * from the raw request. List view uses the main query on upload.php.
 *
 * @package IsuDevMediaDirectory
 */

declare( strict_types = 1 );

namespace IsuDevMediaDirectory\Query;

use IsuDevMediaDirectory\Folders;
use IsuDevMediaDirectory\Schema;
use IsuDevMediaDirectory\Settings;
use WP_Query;

defined( 'ABSPATH' ) || exit;

const FOLDER_VAR = 'imd_folder';
const NESTED_VAR = 'imd_nested';

/**
 * Hook the query filters.
 *
 * @return void
 */
function boot(): void {
	add_filter( 'ajax_query_attachments_args', __NAMESPACE__ . '\\ajax_args' );
	add_action( 'pre_get_posts', __NAMESPACE__ . '\\list_view' );
	add_filter( 'posts_where', __NAMESPACE__ . '\\where', 10, 2 );
	add_filter( 'the_posts', __NAMESPACE__ . '\\prime', 10, 2 );
}

/**
 * Turn a request value into a folder ID, 'unassigned' or null for all files.
 *
 * @param mixed $value Raw value.
 * @return int|string|null
 */
function parse_folder( $value ) {
	if ( Folders\UNASSIGNED === $value ) {
		return Folders\UNASSIGNED;
	}

	$id = absint( $value );

	return $id && Folders\exists( $id ) ? $id : null;
}

/**
 * Carry the folder from the media modal request into the query.
 *
 * @param array<string, mixed> $args WP_Query args.
 * @return array<string, mixed>
 */
function ajax_args( array $args ): array {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Core checked capability; this only narrows a read.
	$query = isset( $_REQUEST['query'] ) && is_array( $_REQUEST['query'] ) ? map_deep( wp_unslash( $_REQUEST['query'] ), 'sanitize_text_field' ) : array();

	if ( isset( $query[ FOLDER_VAR ] ) ) {
		$args[ FOLDER_VAR ] = parse_folder( $query[ FOLDER_VAR ] );
		$args[ NESTED_VAR ] = isset( $query[ NESTED_VAR ] ) ? rest_sanitize_boolean( $query[ NESTED_VAR ] ) : Settings\nested_for_user();
	}

	return $args;
}

/**
 * Carry the folder from the list view URL into the main query.
 *
 * @param WP_Query $query Query.
 * @return void
 */
function list_view( WP_Query $query ): void {
	global $pagenow;

	if ( ! is_admin() || ! $query->is_main_query() || 'upload.php' !== $pagenow ) {
		return;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filter.
	if ( isset( $_GET[ FOLDER_VAR ] ) ) {
		$query->set( FOLDER_VAR, parse_folder( sanitize_key( wp_unslash( $_GET[ FOLDER_VAR ] ) ) ) );
		$query->set( NESTED_VAR, isset( $_GET[ NESTED_VAR ] ) ? rest_sanitize_boolean( wp_unslash( $_GET[ NESTED_VAR ] ) ) : Settings\nested_for_user() );
	}
	// phpcs:enable
}

/**
 * Add the folder condition to the SQL.
 *
 * @param string   $where WHERE clause.
 * @param WP_Query $query Query.
 * @return string
 */
function where( string $where, WP_Query $query ): string {
	$folder = $query->get( FOLDER_VAR, null );

	if ( null === $folder || '' === $folder ) {
		return $where;
	}

	global $wpdb;

	$items = Schema\items_table();

	if ( Folders\UNASSIGNED === $folder ) {
		return $where . " AND NOT EXISTS ( SELECT 1 FROM {$items} imd WHERE imd.attachment_id = {$wpdb->posts}.ID )";
	}

	$ids = implode( ',', array_map( 'intval', Folders\scope( (int) $folder, (bool) $query->get( NESTED_VAR ) ) ) );

	return $where . " AND {$wpdb->posts}.ID IN ( SELECT imd.attachment_id FROM {$items} imd WHERE imd.folder_id IN ({$ids}) )";
}

/**
 * Load folder IDs for a page of attachments in one query.
 *
 * @param \WP_Post[] $posts Posts.
 * @param WP_Query   $query Query.
 * @return \WP_Post[]
 */
function prime( array $posts, WP_Query $query ): array {
	if ( 'attachment' === $query->get( 'post_type' ) && $posts ) {
		folders_of( wp_list_pluck( $posts, 'ID' ) );
	}

	return $posts;
}

/**
 * Cached folder IDs of attachments.
 *
 * @param int[] $ids Attachment IDs.
 * @return array<int, int[]>
 */
function folders_of( array $ids ): array {
	static $cache = array();

	$missing = array_diff( array_map( 'intval', $ids ), array_keys( $cache ) );

	if ( $missing ) {
		$cache = Folders\of( $missing ) + $cache;
	}

	return array_intersect_key( $cache, array_flip( $ids ) );
}
