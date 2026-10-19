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
- Right-click a folder for New subfolder / Rename / Delete. Deleting a folder
  asks what to do with its files: move them up, move them to another folder, or
  delete them.
- English and Polish.

Requires WordPress 7.0+ and PHP 7.4+. Folders are virtual: files never move on
disk and their URLs never change.

## Settings

Media → **Folder settings**

- **Subfolders** — whether "Show files from subfolders" starts on for users who
  have not touched the switch yet.
- **Import from HappyFiles** — copies the HappyFiles folder tree and which file
  sits where. HappyFiles data is not changed; running it again only adds what is
  missing. Works with HappyFiles deactivated.

## WP-CLI

```bash
wp media-directory import-happyfiles   # same as the settings button
wp media-directory list                # folders with direct and total counts
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
