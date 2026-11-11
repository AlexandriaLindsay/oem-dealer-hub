# DealerDock - notes for Claude

DealerDock is a self-serve dealer platform and dealer network for equipment manufacturers. Pricing: Starter $49/mo (up to 10 dealers, 3 staff, 10 GB), Pro $99/mo (unlimited dealers), dealers always free, ERP integration quoted after a meeting. The product and architecture plan is in [docs/PLAN.md](docs/PLAN.md); setup and how tenancy works are in [README.md](README.md).

## Background

- It's built from what the owner learned building a dealer portal for an employer (KA Group). **Reuse lessons and patterns, never that portal's code, data, ERP (Genius) specifics or credentials.**
- This is the owner's personal project. It lives outside OneDrive on purpose (`C:\dev\dealerdock`). Git uses the personal identity (repo-local `user.email`), and the repo is `AlexandriaLindsay/dealerdock` on the personal GitHub account. **Never** push it to, or create anything under, the KAGroup organization.

## Working rules

- **The owner does all commits and pushes.** Don't commit or push. Hand over ready-to-paste commands.
- The terminal is often **PowerShell** (`cd C:\dev\dealerdock`), sometimes Git Bash (`cd /c/dev/dealerdock`). Check which one before giving `cd` commands.
- Two GitHub accounts are logged in to `gh`: AlexandriaLindsay (personal) and AlexKAGroup (work). The remote URL includes `AlexandriaLindsay@`, so pushes from this repo use the personal login.
- Times in Eastern, 12-hour (e.g. "2:18 PM").
- Don't take secrets through chat. Tell the owner where to paste them.

## Development

- API: `cd backend && php artisan serve --port=8010`. **Port 8010**, because 8000 is the KA portal's dev server.
- App: `cd frontend && npm run dev`, at http://localhost:5173 (Vite proxies `/api` and `/sanctum` to 8010).
- Demo data: `php artisan migrate:fresh --seed`. The demo password is `dealerdock-demo`, and the accounts are in the README.
- Tests: `cd backend && php artisan test` (in-memory SQLite). Frontend: `npm run lint && npm run build`. Style: `vendor/bin/pint`.
- CI (`.github/workflows/ci.yml`) runs all of that on every push.

## Conventions

- Every tenant-owned model uses `App\Tenancy\BelongsToManufacturer`. Every new tenant-owned module adds cases to `tests/Feature/TenantIsolationTest.php`.
- Background work runs inside `Tenant::run($manufacturer, ...)`, never "whoever is signed in".
- Prices and limits live only in `App\Support\Plan`. Feature access lives in `App\Support\Features` and uses the `feature:` middleware.
- Tenant API routes go under `/api/m/{tenant}/...`, with the `two-factor` and `tenant` middleware.
- Migrations stay additive once deployed (slot deploys run the old code against the new schema briefly).
- Write comments and UI copy in plain language, explaining why.

## Status (2026-11-11)

Phase 0 is done and pushed, with the first CI run green. Still open from Phase 0: email/text two-factor codes and trusted browsers, a Filament console for DealerDock staff, Azure deploy (slots + OIDC).

Phase 1 has started. Its first slice: product models and options, machines with statuses and dealers, and `machine_events` history. The table is `product_models` (class `ProductModel`), not `models`, so it doesn't clash with Eloquent's `Model`. `Machine` has a dealer scope, so a dealer only sees their own machines. The fleet report and Excel export (`App\Reports\FleetReport`) are built too. Machine filters live in one place, `Machine::filter()` plus `Machine::filterRules()`. Status colours are the `--status-*` tokens in `index.css`, checked with the dataviz palette validator. Next in Phase 1: registrations, manuals, CSV import, onboarding tour.
