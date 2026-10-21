# AGENTS.md — IsuDev Media Directory

Source of truth for every agent (Codex, Claude, Gemini, Copilot…) and human
working in this repo. `CLAUDE.md` and `GEMINI.md` only point here. Everything
needed to pick the work up lives in the repo — nothing depends on one tool's
private features.

Repository: `git@github.com:IsuDevelopment/wp-media-directory.git` (public).

## Start here

1. This file — product rules, code rules, commands.
2. [`docs/CODEMAP.md`](docs/CODEMAP.md) — where every file, route, table and
   flow lives, the core couplings, and how-tos for common changes.
3. Only the doc the task needs:
   [`docs/VERIFY.md`](docs/VERIFY.md) (prove it works),
   [`docs/RELEASE.md`](docs/RELEASE.md) (versions, CI, updates, wordpress.org,
   monetization), [`CHANGELOG.md`](CHANGELOG.md), [`README.md`](README.md)
   (operator-facing).
4. Then the code.

| Task | Read | Update in the same change |
| --- | --- | --- |
| UI behaviour (tree, header, drag, undo) | CODEMAP "JS", "Main flows" | CODEMAP, CHANGELOG |
| New REST route / repository function | CODEMAP "REST", "How to" | CODEMAP, `tools/smoke.php` |
| New or changed string | "Translations" below | `languages/*` |
| Something broke after a WP update | CODEMAP "Core couplings" | that table |
| Settings / WP-CLI / anything an operator sees | README | README, CHANGELOG |
| Release | `docs/RELEASE.md` | version in 4 places, CHANGELOG |

## What this is

A WordPress plugin (WP 7.0+, PHP 7.4+) that adds folders to the Media Library
and to every media modal. An isuDev product: it replaces HappyFiles Pro on
client sites, is released on GitHub and will go to wordpress.org.
`example/happyfiles-pro/` (gitignored, may be absent) is HappyFiles, kept only
as a reference for the import.

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
- **Remember the user's place.** Last folder, subfolder switch and open tree
  branches persist; the user's own preferences (⚙ menu) win over the site
  defaults admins set in Folder settings → General. Settings pages are admins
  only; behaviour preferences belong to each user.
- English source strings; Polish, German and Norwegian ship in `languages/`.

## Code rules

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
- **No `window.confirm`/`alert`.** They are blocked in some browsers and look
  foreign. Use a `Modal` (see `delete-folder-modal.js`) rendered through
  `components/notices.js`, which sits above the media modal (z-index 160000).
- **Folder state per view, data state shared.** The open folder lives in the
  view (`view.js`); tree, toggle, undo and notices live in `store.js`.
- **The grid's header follows the *shown* folder** (`view.getShown()`), not the
  selected one, so breadcrumb and files change in the same frame.
- **Uploads are stamped with their folder at drop/pick time** (`pickedFolder`
  in `media.js`), never read at upload time — the HappyFiles misfiling bug.
- **Permissions are enforced on the server.** Every REST route and the upload
  filing check a matrix capability (`includes/permissions.php`); the UI reads
  `config.can` only to hide what would be refused. A new action needs both.
- **Importers never write to the source plugin except in `cleanup()`**, which
  is refused while that plugin is active. Tests use fake sources, never a real
  plugin's data.
- **Read raw `$_REQUEST['query']` in `ajax_query_attachments_args`.** Core
  whitelists query keys; ours would be dropped.
- **No top-level hook registration in `includes/`.** Each module has `boot()`,
  called from the bootstrap.
- **`build/`, `vendor/` and zips are never committed.** CI builds them and puts
  `build/` on the release tag only (`main` stays source-only).
- **The self-updater only runs from a release zip** (`includes/updater.php`):
  bundled `vendor/`, no `.git`, admin or cron. Keep all three conditions.
- **The free plugin never locks local features behind payment** — it is headed
  for wordpress.org (no trialware). Monetization follows the shared isuDev
  model; how Pro applies to this local-only plugin is still open
  (`docs/RELEASE.md`). Extension points are hooks/filters, listed in the code map.
- **WordPress Coding Standards** (`phpcs.xml.dist`), `strict_types`, `ABSPATH`
  guard, `class-*.php` for class files. JS: `@wordpress/eslint-plugin`, JSDoc on
  exported functions.
- **Every user-facing string is translatable** (domain `isudev-media-directory`,
  `_n()` for counts) and gets its translations (pl_PL, de_DE, nb_NO) in the
  same change. Never put a number in a non-`_n()` sentence; use `Label: %d`.
- **No new dependency** without a line in `docs/CODEMAP.md` saying why.

## Working agentically

- **Comments are for agents.** Comment only what the code cannot say: core
  workarounds, couplings to core markup/behaviour, non-obvious decisions. One or
  two lines; longer background belongs here or in `docs/`.
- **Docs move with the code, in the same change.** Stale docs are bugs.
  `npm run test:docs` fails when a file, REST route or WordPress hook is missing
  from `docs/CODEMAP.md`.
- **A core coupling you add goes into the "Core couplings" table** with the
  symptom it causes when it breaks. That table is the checklist after every
  WordPress update.
- **Portable tooling only.** npm/composer scripts, `git`, WP-CLI, plain file
  edits. A new workflow step becomes an npm script documented here — not a
  command only one agent knows. Tool-specific wrappers (e.g. `.claude/skills/`)
  only point at docs here and in `docs/`.
- **Never type credentials.** Ask the developer to log in.

## Definition of done

1. `npm run build && npm run check` green — report the output.
2. Verified in the real admin per [`docs/VERIFY.md`](docs/VERIFY.md) for any
   UI or query change (or say plainly that it was not).
3. Docs updated per the task table above; `CHANGELOG.md` for anything a site
   owner notices (under the next, unreleased version heading).
4. Test data you created is removed or listed in the report.
5. Commit/push only when the developer asks. Commits end with the
   co-author line your harness requires.

## Layout

- `isudev-media-directory.php` — bootstrap. `uninstall.php` — drop tables.
- `includes/` — PHP modules (schema, folders repository, permissions, query,
  REST, admin, settings, import engine + `import/` sources, updater, WP-CLI).
- `src/` — admin UI source (React via `@wordpress/element`, Backbone patches).
  `build/` — compiled output (gitignored); the plugin loads only `build/`.
- `languages/` — `.pot`; per locale (`pl_PL`, `de_DE`, `nb_NO`) `.po`, `.mo`,
  `.l10n.php` and JS `.json`.
- `tools/` — `smoke.php`, `check-translations.php`, `check-version.php`,
  `check-docs.php`.
- `docs/` — `CODEMAP.md`, `VERIFY.md`, `RELEASE.md`.
- `.github/workflows/release.yml` — build + GitHub release on a version bump.
- `.claude/` — Claude Code wrappers only (skills → docs, permissions, browser
  launch config). Nothing there is required to work on the plugin.

## Commands

```bash
nvm use                # Node 22 (.nvmrc); older Node fails
composer install       # PHPCS + plugin-update-checker
npm install
npm run build          # one-shot; never leave `npm start` watching in automated work
npm run check          # lint:js, lint:css, lint:php, test:version, test:docs, test:i18n, test:smoke
```

- `test:smoke` — `wp eval-file tools/smoke.php`: repository, query filter, every
  REST route, delete + restore against the real DB. Creates `Smoke *` folders and
  removes them; restores the folders of the 3 attachments it touches. Needs the
  site running: "Error establishing a database connection" (and HTTP 502 from
  the site) means the Local site is stopped — ask the developer to start it, it
  is not a code failure. Report the other checks as passed and smoke as skipped.
- `test:version` — plugin header, `VERSION`, `package.json`, `package-lock.json`
  and a `CHANGELOG.md` section agree.
- `test:docs` — every file, REST route and hook appears in `docs/CODEMAP.md`;
  `CLAUDE.md`/`GEMINI.md` point here.
- `test:i18n` — every `.pot` entry translated in every `languages/*.po`, plural
  count right, compiled files fresh, JS JSON complete.
- Stylelint uses `.stylelintrc.json` (BEM class names allowed,
  `no-descending-specificity` off because we override core selectors).

## Local environment

- WP-CLI needs to find the site: create `wp-cli.local.yml` (gitignored) in the
  repo root. For a Local (Flywheel) site it must inherit Local's `wp-cli.yml`,
  which loads the DB socket — without it: "Error establishing a database
  connection".

  ```yaml
  _:
    inherit: "<site root>/wp-cli.yml"
  path: "<site root>"
  ```

- Maintainer's setup: Local site `http://isudev-library.local/`, root
  `/Users/lukaszbiedron/Local Sites/isudev-library/app/public`, this repo
  symlinked into its `wp-content/plugins/`. Test data: 20+ generated images and a
  sample tree (Główna › Foo › Bar › Zaga, Alarmy › Czujniki, …).
- The site has no Polish core language pack (see Translations).

## Translations

```bash
npm run build              # JS strings are extracted from build/index.js
npm run i18n:make-pot      # PHP + build/, excludes src/
npm run i18n:update-po     # merge new strings into languages/*-pl_PL.po
# translate every new or fuzzy entry in the .po (rules below)
npm run i18n:build         # .mo, .l10n.php, JS .json
npm run test:i18n
```

- English source with typographic quotes `“…”`; placeholders get a
  `/* translators: */` comment on the line above.
- Languages: `pl_PL`, `de_DE`, `nb_NO`. Every new or fuzzy entry is
  translated in all of them; remove `#, fuzzy` once checked. Follow the
  WordPress core wording of each language.

  | Locale | Quotes | Plurals | Notes |
  | --- | --- | --- | --- |
  | `pl_PL` | `„…”` | 3: `[0]` 1 plik, `[1]` 2–4/22–24 pliki, `[2]` 5–21/25+ plików | „Anuluj”, „Usuń”, „Biblioteka mediów” |
  | `de_DE` | `„…“` | 2 | Informal **du** like core de_DE (formal Sie is `de_DE_formal`); „Mediathek”, „Ordner”, „Rückgängig” |
  | `nb_NO` | `«…»` | 2 | „Mediebibliotek”, „mappe”, „Angre”, „Avbryt” |

  A new language: copy the `.pot` to `languages/isudev-media-directory-<locale>.po`
  with `Language` and `Plural-Forms` headers, translate, add its plural count to
  `$nplurals` in `tools/check-translations.php`, run `i18n:build`.
- The JS file is `isudev-media-directory-pl_PL-<md5("build/index.js")>.json`:
  the name is stable (hash of the path), the content must be regenerated after
  strings change. `Admin\enqueue()` calls `wp_set_script_translations()`.
- Without a Polish core pack `switch_to_locale( 'pl_PL' )` does nothing. Check
  with `wp eval 'add_filter("determine_locale", fn() => "pl_PL"); load_textdomain("isudev-media-directory", IsuDevMediaDirectory\PATH . "languages/isudev-media-directory-pl_PL.mo", "pl_PL"); …'`
  or install it: `wp language core install pl_PL`.

## Known gaps / backlog

- REST uploads (dropping a file straight onto an Image block, `/wp/v2/media`)
  and `media-new.php` do not file into a folder.
- List view reloads the page on folder change (server-rendered table).
- "All files" shows every root folder as a tile; with many roots this pushes
  files down — collapse or hide pending a decision.
- No folder sorting other than natural name order; no folder colours.
- Per-user permission overrides (like HappyFiles' profile field): not built —
  role editor plugins can grant/deny the `imd_*` capabilities per user. Build a
  profile field only if clients ask for it.
- Only one public hook so far (`isudev_media_directory/import_sources`); more
  are needed before any Pro features.
- Imports cover media folders only; other plugins' folders for posts/pages
  (e.g. HappyFiles `hf_cat_*`) are out of scope.
- No `readme.txt` / wordpress.org SVN deploy yet; monetization model for this
  plugin undecided (`docs/RELEASE.md`).
- CI actions run on deprecated Node 20 (`actions/checkout@v4`, `setup-node@v4`,
  `action-gh-release@v2`) — bump when convenient.
