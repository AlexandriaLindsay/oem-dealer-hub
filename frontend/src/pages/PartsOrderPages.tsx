import { useEffect, useRef, useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { Package, Truck } from 'lucide-react'
import { api, ApiError, type PartsOrderDetail, type PartsOrderPage, type PartsOrderRow, type PartsOrderStatus } from '../lib/api'
import { Board, ViewSwitch, type BoardColumn, type BoardTone } from '../components/Board'
import { useApi } from '../lib/useApi'
import { formatDate, formatDateTime, useSlug } from '../lib/tenant'
import { useCurrentManufacturer } from '../components/Layout'
import { Loading } from '../components/AddForm'
import { Field, FormError } from '../components/Form'

function asApiError(e: unknown): ApiError {
  return e instanceof ApiError ? e : new ApiError(0, { message: 'Could not reach OEM Dealer Hub.' })
}

const STATUS_LABEL: Record<PartsOrderStatus, string> = { submitted: 'Submitted', in_progress: 'Being prepared', shipped: 'Shipped', cancelled: 'Cancelled' }
const SHIP_LABEL = { standard: 'Standard', rush: 'Rush', pickup: 'Pickup' } as const

export function PartsBadge({ status }: { status: PartsOrderStatus }) {
  return <span className={`badge badge-parts-${status}`}>{STATUS_LABEL[status]}</span>
}

// ---- The list / the parts desk's queue ----

export function PartsOrdersPage() {
  const slug = useSlug()
  const current = useCurrentManufacturer()
  const isDealer = current?.acting_as === 'dealer'
  const canOrder = !!current?.features.includes('order_parts')
  const isDesk = !!current?.features.includes('manage_parts_orders')
  const [params, setParams] = useSearchParams()
  // The board by default: a column per status. The list (with tabs) is a click away.
  const view = params.get('view') === 'list' ? 'list' : 'board'
  // The parts desk starts on its to-do list; dealers on everything they've ordered.
  const status = params.get('status') ?? (isDesk ? 'open' : '')
  const q = params.get('q') ?? ''
  const query = new URLSearchParams()
  if (status) query.set('status', status)
  if (q) query.set('q', q)
  const { data, error } = useApi<PartsOrderPage>(view === 'list' ? `/m/${slug}/parts-orders${query.size ? `?${query}` : ''}` : null)

  function setView(v: 'list' | 'board') {
    const next = new URLSearchParams()
    if (v === 'list') next.set('view', 'list')
    if (q) next.set('q', q)
    setParams(next, { replace: true })
  }

  const tabs: [string, string][] = [
    ['open', isDesk ? 'To do' : 'Open'],
    ['shipped', 'Shipped'],
    ['cancelled', 'Cancelled'],
    ['', 'All'],
  ]

  return (
    <>
      <div className="page-head">
        <h1>Parts orders</h1>
        {canOrder && (
          <Link to="new" className="button-primary">
            Order parts
          </Link>
        )}
      </div>
      <p className="lede">
        {isDealer
          ? `Parts orders to ${current?.name}'s parts desk. They're requests by part number; ${current?.name} invoices them as usual.`
          : isDesk
            ? 'Orders from your dealers, oldest first. Start each one, then mark it shipped with the tracking number.'
            : 'Parts orders from your dealers.'}
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
          <label htmlFor="parts-search" className="sr-only">
            Search part number, PO, serial or order number
          </label>
          <input id="parts-search" name="q" type="search" placeholder="Part number, PO, serial or order number" defaultValue={q} key={q} />
          <button className="secondary">Search</button>
        </form>
      </div>

      {view === 'board' ? (
        <PartsBoard slug={slug} q={q} isDesk={isDesk} canOrder={canOrder} isDealer={isDealer} />
      ) : !data ? (
        <Loading error={error} />
      ) : data.total === 0 ? (
        <p className="muted">{q ? 'No orders match.' : status === 'open' ? 'Nothing waiting.' : 'No parts orders yet.'}</p>
      ) : (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Order</th>
                {!isDealer && <th>Dealer</th>}
                <th>PO</th>
                <th>Parts</th>
                <th>Shipping</th>
                <th>Sent</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              {data.data.map((o) => (
                <tr key={o.id}>
                  <td>
                    <Link to={String(o.id)}>{o.number}</Link>
                  </td>
                  {!isDealer && <td>{o.dealer}</td>}
                  <td>{o.po_number ?? '—'}</td>
                  <td>
                    {o.lines_count} line{o.lines_count === 1 ? '' : 's'}
                  </td>
                  <td>{o.ship_method === 'rush' ? <strong className="rush">Rush</strong> : SHIP_LABEL[o.ship_method]}</td>
                  <td>{formatDate(o.created_at)}</td>
                  <td>
                    <PartsBadge status={o.status} />
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

// ---- The board ----

/** Where the parts desk can move an order from each status (PartsOrder::MOVES on the server). */
const DESK_MOVES: Record<PartsOrderStatus, PartsOrderStatus[]> = {
  submitted: ['in_progress', 'shipped', 'cancelled'],
  in_progress: ['shipped', 'cancelled'],
  shipped: [],
  cancelled: [],
}
const COLUMNS: { key: PartsOrderStatus; title: string; tone: BoardTone }[] = [
  { key: 'submitted', title: 'Submitted', tone: 'accent' },
  { key: 'in_progress', title: 'Being prepared', tone: 'neutral' },
  { key: 'shipped', title: 'Shipped', tone: 'ok' },
  { key: 'cancelled', title: 'Cancelled', tone: 'danger' },
]
const MOVE_LABEL: Record<PartsOrderStatus, string> = { submitted: '', in_progress: 'Start', shipped: 'Ship…', cancelled: 'Cancel…' }

/**
 * Every order as a card in its status's column. The parts desk moves cards
 * along (drag, or the buttons); shipping asks for the carrier and tracking
 * number, cancelling for a reason the dealer sees. A dealer can take back an
 * order the desk hasn't started.
 */
function PartsBoard({ slug, q, isDesk, canOrder, isDealer }: { slug: string; q: string; isDesk: boolean; canOrder: boolean; isDealer: boolean }) {
  const path = (status: string) => `/m/${slug}/parts-orders?${new URLSearchParams(q ? { status, q } : { status })}`
  const cols = {
    submitted: useApi<PartsOrderPage>(path('submitted')),
    in_progress: useApi<PartsOrderPage>(path('in_progress')),
    shipped: useApi<PartsOrderPage>(path('shipped')),
    cancelled: useApi<PartsOrderPage>(path('cancelled')),
  }
  const [moving, setMoving] = useState<{ order: PartsOrderRow; to: 'shipped' | 'cancelled' } | null>(null)
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)

  const reloadAll = () => Promise.all(Object.values(cols).map((c) => c.reload()))

  const movesFor = (o: PartsOrderRow): string[] => {
    if (isDesk) return DESK_MOVES[o.status]
    if (canOrder && o.status === 'submitted') return ['cancelled']
    return []
  }

  async function move(o: PartsOrderRow, to: string) {
    setError(null)
    if (!isDesk) {
      // The dealer taking an order back.
      if (!window.confirm(`Cancel parts order ${o.number}?`)) return
      return save(() => api.post(`/m/${slug}/parts-orders/${o.id}/cancel`))
    }
    if (to === 'shipped' || to === 'cancelled') return setMoving({ order: o, to })
    return save(() => api.post(`/m/${slug}/parts-orders/${o.id}/status`, { status: to }))
  }

  async function save(call: () => Promise<unknown>) {
    setBusy(true)
    try {
      await call()
      setMoving(null)
      await reloadAll()
    } catch (e) {
      setError(asApiError(e))
    } finally {
      setBusy(false)
    }
  }

  const columns: BoardColumn<PartsOrderRow>[] = COLUMNS.map(({ key, title, tone }) => {
    const c = cols[key]
    // Waiting work oldest first, so the longest-waiting order is on top; finished work newest first.
    const items = c.data ? (key === 'submitted' || key === 'in_progress' ? [...c.data.data].sort((a, b) => a.id - b.id) : c.data.data) : null
    return {
      key,
      title,
      tone,
      items,
      total: c.data?.total ?? 0,
      error: c.error,
      footer: c.data && c.data.total > c.data.data.length ? `Showing ${c.data.data.length} of ${c.data.total}. The list view has them all.` : undefined,
    }
  })

  return (
    <>
      {error && !moving && (
        <p className="form-error board-error" role="alert">
          {error.field('status') ?? error.message}
        </p>
      )}
      <Board
        label="Parts orders by status"
        columns={columns}
        cardKey={(o) => o.id}
        movesFor={movesFor}
        onMove={move}
        empty={q ? 'None match.' : 'None.'}
        renderCard={(o) => (
          <>
            <div className="card-top">
              <Link to={String(o.id)}>{o.number}</Link>
              {o.ship_method === 'rush' && <span className="card-flag">Rush</span>}
            </div>
            {!isDealer && <span className="card-title">{o.dealer}</span>}
            <div className="card-meta">
              <span>
                <Package size={14} aria-hidden /> {o.lines_count} line{o.lines_count === 1 ? '' : 's'}
              </span>
              {o.po_number && <span>PO {o.po_number}</span>}
              {/* Rush already has its flag at the top. */}
              {o.ship_method !== 'rush' && (
                <span>
                  <Truck size={14} aria-hidden /> {SHIP_LABEL[o.ship_method]}
                </span>
              )}
              <span>{formatDate(o.status === 'shipped' ? o.shipped_at : o.created_at)}</span>
            </div>
            {movesFor(o).length > 0 && (
              <div className="card-actions">
                {movesFor(o).map((to) => (
                  <button key={to} className={to === 'cancelled' ? 'is-danger' : undefined} disabled={busy} onClick={() => move(o, to)}>
                    {isDesk ? MOVE_LABEL[to as PartsOrderStatus] : 'Cancel'}
                    <span className="sr-only"> order {o.number}</span>
                  </button>
                ))}
              </div>
            )}
          </>
        )}
      />
      {moving && <MoveDialog order={moving.order} to={moving.to} busy={busy} error={error} onCancel={() => {
            setMoving(null)
            setError(null)
          }} onSave={(body) => save(() => api.post(`/m/${slug}/parts-orders/${moving.order.id}/status`, { status: moving.to, ...body }))} />}
    </>
  )
}

/** The details shipping or cancelling needs, asked for in a small dialog over the board. */
function MoveDialog({
  order,
  to,
  busy,
  error,
  onCancel,
  onSave,
}: {
  order: PartsOrderRow
  to: 'shipped' | 'cancelled'
  busy: boolean
  error: ApiError | null
  onCancel: () => void
  onSave: (body: Record<string, string | null>) => void
}) {
  const ref = useRef<HTMLDialogElement>(null)
  useEffect(() => {
    const d = ref.current
    if (d && !d.open) d.showModal()
  }, [])

  return (
    <dialog ref={ref} className="move-dialog" aria-labelledby="move-title" onCancel={(e) => {
        e.preventDefault()
        onCancel()
      }}>
      <form
        noValidate
        onSubmit={(e) => {
          e.preventDefault()
          const f = new FormData(e.currentTarget)
          const v = (k: string) => String(f.get(k) ?? '').trim() || null
          onSave({ carrier: v('carrier'), tracking_number: v('tracking_number'), parts_note: v('parts_note') })
        }}
      >
        <h2 id="move-title">
          {to === 'shipped' ? 'Ship' : 'Cancel'} order {order.number}
        </h2>
        <p className="muted small">{order.dealer} gets an email with this.</p>
        {error && !Object.keys(error.errors).length && <p className="form-error">{error.message}</p>}
        {error?.field('status') && <p className="form-error">{error.field('status')}</p>}
        {to === 'shipped' && (
          <>
            <Field label="Carrier" name="carrier" error={error} />
            <Field label="Tracking number" name="tracking_number" error={error} />
          </>
        )}
        <div className="field">
          <label htmlFor="move-note">{to === 'cancelled' ? 'Why? (the dealer sees this)' : 'Note for the dealer (optional)'}</label>
          <textarea id="move-note" name="parts_note" rows={2} maxLength={2000} aria-invalid={!!error?.field('parts_note')} />
          {error?.field('parts_note') && <p className="field-error">{error.field('parts_note')}</p>}
        </div>
        <div className="form-actions">
          <button className={to === 'cancelled' ? 'danger' : 'primary'} disabled={busy}>
            {busy ? 'Saving…' : to === 'cancelled' ? 'Cancel the order' : 'Mark shipped'}
          </button>
          <button type="button" className="link" onClick={onCancel}>
            Back
          </button>
        </div>
      </form>
    </dialog>
  )
}

// ---- Ordering ----

interface Line {
  key: number
  part_number: string
  description: string
  quantity: string
}

let lineKey = 0
const blankLine = (): Line => ({ key: lineKey++, part_number: '', description: '', quantity: '1' })

/**
 * Lines pasted from a spreadsheet or email: one part per line, as
 * "part, quantity", "part<TAB>quantity", or "part<TAB>description<TAB>quantity".
 * Anything without a clear part number is skipped.
 */
function parsePasted(text: string): Line[] {
  return text
    .split(/\r?\n/)
    .map((row) => row.split(/\t|,|;/).map((c) => c.trim()))
    .filter((cells) => cells[0] && !/^part/i.test(cells[0]))
    .map((cells) => {
      // The quantity comes after the part number (which may itself be all digits).
      const qty = cells.slice(1).reverse().find((c) => /^\d{1,4}$/.test(c)) ?? '1'
      const description = cells.slice(1).filter((c) => c !== qty).join(' ')
      return { key: lineKey++, part_number: cells[0], description, quantity: qty }
    })
}

export function NewPartsOrderPage() {
  const slug = useSlug()
  const navigate = useNavigate()
  const defaults = useApi<{ ship_to: Record<string, string> }>(`/m/${slug}/parts-orders/defaults`).data
  const [lines, setLines] = useState<Line[]>([blankLine()])
  const [method, setMethod] = useState<'standard' | 'rush' | 'pickup'>('standard')
  const [shipTo, setShipTo] = useState<Record<string, string>>({})
  const [paste, setPaste] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)

  // Ship to the dealer unless they say otherwise.
  useEffect(() => {
    if (defaults) setShipTo((s) => ({ ...defaults.ship_to, ...s }))
  }, [defaults])

  const setLine = (key: number, patch: Partial<Line>) => setLines((all) => all.map((l) => (l.key === key ? { ...l, ...patch } : l)))

  async function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const f = new FormData(e.currentTarget)
    const filled = lines.filter((l) => l.part_number.trim())
    setBusy(true)
    setError(null)
    try {
      const order = await api.post<PartsOrderDetail>(`/m/${slug}/parts-orders`, {
        po_number: String(f.get('po_number') ?? '').trim() || null,
        machine_serial: String(f.get('machine_serial') ?? '').trim() || null,
        ship_method: method,
        ship_to: method === 'pickup' ? null : shipTo,
        notes: String(f.get('notes') ?? '').trim() || null,
        lines: filled.map((l) => ({ part_number: l.part_number.trim(), description: l.description.trim() || null, quantity: Number(l.quantity) || 0 })),
      })
      navigate(`../${order.id}`, { relative: 'path' })
    } catch (err) {
      setError(asApiError(err))
      setBusy(false)
    }
  }

  const lineError = (i: number) => error?.field(`lines.${i}.part_number`) ?? error?.field(`lines.${i}.quantity`)
  const filledIndex = (key: number) => lines.filter((l) => l.part_number.trim()).findIndex((l) => l.key === key)

  return (
    <>
      <Link to=".." relative="path" className="back">
        ← Parts orders
      </Link>
      <h1>Order parts</h1>
      <p className="lede">By part number, from the parts books under Manuals. The parts desk confirms prices and availability.</p>

      <form onSubmit={onSubmit} noValidate>
        <section className="panel">
          <h2>Parts</h2>
          <div className="table-wrap flat">
            <table className="parts-lines">
              <thead>
                <tr>
                  <th>Part number</th>
                  <th>Description (optional)</th>
                  <th className="num">Qty</th>
                  <th>
                    <span className="sr-only">Remove</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                {lines.map((l, i) => (
                  <tr key={l.key}>
                    <td>
                      <input aria-label={`Part number, line ${i + 1}`} className="line-input part" value={l.part_number} onChange={(e) => setLine(l.key, { part_number: e.target.value })} />
                      {filledIndex(l.key) >= 0 && lineError(filledIndex(l.key)) && <span className="field-error block">{lineError(filledIndex(l.key))}</span>}
                    </td>
                    <td>
                      <input aria-label={`Description, line ${i + 1}`} className="line-input wide" value={l.description} onChange={(e) => setLine(l.key, { description: e.target.value })} />
                    </td>
                    <td className="num">
                      <input aria-label={`Quantity, line ${i + 1}`} className="line-input qty" inputMode="numeric" value={l.quantity} onChange={(e) => setLine(l.key, { quantity: e.target.value })} />
                    </td>
                    <td>
                      {lines.length > 1 && (
                        <button type="button" className="link" onClick={() => setLines((all) => all.filter((x) => x.key !== l.key))}>
                          Remove
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {error?.field('lines') && <p className="field-error">{error.field('lines')}</p>}
          <button type="button" className="link" onClick={() => setLines((all) => [...all, blankLine()])}>
            + Add a line
          </button>
          <details className="paste-lines">
            <summary>Paste lines from a spreadsheet</summary>
            <div className="field">
              <label htmlFor="paste">One part per line: part number and quantity (a description in between is fine).</label>
              <textarea id="paste" rows={4} placeholder={'AS4-1102\t2\nHW-0450, 24'} value={paste} onChange={(e) => setPaste(e.target.value)} />
            </div>
            <button
              type="button"
              className="secondary"
              disabled={!paste.trim()}
              onClick={() => {
                const parsed = parsePasted(paste)
                setLines((all) => [...all.filter((l) => l.part_number.trim()), ...parsed])
                setPaste('')
              }}
            >
              Add these lines
            </button>
          </details>
        </section>

        <section className="panel">
          <h2>Order details</h2>
          <div className="grid-form">
            <Field label="Your PO number" name="po_number" error={error} hint="Optional - shown to the parts desk and on emails." />
            <Field label="For machine (serial)" name="machine_serial" error={error} hint="Optional - helps the parts desk check fitment." />
          </div>
          <fieldset className="choice">
            <legend>Shipping</legend>
            {(['standard', 'rush', 'pickup'] as const).map((m) => (
              <label key={m} className="check">
                <input type="radio" name="ship_method" checked={method === m} onChange={() => setMethod(m)} /> {m === 'pickup' ? 'We\'ll pick it up' : SHIP_LABEL[m]}
              </label>
            ))}
          </fieldset>
          {method !== 'pickup' && (
            <div className="grid-form">
              {(
                [
                  ['name', 'Ship to'],
                  ['address', 'Street address'],
                  ['city', 'City'],
                  ['region', 'State / province'],
                  ['postal_code', 'ZIP / postal code'],
                  ['phone', 'Phone'],
                ] as const
              ).map(([key, label]) => (
                <Field key={key} label={label} name={`ship_${key}`} errorKey={`ship_to.${key}`} error={error} value={shipTo[key] ?? ''} onChange={(e) => setShipTo({ ...shipTo, [key]: e.target.value })} />
              ))}
            </div>
          )}
          <div className="field">
            <label htmlFor="parts-notes">Notes for the parts desk</label>
            <textarea id="parts-notes" name="notes" rows={2} maxLength={2000} placeholder="Optional" />
          </div>
        </section>

        <div className="save-bar">
          <FormError error={error} />
          {error && Object.keys(error.errors).length > 0 && <span className="form-error">Some things need fixing (marked above).</span>}
          <button className="primary" disabled={busy}>
            {busy ? 'Sending…' : 'Send to the parts desk'}
          </button>
        </div>
      </form>
    </>
  )
}

// ---- One order ----

export function PartsOrderPage() {
  const slug = useSlug()
  const { id } = useParams()
  const { data, error, reload } = useApi<PartsOrderDetail>(`/m/${slug}/parts-orders/${id}`)
  const [acting, setActing] = useState<PartsOrderStatus | null>(null)
  const [busy, setBusy] = useState(false)
  const [actionError, setActionError] = useState<ApiError | null>(null)

  async function submit(body: Record<string, unknown>, path = 'status') {
    setBusy(true)
    setActionError(null)
    try {
      await api.post(`/m/${slug}/parts-orders/${id}/${path}`, body)
      setActing(null)
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
          ← Parts orders
        </Link>
        {error?.status === 404 ? <p className="muted">That order isn't here.</p> : <Loading error={error} />}
      </>
    )
  }

  const s = data.ship_to

  return (
    <>
      <Link to=".." relative="path" className="back">
        ← Parts orders
      </Link>
      <div className="page-head">
        <h1>
          Parts order {data.number} <PartsBadge status={data.status} />
        </h1>
      </div>
      <p className="lede">
        From {data.dealer}
        {data.submitted_by && ` (${data.submitted_by})`} · {formatDateTime(data.created_at)}
        {data.ship_method === 'rush' && (
          <>
            {' '}
            · <strong className="rush">Rush</strong>
          </>
        )}
      </p>

      {data.status === 'shipped' && (
        <div className="callout callout-ok" role="status">
          <strong>Shipped {formatDate(data.shipped_at)}</strong>
          {data.carrier && ` with ${data.carrier}`}
          {data.tracking_number && (
            <>
              {' '}
              · tracking <strong>{data.tracking_number}</strong>
            </>
          )}
          {data.parts_note && <p>{data.parts_note}</p>}
        </div>
      )}
      {data.status === 'cancelled' && (
        <div className="callout callout-danger" role="status">
          <strong>Cancelled {formatDate(data.cancelled_at)}</strong>
          {data.parts_note && <p>{data.parts_note}</p>}
        </div>
      )}
      {data.status === 'in_progress' && data.parts_note && (
        <div className="callout" role="status">
          <strong>From the parts desk:</strong> {data.parts_note}
        </div>
      )}

      <section className="panel">
        <h2>Parts</h2>
        <div className="table-wrap flat">
          <table>
            <thead>
              <tr>
                <th>Part number</th>
                <th>Description</th>
                <th className="num">Qty</th>
              </tr>
            </thead>
            <tbody>
              {data.lines.map((l, i) => (
                <tr key={i}>
                  <td>
                    <code>{l.part_number}</code>
                  </td>
                  <td>{l.description ?? <span className="muted">—</span>}</td>
                  <td className="num">{l.quantity}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      <dl className="facts">
        <div>
          <dt>Their PO</dt>
          <dd>{data.po_number ?? '—'}</dd>
        </div>
        <div>
          <dt>For machine</dt>
          <dd>{data.machine_serial ?? '—'}</dd>
        </div>
        <div>
          <dt>Shipping</dt>
          <dd>{data.ship_method === 'pickup' ? 'Pickup' : SHIP_LABEL[data.ship_method]}</dd>
        </div>
        <div>
          <dt>Ship to</dt>
          <dd>{s ? [s.name, s.address, [s.city, s.region, s.postal_code].filter(Boolean).join(' '), s.phone].filter(Boolean).map((x, i) => <span key={i} className="block">{x}</span>) : '—'}</dd>
        </div>
      </dl>
      {data.notes && (
        <section className="panel">
          <h2>Dealer's notes</h2>
          <p className="prewrap">{data.notes}</p>
        </section>
      )}

      {/* The parts desk's actions. */}
      {data.next.length > 0 && (
        <section className="panel">
          <h2>Parts desk</h2>
          {actionError && !Object.keys(actionError.errors).length && <p className="form-error">{actionError.message}</p>}
          {actionError?.field('status') && <p className="form-error">{actionError.field('status')}</p>}
          {acting === null ? (
            <div className="form-actions">
              {data.next.includes('in_progress') && (
                <button className="secondary" disabled={busy} onClick={() => submit({ status: 'in_progress' })}>
                  Start on it
                </button>
              )}
              {data.next.includes('shipped') && (
                <button className="primary" disabled={busy} onClick={() => setActing('shipped')}>
                  Mark shipped…
                </button>
              )}
              {data.next.includes('cancelled') && (
                <button className="link danger-link" disabled={busy} onClick={() => setActing('cancelled')}>
                  Cancel order…
                </button>
              )}
            </div>
          ) : (
            <form
              noValidate
              onSubmit={(e) => {
                e.preventDefault()
                const f = new FormData(e.currentTarget)
                const v = (k: string) => String(f.get(k) ?? '').trim() || null
                submit({ status: acting, carrier: v('carrier'), tracking_number: v('tracking_number'), parts_note: v('parts_note') })
              }}
            >
              {acting === 'shipped' && (
                <div className="grid-form">
                  <Field label="Carrier" name="carrier" error={actionError} />
                  <Field label="Tracking number" name="tracking_number" error={actionError} />
                </div>
              )}
              <div className="field">
                <label htmlFor="parts-note">{acting === 'cancelled' ? 'Why? (the dealer sees this)' : 'Note for the dealer (optional)'}</label>
                <textarea id="parts-note" name="parts_note" rows={2} maxLength={2000} aria-invalid={!!actionError?.field('parts_note')} placeholder={acting === 'cancelled' ? 'e.g. Superseded by AS4-1102B - please reorder.' : 'e.g. Line 2 backordered, ships Friday.'} />
                {actionError?.field('parts_note') && <p className="field-error">{actionError.field('parts_note')}</p>}
              </div>
              <div className="form-actions">
                <button className={acting === 'cancelled' ? 'danger' : 'primary'} disabled={busy}>
                  {busy ? 'Saving…' : acting === 'cancelled' ? 'Cancel the order' : 'Mark shipped'}
                </button>
                <button type="button" className="link" onClick={() => setActing(null)}>
                  Back
                </button>
              </div>
            </form>
          )}
        </section>
      )}

      {data.can_cancel && (
        <p>
          <button className="link danger-link" disabled={busy} onClick={() => window.confirm('Cancel this parts order?') && submit({}, 'cancel')}>
            Cancel this order
          </button>
          <span className="muted small"> - possible until the parts desk starts on it.</span>
        </p>
      )}
    </>
  )
}
