<?php
/**
 * Fails when the Polish translation has gaps or the JS translation file is
 * missing or stale. Plain PHP, no WordPress.
 *
 * Usage: npm run test:i18n   (php tools/check-translations.php)
 *
 * @package IsuDevMediaDirectory
 */

declare( strict_types = 1 );

// phpcs:disable WordPress -- Dev script outside WordPress.

$root   = dirname( __DIR__ );
$domain = 'isudev-media-directory';
$errors = array();

/**
 * Parse a .po/.pot file into entries.
 *
 * @param string $file Path.
 * @return array<int, array{msgid:string, plural:bool, translated:bool, refs:string}>
 */
function imd_parse_po( string $file ): array {
	$entries = array();

	foreach ( preg_split( '/\n\s*\n/', (string) file_get_contents( $file ) ) as $block ) {
		if ( ! preg_match( '/^msgid "(.*)"$/m', $block, $id ) || '' === $id[1] ) {
			continue;
		}

		preg_match_all( '/^msgstr(?:\[\d\])? "(.*)"$/m', $block, $strs );
		preg_match_all( '/^#: (.*)$/m', $block, $refs );

		$entries[] = array(
			'msgid'      => stripcslashes( $id[1] ),
			'plural'     => (bool) preg_match( '/^msgid_plural /m', $block ),
			'translated' => $strs[1] && ! in_array( '', $strs[1], true ),
			'refs'       => implode( ' ', $refs[1] ),
		);
	}

	return $entries;
}

$pot = imd_parse_po( "$root/languages/$domain.pot" );
$po  = array_column( imd_parse_po( "$root/languages/$domain-pl_PL.po" ), null, 'msgid' );

foreach ( $pot as $entry ) {
	if ( ! isset( $po[ $entry['msgid'] ] ) ) {
		$errors[] = "Missing in pl_PL.po (run i18n:update-po): {$entry['msgid']}";
	} elseif ( ! $po[ $entry['msgid'] ]['translated'] ) {
		$errors[] = "Untranslated: {$entry['msgid']}";
	}
}

if ( ! preg_match( '/nplurals=3;/', (string) file_get_contents( "$root/languages/$domain-pl_PL.po" ) ) ) {
	$errors[] = 'pl_PL.po must declare nplurals=3.';
}

foreach ( array( 'mo', 'l10n.php' ) as $ext ) {
	$file = "$root/languages/$domain-pl_PL.$ext";

	// A git checkout sets arbitrary mtimes, so freshness is only checked locally.
	$stale = ! getenv( 'CI' ) && is_file( $file ) && filemtime( $file ) < filemtime( "$root/languages/$domain-pl_PL.po" );

	if ( ! is_file( $file ) || $stale ) {
		$errors[] = "languages/$domain-pl_PL.$ext is missing or older than the .po (run i18n:build).";
	}
}

// WordPress looks the JS file up by md5 of the script path relative to the plugin.
$json = "$root/languages/$domain-pl_PL-" . md5( 'build/index.js' ) . '.json';

if ( ! is_file( $json ) ) {
	$errors[] = 'JS translations missing for build/index.js (run i18n:build).';
} else {
	$messages = json_decode( (string) file_get_contents( $json ), true )['locale_data']['messages'] ?? array();

	foreach ( $pot as $entry ) {
		if ( false !== strpos( $entry['refs'], 'build/index.js' ) && ! isset( $messages[ $entry['msgid'] ] ) ) {
			$errors[] = "JS string missing from the JSON (run i18n:build): {$entry['msgid']}";
		}
	}
}

if ( $errors ) {
	fwrite( STDERR, implode( "\n", $errors ) . "\n" );
	exit( 1 );
}

printf( "Translations OK: %d strings.\n", count( $pot ) );
