# Deploying to Ubuntu Server

Target: **Ubuntu 24.04 LTS**, nginx + PHP 8.4-FPM + MySQL 8, both projects on one
host. Everything below is copy-paste from a fresh server.

Replace `inventory.example.gov.ph` with your real hostname throughout.

---

## 0. What you need first

- An Ubuntu 24.04 server you can `sudo` on.
- **A DNS name pointing at it.** Not optional — see the HTTPS note below.
- The Gmail app password for `icts.fo11@dswd.gov.ph` (rotate it if it has ever
  been pasted into a chat or ticket).

> ### HTTPS is a functional requirement, not hardening
>
> The QR scanner calls `getUserMedia()`, and browsers only expose the camera in
> a **secure context**. Over plain HTTP the camera silently never starts and the
> app falls back to typing codes by hand. `localhost` is exempt, which is why it
> works in development and would surprise you in production.
>
> If this server is LAN-only with no public DNS, you cannot use Let's Encrypt.
> Use an internal CA or a self-signed certificate distributed to the client
> devices — but do not skip TLS and expect the scanner to work.

---

## 1. Packages

```bash
sudo apt update && sudo apt upgrade -y
```

```bash
sudo apt install -y nginx mysql-server git unzip curl \
  php8.4-fpm php8.4-mysql php8.4-mbstring php8.4-xml php8.4-curl \
  php8.4-zip php8.4-bcmath php8.4-intl
```

If `php8.4-*` is not in your archive, add the PPA first:

```bash
sudo add-apt-repository -y ppa:ondrej/php && sudo apt update
```

Node 20 LTS for the client build:

```bash
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash - && sudo apt install -y nodejs
```

Composer:

```bash
curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin --filename=composer
```

## 2. Database

```bash
sudo mysql_secure_installation
```

Create the database and a user that is **not** root:

```bash
sudo mysql -e "CREATE DATABASE ict_inventory CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

```bash
sudo mysql -e "CREATE USER 'ict_inventory'@'127.0.0.1' IDENTIFIED BY 'CHANGE_ME'; GRANT ALL PRIVILEGES ON ict_inventory.* TO 'ict_inventory'@'127.0.0.1'; FLUSH PRIVILEGES;"
```

## 3. Code

```bash
sudo mkdir -p /var/www && sudo chown "$USER" /var/www
```

```bash
git clone <your-repo-url> /var/www/ict-inventory && cd /var/www/ict-inventory
```

## 4. Configuration

```bash
cp deploy/api.env.production.example api/.env
```

```bash
cp deploy/web.env.production.example web/.env.production
```

Edit `api/.env`: set `APP_URL`, `DB_PASSWORD`, `MAIL_PASSWORD`, and
`FRONTEND_URLS`. Then generate the key:

```bash
cd /var/www/ict-inventory/api && php artisan key:generate --force
```

## 5. Permissions

PHP-FPM runs as `www-data`, and Laravel writes logs, caches and sessions:

```bash
sudo chown -R www-data:www-data /var/www/ict-inventory
```

```bash
sudo find /var/www/ict-inventory/api/storage /var/www/ict-inventory/api/bootstrap/cache -type d -exec chmod 775 {} \;
```

## 6. Deploy

```bash
cd /var/www/ict-inventory && sudo -u www-data ./deploy/deploy.sh
```

The script installs dependencies, migrates, provisions the bootstrap admin,
caches config/routes/views, and builds the client bundle. It refuses to run if
`APP_KEY` is unset or `APP_DEBUG` is still true.

## 7. nginx

```bash
sudo cp deploy/nginx/ict-inventory.conf /etc/nginx/sites-available/ict-inventory
```

Edit the two `server_name` lines and the certificate paths, then:

```bash
sudo ln -sf /etc/nginx/sites-available/ict-inventory /etc/nginx/sites-enabled/ && sudo rm -f /etc/nginx/sites-enabled/default
```

```bash
sudo nginx -t && sudo systemctl reload nginx
```

## 8. TLS

```bash
sudo apt install -y certbot python3-certbot-nginx && sudo certbot --nginx -d inventory.example.gov.ph
```

Renewal is installed as a systemd timer automatically. Verify with
`systemctl list-timers certbot.timer`.

## 9. Scheduled tasks

Prunes expired sign-in codes and stale API tokens:

```bash
sudo cp deploy/systemd/ict-inventory-scheduler.* /etc/systemd/system/ && sudo systemctl daemon-reload
```

```bash
sudo systemctl enable --now ict-inventory-scheduler.timer
```

## 10. Firewall

```bash
sudo ufw allow OpenSSH && sudo ufw allow 'Nginx Full' && sudo ufw --force enable
```

---

## Verify

```bash
curl -fsS https://inventory.example.gov.ph/api/health
```

Then, in a browser:

1. Open the site — you should land on the sign-in screen.
2. Enter the admin address; a code should arrive by email.
3. Sign in, open **Equipment**, and press **Scan** — the camera must start. If it
   does not, you are not on HTTPS.
4. Hard-refresh on `/equipment` — it must load, not 404. That proves the SPA
   fallback is working.

Mail is the piece most likely to fail silently. Test it directly:

```bash
cd /var/www/ict-inventory/api && php artisan tinker --execute="Mail::raw('deployment test', fn(\$m) => \$m->to('you@dswd.gov.ph')->subject('ICT Inventory test'));"
```

No exception means the relay accepted it. If nothing arrives, the problem is
SPF/DKIM for `dswd.gov.ph`, not this application — a From header alone does not
authorise sending as that domain.

---

## Updating

```bash
cd /var/www/ict-inventory && git pull && sudo -u www-data ./deploy/deploy.sh
```

Re-running is safe. Migrations are incremental, the admin seeder is
`updateOrCreate`, and the caches are cleared before being rebuilt.

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
| 502 Bad Gateway | PHP-FPM socket path. Check `php -v` matches `php8.4-fpm.sock` in the nginx config |
| 500 on every API call, blank log | `storage/` not writable by `www-data` |
| Blank page, 404s for `/assets/*.js` | Stale `index.html` cached. The nginx config sends `no-store` for it; clear the browser cache once |
| Refresh on `/equipment` 404s | SPA fallback missing — the `try_files ... /index.html` line |
| Camera never starts | Not a secure context. HTTPS is required |
| Sign-in code never arrives | Check `storage/logs/laravel-*.log`, then SPF/DKIM for the sending domain |
| Config change has no effect | `php artisan config:cache` is stale. `deploy.sh` clears before caching; run it again |

Logs:

```bash
sudo tail -f /var/log/nginx/ict-inventory.error.log /var/www/ict-inventory/api/storage/logs/laravel-$(date +%F).log
```
