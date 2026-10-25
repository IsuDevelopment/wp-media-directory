<?php
/**
 * Source for plugins that keep media folders as taxonomy terms (HappyFiles,
 * Folders by Premio, Enhanced Media Library, WP Media Folder, Wicked Folders).
 *
 * Reads with SQL so it works while the plugin is deactivated and its taxonomy
 * unregistered. Items are matched through term_taxonomy_id, which differs from
 * term_id on many sites (HappyFiles' own importer mixes the two up).
 *
 * @package IsuDevMediaDirectory
 */

declare( strict_types = 1 );

namespace IsuDevMediaDirectory\Import;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Another plugin's terms, possibly unregistered.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core table names; IDs are cast.

/**
 * Taxonomy-based source.
 */
class Taxonomy_Source extends Source {

	/**
	 * Slug.
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * Name.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Taxonomy holding the folders.
	 *
	 * @var string
	 */
	private $taxonomy;

	/**
	 * Plugin name patterns.
	 *
	 * @var string[]
	 */
	private $patterns;

	/**
	 * Term slugs that are the plugin's own root, not a folder.
	 *
	 * @var string[]
	 */
	private $root_slugs;

	/**
	 * Term meta key holding a folder colour, '' when the plugin has none.
	 *
	 * @var string
	 */
	private $color_meta;

	/**
	 * Set up.
	 *
	 * @param string   $slug       Source slug.
	 * @param string   $name       Display name.
	 * @param string   $taxonomy   Taxonomy.
	 * @param string[] $patterns   Plugin name patterns.
	 * @param string[] $root_slugs Root term slugs to skip.
	 * @param string   $color_meta Term meta key with the folder colour.
	 */
	public function __construct( string $slug, string $name, string $taxonomy, array $patterns, array $root_slugs = array(), string $color_meta = '' ) {
		$this->slug       = $slug;
		$this->name       = $name;
		$this->taxonomy   = $taxonomy;
		$this->patterns   = $patterns;
		$this->root_slugs = $root_slugs;
		$this->color_meta = $color_meta;
	}

	/**
	 * Slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return $this->slug;
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Taxonomy.
	 *
	 * @return string
	 */
	public function taxonomy(): string {
		return $this->taxonomy;
	}

	/**
	 * Plugin name patterns.
	 *
	 * @return string[]
	 */
	protected function plugin_patterns(): array {
		return $this->patterns;
	}

	/**
	 * Terms of the taxonomy.
	 *
	 * @return array<int, array{tt_id:int, term_id:int, parent:int, name:string, slug:string}>
	 */
	private function terms(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tt.term_taxonomy_id, t.term_id, tt.parent, t.name, t.slug
				FROM {$wpdb->term_taxonomy} tt
				INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
				WHERE tt.taxonomy = %s",
				$this->taxonomy
			),
			ARRAY_N
		);

		return array_map(
			static function ( $row ) {
				return array(
					'tt_id'   => (int) $row[0],
					'term_id' => (int) $row[1],
					'parent'  => (int) $row[2],
					'name'    => html_entity_decode( (string) $row[3], ENT_QUOTES, 'UTF-8' ),
					'slug'    => (string) $row[4],
				);
			},
			(array) $rows
		);
	}

	/**
	 * Folders keyed by term ID.
	 *
	 * @return array<string, array{name:string, parent:string, color?:string}>
	 */
	public function folders(): array {
		$terms   = $this->terms();
		$colors  = $this->colors();
		$skipped = array();
		$folders = array();

		foreach ( $terms as $term ) {
			if ( in_array( $term['slug'], $this->root_slugs, true ) ) {
				$skipped[] = $term['term_id'];
			}
		}

		foreach ( $terms as $term ) {
			if ( in_array( $term['term_id'], $skipped, true ) ) {
				continue;
			}

			$parent = in_array( $term['parent'], $skipped, true ) ? 0 : $term['parent'];

			$folders[ (string) $term['term_id'] ] = array(
				'name'   => $term['name'],
				'parent' => $parent ? (string) $parent : '',
				'color'  => $colors[ $term['term_id'] ] ?? '',
			);
		}

		return $folders;
	}

	/**
	 * Folder colours keyed by term ID (SQL, so it works with the plugin off).
	 *
	 * @return array<int, string>
	 */
	private function colors(): array {
		global $wpdb;

		if ( '' === $this->color_meta ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tm.term_id, tm.meta_value
				FROM {$wpdb->termmeta} tm
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id AND tt.taxonomy = %s
				WHERE tm.meta_key = %s",
				$this->taxonomy,
				$this->color_meta
			),
			ARRAY_N
		);

		$colors = array();

		foreach ( (array) $rows as $row ) {
			$colors[ (int) $row[0] ] = (string) $row[1];
		}

		return $colors;
	}

	/**
	 * Attachments per term.
	 *
	 * @return array<int, array{0:string, 1:int}>
	 */
	public function items(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tt.term_id, tr.object_id
				FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s
				INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id AND p.post_type = 'attachment'",
				$this->taxonomy
			),
			ARRAY_N
		);

		return array_map(
			static function ( $row ) {
				return array( (string) $row[0], (int) $row[1] );
			},
			(array) $rows
		);
	}

	/**
	 * Delete the terms, their relationships and meta.
	 *
	 * @return void
	 */
	public function cleanup(): void {
		global $wpdb;

		$terms = $this->terms();

		if ( ! $terms ) {
			return;
		}

		$tt_ids   = implode( ',', array_map( 'intval', array_column( $terms, 'tt_id' ) ) );
		$term_ids = implode( ',', array_map( 'intval', array_column( $terms, 'term_id' ) ) );

		$wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ({$tt_ids})" );
		$wpdb->query( "DELETE FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id IN ({$tt_ids})" );

		// Only terms no other taxonomy still uses (shared terms predate WP 4.4).
		$wpdb->query(
			"DELETE t, tm FROM {$wpdb->terms} t
			LEFT JOIN {$wpdb->termmeta} tm ON tm.term_id = t.term_id
			WHERE t.term_id IN ({$term_ids})
			AND NOT EXISTS ( SELECT 1 FROM {$wpdb->term_taxonomy} tt WHERE tt.term_id = t.term_id )"
		);

		clean_term_cache( array_map( 'intval', array_column( $terms, 'term_id' ) ), $this->taxonomy );
	}
}
