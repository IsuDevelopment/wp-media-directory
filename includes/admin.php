<?php
/**
 * Admin glue: scripts, attachment data and upload filing.
 *
 * @package IsuDevMediaDirectory
 */

declare( strict_types = 1 );

namespace IsuDevMediaDirectory\Admin;

use IsuDevMediaDirectory\Download;
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
	add_action( 'pre-upload-ui', __NAMESPACE__ . '\\upload_folder_field' );
	add_action( 'delete_attachment', __NAMESPACE__ . '\\forget_attachment' );
	add_filter( 'media_library_infinite_scrolling', __NAMESPACE__ . '\\infinite_scrolling' );
}

/**
 * Infinite scrolling in the grid and modals on WordPress 7.0, where core still
 * defaults to a "Load more" button. From 7.1 core defaults to infinite
 * scrolling with a per-user opt-out in the profile, so we leave it alone.
 *
 * @param bool $enabled Core's value.
 * @return bool
 */
function infinite_scrolling( $enabled ): bool {
	return version_compare( get_bloginfo( 'version' ), '7.1-alpha', '<' ) ? true : (bool) $enabled;
}

/**
 * The Media Library list view and Add Media File never call wp_enqueue_media(),
 * so load it there too.
 *
 * @param string $hook_suffix Admin page.
 * @return void
 */
function enqueue_library( string $hook_suffix ): void {
	if ( in_array( $hook_suffix, array( 'upload.php', 'media-new.php' ), true ) ) {
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
				'download'   => Download\available() ? Download\base_url() : '',
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
 * "Upload to folder" on Media → Add Media File (media-new.php).
 *
 * A plain field inside core's upload form, so the no-JS browser uploader
 * posts it too; `src/upload-page.js` stamps plupload uploads with it.
 *
 * @return void
 */
function upload_folder_field(): void {
	$screen = get_current_screen();

	if ( ! $screen || 'media' !== $screen->id || ! current_user_can( 'upload_files' ) || ! Permissions\can( Permissions\ORGANIZE ) || ! Folders\all() ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Preselects a field only.
	$selected = isset( $_GET[ Query\FOLDER_VAR ] ) ? absint( $_GET[ Query\FOLDER_VAR ] ) : 0;
	$children = array();

	foreach ( Folders\all() as $folder ) {
		$children[ $folder['parent'] ][] = $folder;
	}

	$options = function ( int $parent_id, int $depth ) use ( &$options, $children, $selected ): void {
		$level = $children[ $parent_id ] ?? array();
		usort( $level, fn( $a, $b ) => strnatcasecmp( $a['name'], $b['name'] ) );

		foreach ( $level as $folder ) {
			printf(
				'<option value="%1$d"%2$s>%3$s%4$s</option>',
				(int) $folder['id'],
				selected( $selected, $folder['id'], false ),
				str_repeat( '&nbsp;&nbsp;&nbsp;', $depth ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed markup.
				esc_html( $folder['name'] )
			);
			$options( (int) $folder['id'], $depth + 1 );
		}
	};
	?>
	<p class="imd-upload-folder">
		<label for="imd-upload-folder"><?php esc_html_e( 'Upload to folder', 'isudev-media-directory' ); ?></label>
		<select id="imd-upload-folder" name="<?php echo esc_attr( Query\FOLDER_VAR ); ?>">
			<option value="0"><?php esc_html_e( 'Not in a folder', 'isudev-media-directory' ); ?></option>
			<?php $options( 0, 0 ); ?>
		</select>
	</p>
	<?php
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
