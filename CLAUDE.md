# OEM Dealer Hub - notes for Claude

OEM Dealer Hub is a self-serve dealer platform and dealer network for equipment manufacturers. Pricing: Starter $49/mo (up to 10 dealers, 3 staff, 10 GB), Pro $99/mo (unlimited dealers), dealers always free, ERP integration quoted after a meeting. The product and architecture plan is in [docs/PLAN.md](docs/PLAN.md); setup and how tenancy works are in [README.md](README.md).

**Name:** the product was renamed from DealerDock to **OEM Dealer Hub** (2026-11-11). Everything people see says OEM Dealer Hub, including UI, emails, legal texts, docs and `APP_NAME`. Internal names still say `dealerdock`: `config/dealerdock.php` (`config('dealerdock.…')`), `DEALERDOCK_*` env vars, the `dealerdock:` browser event, and the demo password `dealerdock-demo`. Leave those unless the owner asks, because renaming them breaks local `.env` files. The folder and repo were renamed to `oem-dealer-hub` too. New user-facing copy says "OEM Dealer Hub" ("an OEM Dealer Hub account").

## Background

- It's built from what the owner learned building a dealer portal for an employer (KA Group). **Reuse lessons and patterns, never that portal's code, data, ERP (Genius) specifics or credentials.**
- This is the owner's personal project. It lives outside OneDrive on purpose (`C:\dev\oem-dealer-hub`). Git uses the personal identity (repo-local `user.email`), and the repo is `AlexandriaLindsay/oem-dealer-hub` on the personal GitHub account. **Never** push it to, or create anything under, the KAGroup organization.

## Working rules

- **The owner does all commits and pushes.** Don't commit or push. Hand over ready-to-paste commands.
- Commit messages and PR descriptions get **no `Co-Authored-By` or "Generated with" lines**. The owner asked for this on 2026-11-11.
- The terminal is often **PowerShell** (`cd C:\dev\oem-dealer-hub`), sometimes Git Bash (`cd /c/dev/oem-dealer-hub`). Check which one before giving `cd` commands.
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

Phase 0 is done and pushed, with the first CI run green. Still open from Phase 0: email/text two-factor codes and trusted browsers, Azure deploy (slots + OIDC).

**Staff console (2026-11-12):** Filament 5 panel at `/admin` (`App\Providers\Filament\AdminPanelProvider`, resources in `app/Filament`).
- It has no login page. `AuthenticatePlatformStaff` (persistent, so Livewire requests re-check it) sends signed-out visitors to the app's `/login?next=console`, staff without two-factor to `/setup-2fa`, and everyone else to a 404. `User::canAccessPanel` requires `is_platform_staff` plus an authenticator app (`hasAuthenticatorApp`, not email codes). The only exception is `User::consoleSkipsTwoFactor()`: APP_ENV=local with `DEALERDOCK_REQUIRE_TWO_FACTOR=false`, never in production.
- `is_platform_staff` isn't fillable and only `php artisan platform:staff` sets it.
- Every change goes through `App\Platform\AccountTools` (refusals throw `RefusedException` with a plain sentence) and writes a `StaffAction` with a required reason in the same transaction. A Stripe-billed account's plan and trial can't be changed there (`billedByStripe`).
- `App\Platform\Standings::where()` is `Plan::standing()` as a query; change both together.
- Tenant-owned rows (`DealerLink`) are counted with `DB::table` or read with `withoutGlobalScopes(['manufacturer','dealer'])` in relation managers; the console never sets a Tenant.
- **Staff view in the app:** `Access::for` gives an active staff member (`User::isActiveStaff`) every other manufacturer too, as an unsaved owner `Membership` with `staff_view: true` (in `/api/me`).
  - `ResolveTenant` refuses every non-GET in a staff view (403, `staff_read_only`). Its first look at each manufacturer is logged as an `opened_in_app` StaffAction, at most once every 8 hours per staff member and manufacturer (cache key).
  - The app shows a banner, names them "OEM Dealer Hub staff" in the sidebar, and skips the automatic tour. Usage visits are refused, so staff never count as customer usage.
  - Where staff also work for a manufacturer, they work there normally.
- Relation managers and widgets load lazily, so tests use `Livewire::test` on them.
- Times show in Eastern (`FilamentTimezone`).
- **Staff write access to a customer's Integrations page (owner's decision, 2026-11-12):** the in-app staff view stays read-only everywhere else. On `/integrations/*`, staff first give a reason (`POST staff-access/integrations`, which opens changes for 2 hours per staff member and manufacturer, held in the cache). `ResolveTenant` then lets those writes through, and `App\Platform\StaffWriteAccess` logs each successful one as a `StaffAction` (`changed_integrations`, with the reason). The customer sees what changed and who changed it, but not why (`staff_changes` on the Integrations page, last 90 days). Stock checks and public stock aren't covered.
- Nothing loads from third parties (`InitialsAvatar` replaces ui-avatars.com).
- Published Filament assets under `public/` are gitignored and rebuilt by `filament:upgrade` on `composer install`.

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
- **Quotes:** `Quote` (dealer-scoped, numbered per dealer link from 1001, status moves in `Quote::MOVES`) and `QuoteLine` (`list_price_cents` copied in, `unit_price_cents` quoted). `QuoteController::priceLines` prices everything server-side from the dealer's list; only a price the dealer typed is taken from the browser. Only dealers write quotes (`build_quotes`) and move them draft ↔ sent. Manufacturer staff mark a sent quote won or lost (`close_quotes`, `POST quotes/{id}/outcome`, owner/admin/sales). Each side sees only its part (`Quote::visibleStatuses`, applied in every `QuoteController` lookup): dealers see draft and sent, manufacturers see sent, won and lost. A closed quote leaves the dealer's view (owner's decision, 2026-11-12). The price list is treated as **list prices** (what a dealer quotes from), not dealer cost. Revisit if the owner says lists are cost.
- **Sales orders:** a won quote becomes a `SalesOrder` (`SalesOrder::fromQuote`, in the same transaction as the outcome, with the quote row locked so a double click can't make two). Customer, lines and totals are copied from the quote. Numbered per manufacturer from SO-1001, one per quote. Statuses are received → in_production → shipped → delivered, or cancelled (with a reason) before it ships; see `SalesOrder::MOVES`. Staff fill in PO, wanted-by date and notes. Manufacturer staff only: `sales_orders` (owner, admin, sales, finance) reads, `manage_sales_orders` (owner, admin, inside_sales) moves orders. Dealers don't see them. It's dealer-scoped (`VisibleToOwnDealer`), so territory reps see only their dealers' orders. It's not in the usage report, which tracks dealer use. Defaults Claude picked while the owner's questions are open: dealers can't see their orders, and no machine or serial is linked. `php artisan sales-orders:backfill` makes orders for quotes won before sales orders existed.
- **ERP integration (Pro, `erp_integration`, owner and admin): see [docs/INTEGRATIONS.md](docs/INTEGRATIONS.md).** It's self-serve from the Integrations page.
  - **API keys** (`ApiKey`): only the SHA-256 hash is stored. Abilities are in `ApiKey::ABILITIES`.
  - **Integration API** under `/api/v1`, outside the `m/{tenant}` routes. The `api-key:<ability>` middleware (`AuthenticateApiKey`) sets the Tenant from the key, with no person, so it sees every dealer. Reads: `sales-orders`, `catalog`. Writes: `PATCH sales-orders/{id|SO-n}` (through `SalesOrder::moveTo` / `updateDetails`, the same path as the board), `POST machines` (upsert by serial, a result per row, history noted "From your ERP"), and `POST prices` (by item ERP number, all or nothing). Webhook envelopes carry `source: api|app` so an ERP can ignore its own echo.
  - **Webhooks** (`Webhook`, secret encrypted; `WebhookDelivery`, kept 30 days by `webhooks:prune`). `Webhooks::send()` queues a `DeliverWebhook` job after commit; it runs in `Tenant::run`, signs the body and retries with backoff. Events are in `Webhook::EVENTS`. `UrlGuard` allows only public https addresses, checked on save and before every call. `DEALERDOCK_WEBHOOKS_ALLOW_PRIVATE=true` is for local testing only.
  - **Payload:** the order shape is `App\Integrations\SalesOrderPayload`. Add fields, never rename or remove them.
  - **ERP numbers:** `erp_ref` on product models, options, sales orders and parts orders. Dealers use `dealer_links.dealer_code`, machines their serial.
  - **Who owns what** is decided in docs/INTEGRATIONS.md.
  - **Queue:** locally, webhooks need `php artisan queue:work` (`QUEUE_CONNECTION=database`).
  - **Who's in charge switches:** `Manufacturer::erpOwns('order_status'|'prices')`, stored in `settings.erp_owns` and applied only on a plan with ERP integration. With one on, staff can't move orders (`SalesOrderController::status`) or edit prices (`PriceListController::prices`), and the screens show read-only notes. The API always can.
  - **ERP connection (we call their API, 2026-11-12):** generic REST plus JSON, token in a header of their choice (`ErpConnection`, one per manufacturer, token encrypted and never returned). `ErpSync` handles `orders_push` (queued from `SalesOrder::fromQuote`, job `PushSalesOrderToErp`) and the pulls `order_status`, `prices` and `machines` (`RunErpSync`, every 15 minutes via `erp:sync`). Mapping is by dot paths, with `{since}` in the path. Each run is an `ErpSyncRun` (`erp-runs:prune`, 30 days). The pulls reuse `MachineSync` and `PriceSync`, which are shared with the v1 API, plus `SalesOrder::moveTo`. Webhooks they fire say `source: erp` (`Webhooks::asErp`). `ErpClient` sends the token only to the base URL's host. Details are in docs/INTEGRATIONS.md.
  - **Item numbers → models:** `ProductModel::forItemCode` matches exactly, else the longest model ERP number the code starts with plus a separator (builds like `1233A_2001_SS`). Machines only; prices stay exact. The machines pull's `add_models` switch (off by default) adds missing models to a "From your ERP" product line (`MachineSync::NEW_MODELS_LINE`). ERP error replies are summarised into the message (HTML stripped), and "isn't a list" names the reply's keys.
  - **Next:** link machines to sales orders; parts orders over the API; presets for common ERPs on top of the ERP connection.
- **Dealer adoption (2026-11-12): keep dealer inventory true with almost no typing.**
  - **Aging:** `Machine::daysInStock()` counts from the shipped date at a dealer, or the built date at the factory, falling back to the date added. It applies only to `Machine::UNSOLD` (inventory, demo, used); rentals and sold machines don't age. The SQL twin is `Machine::stockSinceSql()`. The `aged` filter (days) is shared by the list, fleet report and export. `FleetReport` returns `aging` bands (`Machine::AGE_BANDS`), oldest stock first. The dashboard shows `aged_machines` (180+ days). Band colours are `--age-1..4`, one violet hue checked with `validate_palette --ordinal`.
  - **Easier sign-in:** `users.two_factor_method` is 'totp' or 'email' (null means 'totp'). `EmailCodes` sends 6-digit codes: hashed in the cache, 10 minutes, 5 tries, at most once a minute (the automatic code at sign-in skips that) and 6 an hour. App users can ask for an emailed code at sign-in. `TrustedBrowser` ("remember this browser", 30 days) uses the `odh_trusted_browser` cookie, with only its hash stored; it's in the privacy policy's cookie table. A password reset and `User::clearTwoFactor()` forget all of a user's browsers. The staff console requires `hasAuthenticatorApp()`. The Account page is `/m/{slug}/account`. Text (SMS) codes aren't built (they need a provider). `phpunit.xml` pins `DEALERDOCK_REQUIRE_TWO_FACTOR=true`, because a local .env may turn it off.
  - **Stock checks:** `App\Support\StockChecks`, with `StockCheck` (dealer-scoped) and `StockCheckItem`.
    - **What's asked:** every machine at the dealer that isn't sold.
    - **The email:** `StockCheckRequested`, with a token link to the public `/stock-check/{token}` page. Opening it changes nothing; only the page's buttons answer.
    - **Answers:** "here" stamps `machines.confirmed_at`; "not here" (with a note) is listed for staff. A check is done when every machine is answered, and expired after 14 days.
    - **Rounds:** monthly if `settings.stock_checks_monthly` is on (`stock-checks:run`, daily at 07:00), or on demand.
    - **Permissions:** `stock_checks` (dealers answer, staff read) and `manage_stock_checks` (start, monthly switch).
  - **In stock near you (Pro, `public_inventory`):** `PublicStockController` at `GET /api/public/{slug}/stock`, plus the embeddable `/stock/{slug}` page. Counts only, by active dealer and model (new, demo, used), never serials. It's controlled by `settings.public_inventory.enabled`, and optionally `confirmed_only` (confirmed in the last 45 days). The switches are on the Integrations page.
- `withoutTenant()` lifts the manufacturer **and** dealer scopes (platform tools and tests).
- A stored file that's gone missing returns a 404 with a log warning (`Uploads::abortIfMissing`), not a 500.
- **Parts orders:** `PartsOrder` (dealer-scoped, numbered per manufacturer from 1001, `PartsOrder::MOVES`) and `PartsOrderLine` (part number in upper case, quantity, no prices). Dealers order (`order_parts`); the parts desk is owner, admin and parts (`manage_parts_orders`); inside sales can read. A dealer can cancel only while the order is `submitted`.
- **Preview runs** set `DEALERDOCK_DOCUMENTS_ROOT` to the scratchpad. Never delete anything under `backend/storage`.
- **Broadcasts (Pro):** `Broadcast` (its own dealer scope: only broadcasts sent to the dealer's link and not withdrawn), `BroadcastRecipient` (fixed at send), `BroadcastRead` (per user; create through `$broadcast->reads()`, since `broadcast_id` isn't fillable). Emails go out as `BroadcastSent`, a queued notification built from plain values. Staff use `broadcasts`; dealers use `announcements` (not plan-gated, so old ones stay readable after a downgrade).
- Watch for mass-assignment: a column missing from `$fillable` is silently dropped (it bit `DealerLink.price_list_id` and `BroadcastRead.broadcast_id`). Set such columns through a relation or `forceFill`, and assert the stored value in tests.
- **Usage analytics (Pro):** `UsageDay` holds one row per person, day and area, with a count. The SPA posts each page visit (`useRecordVisit` in `frontend/src/lib/usage.ts`, called from `Layout`) to `POST usage/visits`. It counts **page visits, not API requests**, because pages load lookups (models, dealers) that would credit areas nobody opened. `UsageDay::AREAS` maps each area to the feature that shows it, and a visit counts only if the plan has the report and the person can use that area. `usage:prune` (scheduled daily in `routes/console.php`) deletes rows older than 13 months. What's kept is described in the privacy policy. Changing what's recorded means updating that text and bumping the terms version.
- **Territories (Pro):** `Territory`, `territory_reps` (territory to staff `membership_id`) and `dealer_links.territory_id` (set only through `TerritoryController::assignDealer`, not fillable). `App\Support\Territories` decides who is limited: `sales_rep` or `inside_sales` (`Territory::REP_ROLES`) covering at least one territory, on a plan that includes territories. `Tenant::territoryLinkIds()` (cached per request, null = every dealer) feeds the `dealer` scopes: on `DealerLink`, and in `VisibleToOwnDealer`, where `Machine` also keeps factory stock (`territoryRepsSeeRowsWithoutDealer`). Validation rules that read `dealer_links` directly (`Rule::exists`) bypass the scopes, so add `whereIn('id', territoryLinkIds())` (see `MachineController::activeLinkRule`, broadcast recipients). Eager loads of a scoped-out link come back null. Managing territories is the `territories` feature (owner and admin); being limited needs no feature. `/api/me` gives a limited person `territories` (names).
- **Route ids:** tenant controllers take `int $id` and call `Model::findOrFail`. Implicit route-model binding runs before `ResolveTenant`, so it would throw `MissingTenantException`.
- **Brand colour (Pro, `custom_branding`):** `manufacturers.brand_color` ("#rrggbb", set by `POST brand`). `/api/me` sends it only when the plan includes it. `frontend/src/lib/brand.ts` derives light and dark `--accent`, `--accent-text` and `--accent-soft`, darkening or lightening until every pair passes 4.5:1. `useBrandColor` (in `Layout`) puts them on `:root[data-brand]`. New accent-coloured UI should use those tokens so it follows the brand.
- **App look (2026-11-12):** dark sidebar with `lucide-react` icons (`NAV` in `Layout.tsx`): product name and a collapse-to-icons button on top, a brand card (logo, name, plan; it opens the brand switcher when there's more than one), groups that fold open one at a time (a group with one visible item is just a link; every group is open while the tour runs), and a user menu at the bottom (account, tour, staff console, sign out). A white bar above each page shows group › page. The collapsed state lasts only for the visit (nothing in local storage). Warm off-white page, white cards. Kanban boards come from `components/Board.tsx` (machines, parts orders and quotes default to the board; `?view=list` gives the list, and a machines link with `?status=` opens the filtered list). Every move is a card button as well as a drag, and the allowed moves mirror the server's `MOVES`. `StatusDonut` (in `charts.tsx`) is the status donut on the dashboard and fleet report. `LIGHT`/`DARK` in `lib/brand.ts` must match the surface colours in `index.css`. The privacy policy says nothing is kept in local storage, so view choices live in the URL.
- **Own subdomain (`custom_domain`)** is still `'coming' => true`. It waits on hosting and DNS (no Azure work until the owner raises it).

The other option is the Phase 0 leftovers above. The Azure Blob driver for documents and a ClamAV server belong with the Azure deploy.

UI changes get checked in a real browser: headless Edge with `puppeteer-core` against a throwaway API (port 8011) and Vite (5174) on a scratch SQLite database, never the owner's dev servers or data.
