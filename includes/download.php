<?php
/**
 * Download a folder as a ZIP: its files, with subfolders as directories.
 *
 * @package IsuDevMediaDirectory
 */

declare( strict_types = 1 );

namespace IsuDevMediaDirectory\Download;

use IsuDevMediaDirectory\Folders;
use IsuDevMediaDirectory\Permissions;
use WP_Error;
use ZipArchive;

defined( 'ABSPATH' ) || exit;

const ACTION = 'isudev_media_directory_download';

/**
 * Hook the download handler.
 *
 * @return void
 */
function boot(): void {
	add_action( 'admin_post_isudev_media_directory_download', __NAMESPACE__ . '\\handle' );
}

/**
 * Whether ZIP downloads can work on this server.
 *
 * @return bool
 */
function available(): bool {
	return class_exists( ZipArchive::class );
}

/**
 * URL that downloads a folder; the client appends the folder ID.
 *
 * @return string
 */
function base_url(): string {
	return add_query_arg(
		array(
			'action'   => ACTION,
			'_wpnonce' => wp_create_nonce( ACTION ),
		),
		admin_url( 'admin-post.php' )
	);
}

/**
 * A name safe for a ZIP entry or a download file name.
 *
 * @param string $name Folder or file name.
 * @return string
 */
function clean_name( string $name ): string {
	$name = trim( preg_replace( '/[\\\\\/:*?"<>|\x00-\x1f]+/u', '-', $name ), " .-\t" );

	return '' === $name ? __( 'Folder', 'isudev-media-directory' ) : $name;
}

/**
 * The original file of an attachment (not the "-scaled" copy core makes).
 *
 * @param int $id Attachment ID.
 * @return string Path, or '' when it is missing.
 */
function file_of( int $id ): string {
	$path = wp_attachment_is_image( $id ) ? wp_get_original_image_path( $id ) : get_attached_file( $id );

	return $path && is_readable( $path ) ? (string) $path : '';
}

/**
 * Build the ZIP for a folder in a temporary file.
 *
 * A file filed in two folders of the subtree is in both directories, as the
 * user sees it in the tree. Files the user may not read are skipped.
 *
 * @param int $folder Folder ID.
 * @return string|WP_Error Temporary file path; the caller deletes it.
 */
function build( int $folder ) {
	if ( ! Folders\exists( $folder ) ) {
		return new WP_Error( 'imd_not_found', __( 'Folder not found.', 'isudev-media-directory' ) );
	}

	if ( ! available() ) {
		return new WP_Error( 'imd_no_zip', __( 'This server cannot create ZIP files (the PHP zip extension is missing).', 'isudev-media-directory' ) );
	}

	$all   = Folders\all();
	$paths = array();

	foreach ( Folders\subtree( $folder ) as $id ) {
		$parts = array();

		for ( $current = $id, $guard = 0; $current !== $folder && isset( $all[ $current ] ) && $guard < 100; $current = $all[ $current ]['parent'], $guard++ ) {
			array_unshift( $parts, clean_name( $all[ $current ]['name'] ) );
		}

		$paths[ $id ] = $parts ? implode( '/', $parts ) . '/' : '';
	}

	$file = wp_tempnam( 'imd-folder.zip' );
	$zip  = new ZipArchive();

	if ( true !== $zip->open( $file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		wp_delete_file( $file );
		return new WP_Error( 'imd_zip_failed', __( 'The ZIP file could not be created.', 'isudev-media-directory' ) );
	}

	$used  = array();
	$added = 0;

	foreach ( Folders\of( Folders\attachments_in( array_keys( $paths ) ) ) as $attachment => $folders ) {
		if ( 'trash' === get_post_status( $attachment ) || ! current_user_can( 'read_post', $attachment ) ) {
			continue;
		}

		$source = file_of( $attachment );

		if ( '' === $source ) {
			continue;
		}

		foreach ( array_intersect( $folders, array_keys( $paths ) ) as $in ) {
			$entry = unique_entry( $paths[ $in ], clean_name( wp_basename( $source ) ), $used );

			$zip->addFile( $source, $entry );
			// Media is already compressed; storing is much faster and as small.
			if ( method_exists( $zip, 'setCompressionName' ) ) {
				$zip->setCompressionName( $entry, ZipArchive::CM_STORE );
			}
			++$added;
		}
	}

	foreach ( $paths as $path ) {
		if ( '' !== $path ) {
			$zip->addEmptyDir( rtrim( $path, '/' ) );
		}
	}

	if ( ! $zip->close() || ! $added ) {
		wp_delete_file( $file );
		return new WP_Error( 'imd_empty', __( 'There are no files in this folder to download.', 'isudev-media-directory' ) );
	}

	return $file;
}

/**
 * An entry name not used yet in its directory: "photo.jpg", "photo (2).jpg"…
 *
 * @param string              $dir  Directory with a trailing slash, or ''.
 * @param string              $name File name.
 * @param array<string, true> $used Names taken so far (lowercase), updated.
 * @return string
 */
function unique_entry( string $dir, string $name, array &$used ): string {
	$ext   = pathinfo( $name, PATHINFO_EXTENSION );
	$base  = '' === $ext ? $name : substr( $name, 0, -strlen( $ext ) - 1 );
	$entry = $dir . $name;

	$n = 1;

	while ( isset( $used[ strtolower( $entry ) ] ) ) {
		++$n;
		$entry = $dir . $base . " ({$n})" . ( '' === $ext ? '' : ".{$ext}" );
	}

	$used[ strtolower( $entry ) ] = true;

	return $entry;
}

/**
 * Stream the ZIP of `?folder=` to the browser.
 *
 * @return void
 */
function handle(): void {
	check_admin_referer( ACTION );

	if ( ! current_user_can( 'upload_files' ) || ! Permissions\can( Permissions\VIEW ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'isudev-media-directory' ), 403 );
	}

	$folder = isset( $_GET['folder'] ) ? absint( $_GET['folder'] ) : 0;
	$file   = build( $folder );

	if ( is_wp_error( $file ) ) {
		wp_die( esc_html( $file->get_error_message() ), '', array( 'back_link' => true ) );
	}

	$name = clean_name( Folders\all()[ $folder ]['name'] ) . '.zip';

	// Big folders: never stop halfway through the stream.
	if ( function_exists( 'set_time_limit' ) ) {
		set_time_limit( 0 );
	}

	while ( ob_get_level() ) {
		ob_end_clean();
	}

	nocache_headers();
	header( 'Content-Type: application/zip' );
	header( 'Content-Length: ' . filesize( $file ) );
	header( sprintf( 'Content-Disposition: attachment; filename="%s"; filename*=UTF-8\'\'%s', remove_accents( str_replace( '"', '', $name ) ), rawurlencode( $name ) ) );

	readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming a large temp file.
	wp_delete_file( $file );
	exit;
}
