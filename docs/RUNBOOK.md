# LearnoCentric — Deployment & Operations Runbook

How to deploy and operate LearnoCentric in production.

- **Backend API** (`apps/api`) — PHP 8.2 / Slim 4 / Doctrine ORM 3 / PostgreSQL — runs on the **aaPanel (Ubuntu)** server.
- **Frontend** (`apps/web`) — Angular 20 — runs on **Cloudflare Pages** as a static single-page app (SPA).
- **Database** — PostgreSQL on (or reachable from) the aaPanel server.
- Uploaded files are stored on the server's local disk (Flysystem) and served over HTTP by the API host.

---

## 0. Topology & DNS (do this first)

Recommended two-origin setup:

| Host | Serves | Where |
|---|---|---|
| `learnocentric.com` (and/or `app.learnocentric.com`) | Frontend SPA | Cloudflare Pages |
| `api.learnocentric.com` | Backend API (`/backend/*`, `/uploads/*`, `/health`) | aaPanel site, doc-root `apps/api/public` |

DNS:
- Point the **frontend** hostname at Cloudflare Pages (Pages sets this up when you add the custom domain).
- Point `api.learnocentric.com` (A record) at the aaPanel server's public IP. If it's proxied through Cloudflare (orange cloud), that's fine.

> The frontend calls the API **cross-origin**, so CORS on the API must allow the frontend origin (covered below). If you prefer a single origin with no CORS, see **Appendix B**.

---

## Part A — Backend API on aaPanel (Ubuntu)

### A1. PostgreSQL database

The server already runs similar apps, so PostgreSQL is likely installed. Create a dedicated database + user:

```sql
CREATE USER learno WITH PASSWORD '<STRONG_DB_PASSWORD>';
CREATE DATABASE learnocentric OWNER learno;
GRANT ALL PRIVILEGES ON DATABASE learnocentric TO learno;
```

Note the host/port (aaPanel's PostgreSQL default is `127.0.0.1:5432`; the dev compose used `5433` — use whatever this server exposes).

### A2. Get the code

```bash
cd /www/wwwroot                    # aaPanel's web root
git clone <REPO_URL> learnocentric
cd learnocentric
git checkout main                  # or the release branch/tag you are deploying
```

### A3. PHP 8.2+ and extensions

In aaPanel → **App Store → PHP 8.2** (or 8.3). Then enable these extensions (aaPanel → PHP → Install extensions):

`pdo`, `pdo_pgsql`, `curl`, `mbstring`, `openssl`, `intl`, `fileinfo`, `ctype`, `tokenizer`, `dom`, `xml`, `json`.

`pdo_pgsql` (PostgreSQL) and `curl` (outbound calls to Paystack / ZeptoMail) are the two that are easy to miss. (Agora needs no outbound call — tokens are generated locally.)

### A4. Install PHP dependencies (production)

```bash
cd /www/wwwroot/learnocentric/apps/api
composer install --no-dev --optimize-autoloader --classmap-authoritative
```

> **`ext-redis` platform conflict?** If install fails with *"symfony/cache … conflicts with ext-redis <6.1"* (aaPanel/Ubuntu ships an older `php-redis`, e.g. 5.3.7), append `--ignore-platform-req=ext-redis`. The app doesn't use Redis — `symfony/cache` only pulls it in for an adapter we never instantiate — so skipping that one platform check is safe and installs straight from the lock file (no `composer update`). See **Troubleshooting → Composer ext-redis conflict**.

### A5. Configure `.env`

```bash
cp .env.example .env
```

Edit `apps/api/.env`:

```ini
APP_ENV=prod
APP_DEBUG=false
APP_URL=https://api.learnocentric.com

# Comma-separated list of allowed frontend origins (no trailing slash)
CORS_ALLOWED_ORIGINS=https://learnocentric.com,https://<project>.pages.dev

DB_DRIVER=pdo_pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_NAME=learnocentric
DB_USER=learno
DB_PASSWORD=<STRONG_DB_PASSWORD>

JWT_SECRET=<64+ RANDOM CHARS>      # e.g. `openssl rand -hex 48`
JWT_TTL=86400
JWT_ISSUER=learnocentric

# Email (ZeptoMail) — leave token blank to run without email
ZEPTOMAIL_API_URL=https://api.zeptomail.com/v1.1/email
ZEPTOMAIL_TOKEN=<zeptomail token>
MAIL_FROM_ADDRESS=noreply@learnocentric.com
MAIL_FROM_NAME=LearnoCentric

# Live classes (Agora) — tokens are generated locally; no API URL/key.
AGORA_APP_ID=<agora app id>
AGORA_APP_CERTIFICATE=<agora app certificate>

# File storage (local disk)
STORAGE_DRIVER=local
STORAGE_LOCAL_ROOT=public/uploads
STORAGE_PUBLIC_URL=https://api.learnocentric.com/uploads

# Billing (Paystack)
PAYSTACK_API_URL=https://api.paystack.co
PAYSTACK_SECRET_KEY=<paystack secret>
PAYSTACK_PUBLIC_KEY=<paystack public>
PAYSTACK_CALLBACK_URL=https://learnocentric.com/billing/callback
```

`.env` is git-ignored — it lives only on the server. **Never commit secrets.**

### A6. Uploads directory (Flysystem)

```bash
cd /www/wwwroot/learnocentric/apps/api
mkdir -p public/uploads
chown -R www:www public/uploads     # aaPanel's PHP-FPM user is usually `www`
chmod -R 775 public/uploads
```

`STORAGE_PUBLIC_URL` must resolve to this folder over HTTP (files live under `public/uploads`, so the API host serves them as static files).

**Runtime writable dirs.** The app writes to `var/log`, `var/doctrine/proxies` and `var/cache` (Doctrine proxies, app log, cache). It tries to create them on boot, but that fails silently if `var/` isn't owned by the PHP-FPM user — so create them and hand `var/` to `www`:

```bash
cd /www/wwwroot/learnocentric/apps/api
mkdir -p var/log var/doctrine/proxies var/cache
chown -R www:www var
chmod -R 775 var
```

> If you run `migrations:migrate` / `app:seed` / `composer` as **root**, `var/` files get created as `root:root` and PHP-FPM (running as `www`) then fails with *"proxy directory … must be writable"*. Run console commands as the web user — `sudo -u www php bin/console.php …` — or re-`chown -R www:www var` afterwards.

### A7. Create the aaPanel site (Nginx + PHP-FPM)

1. aaPanel → **Website → Add site**
   - Domain: `api.learnocentric.com`
   - PHP version: 8.2+
   - After creating, point it at the checkout using aaPanel's **two** directory settings (Site → Config):
     - **Website directory (site root)** → `/www/wwwroot/learnocentric/apps/api`
     - **Running directory** → `/public`
   - This matters: aaPanel scopes PHP's `open_basedir` to the **Website directory**, and `public/index.php` bootstraps files *outside* `public/` (`../config/bootstrap.php`, `vendor/`, `src/`, `.env`). Pointing the doc-root straight at `.../public` jails `open_basedir` to `public/` and the app dies with *"open_basedir restriction in effect … bootstrap.php is not within the allowed path(s)"*. Setting the site root to `apps/api` and the running directory to `/public` gives Nginx the right doc-root **and** an `open_basedir` that covers the whole app.
2. Add the SPA-style front-controller rewrite (Slim routes everything through `public/index.php`). In the site's Nginx config (Site → Config → Configuration file), inside `server { ... }`:

```nginx
location / {
    try_files $uri $uri/ /index.php$is_args$args;
}
```

(Keep aaPanel's existing `location ~ \.php$ { fastcgi_pass ... }` block — that runs PHP-FPM. Uploaded files under `/uploads` are served directly by `try_files` as static files.)

3. Reload Nginx (aaPanel does this on save).

### A8. HTTPS

aaPanel → Site → **SSL → Let's Encrypt** → issue a cert for `api.learnocentric.com` and turn on **Force HTTPS**. (If the domain is proxied by Cloudflare, use Cloudflare's origin certificate or set SSL mode to Full.)

### A9. Database schema + first-run data

```bash
cd /www/wwwroot/learnocentric/apps/api
php bin/console.php migrations:migrate --no-interaction
```

The only way to bootstrap roles, permissions and the super-admin is the seeder, which **also creates demo data**. For a real launch:

```bash
php bin/console.php app:seed          # creates roles, permissions, a super admin,
                                      # the subject catalogue, plans — plus demo tenants
```

Then **remove the demo tenants** (their users/classes/enrolments cascade-delete), keeping the super admin, subject catalogue and plans:

```sql
DELETE FROM institutions WHERE name IN ('GOF College', 'Bright Minds Academy');
-- optional: drop the demo content library/package if you don't want the examples
-- DELETE FROM content_packages; DELETE FROM content_resources;
```

> If you re-run `app:seed`, most seeders are idempotent (they skip when their table already has rows), so it will not duplicate. It will **not** re-create tenants you deleted.

See **Part C** for securing the super-admin account.

### A10. Verify the API

```bash
curl -s https://api.learnocentric.com/health            # -> {"status":"ok",...}
curl -s -X POST https://api.learnocentric.com/backend/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"<super admin email>","password":"<password>"}'
```

If outbound calls (Paystack / ZeptoMail) fail with **cURL error 60 (SSL certificate)**, see **Troubleshooting → CA bundle**.

---

## Part B — Frontend on Cloudflare Pages

The app is served as a **static SPA**. (It has server-side rendering for local dev, but Cloudflare Pages hosts the client build; the app hydrates and runs entirely against the API.)

### B1. Point the app at the production API

The browser API base is set in code. Edit **`apps/web/src/app/app.config.ts`**:

```ts
{ provide: API_BASE_URL, useValue: isDevMode() ? '' : 'https://api.learnocentric.com' },
```

The committed default is `https://learnocentric.com` — change it to your **API** host (`https://api.learnocentric.com`). Do the same in `app.config.server.ts` if you ever enable SSR. Commit this change.

### B2. Create the Cloudflare Pages project

Cloudflare dashboard → **Workers & Pages → Create → Pages → Connect to Git** → pick the repo. Build settings:

| Setting | Value |
|---|---|
| Production branch | `main` |
| Framework preset | None (Angular) |
| Build command | `cd apps/web && npm ci && npm run build:pages` |
| Build output directory | `apps/web/dist/learno-client/browser` |
| Root directory | `/` (repo root) |
| Node version | set env var `NODE_VERSION=20` |

`npm run build:pages` builds the client bundle **and** copies the SSR client shell
`index.csr.html` to `index.html` (via `scripts/pages-index.cjs`), because
Cloudflare Pages serves `index.html`. The output folder `dist/learno-client/browser`
is what Pages serves. `apps/web/public/_redirects` (already in the repo) is copied
into it and gives the SPA `/* → /index.html 200` fallback so deep links work.

> The app is configured for SSR in local dev; for Pages we ship the client build
> as a static SPA. Do **not** use plain `npm run build` as the Pages command — it
> emits `index.csr.html` (no `index.html`) and Pages would 404 on the root.

### B3. Custom domain

Pages project → **Custom domains → Set up a domain** → `learnocentric.com` (and/or `app.learnocentric.com`). Cloudflare provisions HTTPS automatically.

### B4. Wire CORS + callbacks to the real frontend origin

Back on the API `.env`, make sure `CORS_ALLOWED_ORIGINS` includes the exact frontend origin(s) you just set (production domain **and** the `*.pages.dev` preview URL if you use previews), then reload PHP:

```
CORS_ALLOWED_ORIGINS=https://learnocentric.com,https://app.learnocentric.com,https://<project>.pages.dev
```

`PAYSTACK_CALLBACK_URL` should point at the frontend billing callback (`https://learnocentric.com/billing/callback`).

### B5. Verify

- Open the site, sign in as the super admin.
- Confirm no CORS errors in the browser console and that a deep link (e.g. `/super-admin/management/plans`) loads on refresh.

---

## Part C — Post-deploy security checklist

- [ ] **Change the super-admin credentials.** The seeder creates a super admin with a well-known default password (`Password@1`). Log in immediately and change the email + password from the profile page, or create a fresh super admin and disable the seeded one.
- [ ] `JWT_SECRET` is a long random value unique to production.
- [ ] `APP_DEBUG=false`, `APP_ENV=prod`.
- [ ] Demo tenants removed (Part A9).
- [ ] Real Agora (App ID + Certificate) / Paystack / ZeptoMail keys in `.env` (or intentionally blank).
- [ ] `.env` file permissions locked down (`chmod 640 .env`, owner `www`).
- [ ] HTTPS forced on the API; Cloudflare HTTPS on the frontend.
- [ ] Uploads directory is writable by `www` but not publicly listable.

---

## Part D — Deploying an update

Backend:
```bash
cd /www/wwwroot/learnocentric && git pull
cd apps/api
composer install --no-dev --optimize-autoloader
php bin/console.php migrations:migrate --no-interaction   # only if there are new migrations
# reload PHP-FPM (aaPanel → PHP → Reload) to clear any opcache
```

Frontend: push to `main`. Cloudflare Pages builds and deploys automatically. (Or trigger a redeploy from the Pages dashboard.)

---

## Part E — Rollback

- **Frontend:** Cloudflare Pages → Deployments → pick the previous successful deployment → **Rollback**. Instant.
- **Backend code:** `git checkout <previous-tag>` then re-run `composer install`.
- **Backend schema:** if a migration must be undone, `php bin/console.php migrations:migrate <previous-version> --no-interaction`. **Take a DB backup before every migration** (Part F) — some migrations are destructive and are not safely reversible without the backup.

---

## Part F — Backups

- **Database:** schedule `pg_dump` (aaPanel → Cron):
  ```bash
  PGPASSWORD='<db pw>' pg_dump -h 127.0.0.1 -U learno learnocentric | gzip > /www/backup/learno_$(date +\%F).sql.gz
  ```
  Keep ≥ 14 daily copies off-box. **Always take one immediately before running migrations.**
- **Uploads:** back up `apps/api/public/uploads` (rsync/tar to off-box storage). These files are not in git.
- **`.env`:** store a copy of the production `.env` in your secrets manager (it is not in git).

---

## Troubleshooting

**CORS errors in the browser.** The frontend origin isn't in `CORS_ALLOWED_ORIGINS`. Add the exact origin (scheme + host, no trailing slash), including the `*.pages.dev` preview origin, and reload PHP. Preflight `OPTIONS` is handled by the API's CORS middleware.

**Frontend route 404s on refresh (but works when navigating in-app).** The SPA fallback isn't taking effect. Confirm `apps/web/public/_redirects` (with `/* /index.html 200`) shipped in the build output, and that the Pages build command was `npm run build:pages` (not `npm run build`) so an `index.html` exists.

**cURL error 60 / SSL certificate on outbound calls (Paystack/ZeptoMail).** PHP's cURL can't find the CA bundle. On Ubuntu, point it at the system bundle in the active `php.ini`:
```ini
curl.cainfo = "/etc/ssl/certs/ca-certificates.crt"
openssl.cafile = "/etc/ssl/certs/ca-certificates.crt"
```
Reload PHP-FPM. (This is the same class of issue documented for the dev environment.)

**Uploads return 404 or "permission denied" on save.** Check `public/uploads` exists, is owned by `www:www`, is `775`, and that `STORAGE_PUBLIC_URL` matches the API host. The Nginx `try_files` rule serves them as static files.

**`open_basedir restriction in effect … bootstrap.php is not within the allowed path(s)`.** aaPanel jailed PHP to the doc-root (`.../public/`), but the app bootstraps files above it (`config/`, `vendor/`, `src/`, `.env`). Fix: Site → Config → set **Website directory** to `/www/wwwroot/learnocentric/apps/api` and **Running directory** to `/public` (see A7), then reload PHP. CLI fallback: unlock the aaPanel `.user.ini` with `chattr -i .../apps/api/public/.user.ini`, change its line to `open_basedir=/www/wwwroot/learnocentric/apps/api/:/tmp/`, `chattr +i` it back, reload PHP-FPM.

**`Your proxy directory … var/doctrine/proxies must be writable`.** PHP-FPM (user `www`) can't write the runtime dirs — usually because they were created as `root` by a CLI command. Fix: `chown -R www:www var && chmod -R 775 var` under `apps/api` (see A6), and run future console commands as `sudo -u www …`.

**Composer `ext-redis` conflict on install.** `composer install` reports *"Your lock file does not contain a compatible set of packages"* and *"symfony/cache … conflicts with ext-redis <6.1"*. The server's bundled `php-redis` is older than symfony/cache's Redis adapter wants. The app never uses Redis, so ignore that one platform requirement:
```bash
composer install --no-dev --optimize-autoloader --classmap-authoritative --ignore-platform-req=ext-redis
```
This installs from the lock file unchanged — do **not** run `composer update` (it would re-resolve untested versions). Permanent alternative: `pecl install redis` to get `ext-redis ≥ 6.1`, then the flag isn't needed.

**500 on every request / blank page.** Check `APP_DEBUG` temporarily `true` and read the PHP-FPM error log (aaPanel → Site → Logs). Usual causes: missing `pdo_pgsql`, wrong DB credentials, or `.env` not readable by `www`.

**"402 module not available" style errors for a school.** Feature-module access is gated by the school's subscription plan — expected behaviour, not a bug. See the plan-scoping design.

**Live class won't connect.** A class must be **Live** (host clicked "Go live", which assigns its Agora channel). Both `AGORA_APP_ID` and `AGORA_APP_CERTIFICATE` must be set — the join endpoint returns **503** if either is blank. No outbound HTTPS is needed (tokens are generated locally); the browser needs camera/mic permission and reachability to Agora's edge (`*.agora.io` / `*.sd-rtn.com`). An `CAN_NOT_GET_GATEWAY_SERVER: static use dynamic key` error in the browser means the App Certificate is enabled but the token is missing or from a different certificate.

---

## Appendix A — Environment variable reference (API)

| Var | Purpose |
|---|---|
| `APP_ENV` / `APP_DEBUG` | `prod` / `false` in production |
| `APP_URL` | Public API base URL |
| `CORS_ALLOWED_ORIGINS` | Comma-separated allowed frontend origins |
| `DB_DRIVER`/`DB_HOST`/`DB_PORT`/`DB_NAME`/`DB_USER`/`DB_PASSWORD` | PostgreSQL connection |
| `JWT_SECRET`/`JWT_TTL`/`JWT_ISSUER` | Auth token signing |
| `ZEPTOMAIL_*` / `MAIL_FROM_*` | Transactional email (optional; blank = disabled) |
| `AGORA_APP_ID` / `AGORA_APP_CERTIFICATE` | Live classes (Agora; local token minting, no outbound call) |
| `STORAGE_DRIVER` / `STORAGE_LOCAL_ROOT` / `STORAGE_PUBLIC_URL` | File uploads |
| `PAYSTACK_*` | Billing |

## Appendix B — Single-origin option (no CORS)

Instead of a separate `api.` subdomain, route `learnocentric.com/backend/*` and `/uploads/*` to the aaPanel origin using a **Cloudflare Rule / Worker** (origin override), and set the frontend API base to `''` (relative). Requests are then same-origin and CORS is unnecessary. This trades a bit of Cloudflare configuration for simpler browser networking.

## Appendix C — Key commands

```bash
# Backend
composer install --no-dev --optimize-autoloader
php bin/console.php migrations:migrate --no-interaction
php bin/console.php migrations:diff            # generate a migration from entity changes
php bin/console.php app:seed                   # roles/permissions/super-admin/catalogue (+demo)

# Frontend (production static build — emits index.html + browser/)
cd apps/web && npm ci && npm run build:pages   # output: dist/learno-client/browser
```
