# Changelog

[Русский](CHANGELOG.md) · [Қазақша](CHANGELOG.kk.md) · **English**

## 2.0.1 — 2026-10-22

### Fixed

- The panel labels "Image Class", "Figure Class" and "Caption" now come from the plugin's own language files. Before, the panel took them from the Joomla language pack, and the Russian Joomla 6.0 pack has no translation for the figure class: the `COM_CONTENT_FIELD_FIGURE_CLASS_LABEL` constant or the English "Figure Class" showed up instead of a label.

### Documentation

- Russian screenshots retaken with the 2.0.1 panel and the Russian Joomla 6.2 pack.

## 2.0.0 — 2026-10-22

### Changed

- New author and maintainer: Beibit Sakhanov, webmarka.kz. The class namespace is now `Webmarka\Plugin\System\ContentImageUpload` (was `Khabar\…`), hence the major version. File headers and the manifest's author, URL and copyright are updated; the line about the original 1.6 version stays, as the GNU GPL requires.
- The license points to `LICENSE` with the full text of the GNU GPL 2.

### Added

- An update server: Joomla offers new versions from the `bsakhanov/plg_system_contentimageupload` repository on GitHub by itself.
- Besides the install package, each release carries an archive to unpack straight into the site root; the plugin is then registered with "System → Install → Discover".
- A `build.sh` script builds both archives from the repository; documentation and service files stay out of the install package.

### Updating from 1.x

- Version 2.0.0 installs over 1.x: settings and watermark originals are kept, and Joomla rebuilds the namespace map itself.

## 1.9.0 — 2026-10-21

### Added

- Drag and drop upload. A file dropped onto the left half of the panel is uploaded exactly as with the "Upload" button; the zone lights up with a dashed outline and a hint sits under the buttons. Files of other formats are turned away in the browser and never reach the server.
- Protection from stray drops: a file released outside the panel no longer opens in the browser tab or takes you away from an unsaved article.
- The "JUImage thumbnail folders" setting (`img` by default). JUImage names a thumbnail after the source file and never checks whether the source changed, so after a watermark change the site would keep showing the old picture. Now, after adding or removing a watermark, the plugin deletes the thumbnails of that image, and the template — wmarka, for instance — draws them again. The `images` folder, `..` and paths outside the site are refused.

### Changed

- The panel buttons moved to a row of their own under the path field and got icons; "Clear" is now an outline button. Before, the path and three buttons were squeezed into one line in a narrow column.
- The Russian upload button now reads «Загрузить», a verb like «Выбрать» and «Очистить».
- The "Image uploaded" line is cleared when the panel image is replaced by picking from the Media Manager, so a message about an earlier upload no longer misleads.
- Translation terms follow the official Joomla 6 language packs: «изображение вводного текста» and «медиа-менеджер» in Russian, «мақала» and «медиа менеджері» in Kazakh. "Uploading…" uses a single ellipsis character.
- The plugin description in the extension list mentions the watermark.

### Documentation

- A detailed guide with screenshots in three languages: `README.md`, `README.kk.md`, `README.en.md`, images in `docs/images`.
- The changelog in three languages.

## 1.8.0 — 2026-10-21

### Added

- The "One image for the intro and full article" switch in the panel and the "Image sync" setting with three modes: switch on by default, switch off by default, or "Always, no switch" — the behaviour of earlier versions.
- With sync off, a separate full article image gets its own "Watermark" box under the native field. The server handles it independently of the panel box.

### Changed

- When an article opens, the switch follows the data: the same image in both fields means on, different images or an empty field mean off. A new article takes the default.
- With sync off, the panel works with the intro image only, and the native full article field stays visible even when native fields are hidden.
- Turning sync on makes the full article image a copy of the intro image together with its description, classes and caption.
- Description, classes and caption changed in the native fields stay the same in both images while sync is on.

### Fixed

- The plugin no longer overwrites the full article image of older articles where it differs from the intro image; before, any image change did that.
- The image can be changed in either native field and the other one follows. Before, a change in the full article field was reverted at once.

## 1.7.1 — 2026-10-20

### Fixed

- Image fields get the value in the form Joomla writes itself: the path plus `#joomlaImage://local-images/…?width=…&height=…`. Before, the plugin wrote a bare path, and `<img>` on the site came out without `width`, `height` and `loading="lazy"`, so the layout jumped while the page loaded. Articles uploaded with 1.6 and 1.7.0 get the sizes the next time they are opened and saved.
- When a path is typed into a native field, both fields get the value with the size after the check.
- "Date + hash" names follow the user's time zone, like the folders.
- "PNG compression" is also shown with "Keep original": it compresses PNG sources as well.

### Documentation

- A detailed README: upload, differences from the Media Manager, all settings.

## 1.7.0 — 2026-10-20

### Fixed: watermark

- The watermark was not applied on save. The script looked for the form as `#adminForm`, while the article form in Joomla 4–6 has `id="item-form"`, so the hidden fields were never created and the server returned early. The box fields now live inside the panel itself.
- Uploading with the box ticked failed: a non-existent `setWatermarkPending()` method was called and the uploaded file was then deleted. Now the watermark is stamped right on upload; if that fails, the image is still uploaded and the editor sees a warning.
- The box was always unticked when an article opened, so simply re-saving would have removed the watermark. Now it shows the real state of the file.
- An unticked box restores the original byte for byte, a ticked one stamps the watermark, and re-saving without changes leaves the file alone.
- The watermark is handled after the article is stored: a failed save never touches the file, and a watermark failure gives a warning instead of "save failed".
- One GD watermark engine instead of two. Opacity multiplies the alpha channel, so soft logo edges stay soft; the mark shrinks when it does not fit with its margins. Fixed the PHP warning about the `margin` key.

### Security

- Originals moved from `administrator/cache` to the closed `images/.contentimageupload/` folder with HMAC names. In the cache they were wiped by expired-cache cleaning, and the copy's name was derived from the public image address, so anyone could download a clean original. Copies left by 1.6 are picked up automatically.

### Fixed: other

- Phone photos are rotated by EXIF both on upload and when stamping. Before, re-encoding dropped the rotation tag and the photo ended up sideways.
- PHP memory is checked before processing: the editor sees a clear error instead of a blank page.
- The date in the upload folder follows the user's time zone, not UTC.
- Russian and Kazakh Cyrillic in file names is transliterated whatever the administrator language.
- After an image changes, its Media Manager thumbnail is deleted and drawn again.

### Changed

- The code follows the Joomla 6 standard: a provider with `lazy()` and `setApplication()` without the deprecated dispatcher argument, `DatabaseAwareTrait`, typed events, a log file of its own at `administrator/logs/plg_system_contentimageupload.php`.
- `script.php` was rewritten as a service provider: the old set of functions was never called by the Joomla installer. Migration of old settings is kept; automatically disabling the third-party WT Image Resize plugin is gone.

### Removed

- The outdated `PRODUCTION-AUDIT.md`. Versions in the manifest and assets are now the same.

## 1.6.13 and earlier

The original branch (© 2026 Хабар): one upload into both article fields, processing through Joomla Image, variables in the folder format, the first version of the watermark.
