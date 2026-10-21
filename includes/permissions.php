<?php
/**
 * Who may do what with folders: a role × action matrix.
 *
 * The matrix is stored in one option and granted as real capabilities through
 * `user_has_cap`, so roles are never modified (uninstall stays clean) and role
 * editor plugins can still grant or deny the capabilities per user.
 *
 * @package IsuDevMediaDirectory
 */

declare( strict_types = 1 );

namespace IsuDevMediaDirectory\Permissions;

defined( 'ABSPATH' ) || exit;

const OPTION = 'isudev_media_directory_permissions';

const VIEW     = 'imd_view_folders';
const ORGANIZE = 'imd_organize_files';
const MANAGE   = 'imd_manage_folders';
const DELETE   = 'imd_delete_folders';

/**
 * Hook the capability filter.
 *
 * @return void
 */
function boot(): void {
	add_filter( 'user_has_cap', __NAMESPACE__ . '\\grant', 10, 4 );
}

/**
 * Actions in display order, with labels.
 *
 * @return array<string, array{label:string, help:string}>
 */
function actions(): array {
	return array(
		VIEW     => array(
			'label' => __( 'See folders', 'isudev-media-directory' ),
			'help'  => __( 'Folder tree, filtering by folder', 'isudev-media-directory' ),
		),
		ORGANIZE => array(
			'label' => __( 'Organize files', 'isudev-media-directory' ),
			'help'  => __( 'Move files between folders, upload into a folder', 'isudev-media-directory' ),
		),
		MANAGE   => array(
			'label' => __( 'Create and rename', 'isudev-media-directory' ),
			'help'  => __( 'Create, rename and move folders', 'isudev-media-directory' ),
		),
		DELETE   => array(
			'label' => __( 'Delete folders', 'isudev-media-directory' ),
			'help'  => __( 'Delete folders that hold files or subfolders', 'isudev-media-directory' ),
		),
	);
}

/**
 * Roles that can use the Media Library at all (have `upload_files`).
 *
 * @return array<string, string> Role slug => translated name.
 */
function roles(): array {
	$roles = array();

	foreach ( wp_roles()->roles as $slug => $role ) {
		if ( ! empty( $role['capabilities']['upload_files'] ) ) {
			$roles[ $slug ] = translate_user_role( $role['name'] );
		}
	}

	return $roles;
}

/**
 * Starting matrix for a role nobody configured yet.
 *
 * @param string $role Role slug.
 * @return array<string, bool>
 */
function role_defaults( string $role ): array {
	$all = in_array( $role, array( 'administrator', 'editor' ), true );

	return array(
		VIEW     => true,
		ORGANIZE => true,
		MANAGE   => $all || 'author' === $role,
		DELETE   => $all,
	);
}

/**
 * The matrix for every role with `upload_files`. Administrators always get all.
 *
 * @return array<string, array<string, bool>>
 */
function matrix(): array {
	static $cache = null, $cached_for = null;

	$saved = get_option( OPTION, array() );

	// user_has_cap runs on every capability check; rebuild only when the option changes.
	if ( null !== $cache && $cached_for === $saved ) {
		return $cache;
	}

	$cached_for = $saved;
	$saved      = is_array( $saved ) ? $saved : array();
	$matrix     = array();

	foreach ( array_keys( roles() ) as $role ) {
		$row = role_defaults( $role );

		if ( isset( $saved[ $role ] ) && is_array( $saved[ $role ] ) ) {
			foreach ( array_keys( $row ) as $action ) {
				$row[ $action ] = ! empty( $saved[ $role ][ $action ] );
			}
		}

		$matrix[ $role ] = 'administrator' === $role ? array_fill_keys( array_keys( $row ), true ) : $row;
	}

	$cache = $matrix;

	return $matrix;
}

/**
 * Clean a submitted matrix. Organizing, managing or deleting implies seeing.
 *
 * @param mixed $value Raw `[role][action] => '1'`.
 * @return array<string, array<string, bool>>
 */
function sanitize( $value ): array {
	$value = is_array( $value ) ? $value : array();
	$clean = array();

	foreach ( array_keys( roles() ) as $role ) {
		$row = array();

		foreach ( array_keys( actions() ) as $action ) {
			$row[ $action ] = ! empty( $value[ $role ][ $action ] );
		}

		if ( $row[ ORGANIZE ] || $row[ MANAGE ] || $row[ DELETE ] ) {
			$row[ VIEW ] = true;
		}

		$clean[ $role ] = $row;
	}

	return $clean;
}

/**
 * Grant our capabilities from the matrix.
 *
 * @param array<string, bool> $allcaps All capabilities of the user.
 * @param string[]            $caps    Required primitive capabilities.
 * @param array<int, mixed>   $args    Requested capability and arguments.
 * @param \WP_User            $user    User.
 * @return array<string, bool>
 */
function grant( array $allcaps, array $caps, array $args, $user ): array {
	$ours = array_intersect( $caps, array_keys( actions() ) );

	if ( ! $ours || empty( $allcaps['upload_files'] ) || ! $user instanceof \WP_User ) {
		return $allcaps;
	}

	$matrix = matrix();

	foreach ( $ours as $cap ) {
		// An explicit grant or denial (role editor plugins) wins over the matrix.
		if ( array_key_exists( $cap, $allcaps ) ) {
			continue;
		}

		foreach ( $user->roles as $role ) {
			if ( ! empty( $matrix[ $role ][ $cap ] ) ) {
				$allcaps[ $cap ] = true;
				break;
			}
		}
	}

	return $allcaps;
}

/**
 * Whether the current user may do an action.
 *
 * @param string $cap One of the action constants.
 * @return bool
 */
function can( string $cap ): bool {
	return current_user_can( $cap );
}

/**
 * Deleting an empty folder (no files, no subfolders) only needs MANAGE: it can
 * be recreated in a second, and it is how a folder you just made is undone.
 *
 * @param array{total:int, has_children:bool} $folder Folder stats.
 * @return bool
 */
function can_delete( array $folder ): bool {
	if ( can( DELETE ) ) {
		return true;
	}

	return can( MANAGE ) && 0 === $folder['total'] && ! $folder['has_children'];
}

/**
 * What the UI needs to know about the current user.
 *
 * @return array<string, bool>
 */
function for_js(): array {
	return array(
		'view'        => can( VIEW ),
		'organize'    => can( ORGANIZE ),
		'manage'      => can( MANAGE ),
		'delete'      => can( DELETE ),
		'deleteFiles' => current_user_can( 'delete_posts' ),
	);
}
