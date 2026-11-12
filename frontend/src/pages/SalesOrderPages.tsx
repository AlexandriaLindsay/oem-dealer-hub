import { useEffect, useRef, useState, type FormEvent } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { CalendarDays, FileText } from 'lucide-react'
import { api, ApiError, type QuoteCustomer, type SalesOrderDetail, type SalesOrderPage, type SalesOrderRow, type SalesOrderStatus } from '../lib/api'
import { useApi } from '../lib/useApi'
import { formatDate, formatDateTime, useSlug } from '../lib/tenant'
import { money } from '../lib/money'
import { useCurrentManufacturer } from '../components/Layout'
import { Loading } from '../components/AddForm'
import { FormError } from '../components/Form'
import { Board, ViewSwitch, type BoardColumn, type BoardTone } from '../components/Board'

/*
 * Sales orders: what a won quote becomes. Manufacturer staff only. Everyone
 * with the page reads them; those who work orders (manage_sales_orders) move
 * them along - into production, shipped, delivered - or cancel one with a reason.
 */

function asApiError(e: unknown): ApiError {
  return e instanceof ApiError ? e : new ApiError(0, { message: 'Could not reach OEM Dealer Hub.' })
}

const STATUS_LABEL: Record<SalesOrderStatus, string> = {
  received: 'Received',
  in_production: 'In production',
  shipped: 'Shipped',
  delivered: 'Delivered',
  cancelled: 'Cancelled',
}

/** What the button for each move says. */
const MOVE_LABEL: Record<SalesOrderStatus, string> = {
  received: '',
  in_production: 'Start building',
  shipped: 'Ship',
  delivered: 'Delivered',
  cancelled: 'Cancel…',
}

/** Where an order can go from each status (SalesOrder::MOVES on the server). */
const MOVES: Record<SalesOrderStatus, SalesOrderStatus[]> = {
  received: ['in_production', 'shipped', 'cancelled'],
  in_production: ['shipped', 'cancelled'],
  shipped: ['delivered'],
  delivered: [],
  cancelled: [],
}

const COLUMNS: { key: SalesOrderStatus; tone: BoardTone }[] = [
  { key: 'received', tone: 'accent' },
  { key: 'in_production', tone: 'neutral' },
  { key: 'shipped', tone: 'neutral' },
  { key: 'delivered', tone: 'ok' },
  { key: 'cancelled', tone: 'danger' },
]

export function SalesOrderBadge({ status }: { status: SalesOrderStatus }) {
  return <span className={`badge badge-so-${status}`}>{STATUS_LABEL[status]}</span>
}

const customerName = (c: QuoteCustomer) => (c.company ? `${c.company} (${c.contact_name})` : c.contact_name)

// ---- The board and the list ----

export function SalesOrdersPage() {
  const slug = useSlug()
  const current = useCurrentManufacturer()
  const canManage = !!current?.features.includes('manage_sales_orders')
  const [params, setParams] = useSearchParams()
  const view = params.get('view') === 'list' ? 'list' : 'board'
  const status = params.get('status') ?? 'open'
  const q = params.get('q') ?? ''
  const query = new URLSearchParams()
  if (status) query.set('status', status)
  if (q) query.set('q', q)
  const { data, error } = useApi<SalesOrderPage>(view === 'list' ? `/m/${slug}/sales-orders?${query}` : null)

  const tabs: [string, string][] = [
    ['open', 'To build'],
    ['shipped', 'Shipped'],
    ['delivered', 'Delivered'],
    ['cancelled', 'Cancelled'],
    ['', 'All'],
  ]

  function setView(v: 'list' | 'board') {
    const next = new URLSearchParams()
    if (v === 'list') next.set('view', 'list')
    if (q) next.set('q', q)
    setParams(next, { replace: true })
  }

  return (
    <>
      <div className="page-head">
        <h1>Sales orders</h1>
      </div>
      <p className="lede">
        Every quote you mark won becomes a sales order here, with the dealer's lines and prices.
        {canManage ? ' Move each one along as it’s built, shipped and delivered.' : ''}
      </p>

      <div className="view-bar">
        <ViewSwitch view={view} onChange={setView} />
        {view === 'list' && (
          <div className="tabs" role="tablist" aria-label="Order status">
            {tabs.map(([key, label]) => (
              <button key={key || 'all'} role="tab" aria-selected={status === key} className={status === key ? 'is-on' : ''} onClick={() => setParams({ view: 'list', status: key }, { replace: true })}>
                {label}
              </button>
            ))}
          </div>
        )}
        <form
          className="filter-search"
          role="search"
          onSubmit={(e) => {
            e.preventDefault()
            const next = new URLSearchParams(params)
            const value = String(new FormData(e.currentTarget).get('q') ?? '').trim()
            if (value) next.set('q', value)
            else next.delete('q')
            setParams(next, { replace: true })
          }}
        >
          <label htmlFor="so-search" className="sr-only">
            Search order number, customer or PO
          </label>
          <input id="so-search" name="q" type="search" placeholder="Order number, customer or PO" defaultValue={q} key={q} />
          <button className="secondary">Search</button>
        </form>
      </div>

      {view === 'board' ? (
        <SalesOrderBoard slug={slug} q={q} canManage={canManage} />
      ) : !data ? (
        <Loading error={error} />
      ) : data.total === 0 ? (
        <p className="muted">{q ? 'No orders match.' : status === 'open' ? 'Nothing to build right now.' : 'No sales orders yet. Mark a sent quote won and it shows up here.'}</p>
      ) : (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Order</th>
                <th>Customer</th>
                <th>Dealer</th>
                <th className="num">Total</th>
                <th>PO</th>
                <th>Wanted by</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              {data.data.map((o) => (
                <tr key={o.id}>
                  <td>
                    <Link to={String(o.id)}>{o.number}</Link>
                  </td>
                  <td>{customerName(o.customer)}</td>
                  <td>{o.dealer}</td>
                  <td className="num">{money(o.total, o.currency)}</td>
                  <td>{o.po_number ?? '—'}</td>
                  <td>{o.requested_date ? formatDate(o.requested_date) : '—'}</td>
                  <td>
                    <SalesOrderBadge status={o.status} />
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

/**
 * A card per order in its status's column. Those who work orders move cards
 * along (drag, or the buttons); cancelling asks for a reason first.
 */
function SalesOrderBoard({ slug, q, canManage }: { slug: string; q: string; canManage: boolean }) {
  const path = (status: string) => `/m/${slug}/sales-orders?${new URLSearchParams(q ? { status, q } : { status })}`
  const cols: Record<SalesOrderStatus, ReturnType<typeof useApi<SalesOrderPage>>> = {
    received: useApi<SalesOrderPage>(path('received')),
    in_production: useApi<SalesOrderPage>(path('in_production')),
    shipped: useApi<SalesOrderPage>(path('shipped')),
    delivered: useApi<SalesOrderPage>(path('delivered')),
    cancelled: useApi<SalesOrderPage>(path('cancelled')),
  }
  const [cancelling, setCancelling] = useState<SalesOrderRow | null>(null)
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)

  // Their ERP moves orders: cards show where each one is, without move buttons.
  const fromErp = Object.values(cols).some((c) => c.data?.status_from_erp)
  const movesFor = (o: SalesOrderRow): string[] => (canManage && !fromErp ? MOVES[o.status] : [])

  async function save(o: SalesOrderRow, to: SalesOrderStatus, extra: Record<string, string> = {}) {
    setBusy(true)
    setError(null)
    try {
      await api.post(`/m/${slug}/sales-orders/${o.id}/status`, { status: to, ...extra })
      setCancelling(null)
      await Promise.all([cols[o.status].reload(), cols[to].reload()])
    } catch (e) {
      setError(asApiError(e))
    } finally {
      setBusy(false)
    }
  }

  function move(o: SalesOrderRow, to: string) {
    setError(null)
    if (to === 'cancelled') setCancelling(o)
    else save(o, to as SalesOrderStatus)
  }

  const columns: BoardColumn<SalesOrderRow>[] = COLUMNS.map(({ key, tone }) => {
    const c = cols[key]
    return {
      key,
      title: STATUS_LABEL[key],
      tone,
      items: c.data?.data ?? null,
      total: c.data?.total ?? 0,
      error: c.error,
      footer: c.data && c.data.total > c.data.data.length ? `Showing ${c.data.data.length} of ${c.data.total}. The list view has them all.` : undefined,
    }
  })

  return (
    <>
      {error && !cancelling && (
        <p className="form-error board-error" role="alert">
          {error.field('status') ?? error.message}
        </p>
      )}
      {fromErp && <ErpNote />}
      <Board
        label="Sales orders by status"
        columns={columns}
        cardKey={(o) => o.id}
        movesFor={movesFor}
        onMove={move}
        empty={q ? 'None match.' : 'None.'}
        renderCard={(o) => (
          <>
            <div className="card-top">
              <Link to={String(o.id)}>{o.number}</Link>
              {o.quote && <span className="muted small">from {o.quote}</span>}
            </div>
            <span className="card-title">{customerName(o.customer)}</span>
            <div className="card-meta">
              <span className="card-amount">{money(o.total, o.currency)}</span>
              <span>{o.dealer}</span>
              {o.po_number && <span>PO {o.po_number}</span>}
              {o.requested_date && o.status !== 'delivered' && o.status !== 'cancelled' && (
                <span>
                  <CalendarDays size={14} aria-hidden /> by {formatDate(o.requested_date)}
                </span>
              )}
            </div>
            {movesFor(o).length > 0 && (
              <div className="card-actions">
                {movesFor(o).map((to) => (
                  <button key={to} className={to === 'cancelled' ? 'is-danger' : undefined} disabled={busy} onClick={() => move(o, to)}>
                    {MOVE_LABEL[to as SalesOrderStatus]}
                    <span className="sr-only"> order {o.number}</span>
                  </button>
                ))}
              </div>
            )}
          </>
        )}
      />
      {cancelling && (
        <CancelDialog
          number={cancelling.number}
          busy={busy}
          error={error}
          onBack={() => {
            setCancelling(null)
            setError(null)
          }}
          onCancel={(reason) => save(cancelling, 'cancelled', { cancel_reason: reason })}
        />
      )}
    </>
  )
}

function ErpNote() {
  const current = useCurrentManufacturer()
  const slug = useSlug()
  return (
    <div className="callout" role="status">
      <strong>Order status comes from your ERP.</strong> Orders move here as your ERP updates them.
      {current?.features.includes('erp_integration') && (
        <>
          {' '}
          To move them here instead, turn that off under <Link to={`/m/${slug}/integrations`}>Integrations</Link>.
        </>
      )}
    </div>
  )
}

/** Cancelling asks why, so whoever reads the order later knows. */
function CancelDialog({ number, busy, error, onBack, onCancel }: { number: string; busy: boolean; error: ApiError | null; onBack: () => void; onCancel: (reason: string) => void }) {
  const ref = useRef<HTMLDialogElement>(null)
  useEffect(() => {
    const d = ref.current
    if (d && !d.open) d.showModal()
  }, [])

  return (
    <dialog
      ref={ref}
      className="move-dialog"
      aria-labelledby="cancel-title"
      onCancel={(e) => {
        e.preventDefault()
        onBack()
      }}
    >
      <form
        noValidate
        onSubmit={(e) => {
          e.preventDefault()
          onCancel(String(new FormData(e.currentTarget).get('cancel_reason') ?? '').trim())
        }}
      >
        <h2 id="cancel-title">Cancel order {number}</h2>
        <p className="muted small">The order stays on record, marked cancelled, with your reason.</p>
        {error?.field('status') && <p className="form-error">{error.field('status')}</p>}
        <div className="field">
          <label htmlFor="cancel-reason">Why is it cancelled?</label>
          <textarea id="cancel-reason" name="cancel_reason" rows={3} maxLength={2000} aria-invalid={!!error?.field('cancel_reason')} placeholder="e.g. Customer's financing fell through." />
          {error?.field('cancel_reason') && <p className="field-error">{error.field('cancel_reason')}</p>}
        </div>
        <div className="form-actions">
          <button className="danger" disabled={busy}>
            {busy ? 'Saving…' : 'Cancel the order'}
          </button>
          <button type="button" className="link" onClick={onBack}>
            Back
          </button>
        </div>
      </form>
    </dialog>
  )
}

// ---- One order ----

export function SalesOrderPage() {
  const slug = useSlug()
  const { id } = useParams()
  const { data, error, reload } = useApi<SalesOrderDetail>(`/m/${slug}/sales-orders/${id}`)
  const [busy, setBusy] = useState(false)
  const [actionError, setActionError] = useState<ApiError | null>(null)
  const [cancelling, setCancelling] = useState(false)

  async function move(to: SalesOrderStatus, extra: Record<string, string> = {}) {
    setBusy(true)
    setActionError(null)
    try {
      await api.post(`/m/${slug}/sales-orders/${id}/status`, { status: to, ...extra })
      setCancelling(false)
      reload()
    } catch (e) {
      setActionError(asApiError(e))
    } finally {
      setBusy(false)
    }
  }

  if (!data) {
    return (
      <>
        <Link to=".." relative="path" className="back">
          ← Sales orders
        </Link>
        {error?.status === 404 ? <p className="muted">That order isn't here.</p> : <Loading error={error} />}
      </>
    )
  }

  const statusError = actionError?.field('status') ?? (actionError && !Object.keys(actionError.errors).length ? actionError.message : null)

  return (
    <>
      <Link to=".." relative="path" className="back">
        ← Sales orders
      </Link>
      <div className="page-head">
        <h1>
          {data.number} <SalesOrderBadge status={data.status} />
        </h1>
        {data.next.length > 0 && (
          <span className="head-actions">
            {data.next.includes('cancelled') && (
              <button className="secondary" disabled={busy} onClick={() => setCancelling(true)}>
                Cancel order…
              </button>
            )}
            {data.next
              .filter((s) => s !== 'cancelled')
              .map((s, i, all) => (
                <button key={s} className={i === all.length - 1 ? 'primary' : 'secondary'} disabled={busy} onClick={() => move(s)}>
                  {s === 'in_production' ? 'Start building' : s === 'shipped' ? 'Mark shipped' : 'Mark delivered'}
                </button>
              ))}
          </span>
        )}
      </div>
      <p className="lede">
        {customerName(data.customer)} · through {data.dealer} · {data.currency === 'CAD' ? 'Canadian dollars' : 'US dollars'}
        {data.quote && (
          <>
            {' · from quote '}
            <Link to={`../../quotes/${data.quote_id}`} relative="path">
              {data.quote}
            </Link>
          </>
        )}
      </p>
      {statusError && (
        <p className="form-error" role="alert">
          {statusError}
        </p>
      )}

      {data.status_from_erp && !['delivered', 'cancelled'].includes(data.status) && <ErpNote />}
      {data.status === 'cancelled' && (
        <div className="callout callout-danger">
          <strong>Cancelled {formatDate(data.cancelled_at)}.</strong>
          {data.cancel_reason && <p className="prewrap">{data.cancel_reason}</p>}
        </div>
      )}

      <dl className="facts">
        <div>
          <dt>Customer PO</dt>
          <dd>{data.po_number ?? '—'}</dd>
        </div>
        <div>
          <dt>ERP order</dt>
          <dd>{data.erp_ref ?? '—'}</dd>
        </div>
        <div>
          <dt>Wanted by</dt>
          <dd>{data.requested_date ? formatDate(data.requested_date) : '—'}</dd>
        </div>
        <div>
          <dt>Won</dt>
          <dd>
            {formatDate(data.created_at)}
            {data.created_by && <span className="muted"> · {data.created_by}</span>}
          </dd>
        </div>
        <div>
          <dt>Into production</dt>
          <dd>{data.started_at ? formatDate(data.started_at) : '—'}</dd>
        </div>
        <div>
          <dt>Shipped</dt>
          <dd>{data.shipped_at ? formatDate(data.shipped_at) : '—'}</dd>
        </div>
        <div>
          <dt>Delivered</dt>
          <dd>{data.delivered_at ? formatDate(data.delivered_at) : '—'}</dd>
        </div>
      </dl>

      {data.can_edit ? <DetailsForm slug={slug} order={data} onSaved={reload} /> : data.notes && <NotesPanel notes={data.notes} />}

      <section className="panel">
        <h2>
          <FileText size={16} aria-hidden style={{ verticalAlign: '-2px', marginRight: '0.4rem' }} />
          What was ordered
        </h2>
        <div className="table-wrap flat">
          <table className="quote-lines">
            <thead>
              <tr>
                <th>Item</th>
                <th className="num">Qty</th>
                <th className="num">Unit price</th>
                <th className="num">Total</th>
              </tr>
            </thead>
            <tbody>
              {data.lines.map((l, i) => (
                <tr key={i} className={l.kind === 'option' ? 'quote-option' : ''}>
                  <td>{l.description}</td>
                  <td className="num">{l.quantity}</td>
                  <td className="num">{money(l.unit_price, data.currency)}</td>
                  <td className="num">{money(l.line_total, data.currency)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <dl className="quote-totals">
          <div>
            <dt>Subtotal</dt>
            <dd>{money(data.subtotal, data.currency)}</dd>
          </div>
          {Number(data.discount) > 0 && (
            <div>
              <dt>Discount</dt>
              <dd>−{money(data.discount, data.currency)}</dd>
            </div>
          )}
          <div className="quote-total">
            <dt>Total</dt>
            <dd>{money(data.total, data.currency)}</dd>
          </div>
        </dl>
      </section>

      {data.handled_by && (
        <p className="muted small">
          Last moved by {data.handled_by}
          {data.status === 'delivered' && data.delivered_at && ` · ${formatDateTime(data.delivered_at)}`}.
        </p>
      )}

      {cancelling && (
        <CancelDialog
          number={data.number}
          busy={busy}
          error={actionError}
          onBack={() => {
            setCancelling(false)
            setActionError(null)
          }}
          onCancel={(reason) => move('cancelled', { cancel_reason: reason })}
        />
      )}
    </>
  )
}

function NotesPanel({ notes }: { notes: string }) {
  return (
    <section className="panel">
      <h2>Notes</h2>
      <p className="prewrap">{notes}</p>
    </section>
  )
}

/** The details a quote doesn't have: the customer's PO, when they want it, notes for the team. */
function DetailsForm({ slug, order, onSaved }: { slug: string; order: SalesOrderDetail; onSaved: () => void }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const [saved, setSaved] = useState(false)

  async function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const f = new FormData(e.currentTarget)
    const v = (k: string) => String(f.get(k) ?? '').trim() || null
    setBusy(true)
    setError(null)
    setSaved(false)
    try {
      await api.post(`/m/${slug}/sales-orders/${order.id}`, { po_number: v('po_number'), requested_date: v('requested_date'), notes: v('notes'), erp_ref: v('erp_ref') })
      setSaved(true)
      onSaved()
    } catch (err) {
      setError(asApiError(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <section className="panel">
      <h2>Order details</h2>
      <form className="grid-form" onSubmit={onSubmit} noValidate>
        <FormError error={error} />
        <div className="field">
          <label htmlFor="so-po">Customer's PO number</label>
          <input id="so-po" name="po_number" maxLength={60} defaultValue={order.po_number ?? ''} aria-invalid={!!error?.field('po_number')} />
          {error?.field('po_number') && <p className="field-error">{error.field('po_number')}</p>}
        </div>
        <div className="field">
          <label htmlFor="so-date">Wanted by</label>
          <input id="so-date" name="requested_date" type="date" defaultValue={order.requested_date ?? ''} aria-invalid={!!error?.field('requested_date')} />
          {error?.field('requested_date') && <p className="field-error">{error.field('requested_date')}</p>}
        </div>
        <div className="field">
          <label htmlFor="so-erp">ERP order number</label>
          <input id="so-erp" name="erp_ref" maxLength={100} defaultValue={order.erp_ref ?? ''} aria-invalid={!!error?.field('erp_ref')} />
          <p className="hint">Once it's entered in your ERP. Sent to your systems with the order.</p>
          {error?.field('erp_ref') && <p className="field-error">{error.field('erp_ref')}</p>}
        </div>
        <div className="field span-all">
          <label htmlFor="so-notes">Notes for your team</label>
          <textarea id="so-notes" name="notes" rows={2} maxLength={2000} defaultValue={order.notes ?? ''} placeholder="e.g. Deliver to the north yard; call ahead." />
          {error?.field('notes') && <p className="field-error">{error.field('notes')}</p>}
        </div>
        <div className="form-actions">
          <button className="primary" disabled={busy}>
            {busy ? 'Saving…' : 'Save details'}
          </button>
          {saved && (
            <span className="notice-inline" role="status">
              Saved
            </span>
          )}
        </div>
      </form>
    </section>
  )
}
