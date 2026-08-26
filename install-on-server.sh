#!/usr/bin/env bash
#
# One-shot provisioning for the ICT Equipment & Borrowing Management System.
# Ubuntu 24.04 LTS. Run ON the server, as a user with sudo:
#
#   sudo bash install-on-server.sh
#
# Expects the two project directories to already be in place:
#
#   /var/www/api    <- this repo's api/
#   /var/www/web    <- this repo's web/
#
# Safe to re-run. Every step is idempotent: packages are only installed if
# missing, the database and user are created only if absent, and the app key and
# database password are generated once and then left alone.
#
# This script never asks for a password. The database password is generated here
# and written into api/.env; the only secret you must supply by hand is the
# mailbox password, which it will prompt you to add at the end.

set -euo pipefail

SERVER_IP="${SERVER_IP:-172.26.120.49}"
API_DIR=/var/www/api
WEB_DIR=/var/www/web
SERVE_ROOT=/srv/ict-inventory
DB_NAME=ict_inventory
DB_USER=ict_inventory

say()  { printf '\n\033[1;36m==> %s\033[0m\n' "$1"; }
ok()   { printf '    \033[1;32m✓\033[0m %s\n' "$1"; }
warn() { printf '    \033[1;33m!\033[0m %s\n' "$1"; }
die()  { printf '\n\033[1;31mERROR: %s\033[0m\n' "$1" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "Run with sudo."
[[ -d "$API_DIR" ]] || die "$API_DIR not found. Copy the repo's api/ there first."
[[ -d "$WEB_DIR" ]] || die "$WEB_DIR not found. Copy the repo's web/ there first."
[[ -f "$API_DIR/artisan" ]] || die "$API_DIR does not look like the Laravel project (no artisan)."
[[ -f "$WEB_DIR/package.json" ]] || die "$WEB_DIR does not look like the Vue project (no package.json)."

# --------------------------------------------------------------- 1. packages
say "Installing packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq

if ! apt-cache policy php8.4-fpm 2>/dev/null | grep -q Candidate:\ [0-9]; then
    warn "php8.4 not in the archive; adding ondrej/php"
    apt-get install -y -qq software-properties-common >/dev/null
    add-apt-repository -y ppa:ondrej/php >/dev/null
    apt-get update -qq
fi

apt-get install -y -qq \
    nginx mysql-server git unzip curl ca-certificates \
    php8.4-fpm php8.4-mysql php8.4-mbstring php8.4-xml \
    php8.4-curl php8.4-zip php8.4-bcmath php8.4-intl >/dev/null
ok "nginx, mysql, php8.4-fpm"

if ! command -v node >/dev/null || [[ "$(node -v | cut -c2-3)" -lt 20 ]]; then
    curl -fsSL https://deb.nodesource.com/setup_20.x | bash - >/dev/null 2>&1
    apt-get install -y -qq nodejs >/dev/null
fi
ok "node $(node -v)"

if ! command -v composer >/dev/null; then
    curl -sS https://getcomposer.org/installer | php -- \
        --install-dir=/usr/local/bin --filename=composer >/dev/null
fi
ok "composer $(composer --version --no-ansi 2>/dev/null | awk '{print $3}')"

# --------------------------------------------------------------- 2. database
say "Database"
mysql -e "CREATE DATABASE IF NOT EXISTS $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
ok "database $DB_NAME"

# Generated, never typed: nobody has to paste a password anywhere.
DB_PASS="$(head -c 32 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 24)"

if mysql -NBe "SELECT 1 FROM mysql.user WHERE user='$DB_USER' AND host='127.0.0.1';" | grep -q 1; then
    # Reuse the password already in .env so a re-run does not lock the app out.
    if [[ -f "$API_DIR/.env" ]] && grep -q '^DB_PASSWORD=.\+' "$API_DIR/.env"; then
        DB_PASS="$(grep '^DB_PASSWORD=' "$API_DIR/.env" | cut -d= -f2- | tr -d '"')"
        ok "reusing the existing database password"
    else
        mysql -e "ALTER USER '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';"
        ok "database user password reset"
    fi
else
    mysql -e "CREATE USER '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';"
    ok "database user $DB_USER created"
fi
mysql -e "GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'127.0.0.1'; FLUSH PRIVILEGES;"

# ----------------------------------------------------------------- 3. config
say "Configuration"
if [[ ! -f "$API_DIR/.env" ]]; then
    cp "$API_DIR/.env.production.example" "$API_DIR/.env"
    ok "api/.env created from the production template"
fi
if [[ ! -f "$WEB_DIR/.env.production" ]]; then
    cp "$WEB_DIR/.env.production.example" "$WEB_DIR/.env.production"
    ok "web/.env.production created"
fi

# Point the API at this host and this database.
sed -i "s|^APP_URL=.*|APP_URL=http://$SERVER_IP/inventory_management_api|" "$API_DIR/.env"
sed -i "s|^FRONTEND_URLS=.*|FRONTEND_URLS=http://$SERVER_IP|" "$API_DIR/.env"
sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=$DB_PASS|" "$API_DIR/.env"
sed -i "s|^APP_DEBUG=.*|APP_DEBUG=false|" "$API_DIR/.env"
sed -i "s|^APP_ENV=.*|APP_ENV=production|" "$API_DIR/.env"
chmod 640 "$API_DIR/.env"
ok "APP_URL, FRONTEND_URLS and DB_PASSWORD set"

# ------------------------------------------------------------ 4. build & wire
say "Installing PHP dependencies"
cd "$API_DIR"
# --no-dev keeps the debug toolchain, and its attack surface, off the server.
sudo -u www-data COMPOSER_HOME=/tmp/composer composer install \
    --no-dev --optimize-autoloader --no-interaction --quiet 2>/dev/null \
  || composer install --no-dev --optimize-autoloader --no-interaction --quiet

grep -q '^APP_KEY=base64:' .env || php artisan key:generate --force --quiet
ok "application key present"

say "Migrating"
php artisan migrate --force --quiet
php artisan db:seed --class=UserSeeder --force --quiet
ok "schema up to date, bootstrap admin provisioned"

say "Caching config, routes and views"
# Clear first — otherwise a stale cache is what gets re-cached.
php artisan optimize:clear --quiet
php artisan config:cache --quiet
php artisan route:cache --quiet
php artisan view:cache --quiet
ok "cached"

say "Building the client"
cd "$WEB_DIR"
# `npm ci` installs exactly the lockfile; `npm install` can silently drift.
npm ci --no-audit --no-fund --silent
npm run build --silent
[[ -f dist/index.html ]] || die "the build produced no dist/index.html"
ok "dist/ built"

# --------------------------------------------------------------- 5. serving
say "Serving root"
# Symlinks rather than nginx's `alias`: alias mis-resolves paths when combined
# with try_files and Ubuntu's fastcgi-php.conf. A plain root also gives Laravel
# the correct SCRIPT_NAME, which is how it strips the subdirectory from the URL.
mkdir -p "$SERVE_ROOT"
ln -sfn "$WEB_DIR/dist"   "$SERVE_ROOT/inventory_management"
ln -sfn "$API_DIR/public" "$SERVE_ROOT/inventory_management_api"
ok "$SERVE_ROOT/{inventory_management,inventory_management_api}"

say "Permissions"
chown -R www-data:www-data "$API_DIR" "$WEB_DIR"
find "$API_DIR/storage" "$API_DIR/bootstrap/cache" -type d -exec chmod 775 {} \;
ok "owned by www-data"

# ----------------------------------------------------------------- 6. nginx
say "nginx"
cat > /etc/nginx/sites-available/ict-inventory <<NGINX
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name $SERVER_IP;

    # Serves ONLY the two symlinked public directories, never the project roots.
    root $SERVE_ROOT;
    index index.html;

    # The symlinks are the whole mechanism; nginx must follow them.
    disable_symlinks off;

    charset utf-8;
    client_max_body_size 12M;

    gzip on;
    gzip_vary on;
    gzip_min_length 1024;
    gzip_types text/css application/javascript application/json image/svg+xml;

    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options        "SAMEORIGIN" always;
    add_header Referrer-Policy        "strict-origin-when-cross-origin" always;

    location ^~ /inventory_management_api {
        try_files \$uri \$uri/ /inventory_management_api/index.php?\$query_string;

        location ~ \.php\$ {
            include snippets/fastcgi-php.conf;
            fastcgi_pass unix:/run/php/php8.4-fpm.sock;
            fastcgi_hide_header X-Powered-By;
            fastcgi_read_timeout 60s;
        }
    }

    # The client uses hash routing, so every route lives after the '#' and never
    # reaches nginx — no history-mode fallback to get wrong.
    location ^~ /inventory_management {
        try_files \$uri \$uri/ /inventory_management/index.html;

        location ^~ /inventory_management/assets/ {
            access_log off;
            expires 1y;
            add_header Cache-Control "public, immutable";
        }

        location = /inventory_management/index.html {
            add_header Cache-Control "no-store, must-revalidate";
        }
    }

    location = / { return 302 /inventory_management/; }

    location ~ /\. { deny all; return 404; }

    access_log /var/log/nginx/ict-inventory.access.log;
    error_log  /var/log/nginx/ict-inventory.error.log;
}
NGINX

ln -sfn /etc/nginx/sites-available/ict-inventory /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default
nginx -t >/dev/null 2>&1 || { nginx -t; die "nginx config rejected"; }
systemctl reload nginx
ok "site enabled and nginx reloaded"

# ------------------------------------------------------------- 7. scheduler
say "Scheduled tasks"
cat > /etc/systemd/system/ict-inventory-scheduler.service <<UNIT
[Unit]
Description=ICT Inventory scheduled tasks
After=network.target mysql.service

[Service]
Type=oneshot
User=www-data
Group=www-data
WorkingDirectory=$API_DIR
ExecStart=/usr/bin/php artisan schedule:run
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=$API_DIR/storage
UNIT

cat > /etc/systemd/system/ict-inventory-scheduler.timer <<'UNIT'
[Unit]
Description=Run ICT Inventory scheduled tasks every minute

[Timer]
OnCalendar=*-*-* *:*:00
Persistent=true
AccuracySec=10s

[Install]
WantedBy=timers.target
UNIT

systemctl daemon-reload
systemctl enable --now ict-inventory-scheduler.timer >/dev/null 2>&1
ok "prunes expired sign-in codes and stale tokens daily"

# -------------------------------------------------------------- 8. firewall
say "Firewall"
ufw allow OpenSSH >/dev/null 2>&1 || true
ufw allow 'Nginx Full' >/dev/null 2>&1 || true
ufw --force enable >/dev/null 2>&1 || true
ok "OpenSSH and Nginx allowed"

# ---------------------------------------------------------------- 9. verify
say "Verifying"
FAIL=0

HEALTH="$(curl -fsS "http://127.0.0.1/inventory_management_api/api/health" 2>/dev/null || true)"
if [[ "$HEALTH" == *'"status":"ok"'* ]]; then ok "API health OK"; else warn "API health FAILED: $HEALTH"; FAIL=1; fi

CODE="$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/inventory_management_api/api/sync/pull)"
if [[ "$CODE" == "401" ]]; then ok "unauthenticated request returns 401"; else warn "expected 401, got $CODE"; FAIL=1; fi

CODE="$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/inventory_management_api/.env)"
if [[ "$CODE" == "404" ]]; then ok ".env is not served"; else warn "SECRETS EXPOSED: /.env returned $CODE"; FAIL=1; fi

CODE="$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/inventory_management/)"
if [[ "$CODE" == "200" ]]; then ok "UI serves"; else warn "UI returned $CODE"; FAIL=1; fi

ASSET="$(grep -o './assets/index-[^"]*\.js' "$WEB_DIR/dist/index.html" | head -1 | sed 's|^\./||')"
CODE="$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1/inventory_management/$ASSET")"
if [[ "$CODE" == "200" ]]; then ok "assets serve"; else warn "asset $ASSET returned $CODE"; FAIL=1; fi

printf '\n'
if [[ $FAIL -eq 0 ]]; then
    printf '\033[1;32mDeployment complete.\033[0m\n\n'
else
    printf '\033[1;33mDeployed, but some checks failed — see the warnings above.\033[0m\n\n'
fi

printf '  UI    http://%s/inventory_management\n'      "$SERVER_IP"
printf '  API   http://%s/inventory_management_api\n\n' "$SERVER_IP"

ADMIN="$(grep '^SEED_ADMIN_EMAIL=' "$API_DIR/.env" | cut -d= -f2- | tr -d '"')"
printf 'Sign in as: %s\n\n' "$ADMIN"

if ! grep -q '^MAIL_PASSWORD=.\+' "$API_DIR/.env"; then
    printf '\033[1;33mSTILL TO DO — sign-in codes cannot be sent yet:\033[0m\n'
    printf '  1. Put the mailbox app password in %s (MAIL_PASSWORD=)\n' "$API_DIR/.env"
    printf '  2. cd %s && sudo -u www-data php artisan config:cache\n' "$API_DIR"
    printf '  3. Test:\n'
    printf '     sudo -u www-data php artisan tinker --execute="Mail::raw('"'"'test'"'"', fn(\$m) => \$m->to('"'"'you@dswd.gov.ph'"'"')->subject('"'"'test'"'"'));"\n\n'
fi

printf 'Note: the QR scanner will not start over plain HTTP — browsers only\n'
printf 'expose the camera in a secure context. Everything else works; see\n'
printf 'DEPLOYMENT.md. USB barcode scanners are unaffected.\n\n'
