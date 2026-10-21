<?php
/**
 * WP-CLI commands.
 *
 * @package IsuDevMediaDirectory
 */

declare( strict_types = 1 );

namespace IsuDevMediaDirectory\CLI;

use IsuDevMediaDirectory\Folders;
use IsuDevMediaDirectory\Import;
use IsuDevMediaDirectory\Permissions;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Manage media folders.
 */
class Command {

	/**
	 * List folders with file counts.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Named args.
	 * @return void
	 */
	public function list( array $args, array $assoc_args ): void {
		WP_CLI\Utils\format_items( $assoc_args['format'], Folders\tree()['folders'], array( 'id', 'parent', 'name', 'count', 'total' ) );
	}

	/**
	 * List plugins folders can be imported from, with what they hold.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Named args.
	 * @return void
	 */
	public function sources( array $args, array $assoc_args ): void {
		$rows = array();

		foreach ( Import\sources() as $slug => $source ) {
			$rows[] = array( 'source' => $slug ) + array( 'name' => $source->name() ) + Import\preview( $source );
		}

		WP_CLI\Utils\format_items( $assoc_args['format'], $rows, array( 'source', 'name', 'folders', 'files', 'imported', 'matches', 'active' ) );
	}

	/**
	 * Import folders and file assignments from another plugin.
	 *
	 * Safe to run again: folders imported before are reused.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : Source slug, see `wp media-directory sources`.
	 *
	 * [--mode=<mode>]
	 * : merge (combine same-named folders) or separate (put all under a new folder).
	 * ---
	 * default: merge
	 * options:
	 *   - merge
	 *   - separate
	 * ---
	 *
	 * [--permissions]
	 * : HappyFiles only: also copy folder access per role.
	 *
	 * ## EXAMPLES
	 *
	 *     wp media-directory import happyfiles --permissions
	 *     wp media-directory import filebird --mode=separate
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Named args.
	 * @return void
	 */
	public function import( array $args, array $assoc_args ): void {
		$source = $this->source( $args[0] );
		$mode   = $assoc_args['mode'] ?? Import\MODE_MERGE;
		$result = Import\run( $source, Import\MODE_SEPARATE === $mode ? Import\MODE_SEPARATE : Import\MODE_MERGE );

		WP_CLI::success( sprintf( '%s: %d folders created, %d reused, %d files filed.', $source->name(), $result['created'], $result['reused'], $result['files'] ) );

		if ( ! empty( $assoc_args['permissions'] ) && 'happyfiles' === $source->slug() ) {
			$access = Import\happyfiles_permissions();
			WP_CLI::success( sprintf( 'Folder access copied for %d roles (%d users had personal access; they now follow their role).', $access['roles'], $access['users'] ) );
		}
	}

	/**
	 * Same as `import happyfiles`. Kept for scripts written for 0.1.0.
	 *
	 * @subcommand import-happyfiles
	 *
	 * @return void
	 */
	public function import_happyfiles(): void {
		$this->import( array( 'happyfiles' ), array() );
	}

	/**
	 * Delete another plugin's folder data. Attachments are never touched.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : Source slug.
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Named args.
	 * @return void
	 */
	public function cleanup( array $args, array $assoc_args ): void {
		$source = $this->source( $args[0] );

		WP_CLI::confirm( sprintf( 'Delete all %s folder data? This cannot be undone.', $source->name() ), $assoc_args );

		$result = Import\cleanup( $source );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( sprintf( '%s folder data deleted.', $source->name() ) );
	}

	/**
	 * Show the permission matrix.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Named args.
	 * @return void
	 */
	public function permissions( array $args, array $assoc_args ): void {
		$rows = array();

		foreach ( Permissions\matrix() as $role => $caps ) {
			$rows[] = array( 'role' => $role ) + array_map(
				static function ( $allowed ) {
					return $allowed ? 'yes' : 'no';
				},
				$caps
			);
		}

		WP_CLI\Utils\format_items( $assoc_args['format'], $rows, array_merge( array( 'role' ), array_keys( Permissions\actions() ) ) );
	}

	/**
	 * A source by slug, or exit with an error.
	 *
	 * @param string $slug Slug.
	 * @return Import\Source
	 */
	private function source( string $slug ): Import\Source {
		$source = Import\source( $slug );

		if ( ! $source ) {
			WP_CLI::error( sprintf( 'Unknown source "%s". Available: %s.', $slug, implode( ', ', array_keys( Import\sources() ) ) ) );
		}

		return $source;
	}
}
