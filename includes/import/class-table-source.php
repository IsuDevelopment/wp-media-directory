<?php
/**
 * Source for plugins with their own folder tables (FileBird, Real Media Library).
 *
 * @package IsuDevMediaDirectory
 */

declare( strict_types = 1 );

namespace IsuDevMediaDirectory\Import;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Another plugin's tables.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and column names come from the class config, not input.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange -- Cleanup drops the other plugin's tables on request.

/**
 * Table-based source.
 */
class Table_Source extends Source {

	/**
	 * Config: slug, name, patterns, folders table + columns, items table + columns, tables to drop.
	 *
	 * @var array<string, mixed>
	 */
	private $config;

	/**
	 * Set up.
	 *
	 * Keys: slug, name, patterns (plugin name regexes), folders + folder_cols
	 * ([id, name, parent]), items + item_cols ([folder, attachment]), drop
	 * (tables removed by cleanup). Table names are without the DB prefix.
	 *
	 * @param array<string, mixed> $config Source definition.
	 */
	public function __construct( array $config ) {
		$this->config = $config;
	}

	/**
	 * Slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return (string) $this->config['slug'];
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name(): string {
		return (string) $this->config['name'];
	}

	/**
	 * Plugin name patterns.
	 *
	 * @return string[]
	 */
	protected function plugin_patterns(): array {
		return $this->config['patterns'];
	}

	/**
	 * Prefixed table name.
	 *
	 * @param string $key Config key.
	 * @return string
	 */
	private function table( string $key ): string {
		global $wpdb;

		return $wpdb->prefix . $this->config[ $key ];
	}

	/**
	 * Folders keyed by the plugin's folder ID. Parents of 0 or below are the root.
	 *
	 * @return array<string, array{name:string, parent:string}>
	 */
	public function folders(): array {
		global $wpdb;

		$table = $this->table( 'folders' );

		if ( ! $this->table_exists( $table ) ) {
			return array();
		}

		list( $id, $name, $parent ) = $this->config['folder_cols'];

		$rows    = $wpdb->get_results( "SELECT `{$id}`, `{$name}`, `{$parent}` FROM `{$table}`", ARRAY_N );
		$folders = array();

		foreach ( (array) $rows as $row ) {
			$folders[ (string) (int) $row[0] ] = array(
				'name'   => (string) $row[1],
				'parent' => (int) $row[2] > 0 ? (string) (int) $row[2] : '',
			);
		}

		return $folders;
	}

	/**
	 * Attachments per folder.
	 *
	 * @return array<int, array{0:string, 1:int}>
	 */
	public function items(): array {
		global $wpdb;

		$table = $this->table( 'items' );

		if ( ! $this->table_exists( $table ) ) {
			return array();
		}

		list( $folder, $attachment ) = $this->config['item_cols'];

		$rows = $wpdb->get_results(
			"SELECT i.`{$folder}`, i.`{$attachment}` FROM `{$table}` i
			INNER JOIN {$wpdb->posts} p ON p.ID = i.`{$attachment}` AND p.post_type = 'attachment'",
			ARRAY_N
		);

		return array_map(
			static function ( $row ) {
				return array( (string) (int) $row[0], (int) $row[1] );
			},
			(array) $rows
		);
	}

	/**
	 * Drop the plugin's folder tables.
	 *
	 * @return void
	 */
	public function cleanup(): void {
		global $wpdb;

		foreach ( $this->config['drop'] as $table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $wpdb->prefix . $table ) . '`' );
		}
	}
}
