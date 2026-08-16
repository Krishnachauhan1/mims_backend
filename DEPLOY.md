# Deploy MIMS API to mis.nextlogicsolution.id

## Server requirements

- PHP **8.3+** with extensions: `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `json`, `mbstring`, `openssl`, `pdo`, `pdo_mysql`, `tokenizer`, `xml`
- MySQL database (created in hPanel)
- Composer (SSH) or upload `vendor/` from local `composer install --no-dev --optimize-autoloader`

## 1. Upload project

Upload the Laravel project to the server (e.g. `~/domains/mis.nextlogicsolution.id/` or similar).

**Document root must point to the `public/` folder**, not the project root.

In Hostinger hPanel:

1. **Domains** → `mis.nextlogicsolution.id` → **Document root**
2. Set to: `.../manufacturing_and_inventory_management_backend/public`

## 2. Environment file

On the server, copy the production env:

```bash
cp .env.production.example .env
# Or upload the local .env.production file and rename it to .env
```

Edit `.env` and set:

| Variable | Value |
|----------|-------|
| `APP_URL` | `https://mis.nextlogicsolution.id` |
| `DB_DATABASE` | `u336943698_mis` (verify exact name in hPanel → Databases) |
| `DB_USERNAME` | `u336943698_mis` |
| `DB_PASSWORD` | **Exact** password from hPanel → Databases (NOT the same as username) |

Generate application key (required once):

```bash
php artisan key:generate
```

## 3. Install dependencies & migrate

```bash
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan config:clear
php artisan migrate --force
php artisan db:seed --class=AdminSeeder --force
php artisan storage:link
php artisan cache:clear
php artisan route:cache
php artisan view:cache
```

> **Important:** Run `key:generate` and `config:clear` before migrate. If `APP_KEY` is empty, login will return **500**.

> **Hostinger:** Do **not** run plain `php artisan config:cache` — the server may inject `DB_HOST=localhost` and bake the wrong DB settings. Either skip config cache entirely (use `config:clear` only), or force the correct values when caching:

```bash
DB_HOST=127.0.0.1 php artisan config:cache
```

## 4. Folder permissions

```bash
chmod -R 775 storage bootstrap/cache
```

Ensure the web server user can write to `storage/` and `bootstrap/cache/`.

## 5. Verify API

```bash
curl https://mis.nextlogicsolution.id/up
curl https://mis.nextlogicsolution.id/api/health
```

`/api/health` should return `{"status":"ok","database":"connected","users":1}`. If it returns 503, the web server cannot reach MySQL (see Troubleshooting).

Login test:

```bash
curl -X POST https://mis.nextlogicsolution.id/api/auth/login \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"email":"harish@nationalelectric.pk","password":"password"}'
```

## 6. Flutter app

Release builds use `https://mis.nextlogicsolution.id/api` automatically.

Local development against your machine:

```bash
flutter run --dart-define=API_BASE_URL=http://127.0.0.1:8000/api
```

Android emulator:

```bash
flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000/api
```

## Troubleshooting

### Entire site returns 500 (including `/up`)

Usually a **corrupt `.env`** — especially `APP_KEY` with multiple `base64:` values stuck together, or `SESSION_DRIVER` typo.

Recovery:

```bash
cd ~/domains/nextlogicsolution.id/laravel/public/mis
nano .env
```

Fix these lines (delete broken `APP_KEY` line entirely first):

```
DB_HOST=127.0.0.1
DB_PASSWORD="your_password_with_special_chars"
SESSION_DRIVER=file
CACHE_STORE=file
```

Then:

```bash
php artisan key:generate    # only after APP_KEY line is deleted
grep APP_KEY .env         # must show ONE base64 value
php artisan config:clear
php artisan cache:clear
curl -s https://mis.nextlogicsolution.id/up
curl -s https://mis.nextlogicsolution.id/api/health
```

Do **not** run `php artisan config:cache` until health returns `ok`.

### Login returns 500

This almost always means the **web PHP process cannot connect to MySQL** (CLI `artisan migrate` may still work).

On SSH, run:

```bash
cd ~/domains/nextlogicsolution.id/laravel/public/mis
tail -30 storage/logs/laravel.log
php artisan tinker --execute="echo App\Models\User::count();"
curl -s https://mis.nextlogicsolution.id/api/health
```

If you see `Access denied for user 'u336943698_mis'@'localhost'`, the **DB password in `.env` is wrong**.

### Fix wrong DB password

1. Open **Hostinger hPanel** → **Databases** → **MySQL Databases**
2. Find database `u336943698_mis` and user `u336943698_mis`
3. Copy the **database password** (or reset it and copy the new one)
4. On SSH, edit `.env`:

```bash
cd ~/domains/nextlogicsolution.id/laravel/public/mis
nano .env
```

Set these lines (paste the real password — do **not** use the username as password).

If the password contains special characters (`*`, `#`, `$`, `!`, spaces), wrap it in **double quotes**:

```
DB_HOST=127.0.0.1
DB_DATABASE=u336943698_mis
DB_USERNAME=u336943698_mis
DB_PASSWORD="your_password_with_special_chars"
```

Also ensure `APP_KEY` is a **single** value (not multiple keys stuck together). If it looks like `base64:xxx=base64:yyy`, **delete the entire `APP_KEY` line** in nano first — do **not** run `key:generate` until the old line is gone, or each run appends another key:

```bash
# In nano: delete the whole APP_KEY=... line, save, then run ONCE:
php artisan key:generate
```

Verify only one key exists:

```bash
grep APP_KEY .env
```

Save (`Ctrl+O`, Enter, `Ctrl+X`), then re-cache:

```bash
php artisan config:clear
php artisan cache:clear
# Hostinger: either skip config:cache, or force DB host when caching:
DB_HOST=127.0.0.1 php artisan config:cache
php artisan route:cache
```

Verify DB connects:

```bash
php artisan tinker --execute="echo App\Models\User::count();"
curl -s https://mis.nextlogicsolution.id/api/health
```

Both should succeed before testing login.

Test login again:

```bash
curl -X POST https://mis.nextlogicsolution.id/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"harish@nationalelectric.pk","password":"password"}'
```

| Issue | Fix |
|-------|-----|
| 500 on login | Run steps above; check `/api/health` and `laravel.log` |
| 500 error (general) | Check `storage/logs/laravel.log`; ensure `APP_KEY` is set |
| Database connection failed | Confirm DB name/user/password in hPanel → MySQL Databases |
| 404 on `/api/*` | Document root must be `public/`; enable `mod_rewrite` |
| Mixed content / HTTP redirects | `APP_URL` must use `https://`; trust proxies is enabled in `bootstrap/app.php` |
| CORS errors (Flutter web) | Set `CORS_ALLOWED_ORIGINS=*` or your frontend origin in `.env` |

## Demo login (after seed)

- Email: `harish@nationalelectric.pk`
- Password: `password`

Change passwords before going live.

## Clear data (keep admin only)

Removes all clients, vendors, inventory, transactions, seed/demo records, etc. **Admin users are kept.**

```bash
php artisan db:clear-except-admin --force
```

Without `--force`, the command asks for confirmation first.
