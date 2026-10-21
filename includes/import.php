<?php
/**
 * Import engine: copy folders and file assignments from other media folder
 * plugins (see includes/import/), preview, clean up their data afterwards and
 * warn while one of them runs next to us.
 *
 * Every imported folder remembers `<source>:<source id>` in `import_ref`, so an
 * import can be repeated: it only adds what is missing.
 *
 * @package IsuDevMediaDirectory
 */

declare( strict_types = 1 );

namespace IsuDevMediaDirectory\Import;

use IsuDevMediaDirectory\Folders;
use IsuDevMediaDirectory\Permissions;
use IsuDevMediaDirectory\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

const MODE_MERGE    = 'merge';
const MODE_SEPARATE = 'separate';

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Our own tables and another plugin's options.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names only; values are prepared.

/**
 * Hook the conflict notice.
 *
 * @return void
 */
function boot(): void {
	add_action( 'admin_notices', __NAMESPACE__ . '\\conflict_notice' );
}

/**
 * Every source, keyed by slug.
 *
 * @return array<string, Source>
 */
function sources(): array {
	static $sources = null;

	if ( null !== $sources ) {
		return $sources;
	}

	$list = array(
		new Taxonomy_Source( 'happyfiles', 'HappyFiles', 'happyfiles_category', array( '/^HappyFiles\b/i' ) ),
		new Table_Source(
			array(
				'slug'        => 'filebird',
				'name'        => 'FileBird',
				'patterns'    => array( '/^FileBird\b/i' ),
				'folders'     => 'fbv',
				'folder_cols' => array( 'id', 'name', 'parent' ),
				'items'       => 'fbv_attachment_folder',
				'item_cols'   => array( 'folder_id', 'attachment_id' ),
				'drop'        => array( 'fbv', 'fbv_attachment_folder' ),
			)
		),
		new Table_Source(
			array(
				'slug'        => 'real-media-library',
				'name'        => 'Real Media Library',
				'patterns'    => array( '/Real Media Library/i' ),
				'folders'     => 'realmedialibrary',
				'folder_cols' => array( 'id', 'name', 'parent' ),
				'items'       => 'realmedialibrary_posts',
				'item_cols'   => array( 'fid', 'attachment' ),
				'drop'        => array( 'realmedialibrary', 'realmedialibrary_posts', 'realmedialibrary_meta', 'realmedialibrary_tmp' ),
			)
		),
		new Taxonomy_Source( 'folders', 'Folders (Premio)', 'media_folder', array( '/^Folders$/i' ) ),
		new Taxonomy_Source( 'enhanced-media-library', 'Enhanced Media Library', 'media_category', array( '/^Enhanced Media Library\b/i' ) ),
		new Taxonomy_Source( 'wp-media-folder', 'WP Media Folder', 'wpmf-category', array( '/^WP Media Folder\b/i' ), array( 'wp-media-folder-root' ) ),
		new Taxonomy_Source( 'wicked-folders', 'Wicked Folders', 'wf_attachment_folders', array( '/^Wicked Folders\b/i' ) ),
	);

	/**
	 * Filters the plugins folders can be imported from.
	 *
	 * @param Source[] $list Sources; add an instance of a Source subclass.
	 */
	$list = apply_filters( 'isudev_media_directory/import_sources', $list );

	$sources = array();

	foreach ( $list as $source ) {
		if ( $source instanceof Source ) {
			$sources[ $source->slug() ] = $source;
		}
	}

	return $sources;
}

/**
 * One source by slug.
 *
 * @param string $slug Slug.
 * @return Source|null
 */
function source( string $slug ): ?Source {
	return sources()[ $slug ] ?? null;
}

/**
 * Our folders already imported from a source, keyed by source folder ID.
 *
 * @param Source $source Source.
 * @return array<string, int>
 */
function imported_map( Source $source ): array {
	global $wpdb;

	$table  = Schema\folders_table();
	$prefix = $source->slug() . ':';
	$rows   = $wpdb->get_results(
		$wpdb->prepare( "SELECT id, import_ref FROM {$table} WHERE import_ref LIKE %s", $wpdb->esc_like( $prefix ) . '%' ),
		ARRAY_A
	);
	$map    = array();

	foreach ( (array) $rows as $row ) {
		$map[ substr( $row['import_ref'], strlen( $prefix ) ) ] = (int) $row['id'];
	}

	return $map;
}

/**
 * Key for matching folders by name under the same parent.
 *
 * @param int    $parent_id Parent folder ID.
 * @param string $name      Folder name.
 * @return string
 */
function name_key( int $parent_id, string $name ): string {
	return $parent_id . '/' . mb_strtolower( trim( $name ) );
}

/**
 * Our existing folders as name keys => ID.
 *
 * @return array<string, int>
 */
function existing_by_name(): array {
	$index = array();

	foreach ( Folders\all() as $folder ) {
		$index[ name_key( $folder['parent'], $folder['name'] ) ] = $folder['id'];
	}

	return $index;
}

/**
 * What an import would do, without doing it.
 *
 * @param Source $source Source.
 * @return array{folders:int, files:int, imported:int, matches:int, active:bool}
 */
function preview( Source $source ): array {
	$folders  = $source->folders();
	$imported = array_intersect_key( imported_map( $source ), $folders );
	$existing = existing_by_name();
	$matches  = 0;

	// Name matches only count at the root: deeper ones depend on how the parents resolve.
	foreach ( $folders as $id => $folder ) {
		if ( ! isset( $imported[ $id ] ) && '' === $folder['parent'] && isset( $existing[ name_key( 0, $folder['name'] ) ] ) ) {
			++$matches;
		}
	}

	return array(
		'folders'  => count( $folders ),
		'files'    => count( array_unique( array_column( $source->items(), 1 ) ) ),
		'imported' => count( $imported ),
		'matches'  => $matches,
		'active'   => null !== $source->active_plugin(),
	);
}

/**
 * Copy a source's folders and file assignments.
 *
 * Merge mode reuses a folder with the same name under the same parent; separate
 * mode puts everything under one new folder named after the source. Folders
 * imported before are reused in both modes.
 *
 * @param Source $source Source.
 * @param string $mode   MODE_MERGE or MODE_SEPARATE.
 * @return array{created:int, reused:int, files:int}
 */
function run( Source $source, string $mode = MODE_MERGE ): array {
	$folders  = $source->folders();
	$map      = imported_map( $source );
	$existing = existing_by_name();
	$created  = 0;
	$reused   = 0;
	$root     = 0;

	if ( MODE_SEPARATE === $mode && $folders ) {
		$root = $map['root'] ?? 0;

		if ( ! $root || ! Folders\exists( $root ) ) {
			$root = Folders\create( $source->name(), 0, $source->slug() . ':root' );
			++$created;
		}
	}

	// Parents before children; a pass that resolves nothing means a parent loop.
	$pending = $folders;

	while ( $pending ) {
		$progress = false;

		foreach ( $pending as $id => $folder ) {
			$parent = $folder['parent'];

			if ( '' !== $parent && isset( $folders[ $parent ] ) && ! isset( $map[ $parent ] ) ) {
				continue;
			}

			$target_parent = '' === $parent || ! isset( $map[ $parent ] ) ? $root : $map[ $parent ];
			$id            = (string) $id;

			if ( isset( $map[ $id ] ) && Folders\exists( $map[ $id ] ) ) {
				++$reused;
			} else {
				$name  = trim( sanitize_text_field( $folder['name'] ) );
				$name  = '' === $name ? __( 'Untitled folder', 'isudev-media-directory' ) : mb_substr( $name, 0, 200 );
				$match = MODE_MERGE === $mode ? ( $existing[ name_key( $target_parent, $name ) ] ?? 0 ) : 0;

				if ( $match ) {
					$map[ $id ] = $match;
					Folders\set_ref( $match, $source->slug() . ':' . $id );
					++$reused;
				} else {
					$map[ $id ]                                    = Folders\create( $name, $target_parent, $source->slug() . ':' . $id );
					$existing[ name_key( $target_parent, $name ) ] = $map[ $id ];
					++$created;
				}
			}

			unset( $pending[ $id ] );
			$progress = true;
		}

		if ( ! $progress ) {
			foreach ( array_keys( $pending ) as $id ) {
				$folders[ $id ]['parent'] = '';
			}
			$pending = array_intersect_key( $folders, $pending );
		}
	}

	$pairs = array();

	foreach ( $source->items() as $item ) {
		if ( isset( $map[ $item[0] ] ) ) {
			$pairs[] = array( $map[ $item[0] ], $item[1] );
		}
	}

	Folders\insert_items( $pairs );

	return array(
		'created' => $created,
		'reused'  => $reused,
		'files'   => count( array_unique( array_column( $pairs, 1 ) ) ),
	);
}

/**
 * Bring HappyFiles' folder access settings over to the permission matrix.
 *
 * HappyFiles levels: none, view, upload, full. Per-user overrides have no
 * counterpart yet; they are counted so the operator can be told.
 *
 * @return array{roles:int, users:int}
 */
function happyfiles_permissions(): array {
	global $wpdb;

	$levels = array(
		'none'   => array(),
		'view'   => array( Permissions\VIEW ),
		'upload' => array( Permissions\VIEW, Permissions\ORGANIZE ),
		'full'   => array( Permissions\VIEW, Permissions\ORGANIZE, Permissions\MANAGE, Permissions\DELETE ),
	);

	$access = get_option( 'happyfiles_folder_access', array() );
	$matrix = Permissions\matrix();
	$roles  = 0;

	foreach ( is_array( $access ) ? $access : array() as $role => $level ) {
		if ( ! isset( $matrix[ $role ], $levels[ $level ] ) || 'administrator' === $role ) {
			continue;
		}

		foreach ( array_keys( $matrix[ $role ] ) as $cap ) {
			$matrix[ $role ][ $cap ] = in_array( $cap, $levels[ $level ], true );
		}

		++$roles;
	}

	update_option( Permissions\OPTION, Permissions\sanitize( $matrix ) );

	$users = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value <> ''", 'happyfiles_folder_access' )
	);

	return array(
		'roles' => $roles,
		'users' => $users,
	);
}

/**
 * Delete a source's folder data. Refused while its plugin is active.
 *
 * @param Source $source Source.
 * @return true|WP_Error
 */
function cleanup( Source $source ) {
	if ( null !== $source->active_plugin() ) {
		return new WP_Error(
			'imd_source_active',
			/* translators: %s: plugin name. */
			sprintf( __( 'Deactivate %s before deleting its folder data.', 'isudev-media-directory' ), $source->name() )
		);
	}

	$source->cleanup();

	return true;
}

/**
 * Warn while another folder plugin runs next to this one.
 *
 * @return void
 */
function conflict_notice(): void {
	$screen = get_current_screen();

	if ( ! current_user_can( 'manage_options' ) || ! $screen || ! in_array( $screen->id, array( 'upload', 'plugins', 'media_page_isudev-media-directory' ), true ) ) {
		return;
	}

	foreach ( sources() as $source ) {
		$file = $source->active_plugin();

		if ( null === $file ) {
			continue;
		}

		$import     = admin_url( 'upload.php?page=isudev-media-directory&tab=import' );
		$deactivate = wp_nonce_url( admin_url( 'plugins.php?action=deactivate&plugin=' . rawurlencode( $file ) ), 'deactivate-plugin_' . $file );

		printf(
			'<div class="notice notice-warning"><p>%s</p><p><a class="button button-primary" href="%s">%s</a> <a class="button" href="%s">%s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %s: plugin name. */
					__( '%s is active next to Media Directory, so the Media Library shows two folder sidebars. Import its folders, then deactivate it.', 'isudev-media-directory' ),
					$source->name()
				)
			),
			esc_url( $import ),
			esc_html__( 'Import folders', 'isudev-media-directory' ),
			esc_url( $deactivate ),
			esc_html(
				sprintf(
					/* translators: %s: plugin name. */
					__( 'Deactivate %s', 'isudev-media-directory' ),
					$source->name()
				)
			)
		);
	}
}
