# Verify a change

How to prove a change works — automated checks, then the real admin. Works for
any agent or human: the browser steps need only a browser with a JS console
(an agent's browser tool, Playwright, or devtools).

## 1. Automated

```bash
nvm use && npm run build && npm run check
```

`check` = lint JS/CSS/PHP + version + translations + `tools/smoke.php` (needs
`wp-cli.local.yml`, see AGENTS.md). All green before going further. A new REST
route or repository function gets a case in `tools/smoke.php`.

## 2. In the admin (browser)

Open `<site>/wp-admin/upload.php?mode=grid` (maintainer's site:
`http://isudev-library.local`). If it shows the login page, ask the developer
to log in and wait — never type credentials. Reload after every build.

Browser quirks that cause false alarms (seen in embedded/agent browsers):

- A background tab has `document.visibilityState === 'hidden'`: rAF is
  throttled. Wait ≥1 s after scrolling or switching before measuring.
- Screenshots taken right after a click can show the previous frame (modal
  open animation). Trust DOM state read from the console, re-screenshot to see.
- Native `confirm()`/`alert()` may not show. The plugin must not use them.

Handles available on the page: `document.querySelector('.mode-grid .imd-has-folders').imdView`
(`getFolder()`, `setFolder(id | null | 'unassigned')`, `getShown()`, `browser`),
`wp.apiFetch` for REST, `wp.media({ multiple: 'add' }).open()` for a modal.

### Folder switch has no flicker

```js
const g = document.querySelector('.mode-grid .imd-has-folders');
const log = []; let on = true; const t0 = performance.now();
const snap = () => {
  const st = [g.querySelectorAll('ul.attachments li.attachment').length,
    Math.round(g.querySelector('.imd-panel').getBoundingClientRect().top),
    g.querySelector('.imd-crumbs')?.innerText.replace(/\n/g, '') || '-'].join('|');
  if (log.at(-1)?.st !== st) log.push({ t: Math.round(performance.now() - t0), st });
  if (on) requestAnimationFrame(snap);
};
snap(); g.imdView.setFolder(5); await new Promise(r => setTimeout(r, 1200)); on = false; log;
```

Pass: one transition (old count → new count), never a `0` frame in between,
panel top constant, breadcrumb changes in the same entry as the files.

### Drag files onto a folder, then undo

```js
const g = document.querySelector('.mode-grid .imd-has-folders');
const dt = new DataTransfer();
g.querySelector('li.attachment').dispatchEvent(new DragEvent('dragstart', { bubbles: true, dataTransfer: dt }));
const row = [...g.querySelectorAll('.imd-row')].find(r => r.textContent.includes('Mobilny'));
for (const t of ['dragenter', 'dragover', 'drop'])
  row.dispatchEvent(new DragEvent(t, { bubbles: true, cancelable: true, dataTransfer: dt }));
// later: undo
window.dispatchEvent(new KeyboardEvent('keydown', { key: 'z', metaKey: true, bubbles: true, cancelable: true }));
```

Check counts in `.imd-tree` innerText and the snackbar `.imd-notices` text
before and after.

### Upload lands in the folder picked at drop time

In a modal (`wp.media({ multiple: 'add' }).open()`): `imdView.setFolder(X)`,
build `File`s from a canvas, assign them to `.media-modal input[type=file]`
through a `DataTransfer`, dispatch `change`, immediately `setFolder(Y)`. After
the upload, `__f.uploader.uploader.uploader.files[i].imdFolder` must be `X`
and the DB must agree:

```bash
wp db query "SELECT p.post_title, f.name FROM wp_posts p JOIN wp_imd_folder_items i ON i.attachment_id = p.ID JOIN wp_imd_folders f ON f.id = i.folder_id ORDER BY p.ID DESC LIMIT 5"
```

### Layout

- Sidebar top equals the filter bar top (`.media-toolbar`) in grid and list.
- Panel bottom reaches the window bottom; the tree scrolls inside it.
- After `scrollTo(0, 500)` the panel top is 44 px (admin bar + 12).
- Modal: header (breadcrumb/tiles/hint) visible above "No items found".
- Check the pane at desktop width and at 782 px or less (stacked layout).

### Clean up

Delete test folders and files you created (REST `DELETE /folders/{id}` with
`files=move`), and say in the report what test data remains.
