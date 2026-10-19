<?php
/**
 * Settings page (Media → Folder settings) and per-user preferences.
 *
 * @package IsuDevMediaDirectory
 */

declare( strict_types = 1 );

namespace IsuDevMediaDirectory\Settings;

use IsuDevMediaDirectory\Import;

defined( 'ABSPATH' ) || exit;

const OPTION           = 'isudev_media_directory_settings';
const USER_NESTED_META = 'isudev_media_directory_nested';
const PAGE             = 'isudev-media-directory';

/**
 * Hook the settings page.
 *
 * @return void
 */
function boot(): void {
	add_action( 'admin_menu', __NAMESPACE__ . '\\add_page' );
	add_action( 'admin_init', __NAMESPACE__ . '\\register' );
	add_action( 'admin_post_isudev_media_directory_import', __NAMESPACE__ . '\\handle_import' );
}

/**
 * Saved settings merged with defaults.
 *
 * @return array{nested: bool}
 */
function get(): array {
	$saved = get_option( OPTION, array() );

	return array(
		'nested' => isset( $saved['nested'] ) ? (bool) $saved['nested'] : true,
	);
}

/**
 * Whether the current user sees files from subfolders.
 *
 * The toggle in the sidebar is remembered per user; the setting is only the
 * starting point for users who never touched it.
 *
 * @return bool
 */
function nested_for_user(): bool {
	$meta = get_user_meta( get_current_user_id(), USER_NESTED_META, true );

	return '' === $meta ? get()['nested'] : '1' === $meta;
}

/**
 * Add the page under Media.
 *
 * @return void
 */
function add_page(): void {
	add_submenu_page(
		'upload.php',
		__( 'Folder settings', 'isudev-media-directory' ),
		__( 'Folder settings', 'isudev-media-directory' ),
		'manage_options',
		PAGE,
		__NAMESPACE__ . '\\render'
	);
}

/**
 * Register the option.
 *
 * @return void
 */
function register(): void {
	register_setting(
		PAGE,
		OPTION,
		array(
			'type'              => 'object',
			'sanitize_callback' => static function ( $value ) {
				return array( 'nested' => ! empty( $value['nested'] ) );
			},
		)
	);
}

/**
 * Render the page.
 *
 * @return void
 */
function render(): void {
	$settings = get();
	$source   = Import\happyfiles_summary();

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only notice after redirect.
	$imported = isset( $_GET['imd_imported'] ) ? absint( $_GET['imd_imported'] ) : null;
	$filed    = isset( $_GET['imd_filed'] ) ? absint( $_GET['imd_filed'] ) : 0;
	// phpcs:enable
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Folder settings', 'isudev-media-directory' ); ?></h1>

		<?php if ( null !== $imported ) : ?>
			<div class="notice notice-success is-dismissible">
				<p>
					<?php
					printf(
						/* translators: 1: number of folders, 2: number of files. */
						esc_html__( 'Imported %1$d folders and filed %2$d files.', 'isudev-media-directory' ),
						(int) $imported,
						(int) $filed
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<form method="post" action="options.php">
			<?php settings_fields( PAGE ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Subfolders', 'isudev-media-directory' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( OPTION ); ?>[nested]" value="1" <?php checked( $settings['nested'] ); ?> />
							<?php esc_html_e( 'Show files from subfolders in parent folders by default', 'isudev-media-directory' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Everyone can still switch this with the toggle above the folder tree. Their choice is remembered.', 'isudev-media-directory' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<h2><?php esc_html_e( 'Import from HappyFiles', 'isudev-media-directory' ); ?></h2>
		<?php if ( ! $source['folders'] ) : ?>
			<p><?php esc_html_e( 'No HappyFiles folders found on this site.', 'isudev-media-directory' ); ?></p>
		<?php else : ?>
			<p>
				<?php
				printf(
					/* translators: 1: number of folders, 2: number of folders already imported. */
					esc_html__( 'Found %1$d HappyFiles folders, %2$d of them already imported.', 'isudev-media-directory' ),
					(int) $source['folders'],
					(int) $source['imported']
				);
				?>
			</p>
			<p class="description"><?php esc_html_e( 'Copies the folder tree and which file sits where. HappyFiles data is left untouched, and running it again only adds what is missing.', 'isudev-media-directory' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="isudev_media_directory_import" />
				<?php wp_nonce_field( 'isudev_media_directory_import' ); ?>
				<?php submit_button( __( 'Import folders', 'isudev-media-directory' ), 'secondary' ); ?>
			</form>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Run the HappyFiles import from the settings page.
 *
 * @return void
 */
function handle_import(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'isudev-media-directory' ), 403 );
	}

	check_admin_referer( 'isudev_media_directory_import' );

	$result = Import\happyfiles();

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'         => PAGE,
				'imd_imported' => $result['folders'],
				'imd_filed'    => $result['files'],
			),
			admin_url( 'upload.php' )
		)
	);
	exit;
}
