# Release

Repository: `git@github.com:IsuDevelopment/wp-media-directory.git` (private; see Repositories).
Plugin slug, folder and text domain: `isudev-media-directory`.

## Steps

1. Bump the version in **all** of: plugin header `Version:` and `const VERSION`
   in `isudev-media-directory.php`, `package.json`, `package-lock.json`
   (`npm version <x.y.z> --no-git-tag-version` does both npm files).
2. Turn `## <x.y.z> — unreleased` in `CHANGELOG.md` into `## <x.y.z> — YYYY-MM-DD`
   (the heading must start with `## <x.y.z>`; its body becomes the release notes).
   In `readme.txt` set `Stable tag: <x.y.z>` and add a short `= <x.y.z> =` entry
   written for site owners.
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
6. Publishes GitHub Release `v<version>` with the zip and the CHANGELOG section,
   here and in the public releases repository (see Repositories).
7. Builds the wordpress.org copy, runs Plugin Check, keeps the wp.org zip as an
   artifact and, when enabled, deploys it to SVN (see wordpress.org).

## Self-updates on client sites

`includes/updater.php` boots Plugin Update Checker v5 against the GitHub
releases of the public releases repository (`enableReleaseAssets()` — installs the zip, not the source archive).
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

## Repositories

- `IsuDevelopment/wp-media-directory` — **private** source repository (this one):
  code, agent docs, tools. Never link it from anything a user sees.
- `IsuDevelopment/wp-media-directory-releases` — **public, releases only** (a
  README and GitHub Releases with the zip). `includes/updater.php` points here;
  `release.yml` publishes every release to it with the `RELEASES_TOKEN` secret
  (fine-grained PAT, Contents: write on that repository).
- wordpress.org SVN — the public face once approved (below).

0.4.2 was the switch: it was released while this repository was still public,
so sites on ≤ 0.4.1 picked it up and now check the releases repository.

## wordpress.org

The directory has rules the GitHub build breaks, so it gets its own build:
`tools/build-wporg.sh` (after `npm run build`) writes
`dist/wporg/isudev-media-directory/` and `dist/isudev-media-directory-wporg-<version>.zip`.

- **No self-updater.** Plugins may not update from anywhere but wordpress.org.
  The build ships without `vendor/` and without `includes/updater.php` (the main
  file loads it only if present) and drops the `Update URI` header.
- **Source for `build/`** (guideline 4): `src/` (without `src/test/`) and a
  trimmed `package.json` ship with it; `readme.txt` "Source code" says how to
  rebuild.
- **No agent or internal files**: `.distignore` plus the build script's own
  excludes (README.md, CHANGELOG.md, tests); the script fails if any of them
  slip in. User-facing text lives in `readme.txt`, not in README.md.
- **No locked features in the free plugin** (no trialware). Paid features live
  in a separate add-on plugin distributed outside wp.org — see Monetization.
- **Slug = text domain.** If wordpress.org assigns a slug other than
  `isudev-media-directory`, the text domain, `languages/` file names, JS JSON
  names and the CI `SLUG` must change with it.
- `readme.txt`: bump `Stable tag` with the version and add a changelog entry;
  keep `Tested up to` current. `Contributors` must be real wordpress.org
  usernames.
- Translations then come from translate.wordpress.org; our bundled ones keep
  working until a language pack replaces them.

CI:

- `wporg-check.yml` runs the build and Plugin Check (`WordPress/plugin-check-action`)
  on every pull request.
- `release.yml` repeats that on release, keeps the wp.org zip as a workflow
  artifact (the first submission is a manual upload at
  wordpress.org/plugins/developers/add/), and — once approved, with the
  repository variable `WPORG_DEPLOY=true` and secrets `SVN_USERNAME` /
  `SVN_PASSWORD` — commits it to SVN `trunk` + `tags/<version>`
  (`10up/action-wordpress-plugin-deploy`). Directory banners, icon and
  screenshots go in `.wordpress-org/` (`banner-772x250.png`,
  `banner-1544x500.png`, `icon-128x128.png`, `icon-256x256.png`,
  `screenshot-1.png`… matching `readme.txt` "Screenshots"); create it before
  the first SVN deploy.

## Monetization (to be designed)

This is an isuDev product, so it follows the shared isuDev plugin model (skill
`isudev-plugins`, reference implementation `~/Other Projects/isudev-review/`):
Paddle as merchant of record, licences and site activations owned by
`papi.isudev.com`, customer account on `accounts.isudev.com`, and in the plugin
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
