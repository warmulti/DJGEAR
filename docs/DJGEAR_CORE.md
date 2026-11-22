# DJGear Core (`djgear-core`) — Architecture & Public Contracts

`djgear-core` is the shared foundation plugin for the DJGear.ir modular WordPress & WooCommerce platform. Every business module built in subsequent phases (Dashboard, SMS Center, Email Center, Form Builder, CRM, SEO Manager, Payment Manager, Analytics, Backup & Security) depends on `djgear-core`'s contracts while remaining independently deployable.

## Core Principles

1. **Single Source of Truth:** WordPress and WooCommerce remain the sole source of truth for users, roles, products, inventory, orders, and payment records. `djgear-core` never duplicates commerce entities.
2. **WooCommerce HPOS Ready:** Declares explicit compatibility with `custom_order_tables` and `cart_checkout_blocks` on `before_woocommerce_init`.
3. **Strict Isolation:** Zero modifications to WordPress or WooCommerce core files; all migrations run under the restricted `djgear_wp_user` database account (never MySQL `root`).
4. **Bilingual RTL/LTR First:** First-class support for Persian (`fa_IR`, `rtl`) and English (`en_US`, `ltr`), compliant with WordPress 6.7+ `init`-phase textdomain loading.

---

## Namespace & PSR-4 Autoloading

- **Composer Package:** `djgear/djgear-core` (`php >= 8.3`)
- **Root Namespace:** `DJGear\Core\` mapped to `wp-content/plugins/djgear-core/src/`
- **Global Kernel Accessor:** `djgear_core(): \DJGear\Core\Plugin`

---

## Public Subsystems & Interfaces

### 1. Service Container (`DJGear\Core\Container`)
- [`ContainerInterface`](file:///d:/AntiGravity/DJGEAR/wp-content/plugins/djgear-core/src/Container/ContainerInterface.php): `bind()`, `singleton()`, `instance()`, `has()`, `get()`, `keys()`.
- [`Container`](file:///d:/AntiGravity/DJGEAR/wp-content/plugins/djgear-core/src/Container/Container.php): deterministic lazy singletons with circular dependency detection (`ContainerException`).

### 2. Module Lifecycle & Discovery (`DJGear\Core\Modules`)
- [`ModuleInterface`](file:///d:/AntiGravity/DJGEAR/wp-content/plugins/djgear-core/src/Modules/ModuleInterface.php) & [`AbstractModule`](file:///d:/AntiGravity/DJGEAR/wp-content/plugins/djgear-core/src/Modules/AbstractModule.php):
  - `id(): string` (lowercase slug)
  - `name(): string`, `version(): string`, `dependencies(): array`, `isEnabled(): bool`
  - Two-phase boot: `register(ContainerInterface)` across all modules first, then `boot(ContainerInterface)` in strict dependency-first topological order.
- **Discovery Hook (`plugins_loaded`, priority 20):**
  ```php
  add_action('djgear_core_register_modules', static function (\DJGear\Core\Modules\ModuleRegistry $registry): void {
      $registry->register(new MyDjgearModule());
  });
  ```
- **Post-Boot Hook:** `do_action('djgear_core_booted', Plugin $core)`.

### 3. Localization & RTL/LTR (`DJGear\Core\I18n`)
- [`Translator`](file:///d:/AntiGravity/DJGEAR/wp-content/plugins/djgear-core/src/I18n/Translator.php) + [`Direction`](file:///d:/AntiGravity/DJGEAR/wp-content/plugins/djgear-core/src/I18n/Direction.php) enum (`rtl` / `ltr`).
- Compiled Persian catalogs in `languages/djgear-core-fa_IR.mo` and `languages/djgear-core-fa_IR.l10n.php`.
- Exports structured localization metadata (`locale`, `direction`, `is_rtl`, `text_domain`, `supported_locales`) for future React dashboard consumption.

### 4. Capabilities & Authorization (`DJGear\Core\Security`)
- [`Capabilities`](file:///d:/AntiGravity/DJGEAR/wp-content/plugins/djgear-core/src/Security/Capabilities.php):
  - `djgear_view_dashboard` (`administrator`, `shop_manager`)
  - `djgear_view_logs` (`administrator`, `shop_manager`)
  - `djgear_manage_core` (`administrator`)
  - `djgear_manage_modules` (`administrator`)
  - `djgear_manage_integrations` (`administrator`)
- [`Authorizer`](file:///d:/AntiGravity/DJGEAR/wp-content/plugins/djgear-core/src/Security/Authorizer.php): `currentUserCan()`, `currentUserCanAny()`, and `restPermissionCallback(string $capability)` returning HTTP `401` for unauthenticated callers and HTTP `403` for authenticated callers lacking the required capability.

### 5. Versioned REST API (`djgear/v1`) (`DJGear\Core\Rest`)
- [`RestKernel`](file:///d:/AntiGravity/DJGEAR/wp-content/plugins/djgear-core/src/Rest/RestKernel.php) + [`RouteInterface`](file:///d:/AntiGravity/DJGEAR/wp-content/plugins/djgear-core/src/Rest/RouteInterface.php) + [`Response`](file:///d:/AntiGravity/DJGEAR/wp-content/plugins/djgear-core/src/Rest/Response.php).
- **Module Route Registration Hook:** `do_action('djgear_core_register_rest_routes', RestKernel $kernel, string $namespace)`.
- **Core Endpoints:**
  - `GET /wp-json/djgear/v1/health` — requires `djgear_view_dashboard`
  - `GET /wp-json/djgear/v1/modules` — requires `djgear_manage_modules`
  - `GET /wp-json/djgear/v1/audit-logs?page=1&per_page=20&action=...` — requires `djgear_view_logs`

### 6. Structured Logging & Audit Log (`DJGear\Core\Logging`)
- [`StructuredLogger`](file:///d:/AntiGravity/DJGEAR/wp-content/plugins/djgear-core/src/Logging/StructuredLogger.php): JSON-formatted log lines with level filtering (`LogLevel`) and automatic recursive redaction of sensitive keys (`password`, `secret`, `token`, `api_key`, `authorization`, `private_key`, `card_number`, `cvv`).
- [`AuditLogger`](file:///d:/AntiGravity/DJGEAR/wp-content/plugins/djgear-core/src/Logging/AuditLogger.php): persists immutable [`AuditEvent`](file:///d:/AntiGravity/DJGEAR/wp-content/plugins/djgear-core/src/Logging/AuditEvent.php) records into `{prefix}djgear_audit_logs` via prepared statements and redacts context prior to storage.

### 7. Replaceable Provider Contracts (`DJGear\Core\Providers`)
Defined as clean contracts only in Phase 1 (external provider adapters belong to their respective Phase 2+ modules):
- Email: [`EmailProviderInterface`](file:///d:/AntiGravity/DJGEAR/wp-content/plugins/djgear-core/src/Providers/Email/EmailProviderInterface.php) + [`EmailMessage`](file:///d:/AntiGravity/DJGEAR/wp-content/plugins/djgear-core/src/Providers/Email/EmailMessage.php)
- SMS: [`SmsProviderInterface`](file:///d:/AntiGravity/DJGEAR/wp-content/plugins/djgear-core/src/Providers/Sms/SmsProviderInterface.php) + [`SmsMessage`](file:///d:/AntiGravity/DJGEAR/wp-content/plugins/djgear-core/src/Providers/Sms/SmsMessage.php)
- Payment: [`PaymentProviderInterface`](file:///d:/AntiGravity/DJGEAR/wp-content/plugins/djgear-core/src/Providers/Payment/PaymentProviderInterface.php) + [`PaymentRequest`](file:///d:/AntiGravity/DJGEAR/wp-content/plugins/djgear-core/src/Providers/Payment/PaymentRequest.php) + [`PaymentVerification`](file:///d:/AntiGravity/DJGEAR/wp-content/plugins/djgear-core/src/Providers/Payment/PaymentVerification.php)
- Registry: [`ProviderRegistry`](file:///d:/AntiGravity/DJGEAR/wp-content/plugins/djgear-core/src/Providers/ProviderRegistry.php) populated via `do_action('djgear_core_register_providers', ProviderRegistry $providers, ContainerInterface $container)`.

---

## Verification Commands

```powershell
# PHP syntax lint, PHPStan Level 6, and PHPUnit unit tests
docker compose exec -T -w /var/www/html/wp-content/plugins/djgear-core wordpress composer lint
docker compose exec -T -w /var/www/html/wp-content/plugins/djgear-core wordpress composer analyse
docker compose exec -T -w /var/www/html/wp-content/plugins/djgear-core wordpress composer test:unit

# Live WordPress + WooCommerce integration tests
docker compose exec -T -u www-data wordpress wp eval-file /var/www/html/wp-content/plugins/djgear-core/tests/Integration/wp-integration-test.php
```
