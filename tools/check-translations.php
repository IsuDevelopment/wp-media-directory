<?php
/**
 * Fails when a translation (every languages/*-<locale>.po) has gaps or the JS translation file is
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

$pot     = imd_parse_po( "$root/languages/$domain.pot" );
$locales = array();

foreach ( glob( "$root/languages/$domain-*.po" ) as $file ) {
	$locales[] = substr( basename( $file, '.po' ), strlen( $domain ) + 1 );
}

// Plural form count per language; a wrong count silently breaks plurals.
$nplurals = array(
	'pl_PL' => 3,
	'de_DE' => 2,
	'nb_NO' => 2,
);

foreach ( $locales as $locale ) {
	$po_file = "$root/languages/$domain-$locale.po";
	$po      = array_column( imd_parse_po( $po_file ), null, 'msgid' );

	foreach ( $pot as $entry ) {
		if ( ! isset( $po[ $entry['msgid'] ] ) ) {
			$errors[] = "$locale: missing (run i18n:update-po): {$entry['msgid']}";
		} elseif ( ! $po[ $entry['msgid'] ]['translated'] ) {
			$errors[] = "$locale: untranslated: {$entry['msgid']}";
		}
	}

	if ( isset( $nplurals[ $locale ] ) && ! preg_match( '/nplurals=' . $nplurals[ $locale ] . ';/', (string) file_get_contents( $po_file ) ) ) {
		$errors[] = "$locale.po must declare nplurals={$nplurals[ $locale ]}.";
	}

	foreach ( array( 'mo', 'l10n.php' ) as $ext ) {
		$file = "$root/languages/$domain-$locale.$ext";

		// A git checkout sets arbitrary mtimes, so freshness is only checked locally.
		$stale = ! getenv( 'CI' ) && is_file( $file ) && filemtime( $file ) < filemtime( $po_file );

		if ( ! is_file( $file ) || $stale ) {
			$errors[] = "languages/$domain-$locale.$ext is missing or older than the .po (run i18n:build).";
		}
	}

	// WordPress looks the JS file up by md5 of the script path relative to the plugin.
	$json = "$root/languages/$domain-$locale-" . md5( 'build/index.js' ) . '.json';

	if ( ! is_file( $json ) ) {
		$errors[] = "$locale: JS translations missing for build/index.js (run i18n:build).";
		continue;
	}

	$messages = json_decode( (string) file_get_contents( $json ), true )['locale_data']['messages'] ?? array();

	foreach ( $pot as $entry ) {
		if ( false !== strpos( $entry['refs'], 'build/index.js' ) && ! isset( $messages[ $entry['msgid'] ] ) ) {
			$errors[] = "$locale: JS string missing from the JSON (run i18n:build): {$entry['msgid']}";
		}
	}
}

if ( $errors ) {
	fwrite( STDERR, implode( "\n", $errors ) . "\n" );
	exit( 1 );
}

printf( "Translations OK: %d strings in %s.\n", count( $pot ), implode( ', ', $locales ) );
