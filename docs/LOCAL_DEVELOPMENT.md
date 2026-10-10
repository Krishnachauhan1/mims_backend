# Local development (Mac + live database copy)

## Prerequisites

- PHP 8.2+ (`php -v`)
- Composer
- MySQL or MariaDB locally (`brew install mysql` or DBngin)
- Flutter SDK (for the app)

## 1. Backend API

```bash
cd manufacturing_and_inventory_management_backend
chmod +x scripts/*.sh
./scripts/setup-local.sh
```

Edit `.env` if your local MySQL user/password differ from `.env.local.example`.

Start the API:

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

Health check: [http://127.0.0.1:8000/api/health](http://127.0.0.1:8000/api/health)

## 2. Live database on your Mac (recommended)

Hostinger usually **blocks remote MySQL** from your laptop. Export on the server, download, import locally.

**On server (SSH):**

```bash
cd ~/domains/powerforceindustries.com/public_html
mysqldump -u YOUR_DB_USER -p YOUR_DB_NAME > ~/powerforce-live.sql
```

**On your Mac:**

```bash
scp -P 65002 u683672103@145.79.58.104:~/powerforce-live.sql ~/Downloads/
cd manufacturing_and_inventory_management_backend
./scripts/import-live-database.sh ~/Downloads/powerforce-live.sql
```

Use the same `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` in `.env` as in `.env.local.example` (or create `mims_local` and import there).

## 3. Flutter app (points to local API in debug)

In **debug** builds, the app loads `assets/config/app_config.local.json` first (`http://127.0.0.1:8000/api`).

```bash
cd manufacring-and-inventory-management
flutter pub get
flutter run -d chrome
# or: flutter run   # Android/iOS — use 10.0.2.2:8000 for Android emulator (see below)
```

**Android emulator:** change `app_config.local.json` base URL to `http://10.0.2.2:8000/api`.

**Physical phone on same Wi‑Fi:** use your Mac LAN IP, e.g. `http://192.168.1.5:8000/api`, and run `php artisan serve --host=0.0.0.0 --port=8000`.

Release / production builds still use `app_config.json` (live server).

## 4. Login

Use the same usernames/passwords as on live (from imported `users` table).

## 5. Optional: Firebase push locally

Copy `storage/app/firebase/service-account.json` from server into the same path locally if you need FCM tests.
