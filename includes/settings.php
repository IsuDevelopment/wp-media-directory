<?php
/**
 * Settings page (Media → Folder settings): General, Permissions and Import
 * tabs, plus the per-user subfolder preference.
 *
 * @package IsuDevMediaDirectory
 */

declare( strict_types = 1 );

namespace IsuDevMediaDirectory\Settings;

use IsuDevMediaDirectory\Import;
use IsuDevMediaDirectory\Permissions;

defined( 'ABSPATH' ) || exit;

const OPTION           = 'isudev_media_directory_settings';
const USER_NESTED_META = 'isudev_media_directory_nested';
const PREFS_META       = 'isudev_media_directory_prefs';
const PAGE             = 'isudev-media-directory';
const NOTICE           = 'isudev_media_directory_notice_';

/**
 * Hook the settings page.
 *
 * @return void
 */
function boot(): void {
	add_action( 'admin_menu', __NAMESPACE__ . '\\add_page' );
	add_action( 'admin_init', __NAMESPACE__ . '\\register' );
	add_action( 'admin_post_isudev_media_directory_import', __NAMESPACE__ . '\\handle_import' );
	add_action( 'admin_post_isudev_media_directory_cleanup', __NAMESPACE__ . '\\handle_cleanup' );
	add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_styles' );
}

/**
 * Behaviour preferences: key => [ site default, label, help ]. Admins set the
 * defaults on the General tab; each user overrides them from the ⚙ menu above
 * the folder tree (stored in their user meta).
 *
 * @return array<string, array{default:bool, label:string, help:string}>
 */
function preference_defs(): array {
	return array(
		'nested'        => array(
			'default' => true,
			'label'   => __( 'Show files from subfolders in parent folders', 'isudev-media-directory' ),
			'help'    => __( 'Also switchable with the toggle above the folder tree.', 'isudev-media-directory' ),
		),
		'tiles'         => array(
			'default' => true,
			'label'   => __( 'Show subfolders above the files', 'isudev-media-directory' ),
			'help'    => __( 'Breadcrumb tiles for the folders inside the open folder, with a “New folder” tile.', 'isudev-media-directory' ),
		),
		'reveal'        => array(
			'default' => true,
			'label'   => __( 'Open the current folder in the tree', 'isudev-media-directory' ),
			'help'    => __( 'Expands the tree to the folder you open from a tile or the breadcrumb.', 'isudev-media-directory' ),
		),
		'per_post_type' => array(
			'default' => false,
			'label'   => __( 'Remember the last folder separately for each content type', 'isudev-media-directory' ),
			'help'    => __( 'For example one folder when adding images to products and another for posts.', 'isudev-media-directory' ),
		),
	);
}

/**
 * Site defaults for every preference.
 *
 * @return array<string, bool>
 */
function get(): array {
	$saved    = get_option( OPTION, array() );
	$settings = array();

	foreach ( preference_defs() as $key => $def ) {
		$settings[ $key ] = isset( $saved[ $key ] ) ? (bool) $saved[ $key ] : $def['default'];
	}

	return $settings;
}

/**
 * The current user's preferences: their own choices over the site defaults.
 *
 * @return array<string, bool>
 */
function prefs_for_user(): array {
	$prefs = get();
	$own   = get_user_meta( get_current_user_id(), PREFS_META, true );
	$own   = is_array( $own ) ? $own : array();

	// 0.1.0 stored only the subfolder toggle, under its own key.
	$legacy = get_user_meta( get_current_user_id(), USER_NESTED_META, true );

	if ( ! isset( $own['nested'] ) && '' !== $legacy ) {
		$own['nested'] = '1' === $legacy;
	}

	foreach ( array_keys( $prefs ) as $key ) {
		if ( isset( $own[ $key ] ) ) {
			$prefs[ $key ] = (bool) $own[ $key ];
		}
	}

	return $prefs;
}

/**
 * Save some of the current user's preferences.
 *
 * @param array<string, bool> $changes Keys from preference_defs().
 * @return array<string, bool> All preferences after saving.
 */
function save_prefs_for_user( array $changes ): array {
	$own = get_user_meta( get_current_user_id(), PREFS_META, true );
	$own = is_array( $own ) ? $own : array();

	foreach ( array_keys( preference_defs() ) as $key ) {
		if ( array_key_exists( $key, $changes ) ) {
			$own[ $key ] = (bool) $changes[ $key ];
		}
	}

	update_user_meta( get_current_user_id(), PREFS_META, $own );

	return prefs_for_user();
}

/**
 * Preferences with the JS key names.
 *
 * @param array<string, bool> $prefs From prefs_for_user().
 * @return array<string, bool>
 */
function prefs_for_js( array $prefs ): array {
	return array(
		'nested'      => $prefs['nested'],
		'tiles'       => $prefs['tiles'],
		'reveal'      => $prefs['reveal'],
		'perPostType' => $prefs['per_post_type'],
	);
}

/**
 * Whether the current user sees files from subfolders.
 *
 * @return bool
 */
function nested_for_user(): bool {
	return prefs_for_user()['nested'];
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
 * Register the options. Each tab is its own settings group, so saving one
 * tab never resets another.
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
				$clean = array();

				foreach ( array_keys( preference_defs() ) as $key ) {
					$clean[ $key ] = ! empty( $value[ $key ] );
				}

				return $clean;
			},
		)
	);

	register_setting(
		PAGE . '-permissions',
		Permissions\OPTION,
		array(
			'type'              => 'object',
			'sanitize_callback' => __NAMESPACE__ . '\\sanitize_permissions',
		)
	);
}

/**
 * Sanitize callback for the permission matrix.
 *
 * @param mixed $value Submitted matrix.
 * @return array<string, array<string, bool>>
 */
function sanitize_permissions( $value ): array {
	return Permissions\sanitize( $value );
}

/**
 * The tab being shown.
 *
 * @return string
 */
function current_tab(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.
	$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';

	return in_array( $tab, array( 'general', 'permissions', 'import' ), true ) ? $tab : 'general';
}

/**
 * URL of a tab.
 *
 * @param string $tab Tab.
 * @return string
 */
function tab_url( string $tab ): string {
	return add_query_arg(
		array(
			'page' => PAGE,
			'tab'  => $tab,
		),
		admin_url( 'upload.php' )
	);
}

/**
 * Keep a result message for the next page load of this user.
 *
 * @param string $type    success | error | warning.
 * @param string $message Message.
 * @return void
 */
function flash( string $type, string $message ): void {
	set_transient(
		NOTICE . get_current_user_id(),
		array(
			'type'    => $type,
			'message' => $message,
		),
		MINUTE_IN_SECONDS
	);
}

/**
 * Start a white dashboard panel (core `.postbox`). Every tab is built from
 * these, so all tabs look the same.
 *
 * @param string $title Panel title.
 * @param string $intro Optional intro paragraph.
 * @return void
 */
function panel_open( string $title, string $intro = '' ): void {
	?>
	<div class="postbox imd-settings-panel">
		<div class="postbox-header"><h2 class="hndle"><?php echo esc_html( $title ); ?></h2></div>
		<div class="inside">
			<?php if ( '' !== $intro ) : ?>
				<p><?php echo esc_html( $intro ); ?></p>
			<?php endif; ?>
	<?php
}

/**
 * Close a panel opened with panel_open().
 *
 * @return void
 */
function panel_close(): void {
	echo '</div></div>';
}

/**
 * Small styles for the settings page: panels are core `.postbox`, this only
 * removes the drag cursor (no postbox.js here) and doubled table borders.
 *
 * @param string $hook_suffix Admin page.
 * @return void
 */
function enqueue_styles( string $hook_suffix ): void {
	if ( 'media_page_' . PAGE !== $hook_suffix ) {
		return;
	}

	wp_register_style( 'isudev-media-directory-settings', false, array(), \IsuDevMediaDirectory\VERSION );
	wp_enqueue_style( 'isudev-media-directory-settings' );
	wp_add_inline_style(
		'isudev-media-directory-settings',
		'.imd-settings-panel{margin-top:20px}'
		. '.imd-settings-panel .postbox-header .hndle{cursor:default;padding:8px 12px;font-size:14px}'
		. '.imd-settings-panel .inside{margin:0;padding:4px 12px 12px}'
		. '.imd-settings-panel .form-table{margin-top:0}'
		. '.imd-settings-panel .widefat{border:0;box-shadow:none}'
		. '.imd-settings-panel .widefat th .description{display:block;font-weight:400}'
		. '.imd-settings-panel hr{margin:16px -12px;border:0;border-top:1px solid #f0f0f1}'
	);
}

/**
 * Render the page.
 *
 * @return void
 */
function render(): void {
	$tab    = current_tab();
	$notice = get_transient( NOTICE . get_current_user_id() );

	if ( $notice ) {
		delete_transient( NOTICE . get_current_user_id() );
	}

	$tabs = array(
		'general'     => __( 'General', 'isudev-media-directory' ),
		'permissions' => __( 'Permissions', 'isudev-media-directory' ),
		'import'      => __( 'Import', 'isudev-media-directory' ),
	);
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Folder settings', 'isudev-media-directory' ); ?></h1>

		<?php if ( is_array( $notice ) ) : ?>
			<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
		<?php endif; ?>

		<nav class="nav-tab-wrapper">
			<?php foreach ( $tabs as $key => $label ) : ?>
				<a href="<?php echo esc_url( tab_url( $key ) ); ?>" class="nav-tab<?php echo $key === $tab ? ' nav-tab-active' : ''; ?>"<?php echo $key === $tab ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>

		<?php
		if ( 'permissions' === $tab ) {
			render_permissions();
		} elseif ( 'import' === $tab ) {
			render_import();
		} else {
			render_general();
		}
		?>
	</div>
	<?php
}

/**
 * General tab.
 *
 * @return void
 */
function render_general(): void {
	$settings = get();
	?>
	<form method="post" action="options.php">
		<?php settings_fields( PAGE ); ?>
		<?php panel_open( __( 'Defaults for everyone', 'isudev-media-directory' ), __( 'How the folders behave for users who have not changed it themselves. Everyone can adjust these for their own account from the ⚙ menu above the folder tree.', 'isudev-media-directory' ) ); ?>
		<table class="form-table" role="presentation">
			<?php foreach ( preference_defs() as $key => $def ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( $def['label'] ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( OPTION . '[' . $key . ']' ); ?>" value="1" <?php checked( $settings[ $key ] ); ?> />
							<?php esc_html_e( 'On', 'isudev-media-directory' ); ?>
						</label>
						<p class="description"><?php echo esc_html( $def['help'] ); ?></p>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php submit_button(); ?>
		<?php panel_close(); ?>
	</form>
	<?php
}

/**
 * Permissions tab: roles × actions.
 *
 * @return void
 */
function render_permissions(): void {
	$matrix  = Permissions\matrix();
	$roles   = Permissions\roles();
	$actions = Permissions\actions();
	?>
	<form method="post" action="options.php">
		<?php settings_fields( PAGE . '-permissions' ); ?>
		<?php panel_open( __( 'Permissions', 'isudev-media-directory' ), __( 'Choose what each role can do with folders. Only roles that can upload files are listed.', 'isudev-media-directory' ) ); ?>
		<table class="widefat striped imd-permissions" role="presentation">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Role', 'isudev-media-directory' ); ?></th>
					<?php foreach ( $actions as $action ) : ?>
						<th scope="col">
							<?php echo esc_html( $action['label'] ); ?>
							<span class="description"><?php echo esc_html( $action['help'] ); ?></span>
						</th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $roles as $role => $name ) : ?>
					<?php $locked = 'administrator' === $role; ?>
					<tr>
						<th scope="row"><?php echo esc_html( $name ); ?></th>
						<?php foreach ( $actions as $cap => $action ) : ?>
							<td>
								<label>
									<span class="screen-reader-text"><?php echo esc_html( $name . ': ' . $action['label'] ); ?></span>
									<input
										type="checkbox"
										name="<?php echo esc_attr( Permissions\OPTION . '[' . $role . '][' . $cap . ']' ); ?>"
										value="1"
										<?php checked( ! empty( $matrix[ $role ][ $cap ] ) ); ?>
										<?php disabled( $locked ); ?>
									/>
								</label>
								<?php if ( $locked ) : ?>
									<input type="hidden" name="<?php echo esc_attr( Permissions\OPTION . '[' . $role . '][' . $cap . ']' ); ?>" value="1" />
								<?php endif; ?>
							</td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">
			<?php esc_html_e( 'Administrators can always do everything. Organizing, creating or deleting includes seeing folders. Empty folders can be deleted by anyone who can create them, so a new folder can be undone. Permanently deleting files still follows WordPress’ own rules.', 'isudev-media-directory' ); ?>
		</p>
		<?php submit_button(); ?>
		<?php panel_close(); ?>
	</form>
	<?php
}

/**
 * Import tab: one block per plugin with folders, a short list for the rest.
 *
 * @return void
 */
function render_import(): void {
	$with_data = array();
	$empty     = array();

	foreach ( Import\sources() as $source ) {
		$preview = Import\preview( $source );

		if ( $preview['folders'] ) {
			$with_data[] = array( $source, $preview );
		} else {
			$empty[] = $source->name();
		}
	}
	?>
	<?php panel_open( __( 'Import', 'isudev-media-directory' ), __( 'Copy folders and which file sits where from another media folder plugin. Its own data is not changed, and an import can be repeated: it only adds what is missing.', 'isudev-media-directory' ) ); ?>
	<?php if ( $empty ) : ?>
		<p>
			<strong><?php esc_html_e( 'Nothing to import from', 'isudev-media-directory' ); ?>:</strong>
			<?php echo esc_html( implode( ', ', $empty ) ); ?>
		</p>
	<?php endif; ?>
	<?php panel_close(); ?>

	<?php foreach ( $with_data as list( $source, $preview ) ) : ?>
		<?php render_source( $source, $preview ); ?>
	<?php endforeach; ?>
	<?php
}

/**
 * One source block: preview, import form, cleanup form.
 *
 * @param Import\Source                                                         $source  Source.
 * @param array{folders:int, files:int, imported:int, matches:int, active:bool} $preview Preview.
 * @return void
 */
function render_source( Import\Source $source, array $preview ): void {
	$slug   = $source->slug();
	$action = admin_url( 'admin-post.php' );
	?>
	<?php panel_open( $source->name() ); ?>
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: number of folders, 2: number of files, 3: number of folders already imported. */
					__( 'Folders: %1$d · Files in them: %2$d · Already imported: %3$d', 'isudev-media-directory' ),
					$preview['folders'],
					$preview['files'],
					$preview['imported']
				)
			);
			?>
			<?php if ( $preview['active'] ) : ?>
				<br /><strong><?php esc_html_e( 'The plugin is still active.', 'isudev-media-directory' ); ?></strong>
			<?php endif; ?>
		</p>

		<form method="post" action="<?php echo esc_url( $action ); ?>">
			<input type="hidden" name="action" value="isudev_media_directory_import" />
			<input type="hidden" name="source" value="<?php echo esc_attr( $slug ); ?>" />
			<?php wp_nonce_field( 'isudev_media_directory_import_' . $slug ); ?>
			<fieldset>
				<legend class="screen-reader-text"><?php esc_html_e( 'Where to put the folders', 'isudev-media-directory' ); ?></legend>
				<p>
					<label>
						<input type="radio" name="mode" value="<?php echo esc_attr( Import\MODE_MERGE ); ?>" checked />
						<?php esc_html_e( 'Merge with my folders', 'isudev-media-directory' ); ?>
					</label>
					<span class="description">
						<?php
						if ( $preview['matches'] ) {
							echo esc_html(
								sprintf(
									/* translators: %d: number of folders. */
									_n( '%d top-level folder has the same name as one of yours and will be combined with it.', '%d top-level folders have the same name as yours and will be combined with them.', $preview['matches'], 'isudev-media-directory' ),
									$preview['matches']
								)
							);
						} else {
							esc_html_e( 'Folders with the same name in the same place are combined.', 'isudev-media-directory' );
						}
						?>
					</span>
				</p>
				<p>
					<label>
						<input type="radio" name="mode" value="<?php echo esc_attr( Import\MODE_SEPARATE ); ?>" />
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: plugin name. */
								__( 'Put everything in a new folder “%s”', 'isudev-media-directory' ),
								$source->name()
							)
						);
						?>
					</label>
				</p>
			</fieldset>
			<?php if ( 'happyfiles' === $slug ) : ?>
				<p>
					<label>
						<input type="checkbox" name="permissions" value="1" checked />
						<?php esc_html_e( 'Also copy HappyFiles folder access per role to Permissions', 'isudev-media-directory' ); ?>
					</label>
				</p>
			<?php endif; ?>
			<?php submit_button( __( 'Import folders', 'isudev-media-directory' ), 'primary', 'submit', false ); ?>
		</form>

		<hr />

		<form method="post" action="<?php echo esc_url( $action ); ?>">
			<input type="hidden" name="action" value="isudev_media_directory_cleanup" />
			<input type="hidden" name="source" value="<?php echo esc_attr( $slug ); ?>" />
			<?php wp_nonce_field( 'isudev_media_directory_cleanup_' . $slug ); ?>
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: plugin name. */
						__( 'After importing you can delete %s’s folder data from the database. Files stay in the library; only that plugin’s folders and assignments are removed. This cannot be undone.', 'isudev-media-directory' ),
						$source->name()
					)
				);
				?>
			</p>
			<?php if ( $preview['active'] ) : ?>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: plugin name. */
							__( 'Deactivate %s first.', 'isudev-media-directory' ),
							$source->name()
						)
					);
					?>
				</p>
			<?php else : ?>
				<p>
					<label>
						<input type="checkbox" name="confirm" value="1" required />
						<?php esc_html_e( 'I understand this cannot be undone', 'isudev-media-directory' ); ?>
					</label>
				</p>
				<?php
				submit_button(
					sprintf(
						/* translators: %s: plugin name. */
						__( 'Delete %s folder data', 'isudev-media-directory' ),
						$source->name()
					),
					'delete',
					'submit',
					false
				);
				?>
			<?php endif; ?>
		</form>
	<?php panel_close(); ?>
	<?php
}

/**
 * The source named in the request, after capability and nonce checks.
 *
 * @param string $nonce_action Nonce action prefix.
 * @return Import\Source
 */
function requested_source( string $nonce_action ): Import\Source {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'isudev-media-directory' ), 403 );
	}

	$slug = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified next.

	check_admin_referer( $nonce_action . $slug );

	$source = Import\source( $slug );

	if ( ! $source ) {
		wp_die( esc_html__( 'Unknown import source.', 'isudev-media-directory' ), 400 );
	}

	return $source;
}

/**
 * Run an import from the settings page.
 *
 * @return void
 */
function handle_import(): void {
	$source = requested_source( 'isudev_media_directory_import_' );

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in requested_source().
	$mode        = isset( $_POST['mode'] ) && Import\MODE_SEPARATE === $_POST['mode'] ? Import\MODE_SEPARATE : Import\MODE_MERGE;
	$permissions = 'happyfiles' === $source->slug() && ! empty( $_POST['permissions'] );
	// phpcs:enable

	$result  = Import\run( $source, $mode );
	$message = sprintf(
		/* translators: 1: plugin name, 2: folders created, 3: folders reused, 4: files filed. */
		__( 'Imported from %1$s. Folders created: %2$d · Existing folders reused: %3$d · Files filed: %4$d.', 'isudev-media-directory' ),
		$source->name(),
		$result['created'],
		$result['reused'],
		$result['files']
	);

	if ( $permissions ) {
		$access   = Import\happyfiles_permissions();
		$message .= ' ' . sprintf(
			/* translators: %d: number of roles. */
			_n( 'Folder access copied for %d role.', 'Folder access copied for %d roles.', $access['roles'], 'isudev-media-directory' ),
			$access['roles']
		);

		if ( $access['users'] ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of users. */
				_n( '%d user had personal HappyFiles access; personal settings are not supported, so they now follow their role.', '%d users had personal HappyFiles access; personal settings are not supported, so they now follow their role.', $access['users'], 'isudev-media-directory' ),
				$access['users']
			);
		}
	}

	flash( 'success', $message );
	wp_safe_redirect( tab_url( 'import' ) );
	exit;
}

/**
 * Delete a source's data from the settings page.
 *
 * @return void
 */
function handle_cleanup(): void {
	$source = requested_source( 'isudev_media_directory_cleanup_' );

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in requested_source().
	if ( empty( $_POST['confirm'] ) ) {
		flash( 'error', __( 'Nothing was deleted: please tick the confirmation box.', 'isudev-media-directory' ) );
	} else {
		$result = Import\cleanup( $source );

		if ( is_wp_error( $result ) ) {
			flash( 'error', $result->get_error_message() );
		} else {
			flash(
				'success',
				sprintf(
					/* translators: %s: plugin name. */
					__( '%s folder data deleted.', 'isudev-media-directory' ),
					$source->name()
				)
			);
		}
	}

	wp_safe_redirect( tab_url( 'import' ) );
	exit;
}
