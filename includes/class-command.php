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
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Manage media folders.
 */
class Command {

	/**
	 * Import folders and file assignments from HappyFiles.
	 *
	 * Safe to run again: folders already imported are reused.
	 *
	 * ## EXAMPLES
	 *
	 *     wp media-directory import-happyfiles
	 *
	 * @subcommand import-happyfiles
	 *
	 * @return void
	 */
	public function import_happyfiles(): void {
		$result = Import\happyfiles();

		WP_CLI::success( sprintf( 'Imported %d folders and filed %d files.', $result['folders'], $result['files'] ) );
	}

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
}
