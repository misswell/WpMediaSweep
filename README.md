# WpMediaSweep · MediaSweep

A **local-first** WordPress media optimization and cleanup tool — scan, compress,
analyze references and safely clean up unused media. Think *DevSweep + OctoShrink,
for WordPress media libraries*.

> 核心理念：本地处理（图片绝不离开你的服务器）、复用 OctoShrink 的压缩策略、
> 不破坏 WordPress 媒体引用关系、为 10 万+ 图片的大型站点设计。

## What it does

```
扫描 → 分析 → 压缩 → 标记 → 找垃圾 → 安全清理
```

| Module | What it gives you |
|---|---|
| **Media scan** | Indexes every image under `wp-content/uploads` (jpg / jpeg / png / webp / avif) in resumable batches, including WordPress `-WxH` size variants linked to their parent; md5 hash computed for every file |
| **Compression** | Octo Engine (PHP port of the OctoShrink strategy): Imagick → GD backend chain, per-format quality (JPEG 85 / PNG lossless / WebP 80 / AVIF 65) |
| **Safety** | Backup → compress → validate → replace. Originals kept as `.ms-original`, one-click restore, "no improvement → keep original" rule, configurable backup retention with daily cleanup |
| **Reference analysis** | Detects where each image is used: post content, featured images, Gutenberg blocks, WooCommerce galleries, Elementor data, theme/plugin files |
| **Risk grading** | Every candidate graded: *safe* (no media library record) / *delete with care* (attachment exists, no references) / *do not delete* (referenced) |
| **Duplicate detection** | md5 grouping across original images — shows identical copies and reclaimable space |
| **Cleanup** | Unused candidates → your confirmation → trash (files moved to `uploads/ms-trash` with manifest + WP native attachment trash) → auto purge after 30 days |
| **WP-CLI** | `wp mediasweep scan / references / compress / unused / clean / duplicates / stats / tick` for large sites |

## Installation

1. Copy (or upload) the `wp-mediasweep` folder to `wp-content/plugins/`
   — or install `mediasweep.zip` from Releases.
2. Activate **MediaSweep** in the Plugins screen.
3. Open the **MediaSweep** menu in wp-admin → **Dashboard** → *Start media scan*.

Requires WordPress 5.8+, PHP 7.4+.

### Running the test stack (optional)

The Docker test stack reads its database passwords from a local `.env` file
(never committed):

```bash
cp .env.example .env
docker compose -f docker-compose.test.yml up -d
```

## How it stays safe on large sites

- **Nothing loads in one go.** Scan, reference scan and compression all run as
  resumable background tasks (`wp_ms_tasks`), stepped in batches (default 200)
  with a per-step time budget (default 20 s).
- **Dual drive:** the admin UI ticks the task loop every few seconds while open;
  WP-Cron keeps it moving when you close the browser. Tasks pause / resume /
  cancel, and crashed tasks are automatically re-queued (handlers are idempotent).
- **Deletion is reversible** until the retention window passes: files go to
  `uploads/ms-trash/<token>/` with a `manifest.json`, attachments use the native
  WordPress trash.

## REST API

Namespace `mediasweep/v1` (all routes require `manage_options`):

| Method | Route | Purpose |
|---|---|---|
| GET | `/stats` | Dashboard statistics (files, bytes, compression, references, duplicates) |
| GET | `/images` | Index list (`status`, `compressed`, `search`, `thumbnails`, `page`, `per_page`) with `risk_level` per row |
| GET | `/duplicates` | Duplicate groups (identical md5 among originals) |
| GET | `/images/{id}/references` | Where an image is referenced |
| POST | `/images/{id}/analyze` | Re-run reference detection for one image |
| POST | `/images/{id}/restore` | Restore the `.ms-original` backup |
| POST | `/scan` | Start/resume the media scan |
| POST | `/scan/references` | Start/resume the reference scan |
| POST | `/compress` | `{id}` one image · `{ids:[…]}` selection · `{all:true}` everything pending |
| POST | `/delete` | Move `{ids:[…]}` to the trash |
| GET/POST | `/trash` · `/trash/{token}/restore` | Trash list & restore |
| GET | `/tasks` · POST `/tasks/{id}/{pause\|resume\|cancel}` | Task control |
| POST | `/tick` | Drive the task loop (used by the UI) |
| GET/POST | `/settings` | Settings |
| GET/DELETE | `/logs` | Operation log |

## Compression backends

The Octo Engine never spawns shell processes. It picks the first available backend:

1. **Imagick** — best quality control, all formats (AVIF needs ImageMagick with AVIF support)
2. **GD** — fallback; JPEG/PNG/WebP everywhere, AVIF on PHP 8.1+

Safety rules (inherited from OctoShrink): output must be a valid image with the
same pixel dimensions and strictly smaller than the original — otherwise the
original is kept. EXIF orientation is baked into pixels before metadata is
stripped, so rotated photos never break.

## Notes & boundaries

- Phase 1 compresses original files; `-WxH` thumbnail variants are skipped
  (WordPress regenerates them) and animated images are never touched.
- Native attachment rows are **not modified** — compression keeps pixel
  dimensions identical, so no WordPress metadata needs rewriting.
- Uninstalling drops the plugin tables/options but **keeps** `.ms-original`
  backups and the trash directory; delete them manually when you no longer
  need recovery.

## Roadmap

- Phase 3: pHash perceptual duplicates (near-identical, not just byte-identical),
  on-upload auto-compress, background thumbnail compression.

## License

GPL-2.0-or-later
