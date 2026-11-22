# Local WordPress & WooCommerce Installation Procedure

This procedure provides a fully reproducible local installation of WordPress, WooCommerce, and Redis Object Cache inside the DJGear Docker stack.

## Prerequisites

1. **Docker Desktop** running with **WSL2** backend enabled.
2. Local `.env` file created from `.env.example` with strong local credentials (excluded from Git).

```powershell
Copy-Item .env.example .env
# Edit .env to replace CHANGE_ME_* values with strong random local passwords
```

## Step 1: Build and Start the Docker Stack

```powershell
docker compose build
docker compose up -d
docker compose ps
```

Wait until all five containers report `healthy`:
- `djgear_mysql`
- `djgear_redis`
- `djgear_mailpit`
- `djgear_wordpress`
- `djgear_nginx`

## Step 2: Run Automated WordPress + WooCommerce Bootstrap

Run the reproducible PowerShell bootstrap script:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\wp-setup.ps1
```

What `wp-setup.ps1` performs via WP-CLI inside `djgear_wordpress`:
1. Verifies MySQL connectivity through WordPress configuration.
2. Completes WordPress core installation (`wp core install`) if not already installed.
3. Sets permalink structure (`/%postname%/`) required by WooCommerce REST API and modern React admin routes.
4. Installs and activates **WooCommerce** at the pinned `WOOCOMMERCE_VERSION` from `.env`.
5. Installs and activates **Redis Object Cache** (`redis-cache`) and enables the persistent drop-in (`wp redis enable`).
6. Installs the Persian (`fa_IR`) language pack alongside default English (`en_US`) so RTL/LTR switching is immediately available.
7. Verifies write permissions for `www-data` on `/var/www/html/wp-content/uploads`.

## Step 3: Verify the Environment

Run the automated validation suite:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\verify-env.ps1
```

## Local Access URLs

- **Storefront:** http://localhost:8080
- **WordPress Admin:** http://localhost:8080/wp-admin
- **Mailpit Web UI:** http://localhost:8025
- **MySQL (Localhost Admin Only):** `127.0.0.1:3306`

## Resetting Local State (Destructive — Use With Caution)

To stop containers while **preserving** database and uploads volumes:
```powershell
docker compose down
```

To completely wipe local volumes and start fresh (requires explicit confirmation):
```powershell
docker compose down -v
```
