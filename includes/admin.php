<?php
/**
 * Admin glue: scripts, attachment data and upload filing.
 *
 * @package IsuDevMediaDirectory
 */

declare( strict_types = 1 );

namespace IsuDevMediaDirectory\Admin;

use IsuDevMediaDirectory\Folders;
use IsuDevMediaDirectory\Permissions;
use IsuDevMediaDirectory\Query;
use IsuDevMediaDirectory\REST;
use IsuDevMediaDirectory\Settings;
use WP_Post;

use const IsuDevMediaDirectory\PATH;
use const IsuDevMediaDirectory\URL;

defined( 'ABSPATH' ) || exit;

const HANDLE = 'isudev-media-directory';

/**
 * Hook everything.
 *
 * @return void
 */
function boot(): void {
	add_action( 'wp_enqueue_media', __NAMESPACE__ . '\\enqueue' );
	add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_library' );
	add_filter( 'wp_prepare_attachment_for_js', __NAMESPACE__ . '\\attachment_json', 10, 2 );
	add_action( 'add_attachment', __NAMESPACE__ . '\\file_upload' );
	add_action( 'delete_attachment', __NAMESPACE__ . '\\forget_attachment' );
}

/**
 * The Media Library list view never calls wp_enqueue_media(), so load it there too.
 *
 * @param string $hook_suffix Admin page.
 * @return void
 */
function enqueue_library( string $hook_suffix ): void {
	if ( 'upload.php' === $hook_suffix ) {
		wp_enqueue_media();
		enqueue();
	}
}

/**
 * Load the sidebar wherever the media modal or library can open.
 *
 * @return void
 */
function enqueue(): void {
	static $done = false;

	if ( $done || ! current_user_can( 'upload_files' ) || ! Permissions\can( Permissions\VIEW ) ) {
		return;
	}

	$done  = true;
	$asset = include PATH . 'build/index.asset.php';

	wp_enqueue_script( HANDLE, URL . 'build/index.js', array_merge( $asset['dependencies'], array( 'media-views' ) ), $asset['version'], true );
	wp_enqueue_style( HANDLE, URL . 'build/style-index.css', array( 'wp-components' ), $asset['version'] );
	wp_set_script_translations( HANDLE, 'isudev-media-directory', PATH . 'languages' );

	global $pagenow;

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only view state.
	$is_library = is_admin() && 'upload.php' === $pagenow;
	$mode       = $is_library ? get_user_option( 'media_library_mode', get_current_user_id() ) : '';
	$mode       = $is_library && isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : $mode;
	$folder     = isset( $_GET[ Query\FOLDER_VAR ] ) ? Query\parse_folder( sanitize_key( wp_unslash( $_GET[ Query\FOLDER_VAR ] ) ) ) : null;
	// phpcs:enable

	wp_add_inline_script(
		HANDLE,
		'window.isudevMediaDirectory = ' . wp_json_encode(
			array(
				'namespace'  => REST\NAMESPACE_V1,
				'tree'       => Folders\tree(),
				'nested'     => Settings\nested_for_user(),
				'prefs'      => Settings\prefs_for_js( Settings\prefs_for_user() ),
				'can'        => Permissions\for_js(),
				'view'       => $is_library ? ( 'list' === $mode ? 'list' : 'grid' ) : 'modal',
				'folder'     => $folder,
				'folderVar'  => Query\FOLDER_VAR,
				'nestedVar'  => Query\NESTED_VAR,
				'settings'   => current_user_can( 'manage_options' ) ? admin_url( 'upload.php?page=' . Settings\PAGE ) : '',
				'libraryUrl' => admin_url( 'upload.php' ),
			)
		) . ';',
		'before'
	);
}

/**
 * Tell the media views which folders a file is in.
 *
 * @param array<string, mixed> $response   Attachment data.
 * @param WP_Post              $attachment Attachment.
 * @return array<string, mixed>
 */
function attachment_json( array $response, WP_Post $attachment ): array {
	$response['imdFolders'] = Query\folders_of( array( $attachment->ID ) )[ $attachment->ID ] ?? array();

	return $response;
}

/**
 * File a fresh upload in the folder it was dropped into.
 *
 * The folder is stamped on each file when it is queued (see uploader.js),
 * so switching folders while a batch uploads cannot misfile it.
 *
 * @param int $attachment_id New attachment.
 * @return void
 */
function file_upload( int $attachment_id ): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- async-upload.php verified its own nonce.
	$folder = isset( $_POST[ Query\FOLDER_VAR ] ) ? absint( $_POST[ Query\FOLDER_VAR ] ) : 0;

	if ( $folder && Folders\exists( $folder ) && current_user_can( 'upload_files' ) && Permissions\can( Permissions\ORGANIZE ) ) {
		Folders\insert_items( array( array( $folder, $attachment_id ) ) );
	}
}

/**
 * Drop a deleted file from its folders.
 *
 * @param int $attachment_id Attachment.
 * @return void
 */
function forget_attachment( int $attachment_id ): void {
	Folders\forget( $attachment_id );
}
