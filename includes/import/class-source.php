<?php
/**
 * A plugin we can import media folders from.
 *
 * A source only reads and deletes the other plugin's data; creating our
 * folders is the engine's job (includes/import.php).
 *
 * @package IsuDevMediaDirectory
 */

declare( strict_types = 1 );

namespace IsuDevMediaDirectory\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for import sources.
 */
abstract class Source {

	/**
	 * Stable key, also the `import_ref` prefix of folders imported from it.
	 *
	 * @return string
	 */
	abstract public function slug(): string;

	/**
	 * Display name.
	 *
	 * @return string
	 */
	abstract public function name(): string;

	/**
	 * Patterns matched against active plugins' "Plugin Name" header.
	 *
	 * @return string[] Regular expressions.
	 */
	abstract protected function plugin_patterns(): array;

	/**
	 * Folders keyed by the source's own ID.
	 *
	 * @return array<string, array{name:string, parent:string, color?:string}> Parent '' = root; color `#rrggbb` when the source has one.
	 */
	abstract public function folders(): array;

	/**
	 * Which attachment sits in which source folder.
	 *
	 * @return array<int, array{0:string, 1:int}> Source folder ID, attachment ID.
	 */
	abstract public function items(): array;

	/**
	 * Delete the source's folder data. Never touches attachments.
	 *
	 * @return void
	 */
	abstract public function cleanup(): void;

	/**
	 * Plugin file of the source plugin when it is active.
	 *
	 * @return string|null
	 */
	public function active_plugin(): ?string {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( get_plugins() as $file => $data ) {
			if ( ! is_plugin_active( $file ) ) {
				continue;
			}

			foreach ( $this->plugin_patterns() as $pattern ) {
				if ( preg_match( $pattern, (string) $data['Name'] ) ) {
					return (string) $file;
				}
			}
		}

		return null;
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	protected function table_exists( string $table ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}
}
