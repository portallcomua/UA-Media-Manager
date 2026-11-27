# UA MediaManager 2.0.0 — development branch

WordPress media library manager by UA Server.

## Install test build
1. Make a full backup of both the database and site files.
2. Deactivate the previous UA MediaManager version.
3. Download the ZIP from the `feature/media-manager-2.0` branch.
4. In WordPress open **Plugins → Add New → Upload Plugin**, upload the ZIP and activate it.
5. Open **UA MediaManager** in the admin menu.

Branch archive: https://github.com/portallcomua/UA-Media-Manager/archive/refs/heads/feature/media-manager-2.0.zip

## Implemented in this development branch
- Media dashboard with attachment count and uploads disk usage.
- Media grid with preview, search, pagination and sorting by title, MIME type and date.
- Hierarchical physical folders created under `wp-content/uploads/uamm/`.
- Bulk physical relocation of attachment originals and known generated image sizes into selected folders.
- Physical file and folder renaming, including known generated image sizes and descendant folder paths.
- Updates `_wp_attached_file` and WordPress attachment metadata; rewrites matching URLs in post content, post meta and options while preserving serialized values.
- Quarantine via WordPress Trash, with restore and permanent delete actions.
- ZIP download of the uploads directory streamed to the browser; the temporary archive is removed after the response.
- ZIP download of files in the WordPress media trash.
- ZIP restore with path traversal checks, a 10,000-file limit, 2 GB uncompressed size limit and no-overwrite behavior.
- When a restored file matches an attachment in WordPress Trash, the attachment is automatically untrashed.
- Public GitHub Releases update check. Publish a versioned GitHub Release; preferably attach a correctly packaged `ua-mediamanager.zip` asset.
- PHP syntax-check workflow for GitHub pushes and pull requests.
- Capability checks, AJAX nonce checks and responsive admin styles.

## Not yet implemented — do not treat as finished
The following original requirements remain open:
- One-click preset folder templates for `images/blog`, `WooCommerce/images`, `WooCommerce/category` and plugin-specific trees.
- A full transactional rollback for database write failures and third-party offload/CDN integrations. Physical move currently rolls back filesystem changes if a file collision/move error occurs, but database and third-party custom references still require broader compatibility testing.
- Complete ZIP-to-media-library import on a fresh database. ZIP restore currently restores files and reconnects only matching attachment records already present in Trash; it is not a full WordPress site backup.
- Reliable unused-media scanner covering page builders, widgets, theme options, WooCommerce, custom fields, CSS and external/plugin storage. Current scan is heuristic only.
- Duplicate detection by file hash and safe duplicate merge/removal.
- Lossless image optimization, batch processing, progress reporting and before/after disk-space statistics.
- Full bilingual Ukrainian/English interface and language auto-detection.
- Freemius SDK licensing and PRO gating. No license activation is currently required or implemented.
- Marketing package: final logo, banner, real admin screenshots, product-page copy and Facebook copy.
- Full compatibility testing against supported WordPress, WooCommerce, Elementor and popular themes.

## Safety
Do not use this development build for bulk destructive operations on a production site. Test on a staging copy first. Keep a database + files backup outside the website. The unused-media scan is not proof that a file is unused. Never permanently delete a candidate solely because it appears in the scan.

## Requirements
- WordPress 6.2+
- PHP 7.4+
- PHP ZipArchive for ZIP backup and restore
