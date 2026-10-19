# Release

Repository: `git@github.com:IsuDevelopment/wp-media-directory.git` (public).
Plugin slug, folder and text domain: `isudev-media-directory`.

## Steps

1. Bump the version in **all** of: plugin header `Version:` and `const VERSION`
   in `isudev-media-directory.php`, `package.json`, `package-lock.json`
   (`npm version <x.y.z> --no-git-tag-version` does both npm files).
2. Turn `## <x.y.z> — unreleased` in `CHANGELOG.md` into `## <x.y.z> — YYYY-MM-DD`
   (the heading must start with `## <x.y.z>`; its body becomes the release notes).
3. `npm run check` — includes `test:version`, which fails on any disagreement.
4. Commit and push to `main`. Never commit `build/`, `vendor/` or zips.

## What CI does (`.github/workflows/release.yml`)

Runs on a push to `main` that touches `isudev-media-directory.php`, or manually:

1. `php tools/check-version.php` — stops on any version mismatch.
2. No-op if tag `v<version>` already exists (editing the main file without a bump is safe).
3. `composer install --no-dev` (the update checker), `npm ci && npm run build`,
   `php tools/check-translations.php`.
4. Commits `build/` on top of the release commit and pushes only tag `v<version>`
   — `main` stays source-only; Composer installs from the tag are runnable.
5. Packs `isudev-media-directory-<version>.zip` from `.distignore` (the single
   exclude list) **plus `vendor/`**, asserts runtime files are in and dev files out.
6. Publishes GitHub Release `v<version>` with the zip and the CHANGELOG section.

## Self-updates on client sites

`includes/updater.php` boots Plugin Update Checker v5 against the GitHub
releases (`enableReleaseAssets()` — installs the zip, not the source archive).
It runs only when all hold:

- wp-admin or cron (never the front end);
- `vendor/autoload.php` exists (release-zip install; Composer installs have no
  bundled `vendor/`, Composer owns their version);
- there is **no `.git` directory** in the plugin — a development checkout or
  symlink must never be replaced by an update.

Keep working: zip top folder `isudev-media-directory`, tags `v<version>`,
release asset attached. Renaming the repo means updating `Updater\REPOSITORY`
and this file. Other `isudev-*` plugins bundle their own PUC copy; PUC handles
several versions side by side, so never rely on `class_exists()` to tell whether
*our* checker is running — check `has_action( 'puc_cron_check_updates-isudev-media-directory' )`.

## wordpress.org (planned)

The directory has rules the GitHub build breaks; the wp.org build must differ:

- **No self-updater.** Guideline: plugins may not update from servers other than
  wordpress.org. The wp.org package ships **without `vendor/`** (exactly what
  `.distignore` produces), so `Updater\boot()` never starts. Also drop the
  `Update URI` header there (Plugin Check flags it).
- **No locked features in the free plugin** (no trialware). Paid features live
  in a separate add-on plugin distributed outside wp.org — see Monetization.
- **Slug = text domain.** If wordpress.org assigns a slug other than
  `isudev-media-directory`, the text domain, `languages/` file names and JS
  JSON names must change with it. Decide the slug before the first submission.
- Needs `readme.txt` (WP format: Stable tag, Tested up to, Requires PHP,
  screenshots, FAQ, changelog), GPL-compatible licence (we are GPL-2.0-or-later),
  no phone-home without consent, and a clean run of the Plugin Check plugin.
- Translations then come from translate.wordpress.org; our bundled `pl_PL` keeps
  working until a language pack replaces it.
- Release to wp.org = SVN `trunk` + `tags/<version>` (a later CI job; nothing
  exists yet).

## Monetization (to be designed)

This is an isuDev product, so it follows the shared isuDev plugin model (skill
`isudev-plugins`, reference implementation `~/Other Projects/isudev-review/`):
Paddle as merchant of record, licences and site activations owned by
`api.isudev.com`, customer account on `account.isudev.com`, and in the plugin
`Api_Client`, `Site_Registration` and `License::has( 'feature' )` copied from
`isudev-reviews`. `License::has()` only shapes UI; **the API enforces the tier**.
Default there: one plugin, Pro unlocked by a licence key, no separate Pro zip.

**Open question for this product:** everything here runs locally, with no
server-side value to enforce, and locking local code in the wordpress.org
build is trialware (not allowed). Options to decide with the developer:

1. Pro = cloud features served by `/media-directory/v1/*` (e.g. AI sorting and
   tagging of uploads into folders, duplicate detection). This is allowed on wp.org
   because the value lives on the server.
2. Pro = local features in a separate Pro add-on zip (outside wp.org,
   self-updating with the licence key).
3. A mix: cloud features through the key, plus an add-on for local extras.

Whatever is chosen, the free plugin keeps everything it has today, and paid
features attach through hooks/filters documented in `docs/CODEMAP.md`.
Nothing licence-related is in this repository yet.
