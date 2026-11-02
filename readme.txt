=== IsuDev Media Directory ===
Contributors: isudev
Tags: media library, folders, media folders, organize media, file manager
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.4.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Simple, fast folders for the Media Library. Files stay visible across the folder tree, every move can be undone, and folders work in every media dialog.

== Description ==

IsuDev Media Directory adds a folder tree to the Media Library (grid and list view) and to every media dialog, for example when you pick an image in the block editor. It is built for people who just want their files in order, without learning a new tool.

Folders are virtual: files never move on disk and their URLs never change.

= Features =

* **Folder tree** next to the files in the Media Library and in every "Add media" dialog.
* **Show files from subfolders**: a parent folder shows everything below it, and each file says which folder it lives in.
* **Breadcrumb and subfolder tiles** above the files, so a folder never looks empty while its subfolders hold files.
* **Drag and drop** files, or a whole selection, onto a folder, a tile or the breadcrumb. Hold Option / Alt to keep them in their current folder as well.
* **Undo everything** with Cmd+Z / Ctrl+Z or the Undo button in the notice: moving files, creating, renaming, moving and deleting folders.
* **Uploads go into the open folder**. On Media → Add Media File, pick the folder in "Upload to folder".
* **Folder colors**, rename, move and delete from the right-click menu.
* **Download a folder** as a ZIP with its original files; subfolders become folders in the ZIP.
* **Permissions per role**: who can see folders, organize files, create and delete folders.
* **Import** from HappyFiles, FileBird, Real Media Library, Folders, Enhanced Media Library, WP Media Folder and Wicked Folders. The other plugin's data is never changed.
* **Remembers where you were**: the last open folder, open branches of the tree and your view options.
* **WP-CLI**: `wp media-directory list`, `import`, `cleanup`, `permissions`.
* Translated into Polish, German and Norwegian (Bokmål).

= Source code =

The JavaScript and CSS in `build/` are compiled with `@wordpress/scripts` from the human-readable sources in `src/`, which ship with the plugin. To rebuild them, run `npm ci && npm run build` in the plugin folder (Node 22).

== Installation ==

1. In wp-admin go to Plugins → Add New, search for "IsuDev Media Directory", then install and activate it.
2. Open Media → Library. The folder tree is on the left.
3. Optional: Media → Folder settings for site defaults, role permissions and importing folders from another plugin.

== Frequently Asked Questions ==

= Does it move my files or change their URLs? =

No. Folders are virtual. Files stay where WordPress put them and their URLs never change.

= Can I switch from another media folders plugin? =

Yes. Media → Folder settings → Import copies folders and file assignments from HappyFiles, FileBird, Real Media Library, Folders, Enhanced Media Library, WP Media Folder and Wicked Folders. It works with that plugin deactivated and never changes its data.

= Who can manage folders? =

Media → Folder settings → Permissions sets per role who can see folders, organize files, create and rename folders, and delete them. Administrators can always do everything.

= What happens when I delete the plugin? =

Deactivating keeps everything. Deleting the plugin removes its folders, settings and user preferences. Your media files are never touched.

= Does the folder download need anything special? =

It needs the PHP zip extension, which most hosts have.

== Screenshots ==

1. The folder tree next to the Media Library grid, with breadcrumb and subfolder tiles.
2. Picking an image from a folder in the block editor's media dialog.
3. Folder settings: role permissions.
4. Importing folders from another plugin.

== Changelog ==

= 0.4.2 =
* First wordpress.org release.

= 0.4.1 =
* Media → Add Media File has an "Upload to folder" field; it starts at the folder you used last.
* Folder counts in the media dialog are right after a file is uploaded straight onto an Image block or as a featured image.

= 0.4.0 =
* Download a folder as a ZIP.
* Folder colors.
* Hide the folder panel for more room.
* Files uploaded while a folder is open appear in it right away.

= 0.3.0 =
* List view switches folders without reloading the page.
* New folder panel header and toolbar.

= 0.2.0 =
* Permissions per role, imports from other folder plugins, view options, German and Norwegian.

= 0.1.0 =
* First release.
