# Guild Census v0.1.1

First installation milestone. No external libraries, hosted assets, tracking or third-party accounts.

## Installation

1. Back up / test on a fresh private WordPress installation (PHP 8.0+).
2. Upload `guild-census-v0.1.1.zip` through **Plugins → Add New → Upload Plugin**, and activate it.
3. Under **Settings → Permalinks**, choose **Post name** (`/%postname%/`) and **Save Changes**.
4. Under **Pages → Add New**, create and **publish** these three *real WordPress Pages* (Shortcode block, not Code block):
   - `Census` — slug `census` — shortcode `[gob_census]`
   - `Census entry` — slug `census-entry` — shortcode `[gob_entry]`
   - `Add / edit census entry` — slug `census-edit` — shortcode `[gob_form]`
5. Under **Guild Census → Page settings**, follow the five-column mapping table. Select **Census** next to **Catalogue / search**, **Census entry** next to **Individual record**, and **Add / edit census entry** next to **Add / edit form**. Click **Save page settings**. A **Ready** check appears next to a correctly configured published Page after saving. If you chose different Page titles, match them by their shortcodes, not their titles.
6. Under **Guild Census → Storage locations**, add your preferred locations; optionally add **Item types** and **Sets**.
7. Visit `/census/` logged in. Use the **Add census entry** link to create an entry. Records also appear at **Guild Census → Census records** in wp-admin.

## Real URLs

The three screen URLs are real WordPress Pages, not custom rewrite endpoints. Example (your domain will differ):

- `https://example.org/census/`
- `https://example.org/census-edit/`
- `https://example.org/census-entry/?record=GOB-000001`

Individual record views are rendered on the real `census-entry` Page, distinguished by the `record` parameter. **This version does not create a separate WordPress Page for each individual record.** If later you require a clean per-record URL, we can add that explicitly, but it would use routing or materialised Pages.

You can choose different slugs, or nest these Pages under a parent Page, by updating their Page settings. Only the shortcodes and page selections matter.

## Version 0.1 functionality

- WP admin **Guild Census** dashboard and record management.
- Private (not publicly queryable) Census record custom post type.
- Three taxonomies: Item types (hierarchical), Sets (non-hierarchical), Storage locations (hierarchical).
- Permanently assigned `GOB-000001`-style ID (atomic counter; not reassigned on deletion).
- Real Page-based catalogue with name/ID search, gallery and paging.
- Individual record display Page.
- Mobile-friendly add/edit Page with name, location, notes and primary photograph upload via native WordPress Media Library / Featured image. This first form deliberately omits the type/set selectors; they can be assigned in wp-admin.
- Same record can be edited in the WP admin or the frontend form.

## Security boundaries — important

- Configure **cPanel Directory Privacy for the entire installation/document root** and HTTPS **before uploading real photos**. Test direct media URL in a private browser session. WP private posts or the page shortcodes DO NOT by themselves protect media URLs.
- If the site is installed at the domain root, protect that root. If WordPress lives in a subdirectory, protect the subdirectory *and verify what aliases, other virtual hosts, or CDN/cache layers may serve files.*
- Disable remote media optimisers, image CDNs and other services that transmit media externally.
- The shortcode interfaces require WP login; users with normal WP Author/Editor/Administrator capabilities can create/edit entries. Role-specific census permissions are not yet implemented.
- Some WordPress operations and hosting integrations may need exceptions or configuration under HTTP Basic Authentication (loopback requests, cron, REST, login sessions).
- Note that a remote rented server is still external hosting, although not a catalogue-provider cloud service.

## Not yet implemented

- Excel/CSV imports with preview and safe update-by-permanent-ID
- CSV export
- Multi-bear photograph tagging and image archive
- Multiple image gallery per record
- Type/set editing on the mobile form
- Upload failure recovery UX / orphan cleanup
- Custom user roles, audit trail, extensive field registry

## Design notes

Post type: `gob_item`. Census ID stored in immutable meta `_gob_census_id`. Taxonomies: `gob_type`, `gob_set`, `gob_location`. Photo is native WordPress Featured image. The `gob_census_counter` option is incremented atomically in MySQL/MariaDB. Deleting a record does not recycle its number.

The plugin does **not** create or rename any files based on census IDs. Original photograph filenames such as `devonHoliday-087.jpg` are fine. WordPress may safely alter a filename on upload to avoid collisions. Group associations will be database relations in a later version.

## Testing checklist

- Log in and save an item from Windows; confirm permanent ID appears in dashboard and detail view.
- Add a photo from iPhone Safari; verify Gallery and Featured image work.
- Change the name and location; verify the ID is unchanged.
- Visit the catalogue while logged out; content is not shown.
- Test the *direct photo URL* while logged out / in private browsing: your web server should challenge for credentials, not display the image.
- Check WP admin editing and image thumbnails still function behind cPanel HTTP Basic Authentication.
- Back up the WordPress database and Media Library together.

## Presentation policy (v0.1.1 onward)

The plugin deliberately enqueues **no frontend CSS**, sets no colours, font sizes, card borders, image cropping or layout grids, and uses ordinary HTML elements and native WordPress thumbnails. Presentation comes from the active WordPress theme. The list has non-visual CSS class hooks (`gob-record-list`, `gob-record`) should you ever want to style it in the theme, but the plugin applies no styling to them. Actual HTML form controls still depend on your theme's styles.

This update does not change the database, URLs, page assignment IDs, census records or images. Updating the plugin from v0.1.0 retains all settings.
