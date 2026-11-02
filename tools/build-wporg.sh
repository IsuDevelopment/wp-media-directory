#!/usr/bin/env bash
# Builds the wordpress.org copy of the plugin into dist/wporg/isudev-media-directory/
# (and a zip next to it). Run after `npm run build`. See docs/RELEASE.md "wordpress.org".
#
# Differs from the GitHub zip: no vendor/ and no includes/updater.php (wp.org plugins
# may not update from elsewhere), no `Update URI` header, and src/ + package.json are
# kept so the minified build/ has its human-readable source (guideline 4).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG=isudev-media-directory
OUT="$ROOT/dist/wporg/$SLUG"

cd "$ROOT"
VERSION="$(php tools/check-version.php)"

[ -f build/index.js ] || { echo "build/index.js is missing; run npm run build first." >&2; exit 1; }

EXCLUDE="$(mktemp)"
trap 'rm -f "$EXCLUDE"' EXIT
grep -vxE '/src|/package\.json' .distignore > "$EXCLUDE"
printf '%s\n' /src/test /includes/updater.php /README.md /CHANGELOG.md >> "$EXCLUDE"

rm -rf "$ROOT/dist/wporg"
mkdir -p "$OUT"
rsync -a --exclude-from="$EXCLUDE" ./ "$OUT/"

# package.json only explains how build/ is made; dev scripts point at files not shipped.
php -r '$f = $argv[1]; $p = json_decode( file_get_contents( $f ), true );
$p["scripts"] = array_intersect_key( $p["scripts"], array_flip( array( "build", "start" ) ) );
file_put_contents( $f, json_encode( $p, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );' "$OUT/package.json"

# Drop the `Update URI` header line: it would stop wordpress.org updates.
sed -i.bak '/^ \* Update URI:/d' "$OUT/$SLUG.php" && rm "$OUT/$SLUG.php.bak"

for f in "$SLUG.php" readme.txt uninstall.php build/index.js build/index.asset.php src/index.js package.json "languages/$SLUG-pl_PL.mo"; do
	[ -f "$OUT/$f" ] || { echo "missing $f" >&2; exit 1; }
done

for f in vendor includes/updater.php AGENTS.md CLAUDE.md GEMINI.md .claude .agents .github docs tools example node_modules src/test README.md CHANGELOG.md; do
	[ ! -e "$OUT/$f" ] || { echo "development file in the wp.org build: $f" >&2; exit 1; }
done

if grep -q 'Update URI' "$OUT/$SLUG.php"; then
	echo "Update URI header is still in $SLUG.php" >&2
	exit 1
fi

(cd "$ROOT/dist/wporg" && rm -f "../$SLUG-wporg-$VERSION.zip" && zip -qr "../$SLUG-wporg-$VERSION.zip" "$SLUG")
echo "dist/wporg/$SLUG and dist/$SLUG-wporg-$VERSION.zip ($VERSION)"
