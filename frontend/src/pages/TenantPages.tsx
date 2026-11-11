import { useState } from 'react'
import { Link } from 'react-router-dom'
import { api, ApiError, MACHINE_STATUSES, type DealerRow, type PriceListSummary, type ProductModelRow } from '../lib/api'
import { useApi } from '../lib/useApi'
import { useSlug } from '../lib/tenant'
import { useCurrentManufacturer } from '../components/Layout'
import { AddForm, Loading } from '../components/AddForm'
import { GettingStarted } from './SetupPages'

// ---- Dashboard ----

interface Dashboard {
  acting_as: 'manufacturer' | 'dealer'
  plan?: { key: string; label: string; trial_ends_at: string | null }
  usage?: Record<string, { used: number; limit: number | null }>
  product_lines: number
  machines: Record<string, number>
  registrations_pending: number
  /** Dealers: announcements this person hasn't opened. */
  announcements_unread?: number
}

const USAGE_LABELS: Record<string, string> = { dealers: 'Dealers', staff: 'Staff users', storage_gb: 'File storage (GB)' }

/** Registrations waiting for the manufacturer - the reviewer's to-do, the dealer's "still waiting". */
function PendingTile({ count, dealer }: { count: number; dealer: boolean }) {
  const current = useCurrentManufacturer()
  if (!current?.features.includes('registrations')) return null

  return (
    <Link className="tile tile-link" to="registrations?status=pending">
      <span className="tile-label">{dealer ? 'Registrations waiting for approval' : 'Registrations to review'}</span>
      <span className="tile-value">{count}</span>
    </Link>
  )
}

/** One tile per machine status, each linking to the list filtered to it. */
function MachineTiles({ counts }: { counts: Record<string, number> }) {
  const current = useCurrentManufacturer()
  if (!current?.features.includes('machines')) return null

  return (
    <>
      <h2 className="section-title">Machines</h2>
      <div className="tiles">
        {MACHINE_STATUSES.map((s) => (
          <Link className="tile tile-link" key={s} to={`machines?status=${s}`}>
            <span className="tile-label">{s.charAt(0).toUpperCase() + s.slice(1)}</span>
            <span className="tile-value">{counts[s] ?? 0}</span>
          </Link>
        ))}
      </div>
    </>
  )
}

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
          {!!data.announcements_unread && (
            <Link className="tile tile-link tile-news" to="announcements">
              <span className="tile-label">New announcements from {current?.name}</span>
              <span className="tile-value">{data.announcements_unread}</span>
            </Link>
          )}
          <PendingTile count={data.registrations_pending} dealer />
        </div>
        <MachineTiles counts={data.machines} />
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
      <GettingStarted />
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
        <PendingTile count={data.registrations_pending} dealer={false} />
      </div>
      <MachineTiles counts={data.machines} />
    </>
  )
}

// ---- Products: lines, models and options ----

export function ProductsPage() {
  const slug = useSlug()
  const current = useCurrentManufacturer()
  const lines = useApi<{ id: number; name: string }[]>(`/m/${slug}/product-lines`)
  const models = useApi<ProductModelRow[]>(`/m/${slug}/product-models`)
  const canEdit = !!current?.features.includes('manage_products')
  const [addingModel, setAddingModel] = useState(false)

  return (
    <>
      <h1>Products</h1>

      <h2 className="section-title">Product lines</h2>
      {canEdit && <AddForm path={`/m/${slug}/product-lines`} fields={[{ name: 'name', label: 'New product line' }]} button="Add" onAdded={lines.reload} inline />}
      {!lines.data ? (
        <Loading error={lines.error} />
      ) : lines.data.length === 0 ? (
        <p className="muted">No product lines yet. Add one, then the models in it.</p>
      ) : (
        <ul className="chips">
          {lines.data.map((l) => (
            <li key={l.id}>{l.name}</li>
          ))}
        </ul>
      )}

      <div className="page-head section-head">
        <h2>Models</h2>
        {canEdit && !addingModel && !!lines.data?.length && (
          <button className="primary" onClick={() => setAddingModel(true)}>
            Add model
          </button>
        )}
      </div>

      {addingModel && lines.data && (
        <section className="panel">
          <h2>Add a model</h2>
          <AddForm
            path={`/m/${slug}/product-models`}
            fields={[
              { name: 'product_line_id', label: 'Product line', options: lines.data.map((l) => ({ value: String(l.id), label: l.name })) },
              { name: 'name', label: 'Model name', required: true },
              { name: 'category', label: 'Category', hint: 'Optional - e.g. "Pull-type scraper".' },
            ]}
            button="Add model"
            onAdded={() => {
              setAddingModel(false)
              models.reload()
            }}
            onCancel={() => setAddingModel(false)}
          />
        </section>
      )}

      {!models.data ? (
        <Loading error={models.error} />
      ) : models.data.length === 0 ? (
        <p className="muted">No models yet.</p>
      ) : (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Model</th>
                <th>Line</th>
                <th>Category</th>
                <th>Options</th>
                <th>Machines</th>
              </tr>
            </thead>
            <tbody>
              {models.data.map((m) => (
                <tr key={m.id}>
                  <td>{m.name}</td>
                  <td>{m.product_line.name}</td>
                  <td>{m.category ?? '—'}</td>
                  <td className="wrap">
                    <OptionsCell slug={slug} model={m} canEdit={canEdit} onAdded={models.reload} />
                  </td>
                  <td>{current?.features.includes('machines') ? <Link to={`../machines?model=${m.id}`} relative="path">{m.machines}</Link> : m.machines}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  )
}

function OptionsCell({ slug, model, canEdit, onAdded }: { slug: string; model: ProductModelRow; canEdit: boolean; onAdded: () => void }) {
  const [adding, setAdding] = useState(false)

  return (
    <>
      {model.options.length === 0 && !adding && <span className="muted">None</span>}
      {model.options.length > 0 && (
        <ul className="chips chips-small">
          {model.options.map((o) => (
            <li key={o.id}>{o.name}</li>
          ))}
        </ul>
      )}
      {canEdit &&
        (adding ? (
          <AddForm
            path={`/m/${slug}/product-models/${model.id}/options`}
            fields={[{ name: 'name', label: `New option for ${model.name}` }]}
            button="Add"
            inline
            onAdded={() => {
              setAdding(false)
              onAdded()
            }}
            onCancel={() => setAdding(false)}
          />
        ) : (
          <button className="link" onClick={() => setAdding(true)}>
            Add option
          </button>
        ))}
    </>
  )
}

// ---- Dealers ----

export function DealersPage() {
  const slug = useSlug()
  const current = useCurrentManufacturer()
  const { data, error, reload } = useApi<DealerRow[]>(`/m/${slug}/dealers`)
  const [adding, setAdding] = useState(false)
  const canManage = !!current?.features.includes('manage_dealers')
  const [statusError, setStatusError] = useState<string | null>(null)
  const canPrice = !!current?.features.includes('manage_pricing')
  const priceLists = useApi<PriceListSummary[]>(canPrice ? `/m/${slug}/price-lists` : null)

  /** Which price list a dealer buys from ('' = the default). */
  async function setPriceList(d: DealerRow, value: string) {
    setStatusError(null)
    try {
      await api.post(`/m/${slug}/dealers/${d.id}/price-list`, { price_list_id: value ? Number(value) : null })
      reload()
    } catch (e) {
      setStatusError(e instanceof ApiError ? e.message : 'Could not reach DealerDock.')
    }
  }

  /** Suspend: they can't sign in to this brand, and stop counting toward the plan. Reactivating must fit the plan. */
  async function setStatus(d: DealerRow, status: 'active' | 'suspended') {
    if (status === 'suspended' && !window.confirm(`Suspend ${d.name}? Their people can't sign in to ${current?.name} until you reactivate them. Their machines and history stay as they are.`)) return
    setStatusError(null)
    try {
      await api.post(`/m/${slug}/dealers/${d.id}/status`, { status })
      reload()
    } catch (e) {
      setStatusError(e instanceof ApiError ? (e.field('status') ?? e.message) : 'Could not reach DealerDock.')
    }
  }

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

      {statusError && (
        <p className="form-error" role="alert">
          {statusError}
        </p>
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
                {canPrice && priceLists.data && priceLists.data.length > 0 && <th>Price list</th>}
                {current?.features.includes('machines') && <th>Machines</th>}
                {canManage && (
                  <th>
                    <span className="sr-only">Actions</span>
                  </th>
                )}
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
                  {canPrice && priceLists.data && priceLists.data.length > 0 && (
                    <td>
                      <label className="sr-only" htmlFor={`pl-${d.id}`}>
                        Price list for {d.name}
                      </label>
                      <select id={`pl-${d.id}`} className="compact-select" value={d.price_list_id && d.price_list_id !== priceLists.data.find((l) => l.is_default)?.id ? d.price_list_id : ''} onChange={(e) => setPriceList(d, e.target.value)}>
                        {priceLists.data.map((l) => (
                          <option key={l.id} value={l.is_default ? '' : l.id}>
                            {l.name}
                            {l.is_default ? ' (default)' : ''}
                          </option>
                        ))}
                      </select>
                    </td>
                  )}
                  {current?.features.includes('machines') && (
                    <td>
                      <Link to={`../machines?dealer=${d.id}`} relative="path">
                        View
                      </Link>
                    </td>
                  )}
                  {canManage && (
                    <td>
                      <button className="link" onClick={() => setStatus(d, d.status === 'active' ? 'suspended' : 'active')}>
                        {d.status === 'active' ? 'Suspend' : 'Reactivate'}
                      </button>
                    </td>
                  )}
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
              { name: 'role', label: 'Role', options: ROLES.map((r) => ({ value: r, label: r.replace('_', ' ') })) },
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
