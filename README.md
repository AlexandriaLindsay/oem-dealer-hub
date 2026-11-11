# DealerDock

A self-serve dealer platform and dealer network for equipment manufacturers. Manufacturers pay (Starter $49/mo up to 10 dealers, Pro $99/mo unlimited); dealers are always free. ERP integration is quoted separately.

The product and architecture plan is in [docs/PLAN.md](docs/PLAN.md).

## Layout

| Folder | What |
|---|---|
| `backend/` | Laravel 12 API (PHP 8.3) - tenancy, auth, plans, features |
| `frontend/` | React 19 + TypeScript + Vite single-page app |
| `docs/` | Plan and design notes |

## Run it locally

Needs PHP 8.3, Composer, Node 20.

```bash
# API - port 8010 (8000 is often taken by other projects)
cd backend
composer install
cp .env.example .env        # first time only
php artisan key:generate    # first time only
php artisan migrate:fresh --seed
php artisan serve --port=8010

# App - in a second terminal
cd frontend
npm install
npm run dev                 # http://localhost:5173
```

Local development uses SQLite (`backend/database/database.sqlite`); production uses MySQL 8.

**Demo accounts** (password `dealerdock-demo` for all; the first sign-in sets up two-factor - scan the QR code with any authenticator app):

| Email | Who |
|---|---|
| `owner@acme.test` | Owner of Acme Scrapers (Pro) |
| `rep@acme.test` | Sales rep at Acme - sees dealers but can't add them |
| `owner@bolt.test` | Owner of Bolt Attachments (Starter - 10 dealer limit) |
| `dealer@prairie.test` | Prairie Equipment - a dealer for **both** Acme and Bolt, with a brand switcher |

Password reset and invite emails go to `backend/storage/logs/laravel.log` locally (`MAIL_MAILER=log`).

## Tests

```bash
cd backend && php artisan test     # in-memory SQLite, no setup needed
cd frontend && npm run build       # type-checks and builds
```

## How tenancy works

- **Every tenant-owned model uses `App\Tenancy\BelongsToManufacturer`.** Its queries are limited to the current manufacturer, new rows get its id, and with no manufacturer set it **throws** rather than returning everything.
- **The current manufacturer comes from the URL** (`/api/m/{slug}/...`) via the `tenant` middleware, which also checks the signed-in user works there - as staff, or as a dealer with an active link. Anything else is a 404.
- **Background work sets it explicitly:** `Tenant::run($manufacturer, fn () => ...)`. Never rely on who's signed in.
- **Dealers are global**; `dealer_links` is each manufacturer's own relationship with a dealer. One login works across every brand a dealer carries (`App\Tenancy\Access`).
- **Every new tenant-owned module adds cases to `tests/Feature/TenantIsolationTest.php`.**

## Plans and feature access

- Prices and limits live only in `App\Support\Plan`.
- Who can use what lives in `App\Support\Features`: role defaults, a per-user override, and the plan on top (an override can't unlock a Pro feature on Starter).
- Routes check with the `feature:<key>` middleware; `/api/me` gives the frontend each person's resolved features per manufacturer.

## Status

Phase 0 (foundation) - see [docs/PLAN.md](docs/PLAN.md) section 7.

- [x] Laravel + React scaffold, Vite dev proxy, SQLite for local work
- [x] Tenancy: manufacturers, global dealers, dealer links, memberships, fail-closed scoping, isolation tests
- [x] Sign-up (14-day Pro trial), sign-in, password reset and invites, authenticator-app 2FA (required, codes can't be replayed)
- [x] Roles, per-user feature overrides, plan limits (dealers, staff) and Pro-only features
- [x] Dashboard with plan usage, product lines, dealers, staff - and the brand switcher
- [x] CI: tests and build on every push
- [ ] Email and text 2FA codes, trusted browsers
- [ ] Filament console for DealerDock staff
- [ ] Azure: deployment slots, OIDC deploys, MySQL, Blob storage
