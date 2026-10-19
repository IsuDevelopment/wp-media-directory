---
name: update-translations
description: Add or change user-facing strings in IsuDev Media Directory and keep the Polish translation complete (POT, pl_PL PO/MO/l10n.php and the JS JSON). Use whenever a change adds, edits or removes a __()/_n()/sprintf string in PHP or src/.
---

# Update translations

1. Write strings in English with the `isudev-media-directory` domain. Counts use
   `_n()`. Placeholders get a `/* translators: */` comment on the line above.
   Typographic quotes `“…”` in English, `„…”` in Polish.
2. Regenerate and merge:

   ```bash
   nvm use
   npm run build            # JS strings are read from build/index.js
   npm run i18n:make-pot
   npm run i18n:update-po
   ```

3. Translate every new or fuzzy entry in
   `languages/isudev-media-directory-pl_PL.po`. Polish plurals:
   `msgstr[0]` 1 → „1 plik”, `msgstr[1]` 2–4, 22–24 → „3 pliki”,
   `msgstr[2]` 0, 5–21, 25+ → „5 plików”. Match WordPress core pl_PL wording
   (e.g. "Cancel" → „Anuluj”, "Delete" → „Usuń”, "Media Library" →
   „Biblioteka mediów”). Remove `#, fuzzy` flags once checked.
4. Compile and check:

   ```bash
   npm run i18n:build
   npm run test:i18n
   ```

5. Spot-check with a forced locale (the local site has no Polish core pack):

   ```bash
   wp eval 'add_filter("determine_locale", fn() => "pl_PL"); load_textdomain("isudev-media-directory", IsuDevMediaDirectory\PATH . "languages/isudev-media-directory-pl_PL.mo", "pl_PL"); printf(_n("%d file", "%d files", 22, "isudev-media-directory"), 22);'
   ```

Commit `.pot`, `.po`, `.mo`, `.l10n.php` and the `.json` together with the code.
