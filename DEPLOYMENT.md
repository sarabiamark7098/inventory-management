# Deploying to Ubuntu Server

Target: **Ubuntu 24.04 LTS**, nginx + PHP 8.4-FPM + MySQL 8.

| | URL | Directory on the server |
| --- | --- | --- |
| UI | `http://172.26.120.49/inventory_management` | `/var/www/web` |
| API | `http://172.26.120.49/inventory_management_api` | `/var/www/api` |

Only those two directories are copied to the server. Everything else — nginx
config, systemd units, commands — is inline below, so nothing extra needs to be
deployed. If your IP differs, change it in step 8 and in `api/.env`.

---

## Read this before you start: the camera scanner will not work

Browsers only expose the camera (`getUserMedia`) in a **secure context** —
HTTPS, or `localhost`. `http://172.26.120.49` is neither.

**Consequence:** the QR/barcode scanner will silently never start. Nothing
crashes; the camera just stays off and the app falls back to its text input.
Everything else — registration, checkout, returns, sync, offline — works
normally, and USB barcode scanners still work because they type the code and
press Enter.

If camera scanning matters you need a certificate: an internal CA certificate
distributed to the client devices is the usual answer on a government LAN. A
public certificate authority cannot issue for a private IP, and clicking through
a self-signed warning is *not* enough — browsers still refuse camera access on a
certificate they do not trust.

Deploy on HTTP first and confirm everything else works; add TLS when you have a
name or a CA. Nothing in the application changes — only nginx and the two URLs.

---

## Fast path: one command

If you just want it running, copy `install-on-server.sh` to the server alongside
the two project directories and run it. It performs every step below and then
verifies the result.

```bash
sudo bash install-on-server.sh
```

It expects `/var/www/api` and `/var/www/web` to already exist, and is safe to
re-run — packages install only if missing, the database user is created only if
absent, and the app key and database password are generated once then left
alone.

It never asks for a password: the database password is generated on the server
and written into `api/.env`. The one secret you must add by hand is
`MAIL_PASSWORD`, and it will tell you so at the end.

The rest of this document is the same procedure done manually, and is worth
reading if a step fails.

---

## 1. Packages

```bash
sudo apt update && sudo apt upgrade -y
```

```bash
sudo apt install -y nginx mysql-server git unzip curl php8.4-fpm php8.4-mysql php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip php8.4-bcmath php8.4-intl
```

If `php8.4-*` is not in your archive, add the PPA first:

```bash
sudo add-apt-repository -y ppa:ondrej/php && sudo apt update
```

Node 20 LTS (only needed to build the client) and Composer:

```bash
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash - && sudo apt install -y nodejs
```

```bash
curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin --filename=composer
```

## 2. Database

```bash
sudo mysql_secure_installation
```

```bash
sudo mysql -e "CREATE DATABASE ict_inventory CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

Create a user that is **not** root — replace `CHANGE_ME`:

```bash
sudo mysql -e "CREATE USER 'ict_inventory'@'127.0.0.1' IDENTIFIED BY 'CHANGE_ME'; GRANT ALL PRIVILEGES ON ict_inventory.* TO 'ict_inventory'@'127.0.0.1'; FLUSH PRIVILEGES;"
```

## 3. Code

Copy the two project directories to `/var/www`:

```
/var/www/api    <- this repo's api/
/var/www/web    <- this repo's web/
```

## 4. Configuration

Each project ships its own production template:

```bash
cp /var/www/api/.env.production.example /var/www/api/.env
```

```bash
cp /var/www/web/.env.production.example /var/www/web/.env.production
```

Edit `/var/www/api/.env` and set `DB_PASSWORD` and `MAIL_PASSWORD`. `APP_URL`
and `FRONTEND_URLS` already point at `172.26.120.49`.

```bash
cd /var/www/api && php artisan key:generate --force
```

`web/.env.production` needs no edits — `VITE_API_URL` is the only build-time
setting the client has, and it is already `/inventory_management_api/api`.

## 5. Serving root

The URLs are `/inventory_management*` but the directories are `api` and `web`,
and what must actually be served is one level deeper (`public/` and `dist/`).
Two symlinks bridge that:

```bash
sudo mkdir -p /srv/ict-inventory
```

```bash
sudo ln -sfn /var/www/web/dist /srv/ict-inventory/inventory_management && sudo ln -sfn /var/www/api/public /srv/ict-inventory/inventory_management_api
```

Symlinks rather than nginx's `alias`: `alias` mis-resolves paths when combined
with `try_files` and Ubuntu's `fastcgi-php.conf`, whose `try_files
$fastcgi_script_name` looks *inside* the alias and 404s. A plain `root` also
gives Laravel the correct `SCRIPT_NAME`, which is how it strips the
subdirectory from the URL and matches its routes.

This also means nginx never serves `/var/www` itself, so `api/.env` is not
reachable over HTTP.

## 6. Build and migrate

```bash
cd /var/www/api && composer install --no-dev --optimize-autoloader --no-interaction
```

`--no-dev` keeps the debug toolchain, and its attack surface, off the server.

```bash
cd /var/www/api && php artisan migrate --force && php artisan db:seed --class=UserSeeder --force
```

Cache config, routes and views. **Clear first** — otherwise a stale cache is
what gets re-cached:

```bash
cd /var/www/api && php artisan optimize:clear && php artisan config:cache && php artisan route:cache && php artisan view:cache
```

Build the client. `npm ci` installs exactly the lockfile; `npm install` can
silently drift:

```bash
cd /var/www/web && npm ci && npm run build
```

## 7. Permissions

PHP-FPM runs as `www-data`, and Laravel writes logs, caches and sessions:

```bash
sudo chown -R www-data:www-data /var/www/api /var/www/web
```

```bash
sudo find /var/www/api/storage /var/www/api/bootstrap/cache -type d -exec chmod 775 {} \;
```

## 8. nginx

```bash
sudo tee /etc/nginx/sites-available/ict-inventory > /dev/null <<'NGINX'
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name 172.26.120.49;

    # Serves ONLY the two symlinked public directories, never the project roots.
    root /srv/ict-inventory;
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

    # ---------------------------------------------------------------- API
    # `^~` stops nginx testing regex locations for these URIs, so the PHP
    # handler is nested inside rather than declared at top level.
    location ^~ /inventory_management_api {
        try_files $uri $uri/ /inventory_management_api/index.php?$query_string;

        location ~ \.php$ {
            include snippets/fastcgi-php.conf;
            fastcgi_pass unix:/run/php/php8.4-fpm.sock;
            fastcgi_hide_header X-Powered-By;
            fastcgi_read_timeout 60s;
        }
    }

    # ---------------------------------------------------------------- SPA
    # The client uses hash routing, so every route lives after the '#' and
    # never reaches nginx. This only ever serves index.html and the
    # fingerprinted assets — no history-mode fallback to get wrong.
    location ^~ /inventory_management {
        try_files $uri $uri/ /inventory_management/index.html;

        location ^~ /inventory_management/assets/ {
            access_log off;
            expires 1y;
            add_header Cache-Control "public, immutable";
        }

        # Never cache the entry point, or a browser keeps loading a stale
        # bundle referencing asset filenames the last deploy deleted.
        location = /inventory_management/index.html {
            add_header Cache-Control "no-store, must-revalidate";
        }
    }

    # Anyone hitting the bare IP lands on the app rather than a 404.
    location = / {
        return 302 /inventory_management/;
    }

    # Belt and braces: nothing dotted is ever served, wherever it lives.
    location ~ /\. {
        deny all;
        return 404;
    }

    access_log /var/log/nginx/ict-inventory.access.log;
    error_log  /var/log/nginx/ict-inventory.error.log;
}
NGINX
```

```bash
sudo ln -sfn /etc/nginx/sites-available/ict-inventory /etc/nginx/sites-enabled/ && sudo rm -f /etc/nginx/sites-enabled/default
```

```bash
sudo nginx -t && sudo systemctl reload nginx
```

## 9. Scheduled tasks

Prunes expired sign-in codes and stale API tokens. A systemd timer rather than a
crontab entry, so output lands in the journal instead of root's mail spool:

```bash
sudo tee /etc/systemd/system/ict-inventory-scheduler.service > /dev/null <<'UNIT'
[Unit]
Description=ICT Inventory scheduled tasks
After=network.target mysql.service

[Service]
Type=oneshot
User=www-data
Group=www-data
WorkingDirectory=/var/www/api
ExecStart=/usr/bin/php artisan schedule:run

# The scheduler only reads and prunes; it needs no wider access than that.
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=/var/www/api/storage
UNIT
```

```bash
sudo tee /etc/systemd/system/ict-inventory-scheduler.timer > /dev/null <<'UNIT'
[Unit]
Description=Run ICT Inventory scheduled tasks every minute

[Timer]
OnCalendar=*-*-* *:*:00
# If the server was down, run once on resume rather than replaying every
# missed minute.
Persistent=true
AccuracySec=10s

[Install]
WantedBy=timers.target
UNIT
```

```bash
sudo systemctl daemon-reload && sudo systemctl enable --now ict-inventory-scheduler.timer
```

## 10. Firewall

```bash
sudo ufw allow OpenSSH && sudo ufw allow 'Nginx Full' && sudo ufw --force enable
```

---

## Verify

```bash
curl -fsS http://172.26.120.49/inventory_management_api/api/health
```

Expect `{"status":"ok",...}`. Then check the API refuses anonymous access
properly — a 401, **not** a 500:

```bash
curl -s -o /dev/null -w '%{http_code}\n' http://172.26.120.49/inventory_management_api/api/sync/pull
```

Confirm the secrets are not published. This must return `404` — if it returns
`200`, the response body is your app key, database password and mailbox
password:

```bash
curl -s -o /dev/null -w '%{http_code}\n' http://172.26.120.49/inventory_management_api/.env
```

In a browser at `http://172.26.120.49/inventory_management`:

1. The sign-in screen loads **with styling**. Unstyled means the assets 404 —
   check the symlinks exist and `disable_symlinks off` is present.
2. Enter the admin address; a code arrives by email.
3. Sign in and open **Equipment**. The URL becomes
   `…/inventory_management/#/equipment` — the `#` is expected, see below.
4. **Hard-refresh on that page.** It must load. Everything after the `#` never
   reaches nginx, so this works with no server-side fallback.
5. The scanner will not open the camera — expected on plain HTTP, see the top of
   this file.

### Why the URLs contain `#`

The client uses hash routing. The app is served from a subdirectory of a host it
does not own the root of, and with history routing that needs the build to know
its own path *and* nginx to serve `index.html` for every unknown route — two
settings that must agree, and the usual cause of "works until you press
refresh". With hash routing the path never changes, so one build runs at any
mount point and there is no fallback to misconfigure.

If you would rather have clean URLs, say so — it means reinstating a build-time
base path and the matching nginx fallback.

### Mail

The piece most likely to fail silently:

```bash
cd /var/www/api && php artisan tinker --execute="Mail::raw('deployment test', fn(\$m) => \$m->to('you@dswd.gov.ph')->subject('ICT Inventory test'));"
```

No exception means the relay accepted it. If nothing arrives, the problem is
SPF/DKIM for `dswd.gov.ph`, not this application — a From header alone does not
authorise sending as that domain.

---

## Updating

Copy the new `api/` and `web/` over, then repeat **step 6**. It is safe to
re-run: migrations are incremental, the admin seeder is `updateOrCreate`, and
the caches are cleared before being rebuilt.

Re-run the `chown` from step 7 afterwards if you copied as a different user.

## Backups

The database is the whole system; the code is replaceable.

```bash
mysqldump --single-transaction --routines ict_inventory | gzip > "ict-inventory-$(date +%F).sql.gz"
```

`--single-transaction` takes a consistent snapshot without locking writers out.
Put it on a timer and copy the output off the box — a backup on the same disk is
not a backup.

## Troubleshooting

| Symptom | Cause |
| --- | --- |
| Page loads unstyled, assets 404 | Symlink missing, or `disable_symlinks off` absent so nginx will not follow it |
| API 404 on every route | Symlink points at the project root instead of `public/` |
| 502 Bad Gateway | PHP-FPM socket path. Check `php -v` matches `php8.4-fpm.sock` in the config |
| 500 on every API call, empty log | `storage/` not writable by `www-data` |
| 500 instead of 401 when signed out | Older build; fixed by `redirectGuestsTo` in `bootstrap/app.php` |
| `.env` downloadable over HTTP | Something is serving `/var/www` directly. Check no other nginx site is enabled |
| Camera never starts | Not a secure context. Expected on a bare IP over HTTP |
| Sign-in code never arrives | `storage/logs/laravel.log`, then SPF/DKIM for the sending domain |
| Config change has no effect | Stale `config:cache`. Re-run the cache commands in step 6 |

Logs:

```bash
sudo tail -f /var/log/nginx/ict-inventory.error.log /var/www/api/storage/logs/laravel.log
```
