/**
 * The API client. Signs in with Laravel Sanctum's session cookie: the first
 * request that changes something fetches the CSRF cookie, and every request
 * after sends it back as X-XSRF-TOKEN.
 */

export class ApiError extends Error {
  status: number
  code?: string
  errors: Record<string, string[]>

  constructor(status: number, body: { message?: string; code?: string; errors?: Record<string, string[]> }) {
    super(body.message || `Request failed (${status})`)
    this.status = status
    this.code = body.code
    this.errors = body.errors ?? {}
  }

  /** The first error for a field, for showing under that input. */
  field(name: string): string | undefined {
    return this.errors[name]?.[0]
  }
}

let csrfReady = false

function xsrfToken(): string | null {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)
  return match ? decodeURIComponent(match[1]) : null
}

async function request<T>(method: string, path: string, body?: unknown): Promise<T> {
  if (method !== 'GET' && !csrfReady) {
    await fetch('/sanctum/csrf-cookie', { credentials: 'include' })
    csrfReady = true
  }

  const headers: Record<string, string> = { Accept: 'application/json' }
  // FormData (file uploads) sets its own multipart Content-Type, boundary included.
  const isForm = body instanceof FormData
  if (body !== undefined && !isForm) headers['Content-Type'] = 'application/json'
  const token = xsrfToken()
  if (token) headers['X-XSRF-TOKEN'] = token

  const response = await fetch(`/api${path}`, {
    method,
    headers,
    credentials: 'include',
    body: body === undefined ? undefined : isForm ? body : JSON.stringify(body),
  })

  // A stale CSRF token (419) - get a fresh one and try once more.
  if (response.status === 419 && csrfReady) {
    csrfReady = false
    return request<T>(method, path, body)
  }

  const data = response.status === 204 ? null : await response.json().catch(() => ({}))
  if (!response.ok) {
    // The account was paused while the app was open: let the app catch up (AuthProvider refreshes /me).
    // Same for terms that changed while the app was open (403 terms_not_accepted).
    if (response.status === 402 || data?.code === 'terms_not_accepted') window.dispatchEvent(new Event('dealerdock:standing-changed'))
    throw new ApiError(response.status, data ?? {})
  }

  return data as T
}

export const api = {
  get: <T>(path: string) => request<T>('GET', path),
  post: <T>(path: string, body?: unknown) => request<T>('POST', path, body ?? {}),
  delete: <T>(path: string, body?: unknown) => request<T>('DELETE', path, body),
}

// ---- Types shared with the backend ----

export interface ManufacturerAccess {
  id: number
  name: string
  slug: string
  plan: 'trial' | 'starter' | 'pro'
  trial_ends_at: string | null
  /** The manufacturer's logo (an API path with a version), or null. */
  logo_url: string | null
  /** Where the account stands (App\Support\Plan::standing): a lapsed account is paused. */
  standing: 'trialing' | 'active' | 'past_due' | 'lapsed'
  acting_as: 'manufacturer' | 'dealer'
  dealer: { id: number; name: string } | null
  role: string
  features: string[]
}

export interface Me {
  user: {
    id: number
    name: string
    email: string
    two_factor: boolean
    two_factor_required: boolean
    /** Guided tours this person has finished or skipped. */
    tours_seen: string[]
    /** The version of the terms they agreed to (null: not yet). */
    terms_version: string | null
  }
  manufacturers: ManufacturerAccess[]
  /** The current version of the terms; the app asks again when it differs from the user's. */
  terms_version: string
}

/** In order of a machine's usual life at a dealer (App\Models\Machine::STATUSES). */
export const MACHINE_STATUSES = ['inventory', 'demo', 'rental', 'sold', 'used'] as const
export type MachineStatus = (typeof MACHINE_STATUSES)[number]

export interface ProductModelRow {
  id: number
  name: string
  category: string | null
  product_line: { id: number; name: string }
  options: { id: number; name: string }[]
  machines: number
}

export interface MachineRow {
  id: number
  serial_no: string
  production_no: string | null
  status: MachineStatus
  model: { id: number; name: string }
  product_line: string
  /** The dealer link it's with; null while it's still at the manufacturer. */
  dealer: { id: number; name: string } | null
  built_at: string | null
  shipped_at: string | null
  updated_at: string | null
}

export interface MachineDetail extends MachineRow {
  history: { id: number; type: string; summary: string; note: string | null; by: string | null; at: string | null }[]
}

export interface MachinePage {
  data: MachineRow[]
  total: number
  page: number
  last_page: number
}

export interface DealerRow {
  id: number
  dealer_id: number
  name: string
  dealer_code: string | null
  /** Their own price list; null = the default. */
  price_list_id: number | null
  status: string
  city: string | null
  region: string | null
  country: string | null
}

export const STATUS_LABELS: Record<MachineStatus, string> = {
  inventory: 'Inventory',
  demo: 'Demo',
  rental: 'Rental',
  sold: 'Sold',
  used: 'Used',
}

export interface FleetSummary {
  total: number
  by_status: Record<MachineStatus, number>
  by_dealer: { id: number | null; name: string; total: number; statuses: Record<MachineStatus, number> }[]
  by_model: { id: number; name: string; line: string; total: number; statuses: Record<MachineStatus, number> }[]
}

/**
 * Downloads a file from the API (signed in with the same session cookie) and
 * hands it to the browser to save, under the name the API gives it.
 */
export async function download(path: string, fallbackName: string): Promise<void> {
  const response = await fetch(`/api${path}`, { credentials: 'include', headers: { Accept: 'application/json, */*' } })
  if (!response.ok) {
    throw new ApiError(response.status, await response.json().catch(() => ({})))
  }

  const disposition = response.headers.get('Content-Disposition') ?? ''
  const name = disposition.match(/filename="?([^";]+)"?/)?.[1] ?? fallbackName
  const url = URL.createObjectURL(await response.blob())
  const link = document.createElement('a')
  link.href = url
  link.download = name
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}

export type RegistrationStatus = 'pending' | 'approved' | 'rejected'

export interface RegistrationRow {
  id: number
  type: 'sale' | 'rental'
  status: RegistrationStatus
  delivered_on: string
  machine: { id: number; serial_no: string; model: string }
  dealer: string
  customer: string
  submitted_by: string | null
  submitted_at: string | null
}

export interface RegistrationDetail extends RegistrationRow {
  product_line: string
  customer_details: Record<string, string>
  notes: string | null
  reviewed_by: string | null
  reviewed_at: string | null
  rejection_reason: string | null
  documents: { id: number; kind: 'bill_of_sale' | 'pdi' | 'other'; name: string; size: number; scan_status: 'clean' | 'not_scanned' }[]
}

export interface RegistrationPage {
  data: RegistrationRow[]
  total: number
  page: number
  last_page: number
}

export type DocumentKind = 'manual' | 'parts_book' | 'bulletin' | 'brochure' | 'other'

export interface LibraryDocument {
  id: number
  title: string
  kind: DocumentKind
  description: string | null
  audience: 'everyone' | 'staff'
  name: string
  mime: string
  size: number
  scan_status: 'clean' | 'not_scanned'
  updated_at: string | null
  /** Empty: covers every machine. */
  covers: { model_id: number; model: string; serial_from: string | null; serial_to: string | null; range: string | null }[]
}

export interface LibraryPage {
  data: LibraryDocument[]
  total: number
  page: number
  last_page: number
}

export interface SerialLookup {
  machine: { serial_no: string; model: string; product_line: string }
  documents: LibraryDocument[]
}

export type ImportAction = 'create' | 'update' | 'unchanged' | 'skip' | 'error'

export interface ImportSummary {
  rows: number
  create: number
  update: number
  unchanged: number
  skip: number
  error: number
}

export interface ImportRow {
  /** The row number in the person's spreadsheet. */
  line: number
  serial: string
  action: ImportAction
  messages: string[]
  changes: { field: string; from: string | null; to: string | null }[]
}

export interface ImportPreview {
  file_name: string
  columns: { used: { field: string; header: string }[]; ignored: string[] }
  summary: ImportSummary
  rows: ImportRow[]
}

export interface ImportRecord {
  id: number
  file_name: string
  mode: 'add' | 'add_update'
  by: string | null
  at: string | null
  summary: ImportSummary
}

export interface BillingPlan {
  key: 'starter' | 'pro'
  label: string
  monthly_usd: number
  annual_usd: number
  limits: { dealers: number | null; staff: number | null; storage_gb: number | null; price_lists: number | null }
  excludes: string[]
  /** What the plan adds over Starter; coming = not built yet. */
  extras: { label: string; coming: boolean }[]
  /** Why the account can't move to this plan right now; empty when it can. */
  blockers: string[]
}

export interface Billing {
  standing: ManufacturerAccess['standing']
  plan: 'starter' | 'pro' | null
  interval: 'monthly' | 'annual' | null
  trial_ends_at: string | null
  subscription: { status: string; ends_at: string | null; trial_ends_at: string | null } | null
  managed_outside: boolean
  card: { brand: string | null; last_four: string } | null
  usage: { dealers: number; staff: number; storage_gb: number }
  configured: boolean
  plans: BillingPlan[]
}

export interface PriceListSummary {
  id: number
  name: string
  currency: 'USD' | 'CAD'
  is_default: boolean
  notes: string | null
  items_count: number | null
  dealers_count: number | null
  updated_at: string | null
}

/** Prices are strings like "1234.50" (exact cents from the API); null = price on request. */
export interface CatalogLine {
  id: number
  name: string
  models: { id: number; name: string; category: string | null; price: string | null; options: { id: number; name: string; price: string | null }[] }[]
}

export interface PriceListDetail extends PriceListSummary {
  lines: CatalogLine[]
}

export interface Catalog {
  /** Dealers get only the currency; staff also the list's name. */
  price_list: { id?: number; name?: string; currency: 'USD' | 'CAD'; is_default?: boolean } | null
  lines: CatalogLine[]
}
