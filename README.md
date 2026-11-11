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

Phase 1 (MVP), done:

- [x] Products: models within product lines, and options per model
- [x] Machines by serial number, with statuses (inventory, demo, rental, sold, used), a dealer (or still at the factory) and a history of every change
- [x] Staff add machines and move them between dealers; dealers see only their own machines and keep their status current
- [x] Fleet report: machines by status, dealer and model across all dealers, with the same filters as the machine list, and an Excel export (OpenSpout, streamed in chunks; text is always written as text, so nothing in a cell can run as a formula)
- [x] Product registration: dealers register a sale or rental with the customer and documents; the manufacturer approves (machine becomes sold or rental, customer becomes an end user) or rejects with a reason; both sides get an email
- [x] Manuals library: manuals, parts books and bulletins linked to models and serial ranges, found by serial number, staff-only documents hidden from dealers
- [ ] Azure Blob storage driver for documents (see "Uploads" below)
- [x] Machine import from CSV or Excel: a preview that writes nothing, then the import (rows with problems are left out), recorded with every row's outcome and an Excel audit report. Re-importing is safe: blank cells never clear anything, and a machine's model is never changed.
- [x] Onboarding: a "Getting started" checklist that ticks itself from real data, the manufacturer's logo (PNG/JPG, scanned, shown to staff and dealers), and a guided tour for manufacturers and for dealers. Tours are versioned (`App\Support\Tours`), so a big feature can get a "What's new" tour later.

Phase 2 (billing and launch), in progress:

- [x] Billing with Stripe (Laravel Cashier): the manufacturer is the Stripe customer; Checkout to start, plan changes in the app (limits enforced), Stripe's portal for card, invoices and cancelling
- [x] Trial, past-due and paused accounts, and suspending/reactivating dealers (only active dealers count toward a plan)
- [ ] Landing page and pricing page
- [ ] Privacy policy and terms, accepted at sign-up
- [ ] Cloudflare Turnstile on sign-in and sign-up

## Billing

**Where an account stands** (`App\Support\Plan::standing`):

| Standing | When | What happens |
|---|---|---|
| Trialing | First 14 days, no card | Pro limits. The owner sees a banner in the last 3 days. |
| Active | A live subscription, or a plan set without one (billed by invoice, or free) | Everything works. |
| Past due | A payment failed and Stripe is retrying | **Everything still works**; the owner sees "update your card". |
| Lapsed | Trial over with no plan, or the subscription ended | **Paused** for staff and dealers (402 `account_lapsed`, with a plain page). Billing stays open. Nothing is deleted. |

`manufacturers.plan` follows the subscription through Stripe's webhooks (`App\Billing\SyncPlan`). Moving to Starter is refused while over its limits, and the owner is told exactly why (`Plan::blockers`).

**Setting up Stripe (test mode first):**

1. In the Stripe dashboard, create two products, Starter and Pro, each with a monthly and an annual price matching `App\Support\Plan` ($49 / $490, $99 / $990, USD).
2. Paste the keys and the four price ids into `backend/.env` (see `backend/.env.example`). Never paste them into chat or commit them.
3. **Billing portal settings:** turn on payment-method updates, invoice history and cancelling (at period end). Turn **off** switching plans there: plan changes go through the app, which enforces the limits.
4. **Webhooks:** locally, `stripe listen --forward-to localhost:8010/stripe/webhook` and put the `whsec_...` it prints into `STRIPE_WEBHOOK_SECRET`. In production, add an endpoint for `https://<host>/stripe/webhook` with Cashier's events (`php artisan cashier:webhook` creates it), and set its signing secret. The app refuses to start in production without one.
5. Try it with Stripe's test card `4242 4242 4242 4242`.

## Uploads

- **Files never live in the web root.** They go on the `documents` disk (`DEALERDOCK_DOCUMENTS_DISK`): a private folder locally (`storage/app/private/documents`, gitignored), object storage in production. They're only sent through the API after its access checks, always as a download, never shown inline in the browser.
- **Every upload is scanned before it's stored** (`App\Support\Uploads`). `DEALERDOCK_SCANNER=clamav` sends each file to a ClamAV daemon (`CLAMAV_HOST`, `CLAMAV_PORT`). If ClamAV can't be reached, the upload is refused rather than stored unchecked. `none` is for local development only: files are marked "not scanned", and the app refuses to boot with it in production.
- PDF, JPG and PNG only: up to 20 MB for registration documents, 40 MB for library documents (`DEALERDOCK_LIBRARY_MAX_KB`). Raising the library limit also means raising PHP's `upload_max_filesize` and `post_max_size`, and clamd's `StreamMaxLength` (25 MB by default), or big files can't be scanned and are refused. Files are stored under generated names, and uploads count toward the plan's file storage.
- Library PDFs and images can open in the browser (`?inline=1`), sent with `Content-Security-Policy: sandbox` so a booby-trapped file can't run as the signed-in user.
- **Serial lookup reaches past the dealer scope on purpose:** a dealer can look up any serial at the manufacturer, because service shops work on machines they didn't sell. The answer is only the model and its documents, never the dealer, status or customer, and it's rate-limited so it can't be used to walk the serial numbers.
- `migrate:fresh` doesn't empty the documents folder. Delete `backend/storage/app/private/documents` if you want a clean start.

**A dealer only ever sees their own machines.** `Machine` and `Registration` have a second global scope on top of the manufacturer one (`App\Tenancy\VisibleToOwnDealer`): when the request is acting as a dealer, they only return that dealer's rows. A controller that forgets a filter still can't show one dealer another dealer's stock. A dealer also sees other dealers in a machine's history only as "another dealer".
