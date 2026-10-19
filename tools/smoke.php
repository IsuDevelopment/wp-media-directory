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

use IsuDevMediaDirectory\Folders;

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

if ( $imd_failed ) {
	WP_CLI::error( sprintf( "%d passed, %d failed:\n  %s", $imd_passed, count( $imd_failed ), implode( "\n  ", $imd_failed ) ) );
}

WP_CLI::success( sprintf( '%d checks passed.', $imd_passed ) );
