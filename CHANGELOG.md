# Changelog

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
