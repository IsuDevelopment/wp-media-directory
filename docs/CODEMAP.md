# Code map

Where everything lives and how a click turns into SQL. Read with
[`AGENTS.md`](../AGENTS.md), which holds the rules.

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
| `settings.php` | `…\Settings` | Media → Folder settings page, `nested` default, per-user toggle meta, HappyFiles import form handler. |
| `import.php` | `…\Import` | HappyFiles → our tables. Reads `happyfiles_category` terms with SQL (works with HappyFiles off). Idempotent via `import_ref = happyfiles:<term_id>`. |
| `updater.php` | `…\Updater` | Plugin Update Checker against GitHub releases; boots only from a release zip (see `docs/RELEASE.md`). |
| `class-command.php` | `…\CLI\Command` | `wp media-directory import-happyfiles`, `wp media-directory list`. |
| `uninstall.php` | — | Drops both tables, options and user meta. Only on delete, never on deactivate. |

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
| `isudev_media_directory_settings` | option | `{ nested: bool }` — site default for the subfolder toggle. |
| `isudev_media_directory_nested` | user meta | `'1'` / `'0'`; empty = use the site default. |

Query vars (raw request keys, constants in `Query`): `imd_folder` (`<id>` or
`unassigned`; absent = all files) and `imd_nested` (`1`/`0`).

### REST — `isudev-media-directory/v1`

All routes require `upload_files`. File writes additionally check
`edit_post` per attachment; deleting files checks `delete_post`.

| Method + path | Body | Returns |
| --- | --- | --- |
| `GET /folders` | — | `tree` |
| `POST /folders` | `name`, `parent` | `id`, `tree` |
| `POST /folders/{id}` | `name?`, `parent?` | `previous {name,parent}`, `tree` — refuses a parent inside its own subtree |
| `DELETE /folders/{id}` | `files` = `move`\|`delete`, `target` = `-1` parent / `0` none / `<id>` | `snapshot`, `previous` (file → folders), `target`, `moved`, `deleted[]`, `tree` |
| `POST /folders/restore` | `snapshot` | `tree` — re-inserts folders under their old IDs |
| `POST /items` | `ids[]`, `folder`, `mode` = `move`\|`add`\|`remove`, `from`, `nested` | `previous`, `current`, `tree` |
| `POST /items/set` | `map {attachmentId: folderIds[]}` | `current`, `tree` — used by undo |
| `POST /preferences` | `nested` | `nested` |

`tree` = `{ folders: [{id, parent, name, count, total}], all, unassigned }`.
`count` = files directly in the folder, `total` = distinct files in its whole subtree.

`move` semantics: files leave every folder of the view they were dragged out of
(`from`, plus its subtree when `nested`), keep folders outside that view, and
join `folder`. From "All files"/"Not in a folder" (`from` = 0) it replaces all.

## JS (`src/`, built to `build/index.js` + `build/style-index.css`)

| File | Role |
| --- | --- |
| `index.js` | Entry. Global Cmd/Ctrl+Z, grid↔list switch keeps the folder, mounts `Notices` root, boots list view. |
| `store.js` | Shared state (`tree`, `nested`, `expanded`, `notices`, `deleting`) via `subscribe`/`getState`; tree helpers (`getChildren`, `getPath`, `isWithin`, `isVisibleIn`); server actions (`createFolder`, `renameFolder`, `moveFolder`, `deleteFolder`, `fileItems`, `setNested`, `refresh`); undo stack; snackbars. Notifies views through `registerView` handlers. |
| `view.js` | `createView()` — one per place that shows files (grid, each modal browser, list). Holds the *selected* folder and the *shown* folder (lags while loading). `getActiveView()` decides where uploads go. `folderProps()` builds query props. |
| `media.js` | Backbone patches: `AttachmentsBrowser.initialize/dispose` (mount sidebar + header, initial props, modal scroll fix), `load()` (prefetch-then-swap, no flicker), `Attachment.render` (folder badge), tile drag start, shift/cmd-click → bulk select, `wp.Uploader` folder stamping. |
| `list.js` | List view: builds the layout around `#posts-filter`, URL navigation, row dragging, hidden inputs that keep the folder through filter submits. |
| `drag.js` | Current drag payload (`files` or `folder`), custom drag image, `imd-is-dragging` body class. |
| `fit-panel.js` | Keeps the sidebar panel in the viewport and full height (replaces CSS sticky, see AGENTS.md). |
| `components/sidebar.js` | Tree: special rows, `FolderRow` (expand, inline rename, drag, context menu, keyboard), new-folder row, folder search, nested toggle. |
| `components/content-header.js` | Breadcrumb, "N more files in subfolders" hint, subfolder tiles (all drop targets). Follows the *shown* folder. |
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
| `tools/check-translations.php` | `npm run test:i18n` — pl_PL complete, compiled files fresh. |
| `tools/check-version.php` | `npm run test:version` — version agrees in all places; prints it for CI. |
| `.github/workflows/release.yml` | Release on version bump (tag with `build/`, zip with `vendor/`). |
| `.distignore` | The one exclude list for packaging (`vendor/` re-added only for the GitHub zip). |
| `.gitattributes` | `export-ignore` for Composer/GitHub source archives. |
| `.claude/` | `skills/` (verify, translations), `settings.json` (allowed commands), `launch.json` (browser pane). |

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
