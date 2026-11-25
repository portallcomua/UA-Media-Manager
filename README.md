# UA MediaManager 2.0.0

WordPress media library management interface by UA Server.

## Install
1. Download the ZIP archive for the `feature/media-manager-2.0` branch.
2. In WordPress go to **Plugins → Add New → Upload Plugin**.
3. Upload the ZIP, install and activate.
4. Open **UA MediaManager** in the WordPress admin menu.

## Included in this development build
- Dashboard with attachment count and actual disk usage of the uploads directory.
- Media grid with previews, search and pagination.
- Sorting by name, MIME type and date, ascending or descending.
- Hierarchical virtual folders attached to WordPress media items.
- Bulk assignment of attachments to folders without changing their physical paths or URLs.
- Quarantine using the native WordPress Trash API, with restore and permanent deletion.
- Full uploads directory ZIP download streamed to the browser; temporary ZIP is deleted after transfer.
- ZIP download of files currently in WordPress media trash.
- Conservative unused-media candidate scan (limited to 250 attachments per run).
- Capability checks, AJAX nonce validation and responsive Ukrainian admin UI.

## Important limitations of this development build
This is the first 2.0 development foundation, not the completed feature set.

- Folders are virtual taxonomy folders. Files are not physically moved and public URLs remain unchanged.
- The unused scan is heuristic and cannot prove a file is unused. It checks selected post content only and must be followed by manual verification.
- Quarantine uses WordPress Trash. It does not create a separate isolated filesystem quarantine directory.
- The quarantine ZIP includes media files found in trash; it is not a full database backup.
- ZIP archive restore is not implemented yet.
- Automatic physical folder restructuring, reference rewriting, duplicate detection, image compression/conversion, batch optimization with a progress bar, and full before/after savings reporting are not implemented yet.
- Freemius licensing and GitHub self-updater are not included yet.
- No generated image sizes are deleted. This is intentional to avoid breaking WooCommerce, responsive images, themes and page builders.

Before testing on a production website, create a hosting-level backup of both database and files. Test first on staging.

## Requirements
- WordPress 6.2+
- PHP 7.4+
- PHP ZipArchive extension for ZIP downloads
