# DJGear.ir — Modular WordPress & WooCommerce Platform

DJGear.ir is a modern, modular WordPress + WooCommerce commerce platform engineered with a hybrid development workflow (Windows 11 + WSL2 + Docker Desktop → Git → Staging VPS → Controlled Production).

## Architecture Baseline

| Component | Baseline Version | Notes |
| --- | --- | --- |
| WordPress | `7.1.5` (PHP 8.3 FPM) | Custom image with WooCommerce extensions + WP-CLI |
| WooCommerce | `11.3.1` | Pinned across local, staging, and production |
| PHP | `8.3` (PHP-FPM) | Includes `redis`, `soap`, `imagick`, `intl`, `gd`, `opcache` |
| Database | MySQL `8.4` LTS | `utf8mb4_unicode_ci`, dedicated least-privilege WP user |
| Web Server | Nginx `1.30-alpine` | Reverse proxy + static asset caching + security hardening |
| Object Cache | Redis `7.4-alpine` | Internal backend network only, password-authenticated |
| Local Mail | Mailpit `v1` | Captures all dev emails via `djgear-dev-mailpit.php` MU-plugin |
| Frontend Tooling | Node.js LTS (`v24.x`) | React + TypeScript + Vite (Phase 1+ Dashboard/Modules) |

## Quick Start (Local Development)

1. Ensure **Docker Desktop (WSL2)** is running.
2. Create your local environment file (never commit `.env`):
   ```powershell
   Copy-Item .env.example .env
   ```
   Replace all `CHANGE_ME_*` placeholders in `.env` with strong local credentials.
3. Build and start services:
   ```powershell
   docker compose build
   docker compose up -d
   ```
4. Bootstrap WordPress, WooCommerce, and Redis Object Cache:
   ```powershell
   powershell -ExecutionPolicy Bypass -File .\scripts\wp-setup.ps1
   ```
5. Run the validation suite:
   ```powershell
   powershell -ExecutionPolicy Bypass -File .\scripts\verify-env.ps1
   ```

## Local Access URLs

- **WordPress Storefront:** http://localhost:8080
- **WordPress Admin:** http://localhost:8080/wp-admin
- **Mailpit Inbox UI:** http://localhost:8025
- **MySQL (Localhost-Only Admin):** `127.0.0.1:3306`

## Repository Layout

- `compose.yaml` — Local 5-container Docker Compose stack
- `.env.example`, `.env.staging.example`, `.env.production.example` — Environment configuration templates
- `infrastructure/docker/` — Custom WordPress PHP-FPM Dockerfile, PHP/FPM, MySQL 8.4, and Redis configs
- `infrastructure/nginx/` — Hardened Nginx FastCGI configuration
- `wp-content/mu-plugins/` — Must-use plugins (dev Mailpit SMTP router)
- `wp-content/plugins/` — Future independent DJGear plugins (Phase 1+)
- `wp-content/themes/` — Future custom DJGear theme (Phase 1+)
- `scripts/` — Reproducible setup and verification scripts
- `docs/ARCHITECTURE.md` — Detailed hybrid architecture and module blueprint
- `docs/WORDPRESS_INSTALL.md` — Step-by-step installation guide

## Git Workflow

- `main` — Approved stable releases only.
- `develop` — Integrated development branch.
- `feature/*` — Isolated feature or infrastructure branches merged into `develop`.
- `.env`, credentials, SQL dumps, backups, and uploads are strictly ignored via `.gitignore`.
