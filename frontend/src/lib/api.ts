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
