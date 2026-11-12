# Connecting an ERP to OEM Dealer Hub

How a manufacturer's own systems (usually their ERP) connect to OEM Dealer
Hub, self-serve, and how it's built. Pro plan; managed by the owner and
admins under **Integrations**. Hands-on setup by us is still quoted separately.

## What a manufacturer gets

| Piece | What it's for |
|---|---|
| **API keys** | Their system reads from OEM Dealer Hub (`/api/v1`). One key per system, each limited to what's ticked. |
| **Webhooks** | We call their address the moment something happens, so their ERP doesn't have to keep asking. |
| **ERP connection** | For an ERP that has an API but can't call ours: we call theirs. We send new sales orders and fetch order status, prices and machines. |
| **ERP numbers** | The number their ERP uses for each dealer (`dealer_code`), model and option (`erp_ref`). It goes out with every order, so their system matches records without guessing from names. |

## Who owns what (decided 2026-11-12)

Two-way sync where both sides edit the same thing is where integrations go
wrong, so each kind of data has one owner:

| Data | Owner | So |
|---|---|---|
| Quotes, until won | OEM Dealer Hub | Dealers write them here; the ERP never sees drafts. |
| A new sales order | OEM Dealer Hub | Made here when a quote is won, then sent to the ERP (`sales_order.created`). |
| The order's ERP number | ERP | Sent by the ERP (`PATCH sales-orders`), or typed by staff on the order. Accepted even on a finished order. |
| Order status after it's received | **Their choice** | Off by default: staff move orders on the board, and the ERP may too. With **"Sales order status comes from our ERP"** on, only the API moves them; the board and order page are read-only and say so. |
| Prices | **Their choice** | Off by default: staff type them into price lists, and the ERP may send them too. With **"Prices come from our ERP"** on, only the API changes them; price lists are read-only and say so. |
| Machines | Both | The ERP adds and updates them by serial (`POST machines`); staff and dealers keep using the machine pages. Every ERP change is in the machine's history as "From your ERP". |
| Dealer, model and option numbers | ERP | Typed in under Integrations → Match your ERP's numbers (the ERP reads them back from `GET catalog`). |

The two switches are under Integrations → Who's in charge, and they're stored in
`manufacturers.settings.erp_owns` (`Manufacturer::erpOwns()`). They only apply while
the plan includes ERP integration: on Starter, staff can move orders and edit prices again.

## The API (v1)

```
Authorization: Bearer odh_...        (one key = one manufacturer)

GET   /api/v1/sales-orders?updated_since=<ISO 8601, URL-encoded>&status=<status>&page=<n>   sales_orders:read
GET   /api/v1/sales-orders/SO-1001     (or by id: /api/v1/sales-orders/42)              sales_orders:read
PATCH /api/v1/sales-orders/SO-1001     erp_ref, po_number, requested_date, status,      sales_orders:write
                                       cancel_reason (needed to cancel)
GET   /api/v1/catalog                  dealers + customer numbers, models/options +     catalog:read
                                       item numbers, price lists
POST  /api/v1/machines                 { machines: [...] }, up to 500, upsert by serial  machines:write
POST  /api/v1/prices                   { price_list, prices: [{ item, price }] }         prices:write
```

- **Order updates** use the same rules as the board (`SalesOrder::moveTo`):
  details first, then the move, in one transaction, so a refused move changes nothing.
- **Machines:** each row has `serial_no`, plus `model` (their item number) or
  `model_name` for a new one, `dealer_code` (left out: dealer unchanged;
  null: back to the factory; only active dealers), `status`, `production_no`,
  `built_at` and `shipped_at`. Each row is its own transaction and gets its own result:
  `created`, `updated`, `unchanged` or `error` with messages. Bad rows don't hold up good ones.
- **Prices** are matched by the item's ERP number. All or nothing, like the price
  list page: one unknown item, duplicate number or bad price, and nothing is
  saved, with every problem listed. `null` means "price on request".
- **Echoes:** a change made through an API key still fires webhooks, with
  `"source": "api"` (`"erp"` when the ERP connection fetched it, otherwise `"app"`), so the ERP can ignore its own update.

- Lists are oldest change first, 100 to a page, so a sync can keep the newest
  `updated_at` it saw and ask for anything since.
- 120 requests a minute per key.
- Errors: `401 invalid_key`, `403 missing_ability`, `403 upgrade_required`
  (not on Pro), `402 account_lapsed`, `404 not_found`, `422` (bad filter).

## The ERP connection (we call their API)

The other direction, for an ERP with a REST API that can't, or won't, call ours.
Under Integrations → Connect to your ERP's API, they enter the API address, a
sign-in header and a token (stored encrypted, never sent back), and turn on any of the four syncs.
Code: `AppIntegrationsErpSync` (the syncs), `ErpClient` (the calls),
`ErpConnection` (one per manufacturer), `ErpSyncRun` (each run, kept 30 days by `erp-runs:prune`).

| Sync | When | What |
|---|---|---|
| `orders_push` | A quote is won (`SalesOrder::fromQuote` → `ErpSync::queuePush`) | POSTs the sales order (`SalesOrderPayload`, the same shape as the API) to their path, with `Idempotency-Key: oemdh-sales-order-<id>`. Reads their order number from the reply (`ref_field`, a dot path) into `erp_ref`. Retried like a webhook, but a 4xx other than 408 or 429 fails at once. Can be sent again from the page. |
| `order_status` | Every 15 minutes (`erp:sync`) or Sync now | GETs their orders, finds ours by `erp_ref` or by our number (`match`), maps their status words to ours, and moves the order through `SalesOrder::moveTo`. A jump (say, received to delivered) walks the allowed steps (`ErpSync::pathTo`). Unmatched statuses and orders that aren't ours are left alone. |
| `prices` | The same | GETs prices onto one chosen price list through `PriceSync`: all or nothing, but item numbers we don't have are **skipped**, because an ERP has many more items than a manufacturer sells through dealers. |
| `machines` | The same | GETs machines and upserts each by serial through `MachineSync`, the same code as `POST /api/v1/machines`, each row on its own. With **"Add models we don't have yet"** (`add_models`, off by default, this sync only), a new machine whose model isn't found adds one: named from `model_name`, else the item number, with the item number as its ERP number, in a "From your ERP" product line. |

**Item numbers and models:** many ERPs give each build its own item number (`1233A_2001_SS`,
`1233A_2101_ADT_NECK`). So when finding a machine's model (API and ERP connection alike), a model's
ERP number also covers item numbers that start with it and then `_ - . /` or a space
(`ProductModel::forItemCode`). An exact match wins, then the longest; `1233A` never takes `1233AB_…`.
With both `model` and `model_name`, a model with that name is used when the item number finds none.
Prices still match item numbers exactly, since each build has its own price.

**Mapping, for any JSON API:** each pull has a `path` (`{since}` becomes the last
run's start minus 2 minutes, ISO 8601 UTC, URL-encoded; `1970-01-01T00:00:00Z` the first time
or after "Fetch everything again"), `list` (a dot path to the array, empty if the reply is one),
`next` (a dot path to a next-page link, up to 50 pages; keys with dots, like `@odata.nextLink`, work)
and `fields` (our field → their dot path). A field that's mapped but missing from an item is left
out, not nulled, so a missing dealer never sends a machine back to the factory. "Try it" fetches
the first page and says what each item would do, without writing anything.

**Where it got to:** `erp_connections.state.<sync>.since` moves on after a run that didn't fail,
including one with row problems, which are listed on the run. Fix the mapping and use "Fetch
everything again". A failed run doesn't move it, so the next run tries the same range.

**Safety:** `UrlGuard` before every call, no redirects, the token goes **only to the base URL's
scheme, host and port** (a next-page link elsewhere fails the run), 30-second timeout, 10 MB
per reply. Header names that would break the request (`Host`, `Content-Type`…) are refused.
One run of each sync per manufacturer at a time (`RunErpSync` is `ShouldBeUnique`). Only on a plan
with `erp_integration`, and never for a paused account.

## Webhooks

A `POST` of JSON to their address:

```json
{
  "id": "6f0c...uuid",
  "event": "sales_order.created",
  "created_at": "2026-11-12T15:04:05+00:00",
  "manufacturer": "acme",
  "data": { "...": "the same sales order the API returns" }
}
```

Events: `sales_order.created`, `sales_order.status_changed`,
`sales_order.updated`, and `ping` (the "Send a test" button).

Headers: `X-OEMDH-Event`, `X-OEMDH-Delivery` (the id, the same on every
retry, so the receiver can ignore a repeat), and `X-OEMDH-Signature`.

**Checking the signature:** `X-OEMDH-Signature: t=<unix time>,v1=<hex>`, where
`v1 = HMAC-SHA256(secret, t + "." + raw body)`. Compare in constant time and
reject anything more than 5 minutes old.

**Delivery:** any 2xx within 10 seconds is delivered. Otherwise it's retried
after 1 minute, 5 minutes, 30 minutes, 2 hours and 6 hours, then marked
failed; it can be sent again from the Integrations page. Calls are kept 30
days (`webhooks:prune`, daily).

## The sales order shape

`App\Integrations\SalesOrderPayload` builds it, and the API and webhooks both
use it. **Add fields, never rename or remove them**, because that breaks people's
integrations. Money is exact strings ("1234.50"). It carries `erp_ref`, `dealer.dealer_code`
and, on each line, `item.erp_ref`.

## Set up by us (OEM Dealer Hub staff)

For the paid "we set it up for you" service, our staff can make changes on a customer's
Integrations page: API keys, webhooks, ERP numbers, who's in charge, and the ERP connection.
Staff looking in are read-only everywhere else. Before changing anything they say why, which opens
changes for 2 hours. Each change that goes through is logged in the staff console's Activity
with that reason, and the customer sees a "Changed by OEM Dealer Hub staff" list on the page
(what, who, when; not the reason). Code: `App\Platform\StaffWriteAccess`, `ResolveTenant`.

## Safety

- **Keys:** only a SHA-256 hash is stored; the key is shown once. Revoking is
  immediate. A key works only on Pro and while the account isn't paused.
- **Signing secrets:** stored encrypted (`APP_KEY`), shown once, can be replaced.
- **Webhook addresses:** public `https` only (`App\Integrations\UrlGuard`): never
  localhost, private networks or the cloud metadata address, checked when
  saved and again before every call; redirects aren't followed.
  `DEALERDOCK_WEBHOOKS_ALLOW_PRIVATE=true` relaxes this for local testing only.
- **Tenancy:** keys, webhooks and calls are tenant-owned; covered in
  `TenantIsolationTest::test_integrations_stay_inside_their_manufacturer`.
- **Queue:** calls go out on the queue (`DeliverWebhook`), after the database
  commit. Production needs a queue worker (it already does for emails). Locally,
  run `php artisan queue:work`, or nothing is sent.

## Next (not built)

1. **Linking machines to sales orders**, so shipping an order can name the serials that went out.
2. **More events and endpoints:** parts orders (and their status from the ERP), registrations.
3. **Ready-made connectors** (presets for the ERP connection's settings) for the one or two ERPs most customers use, built
   on this same API, only if demand shows up.
