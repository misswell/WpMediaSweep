=== MediaSweep ===
Tags: media, image compression, unused images, cleanup, optimization
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Compress images locally, analyze media references, and review unused files with a recoverable trash and original-image backups.

== Description ==

MediaSweep helps administrators optimize and review their WordPress uploads without sending images to a third-party compression service.

* Scan JPG, JPEG, PNG, WebP, and AVIF files in the uploads directory.
* Compress images on the server using Imagick or GD. Compression is accepted only when the image remains valid, keeps its pixel dimensions, and becomes smaller.
* Keep original-image backups by default and restore them from the image list.
* Analyze post content, Gutenberg block attachment IDs, featured images, WooCommerce galleries, Elementor data, and optional theme/plugin file references.
* Review unused candidates in a dedicated tab. Reference detection cannot discover every possible use; review candidates before moving them to the trash.
* Move selected files to a recoverable MediaSweep trash. Restore them from the separate Trash tab before the retention period expires.
* Run scans and compression as resumable background tasks driven by the admin page and WP-Cron.
* Optionally compress newly uploaded attachments automatically.
* Use the wp mediasweep commands for scans, compression, reference analysis, and diagnostics.

= Requirements =

WordPress 5.8 or newer, PHP 7.4 or newer, and a writable local uploads directory are required. Image compression needs Imagick or GD with support for the selected image format. MySQL or MariaDB must support named advisory locks. Remote object-storage-only media is not supported.

= Privacy and external services =

MediaSweep does not upload images, collect telemetry, or require an external API key. Image processing, the media index, reference records, tasks, and operation logs stay on your server. The plugin makes no external-service requests for compression or analysis.

= Backups and retention =

Original backups use the .ms-original suffix beside each image. They are kept indefinitely by default. Administrators can disable backups or choose a backup-retention period in Settings. MediaSweep trash is purged after 30 days by default; its retention period is configurable. Entries with an unfinished restore are retained for recovery.

Uninstalling the plugin removes its database tables and settings but leaves original backups and the MediaSweep trash files on disk. Remove those files manually only when you no longer need recovery.

= Source code and build instructions =

The complete human-readable PHP and JavaScript source, including the admin JSX and build tooling, is available at:
https://github.com/misswell/WpMediaSweep/tree/v0.3.5

To reproduce the admin JavaScript bundle, check out that version, run npm ci, then npm run build. The build writes wp-mediasweep/admin/assets/build/app.js and app.asset.php. WordPress provides the wp.element, wp.components, and other WordPress JavaScript dependencies; no React runtime is bundled.

Development and issue tracker:
https://github.com/misswell/WpMediaSweep

== Installation ==

1. Install MediaSweep from the plugin directory after approval, or upload the plugin ZIP through Plugins > Add New > Upload Plugin.
2. Activate MediaSweep.
3. Open MediaSweep in the admin menu and start a media scan.
4. Run a reference scan before reviewing the Unused tab.
5. Review candidates, move confirmed unused files to the trash, and restore them from the Trash tab when needed.

== Frequently Asked Questions ==

= Are images sent to another server? =

No. Imagick or GD processes images locally on your WordPress server.

= Can I restore a compressed image? =

Yes, when original backups are enabled and the backup has not expired or been removed. Recompression preserves the first existing original backup.

= Does an unused result guarantee that an image can be deleted? =

No. Dynamic code, external sites, custom fields, and other integrations may use an image in ways the scanner cannot detect. Review its references and media-library association before moving it to the trash.

= What happens if a move or restore fails? =

Failed trash moves are rolled back where possible. Recovery manifests are retained when a rollback or restore cannot finish. Existing restore destinations are not overwritten.

= Does this compress every generated thumbnail? =

The current compression task processes original files. Size variants are indexed so their references can be attributed to the original image.

== Changelog ==

= 0.3.5 =
* Add WordPress.org directory readme, privacy and retention documentation, source/build links, and the GPL license text.

= 0.3.4 =
* Separate Unused and Trash tabs with Simplified Chinese translations.
* Preserve the first original backup and serialize image operations and background task ticks.
* Save recovery manifests before moves; roll back failed trash moves and retain interrupted restore plans.
* Resume selected compression across task ticks and preserve pause/cancel state.
* Index newly uploaded attachments before automatic compression.
* Preserve theme/plugin references during single-image analysis and detect ID-only blocks and size-variant URLs.
* Fix expired trash cleanup and skip unfinished recovery entries.

== Upgrade Notice ==

= 0.3.5 =
Adds directory documentation and licensing. Existing media, backups, and settings are preserved.
