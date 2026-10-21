<?php
/**
 * Removes the plugin's tables and options when it is deleted from the admin.
 *
 * Deactivation leaves everything alone on purpose — only an explicit delete
 * reaches this file.
 *
 * @package IsuDevMediaDirectory
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dropping our own tables.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}imd_folder_items" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}imd_folders" );
// phpcs:enable

delete_option( 'isudev_media_directory_db_version' );
delete_option( 'isudev_media_directory_settings' );
delete_metadata( 'user', 0, 'isudev_media_directory_nested', '', true );
delete_metadata( 'user', 0, 'isudev_media_directory_prefs', '', true );
delete_option( 'isudev_media_directory_permissions' );
