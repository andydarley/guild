# Guild — WordPress catalogue

Version 0.1.5. Requires WordPress 6.4+, PHP 8.0+.

## Installation / upgrade

Install `guild-v0.1.5.zip` over the existing `guild` plugin, or deploy the replacement files through Git. Back up the site before updating. Do not deactivate or uninstall to upgrade. Existing records, permanent IDs, taxonomies, page mappings and shortcodes are preserved.

## Pages

Create ordinary published WordPress Pages, each with a Shortcode block, then match them in **Guild → Pages**:

| Function | Suggested page slug | Shortcode |
|---|---|---|
| Catalogue / search | `/census/` (or `/` as homepage) | `[gob_census]` |
| Individual entry | `/census-entry/` | `[gob_entry]` |
| Add/edit form | `/census-edit/` | `[gob_form]` |

Individual record URLs use `?record=GOB-000001` on the assigned entry Page. No virtual frontend routes or rewrite rules are required.

## Admin

The **Guild** admin menu contains one dashboard entry. **Records** links straight to the native `Census records` post list, which already has an **Add New** button. Other dashboard tabs manage item types, sets, storage locations, importing/exporting and page assignments.

## Form

The frontend form supports name, photograph, notes, one item type, multiple sets and one storage location. Types and locations are hierarchical; sets are not. Define taxonomy terms on their Guild dashboard tabs. Inline term creation is not part of 0.1.4.

The original iOS photo safety advice and recoverable upload errors remain. Take photos in the Camera app first so an independent copy exists in Photos. The browser checks selected photo file size before submission; PHP upload limits also apply.

## Privacy

The plugin does not secure static Media Library files. Protect the entire installation including direct image URLs with cPanel Directory Privacy / Apache and HTTPS; verify in a logged-out/private browser.

## Source layout

- `guild.php` — bootstrapping and asset registration
- `includes/post-types.php` — post type and permanent ID operations
- `includes/taxonomies.php` — taxonomy management
- `includes/admin.php` — Guild dashboard and page settings
- `includes/forms.php` — frontend create/edit and upload handling
- `includes/frontend.php` — display, URLs, search and access checks
- `assets/css/guild.css` — minimal structural layout; theme owns appearance
- `assets/js/guild-form.js` — client-side photograph upload size check
- `includes/import-export.php` — spreadsheet preview, batch imports and CSV exports

Not included yet: group-photo association or inline term creation.

## Version 0.1.5 — Import and export

The single Guild dashboard has an **Import / Export** tab (available to WordPress administrators). The Guild admin icon is `dashicons-pets`.

### Spreadsheet columns

The first row must include **ID** and **Name**. Optional columns are **Type**, **Sets**, **Location** and **Notes**. CSV exports additionally contain **Primary image** (a URL for reference; it is ignored on reimport). The importer accepts `.xlsx` (first worksheet only, ZIP and SimpleXML PHP extensions required) or UTF-8 `.csv`. It does not support the old `.xls` format.

- Existing **GOB-000001**-style IDs: update that record. Unknown IDs produce errors; they are never used to create records.
- Empty IDs: create new records and allocate permanent IDs automatically.
- Omitting a column or leaving a cell blank: preserve that existing value. Clearing values through an import is not part of this release.
- **Type** and **Location** must refer to existing taxonomy terms, by unique name or full hierarchical path such as `Study → Cabinet → Top shelf`.
- **Sets** can list several existing set names separated by a vertical bar (`|`); no new terms are created automatically.
- Names are not used to identify existing records.
- Photos and existing featured-image assignments are never modified by import.

### Safety and workload

The import displays every planned action or error for confirmation **before writing anything**. Invalid rows are skipped, not applied. Once confirmed, batches of **up to 25 rows** are processed only when the administrator clicks the next-batch control. The process has no background jobs. Maximum 2 MB upload, 500 data rows per import. The resulting report CSV records assigned IDs and individual failures. Keep the browser open and complete the batches; imports are not transactional, and already-processed batches are not rolled back if you stop.

The temporary preview and report are held in a user-specific WordPress transient for one hour. Do not put sensitive data into spreadsheets if you are using a shared object-cache service. No third-party services are contacted. Standard cPanel Directory Privacy protects this entire installation and its WordPress-hosted images; Guild does not change Apache security settings.

CSV exports are UTF-8 with an Excel-friendly BOM. Spreadsheet-formula-leading characters are escaped with an apostrophe on export, and that escape is understood by Guild on reimport. The current export does not include binary photograph files. Before large imports, make a backup of the database.

### Upgrading

Replace the complete `guild/` plugin directory or upload `guild-v0.1.5.zip` over the installed version. Do **not** uninstall the old plugin first. Existing post types, metadata keys, shortcodes, page assignments and images are unchanged. No new WordPress pages or rewrite rules are needed.
