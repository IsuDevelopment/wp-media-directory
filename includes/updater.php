<?php
/**
 * Self-updates from GitHub releases (Plugin Update Checker).
 *
 * Only release-zip installs update themselves: they bundle vendor/. Composer
 * installs have none (Composer owns the version), and a git checkout must
 * never be overwritten by an update — see docs/RELEASE.md.
 *
 * @package IsuDevMediaDirectory
 */

declare( strict_types = 1 );

namespace IsuDevMediaDirectory\Updater;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

use const IsuDevMediaDirectory\PATH;

defined( 'ABSPATH' ) || exit;

const REPOSITORY = 'https://github.com/IsuDevelopment/wp-media-directory/';
const SLUG       = 'isudev-media-directory';

/**
 * Start the update checker when this install should self-update.
 *
 * @param string $plugin_file Main plugin file.
 * @return void
 */
function boot( string $plugin_file ): void {
	$autoload = PATH . 'vendor/autoload.php';

	// Front end never needs it; a git checkout (local development) must not be replaced.
	if ( ! ( is_admin() || wp_doing_cron() ) || ! is_readable( $autoload ) || is_dir( PATH . '.git' ) ) {
		return;
	}

	require_once $autoload;

	if ( ! class_exists( PucFactory::class ) ) {
		return;
	}

	$checker = PucFactory::buildUpdateChecker( REPOSITORY, $plugin_file, SLUG );
	$api     = $checker->getVcsApi();

	// Install the built zip attached to the release, not GitHub's source archive. Checked by
	// method: PUC exposes its API classes under a version-specific namespace.
	if ( method_exists( $api, 'enableReleaseAssets' ) ) {
		$api->enableReleaseAssets();
	}
}
