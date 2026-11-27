# Guild — version 0.1.2

The WordPress plugin and repository are named `guild`. This version changes the admin UI from separate sidebar entries to one **Guild** menu with tabs:

- Overview
- Records (links to the native WordPress post editor and list)
- Item types (hierarchical terms)
- Sets (non-hierarchical terms)
- Storage locations (hierarchical terms)
- Pages (mapping the existing, real WordPress pages to their shortcodes)

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
