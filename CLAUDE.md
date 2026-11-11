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

Phase 1 (MVP) is done. Where things live:

- **Machines:** `ProductModel` (table `product_models`, not `models`, to avoid clashing with Eloquent's `Model`), `Machine` with `machine_events` history. Filters in one place: `Machine::filter()` and `Machine::filterRules()`.
- **Dealer-owned data** (`Machine`, `Registration`) uses `App\Tenancy\VisibleToOwnDealer`, so a dealer only sees their own rows.
- **Fleet report and export:** `App\Reports\FleetReport`. Status colours are the `--status-*` tokens in `index.css`, checked with the dataviz palette validator.
- **Registrations:** `Registration` (approve/reject); `EndUser` is created on approval.
- **Uploads:** everything goes through `App\Support\Uploads` (storage limit, ClamAV scan via `App\Support\Scanning`, private disk). Spreadsheet exports always write text as text cells, never formulas.
- **Manuals:** `Document` (an audience scope hides staff-only documents from dealers) and `DocumentModel` (model plus an optional serial range, compared naturally). Serial lookup deliberately skips the dealer scope and returns only the model and documents.
- **Import:** `App\Imports\SpreadsheetFile` reads CSV (UTF-8 or Windows-1252; comma, semicolon or tab) or XLSX. `App\Imports\MachineImporter::analyse()` is the preview and writes nothing; `apply()` re-analyses and writes in one transaction. Each import is a `MachineImport`.
- **Onboarding:** `App\Support\Onboarding` (checklist steps computed from data), the logo on `Manufacturer`, tours in `frontend/src/lib/tours.ts` with keys in `App\Support\Tours`. The tour works out its steps on every render, because targets like the dashboard checklist load late.

Phase 2 (billing and launch) has started:

- **Billing:** Cashier with `Manufacturer` as the Billable model (not `User`); the subscription tables use `manufacturer_id`. Stripe calls go through `App\Billing\BillingGateway` (`StripeGateway`; tests bind `Tests\Support\FakeBillingGateway`). `App\Billing\SyncPlan` keeps `manufacturers.plan` in step with Stripe from webhooks. `Plan::standing()` is trialing, active, past_due or lapsed; the `standing` middleware pauses a lapsed account (402) everywhere except Billing and the logo. Past-due accounts stay open (`Cashier::keepPastDueSubscriptionsActive`). Stripe price ids are in `config/services.php`; amounts stay in `Plan`.
- **Plan limits count active dealers**; dealers can be suspended and reactivated (`POST dealers/{link}/status`).
- **Public site:** landing, pricing, privacy and terms (`frontend/src/pages/PublicPages.tsx`, `LegalPages.tsx`), with facts from `GET /api/site`. A Pro feature not built yet has `'coming' => true` in `Features::LIST` and shows "coming soon"; remove it when the feature ships.
- **Terms:** `users.terms_version` must equal `config('dealerdock.legal.version')`, enforced by the `terms` middleware on tenant routes. The legal texts are **unreviewed drafts**. Any new cookie or tracking goes into the privacy policy's table before it ships.
- **Turnstile:** `App\Support\Turnstile::check()` in register, login and forgot-password. A bad token is refused; if Cloudflare is unreachable the request goes through.
- **Phase 2 is done in code.** Before launch: company details, retention, refunds and liability in the legal texts, plus a lawyer's review.

Phase 3 (commercial) has started. The owner chose it over Azure (no Azure work for now; see memory).

- **Price lists:** `PriceList` / `PriceListItem` (`item_type` model|option, `price_cents`). `App\Support\Money` turns typed prices into cents and back; the API returns prices as "1234.50" strings, null = price on request. `PriceList::forDealer()` gives a dealer's own list or the default. `CatalogController` never lets a dealer see another list, or any list's name. `DealerLink.price_list_id` is set only through `PriceListController::assign` (it isn't fillable).
- **Next in Phase 3:** quotes (snapshot prices from the dealer's list), then parts orders.

The other option is the Phase 0 leftovers above. The Azure Blob driver for documents and a ClamAV server belong with the Azure deploy.

UI changes get checked in a real browser: headless Edge with `puppeteer-core` against a throwaway API (port 8011) and Vite (5174) on a scratch SQLite database, never the owner's dev servers or data.
