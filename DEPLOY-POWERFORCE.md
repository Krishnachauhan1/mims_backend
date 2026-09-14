# Deploy MIMS API — powerforceindustries.com (main domain)

New Hostinger account:

| Item | Value |
|------|--------|
| SSH | `ssh -p 65002 u683672103@145.79.58.104` |
| Domain | `powerforceindustries.com` |
| API URL (target) | `https://powerforceindustries.com/api` |

---

## Step 1 — hPanel: MySQL database

1. **Websites** → **Databases** → **MySQL Databases**
2. Create database + user (note exact names, e.g. `u683672103_mims`)
3. Copy **password** into `.env` later

---

## Step 2 — Document root (fixed `public_html`)

Hostinger **main domain** document root usually **cannot** be changed. Use this layout:

```
domains/powerforceindustries.com/
  public_html/          ← web root (fixed)
    .htaccess           ← routes /api/* to public/index.php (see deploy/hostinger/)
    app/                ← full Laravel project HERE (or in mims/ subfolder)
    bootstrap/
    public/
      index.php
      .htaccess
    storage/
    vendor/
    .env
```

**API URL:** `https://powerforceindustries.com/api/health`

Copy `deploy/hostinger/public_html.htaccess` to `public_html/.htaccess` (backup existing first).

> Safer long-term: keep Laravel in `mims-api/` outside `public_html` + only `public_html/mims/index.php` bootstrap — see section “Alternative layout” at bottom.

---

## Step 2b — Hostinger PHP limits (SSH)

Shared hosting often disables **`exec()`** and **`proc_open()`**. You may see:

- `Call to undefined function Illuminate\Filesystem\exec()` on `storage:link`
- `proc_open` error during `composer install` post-scripts

**This is normal.** Migrations/seeders can still succeed.

### Composer (SSH)

```bash
composer install --no-dev --optimize-autoloader --no-scripts
```

Upload `vendor/` from your Mac if SSH composer fails completely.

### Storage link (manual — instead of `php artisan storage:link`)

From Laravel project root (where `app/` and `public/` exist):

```bash
cd ~/domains/powerforceindustries.com/public_html
ln -sfn "$(pwd)/storage/app/public" "$(pwd)/public/storage"
ls -la public/storage
```

Document uploads need this symlink for `/storage/...` URLs.

---

## Step 3 — Upload backend from Mac

```bash
cd manufacturing_and_inventory_management_backend
chmod +x deploy-powerforce.sh
./deploy-powerforce.sh
```

Or manual rsync — same paths as in `deploy-powerforce.sh`.

---

## Step 4 — SSH: configure & migrate

```bash
ssh -p 65002 u683672103@145.79.58.104
cd ~/domains/powerforceindustries.com/mims-api
```

Edit `.env`:

```bash
nano .env
```

Set (from hPanel):

```
APP_URL=https://powerforceindustries.com
DB_HOST=127.0.0.1
DB_DATABASE=u683672103_mims
DB_USERNAME=u683672103_mims
DB_PASSWORD="paste_exact_password_from_hpanel"
SESSION_DOMAIN=powerforceindustries.com
SANCTUM_STATEFUL_DOMAINS=powerforceindustries.com,www.powerforceindustries.com
CORS_ALLOWED_ORIGINS=*
```

Then:

```bash
composer install --no-dev --optimize-autoloader --no-scripts
php artisan key:generate --force
php artisan optimize:clear
php artisan migrate --force
php artisan db:seed --class=AdminSeeder --force
php artisan db:seed --class=WorkflowSeeder --force
# storage:link fails on Hostinger — use manual symlink:
ln -sfn "$(pwd)/storage/app/public" "$(pwd)/public/storage"
chmod -R 775 storage bootstrap/cache
```

Verify:

```bash
curl -4 https://powerforceindustries.com/api/health
curl -4 -X POST https://powerforceindustries.com/api/auth/login \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"email":"gaurav@gmail.com","password":"password"}'
```

---

## Step 5 — Security (Hostinger)

1. **Bot Protection** → **OFF** for `powerforceindustries.com` (or allowlist `/api/*`)  
   Otherwise Postman / Flutter get **403 Bot Verification**.
2. **SSL** → enable HTTPS for the domain (Auto SSL).

---

## Step 6 — Flutter app API URL

Update `manufacring-and-inventory-management/assets/config/app_config.json`:

```json
"base_url": "https://powerforceindustries.com/api"
```

And `lib/config/app_config.dart` fallback if used:

```dart
static const String fallbackBaseUrl = 'https://powerforceindustries.com/api';
```

Rebuild / hot restart the app.

---

## Old folder `public_html/mims`

You currently have `~/domains/powerforceindustries.com/public_html/mims`.

- **Option A (recommended):** Use new path `mims-api/` + document root above; ignore or delete old `public_html/mims` after migration.
- **Option B:** If you must keep `/mims` in URL → API becomes `https://powerforceindustries.com/mims/api` — requires different document root / rewrite; not recommended for production app.

---

## Troubleshooting

| Issue | Fix |
|-------|-----|
| `composer` proc_open error | Use `--no-scripts`; or upload `vendor/` from Mac |
| `storage:link` exec() error | Manual: `ln -sfn storage/app/public public/storage` |
| 404 on `/api/health` | Add `public_html/.htaccess` from `deploy/hostinger/` |
| 500 login | Check `.env` DB password, `php artisan key:generate`, `storage/` permissions |
| 403 HTML captcha | Disable Bot Protection in hPanel |
| `composer` not found | Run `composer install` locally, rsync `vendor/` to server |

---

## Quick SSH path reference

```bash
cd ~/domains/powerforceindustries.com/mims-api
tail -50 storage/logs/laravel.log
php artisan optimize:clear
```
