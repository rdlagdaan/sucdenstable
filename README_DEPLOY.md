# SUCDEN / AMEROP — Deploy Runbook

This repo is set up for dev⇄prod parity using Docker Compose, Octane (Swoole), and an Nginx-served SPA.

## Services (Compose)
- **postgres**: 15 (host port 5434)
- **app**: Laravel 12 + Octane Swoole (host port 8686)
- **queue**: Laravel queue worker
- **frontend**: Nginx serving built React app (host port 3001), proxies `/api` + `/sanctum` → `app:8686`

## First-time setup (prod host)
1. Copy `.env.example` → `.env` and set real values (DB, APP_URL).
2. Bring stack up once:
   ```bash
   docker compose up -d
   docker compose run --rm app sh -lc "php artisan storage:link || true"
   docker compose run --rm app sh -lc "chown -R www-data:www-data storage bootstrap/cache || true"
