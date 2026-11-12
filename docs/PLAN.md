# OEM Dealer Hub - product and architecture plan

**OEM Dealer Hub** is a self-serve dealer platform and dealer network for equipment manufacturers. A manufacturer signs up, invites its dealers, and runs inventory, registrations, pricing, quotes, parts and documents in one place. Plans are **Starter at $49/month** (up to 10 dealers) and **Pro at $99/month** (unlimited dealers). ERP integration is sold separately, **by quote after a meeting**.

This is a new codebase. It reuses lessons, patterns and designs from building a dealer portal before. It does not reuse that portal's code, data, ERP specifics or credentials.

---

## 1. Product

### Who uses it

| Who | What they do |
|---|---|
| **Manufacturer** (the paying tenant) | Set up product lines, price lists and territories. Invite dealers. Approve registrations. See every dealer's fleet. Send announcements. |
| **Manufacturer staff** | The same tools, limited by role: sales rep, inside sales, warranty, finance, parts. |
| **Dealer** (free to the dealer) | See their machines and change their status. Register sales and rentals. Browse pricing, build quotes, order parts, read manuals. |
| **OEM Dealer Hub staff** | Support, billing, tenant health. |

### The "network" part

A dealer often carries several manufacturers' brands. The portal hit this with dealers that had two accounts, one per brand.

- In OEM Dealer Hub, a **dealer organization and its users are global**. One login works across every manufacturer the dealer is linked to, with a brand switcher.
- **Each manufacturer only sees its own relationship** with the dealer: its machines, registrations, prices and territory. It never sees another manufacturer's data.
- Later, a **public dealer locator** per manufacturer ("find a dealer near you"), and a directory where manufacturers can invite dealers who are already on OEM Dealer Hub.

### Modules (from the portal's feature list)

| Module | Includes | MVP? |
|---|---|---|
| Accounts and access | Manufacturer and dealer orgs, invites, roles, per-feature On/Off per user, 2FA | Yes |
| Products | Product lines, models, options, categories, serial and production numbers | Yes |
| Inventory and fleet | Each dealer's machines with statuses (Inventory, Demo, Rental, Sold, Used), transfers between dealers, a fleet report across all dealers with filters, charts and Excel export, end users | Yes |
| Product registration | Sale and rental registration form, documents (bill of sale, PDI), review queue with Approve/Reject, email notifications | Yes |
| Pricing and quotes | Price lists per dealer group and currency, catalog, quote builder, quote history | Yes |
| Documents | Manuals library searchable by serial number, uploads with malware scanning | Yes |
| Communications | Broadcast email/announcements, the onboarding tour, "What's new" tours | Tour in MVP; broadcasts next |
| Territories | Rep territories, dealer product lines, area of responsibility | Next |
| Parts orders | Parts order form sent to the parts desk | Next |
| Analytics | Usage by dealer and feature, for the manufacturer | Next |
| ERP integration | Connectors (see section 5) | Paid add-on |

---

## 2. Pricing

Manufacturers pay; **dealers are always free**, because they're the network. Charging them would slow adoption.

| | **Starter** | **Pro** | **ERP integration** |
|---|---|---|---|
| Price | **$49/month** | **$99/month** | By quote, after a meeting |
| Dealers | Up to 10 | **Unlimited** | - |
| Manufacturer staff users | Up to 3 | Unlimited | - |
| File storage (manuals, photos, documents) | 10 GB | 100 GB | - |
| Inventory, fleet report, registrations, manuals, quotes | Yes | Yes | - |
| Price lists | 1 | Unlimited | - |
| Broadcasts, usage analytics, territories | - | Yes | - |
| Own subdomain and branding | OEM Dealer Hub subdomain, logo only | Own subdomain, logo and colours | - |
| ERP connector | - | Available as add-on | Setup fee + monthly, per connector |

- **14-day free trial** of Pro, no card needed to start. At the end of the trial the manufacturer picks a plan.
- **Annual billing** at two months free ($490 / $990 a year).
- Billed through Stripe with Laravel Cashier: card, invoices, upgrade/downgrade and cancel any time. Downgrading to Starter with more than 10 dealers is blocked until they're under the limit. Dealers are never cut off silently.
- **Limits are enforced in one place** (a `Plan` class with the limits per plan), so prices and limits can change without touching the modules.
- The Starter numbers are a starting point. Adjust them after talking to the first few manufacturers.
- **ERP integration:** a "Request a quote" form that books a meeting. Pro only, since a manufacturer with an ERP is rarely a 10-dealer business.

---

## 3. Architecture

### Stack

- **Backend:** Laravel 12 (API + Filament for the OEM Dealer Hub staff console).
- **Frontend:** React + TypeScript + Vite single-page app. The same frontend is used by manufacturers and dealers, and shows modules by role and feature.
- **Database:** MySQL 8. Redis for cache and queues once there's real traffic. Until then, database queues.
- **Files:** object storage (Azure Blob or S3) from day one, never the web server's disk. The portal's move to a mounted share was needed precisely because uploads lived on the app server.
- **Hosting:** Azure App Service with deployment slots from the start (zero-downtime swap), GitHub Actions deploying through OIDC. No stored deploy secrets.

### Tenancy: one database, `manufacturer_id` on every row

- Every tenant-owned table has `manufacturer_id`. A global Eloquent scope applies it to every query from the signed-in user's current manufacturer, and new rows get it automatically.
- **Dealer orgs are global.** A `dealer_links` table connects a dealer to a manufacturer, with that manufacturer's own dealer code, price list, territory and status. Most tenant data hangs off the link, not the dealer.
- **Tests guard the boundary.** Each module has a test that signs in as manufacturer A and checks nothing from manufacturer B can be read or written, by ID, search, export or report.
- **Queued jobs and scheduled commands** set the tenant explicitly. They never rely on "whoever is signed in". The portal's ERP session crossover, where one login reached the wrong company, is the warning.
- Subdomain per manufacturer (e.g. `acme.` on the product domain) for branding. The app also works from the main domain with a brand switcher.

### Roles and feature access

The portal's model carries over: role defaults, plus a per-user On/Off override, plus `feature:` route middleware. Manufacturers can rename roles and choose defaults for their own staff.

### Core data model (first pass)

```
manufacturers         id, name, slug, logo, plan, stripe ids, settings
users                 id, name, email, 2fa, ... (global)
memberships           user_id, org_type (manufacturer|dealer), org_id, role, feature overrides
dealers               id, name, address, phone, website (global)
dealer_links          manufacturer_id, dealer_id, dealer_code, price_list_id, territory_id, status
product_lines         manufacturer_id, name
models                manufacturer_id, product_line_id, name, category, specs
options               manufacturer_id, model_id, name
machines              manufacturer_id, model_id, serial_no, production_no, dealer_link_id, status, end_user_id, built_at, shipped_at
machine_events        manufacturer_id, machine_id, type (status, transfer, registration), from, to, by, at, note
end_users             manufacturer_id, company, contact, email, phone, address
registrations         manufacturer_id, machine_id, dealer_link_id, type (sale|rental), status, details, documents
price_lists           manufacturer_id, name, currency, effective_at
price_list_items      price_list_id, model_id|option_id, price
quotes, quote_items   manufacturer_id, dealer_link_id, ...
documents             manufacturer_id, type (manual|bulletin|...), file, model/serial links
broadcasts            manufacturer_id, ...
activity_log          manufacturer_id, user_id, action, subject, at
```

Machine history is a log of events (`machine_events`), not overwritten columns. The portal's transfer history ended up as text appended to an ERP note field. Here it's real data from the start.

---

## 4. Security (sold as a feature)

Carried over from the portal:
- 2FA for everyone (authenticator app, email or SMS code) and a "trust this browser for 30 days" option.
- Sign-in and activity logs the manufacturer can see.
- Cloudflare Turnstile on sign-in and sign-up, and rate limiting.
- Malware scanning on every upload.
- Privacy policy and terms that list every kind of tracking. Any new tracking goes into the policy first.

New for SaaS:
- Tenant isolation tests (see section 3).
- Audit log export for a manufacturer's own users.
- Single sign-on (Microsoft or Google) for larger manufacturers, later.

---

## 5. ERP integration (the paid add-on)

The portal's Genius integration taught most of this. The design goal: **OEM Dealer Hub's own data is the source of truth for the app, and a connector syncs it with the ERP.** The app never depends on the ERP being reachable.

### Connector interface

Each ERP (Epicor, Infor, SAP Business One, Dynamics, Genius, or a CSV/SFTP drop) is a plug-in that implements:
- **Pull:** equipment and serials, dealers/customers, price lists, open production orders, end users. Incremental by cursor, with a full pass when the cursor is lost.
- **Push:** registrations, status changes, transfers, quotes and parts orders, mapped to the ERP's documents.

### Lessons built in

- **Test and live mode** per connector. Test writes go to a sandbox company. Show which mode is on in the admin screen. A local copy once ran in live mode by accident and created a real ERP record.
- **Outbox with retry.** Every push is a row (payload, status, attempts, last error), retried on a schedule, with a screen to see and resend failures. Pushes are idempotent, so a retry never creates duplicates.
- **Pull after push.** After writing, read the record back so the app shows what the ERP actually stored.
- **Through the ERP's API only.** Never write straight into its database. Where the API can't do something, build a small custom endpoint on the ERP side instead.
- **One session per ERP company.** Never reuse a login across companies.
- **Nightly incremental sync by default.** Syncing every minute against a slow or firewalled ERP once made the whole site slow. Timeouts are short, and a failing sync logs and moves on.
- **Static outbound IP** (NAT gateway) so customers' IT can allow-list one address, not a list of 31.
- **Field mapping in config, per customer.** Hard-coded field names and production-number formats were a constant source of fixes.
- **CSV import with an audit report** as the no-ERP fallback, and for onboarding a manufacturer's existing machines.

### The sales process

Request form → discovery meeting → fixed quote (setup + monthly) → connector configured in test mode against a sandbox → sign-off → live.

---

## 6. Operations

- **Deploys:** GitHub Actions → deploy to slot → run migrations on the slot → warm → swap → check the live site → swap back automatically if it's wrong. Migrations stay additive (add, never rename or drop in the same release).
- **Background work:** queue workers and the scheduler run as separate processes, stopped in the deploy slot.
- **Releases:** tagged versions and GitHub Releases. A version label in the app, and "What's new" tours for big features.
- **Monitoring:** health endpoint, error tracking (e.g. Sentry), uptime checks, and per-tenant sync failure alerts.
- **Backups:** daily database backups plus storage soft-delete, and a tested restore.
- **Self-serve onboarding:** sign up → name and logo → product lines → import machines (CSV) → invite dealers → guided tour.

---

## 7. Build phases

| Phase | Scope | Result |
|---|---|---|
| **0. Foundation** | Repo, Laravel + React, tenancy scopes and isolation tests, auth with 2FA, orgs, memberships, roles and feature access, Filament staff console, CI, Azure slots + OIDC | A signed-in manufacturer and dealer, each seeing only their own data |
| **1. MVP** | Products, machines and statuses, dealer inventory, fleet report and export, registrations, documents/manuals, CSV import, onboarding tour | Usable by a first pilot manufacturer |
| **2. Billing and launch** | Stripe/Cashier, Starter and Pro plans, trial, plan limits, marketing site and pricing page, sign-up flow, privacy and terms | Paid sign-ups |
| **3. Commercial** | Price lists, quotes, parts orders, territories, broadcasts, usage analytics | Full feature set |
| **4. Network** | Multi-manufacturer dealer switcher, dealer directory and invites, public dealer locator | The network |
| **5. ERP** | Connector framework, outbox, first connector, "Request a quote" flow | The paid add-on |

---

## 8. Open questions

1. Starter limits: are 10 dealers, 3 staff users and 10 GB right for $49?
2. Is there a first pilot manufacturer? It decides which ERP connector comes first.
3. Currencies and countries: USD and CAD from the start? Tax handling (Stripe Tax)?
4. Domain: is a domain for "OEM Dealer Hub" available (`.com` / `.app` / `.io`), and is the name clear as a trademark? (Renamed from DealerDock on 2026-11-11.)
5. Hosting budget for year one.
6. Ownership: confirm with KA Group that OEM Dealer Hub is yours to build and sell, since it grew out of work for them.
