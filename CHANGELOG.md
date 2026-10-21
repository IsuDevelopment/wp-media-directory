# Changelog

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
