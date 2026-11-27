# Guild — version 0.1.3

## Photo-upload failure recovery

This release fixes the frontend photograph failure workflow introduced in earlier versions. The previous handler saved the census record and then displayed an unrecoverable WordPress error page when a photo failed to upload.

- Before submission, the form now checks the selected image size against the effective PHP/WordPress upload limit and blocks submissions that exceed it. The exact limit is shown near the image input. This browser check is advisory and is not a substitute for server-side limits.
- If PHP or WordPress reports an upload failure *after the census record is saved*, the browser redirects to the existing record's edit page and displays an error and retry guidance. It does not create a duplicate record, and it does not replace any existing primary image on failure.
- Removed the `capture="environment"` attribute. The form recommends taking photographs in the iOS Camera app first so a failed upload cannot destroy the sole copy. Selecting a photo from Photos is still supported.
- Successful saves still return to the individual record page.

**Server setup:** In cPanel's MultiPHP INI Editor (or applicable PHP settings), check `upload_max_filesize` and `post_max_size`; the latter must be larger than the former. Large uploads can also be constrained by web-server/WAF limits. An image above `post_max_size` may be rejected by PHP *before* the plugin receives the form, so the browser-side limit check is important. The plugin does not resize photographs automatically in this version.

## Updating

Install this ZIP to update the existing plugin in `wp-content/plugins/guild/`. No database changes, page changes or ID changes are needed. Existing records and settings are preserved. Test on iOS using a small photograph and then a deliberately oversized file (which should be rejected *before* submission).

## IMPORTANT: migrating from `guild-census` 0.1.1

Because the plugin directory is now `guild` rather than `guild-census`, WordPress treats it as a different plugin. **Deactivate Guild Census 0.1.1 before activating Guild 0.1.2**. Do **not** select Delete for the old plugin until the new one has been activated and checked. The plugin has no deactivation or uninstall data-removal code.

1. Make a routine backup of the database/files.
2. Deactivate **Guild Census** (do not delete it yet).
3. Upload `guild-v0.1.2.zip` via Plugins → Add New → Upload Plugin.
4. Activate **Guild**.
5. Go to **Guild** in WordPress admin. Confirm that existing page mappings, records and terms remain intact.
6. After confirming it works, the old, deactivated `guild-census` folder can be removed.

This release keeps internal post types, taxonomy slugs, shortcodes, option keys and permanent GOB IDs unchanged. No database migration is required.

## Page mapping

On the **Guild → Pages** tab, select published pages containing the indicated shortcodes:

| Purpose | Example real Page | Shortcode |
|---|---|---|
| Catalogue and search | Census / site homepage | `[gob_census]` |
| Individual record | Census entry | `[gob_entry]` |
| Add or edit record | Census edit | `[gob_form]` |

The `Pages` tab checks selected pages for their shortcode. On **Settings → Permalinks**, `Post name` is recommended. Existing URLs and pages need not be changed.

## Creating storage locations

Open **Guild → Storage locations** (the tab inside the dashboard, not a separate sidebar item). Enter a location name, optionally select a parent, and save. Nested terms are shown as paths, for example `Study → Bookcase → Top shelf`. The entry form uses those paths when selecting a location.

Other categories are managed through the **Item types** and **Sets** tabs. Records can still be edited with the native WordPress post editor through links on the **Records** tab.

## Privacy

The plugin itself does not protect media files served through direct web URLs. Secure the installation, including `/wp-content/uploads/`, at the server level (for example cPanel Directory Privacy), and test direct image URLs without authentication before adding private images.

## Scope of this release

No new import/export or image tagging features yet. No frontend stylesheet is supplied: the active theme controls the frontend appearance. WordPress admin styling is used for dashboard tabs, tables and controls.

The code has been checked with `php -l`, but has not been integration-tested within a running WordPress instance.
