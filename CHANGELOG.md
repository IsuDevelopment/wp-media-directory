# Changelog

## 0.4.0 — 2026-10-26

- Download a folder: right-click a folder (or ⋮) → Download gives a ZIP named
  after the folder with its original files; subfolders become folders in the
  ZIP.
- Folder colors: right-click a folder (or ⋮) → Change color. Pick a preset,
  any custom color or Clear; Undo works as usual. HappyFiles and FileBird
  imports bring their folder colors along.
- Hide the folder panel with the button at the right of its toolbar; “Show
  folders” before the breadcrumb brings it back. Remembered in the browser,
  applies to the Media Library, list view and media dialogs.
- Files uploaded or dropped while a folder is open now appear in it right
  away, with upload progress, instead of only after a page reload.

## 0.3.0 — 2026-10-21

- List view: switching folders or the subfolder switch no longer reloads and
  flashes the page; only the rows and pagination change. (0.2.0 tried this but
  an invalid selector made it fall back to a full reload every time.)
- Folder settings: every tab uses the same white dashboard panels.
- List view: even 12px spacing between breadcrumb, subfolder tiles and the
  filter bar.
- Folder panel header: the title on its own line, under it a compact toolbar
  with expand all, collapse all, "Show files from subfolders" as a pressed
  icon button, New folder and ⚙.
- Grid: "Subfolders (n)" and "Files from subfolders" toggles (same pressed
  look) and a "New folder" button at the right of the breadcrumb.
- Infinite scrolling in the grid and media modals on WordPress 7.0 too (core
  only defaults to it from 7.1, where a user's profile opt-out is respected).

## 0.2.0 — 2026-10-21

- Permissions: a role × action matrix (see, organize files, create and rename,
  delete folders) in Media → Folder settings → Permissions, enforced on the
  server; the folder UI hides what a user may not do.
- Import from FileBird, Real Media Library, Folders (Premio), Enhanced Media
  Library, WP Media Folder and Wicked Folders, next to HappyFiles.
- Import preview, merge-or-separate mode, HappyFiles folder access → Permissions,
  and deleting the old plugin's folder data afterwards.
- Notice while another folder plugin is active, with import and deactivate buttons.
- WP-CLI: `sources`, `import <source>`, `cleanup <source>`, `permissions`.
- ⚙ View options per user (site defaults in Folder settings → General): show
  subfolders above the files, open the current folder in the tree, remember the
  last folder per content type (opt-in).
- Subfolder tiles: a "New folder" tile, right-click to rename or delete; no
  tiles on "All files".
- German and Norwegian (Bokmål) translations.

## 0.1.0 — 2026-10-19

First version.

- Folder tree in the Media Library (grid and list) and in every media modal.
- Files from subfolders visible in parent folders (per-user switch, site default
  in Media → Folder settings), with a folder badge on each file.
- Breadcrumb, subfolder tiles and a "files in subfolders" hint above the files.
- Drag and drop for files (move, ⌥/Alt to add) and folders; context menu on
  right-click; keyboard: arrows, F2, Delete.
- Undo (⌘Z / Ctrl+Z and snackbar) for every reversible change.
- Shift / Cmd-click range selection in the grid.
- Uploads filed into the open folder, stamped at drop time.
- Delete-folder dialog: move files up, to another folder, or delete them.
- No flicker when switching folders: the next page loads before it replaces
  the current one.
- HappyFiles import (settings button and WP-CLI), safe to repeat.
- Polish translation.
- Self-updates from GitHub releases on sites installed from the release zip.
