<?php
/**
 * Fails when the version disagrees between the plugin header, the VERSION
 * const, package.json, package-lock.json and CHANGELOG.md. Plain PHP.
 *
 * Usage: npm run test:version   (php tools/check-version.php)
 * Prints the version on success, so CI can reuse it.
 *
 * @package IsuDevMediaDirectory
 */

declare( strict_types = 1 );

// phpcs:disable WordPress -- Dev script outside WordPress.

$root   = dirname( __DIR__ );
$main   = (string) file_get_contents( "$root/isudev-media-directory.php" );
$errors = array();

preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $main, $header );
preg_match( "/^const VERSION = '([^']+)';/m", $main, $const );

$version  = $header[1] ?? '';
$package  = json_decode( (string) file_get_contents( "$root/package.json" ), true );
$lock     = json_decode( (string) file_get_contents( "$root/package-lock.json" ), true );
$versions = array(
	'VERSION const'                => $const[1] ?? '',
	'package.json'                 => $package['version'] ?? '',
	'package-lock.json'            => $lock['version'] ?? '',
	'package-lock.json packages[]' => $lock['packages']['']['version'] ?? '',
);

if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
	$errors[] = "Plugin header Version is not x.y.z: '$version'.";
}

foreach ( $versions as $where => $value ) {
	if ( $value !== $version ) {
		$errors[] = "$where is '$value', plugin header is '$version'.";
	}
}

if ( ! preg_match( '/^## ' . preg_quote( $version, '/' ) . '\b/m', (string) file_get_contents( "$root/CHANGELOG.md" ) ) ) {
	$errors[] = "CHANGELOG.md has no '## $version' section.";
}

if ( $errors ) {
	fwrite( STDERR, implode( "\n", $errors ) . "\n" );
	exit( 1 );
}

echo $version . "\n";
