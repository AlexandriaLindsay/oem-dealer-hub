import { useState, type FormEvent } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { api, ApiError, MACHINE_STATUSES, STATUS_LABELS, type MachineStatus, type DealerRow, type MachineDetail, type MachinePage, type MachineRow, type ProductModelRow } from '../lib/api'
import { Board, ViewSwitch, type BoardColumn } from '../components/Board'
import { useApi } from '../lib/useApi'
import { formatDate, formatDateTime, useSlug } from '../lib/tenant'
import { useCurrentManufacturer } from '../components/Layout'
import { AddForm, Loading, type Option } from '../components/AddForm'
import { Swatch } from '../components/charts'
import { FormError } from '../components/Form'
import { MachineRegistrations } from './RegistrationPages'
import { MachineDocuments } from './LibraryPages'

const STATUS_OPTIONS: Option[] = MACHINE_STATUSES.map((s) => ({ value: s, label: capitalize(s) }))

function capitalize(s: string): string {
  return s.charAt(0).toUpperCase() + s.slice(1)
}

/**
 * How long an unsold machine has sat, in days. Over 180 is flagged (the
 * fleet report's "aged stock"), with words as well as the colour.
 */
export function DaysInStock({ days, card = false }: { days: number | null; card?: boolean }) {
  if (days === null) return card ? null : <span className="muted">—</span>
  const old = days > 180
  return (
    <span className={`days-in-stock${old ? ' is-old' : ''}`} title={old ? 'Unsold for over 180 days' : undefined}>
      {card ? `${days} days in stock` : days}
      {old && <span className="sr-only"> (over 180 days)</span>}
    </span>
  )
}

export function StatusBadge({ status }: { status: MachineStatus }) {
  // Neutral pill with the status swatch: the same colour key as the fleet report charts.
  return (
    <span className="badge badge-status">
      <Swatch status={status} />
      {status}
    </span>
  )
}

/** Active dealers as select options - only staff who can see the dealer network get them. */
function useDealerOptions(enabled: boolean): DealerRow[] {
  const slug = useSlug()
  const { data } = useApi<DealerRow[]>(enabled ? `/m/${slug}/dealers` : null)
  return (data ?? []).filter((d) => d.status === 'active')
}

// ---- The machine list ----

export function MachinesPage() {
  const slug = useSlug()
  const current = useCurrentManufacturer()
  const isDealer = current?.acting_as === 'dealer'
  const canAdd = !!current?.features.includes('manage_machines')
  const [params, setParams] = useSearchParams()
  const [adding, setAdding] = useState(false)
  // The board by default: a column per status. The list (with paging) is a click away,
  // and a link to one status (the dashboard's donut, the fleet report) opens the list filtered to it.
  const view = params.get('view') === 'list' || (params.get('view') !== 'board' && params.has('status')) ? 'list' : 'board'
  // Bumped after adding a machine, so the board fetches again too.
  const [version, setVersion] = useState(0)

  const query = new URLSearchParams()
  for (const key of ['q', 'status', 'line', 'model', 'dealer', 'aged', 'page']) {
    const value = params.get(key)
    if (value) query.set(key, value)
  }
  const { data, error, reload } = useApi<MachinePage>(view === 'list' ? `/m/${slug}/machines${query.size ? `?${query}` : ''}` : null)

  /** The board's filters: everything but status (the columns) and the page. */
  const boardQuery = new URLSearchParams(query)
  boardQuery.delete('status')
  boardQuery.delete('page')

  function setView(v: 'list' | 'board') {
    const next = new URLSearchParams(params)
    next.delete('page')
    if (v === 'list') next.set('view', 'list')
    else {
      next.delete('view')
      next.delete('status')
    }
    setParams(next, { replace: true })
  }
  const models = useApi<ProductModelRow[]>(`/m/${slug}/product-models`).data ?? []
  const dealers = useDealerOptions(!isDealer && !!current?.features.includes('dealers'))

  /** Filters live in the URL, so a filtered list can be bookmarked or shared. */
  function setFilter(key: string, value: string) {
    const next = new URLSearchParams(params)
    if (value) next.set(key, value)
    else next.delete(key)
    if (key !== 'page') next.delete('page')
    setParams(next, { replace: true })
  }

  function onSearch(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setFilter('q', String(new FormData(e.currentTarget).get('q') ?? '').trim())
  }

  return (
    <>
      <div className="page-head">
        <h1>{isDealer ? 'Your machines' : 'Machines'}</h1>
        <span className="head-actions">
          {current?.features.includes('import_machines') && !adding && (
            <Link to="import" className="button-secondary">
              Import from a spreadsheet
            </Link>
          )}
          {canAdd && !adding && (
            <button className="primary" onClick={() => setAdding(true)} disabled={models.length === 0} title={models.length === 0 ? 'Add a model under Products first' : undefined}>
              Add machine
            </button>
          )}
        </span>
      </div>

      {isDealer && <p className="lede">The {current?.name} machines at {current?.dealer?.name} right now. Keep each one's status up to date as it moves through demo, rental and sale.</p>}

      {adding && (
        <section className="panel">
          <h2>Add a machine</h2>
          <AddForm
            path={`/m/${slug}/machines`}
            fields={[
              { name: 'product_model_id', label: 'Model', options: models.map((m) => ({ value: String(m.id), label: `${m.name} (${m.product_line.name})` })) },
              { name: 'serial_no', label: 'Serial number', required: true },
              { name: 'production_no', label: 'Production number', hint: 'Optional - e.g. the work order it was built on.' },
              {
                name: 'dealer_link_id',
                label: 'Dealer',
                options: [{ value: '', label: 'Still at the factory' }, ...dealers.map((d) => ({ value: String(d.id), label: d.name }))],
                hint: 'Picking a dealer marks it shipped today.',
              },
              { name: 'status', label: 'Status', options: STATUS_OPTIONS },
              { name: 'built_at', label: 'Built on', type: 'date' },
            ]}
            button="Add machine"
            onAdded={() => {
              setAdding(false)
              if (view === 'list') reload()
              else setVersion((v) => v + 1)
            }}
            onCancel={() => setAdding(false)}
          />
        </section>
      )}

      <div className="filters">
        <ViewSwitch view={view} onChange={setView} />
        <form onSubmit={onSearch} className="filter-search" role="search">
          <label htmlFor="machine-search" className="sr-only">
            Search serial or production number
          </label>
          <input id="machine-search" name="q" type="search" placeholder="Serial or production number" defaultValue={params.get('q') ?? ''} key={params.get('q') ?? ''} />
          <button className="secondary">Search</button>
        </form>
        {view === 'list' && <FilterSelect label="Status" value={params.get('status') ?? ''} onChange={(v) => setFilter('status', v)} options={[{ value: '', label: 'Any status' }, ...STATUS_OPTIONS]} />}
        <FilterSelect
          label="Model"
          value={params.get('model') ?? ''}
          onChange={(v) => setFilter('model', v)}
          options={[{ value: '', label: 'Any model' }, ...models.map((m) => ({ value: String(m.id), label: m.name }))]}
        />
        <FilterSelect
          label="How long in stock"
          value={params.get('aged') ?? ''}
          onChange={(v) => setFilter('aged', v)}
          options={[
            { value: '', label: 'Any age' },
            { value: '90', label: 'Unsold 90+ days' },
            { value: '180', label: 'Unsold 180+ days' },
            { value: '365', label: 'Unsold over a year' },
          ]}
        />
        {!isDealer && dealers.length > 0 && (
          <FilterSelect
            label="Dealer"
            value={params.get('dealer') ?? ''}
            onChange={(v) => setFilter('dealer', v)}
            options={[{ value: '', label: 'Any dealer' }, { value: 'none', label: 'At the factory' }, ...dealers.map((d) => ({ value: String(d.id), label: d.name }))]}
          />
        )}
      </div>

      {view === 'board' ? (
        <MachineBoard key={version} slug={slug} filters={boardQuery.toString()} isDealer={isDealer} canMove={!!current?.features.includes('machine_status')} />
      ) : !data ? (
        <Loading error={error} />
      ) : data.total === 0 ? (
        <p className="muted">{query.size ? 'No machines match those filters.' : isDealer ? 'No machines with you yet.' : 'No machines yet.'}</p>
      ) : (
        <>
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Serial</th>
                  <th>Model</th>
                  {!isDealer && <th>Dealer</th>}
                  <th>Status</th>
                  <th>Shipped</th>
                  <th className="num">Days in stock</th>
                </tr>
              </thead>
              <tbody>
                {data.data.map((m) => (
                  <tr key={m.id}>
                    <td>
                      <Link to={`${m.id}`}>{m.serial_no}</Link>
                    </td>
                    <td>
                      {m.model.name} <span className="muted">· {m.product_line}</span>
                    </td>
                    {!isDealer && <td>{m.dealer?.name ?? <span className="muted">At the factory</span>}</td>}
                    <td>
                      <StatusBadge status={m.status} />
                    </td>
                    <td>{formatDate(m.shipped_at)}</td>
                    <td className="num"><DaysInStock days={m.days_in_stock} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="pager">
            <span className="muted">
              {data.total} machine{data.total === 1 ? '' : 's'}
              {data.last_page > 1 && ` · page ${data.page} of ${data.last_page}`}
            </span>
            {data.last_page > 1 && (
              <span>
                <button className="link" disabled={data.page <= 1} onClick={() => setFilter('page', String(data.page - 1))}>
                  Previous
                </button>
                <button className="link" disabled={data.page >= data.last_page} onClick={() => setFilter('page', String(data.page + 1))}>
                  Next
                </button>
              </span>
            )}
          </div>
        </>
      )}
    </>
  )
}

/**
 * Machines as a board: a column per status, a card per machine. Someone who
 * can change status moves a card by dragging it or with its "Move to" menu;
 * each move lands in the machine's history like a change made on its page.
 */
function MachineBoard({ slug, filters, isDealer, canMove }: { slug: string; filters: string; isDealer: boolean; canMove: boolean }) {
  const path = (status: MachineStatus) => `/m/${slug}/machines?${new URLSearchParams(`${filters}${filters ? '&' : ''}status=${status}`)}`
  const cols: Record<MachineStatus, ReturnType<typeof useApi<MachinePage>>> = {
    inventory: useApi<MachinePage>(path('inventory')),
    demo: useApi<MachinePage>(path('demo')),
    rental: useApi<MachinePage>(path('rental')),
    sold: useApi<MachinePage>(path('sold')),
    used: useApi<MachinePage>(path('used')),
  }
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)

  const movesFor = (m: MachineRow): string[] => (canMove ? MACHINE_STATUSES.filter((s) => s !== m.status) : [])

  async function move(m: MachineRow, to: string) {
    setError(null)
    setBusy(true)
    try {
      await api.post(`/m/${slug}/machines/${m.id}/status`, { status: to })
      await Promise.all([cols[m.status].reload(), cols[to as MachineStatus].reload()])
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(0, { message: 'Could not reach OEM Dealer Hub.' }))
    } finally {
      setBusy(false)
    }
  }

  const columns: BoardColumn<MachineRow>[] = MACHINE_STATUSES.map((s) => {
    const c = cols[s]
    return {
      key: s,
      title: STATUS_LABELS[s],
      tone: 'neutral',
      color: `var(--status-${s})`,
      items: c.data?.data ?? null,
      total: c.data?.total ?? 0,
      error: c.error,
      footer: c.data && c.data.total > c.data.data.length ? `Showing the latest ${c.data.data.length} of ${c.data.total}. The list view has them all.` : undefined,
    }
  })

  return (
    <>
      {error && (
        <p className="form-error board-error" role="alert">
          {error.message}
        </p>
      )}
      <Board
        label="Machines by status"
        columns={columns}
        cardKey={(m) => m.id}
        movesFor={movesFor}
        onMove={move}
        empty={filters ? 'None match.' : 'None.'}
        renderCard={(m) => (
          <>
            <div className="card-top">
              <Link to={String(m.id)}>{m.serial_no}</Link>
            </div>
            <span className="card-title">
              {m.model.name} <span className="muted">· {m.product_line}</span>
            </span>
            <div className="card-meta">
              {!isDealer && <span>{m.dealer?.name ?? 'At the factory'}</span>}
              {m.days_in_stock !== null ? <DaysInStock days={m.days_in_stock} card /> : m.shipped_at && <span>Shipped {formatDate(m.shipped_at)}</span>}
            </div>
            {canMove && (
              <div className="card-actions">
                <label className="card-move">
                  <span className="sr-only">Move {m.serial_no} to</span>
                  <select value="" disabled={busy} onChange={(e) => e.target.value && move(m, e.target.value)}>
                    <option value="">Move to…</option>
                    {movesFor(m).map((s) => (
                      <option key={s} value={s}>
                        {STATUS_LABELS[s as MachineStatus]}
                      </option>
                    ))}
                  </select>
                </label>
              </div>
            )}
          </>
        )}
      />
    </>
  )
}

function FilterSelect(props:{ label: string; value: string; onChange: (value: string) => void; options: Option[] }) {
  return (
    <label className="filter-select">
      <span className="sr-only">{props.label}</span>
      <select value={props.value} onChange={(e) => props.onChange(e.target.value)}>
        {props.options.map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </select>
    </label>
  )
}

// ---- One machine ----

export function MachinePage() {
  const slug = useSlug()
  const { id } = useParams()
  const current = useCurrentManufacturer()
  const isDealer = current?.acting_as === 'dealer'
  const { data, error, reload } = useApi<MachineDetail>(`/m/${slug}/machines/${id}`)
  const canMove = !!current?.features.includes('manage_machines')
  const dealers = useDealerOptions(canMove && !!current?.features.includes('dealers'))

  if (!data) {
    return (
      <>
        <Link to=".." relative="path" className="back">
          ← Machines
        </Link>
        {error?.status === 404 ? <p className="muted">That machine isn't here. It may have moved to another dealer.</p> : <Loading error={error} />}
      </>
    )
  }

  return (
    <>
      <Link to=".." relative="path" className="back">
        ← {isDealer ? 'Your machines' : 'Machines'}
      </Link>
      <div className="page-head">
        <h1>
          {data.serial_no} <StatusBadge status={data.status} />
        </h1>
      </div>
      <p className="lede">
        {data.model.name} · {data.product_line}
      </p>

      <dl className="facts">
        <div>
          <dt>Dealer</dt>
          <dd>{data.dealer?.name ?? 'At the factory'}</dd>
        </div>
        <div>
          <dt>Production number</dt>
          <dd>{data.production_no ?? '—'}</dd>
        </div>
        <div>
          <dt>Built</dt>
          <dd>{formatDate(data.built_at)}</dd>
        </div>
        <div>
          <dt>Shipped</dt>
          <dd>{formatDate(data.shipped_at)}</dd>
        </div>
        <div>
          <dt>Confirmed on the lot</dt>
          <dd>{data.confirmed_at ? formatDate(data.confirmed_at) : <span className="muted">Not yet</span>}</dd>
        </div>
      </dl>

      <div className="two-up">
        {current?.features.includes('machine_status') && (
          <ChangeForm
            title="Change status"
            path={`/m/${slug}/machines/${data.id}/status`}
            field="status"
            value={data.status}
            options={STATUS_OPTIONS}
            button="Save status"
            onDone={reload}
          />
        )}
        {canMove && (
          <ChangeForm
            title="Move to another dealer"
            path={`/m/${slug}/machines/${data.id}/transfer`}
            field="dealer_link_id"
            value={data.dealer ? String(data.dealer.id) : ''}
            options={[{ value: '', label: 'Back to the factory' }, ...dealers.map((d) => ({ value: String(d.id), label: d.name }))]}
            button="Move machine"
            onDone={reload}
          />
        )}
      </div>

      {current?.features.includes('registrations') && <MachineRegistrations machine={data} />}

      {current?.features.includes('documents') && <MachineDocuments machineId={data.id} />}

      <h2 className="section-title">History</h2>
      <ol className="history">
        {data.history.map((e) => (
          <li key={e.id}>
            <span className="history-summary">{e.summary}</span>
            {e.note && <span className="history-note">“{e.note}”</span>}
            <span className="muted">
              {formatDateTime(e.at)}
              {e.by && ` · ${e.by}`}
            </span>
          </li>
        ))}
      </ol>
    </>
  )
}

/** A select plus an optional note, for one change to a machine (status, or dealer). */
function ChangeForm(props: { title: string; path: string; field: string; value: string; options: Option[]; button: string; onDone: () => void }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)

  async function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const form = e.currentTarget
    const data = new FormData(form)
    const value = String(data.get(props.field) ?? '')
    const note = String(data.get('note') ?? '').trim()
    setBusy(true)
    setError(null)
    try {
      // An empty dealer means "back to the factory", which the API takes as null.
      await api.post(props.path, { [props.field]: value === '' ? null : value, note: note || undefined })
      form.reset()
      props.onDone()
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError(0, { message: 'Could not reach OEM Dealer Hub.' }))
    } finally {
      setBusy(false)
    }
  }

  const id = `change-${props.field}`
  return (
    <section className="panel">
      <h2>{props.title}</h2>
      {/* key: start from the machine's current value again after each change, and once the options have loaded. */}
      <form onSubmit={onSubmit} noValidate key={`${props.value}:${props.options.length}`}>
        <div className="field">
          <label htmlFor={id} className="sr-only">
            {props.title}
          </label>
          <select id={id} name={props.field} defaultValue={props.value}>
            {props.options.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </select>
          {error?.field(props.field) && <p className="field-error">{error.field(props.field)}</p>}
        </div>
        <div className="field">
          <label htmlFor={`${id}-note`}>Note</label>
          <input id={`${id}-note`} name="note" maxLength={500} placeholder="Optional - shows in the history" />
        </div>
        <FormError error={error} />
        <button className="primary" disabled={busy}>
          {busy ? 'Saving…' : props.button}
        </button>
      </form>
    </section>
  )
}
