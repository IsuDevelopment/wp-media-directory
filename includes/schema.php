<?php
/**
 * Database tables.
 *
 * Folders live in their own two tables instead of a taxonomy, so they never
 * show up in taxonomy lists, term queries or other plugins' term pickers.
 *
 * @package IsuDevMediaDirectory
 */

declare( strict_types = 1 );

namespace IsuDevMediaDirectory\Schema;

defined( 'ABSPATH' ) || exit;

const DB_VERSION = '1';
const OPTION     = 'isudev_media_directory_db_version';

/**
 * Hook the schema check.
 *
 * @return void
 */
function boot(): void {
	add_action( 'plugins_loaded', __NAMESPACE__ . '\\maybe_upgrade' );
}

/**
 * Table holding the folders.
 *
 * @return string
 */
function folders_table(): string {
	global $wpdb;

	return $wpdb->prefix . 'imd_folders';
}

/**
 * Table holding which attachment sits in which folder.
 *
 * @return string
 */
function items_table(): string {
	global $wpdb;

	return $wpdb->prefix . 'imd_folder_items';
}

/**
 * Create or update the tables when the stored version is behind.
 *
 * @return void
 */
function maybe_upgrade(): void {
	if ( get_option( OPTION ) !== DB_VERSION ) {
		install();
	}
}

/**
 * Create the tables.
 *
 * @return void
 */
function install(): void {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset = $wpdb->get_charset_collate();
	$folders = folders_table();
	$items   = items_table();

	dbDelta(
		"CREATE TABLE {$folders} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
			name varchar(200) NOT NULL DEFAULT '',
			import_ref varchar(64) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY parent_id (parent_id),
			KEY import_ref (import_ref)
		) {$charset};
		CREATE TABLE {$items} (
			folder_id bigint(20) unsigned NOT NULL,
			attachment_id bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (folder_id,attachment_id),
			KEY attachment_id (attachment_id)
		) {$charset};"
	);

	update_option( OPTION, DB_VERSION, false );
}
