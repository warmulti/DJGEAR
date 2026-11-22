# DJGear Custom Skins (`djgear-custom-skins`) — Phase 4 Documentation

Commercial custom DJ equipment skin ordering system integrated with `djgear-core`, `djgear-device-catalog`, `djgear-dashboard`, and WooCommerce (HPOS-compatible).

## 1. Business Model & Architectural Boundaries

- **Zero Engineering Dielines on the Website:** No engineering dieline storage, proprietary production templates, dieline download endpoints, or engineering staff permissions exist in `djgear-custom-skins` or customer-facing screens.
- **WooCommerce as Sole Commerce Authority:** Products, base prices, taxes, coupons, cart, checkout, payment gateways, and orders live strictly in WooCommerce (HPOS-safe). Plan surcharges are applied server-side in `woocommerce_before_calculate_totals` from the frozen job snapshot.
- **Commercial Ordering Workflow:**
  1. Customer selects a DJ device from `djgear-device-catalog`.
  2. Customer selects a commercial skin model & color/finish (`djgear_skin_variants`).
  3. Customer selects an ordering plan (`djgear_skin_plans`):
     - **Standard Plans (`allows_artwork_upload = false`):** No artwork upload allowed or required; upon WooCommerce payment confirmation, the job enters `queued_for_production` directly.
     - **Custom Design Plans (`allows_artwork_upload = true`):** Requires WordPress authentication (`401` if anonymous), enforces DPI validation, and enters `submitted_for_review` upon payment for designer proof review and customer approval before production.

## 2. Database Schema (5 Normalized MySQL 8.4 Tables)

All tables use `utf8mb4_unicode_ci`, run via idempotent versioned migrations under restricted `djgear_wp_user`, preserve data on plugin deactivation, and guard rollbacks behind `DJGEAR_ALLOW_DESTRUCTIVE_ROLLBACK === true` + confirmation token `CONFIRM_DESTRUCTIVE_SKINS_ROLLBACK`:

1. `{prefix}djgear_skin_variants` — Device-to-skin model/color/finish options + canonical WooCommerce `wc_product_id` (`UNIQUE(device_id, model_code, color_code)`).
2. `{prefix}djgear_skin_plans` — Configurable Standard and Custom Design ordering plans (`UNIQUE(slug)`).
3. `{prefix}djgear_skin_jobs` — Customer skin jobs linked to WooCommerce HPOS `wc_order_id` and `wc_order_item_id` (`UNIQUE(job_uuid)`, `UNIQUE(wc_order_item_id)`).
4. `{prefix}djgear_skin_artworks` — Immutable customer artwork uploads and designer proof revisions (`SHA-256` digests, pixel dimensions, DPI status, customer decisions).
5. `{prefix}djgear_skin_status_history` — Immutable status transition and approval audit trail.

## 3. Authenticated Artwork Upload, DPI Policy & Private Vault Security

- **Authentication & Plan Guard:** `POST /djgear/v1/skins/jobs/{uuid}/artwork` requires WordPress authentication (`401`), enforces job ownership (`403` for other customers), and rejects uploads on Standard plans (`400`).
- **Private Storage Vault:** Stored strictly under `/wp-content/uploads/djgear-private/customer-artwork/{YYYY}/{MM}/{job_uuid}/{uuid}.{ext}` with Nginx `deny all` (`403`), `.htaccess`, `index.php`, magic-byte `finfo_file` validation (`image/png`, `image/jpeg`, `image/tiff`, `application/pdf`; blocks PHP/HTML/SVG/JS), 25 MiB limit, `15000×15000` bomb guard, and `realpath()` containment on every authenticated download proxy request (`X-Content-Type-Options: nosniff`, `Cache-Control: private, no-store`).
- **DPI Validation Policy (`ArtworkDpiValidator`):**
  - `< 180 DPI` → `rejected` (blocked from WooCommerce checkout).
  - `180–299 DPI` → `warning` (requires explicit customer `POST /acknowledge-dpi` before checkout).
  - `>= 300 DPI` → `pass` (immediately checkout-ready).

## 4. Capabilities Matrix

| Capability | Roles | Purpose |
| --- | --- | --- |
| `djgear_view_skin_orders` | `administrator`, `shop_manager` | View skin production queue, artwork preflight reports, and job timelines |
| `djgear_manage_skin_orders` | `administrator`, `shop_manager` | Upload designer proofs, advance production/shipping stages, set tracking codes |
| `djgear_manage_skin_catalog` | `administrator`, `shop_manager` | Manage skin models, colors, finishes, and WooCommerce product mappings |
| `djgear_manage_skin_plans` | `administrator` only | Create, edit, and delete ordering plans, surcharges, and revision limits |

## 5. REST API Endpoints (`djgear/v1/skins/*`)

- **Public Storefront:** `GET /djgear/v1/skins/options?device_id={id}` (Redis-cached, IP rate-limited, strict allowlist).
- **Customer Portal & Configurator:** `POST /skins/jobs`, `POST /skins/jobs/{uuid}/artwork`, `POST /skins/jobs/{uuid}/acknowledge-dpi`, `POST /skins/jobs/{uuid}/add-to-cart`, `GET /skins/my-jobs`, `GET /skins/my-jobs/{uuid}`, `POST /skins/my-jobs/{uuid}/approve-proof`, `POST /skins/my-jobs/{uuid}/request-revision`, `GET /skins/jobs/{uuid}/artworks/{rev_id}/download`.
- **Admin Dashboard:** CRUD `/skins/admin/variants`, CRUD `/skins/admin/plans`, `GET /skins/admin/jobs`, `GET /skins/admin/jobs/{id}`, `POST /skins/admin/jobs/{id}/proof`, `POST /skins/admin/jobs/{id}/transition`.

## 6. Dashboard Navigation & Legacy Phase 3 Preservation

- `djgear-dashboard` exposes **Skin Models & Colors** (`skins-variants`), **Ordering Plans** (`skins-plans`, admin-only), and **Skin Orders & Production Queue** (`skins-orders`) in dark-navy Persian RTL & English LTR.
- Legacy Phase 3 `catalog-skins` navigation item is hidden from the dashboard sidebar while preserving `catalog-devices`, `catalog-taxonomies`, and admin-only `catalog-import-export`.
- All Phase 3 tables (`djg_djgear_skin_definitions`), records, PHP code, and tests remain intact and untouched.
