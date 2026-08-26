# Deployment

LearnoCentric ships as two independently deployed pieces:

| Piece        | What it is                                  | Where it runs                          |
|--------------|---------------------------------------------|----------------------------------------|
| **Frontend** | Angular 20 SPA (static browser build)       | **Cloudflare Pages**                   |
| **API**      | PHP 8.2+ / Slim + PostgreSQL                | **aaPanel server** (Cloudflare-proxied)|

The production browser build calls the API **directly** at
`https://api.YOUR_DOMAIN` (baked into `apps/web/src/app/app.config.ts`). There is
**no** Cloudflare Worker or `/backend` reverse-proxy to configure — the SPA and the
API are separate origins wired together by CORS. Files are path-only and served by
the API at `https://api.YOUR_DOMAIN/backend/files?p=…`.

> Replace `YOUR_DOMAIN` throughout with your real domain, and every `change-me` /
> empty secret with a real value. Nothing below asks you to share credentials — you
> set them directly on the server.

---

## 0. Prerequisites

- A domain on Cloudflare (e.g. `learnocentric.com`), with DNS managed by Cloudflare.
- An aaPanel server (Linux) you can SSH into, with:
  - **PHP 8.2 or newer** + extensions: `pdo`, `pdo_pgsql`, `mbstring`, `ctype`, `openssl`, `curl`, `json`, `fileinfo`.
  - **PostgreSQL 14+** (aaPanel App Store → *PostgreSQL Manager*, or an external managed Postgres).
  - **Composer 2**.
- Node 20+ locally (or in CI) to build the frontend.

> **aaPanel PHP note:** aaPanel installs each PHP version under its own path
> (e.g. `/www/server/php/82/bin/php`), and the `php` on your `$PATH` may be a
> *different* version than the one serving the site. Run every `php` / `composer`
> command below with the **8.2+ binary** — e.g. `/www/server/php/82/bin/php bin/console.php …`
> — or symlink it. aaPanel also disables some functions by default
> (Website → PHP settings → *Disabled functions*); the API needs none of the
> commonly-disabled ones (`exec`, `proc_open`, `putenv`), so the defaults are fine.

---

## 1. API → aaPanel server

### 1.1 Create the site

1. aaPanel → **Website → Add site**
   - Domain: `api.YOUR_DOMAIN`
   - PHP version: **8.2+**
   - Leave "Create database" unchecked (we use PostgreSQL, added below).
2. This creates a docroot like `/www/wwwroot/api.YOUR_DOMAIN`.

### 1.2 Get the code onto the server

```bash
cd /www/wwwroot/api.YOUR_DOMAIN
git clone https://github.com/YOUR_ORG/learnocentric.com.git .
cd apps/api
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader
```

> The Slim front controller is `apps/api/public/index.php`. Point the site's
> **document root** at `/www/wwwroot/api.YOUR_DOMAIN/apps/api/public`
> (Website → Settings → **Site directory / Running directory**).
>
> **Running as root:** aaPanel shells in as `root`; Composer warns against this.
> `COMPOSER_ALLOW_SUPERUSER=1` suppresses the prompt. Afterwards, hand the tree
> back to the web user so runtime writes work: `chown -R www:www /www/wwwroot/api.YOUR_DOMAIN`.
>
> **`ext-redis` conflict:** if an older `composer install` ever fails with
> *"symfony/cache … conflicts with ext-redis <6.1"* (the server has phpredis < 6.1),
> it's already handled — `composer.json` sets `config.platform.ext-redis: false`
> so Composer ignores the unused Redis extension during resolution (the API uses a
> filesystem metadata cache, never Redis). No `--ignore-platform-req` flag needed.

### 1.3 PostgreSQL database

In *PostgreSQL Manager* (or via `psql`) create a database and user:

```sql
CREATE DATABASE learnocentric;
CREATE USER learno WITH PASSWORD 'CHANGE_ME_STRONG_DB_PASSWORD';
GRANT ALL PRIVILEGES ON DATABASE learnocentric TO learno;
```

### 1.4 Environment file

```bash
cd /www/wwwroot/api.YOUR_DOMAIN/apps/api
cp .env.example .env
```

Edit `.env` for production:

```dotenv
APP_ENV=prod
APP_DEBUG=false
APP_URL=https://api.YOUR_DOMAIN

# The SPA origin(s) allowed to call the API (comma-separated, no trailing slash).
CORS_ALLOWED_ORIGINS=https://YOUR_DOMAIN,https://www.YOUR_DOMAIN

# Turn on table-driven RBAC only AFTER verifying the seeded grant matrix in staging.
RBAC_ENFORCE=false

DB_DRIVER=pdo_pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_NAME=learnocentric
DB_USER=learno
DB_PASSWORD=CHANGE_ME_STRONG_DB_PASSWORD

JWT_SECRET=CHANGE_ME_LONG_RANDOM_STRING   # e.g. `openssl rand -hex 48`
JWT_TTL=86400
JWT_ISSUER=learnocentric

# File storage. Uploads are served ONLY through the access-controlled route
# /backend/files?p=… (streamed via Flysystem) — never as a static URL. Keep the
# store OUTSIDE the web docroot (public/) so files can't be fetched directly;
# `storage/uploads` resolves to apps/api/storage/uploads. STORAGE_PUBLIC_URL is
# NOT used for serving (the SPA builds path-only /backend/files references).
STORAGE_DRIVER=local
STORAGE_LOCAL_ROOT=storage/uploads
STORAGE_PUBLIC_URL=https://api.YOUR_DOMAIN

# Optional integrations — leave blank to disable that feature.
ZEPTOMAIL_TOKEN=
MAIL_FROM_ADDRESS=noreply@YOUR_DOMAIN
MAIL_FROM_NAME=LearnoCentric
# Agora live video (console.agora.io → project → enable App Certificate).
# Both must be set for live classes to connect; blank disables live video.
AGORA_APP_ID=
AGORA_APP_CERTIFICATE=
PAYSTACK_SECRET_KEY=
PAYSTACK_PUBLIC_KEY=
PAYSTACK_CALLBACK_URL=https://YOUR_DOMAIN/billing/callback
```

### 1.5 Migrate, seed + generate proxies

```bash
cd /www/wwwroot/api.YOUR_DOMAIN/apps/api
composer migrate                        # migrations:migrate --no-interaction
composer seed                           # app:seed (idempotent — safe to re-run)
php bin/console.php orm:generate-proxies # REQUIRED in prod (see note below)
```

`app:seed` is idempotent: each block guards on existing rows, so re-running never
duplicates data. It seeds the full pilot dataset (accounts, academic spine,
assessments, worksheets, billing, content, support, messaging, interventions,
safeguarding, reports, audit logs) so every screen has data to render.

> **Why `orm:generate-proxies`?** Doctrine's `dev_mode` follows `APP_DEBUG`. With
> `APP_DEBUG=false` (production) Doctrine **never** auto-generates entity proxy
> classes at runtime, so they must be built ahead of time — otherwise the first
> lazy-loaded association throws *"proxy class not found"*. Re-run this command on
> every deploy that changes entities (it's in the update flow in §4). If you'd
> rather trade a little performance to skip it, set `APP_DEBUG=true`, but leave
> `APP_ENV=prod` and keep the debug error page off at the web-server level.

### 1.6 Writable runtime + uploads directories

The API writes to `apps/api/var/` (logs, cache, generated proxies) and stores
uploads under `apps/api/storage/uploads` (per §1.4, outside the docroot). Both
must be **writable by the PHP user** (`www` on aaPanel) and the store must be
**persistent** across deploys (never wipe it on `git pull`):

```bash
cd /www/wwwroot/api.YOUR_DOMAIN/apps/api
mkdir -p storage/uploads var/log var/cache var/doctrine/proxies
chown -R www:www storage var       # aaPanel's PHP-FPM runs as user `www`
chmod -R 775 storage var
```

### 1.7 Web-server rewrite (route everything to the front controller)

Slim needs all non-file requests routed to `public/index.php`. aaPanel uses Nginx
by default — add this to the site's **URL Rewrite** config:

```nginx
location / {
    try_files $uri $uri/ /index.php$is_args$args;
}
```

If the site runs Apache instead, a `.htaccess` in `public/` with the standard Slim
front-controller rewrite works the same way.

Verify: `curl https://api.YOUR_DOMAIN/health` should return a JSON health payload.
(All application routes are under `/backend/*`; only `/health` sits at the root.)

### 1.8 DNS + TLS via Cloudflare

1. Cloudflare → **DNS**: add an `A` record `api` → your server's public IP,
   **Proxy status: Proxied** (orange cloud).
2. **SSL/TLS mode: Full (strict)**. Install a certificate on the origin — either
   aaPanel's Let's Encrypt (Website → SSL) or a Cloudflare **Origin Certificate**.

---

## 2. Frontend → Cloudflare Pages

### 2.1 Confirm the API base URL

Production API base is set in `apps/web/src/app/app.config.ts`:

```ts
{ provide: API_BASE_URL, useValue: isDevMode() ? '' : 'https://api.YOUR_DOMAIN' }
```

If your domain differs from the committed default, update that line (and the SSR
value in `app.config.server.ts`) before building.

### 2.2 Build the static SPA

Although the project is SSR-capable, the Cloudflare deploy is a **static SPA**. The
`build:pages` script produces the browser bundle and copies `index.csr.html` →
`index.html` so Pages can serve it:

```bash
cd apps/web
npm ci
npm run build:pages
```

Output: `apps/web/dist/learno-client/browser` — this is the folder Cloudflare serves.
It already contains `_redirects` (SPA fallback: `/* → /index.html 200`).

### 2.3 Deploy to Cloudflare Pages

**Option A — Git integration (recommended):** Cloudflare → **Workers & Pages →
Create → Pages → Connect to Git**, then:

| Setting                 | Value                                   |
|-------------------------|-----------------------------------------|
| Build command           | `cd apps/web && npm ci && npm run build:pages` |
| Build output directory  | `apps/web/dist/learno-client/browser`   |
| Node version            | `20` (set `NODE_VERSION=20` env var)    |

**Option B — Direct upload (Wrangler):**

```bash
cd apps/web
npm run build:pages
npx wrangler pages deploy dist/learno-client/browser --project-name=learnocentric
```

### 2.4 Custom domain

Cloudflare Pages → your project → **Custom domains** → add `YOUR_DOMAIN`
(and `www.YOUR_DOMAIN`). Cloudflare wires the DNS automatically.

---

## 3. Post-deploy checklist

- [ ] `curl https://api.YOUR_DOMAIN/health` returns healthy JSON.
- [ ] `https://YOUR_DOMAIN` loads the SPA; hard-refresh on a deep route (e.g.
      `/dashboard`) still loads (confirms the `_redirects` SPA fallback).
- [ ] Sign in works — confirms CORS (`CORS_ALLOWED_ORIGINS` includes the Pages
      origin) and JWT are correct. Browser console shows **no** CORS errors.
- [ ] Upload a file in any form (e.g. school logo, worksheet) — the green progress
      bar advances and the file renders back via `/backend/files?p=…`. (If uploads
      500, check `apps/api/var/log` and that `storage/uploads` is writable by `www`.)
- [ ] No *"proxy class not found"* errors on pages with related data — confirms
      `orm:generate-proxies` ran (a symptom of skipping §1.5's proxy step).
- [ ] Dashboards render with seeded data (no empty states from a missing seed).
- [ ] (When ready) flip `RBAC_ENFORCE=true` in the API `.env` **after** verifying
      the grant matrix in staging, then restart PHP.

---

## 4. Updating a live deployment

```bash
# API (on the aaPanel server)
cd /www/wwwroot/api.YOUR_DOMAIN
git pull
cd apps/api
composer install --no-dev --optimize-autoloader
composer migrate                          # applies new migrations; no-op if none
php bin/console.php orm:generate-proxies   # refresh proxies after entity changes
# (storage/ and var/ are left untouched — never delete storage/uploads)

# Frontend
# Push to the connected branch → Cloudflare Pages rebuilds automatically,
# or re-run `npm run build:pages` + `wrangler pages deploy` for direct upload.
```

Restart the API's PHP process from aaPanel (Website → Settings → **Reload/Restart**)
after `.env` changes.
