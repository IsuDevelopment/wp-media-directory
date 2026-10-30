<?php
/**
 * Plugin Name:       IsuDev Media Directory
 * Plugin URI:        https://github.com/IsuDevelopment/wp-media-directory
 * Description:       Simple folders for the Media Library. Files stay visible across the whole folder tree, moves can be undone, and the folders work in every media modal.
 * Version:           0.4.1
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Author:            IsuDev
 * Author URI:        https://isudev.pl
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       isudev-media-directory
 * Domain Path:       /languages
 * Update URI:        false
 *
 * @package IsuDevMediaDirectory
 */

declare( strict_types = 1 );

namespace IsuDevMediaDirectory;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.4.1';

define( 'IsuDevMediaDirectory\\PATH', plugin_dir_path( __FILE__ ) );
define( 'IsuDevMediaDirectory\\URL', plugin_dir_url( __FILE__ ) );

require_once PATH . 'includes/schema.php';
require_once PATH . 'includes/folders.php';
require_once PATH . 'includes/permissions.php';
require_once PATH . 'includes/query.php';
require_once PATH . 'includes/rest.php';
require_once PATH . 'includes/admin.php';
require_once PATH . 'includes/download.php';
require_once PATH . 'includes/settings.php';
require_once PATH . 'includes/import/class-source.php';
require_once PATH . 'includes/import/class-taxonomy-source.php';
require_once PATH . 'includes/import/class-table-source.php';
require_once PATH . 'includes/import.php';
require_once PATH . 'includes/updater.php';

register_activation_hook( __FILE__, __NAMESPACE__ . '\\Schema\\install' );

Schema\boot();
Permissions\boot();
Query\boot();
REST\boot();
Admin\boot();
Download\boot();
Settings\boot();
Import\boot();
Updater\boot( __FILE__ );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once PATH . 'includes/class-command.php';
	\WP_CLI::add_command( 'media-directory', CLI\Command::class );
}

/**
 * Load the plugin text domain from languages/.
 *
 * @return void
 */
function load_textdomain(): void {
	load_plugin_textdomain( 'isudev-media-directory', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', __NAMESPACE__ . '\\load_textdomain', 1 );
