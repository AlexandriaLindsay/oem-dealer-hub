import { useState, type FormEvent, type ReactNode } from 'react'
import { ArrowRight, BookOpen, Boxes, ChartPie, ClipboardCheck, FileText, Hourglass, Megaphone, PackageCheck, Store, Tractor, Wrench, type LucideIcon } from 'lucide-react'
import { Link } from 'react-router-dom'
import { api, ApiError, type DealerRow, type PriceListSummary, type ProductModelRow } from '../lib/api'
import { useApi } from '../lib/useApi'
import { useAuth } from '../lib/auth'
import { StatusDonut } from '../components/charts'
import { formatDate, useSlug } from '../lib/tenant'
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
  /** Unsold machines sitting over 180 days. */
  aged_machines: number
  /** Dealers: a stock check waiting for them. */
  stock_check?: { id: number; machines: number; unanswered: number; due_on: string } | null
  /** Dealers: announcements this person hasn't opened. */
  announcements_unread?: number
}

const USAGE_LABELS: Record<string, string> = { dealers: 'Dealers', staff: 'Staff users', storage_gb: 'File storage (GB)' }

const fmt = new Intl.NumberFormat()

/** "Good morning, Sam" - by the person's own clock. */
function Greeting() {
  const { me } = useAuth()
  const hour = new Date().getHours()
  const part = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening'
  const first = me?.user.name.split(/\s+/)[0]
  return <span className="eyebrow">{first ? `${part}, ${first}` : part}</span>
}

/** A headline number. A link when there's a page behind it. */
function Kpi({ label, value, sub, to, icon: Icon }: { label: string; value: ReactNode; sub?: ReactNode; to?: string; icon: LucideIcon }) {
  const body = (
    <>
      <span className="kpi-icon" aria-hidden>
        <Icon size={18} strokeWidth={1.9} />
      </span>
      <span className="tile-label">{label}</span>
      <span className="tile-value">{value}</span>
      {sub && <span className="tile-sub">{sub}</span>}
    </>
  )
  return to ? (
    <Link className="tile kpi-link" to={to}>
      {body}
    </Link>
  ) : (
    <div className="tile">{body}</div>
  )
}

/** Every machine by status, each status linking to the list filtered to it. */
function MachinesCard({ counts, dealer }: { counts: Record<string, number>; dealer: boolean }) {
  return (
    <section className="panel" aria-labelledby="machines-by-status">
      <div className="card-head">
        <div>
          <h2 id="machines-by-status">Machines by status</h2>
          <p>{dealer ? 'Every machine you have from this brand.' : 'Every machine, at the factory and at your dealers.'}</p>
        </div>
        <Link to="machines">
          All machines <ArrowRight size={14} aria-hidden />
        </Link>
      </div>
      <StatusDonut counts={counts} linkTo={(s) => `machines?status=${s}`} />
    </section>
  )
}

/** Shortcuts to the everyday jobs this person can do. */
function QuickActions({ items }: { items: { to: string; label: string; help: string; icon: LucideIcon; show: boolean }[] }) {
  const shown = items.filter((i) => i.show)
  if (shown.length === 0) return null
  return (
    <section className="panel" aria-labelledby="quick-actions">
      <div className="card-head">
        <h2 id="quick-actions">Get things done</h2>
      </div>
      <ul className="quick">
        {shown.map(({ to, label, help, icon: Icon }) => (
          <li key={to}>
            <Link to={to}>
              <Icon size={18} strokeWidth={1.9} aria-hidden />
              <span>
                {label}
                <small>{help}</small>
              </span>
              <ArrowRight size={16} aria-hidden />
            </Link>
          </li>
        ))}
      </ul>
    </section>
  )
}

export function DashboardPage() {
  const slug = useSlug()
  const current = useCurrentManufacturer()
  const { data, error } = useApi<Dashboard>(`/m/${slug}/dashboard`)
  const has = (feature: string) => !!current?.features.includes(feature)

  if (!data) return <Loading error={error} />

  const machineTotal = Object.values(data.machines).reduce((a, b) => a + b, 0)

  if (data.acting_as === 'dealer') {
    return (
      <>
        <div className="page-head">
          <div>
            <Greeting />
            <h1>{current?.name}</h1>
          </div>
        </div>
        <p className="lede">You're signed in as {current?.dealer?.name}, a dealer for {current?.name}.</p>
        {data.stock_check && data.stock_check.unanswered > 0 && (
          <div className="callout stock-check-callout" role="status">
            <strong>Stock check: are these {data.stock_check.machines} machines still on your lot?</strong> {current?.name} asks by {formatDate(data.stock_check.due_on)}. Usually it's one click. <Link to="stock-checks">Check the list</Link>
          </div>
        )}
        <div className="kpis">
          {has('machines') && <Kpi icon={Tractor} label="Machines" value={fmt.format(machineTotal)} sub="in your yard and with customers" to="machines" />}
          {has('machines') && <Kpi icon={Hourglass} label="Sitting 180+ days" value={fmt.format(data.aged_machines)} sub="unsold on your lot" to="machines?view=list&aged=180" />}
          {has('registrations') && <Kpi icon={ClipboardCheck} label="Waiting for approval" value={data.registrations_pending} sub="registrations sent" to="registrations?status=pending" />}
          {has('announcements') && <Kpi icon={Megaphone} label="New announcements" value={data.announcements_unread ?? 0} sub={`from ${current?.name}`} to="announcements" />}
        </div>
        <div className="dash-grid">
          {has('machines') && <MachinesCard counts={data.machines} dealer />}
          <QuickActions
            items={[
              { to: 'documents', label: 'Find a manual', help: 'By the serial number on the plate', icon: BookOpen, show: has('documents') },
              { to: 'parts-orders/new', label: 'Order parts', help: 'By part number, sent to the parts desk', icon: Wrench, show: has('order_parts') },
              { to: 'quotes', label: 'Write a quote', help: 'Priced from your price list', icon: FileText, show: has('build_quotes') },
              { to: 'machines', label: 'Register a sale', help: 'Open the machine, then register it', icon: ClipboardCheck, show: has('register_machines') },
            ]}
          />
        </div>
      </>
    )
  }

  const trialDays = data.plan?.trial_ends_at ? Math.max(0, Math.ceil((Date.parse(data.plan.trial_ends_at) - Date.now()) / 86_400_000)) : null
  const dealers = data.usage?.dealers

  return (
    <>
      <div className="page-head">
        <div>
          <Greeting />
          <h1>{current?.name}</h1>
        </div>
      </div>
      <p className="lede">
        {data.plan?.label} plan{trialDays !== null && data.plan?.key === 'trial' && <> · {trialDays} days left in your trial</>}
      </p>
      <GettingStarted />
      <div className="kpis">
        {has('machines') && <Kpi icon={Tractor} label="Machines" value={fmt.format(machineTotal)} sub="at the factory and at dealers" to="machines" />}
        {dealers && (
          <Kpi icon={Store} label="Active dealers" value={fmt.format(dealers.used)} sub={dealers.limit === null ? 'no limit on your plan' : `of ${dealers.limit} on your plan`} to={has('dealers') ? 'dealers' : undefined} />
        )}
        {has('registrations') && <Kpi icon={ClipboardCheck} label="To review" value={data.registrations_pending} sub="registrations waiting" to="registrations?status=pending" />}
        {has('machines') ? (
          <Kpi icon={Hourglass} label="Sitting 180+ days" value={fmt.format(data.aged_machines)} sub="unsold, at dealers and the factory" to={has('fleet_report') ? 'fleet' : 'machines?view=list&aged=180'} />
        ) : (
          <Kpi icon={Boxes} label="Product lines" value={data.product_lines} sub="in your catalogue" to={has('products') ? 'products' : undefined} />
        )}
      </div>
      <div className="dash-grid">
        {has('machines') && <MachinesCard counts={data.machines} dealer={false} />}
        <div className="stack">
          {data.usage && (
            <section className="panel" aria-labelledby="plan-limits">
              <div className="card-head">
                <div>
                  <h2 id="plan-limits">Your plan</h2>
                  <p>What you're using against the {data.plan?.label} plan's limits.</p>
                </div>
                {has('billing') && (
                  <Link to="billing">
                    Billing <ArrowRight size={14} aria-hidden />
                  </Link>
                )}
              </div>
              <ul className="meters">
                {Object.entries(data.usage).map(([key, u]) => (
                  <li key={key}>
                    <span>{USAGE_LABELS[key] ?? key}</span>
                    <strong>
                      {fmt.format(u.used)} <small>{u.limit === null ? '· unlimited' : `of ${fmt.format(u.limit)}`}</small>
                    </strong>
                    {u.limit !== null && (
                      <span className="meter" aria-hidden>
                        <span style={{ width: `${Math.min(100, (u.used / u.limit) * 100)}%` }} />
                      </span>
                    )}
                  </li>
                ))}
              </ul>
            </section>
          )}
          <QuickActions
            items={[
              { to: 'machines/import', label: 'Import machines', help: 'From a spreadsheet you already have', icon: Tractor, show: has('import_machines') },
              { to: 'dealers', label: 'Invite a dealer', help: 'Dealers are always free', icon: Store, show: has('manage_dealers') },
              { to: 'sales-orders', label: 'Sales orders to build', help: 'Won quotes, from received to delivered', icon: PackageCheck, show: has('manage_sales_orders') },
              { to: 'parts-orders', label: 'Work the parts queue', help: 'Orders waiting for the parts desk', icon: Wrench, show: has('manage_parts_orders') },
              { to: 'fleet', label: 'Fleet report', help: 'By dealer and model, with an Excel export', icon: ChartPie, show: has('fleet_report') },
            ]}
          />
        </div>
      </div>
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
  // Only once some dealer is in a territory - otherwise it's an empty column.
  const hasTerritories = !!data?.some((d) => d.territory)
  // Which lines each dealer carries only matters with more than one line.
  const lines = useApi<{ id: number; name: string }[]>(current?.features.includes('products') ? `/m/${slug}/product-lines` : null).data ?? []
  const showLines = lines.length > 1
  const [editingLines, setEditingLines] = useState<DealerRow | null>(null)
  const lineNames = (d: DealerRow) =>
    d.product_line_ids.length === 0
      ? 'All lines'
      : lines
          .filter((l) => d.product_line_ids.includes(l.id))
          .map((l) => l.name)
          .join(', ')

  /** Which price list a dealer buys from ('' = the default). */
  async function setPriceList(d: DealerRow, value: string) {
    setStatusError(null)
    try {
      await api.post(`/m/${slug}/dealers/${d.id}/price-list`, { price_list_id: value ? Number(value) : null })
      reload()
    } catch (e) {
      setStatusError(e instanceof ApiError ? e.message : 'Could not reach OEM Dealer Hub.')
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
      setStatusError(e instanceof ApiError ? (e.field('status') ?? e.message) : 'Could not reach OEM Dealer Hub.')
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

      {editingLines && (
        <DealerLinesForm
          key={editingLines.id}
          dealer={editingLines}
          lines={lines}
          onDone={(saved) => {
            setEditingLines(null)
            if (saved) reload()
          }}
        />
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
                {hasTerritories && <th>Territory</th>}
                {showLines && <th>Product lines</th>}
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
                  {hasTerritories && <td>{d.territory?.name ?? '—'}</td>}
                  {showLines && (
                    <td>
                      {lineNames(d)}
                      {canManage && (
                        <>
                          {' '}
                          <button className="link" onClick={() => setEditingLines(d)} aria-label={`Change product lines for ${d.name}`}>
                            Change
                          </button>
                        </>
                      )}
                    </td>
                  )}
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

/**
 * Which product lines a dealer carries. It decides what they see on Prices
 * and can put on a quote - not the machines or manuals they already have.
 * "Every line" also covers lines added later.
 */
function DealerLinesForm({ dealer, lines, onDone }: { dealer: DealerRow; lines: { id: number; name: string }[]; onDone: (saved: boolean) => void }) {
  const slug = useSlug()
  const [every, setEvery] = useState(dealer.product_line_ids.length === 0)
  const [chosen, setChosen] = useState<number[]>(dealer.product_line_ids)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  async function save(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    if (!every && chosen.length === 0) {
      setError('Tick at least one line, or choose every line.')
      return
    }
    setBusy(true)
    setError(null)
    try {
      await api.post(`/m/${slug}/dealers/${dealer.id}/product-lines`, { product_line_ids: every ? [] : chosen })
      onDone(true)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Could not reach OEM Dealer Hub.')
      setBusy(false)
    }
  }

  return (
    <section className="panel">
      <h2>Product lines for {dealer.name}</h2>
      <p className="hint">This decides what they see on Prices and can quote. Machines and manuals they already have stay as they are.</p>
      <form onSubmit={save} noValidate>
        <fieldset className="choice-group">
          <legend>{dealer.name} carries</legend>
          <label className="check">
            <input type="radio" name="every" checked={every} onChange={() => setEvery(true)} />
            Every line, including ones you add later
          </label>
          <label className="check">
            <input type="radio" name="every" checked={!every} onChange={() => setEvery(false)} />
            Only these lines:
          </label>
          <div className="choice-sub">
            {lines.map((l) => (
              <label key={l.id} className="check">
                <input
                  type="checkbox"
                  disabled={every}
                  checked={!every && chosen.includes(l.id)}
                  onChange={(e) => setChosen(e.target.checked ? [...chosen, l.id] : chosen.filter((id) => id !== l.id))}
                />
                {l.name}
              </label>
            ))}
          </div>
        </fieldset>
        {error && (
          <p className="form-error" role="alert">
            {error}
          </p>
        )}
        <div className="form-actions">
          <button className="primary" disabled={busy}>
            Save
          </button>
          <button type="button" className="link" onClick={() => onDone(false)}>
            Cancel
          </button>
        </div>
      </form>
    </section>
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
