<?php
/**
 * End-to-end smoke test against a real WordPress: repository, query filter,
 * REST routes, delete + restore. Leaves the site as it found it.
 *
 * Usage: npm run test:smoke   (wp eval-file tools/smoke.php)
 * Needs: plugin active, at least 3 attachments, wp-cli.local.yml pointing at the site.
 *
 * @package IsuDevMediaDirectory
 */

// No strict_types: wp eval-file evals this file, where declare() is not allowed.

use IsuDevMediaDirectory\Download;
use IsuDevMediaDirectory\Folders;
use IsuDevMediaDirectory\Import;
use IsuDevMediaDirectory\Permissions;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Dev script run through wp eval-file.

$imd_passed = 0;
$imd_failed = array();

$imd_is = static function ( string $label, $actual, $expected ) use ( &$imd_passed, &$imd_failed ) {
	if ( $actual === $expected ) {
		++$imd_passed;
		return;
	}

	$imd_failed[] = sprintf( '%s — expected %s, got %s', $label, wp_json_encode( $expected ), wp_json_encode( $actual ) );
};

$imd_rest = static function ( string $method, string $path, array $params = array() ) {
	$request = new WP_REST_Request( $method, '/isudev-media-directory/v1' . $path );

	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}

	return rest_do_request( $request )->get_data();
};

$imd_query = static function ( array $ids, $folder, bool $nested ): array {
	$query = new WP_Query(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post__in'       => $ids,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'imd_folder'     => $folder,
			'imd_nested'     => $nested,
		)
	);

	return array_map( 'intval', $query->posts );
};

$imd_admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
wp_set_current_user( (int) ( $imd_admins[0] ?? 0 ) );

$imd_files = array_map(
	'intval',
	get_posts(
		array(
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			'numberposts' => 3,
			'orderby'     => 'ID',
			'order'       => 'ASC',
			'fields'      => 'ids',
		)
	)
);

if ( count( $imd_files ) < 3 ) {
	WP_CLI::error( 'Need at least 3 attachments.' );
}

list( $imd_f1, $imd_f2, $imd_f3 ) = $imd_files;

$imd_original = Folders\of( $imd_files );
$imd_a        = Folders\create( 'Smoke A', 0, 'smoke' );
$imd_b        = Folders\create( 'Smoke B', $imd_a, 'smoke' );
$imd_c        = Folders\create( 'Smoke C', 0, 'smoke' );

try {
	Folders\set(
		array(
			$imd_f1 => array( $imd_b ),
			$imd_f2 => array( $imd_a ),
			$imd_f3 => array(),
		)
	);

	$imd_tree = array_column( Folders\tree()['folders'], null, 'id' );
	$imd_is( 'A direct count', $imd_tree[ $imd_a ]['count'], 1 );
	$imd_is( 'A total includes B', $imd_tree[ $imd_a ]['total'], 2 );
	$imd_is( 'B total', $imd_tree[ $imd_b ]['total'], 1 );

	$imd_is( 'query A nested', $imd_query( $imd_files, $imd_a, true ), array( $imd_f1, $imd_f2 ) );
	$imd_is( 'query A flat', $imd_query( $imd_files, $imd_a, false ), array( $imd_f2 ) );
	$imd_is( 'query unassigned', $imd_query( $imd_files, Folders\UNASSIGNED, false ), array( $imd_f3 ) );

	// Search runs in SQL over the whole library, narrowed to the folder view.
	$imd_title = get_the_title( $imd_f1 );
	$imd_found = new WP_Query(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			's'              => $imd_title,
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'imd_folder'     => $imd_a,
			'imd_nested'     => true,
		)
	);
	$imd_is( 'search inside a folder finds a file from its subfolder', in_array( $imd_f1, array_map( 'intval', $imd_found->posts ), true ), true );
	$imd_found = new WP_Query(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			's'              => $imd_title,
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'imd_folder'     => $imd_c,
			'imd_nested'     => true,
		)
	);
	$imd_is( 'search inside another folder does not find it', in_array( $imd_f1, array_map( 'intval', $imd_found->posts ), true ), false );

	$imd_moved = $imd_rest(
		'POST',
		'/items',
		array(
			'ids'    => array( $imd_f1, $imd_f2 ),
			'folder' => $imd_c,
			'mode'   => 'move',
			'from'   => $imd_a,
			'nested' => true,
		)
	);
	$imd_is(
		'move out of A view into C',
		Folders\of( array( $imd_f1, $imd_f2 ) ),
		array(
			$imd_f1 => array( $imd_c ),
			$imd_f2 => array( $imd_c ),
		)
	);

	$imd_rest( 'POST', '/items/set', array( 'map' => $imd_moved['previous'] ) );
	$imd_is(
		'undo move',
		Folders\of( array( $imd_f1, $imd_f2 ) ),
		array(
			$imd_f1 => array( $imd_b ),
			$imd_f2 => array( $imd_a ),
		)
	);

	$imd_rest(
		'POST',
		'/items',
		array(
			'ids'    => array( $imd_f3 ),
			'folder' => $imd_c,
			'mode'   => 'add',
		)
	);
	$imd_is( 'add keeps nothing else', Folders\of( array( $imd_f3 ) ), array( $imd_f3 => array( $imd_c ) ) );

	$imd_colored = $imd_rest( 'POST', '/folders/' . $imd_b, array( 'color' => '#F2C' ) );
	$imd_is( 'color is stored as #rrggbb', Folders\all()[ $imd_b ]['color'], '#ff22cc' );
	$imd_is( 'color change returns the previous color', $imd_colored['previous']['color'], '' );
	$imd_is( 'tree carries the color', array_column( $imd_colored['tree']['folders'], 'color', 'id' )[ $imd_b ], '#ff22cc' );
	$imd_is( 'invalid color is refused', $imd_rest( 'POST', '/folders/' . $imd_b, array( 'color' => 'red' ) )['code'] ?? '', 'imd_bad_color' );

	$imd_deleted = $imd_rest(
		'DELETE',
		'/folders/' . $imd_a,
		array(
			'files'  => 'move',
			'target' => $imd_c,
		)
	);
	$imd_is( 'delete A removes B', Folders\exists( $imd_b ), false );
	$imd_is( 'delete A moves files to C', $imd_deleted['moved'], 2 );
	$imd_is( 'files now in C', $imd_query( $imd_files, $imd_c, false ), $imd_files );

	$imd_rest( 'POST', '/folders/restore', array( 'snapshot' => $imd_deleted['snapshot'] ) );
	$imd_rest( 'POST', '/items/set', array( 'map' => $imd_deleted['previous'] ) );
	$imd_is( 'restore brings B back', Folders\exists( $imd_b ), true );
	// Download: A's file at the root of the ZIP, B's file in "Smoke B/".
	$imd_zip_file = Download\build( $imd_a );
	$imd_entries  = array();

	if ( is_string( $imd_zip_file ) ) {
		$imd_zip = new ZipArchive();
		$imd_zip->open( $imd_zip_file );

		for ( $imd_i = 0; $imd_i < $imd_zip->count(); $imd_i++ ) { // phpcs:ignore Generic.CodeAnalysis.ForLoopWithTestFunctionCall
			$imd_entries[] = $imd_zip->getNameIndex( $imd_i );
		}

		$imd_zip->close();
		wp_delete_file( $imd_zip_file );
	}

	sort( $imd_entries );
	$imd_expected = array(
		'Smoke B/',
		'Smoke B/' . wp_basename( Download\file_of( $imd_f1 ) ),
		wp_basename( Download\file_of( $imd_f2 ) ),
	);
	sort( $imd_expected );
	$imd_is( 'download zip holds the folder with its subfolder', $imd_entries, $imd_expected );
	$imd_is( 'download of an empty folder is refused', is_wp_error( Download\build( Folders\create( 'Smoke empty', $imd_c, 'smoke' ) ) ), true );
	$imd_used = array();
	$imd_is( 'duplicate names get a number', array( Download\unique_entry( '', 'a.jpg', $imd_used ), Download\unique_entry( '', 'A.jpg', $imd_used ) ), array( 'a.jpg', 'A (2).jpg' ) );
	$imd_is( 'zip names lose path characters', Download\clean_name( 'a/b: "c"' ), 'a-b- -c' );

	$imd_is( 'restore keeps the color', Folders\all()[ $imd_b ]['color'], '#ff22cc' );
	$imd_rest( 'POST', '/folders/' . $imd_b, array( 'color' => '' ) );
	$imd_is( 'empty color clears it', Folders\all()[ $imd_b ]['color'], '' );
	$imd_is(
		'restore puts files back',
		Folders\of( array( $imd_f1, $imd_f2 ) ),
		array(
			$imd_f1 => array( $imd_b ),
			$imd_f2 => array( $imd_a ),
		)
	);

	$imd_cycle = new WP_REST_Request( 'POST', '/isudev-media-directory/v1/folders/' . $imd_a );
	$imd_cycle->set_param( 'parent', $imd_b );
	$imd_is( 'moving a folder into its child is refused', rest_do_request( $imd_cycle )->get_status(), 400 );
	$imd_is( 'parent unchanged', Folders\all( true )[ $imd_a ]['parent'], 0 );
} finally {
	foreach ( array( $imd_a, $imd_c ) as $imd_id ) {
		if ( Folders\exists( $imd_id ) ) {
			Folders\delete( $imd_id );
		}
	}

	Folders\set( $imd_original );
}

/*
 * Preferences: partial saves keep the rest; the 0.1.0 key is still read.
 */
$imd_user_id    = get_current_user_id();
$imd_saved_pref = get_user_meta( $imd_user_id, IsuDevMediaDirectory\Settings\PREFS_META, true );
$imd_saved_old  = get_user_meta( $imd_user_id, IsuDevMediaDirectory\Settings\USER_NESTED_META, true );

try {
	delete_user_meta( $imd_user_id, IsuDevMediaDirectory\Settings\PREFS_META );
	update_user_meta( $imd_user_id, IsuDevMediaDirectory\Settings\USER_NESTED_META, '0' );
	$imd_is( 'legacy nested preference is read', IsuDevMediaDirectory\Settings\prefs_for_user()['nested'], false );

	$imd_pref = $imd_rest( 'POST', '/preferences', array( 'reveal' => false ) );
	$imd_is( 'saving one preference keeps the others', array( $imd_pref['reveal'], $imd_pref['nested'], $imd_pref['tiles'] ), array( false, false, IsuDevMediaDirectory\Settings\get()['tiles'] ) );

	$imd_pref = $imd_rest( 'POST', '/preferences', array( 'perPostType' => true ) );
	$imd_is( 'perPostType maps to per_post_type', IsuDevMediaDirectory\Settings\prefs_for_user()['per_post_type'], true );
} finally {
	'' === $imd_saved_pref ? delete_user_meta( $imd_user_id, IsuDevMediaDirectory\Settings\PREFS_META ) : update_user_meta( $imd_user_id, IsuDevMediaDirectory\Settings\PREFS_META, $imd_saved_pref );
	'' === $imd_saved_old ? delete_user_meta( $imd_user_id, IsuDevMediaDirectory\Settings\USER_NESTED_META ) : update_user_meta( $imd_user_id, IsuDevMediaDirectory\Settings\USER_NESTED_META, $imd_saved_old );
}

/*
 * Permissions: a throwaway author against the REST routes.
 */
require_once ABSPATH . 'wp-admin/includes/user.php';

$imd_saved_matrix = get_option( Permissions\OPTION, null );
$imd_admin_id     = get_current_user_id();
$imd_author       = wp_insert_user(
	array(
		'user_login' => 'imd-smoke-' . wp_generate_password( 6, false ),
		'user_pass'  => wp_generate_password(),
		'role'       => 'author',
	)
);
$imd_perm_folders = array();

$imd_set_author = static function ( array $caps ) {
	$matrix           = Permissions\matrix();
	$matrix['author'] = array_merge( array_fill_keys( array_keys( Permissions\actions() ), false ), $caps );
	update_option( Permissions\OPTION, Permissions\sanitize( $matrix ) );
};

try {
	wp_set_current_user( (int) $imd_author );

	$imd_set_author( array() );
	$imd_is( 'no "see" → tree refused', rest_do_request( new WP_REST_Request( 'GET', '/isudev-media-directory/v1/folders' ) )->get_status(), 403 );

	$imd_set_author( array( Permissions\VIEW => true ) );
	$imd_is( 'see only → tree allowed', rest_do_request( new WP_REST_Request( 'GET', '/isudev-media-directory/v1/folders' ) )->get_status(), 200 );
	$imd_create = new WP_REST_Request( 'POST', '/isudev-media-directory/v1/folders' );
	$imd_create->set_param( 'name', 'Smoke perm' );
	$imd_is( 'see only → create refused', rest_do_request( $imd_create )->get_status(), 403 );

	$imd_set_author( array( Permissions\MANAGE => true ) );
	$imd_res = rest_do_request( $imd_create );
	$imd_is( 'manage → create allowed', $imd_res->get_status(), 201 );
	$imd_parent         = (int) ( $imd_res->get_data()['id'] ?? 0 );
	$imd_perm_folders[] = $imd_parent;
	$imd_child          = Folders\create( 'Smoke perm child', $imd_parent, 'smoke' );

	$imd_is( 'manage, no delete → non-empty folder refused', rest_do_request( new WP_REST_Request( 'DELETE', '/isudev-media-directory/v1/folders/' . $imd_parent ) )->get_status(), 403 );
	$imd_is( 'manage, no delete → empty folder allowed', rest_do_request( new WP_REST_Request( 'DELETE', '/isudev-media-directory/v1/folders/' . $imd_child ) )->get_status(), 200 );

	$imd_set_author( array( Permissions\DELETE => true ) );
	$imd_is( 'delete → non-empty-capable delete allowed', rest_do_request( new WP_REST_Request( 'DELETE', '/isudev-media-directory/v1/folders/' . $imd_parent ) )->get_status(), 200 );

	$imd_is( 'administrator always has every capability', user_can( $imd_admin_id, Permissions\DELETE ), true );
} finally {
	wp_set_current_user( $imd_admin_id );

	foreach ( $imd_perm_folders as $imd_id ) {
		if ( Folders\exists( $imd_id ) ) {
			Folders\delete( $imd_id );
		}
	}

	if ( null === $imd_saved_matrix ) {
		delete_option( Permissions\OPTION );
	} else {
		update_option( Permissions\OPTION, $imd_saved_matrix );
	}

	if ( $imd_author && ! is_wp_error( $imd_author ) ) {
		wp_delete_user( (int) $imd_author );
	}
}

/*
 * Import: a taxonomy source and a table source, both fake, so a real plugin's
 * data on the site is never touched.
 */
global $wpdb;

// phpcs:disable WordPress.DB -- Fake source tables for the test, created and dropped here.

$imd_tax_source = new Import\Taxonomy_Source( 'imd-smoke-tax', 'Smoke tax', 'imd_smoke_tax', array( '/^$/' ), array(), 'imd_smoke_color' );
$imd_tab_source = new Import\Table_Source(
	array(
		'slug'          => 'imd-smoke-table',
		'name'          => 'Smoke table',
		'patterns'      => array( '/^$/' ),
		'folders'       => 'imd_smoke_folders',
		'folder_cols'   => array( 'id', 'name', 'parent' ),
		'items'         => 'imd_smoke_items',
		'item_cols'     => array( 'folder_id', 'attachment_id' ),
		'drop'          => array( 'imd_smoke_folders', 'imd_smoke_items' ),
		'colors_option' => 'imd_smoke_colors',
	)
);
$imd_original   = Folders\of( $imd_files );
$imd_existing   = Folders\create( 'Smoke Import A', 0, '' );

try {
	register_taxonomy( 'imd_smoke_tax', 'attachment', array( 'hierarchical' => true ) );
	$imd_term_a = wp_insert_term( 'Smoke Import A', 'imd_smoke_tax' );
	$imd_term_b = wp_insert_term( 'Smoke Import B', 'imd_smoke_tax', array( 'parent' => $imd_term_a['term_id'] ) );
	wp_set_object_terms( $imd_f1, array( (int) $imd_term_b['term_id'] ), 'imd_smoke_tax' );
	add_term_meta( (int) $imd_term_b['term_id'], 'imd_smoke_color', '#3FA35B' );

	$imd_preview = Import\preview( $imd_tax_source );
	$imd_is( 'taxonomy preview counts', array( $imd_preview['folders'], $imd_preview['files'], $imd_preview['matches'] ), array( 2, 1, 1 ) );

	$imd_run = Import\run( $imd_tax_source, Import\MODE_MERGE );
	$imd_is( 'merge reuses same-named root, creates child', array( $imd_run['created'], $imd_run['reused'], $imd_run['files'] ), array( 1, 1, 1 ) );
	$imd_map = Import\imported_map( $imd_tax_source );
	$imd_is( 'merged root is the existing folder', $imd_map[ (string) $imd_term_a['term_id'] ] ?? 0, $imd_existing );
	$imd_is( 'file filed into imported child', in_array( $imd_map[ (string) $imd_term_b['term_id'] ] ?? -1, Folders\of( array( $imd_f1 ) )[ $imd_f1 ], true ), true );
	$imd_is( 'import brings the folder color', Folders\all()[ $imd_map[ (string) $imd_term_b['term_id'] ] ?? 0 ]['color'] ?? '', '#3fa35b' );
	$imd_is( 'second run adds nothing', Import\run( $imd_tax_source, Import\MODE_MERGE )['created'], 0 );

	Import\cleanup( $imd_tax_source );
	$imd_is( 'taxonomy cleanup removes terms', Import\preview( $imd_tax_source )['folders'], 0 );

	$imd_charset = $wpdb->get_charset_collate();
	$wpdb->query( "CREATE TABLE {$wpdb->prefix}imd_smoke_folders (id INT, name VARCHAR(50), parent INT) {$imd_charset}" );
	$wpdb->query( "CREATE TABLE {$wpdb->prefix}imd_smoke_items (folder_id INT, attachment_id BIGINT) {$imd_charset}" );
	$wpdb->query( "INSERT INTO {$wpdb->prefix}imd_smoke_folders VALUES (5, 'Smoke T1', -1), (6, 'Smoke T2', 5)" );
	$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}imd_smoke_items VALUES (6, %d), (6, %d)", $imd_f2, $imd_f3 ) );

	update_option( 'imd_smoke_colors', array( 6 => '#D6559B' ), false );

	$imd_run = Import\run( $imd_tab_source, Import\MODE_SEPARATE );
	$imd_is( 'separate creates root + 2 folders, files 2', array( $imd_run['created'], $imd_run['files'] ), array( 3, 2 ) );
	$imd_map = Import\imported_map( $imd_tab_source );
	$imd_is( 'table import brings the folder color', Folders\all()[ $imd_map['6'] ]['color'] ?? '', '#d6559b' );
	$imd_is( 'separate root holds the tree', Folders\all()[ $imd_map['5'] ]['parent'], $imd_map['root'] );

	Import\cleanup( $imd_tab_source );
	$imd_is( 'table cleanup drops tables', Import\preview( $imd_tab_source )['folders'], 0 );
	$imd_is( 'table cleanup deletes the color option', get_option( 'imd_smoke_colors', 'gone' ), 'gone' );
} finally {
	foreach ( array_merge( array_values( Import\imported_map( $imd_tax_source ) ), array_values( Import\imported_map( $imd_tab_source ) ), array( $imd_existing ) ) as $imd_id ) {
		if ( Folders\exists( $imd_id ) ) {
			Folders\delete( $imd_id );
		}
	}

	Import\cleanup( $imd_tax_source );
	$imd_tab_source->cleanup();
	delete_option( 'imd_smoke_colors' );
	Folders\set( $imd_original );
}

if ( $imd_failed ) {
	WP_CLI::error( sprintf( "%d passed, %d failed:\n  %s", $imd_passed, count( $imd_failed ), implode( "\n  ", $imd_failed ) ) );
}

WP_CLI::success( sprintf( '%d checks passed.', $imd_passed ) );
