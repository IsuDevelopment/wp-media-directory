# AGENTS.md — IsuDev Media Directory

Source of truth for agents working in this repo. File-by-file map, REST
reference, flows and core couplings: [`docs/CODEMAP.md`](docs/CODEMAP.md).
Versioning, CI, self-updates, wordpress.org and monetization plans:
[`docs/RELEASE.md`](docs/RELEASE.md).

Repository: `git@github.com:IsuDevelopment/wp-media-directory.git` (public).

## What this is

A WordPress plugin (WP 7.0+, PHP 7.4+) that adds folders to the Media Library
and to every media modal. It replaces HappyFiles Pro on IsuDev client sites;
`example/happyfiles-pro/` is that plugin, kept for reference only (gitignored,
never shipped, never loaded).

## Product rules — check every change against these

- **Simplicity for non-technical clients wins.** If a feature needs explaining,
  it is not done. Fewer dialogs; prefer an Undo snackbar over "Are you sure?".
- **Files are visible across the tree.** With "Show files from subfolders" on, a
  parent folder shows everything below it, and each tile says where the file
  really lives (badge). The toggle stays visible above the tree, is remembered
  per user, and the site default lives in Media → Folder settings.
- **Subfolders must be visible where the files are**: breadcrumb, subfolder
  tiles and the "N more files are in subfolders — Show them here" hint. A
  folder must never look empty while its subfolders hold files.
- **Native WordPress feel.** `@wordpress/components`, admin theme colour
  (`--wp-admin-theme-color`), core spacing, borders (`#c3c4c7`/`#dcdcde`) and
  typography. No custom design language.
- **Every reversible action is undoable** (Cmd/Ctrl+Z and the snackbar Undo):
  move/add/remove files, create, rename, move and delete folders. Only
  permanently deleting files is not, and the UI says so.
- **Virtual folders only.** Files never move on disk; URLs never change. (Asked
  and decided: no Media-Library-Plus-style physical folders. A later "import a
  directory structure" feature may create folders from a ZIP/server path.)
- English source strings; Polish ships in `languages/`.

## Golden rules (code)

- **`includes/folders.php` is the only code that queries the plugin's tables.**
  Everything else calls `Folders\…`. Its in-request cache (`Folders\all()`) must
  be refreshed (`all( true )`) after any write.
- **Every REST write returns the fresh `tree`** and whatever is needed to undo
  it (`previous`, `snapshot`). The client never patches counts locally.
- **Every store action that changes data pushes an undo entry** (`pushUndo`)
  whose `revert` returns a promise, and notifies views through
  `eachView( 'onFiled' | 'onFoldersRemoved' | 'onTreeMoved' | 'onFilesDeleted' | 'onNestedChange' )`.
  A new view type (`createView`) must implement those handlers.
- **Never `props.set()` a folder on a live media collection.** Core empties the
  grid, then refills it — the "whole UI blinks" bug. Use `load()` in
  `src/media.js` (prefetch, then `mirror`). Same for refreshes: `load( browser )`,
  never `collection._requery()`.
- **No CSS `position: sticky` for the sidebar.** Core's media frame and the
  attachments browser hide overflow, so sticky binds to the grid and the panel
  jumps as the grid height changes. `src/fit-panel.js` positions it.
- **No `window.confirm`/`alert`.** They are blocked in some browsers/panes and
  look foreign. Use a `Modal` (see `delete-folder-modal.js`) rendered through
  `components/notices.js`, which sits above the media modal (z-index 160000).
- **Folder state per view, data state shared.** The open folder lives in the
  view (`view.js`); tree, toggle, undo and notices live in `store.js`.
- **The grid's header follows the *shown* folder** (`view.getShown()`), not the
  selected one, so breadcrumb and files change in the same frame.
- **Uploads are stamped with their folder at drop/pick time** (`pickedFolder`
  in `media.js`), never read at upload time — the HappyFiles misfiling bug.
- **Read raw `$_REQUEST['query']` in `ajax_query_attachments_args`.** Core
  whitelists query keys; ours would be dropped.
- **No top-level hook registration in `includes/`.** Each module has `boot()`,
  called from the bootstrap.
- **`build/`, `vendor/` and zips are never committed.** CI builds them and puts
  `build/` on the release tag only (`main` stays source-only).
- **The free plugin never locks local features behind payment** — it is headed
  for wordpress.org. Monetization follows the shared isuDev model (skill
  `isudev-plugins`); how Pro applies to this local-only plugin is still open
  (docs/RELEASE.md). Extension points are hooks/filters, documented in the code map.
- **The self-updater only runs from a release zip** (`includes/updater.php`):
  bundled `vendor/`, no `.git`, admin or cron. Keep all three conditions.
- **WordPress Coding Standards** (`phpcs.xml.dist`), `strict_types`, `ABSPATH`
  guard, `class-*.php` for class files. JS: `@wordpress/eslint-plugin`
  (`npm run lint:js`), JSDoc on exported functions.
- **Every user-facing string is translatable** with the `isudev-media-directory`
  domain, `_n()` for counts (Polish has three plural forms), and gets a Polish
  translation in the same change (see Translations).

## Working agentically

- **Comments are for agents.** Comment only what the code cannot say: core
  workarounds, couplings to core markup/behaviour, non-obvious decisions. One or
  two lines; longer background belongs in this file or `docs/`.
- **Every change updates the docs of what it touches in the same change**:
  `docs/CODEMAP.md` (files, routes, flows, core couplings table), this file
  (rules), `CHANGELOG.md` (anything a site owner notices), `README.md` (anything
  an operator configures).
- **A core coupling you add goes into the "Core couplings" table** in
  `docs/CODEMAP.md` with the symptom it causes when it breaks. That table is the
  checklist after every WordPress update.
- **Verify in the real admin, not only with linters** — the skill
  [`verify-media-directory`](.claude/skills/verify-media-directory/SKILL.md)
  has the browser recipes (drag simulation, flicker measurement, upload race).

## Layout

- `isudev-media-directory.php` — bootstrap. `uninstall.php` — drop tables.
- `includes/` — PHP modules (schema, folders repository, query, REST, admin,
  settings, HappyFiles import, WP-CLI).
- `src/` — admin UI source (React via `@wordpress/element`, Backbone patches).
  `build/` — compiled output; the plugin loads only `build/`.
- `languages/` — `.pot`, `pl_PL` `.po`/`.mo`/`.l10n.php`/JS `.json`.
- `tools/` — `smoke.php` (WP-CLI end-to-end), `check-translations.php`,
  `check-version.php`.
- `.github/workflows/release.yml` — build + GitHub release on a version bump.
- `docs/CODEMAP.md` — map. `docs/RELEASE.md` — releasing. `.claude/skills/` —
  agent workflows.

## Local environment

- Test site: Local by Flywheel, `http://isudev-library.local/`, WordPress root
  `/Users/lukaszbiedron/Local Sites/isudev-library/app/public`. This plugin is
  symlinked into its `wp-content/plugins/`.
- **WP-CLI works here.** `wp-cli.local.yml` (gitignored, per machine) points WP-CLI
  at the site and inherits Local's `wp-cli.yml` (which loads the DB socket):

  ```yaml
  _:
    inherit: "/Users/…/Local Sites/isudev-library/app/public/wp-cli.yml"
  path: "/Users/…/Local Sites/isudev-library/app/public"
  ```

  Without the `inherit` line WP-CLI fails with "Error establishing a database
  connection".
- Admin login: the developer logs in themselves in the browser pane; agents do
  not type credentials. Test data: 20+ generated images and a sample tree
  (Główna › Foo › Bar › Zaga, Alarmy › Czujniki, …).
- Node: `nvm use` (22.x, `.nvmrc`). The default shell node is v10 and fails.

## Build & check

```bash
nvm use
npm run build          # one-shot; never leave `npm start` watching during automated work
npm run check          # lint:js, lint:css, lint:php, test:version, test:i18n, test:smoke
```

- `npm run test:smoke` — `wp eval-file tools/smoke.php`: repository, query
  filter, every REST route, delete + restore. Creates `Smoke *` folders and
  removes them; restores the folders of the 3 attachments it touches.
- `npm run test:version` — plugin header, `VERSION`, `package.json`,
  `package-lock.json` and a `CHANGELOG.md` section agree.
- `npm run test:i18n` — every `.pot` entry translated in `pl_PL`, `.mo`/`.l10n.php`
  newer than the `.po`, JS JSON present and complete.
- Stylelint uses `.stylelintrc.json` (BEM class names allowed,
  `no-descending-specificity` off because we override core selectors).

## Translations

```bash
npm run build              # JS strings are extracted from build/index.js
npm run i18n:make-pot      # PHP + build/, excludes src/
npm run i18n:update-po     # merge new strings into languages/*-pl_PL.po
# translate every new entry in the .po (Polish quotes „…”, nplurals=3)
npm run i18n:build         # .mo, .l10n.php, JS .json
npm run test:i18n
```

The JS file is `isudev-media-directory-pl_PL-<md5("build/index.js")>.json`.
The hash is of the path, so the name is stable across builds, but its content
must be regenerated after strings change. `Admin\enqueue()` calls
`wp_set_script_translations()` with `languages/`. The local site has no Polish
core pack, so `switch_to_locale( 'pl_PL' )` does nothing there. Force the locale
in `wp eval` (`add_filter( 'determine_locale', fn() => 'pl_PL' )` and
`load_textdomain()` with the `.mo`), or install the pack:
`wp language core install pl_PL`.

## Known gaps / backlog

- REST uploads (dropping a file straight onto an Image block, `/wp/v2/media`)
  and `media-new.php` do not file into a folder.
- List view reloads the page on folder change (server-rendered table).
- "All files" shows every root folder as a tile; with many roots this pushes
  files down — collapse or hide pending a decision.
- No folder sorting other than natural name order; no folder colours.
- No `readme.txt` / wordpress.org SVN deploy yet (docs/RELEASE.md).
- Monetization (Pro add-on, licence API) not designed yet.
