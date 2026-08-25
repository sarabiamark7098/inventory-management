#!/usr/bin/env bash
#
# Deploy the ICT Equipment & Borrowing Management System.
#
#   sudo -u www-data ./deploy/deploy.sh
#
# Safe to re-run: every step is idempotent. Run it from the repository root on
# the server.

set -euo pipefail

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
API_DIR="$APP_ROOT/api"
WEB_DIR="$APP_ROOT/web"

say() { printf '\n\033[1;36m==> %s\033[0m\n' "$1"; }
die() { printf '\n\033[1;31mERROR: %s\033[0m\n' "$1" >&2; exit 1; }

[[ -f "$API_DIR/.env" ]] || die "api/.env is missing. Copy deploy/api.env.production.example and fill it in."
grep -q '^APP_KEY=base64:' "$API_DIR/.env" || die "APP_KEY is not set. Run: php artisan key:generate --force"
grep -q '^APP_DEBUG=false' "$API_DIR/.env" || die "APP_DEBUG must be false in production."

# ---------------------------------------------------------------------- API
say "API: installing PHP dependencies"
cd "$API_DIR"
# --no-dev keeps the debug toolchain (and its attack surface) off the server.
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

say "API: running migrations"
# --force is required because this is a non-interactive production run.
php artisan migrate --force

say "API: provisioning the bootstrap admin"
# updateOrCreate, so re-running never duplicates or resets anyone.
php artisan db:seed --class=UserSeeder --force

say "API: caching configuration"
# Order matters: clear first, or a stale cache is what gets re-cached.
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

say "API: linking storage"
php artisan storage:link 2>/dev/null || true

# ---------------------------------------------------------------------- WEB
say "WEB: building the client bundle"
cd "$WEB_DIR"
[[ -f .env.production ]] || die "web/.env.production is missing. Copy deploy/web.env.production.example."

# `npm ci` installs exactly the lockfile — `npm install` can silently drift.
npm ci
npm run build

[[ -f dist/index.html ]] || die "The build produced no dist/index.html."

# -------------------------------------------------------------------- CHECK
say "Verifying"
cd "$API_DIR"
php artisan about --only=environment | sed -n '1,12p'

if command -v curl >/dev/null; then
    APP_URL="$(grep '^APP_URL=' .env | cut -d= -f2- | tr -d '"')"
    printf 'health: '
    curl -fsS "${APP_URL%/}/api/health" || printf '(unreachable — check nginx and php-fpm)'
    printf '\n'
fi

say "Done. Reload nginx if the site config changed: sudo systemctl reload nginx"
