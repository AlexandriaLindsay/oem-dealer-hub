import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { api, ApiError, type Catalog, type QuoteCustomer, type QuoteDetail, type QuoteLineData, type QuotePage, type QuoteStatus } from '../lib/api'
import { useApi } from '../lib/useApi'
import { formatDate, useSlug } from '../lib/tenant'
import { fromCents, money, toCents } from '../lib/money'
import { useCurrentManufacturer } from '../components/Layout'
import { Loading } from '../components/AddForm'
import { Field, FormError } from '../components/Form'

function asApiError(e: unknown): ApiError {
  return e instanceof ApiError ? e : new ApiError(0, { message: 'Could not reach DealerDock.' })
}

const STATUS_LABEL: Record<QuoteStatus, string> = { draft: 'Draft', sent: 'Sent', won: 'Won', lost: 'Lost' }

export function QuoteBadge({ status, expired }: { status: QuoteStatus; expired?: boolean }) {
  return <span className={`badge badge-quote-${expired ? 'expired' : status}`}>{expired ? 'Expired' : STATUS_LABEL[status]}</span>
}

const customerName = (c: QuoteCustomer) => (c.company ? `${c.company} (${c.contact_name})` : c.contact_name)

// ---- The list ----

const TABS: [string, string][] = [
  ['', 'All'],
  ['draft', 'Drafts'],
  ['sent', 'Sent'],
  ['won', 'Won'],
  ['lost', 'Lost'],
]

export function QuotesPage() {
  const slug = useSlug()
  const navigate = useNavigate()
  const current = useCurrentManufacturer()
  const isDealer = current?.acting_as === 'dealer'
  const canBuild = !!current?.features.includes('build_quotes')
  const [params, setParams] = useSearchParams()
  const status = params.get('status') ?? ''
  const q = params.get('q') ?? ''
  const query = new URLSearchParams()
  if (status) query.set('status', status)
  if (q) query.set('q', q)
  const { data, error } = useApi<QuotePage>(`/m/${slug}/quotes${query.size ? `?${query}` : ''}`)
  const [creating, setCreating] = useState(false)

  return (
    <>
      <div className="page-head">
        <h1>Quotes</h1>
        {canBuild && !creating && (
          <button className="primary" onClick={() => setCreating(true)}>
            New quote
          </button>
        )}
      </div>
      <p className="lede">
        {isDealer ? `Quotes for your customers, priced from your ${current?.name} prices.` : 'Quotes your dealers have written for their customers. Read-only here.'}
      </p>

      {creating && <NewQuoteForm slug={slug} onCancel={() => setCreating(false)} onCreated={(id) => navigate(String(id))} />}

      <div className="tabs" role="tablist" aria-label="Quote status">
        {TABS.map(([key, label]) => (
          <button key={key || 'all'} role="tab" aria-selected={status === key} className={status === key ? 'is-on' : ''} onClick={() => setParams(key ? { status: key } : {}, { replace: true })}>
            {label}
          </button>
        ))}
      </div>
      <div className="filters">
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
          <label htmlFor="quote-search" className="sr-only">
            Search customer or quote number
          </label>
          <input id="quote-search" name="q" type="search" placeholder="Customer or quote number" defaultValue={q} key={q} />
          <button className="secondary">Search</button>
        </form>
      </div>

      {!data ? (
        <Loading error={error} />
      ) : data.total === 0 ? (
        <p className="muted">{query.size ? 'No quotes match.' : canBuild ? 'No quotes yet. Start one for a customer.' : 'No quotes yet.'}</p>
      ) : (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Quote</th>
                <th>Customer</th>
                {!isDealer && <th>Dealer</th>}
                <th>Total</th>
                <th>Valid until</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              {data.data.map((row) => (
                <tr key={row.id}>
                  <td>
                    <Link to={String(row.id)}>{row.number}</Link>
                  </td>
                  <td>{customerName(row.customer)}</td>
                  {!isDealer && <td>{row.dealer}</td>}
                  <td className="num">{money(row.total, row.currency)}</td>
                  <td>{formatDate(row.valid_until)}</td>
                  <td>
                    <QuoteBadge status={row.status} expired={row.expired} />
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

function NewQuoteForm({ slug, onCancel, onCreated }: { slug: string; onCancel: () => void; onCreated: (id: number) => void }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const in30 = new Date(Date.now() + 30 * 86_400_000).toLocaleDateString('en-CA')

  async function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const f = new FormData(e.currentTarget)
    const get = (k: string) => String(f.get(k) ?? '').trim() || undefined
    setBusy(true)
    setError(null)
    try {
      const created = await api.post<QuoteDetail>(`/m/${slug}/quotes`, {
        customer: { company: get('company'), contact_name: get('contact_name') ?? '', email: get('email'), phone: get('phone') },
        valid_until: get('valid_until'),
      })
      onCreated(created.id)
    } catch (err) {
      setError(asApiError(err))
      setBusy(false)
    }
  }

  return (
    <section className="panel">
      <h2>New quote - who's it for?</h2>
      <form onSubmit={onSubmit} noValidate className="grid-form">
        <Field label="Company" name="company" error={error} errorKey="customer.company" hint="Leave blank for a private buyer." />
        <Field label="Contact name" name="contact_name" required error={error} errorKey="customer.contact_name" />
        <Field label="Email" name="email" type="email" error={error} errorKey="customer.email" />
        <Field label="Phone" name="phone" type="tel" error={error} errorKey="customer.phone" />
        <Field label="Valid until" name="valid_until" type="date" defaultValue={in30} error={error} />
        <div className="form-actions">
          <FormError error={error} />
          {error?.field('customer') && <p className="form-error">{error.field('customer')}</p>}
          <button className="primary" disabled={busy}>
            {busy ? 'Starting…' : 'Start the quote'}
          </button>
          <button type="button" className="link" onClick={onCancel}>
            Cancel
          </button>
        </div>
      </form>
    </section>
  )
}

// ---- One quote: view, or build while it's a draft ----

/** A line being edited: the unit price as typed ('' = use the list price). */
interface DraftLine {
  key: string
  kind: QuoteLineData['kind']
  item_id: number | null
  description: string
  quantity: string
  list_price: string | null
  unit_price: string
}

let nextKey = 0
const newKey = () => `l${nextKey++}`

export function QuotePage() {
  const slug = useSlug()
  const navigate = useNavigate()
  const { id } = useParams()
  const { data, error, reload } = useApi<QuoteDetail>(`/m/${slug}/quotes/${id}`)
  const [busy, setBusy] = useState<string | null>(null)
  const [actionError, setActionError] = useState<ApiError | null>(null)

  async function act(what: 'status' | 'copy' | 'delete', status?: QuoteStatus) {
    setBusy(what + (status ?? ''))
    setActionError(null)
    try {
      if (what === 'copy') {
        const copy = await api.post<QuoteDetail>(`/m/${slug}/quotes/${id}/copy`)
        navigate(`../${copy.id}`, { relative: 'path' })
      } else if (what === 'delete') {
        if (!window.confirm('Delete this draft quote?')) return
        await api.delete(`/m/${slug}/quotes/${id}`)
        navigate('..', { relative: 'path' })
      } else {
        await api.post(`/m/${slug}/quotes/${id}/status`, { status })
        reload()
      }
    } catch (e) {
      setActionError(asApiError(e))
    } finally {
      setBusy(null)
    }
  }

  if (!data) {
    return (
      <>
        <Link to=".." relative="path" className="back">
          ← Quotes
        </Link>
        {error?.status === 404 ? <p className="muted">That quote isn't here.</p> : <Loading error={error} />}
      </>
    )
  }

  const statusError = actionError?.field('status') ?? (actionError && !Object.keys(actionError.errors).length ? actionError.message : null)

  return (
    <>
      <Link to=".." relative="path" className="back">
        ← Quotes
      </Link>
      <div className="page-head">
        <h1>
          {data.number} <QuoteBadge status={data.status} expired={data.expired} />
        </h1>
        <span className="head-actions">
          <Link to="print" className="button-secondary" target="_blank">
            Print / PDF
          </Link>
          {data.can_change_status && (
            <>
              <button className="secondary" disabled={busy !== null} onClick={() => act('copy')}>
                Copy
              </button>
              {data.status === 'sent' && (
                <>
                  <button className="secondary" disabled={busy !== null} onClick={() => act('status', 'draft')}>
                    Reopen as draft
                  </button>
                  <button className="secondary" disabled={busy !== null} onClick={() => act('status', 'lost')}>
                    Mark lost
                  </button>
                  <button className="primary" disabled={busy !== null} onClick={() => act('status', 'won')}>
                    Mark won
                  </button>
                </>
              )}
            </>
          )}
        </span>
      </div>
      <p className="lede">
        {customerName(data.customer)} · {data.currency === 'CAD' ? 'Canadian dollars' : 'US dollars'}
        {data.valid_until && ` · valid until ${formatDate(data.valid_until)}`}
        {data.sent_at && ` · sent ${formatDate(data.sent_at)}`}
        {!data.can_change_status && ` · from ${data.dealer}`}
      </p>
      {statusError && (
        <p className="form-error" role="alert">
          {statusError}
        </p>
      )}

      {data.can_edit ? (
        <QuoteBuilder
          slug={slug}
          quote={data}
          onSaved={reload}
          onSend={() => act('status', 'sent')}
          onDelete={() => act('delete')}
          sending={busy === 'statussent'}
        />
      ) : (
        <QuoteView quote={data} />
      )}
    </>
  )
}

function QuoteView({ quote }: { quote: QuoteDetail }) {
  return (
    <>
      <section className="panel">
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
              {quote.lines.map((l, i) => (
                <tr key={i} className={l.kind === 'option' ? 'quote-option' : ''}>
                  <td>{l.description}</td>
                  <td className="num">{l.quantity}</td>
                  <td className="num">{money(l.unit_price, quote.currency)}</td>
                  <td className="num">{money(l.line_total, quote.currency)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <Totals currency={quote.currency} subtotal={quote.subtotal} discount={quote.discount} total={quote.total} />
      </section>
      {quote.notes && (
        <section className="panel">
          <h2>Notes for the customer</h2>
          <p className="prewrap">{quote.notes}</p>
        </section>
      )}
    </>
  )
}

function Totals({ currency, subtotal, discount, total, preview }: { currency: string; subtotal: string; discount: string; total: string; preview?: boolean }) {
  return (
    <dl className="quote-totals">
      <div>
        <dt>Subtotal</dt>
        <dd>{money(subtotal, currency)}</dd>
      </div>
      {Number(discount) > 0 && (
        <div>
          <dt>Discount</dt>
          <dd>−{money(discount, currency)}</dd>
        </div>
      )}
      <div className="quote-total">
        <dt>Total{preview ? ' (before saving)' : ''}</dt>
        <dd>{money(total, currency)}</dd>
      </div>
      <p className="hint">Taxes not included.</p>
    </dl>
  )
}

function QuoteBuilder({ slug, quote, onSaved, onSend, onDelete, sending }: { slug: string; quote: QuoteDetail; onSaved: () => void; onSend: () => void; onDelete: () => void; sending: boolean }) {
  const catalog = useApi<Catalog>(`/m/${slug}/catalog`).data
  const [customer, setCustomer] = useState<QuoteCustomer>(quote.customer)
  const [validUntil, setValidUntil] = useState(quote.valid_until ?? '')
  const [notes, setNotes] = useState(quote.notes ?? '')
  const [discount, setDiscount] = useState(Number(quote.discount) > 0 ? quote.discount : '')
  const [lines, setLines] = useState<DraftLine[]>([])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const [dirty, setDirty] = useState(false)

  // The saved lines, as editable rows: a price equal to the list price shows as "use the list price".
  useEffect(() => {
    setLines(
      quote.lines.map((l) => ({
        key: newKey(),
        kind: l.kind,
        item_id: l.item_id,
        description: l.description,
        quantity: String(l.quantity),
        list_price: l.list_price,
        unit_price: l.list_price !== null && l.unit_price === l.list_price ? '' : l.unit_price,
      })),
    )
    setDirty(false)
  }, [quote])

  const change = <T,>(set: (v: T) => void) => (v: T) => {
    set(v)
    setDirty(true)
  }

  // A running total as things are typed; the server works out the real one on save.
  const preview = useMemo(() => {
    let subtotal = 0
    for (const l of lines) {
      const unit = l.unit_price.trim() ? toCents(l.unit_price) : l.list_price ? toCents(l.list_price) : null
      const qty = Number(l.quantity)
      if (unit !== null && Number.isInteger(qty) && qty > 0) subtotal += unit * qty
    }
    const off = discount.trim() ? (toCents(discount) ?? 0) : 0
    return { subtotal: fromCents(subtotal), discount: fromCents(off), total: fromCents(Math.max(0, subtotal - off)) }
  }, [lines, discount])

  async function save(): Promise<boolean> {
    setBusy(true)
    setError(null)
    try {
      await api.post(`/m/${slug}/quotes/${quote.id}`, {
        customer,
        valid_until: validUntil || null,
        notes: notes.trim() || null,
        discount: discount.trim() || null,
        lines: lines.map((l) => ({ kind: l.kind, item_id: l.item_id, description: l.description, quantity: Number(l.quantity) || 0, unit_price: l.unit_price.trim() || null })),
      })
      onSaved()
      return true
    } catch (e) {
      setError(asApiError(e))
      return false
    } finally {
      setBusy(false)
    }
  }

  const lineError = (i: number) => error?.field(`lines.${i}.unit_price`) ?? error?.field(`lines.${i}.quantity`) ?? error?.field(`lines.${i}.description`) ?? error?.field(`lines.${i}.item_id`)
  const update = (key: string, patch: Partial<DraftLine>) => {
    setLines((all) => all.map((l) => (l.key === key ? { ...l, ...patch } : l)))
    setDirty(true)
  }

  return (
    <>
      <section className="panel">
        <h2>Customer</h2>
        <div className="grid-form">
          {(
            [
              ['company', 'Company'],
              ['contact_name', 'Contact name'],
              ['email', 'Email'],
              ['phone', 'Phone'],
              ['city', 'City'],
              ['region', 'State / province'],
            ] as const
          ).map(([key, label]) => (
            <Field
              key={key}
              label={label}
              name={`customer_${key}`}
              errorKey={`customer.${key}`}
              error={error}
              value={customer[key] ?? ''}
              onChange={(e) => change(setCustomer)({ ...customer, [key]: e.target.value })}
            />
          ))}
          <Field label="Valid until" name="valid_until" type="date" error={error} value={validUntil} onChange={(e) => change(setValidUntil)(e.target.value)} />
        </div>
      </section>

      <section className="panel">
        <h2>What's quoted</h2>
        {lines.length === 0 ? (
          <p className="muted">Nothing yet. Add a model below.</p>
        ) : (
          <div className="table-wrap flat">
            <table className="quote-lines">
              <thead>
                <tr>
                  <th>Item</th>
                  <th className="num">Qty</th>
                  <th className="num">Unit price</th>
                  <th className="num">Total</th>
                  <th>
                    <span className="sr-only">Remove</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                {lines.map((l, i) => {
                  const unit = l.unit_price.trim() ? toCents(l.unit_price) : l.list_price ? toCents(l.list_price) : null
                  const qty = Number(l.quantity)
                  return (
                    <tr key={l.key} className={l.kind === 'option' ? 'quote-option' : ''}>
                      <td>
                        {l.kind === 'custom' ? (
                          <input aria-label="Description" className="line-input wide" placeholder="e.g. Freight" value={l.description} onChange={(e) => update(l.key, { description: e.target.value })} />
                        ) : (
                          l.description
                        )}
                        {lineError(i) && <span className="field-error block">{lineError(i)}</span>}
                      </td>
                      <td className="num">
                        <input aria-label={`${l.description || 'Line'} quantity`} className="line-input qty" inputMode="numeric" value={l.quantity} onChange={(e) => update(l.key, { quantity: e.target.value })} />
                      </td>
                      <td className="num">
                        <input
                          aria-label={`${l.description || 'Line'} unit price`}
                          className="line-input"
                          inputMode="decimal"
                          placeholder={l.list_price ? fromDisplay(l.list_price) : 'Enter price'}
                          value={l.unit_price}
                          onChange={(e) => update(l.key, { unit_price: e.target.value })}
                        />
                        {l.list_price && l.unit_price.trim() && <span className="hint block">List {money(l.list_price, quote.currency)}</span>}
                      </td>
                      <td className="num">{unit !== null && qty > 0 ? money(fromCents(unit * qty), quote.currency) : '—'}</td>
                      <td>
                        <button className="link" aria-label={`Remove ${l.description || 'line'}`} onClick={() => {
                            setLines((all) => all.filter((x) => x.key !== l.key))
                            setDirty(true)
                          }}
                        >
                          Remove
                        </button>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
        <p className="hint">Leave a unit price empty to use your list price. Change it to quote a different price.</p>

        <AddFromCatalog
          catalog={catalog}
          onAdd={(added) => {
            setLines((all) => [...all, ...added])
            setDirty(true)
          }}
        />
        <button
          className="link"
          onClick={() => {
            setLines((all) => [...all, { key: newKey(), kind: 'custom', item_id: null, description: '', quantity: '1', list_price: null, unit_price: '' }])
            setDirty(true)
          }}
        >
          + Add your own line (freight, setup…)
        </button>

        <div className="discount-row">
          <label htmlFor="quote-discount">Discount off the total</label>
          <span className="money-input">
            <span aria-hidden>{quote.currency === 'CAD' ? 'CA$' : '$'}</span>
            <input id="quote-discount" inputMode="decimal" placeholder="0" value={discount} onChange={(e) => change(setDiscount)(e.target.value)} aria-invalid={!!error?.field('discount')} />
          </span>
          {error?.field('discount') && <span className="field-error">{error.field('discount')}</span>}
        </div>
        <Totals currency={quote.currency} {...preview} preview={dirty} />
      </section>

      <section className="panel">
        <label htmlFor="quote-notes">
          <h2>Notes for the customer</h2>
        </label>
        <textarea id="quote-notes" rows={3} maxLength={4000} placeholder="Optional - shown on the quote. E.g. delivery time, what's included." value={notes} onChange={(e) => change(setNotes)(e.target.value)} />
      </section>

      <div className="save-bar">
        {error && !Object.keys(error.errors).some((k) => k.startsWith('lines.') || k.startsWith('customer.') || k === 'discount') ? (
          <span className="form-error">{error.field('status') ?? error.message}</span>
        ) : error ? (
          <span className="form-error">Some things need fixing (marked above). Nothing was saved.</span>
        ) : (
          <span className="muted">{dirty ? 'Unsaved changes' : 'Saved'}</span>
        )}
        <button className="link danger-link" onClick={onDelete}>
          Delete draft
        </button>
        <button className="secondary" disabled={busy || !dirty} onClick={() => save()}>
          {busy ? 'Saving…' : 'Save draft'}
        </button>
        <button
          className="primary"
          disabled={busy || sending || lines.length === 0}
          onClick={async () => {
            if (!dirty || (await save())) onSend()
          }}
        >
          {sending ? 'Marking…' : 'Mark as sent'}
        </button>
      </div>
    </>
  )
}

/** "48500.00" -> "48,500.00" for a placeholder. */
function fromDisplay(price: string): string {
  return Number(price).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

/** Pick a model, tick its options, set a quantity - adds the lines at list price. */
function AddFromCatalog({ catalog, onAdd }: { catalog: Catalog | null; onAdd: (lines: DraftLine[]) => void }) {
  const models = (catalog?.lines ?? []).flatMap((line) => line.models.map((m) => ({ ...m, line: line.name })))
  const [modelId, setModelId] = useState('')
  const [picked, setPicked] = useState<number[]>([])
  const [qty, setQty] = useState('1')
  const model = models.find((m) => String(m.id) === modelId)
  const currency = catalog?.price_list?.currency ?? 'USD'

  if (!catalog) return null

  function add() {
    if (!model) return
    const lines: DraftLine[] = [
      { key: newKey(), kind: 'model', item_id: model.id, description: model.name, quantity: qty, list_price: model.price, unit_price: '' },
      ...model.options
        .filter((o) => picked.includes(o.id))
        .map((o): DraftLine => ({ key: newKey(), kind: 'option', item_id: o.id, description: `${model.name} - ${o.name}`, quantity: qty, list_price: o.price, unit_price: '' })),
    ]
    onAdd(lines)
    setModelId('')
    setPicked([])
    setQty('1')
  }

  return (
    <div className="add-model">
      <div className="add-model-row">
        <div className="field">
          <label htmlFor="add-model">Add a model</label>
          <select
            id="add-model"
            value={modelId}
            onChange={(e) => {
              setModelId(e.target.value)
              setPicked([])
            }}
          >
            <option value="">Choose a model…</option>
            {models.map((m) => (
              <option key={m.id} value={m.id}>
                {m.name} ({m.line}) - {money(m.price, currency)}
              </option>
            ))}
          </select>
        </div>
        {model && (
          <div className="field">
            <label htmlFor="add-qty">Quantity</label>
            <input id="add-qty" className="line-input qty" inputMode="numeric" value={qty} onChange={(e) => setQty(e.target.value)} />
          </div>
        )}
      </div>
      {model && model.options.length > 0 && (
        <fieldset className="choice option-picks">
          <legend>Options</legend>
          {model.options.map((o) => (
            <label key={o.id} className="check">
              <input type="checkbox" checked={picked.includes(o.id)} onChange={(e) => setPicked(e.target.checked ? [...picked, o.id] : picked.filter((x) => x !== o.id))} /> {o.name} <span className="muted">· {money(o.price, currency)}</span>
            </label>
          ))}
        </fieldset>
      )}
      {model && (
        <button className="secondary" onClick={add}>
          Add to quote
        </button>
      )}
    </div>
  )
}

// ---- The printable copy ----

export function QuotePrintPage() {
  const slug = useSlug()
  const { id } = useParams()
  const { data, error } = useApi<QuoteDetail>(`/m/${slug}/quotes/${id}`)

  if (!data) return <Loading error={error} />
  const c = data.customer
  const f = data.from

  return (
    <article className="quote-print">
      <div className="print-actions">
        <button className="primary" onClick={() => window.print()}>
          Print / Save as PDF
        </button>
      </div>
      <header className="quote-print-head">
        <div>
          <h1>{f.dealer}</h1>
          <p>
            {[f.city, f.region].filter(Boolean).join(', ')}
            {f.phone && <><br />{f.phone}</>}
            {f.website && <><br />{f.website}</>}
          </p>
        </div>
        <div className="quote-print-meta">
          {f.logo_url && <img src={f.logo_url} alt={`${f.manufacturer} logo`} onError={(e) => (e.currentTarget.style.display = 'none')} />}
          <h2>Quote {data.number}</h2>
          <p>
            {formatDate(data.sent_at ?? data.created_at)}
            {data.valid_until && <><br />Valid until {formatDate(data.valid_until)}</>}
          </p>
        </div>
      </header>

      <section className="quote-print-to">
        <h3>For</h3>
        <p>
          {c.company && <><strong>{c.company}</strong><br /></>}
          {c.contact_name}
          {c.email && <><br />{c.email}</>}
          {c.phone && <><br />{c.phone}</>}
          {(c.city || c.region) && <><br />{[c.city, c.region].filter(Boolean).join(', ')}</>}
        </p>
      </section>

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
      <Totals currency={data.currency} subtotal={data.subtotal} discount={data.discount} total={data.total} />
      <p className="hint">All prices in {data.currency === 'CAD' ? 'Canadian' : 'US'} dollars.</p>

      {data.notes && (
        <section>
          <h3>Notes</h3>
          <p className="prewrap">{data.notes}</p>
        </section>
      )}
      <footer className="muted small">{f.manufacturer} equipment, quoted by {f.dealer}.</footer>
    </article>
  )
}
