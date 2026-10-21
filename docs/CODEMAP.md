# Code map

Where everything lives and how a click turns into SQL. Read with
[`AGENTS.md`](../AGENTS.md), which holds the rules. `npm run test:docs` keeps
this file honest: every file, REST route and WordPress hook must appear here.

## Big picture

```
 Browser (wp-admin)                                         Server
 ─────────────────────────────────────────────              ─────────────────────────────
 core media views (Backbone)                                admin-ajax query-attachments
   AttachmentsBrowser ── patched by src/media.js ──props──►   └ includes/query.php  ─┐
     ├ .imd-sidebar        React <Sidebar>                                          │
     ├ .imd-content-header React <ContentHeader>                                    ├► includes/folders.php
     └ Attachment tiles    folder badge                                             │   (only DB access)
 list table (upload.php?mode=list) ── src/list.js                                   │        │
                                                                                    │   {prefix}imd_folders
 src/store.js  (one shared state + undo stack) ── apiFetch ──► REST isudev-media-directory/v1   {prefix}imd_folder_items
                                                                 └ includes/rest.php ┘
 wp.Uploader (plupload) ── multipart imd_folder ─────────────► async-upload.php
                                                                 └ includes/admin.php file_upload()
```

## PHP (`includes/`)

| File | Namespace | Role |
| --- | --- | --- |
| `isudev-media-directory.php` | `IsuDevMediaDirectory` | Bootstrap: `VERSION`, `PATH`, `URL`, requires, every module's `boot()`, activation hook, WP-CLI registration, textdomain. |
| `schema.php` | `…\Schema` | Table names (`folders_table()`, `items_table()`), `install()` via `dbDelta`, `maybe_upgrade()` on `plugins_loaded` when `DB_VERSION` changes. |
| `folders.php` | `…\Folders` | **Repository — the only code that touches the tables.** Tree reads, CRUD, `subtree()`, `scope()`, `of()`, `set()`, `insert_items()`, `attachments_in()`, `delete()` → snapshot, `restore()`, `tree()` with counts. |
| `query.php` | `…\Query` | Narrows attachment queries: `ajax_query_attachments_args` (grid + modals), `pre_get_posts` (list view), `posts_where` (the SQL), `the_posts` (primes folder IDs), `folders_of()` cache. |
| `rest.php` | `…\REST` | All routes. Every write returns the fresh `tree` plus what the client needs to undo. |
| `admin.php` | `…\Admin` | Enqueue on `wp_enqueue_media` + `upload.php`, `window.isudevMediaDirectory` config, `imdFolders` in attachment JSON, filing uploads (`add_attachment`), cleanup on `delete_attachment`. |
| `settings.php` | `…\Settings` | Preferences: `preference_defs()` (nested, tiles, reveal, per_post_type), site defaults `get()`, per-user `prefs_for_user()` / `save_prefs_for_user()` / `prefs_for_js()`. Media → Folder settings (admins only) with tabs General (preference defaults), Permissions (matrix form), Import (per-source preview, merge/separate, HappyFiles permissions, cleanup with confirmation). Results via a per-user transient notice. |
| `permissions.php` | `…\Permissions` | Role × action matrix (option `isudev_media_directory_permissions`) granted as capabilities `imd_view_folders`, `imd_organize_files`, `imd_manage_folders`, `imd_delete_folders` via `user_has_cap`. `can_delete()` = delete cap, or manage cap on an empty folder. `for_js()` → `config.can`. |
| `import.php` | `…\Import` | Import engine: `sources()` registry (filter `isudev_media_directory/import_sources`), `preview()`, `run( $source, merge\|separate )`, `happyfiles_permissions()`, `cleanup()` (refused while the source plugin is active), conflict notice. Idempotent via `import_ref = <source>:<source id>` (`<source>:root` for the separate-mode folder). |
| `import/class-source.php` | `…\Import\Source` | Abstract source: `slug()`, `name()`, `folders()` (`[id => {name, parent}]`, parent `''` = root), `items()` (`[[folder id, attachment id]]`), `cleanup()`, `active_plugin()` (matches active plugins' "Plugin Name" against `plugin_patterns()`). |
| `import/class-taxonomy-source.php` | `…\Import\Taxonomy_Source` | Taxonomy-based plugins: HappyFiles (`happyfiles_category`), Folders by Premio (`media_folder`), Enhanced Media Library (`media_category`), WP Media Folder (`wpmf-category`, skips its `wp-media-folder-root` term), Wicked Folders (`wf_attachment_folders`). SQL only, works with the plugin off; items via `term_taxonomy_id`. Cleanup deletes terms, relationships, meta. |
| `import/class-table-source.php` | `…\Import\Table_Source` | Table-based plugins: FileBird (`fbv`, `fbv_attachment_folder`), Real Media Library (`realmedialibrary`, `realmedialibrary_posts`; parent `-1` = root). Cleanup drops their tables. |
| `updater.php` | `…\Updater` | Plugin Update Checker against GitHub releases; boots only from a release zip (see `docs/RELEASE.md`). |
| `class-command.php` | `…\CLI\Command` | `wp media-directory list`, `sources`, `import <source> [--mode=merge\|separate] [--permissions]`, `cleanup <source> [--yes]`, `permissions`; `import-happyfiles` kept as an alias. |
| `uninstall.php` | — | Drops both tables, options and user meta. Only on delete, never on deactivate. |

### WordPress hooks we use

| Hook | Type | Module → function | Why |
| --- | --- | --- | --- |
| `plugins_loaded` | action | `Schema\maybe_upgrade` | Create/upgrade tables when `DB_VERSION` changes. |
| `init` (priority 1) | action | `load_textdomain` (bootstrap) | Translations before anything renders. |
| `rest_api_init` | action | `REST\register_routes` | All routes. |
| `wp_enqueue_media` | action | `Admin\enqueue` | Load the UI wherever a media modal can open. |
| `admin_enqueue_scripts` | action | `Admin\enqueue_library` | List view never calls `wp_enqueue_media()`. |
| `wp_prepare_attachment_for_js` | filter | `Admin\attachment_json` | `imdFolders` on every attachment model. |
| `add_attachment` | action | `Admin\file_upload` | File an upload into its stamped folder. |
| `delete_attachment` | action | `Admin\forget_attachment` | Drop deleted files from folders. |
| `ajax_query_attachments_args` | filter | `Query\ajax_args` | Folder for grid and modal queries. |
| `pre_get_posts` | action | `Query\list_view` | Folder for the list view main query. |
| `posts_where` | filter | `Query\where` | The folder SQL. |
| `the_posts` | filter | `Query\prime` | One query for a page's folder IDs. |
| `admin_menu` | action | `Settings\add_page` | Media → Folder settings. |
| `admin_init` | action | `Settings\register` | Settings API option. |
| `admin_post_isudev_media_directory_import` | action | `Settings\handle_import` | Import button (any source). |
| `admin_post_isudev_media_directory_cleanup` | action | `Settings\handle_cleanup` | Delete a source's folder data. |
| `user_has_cap` | filter | `Permissions\grant` | Grant our capabilities from the matrix; explicit grants/denials (role editors) win. |
| `admin_notices` | action | `Import\conflict_notice` | Another folder plugin active (Media, Plugins, our settings screens). |

Plus `register_activation_hook` → `Schema\install`, `WP_CLI::add_command( 'media-directory' )`,
and Plugin Update Checker's own hooks (slug `isudev-media-directory`).

### Extension points (public API)

| Hook | Type | Arguments | Use |
| --- | --- | --- | --- |
| `isudev_media_directory/import_sources` | filter | `Source[] $list` | Add an import source: an instance of a `Import\Source` subclass (or a configured `Taxonomy_Source` / `Table_Source`). |

Capabilities `imd_view_folders`, `imd_organize_files`, `imd_manage_folders`,
`imd_delete_folders` are public too: role editor plugins can grant or deny them
per user, and that wins over the matrix.

When adding an extension point:

- name hooks `isudev_media_directory/<name>` (slash-namespaced; `phpcs.xml.dist`
  allows it), JS filters through `@wordpress/hooks` as `isudevMediaDirectory.<name>`;
- list it here with its arguments and when it fires, and in `CHANGELOG.md` —
  from then on it is a public contract.

### Data

```
{prefix}imd_folders        id, parent_id (0 = root), name, import_ref, created_at
{prefix}imd_folder_items   folder_id, attachment_id   PK (folder_id, attachment_id), KEY attachment_id
```

A file may sit in several folders (Alt/Option-drag adds). "Not in a folder" = no
row in `imd_folder_items`. Trash is excluded from counts.

Options and meta:

| Key | Where | What |
| --- | --- | --- |
| `isudev_media_directory_db_version` | option | Schema version, compared to `Schema\DB_VERSION`. |
| `isudev_media_directory_settings` | option | Site defaults `{ nested, tiles, reveal, per_post_type }` (bools). |
| `isudev_media_directory_prefs` | user meta | The user's own preferences `{nested, tiles, reveal, per_post_type}`; missing keys use the site defaults. |
| `isudev_media_directory_nested` | user meta | 0.1.0 subfolder toggle (`'1'`/`'0'`); still read when `prefs` has no `nested`. |
| `isudev_media_directory_permissions` | option | `[role][cap] => bool`; missing roles get `role_defaults()` (admin/editor all, author no delete, others see + organize). |
| `isudev_media_directory_notice_<user id>` | transient | One-off settings page result message. |

Query vars (raw request keys, constants in `Query`): `imd_folder` (`<id>` or
`unassigned`; absent = all files) and `imd_nested` (`1`/`0`).

### REST — `isudev-media-directory/v1`

All routes require `upload_files` plus a matrix capability: read and
`/preferences` → `imd_view_folders`; `/items*` → `imd_organize_files`; create,
rename, move → `imd_manage_folders`; `DELETE` and `/folders/restore` →
`imd_manage_folders` or `imd_delete_folders`, then `Permissions\can_delete()`
per folder (403 otherwise). File writes also check `edit_post` per attachment;
deleting files checks `delete_post`. The UI reads the same rules from
`config.can` (`store.js` `can`, `canDeleteFolder()`) and only hides what the
server would refuse.

| Method + path | Body | Returns |
| --- | --- | --- |
| `GET /folders` | — | `tree` |
| `POST /folders` | `name`, `parent` | `id`, `tree` |
| `POST /folders/{id}` | `name?`, `parent?` | `previous {name,parent}`, `tree` — refuses a parent inside its own subtree |
| `DELETE /folders/{id}` | `files` = `move`\|`delete`, `target` = `-1` parent / `0` none / `<id>` | `snapshot`, `previous` (file → folders), `target`, `moved`, `deleted[]`, `tree` |
| `POST /folders/restore` | `snapshot` | `tree` — re-inserts folders under their old IDs |
| `POST /items` | `ids[]`, `folder`, `mode` = `move`\|`add`\|`remove`, `from`, `nested` | `previous`, `current`, `tree` |
| `POST /items/set` | `map {attachmentId: folderIds[]}` | `current`, `tree` — used by undo |
| `POST /preferences` | any of `nested`, `tiles`, `reveal`, `perPostType` | all four (the user's effective preferences) |

`tree` = `{ folders: [{id, parent, name, count, total}], all, unassigned }`.
`count` = files directly in the folder, `total` = distinct files in its whole subtree.

`move` semantics: files leave every folder of the view they were dragged out of
(`from`, plus its subtree when `nested`), keep folders outside that view, and
join `folder`. From "All files"/"Not in a folder" (`from` = 0) it replaces all.

## JS (`src/`, built to `build/index.js` + `build/style-index.css`)

| File | Role |
| --- | --- |
| `index.js` | Entry. Global Cmd/Ctrl+Z, grid↔list switch keeps the folder, mounts `Notices` root, boots list view. |
| `store.js` | Shared state (`tree`, `nested`, `prefs`, `expanded`, `notices`, `deleting`); `can` / `canDeleteFolder()` (permissions); `setPref()`; `removeFolder()` (ask or delete); last-folder memory in localStorage (`imd:last-folder`, or `imd:last-folder:<typenow>` with per-content-type on) via `subscribe`/`getState`; tree helpers (`getChildren`, `getPath`, `isWithin`, `isVisibleIn`); server actions (`createFolder`, `renameFolder`, `moveFolder`, `deleteFolder`, `fileItems`, `setNested`, `refresh`); undo stack; snackbars. Notifies views through `registerView` handlers. |
| `view.js` | `createView()` — one per place that shows files (grid, each modal browser, list). Holds the *selected* folder and the *shown* folder (lags while loading); expands the tree to the opened folder when `prefs.reveal`. `getActiveView()` decides where uploads go. `folderProps()` builds query props. |
| `media.js` | Backbone patches: `AttachmentsBrowser.initialize/dispose` (mount sidebar + header, initial props, modal scroll fix), `load()` (prefetch-then-swap, no flicker), `Attachment.render` (folder badge), tile drag start, shift/cmd-click → bulk select, `wp.Uploader` folder stamping. |
| `list.js` | List view: builds the layout around `#posts-filter`, URL navigation, row dragging, hidden inputs that keep the folder through filter submits. |
| `drag.js` | Current drag payload (`files` or `folder`), custom drag image, `imd-is-dragging` body class. |
| `fit-panel.js` | Keeps the sidebar panel in the viewport and full height (replaces CSS sticky, see AGENTS.md). |
| `components/sidebar.js` | Tree: special rows, `FolderRow` (expand, inline rename, drag, context menu, keyboard), new-folder row, folder search, nested toggle, ⚙ `ViewOptions` menu, scroll to the open folder. |
| `components/content-header.js` | Breadcrumb, "N more files in subfolders" hint, subfolder tiles (drop targets, right-click `TileMenu` rename/delete, F2) and a `NewFolderTile`. Nothing on "All files"; tiles only with `prefs.tiles`. Follows the *shown* folder. |
| `components/delete-folder-modal.js` | "What happens to the files?" dialog. |
| `components/notices.js` | Snackbars + the delete dialog, rendered once per page above the media modal. |
| `components/common.js` | `useStore`, `useViewFolder`, `useDropTarget`, `NameInput`, folder icons. |
| `style.scss` | All styles; sections per area (panel/tree, grid, modal, list, header, tiles, snackbars, delete dialog). |

### Main flows

**Open folder (grid/modal).** `Sidebar` → `view.setFolder(id)` → `media.js`
`onChange` → `load(browser, folderProps)` builds a new `wp.media.model.Query`,
fetches page 1, then `props.set(…, {silent})` + `collection.mirror(query)` →
`view.setShown()` → `ContentHeader` re-renders with the files. Grid also
`history.replaceState`s `?imd_folder=`.

**Drag files onto a folder.** `dragstart` on `li.attachment` (or a list row) →
`drag.startFiles(ids, from)` → `useDropTarget` on a row/tile/crumb `onDrop` →
`store.fileItems()` → `POST /items` → `setState({tree})` + `eachView('onFiled')`
→ models get `imdFolders`, files no longer visible are removed from the
collection → snackbar with Undo → undo entry `POST /items/set previous`.

**Upload.** `drop`/file-input `change` (capture) stamps `pickedFolder` from the
active view → plupload `FilesAdded` copies it onto each file → `BeforeUpload`
puts it in `multipart_params.imd_folder` → `Admin\file_upload()` on
`add_attachment` → `UploadComplete` → `store.refresh()`.

**Delete folder.** Row menu / Delete key → `requestDelete(id)` (empty folders
skip straight to `deleteFolder`) → `DeleteFolderModal` → `DELETE /folders/{id}`
→ undo = `/folders/restore` + `/items/set previous` (not for deleted files).

## Tooling

| Path | What |
| --- | --- |
| `tools/smoke.php` | `npm run test:smoke` — WP-CLI end-to-end against the real DB. |
| `tools/check-translations.php` | `npm run test:i18n` — every locale in `languages/` complete, plural counts right, compiled files fresh. |
| `tools/check-version.php` | `npm run test:version` — version agrees in all places; prints it for CI. |
| `tools/check-docs.php` | `npm run test:docs` — this map covers every file, route and hook; agent pointer files point at `AGENTS.md`; doc links resolve. |
| `docs/VERIFY.md` | Agent-neutral verification recipes (checks + browser console snippets). |
| `CLAUDE.md`, `GEMINI.md` | Pointers to `AGENTS.md`, nothing else. |
| `.github/workflows/release.yml` | Release on version bump (tag with `build/`, zip with `vendor/`). |
| `.distignore` | The one exclude list for packaging (`vendor/` re-added only for the GitHub zip). |
| `.gitattributes` | `export-ignore` for Composer/GitHub source archives. |
| `.claude/` | Claude Code only: `skills/` (thin pointers to `docs/VERIFY.md` and AGENTS.md "Translations"), `settings.json` (allowed commands), `launch.json` (browser pane). |

## Dependencies

| Package | Kind | Why |
| --- | --- | --- |
| `yahnis-elsts/plugin-update-checker` | composer, runtime | Self-updates from GitHub releases (`includes/updater.php`). Shipped only in the GitHub zip. |
| `squizlabs/php_codesniffer`, `wp-coding-standards/wpcs`, `phpcompatibility/phpcompatibility-wp`, `dealerdirect/phpcodesniffer-composer-installer` | composer, dev | `npm run lint:php`. |
| `@wordpress/scripts` | npm, dev | Build, ESLint, Stylelint, Prettier. |
| `@wordpress/element`, `components`, `i18n`, `api-fetch`, `dom-ready`, `primitives`, `compose` | npm, externals | Provided by WordPress at runtime (`build/index.asset.php`); installed for lint/resolve. |
| `@wordpress/icons`, `clsx` | npm, bundled | Icons in the tree/menus; class names. |
| `typescript@5` | npm, dev | Only so `@wordpress/eslint-plugin`'s TS rules load (ESLint crashed without it). |

## How to

**Add a REST route.** Register in `REST\register_routes()` with
`permission_callback` (`can_manage` + per-attachment caps for file writes),
call only `Folders\…`, return `tree` and undo data. Add a store action in
`src/store.js` that `setState({ tree })`, notifies views, and `pushUndo`s.
Add a case to `tools/smoke.php` and a row to the REST table above.

**Change the database.** Edit the `CREATE TABLE` in `Schema\install()` (dbDelta
syntax: two spaces after `PRIMARY KEY`, one column per line), bump
`Schema\DB_VERSION`; `maybe_upgrade()` applies it on the next load. Update the
Data section and `uninstall.php` if a table is added.

**Add a setting.** Default in `Settings\get()`, sanitize in `register()`,
field in `render()`; per-user state as user meta (and delete it in
`uninstall.php`). README "Settings" + CHANGELOG.

**React to a data change in every view.** Call `eachView( 'onSomething', … )`
from the store action and implement `onSomething` in all three view types:
`src/media.js` (grid + modal), `src/list.js` (list). Add the name to the list in
AGENTS.md "Code rules".

**Add an import source.** For a taxonomy- or table-based plugin, add a
configured `Taxonomy_Source` / `Table_Source` to `Import\sources()` (or from
outside through the `isudev_media_directory/import_sources` filter); anything
else subclasses `Import\Source`. Never test against a real plugin's data —
`tools/smoke.php` uses a fake taxonomy and fake tables.

**Change permissions.** New action: a constant + label in
`Permissions\actions()`, a default in `role_defaults()`, the REST permission
callback, `for_js()` and the UI check through `can`.

**Add UI text.** Follow AGENTS.md "Translations" in the same change.

**After a WordPress update.** Walk the "Core couplings" table below in the real
admin (`docs/VERIFY.md`), plus `npm run check`.

## Core couplings (what a WordPress update can break)

| We rely on | Used in | Symptom when it breaks |
| --- | --- | --- |
| `wp.media.view.AttachmentsBrowser` (`initialize`, `dispose`, `collection.props`, `attachmentsWrapper`, `attachments.options.scrollElement`, `toolbar.get('spinner')`) | `media.js` | No sidebar, or sidebar in gallery-edit grids |
| `wp.media.model.Query.get`, `Attachments.mirror`, `Query.more` | `media.js load()` | Folder clicks do nothing or show stale files |
| `wp.media.view.Attachment` `initialize`/`render`, `li.attachment[data-id]`, `.attachment-preview` | `media.js` | No folder badges, tiles not draggable |
| Manage frame modes `grid`/`edit`/`select`, `activateMode`/`deactivateMode` | `media.js` shift-click | Shift-click opens the details modal |
| `wp.Uploader.prototype.init`, plupload `FilesAdded`/`BeforeUpload`/`UploadComplete`, `setOption('multipart_params')` | `media.js` | Uploads land outside the folder |
| `ajax_query_attachments_args` + raw `$_REQUEST['query']` (core whitelists keys) | `query.php` | Every folder shows all files |
| Modal CSS: `.attachments-browser .attachments` absolute at `top: 72px`, `right: 300px`; 900px breakpoint `top: 131px`, `right: 262px` | `style.scss` modal section | Header hidden behind files, overlap |
| Grid toolbar `.wp-filter` `margin: 12px 0`; `.media-frame` / browser `overflow: hidden` | `style.scss`, `fit-panel.js` | Sidebar misaligned, cut off |
| List table `#posts-filter`, `#the-list tr#post-<id>`, `input[name="media[]"]`, `.view-switch a` | `list.js`, `index.js` | List view without sidebar, rows not draggable |
| Media modal z-index 160000 | `style.scss` (`.imd-delete-overlay` 170000, snackbars 200000) | Dialog/snackbars behind the modal |
