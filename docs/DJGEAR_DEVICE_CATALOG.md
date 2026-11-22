# DJGear Device Catalog (`djgear-device-catalog`)

`djgear-device-catalog` is an independent WordPress plugin providing a specialized DJ hardware catalog, bilingual technical specifications, WooCommerce product compatibility graph, and versioned Compatible Skin Engineering Definitions for DJGear.ir.

## Canonical Data Architecture (Single Source of Truth)

- **WordPress CPT (`djgear_device`)**: Canonical ID, URL slug (`post_name`), publish/draft status, and display title ONLY (zero duplicated custom `post_meta`).
- **WordPress Taxonomies**: `djgear_brand` (Manufacturers) and `djgear_device_category` (Hierarchical Categories) with bilingual term metadata (`name_fa`, `name_en`, logos, sort order).
- **WordPress Media Library**: All public images and private engineering dieline files stored natively and referenced by `attachment_id`.
- **5 Normalized MySQL 8.4 Tables** (managed by versioned, idempotent, reversible migration `0001_create_catalog_tables` under restricted `djgear_wp_user`):
  1. `{prefix}djgear_device_index` — 1:1 canonical device identity (`UNIQUE canonical_key`, `UNIQUE mpn`), bilingual names/summaries, lifecycle status, `skin_ready`, optional `primary_wc_product_id`, `FULLTEXT` search index.
  2. `{prefix}djgear_device_specs` — Grouped bilingual technical specs and indexed filterable numeric/slug attributes.
  3. `{prefix}djgear_device_media` — Ordered public media roles (`hero`, `gallery`, `top_view`, `rear_io`, `skin_preview`). Engineering dielines are never stored here.
  4. `{prefix}djgear_device_compatibility` — Typed compatibility graph (`device`, `wc_product`, `skin_definition`) supporting multi-device skin fit and bidirectional WooCommerce product lookups without duplicating WC data.
  5. `{prefix}djgear_skin_definitions` — Versioned skin engineering blueprints (`template_code`, `template_version`, mm dimensions, bleed, min DPI, zones JSON, supported finishes, public preview vs. private `dieline_attachment_id` & `internal_notes`).

## Capabilities & Role Matrix

| Capability | Default Roles | Scope |
| --- | --- | --- |
| `djgear_view_catalog` | `administrator`, `shop_manager` | Read catalog devices, brands, categories, specs, compatibility |
| `djgear_manage_catalog` | `administrator`, `shop_manager` | Create/update/delete brands, categories, devices, specs, media, compatibility |
| `djgear_manage_skin_definitions` | `administrator` only | Manage versioned skin blueprints & private engineering dielines |
| `djgear_import_export_catalog` | `administrator` only | Dry-run validate, execute batch imports, and export JSON/CSV snapshots |

## Public Read API Security (`/djgear/v1/catalog/public/*`)

- **Endpoints:** `GET /public/devices`, `/public/devices/{slug}`, `/public/brands`, `/public/categories`, `/public/filters`, `/public/wc-product/{wc_id}/devices`.
- **Active-Only & Allowlist:** All responses pass through `PublicCatalogSerializer` allowlists — strictly stripping `dieline_attachment_id`, `dieline_url`, `internal_notes`, `edit_url`, and any non-`active` device or skin.
- **Redis Caching & Invalidation:** Cached in `djgear_catalog_public` with atomic version-salt invalidation (`CatalogCacheService::invalidatePublicCache()`) triggered on every write. Admin/private responses are never cached publicly.
- **Rate Limiting:** Object-cache IP quota (`120 req / 60s` default, filterable via `djgear_catalog_public_rate_limit`, returning HTTP `429`).

## Verification Commands

```powershell
# Backend static analysis & unit tests
docker compose exec -T -w /var/www/html/wp-content/plugins/djgear-device-catalog wordpress sh -c "composer validate --strict && composer lint && composer analyse && composer test:unit"

# Live WordPress + WooCommerce integration tests (51 assertions)
docker compose exec -T -u www-data wordpress wp eval-file /var/www/html/wp-content/plugins/djgear-device-catalog/tests/Integration/wp-catalog-integration-test.php
```
