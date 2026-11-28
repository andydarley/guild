# Guild — WordPress catalogue

Version 0.1.4. Requires WordPress 6.4+, PHP 8.0+.

## Installation / upgrade

Install `guild-v0.1.4.zip` over the existing `guild` plugin, or deploy the replacement files through Git. Back up the site before updating. Do not deactivate or uninstall to upgrade. Existing records, permanent IDs, taxonomies, page mappings and shortcodes are preserved.

## Pages

Create ordinary published WordPress Pages, each with a Shortcode block, then match them in **Guild → Pages**:

| Function | Suggested page slug | Shortcode |
|---|---|---|
| Catalogue / search | `/census/` (or `/` as homepage) | `[gob_census]` |
| Individual entry | `/census-entry/` | `[gob_entry]` |
| Add/edit form | `/census-edit/` | `[gob_form]` |

Individual record URLs use `?record=GOB-000001` on the assigned entry Page. No virtual frontend routes or rewrite rules are required.

## Admin

The **Guild** admin menu contains one dashboard entry. **Records** links straight to the native `Census records` post list, which already has an **Add New** button. Other dashboard tabs manage item types, sets, storage locations and page assignments.

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

Not included yet: Excel import, CSV export, group-photo association, inline term creation.
