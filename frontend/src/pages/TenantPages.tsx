import { useState, type FormEvent } from 'react'
import { useParams } from 'react-router-dom'
import { api, ApiError } from '../lib/api'
import { useApi } from '../lib/useApi'
import { useCurrentManufacturer } from '../components/Layout'
import { Field, FormError, formValues } from '../components/Form'

function useSlug(): string {
  return useParams().slug!
}

function Loading({ error }: { error: ApiError | null }) {
  return error ? <p className="form-error">{error.message}</p> : <p className="muted">Loading…</p>
}

// ---- Dashboard ----

interface Dashboard {
  acting_as: 'manufacturer' | 'dealer'
  plan?: { key: string; label: string; trial_ends_at: string | null }
  usage?: Record<string, { used: number; limit: number | null }>
  product_lines: number
}

const USAGE_LABELS: Record<string, string> = { dealers: 'Dealers', staff: 'Staff users' }

export function DashboardPage() {
  const slug = useSlug()
  const current = useCurrentManufacturer()
  const { data, error } = useApi<Dashboard>(`/m/${slug}/dashboard`)

  if (!data) return <Loading error={error} />

  if (data.acting_as === 'dealer') {
    return (
      <>
        <h1>{current?.name}</h1>
        <p className="lede">You're signed in as {current?.dealer?.name}, a dealer for {current?.name}.</p>
        <div className="tiles">
          <div className="tile">
            <span className="tile-label">Product lines</span>
            <span className="tile-value">{data.product_lines}</span>
          </div>
        </div>
      </>
    )
  }

  const trialDays = data.plan?.trial_ends_at ? Math.max(0, Math.ceil((Date.parse(data.plan.trial_ends_at) - Date.now()) / 86_400_000)) : null

  return (
    <>
      <h1>Dashboard</h1>
      <p className="lede">
        {data.plan?.label} plan{trialDays !== null && data.plan?.key === 'trial' && <> · {trialDays} days left in your trial</>}
      </p>
      <div className="tiles">
        {Object.entries(data.usage ?? {}).map(([key, u]) => (
          <div className="tile" key={key}>
            <span className="tile-label">{USAGE_LABELS[key] ?? key}</span>
            <span className="tile-value">
              {u.used}
              <small>{u.limit === null ? ' · unlimited' : ` of ${u.limit}`}</small>
            </span>
            {u.limit !== null && (
              <span className="meter" aria-hidden>
                <span style={{ width: `${Math.min(100, (u.used / u.limit) * 100)}%` }} />
              </span>
            )}
          </div>
        ))}
        <div className="tile">
          <span className="tile-label">Product lines</span>
          <span className="tile-value">{data.product_lines}</span>
        </div>
      </div>
    </>
  )
}

// ---- Product lines ----

export function ProductLinesPage() {
  const slug = useSlug()
  const current = useCurrentManufacturer()
  const { data, error, reload } = useApi<{ id: number; name: string }[]>(`/m/${slug}/product-lines`)
  const canEdit = current?.features.includes('manage_products')

  return (
    <>
      <h1>Product lines</h1>
      {canEdit && <AddForm path={`/m/${slug}/product-lines`} fields={[{ name: 'name', label: 'New product line' }]} button="Add" onAdded={reload} inline />}
      {!data ? (
        <Loading error={error} />
      ) : data.length === 0 ? (
        <p className="muted">No product lines yet.</p>
      ) : (
        <ul className="list">
          {data.map((l) => (
            <li key={l.id}>{l.name}</li>
          ))}
        </ul>
      )}
    </>
  )
}

// ---- Dealers ----

interface DealerRow {
  id: number
  name: string
  dealer_code: string | null
  status: string
  city: string | null
  region: string | null
  country: string | null
}

export function DealersPage() {
  const slug = useSlug()
  const current = useCurrentManufacturer()
  const { data, error, reload } = useApi<DealerRow[]>(`/m/${slug}/dealers`)
  const [adding, setAdding] = useState(false)

  return (
    <>
      <div className="page-head">
        <h1>Dealers</h1>
        {current?.features.includes('manage_dealers') && !adding && (
          <button className="primary" onClick={() => setAdding(true)}>
            Add dealer
          </button>
        )}
      </div>

      {adding && (
        <section className="panel">
          <h2>Add a dealer</h2>
          <AddForm
            path={`/m/${slug}/dealers`}
            fields={[
              { name: 'name', label: 'Dealer name', required: true },
              { name: 'dealer_code', label: 'Your dealer code', hint: 'Optional - e.g. its customer number in your ERP.' },
              { name: 'city', label: 'City' },
              { name: 'region', label: 'State / province' },
              { name: 'contact_name', label: 'Contact name' },
              { name: 'contact_email', label: 'Contact email', type: 'email', hint: "They'll get an email to set a password and sign in." },
            ]}
            button="Add dealer"
            onAdded={() => {
              setAdding(false)
              reload()
            }}
            onCancel={() => setAdding(false)}
          />
        </section>
      )}

      {!data ? (
        <Loading error={error} />
      ) : data.length === 0 ? (
        <p className="muted">No dealers yet. Add your first one to invite them in.</p>
      ) : (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Dealer</th>
                <th>Code</th>
                <th>Location</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              {data.map((d) => (
                <tr key={d.id}>
                  <td>{d.name}</td>
                  <td>{d.dealer_code ?? '—'}</td>
                  <td>{[d.city, d.region, d.country].filter(Boolean).join(', ') || '—'}</td>
                  <td>
                    <span className={`badge badge-${d.status}`}>{d.status}</span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  )
}

// ---- Staff ----

interface StaffRow {
  id: number
  name: string
  email: string
  role: string
  two_factor: boolean
}

const ROLES = ['admin', 'sales_rep', 'inside_sales', 'warranty', 'finance', 'parts']

export function StaffPage() {
  const slug = useSlug()
  const { data, error, reload } = useApi<StaffRow[]>(`/m/${slug}/staff`)
  const [adding, setAdding] = useState(false)

  return (
    <>
      <div className="page-head">
        <h1>Staff</h1>
        {!adding && (
          <button className="primary" onClick={() => setAdding(true)}>
            Invite staff
          </button>
        )}
      </div>

      {adding && (
        <section className="panel">
          <h2>Invite a staff member</h2>
          <AddForm
            path={`/m/${slug}/staff`}
            fields={[
              { name: 'name', label: 'Name', required: true },
              { name: 'email', label: 'Email', type: 'email', required: true },
              { name: 'role', label: 'Role', options: ROLES },
            ]}
            button="Send invite"
            onAdded={() => {
              setAdding(false)
              reload()
            }}
            onCancel={() => setAdding(false)}
          />
        </section>
      )}

      {!data ? (
        <Loading error={error} />
      ) : (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Two-factor</th>
              </tr>
            </thead>
            <tbody>
              {data.map((s) => (
                <tr key={s.id}>
                  <td>{s.name}</td>
                  <td>{s.email}</td>
                  <td>{s.role.replace('_', ' ')}</td>
                  <td>{s.two_factor ? 'On' : <span className="muted">Not set up</span>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  )
}

// ---- A small add form used by the pages above ----

interface AddField {
  name: string
  label: string
  type?: string
  required?: boolean
  hint?: string
  options?: string[]
}

function AddForm(props: {
  path: string
  fields: AddField[]
  button: string
  onAdded: () => void
  onCancel?: () => void
  inline?: boolean
}) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)

  async function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const form = e.currentTarget
    const values = Object.fromEntries(Object.entries(formValues(form)).filter(([, v]) => v !== ''))
    setBusy(true)
    setError(null)
    try {
      await api.post(props.path, values)
      form.reset()
      props.onAdded()
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError(0, { message: 'Could not reach DealerDock.' }))
    } finally {
      setBusy(false)
    }
  }

  return (
    <form onSubmit={onSubmit} noValidate className={props.inline ? 'inline-form' : 'grid-form'}>
      {props.fields.map((f) =>
        f.options ? (
          <div className="field" key={f.name}>
            <label htmlFor={`field-${f.name}`}>{f.label}</label>
            <select id={`field-${f.name}`} name={f.name}>
              {f.options.map((o) => (
                <option key={o} value={o}>
                  {o.replace('_', ' ')}
                </option>
              ))}
            </select>
          </div>
        ) : (
          <Field key={f.name} label={f.label} name={f.name} type={f.type ?? 'text'} required={f.required} hint={f.hint} error={error} />
        ),
      )}
      <div className="form-actions">
        <FormError error={error} />
        <button className="primary" disabled={busy}>
          {busy ? 'Saving…' : props.button}
        </button>
        {props.onCancel && (
          <button type="button" className="link" onClick={props.onCancel}>
            Cancel
          </button>
        )}
      </div>
    </form>
  )
}
