<?php
/**
 * Fails when the agent docs drift from the code: a source file, REST route or
 * WordPress hook missing from docs/CODEMAP.md, an agent pointer file that does
 * not point at AGENTS.md, or a broken relative link in the docs. Plain PHP.
 *
 * Usage: npm run test:docs   (php tools/check-docs.php)
 *
 * @package IsuDevMediaDirectory
 */

declare( strict_types = 1 );

// phpcs:disable WordPress -- Dev script outside WordPress.

$root   = dirname( __DIR__ );
$map    = (string) file_get_contents( "$root/docs/CODEMAP.md" );
$errors = array();

// Every source file is mapped.
$files = array_merge(
	glob( "$root/includes/*.php" ),
	glob( "$root/includes/*/*.php" ),
	glob( "$root/src/*.{js,scss}", GLOB_BRACE ),
	glob( "$root/src/components/*.js" ),
	glob( "$root/tools/*.php" ),
	array( "$root/isudev-media-directory.php", "$root/uninstall.php", "$root/.github/workflows/release.yml" )
);

foreach ( $files as $file ) {
	if ( false === strpos( $map, basename( $file ) ) ) {
		$errors[] = 'docs/CODEMAP.md does not mention ' . substr( $file, strlen( $root ) + 1 );
	}
}

// Every REST route is in the REST table; `(?P<id>\d+)` is written `{id}`.
preg_match_all( "/register_rest_route\(\s*NAMESPACE_V1,\s*'([^']+)'/", (string) file_get_contents( "$root/includes/rest.php" ), $routes );

foreach ( $routes[1] as $route ) {
	$route = preg_replace( '/\(\?P<(\w+)>[^)]*\)/', '{$1}', $route );

	if ( false === strpos( $map, " $route" ) && false === strpos( $map, "`$route" ) ) {
		$errors[] = "docs/CODEMAP.md REST table is missing $route";
	}
}

// Every WordPress hook we add is in the hooks table.
foreach ( array_merge( glob( "$root/includes/*.php" ), glob( "$root/includes/*/*.php" ) ) as $file ) {
	preg_match_all( "/add_(?:action|filter)\(\s*'([^']+)'/", (string) file_get_contents( $file ), $hooks );

	foreach ( $hooks[1] as $hook ) {
		if ( false === strpos( $map, "`$hook`" ) ) {
			$errors[] = "docs/CODEMAP.md hooks table is missing $hook (" . basename( $file ) . ')';
		}
	}
}

// Agent entry points all lead to AGENTS.md.
foreach ( array( 'CLAUDE.md', 'GEMINI.md' ) as $pointer ) {
	if ( ! is_file( "$root/$pointer" ) || false === strpos( (string) file_get_contents( "$root/$pointer" ), 'AGENTS.md' ) ) {
		$errors[] = "$pointer must exist and point to AGENTS.md";
	}
}

// Relative links in the docs resolve.
$docs = array_merge(
	array( 'AGENTS.md', 'CLAUDE.md', 'GEMINI.md', 'README.md' ),
	array_map(
		static function ( $file ) use ( $root ) {
			return substr( $file, strlen( $root ) + 1 );
		},
		array_merge( glob( "$root/docs/*.md" ), glob( "$root/.claude/skills/*/SKILL.md" ) )
	)
);

foreach ( $docs as $doc ) {
	preg_match_all( '/\]\(([^)#\s]+)(?:#[^)]*)?\)/', (string) file_get_contents( "$root/$doc" ), $links );

	foreach ( $links[1] as $link ) {
		if ( preg_match( '#^[a-z]+:#i', $link ) ) {
			continue;
		}

		if ( ! file_exists( dirname( "$root/$doc" ) . '/' . $link ) ) {
			$errors[] = "$doc links to missing $link";
		}
	}
}

if ( $errors ) {
	fwrite( STDERR, implode( "\n", $errors ) . "\n" );
	exit( 1 );
}

printf( "Docs OK: %d files, %d routes mapped.\n", count( $files ), count( $routes[1] ) );
