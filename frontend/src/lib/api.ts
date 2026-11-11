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
  if (body !== undefined) headers['Content-Type'] = 'application/json'
  const token = xsrfToken()
  if (token) headers['X-XSRF-TOKEN'] = token

  const response = await fetch(`/api${path}`, {
    method,
    headers,
    credentials: 'include',
    body: body === undefined ? undefined : JSON.stringify(body),
  })

  // A stale CSRF token (419) - get a fresh one and try once more.
  if (response.status === 419 && csrfReady) {
    csrfReady = false
    return request<T>(method, path, body)
  }

  const data = response.status === 204 ? null : await response.json().catch(() => ({}))
  if (!response.ok) {
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
  }
  manufacturers: ManufacturerAccess[]
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
  status: string
  city: string | null
  region: string | null
  country: string | null
}
