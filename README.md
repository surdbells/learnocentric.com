# LearnoCentric LMS — Monorepo

A curriculum-aligned academic-improvement & competency-evidence platform for Nigerian
schools and tutoring academies (four portals: Learner, Teacher, School/Institution Admin,
Super Admin).

```
apps/
  web/   Angular 20 frontend (Bootstrap 5, green design system)
  api/   PHP 8.3 backend — Slim 4 + Doctrine ORM + PostgreSQL
```

## Stack

| Concern        | Choice |
|----------------|--------|
| Frontend       | Angular 20 (SSR), Bootstrap 5 |
| API framework  | Slim 4 + PHP-DI |
| ORM / DB       | Doctrine ORM 3 + Doctrine Migrations + PostgreSQL 16 |
| Auth           | JWT (HS256), table-driven RBAC, multi-tenant by institution |
| File storage   | Flysystem (local adapter by default) |
| Billing        | Paystack |
| Email          | ZeptoMail |
| Live video     | Daily.co |

## Prerequisites

- Node 20+, PHP 8.3+ with `pdo_pgsql`, Composer 2, Docker Desktop.

## First-time setup

```bash
# 1. Database (PostgreSQL on host port 5433 — 5432 may be in use)
docker compose up -d

# 2. Backend deps + schema + seed data
composer --working-dir=apps/api install
php apps/api/bin/console.php migrations:migrate --no-interaction
php apps/api/bin/console.php app:seed          # roles, permissions, institutions, users, audit logs

# 3. Frontend deps
npm run web:install
```

## Running (two terminals)

```bash
npm run api:serve     # PHP API at http://127.0.0.1:8090
npm start             # Angular app at http://localhost:4200 (proxies /backend -> :8090)
```

## Ports (non-default to avoid local conflicts)

- **Postgres:** host `5433` → container `5432`
- **API:** `127.0.0.1:8090`
- **Web:** `localhost:4200` (dev proxy forwards `/backend` to the API)

## Baseline accounts (password: `Password@1`)

| Email | Role | Institution |
|-------|------|-------------|
| `surdbells@gmail.com` | super_admin | — |
| `school@gmail.com` | school_admin | GOF College (school) |
| `teacher@gmail.com` | teacher | GOF College |
| `student@gmail.com` | student | GOF College |
| `academy@gmail.com` | tutor_admin | Bright Minds Academy (academy) |

## Backend console

```bash
php apps/api/bin/console.php migrations:diff      # generate a migration from entity changes
php apps/api/bin/console.php migrations:migrate   # apply migrations
php apps/api/bin/console.php app:seed             # (re)seed — idempotent
php apps/api/bin/console.php list                 # all commands
```

## Configuration

Copy `apps/api/.env.example` → `apps/api/.env`. Set `ZEPTOMAIL_TOKEN`, `DAILY_API_KEY`,
`PAYSTACK_SECRET_KEY`/`PAYSTACK_PUBLIC_KEY` to enable those integrations (they no-op safely
when blank). The product spec lives in `Learno_LMS_Product_Feature_and_Flow_Specification`.
