# IsuDev Media Directory

Simple folders for the WordPress Media Library — built to replace HappyFiles Pro.

- Folder tree next to the files in the Media Library (grid and list) and in every
  media modal, e.g. when picking an image in the block editor.
- **Show files from subfolders**: a parent folder shows everything below it, each
  file labelled with the folder it lives in. The switch is above the tree and is
  remembered per user.
- Breadcrumb and subfolder tiles above the files; a folder never looks empty
  when its subfolders hold files.
- Drag files (or a whole selection) onto a folder, a tile or the breadcrumb.
  Hold ⌥ Option / Alt to keep them in their current folder as well.
- Shift / Cmd-click in the grid selects ranges without pressing "Bulk select".
- Undo everything with ⌘Z / Ctrl+Z or the Undo button in the notice.
- Uploads go into the open folder, even if you click another folder mid-upload.
- Right-click a folder for New subfolder / Rename / Change color / Download /
  Delete. Download gives a ZIP of the folder (original files, subfolders
  included) named after it; it needs the PHP zip extension.
  Colors come from a preset palette or any custom color. Deleting a folder
  asks what to do with its files: move them up, move them to another folder, or
  delete them.
- Permissions per role: who can see, organize, create and delete folders.
- Import from HappyFiles, FileBird, Real Media Library, Folders, Enhanced Media
  Library, WP Media Folder and Wicked Folders.
- **Remembers where you were.** The last open folder, the "Show files from
  subfolders" switch and which branches of the tree are open are remembered,
  so the Media Library and every "Add media" dialog (e.g. a featured image in a
  post) open where you left off. Optionally per content type — one folder for
  products, another for posts.
- **⚙ View options** above the tree, per user: show subfolders as tiles above
  the files (with a "New folder" tile; right-click a tile to rename or delete),
  open the current folder in the tree, remember the folder per content type.
- **Hide folders** — the last button in the panel toolbar hides the tree for
  more room; “Show folders” before the breadcrumb brings it back.
- English, Polish, German and Norwegian (Bokmål).

Requires WordPress 7.0+ and PHP 7.4+. Folders are virtual: files never move on
disk and their URLs never change.

## Settings

Media → **Folder settings**

Only administrators (`manage_options`) can open this page.

- **General** — defaults for everyone: show files from subfolders, show
  subfolders above the files, open the current folder in the tree, remember the
  folder per content type (off by default). Each user can change these for
  themselves in the ⚙ menu above the folder tree; their choice wins.
- **Permissions** — per role (roles that can upload files):
  *See folders*, *Organize files* (move files, upload into a folder),
  *Create and rename* (also move folders), *Delete folders*. Administrators
  always have everything; by default editors have everything, authors all but
  deleting, other roles see and organize. Empty folders can be deleted by
  anyone who can create them. Role editor plugins (Members, User Role Editor)
  can grant or deny the capabilities `imd_view_folders`, `imd_organize_files`,
  `imd_manage_folders`, `imd_delete_folders` to single users.
- **Import** — copy folders and file assignments from HappyFiles, FileBird,
  Real Media Library, Folders (Premio), Enhanced Media Library, WP Media Folder
  or Wicked Folders. Works with that plugin deactivated and never changes its
  data. Choose *Merge with my folders* (same name in the same place = one
  folder) or *Put everything in a new folder*. HappyFiles and FileBird folder
  colors come along. From HappyFiles you can also copy
  folder access per role. Imports can be repeated; they only add what is
  missing. Afterwards, with the other plugin deactivated, you can delete its
  folder data. While such a plugin is active, a notice offers to import and
  deactivate it.

## WP-CLI

```bash
wp media-directory list                          # folders with direct and total counts
wp media-directory sources                       # plugins to import from, with counts
wp media-directory import happyfiles --permissions
wp media-directory import filebird --mode=separate
wp media-directory cleanup filebird --yes        # delete FileBird's folder data
wp media-directory permissions                   # the role matrix
```

## Installing and updating

Download `isudev-media-directory-<version>.zip` from the
[GitHub releases](https://github.com/IsuDevelopment/wp-media-directory/releases)
and upload it in Plugins → Add New. Installs from the zip update themselves from
new releases like any other plugin. Composer installs (`type: vcs` on a tag) are
updated through Composer.

## Uninstall

Deactivating keeps everything. Deleting the plugin drops its two tables
(`{prefix}imd_folders`, `{prefix}imd_folder_items`), its options and user
preferences. Media files are never touched.

## Development

See [`AGENTS.md`](AGENTS.md) and [`docs/CODEMAP.md`](docs/CODEMAP.md).
