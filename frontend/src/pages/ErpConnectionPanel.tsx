import { useState, type FormEvent } from 'react'
import { Plug, Plus, X } from 'lucide-react'
import { api, ApiError } from '../lib/api'
import { useApi } from '../lib/useApi'
import { formatDateTime } from '../lib/tenant'
import { Loading } from '../components/AddForm'
import { FormError } from '../components/Form'

/*
 * "Connect to your ERP's API": the other direction from API keys. OEM Dealer
 * Hub calls their ERP itself, for an ERP that can't call us. Any REST API
 * that speaks JSON: for each sync they say which path to call, where the
 * list is in the reply, and where each of our fields is in an item (dot
 * paths like "Customer.No"). "Try it" shows the first page and what each
 * item would do, before anything changes.
 *
 * The token is never sent back to the browser, only whether one is saved.
 */

type Pull = 'order_status' | 'prices' | 'machines'
type SyncName = 'orders_push' | Pull

interface StatusPair {
  erp: string
  ours: string
}

interface PullSettings {
  on: boolean
  path: string | null
  list: string | null
  next: string | null
  fields: Record<string, string | null>
  statuses?: StatusPair[]
  match?: 'erp_ref' | 'number'
  price_list_id?: number | null
  add_models?: boolean
}

interface Syncs {
  orders_push: { on: boolean; path: string | null; ref_field: string | null }
  order_status: PullSettings
  prices: PullSettings
  machines: PullSettings
}

interface Connection {
  base_url: string
  auth_header: string
  auth_prefix: string | null
  token_set: boolean
  token_hint: string | null
  syncs: Syncs
  active: boolean
  last_since: Record<Pull, string | null>
}

interface Run {
  id: number
  sync: SyncName
  sales_order: { id: number; number: string } | null
  status: 'running' | 'ok' | 'partial' | 'failed'
  attempts: number
  counts: Record<string, number> | null
  message: string | null
  problems: string[]
  created_at: string | null
  finished_at: string | null
}

interface ErpData {
  connection: Connection | null
  syncs: Record<SyncName, string>
  fields: Record<Pull, Record<string, boolean>>
  order_statuses: string[]
  machine_statuses: string[]
  price_lists: { id: number; name: string }[]
  runs: Run[]
}

interface Preview {
  ok: boolean
  message?: string
  found?: number
  sample?: string | null
  rows?: { fields: Record<string, string | null>; outcome: string }[]
}

const FIELD_LABELS: Record<string, string> = {
  order: 'Order number',
  status: 'Status',
  erp_ref: "Your ERP's order number",
  cancel_reason: 'Why it was cancelled',
  item: 'Item number',
  price: 'Price',
  serial_no: 'Serial number',
  model: 'Model (your item number)',
  model_name: 'Model (by name)',
  dealer_code: 'Dealer (your customer number)',
  production_no: 'Production number',
  built_at: 'Built on',
  shipped_at: 'Shipped on',
}

const PULL_HELP: Record<Pull, string> = {
  order_status: 'Orders that started here are moved to match your ERP: into production, shipped, delivered or cancelled. An order your ERP jumps ahead (say, straight to delivered) passes through the steps in between.',
  prices: "Prices go onto one price list, matched by item number. Items with no matching model or option are skipped, since your ERP probably has more items than you sell through dealers. One bad price and nothing is saved, so a list is never half updated.",
  machines: 'Machines are matched by serial number: new ones are added, known ones updated. Each one is handled on its own, so one bad row doesn\'t hold up the rest. Leave the dealer field empty to never change a machine\'s dealer.',
}

const RUN_LABELS: Record<SyncName, string> = {
  orders_push: 'Order sent',
  order_status: 'Order status',
  prices: 'Prices',
  machines: 'Machines',
}

function emptyPull(fields: Record<string, boolean>): PullSettings {
  return { on: false, path: '', list: '', next: '', fields: Object.fromEntries(Object.keys(fields).map((f) => [f, ''])), statuses: [] }
}

function asApiError(e: unknown): ApiError {
  return e instanceof ApiError ? e : new ApiError(0, { message: 'Could not reach OEM Dealer Hub.' })
}

const words = (s: string) => s.replace(/_/g, ' ')

export function ErpConnectionPanel({ slug }: { slug: string }) {
  const { data, error, reload } = useApi<ErpData>(`/m/${slug}/integrations/erp`)
  const [editing, setEditing] = useState(false)

  return (
    <section className="panel" aria-labelledby="erp-title">
      <div className="card-head">
        <div>
          <h2 id="erp-title">
            <Plug size={16} aria-hidden className="h-icon" /> Connect to your ERP's API
          </h2>
          <p>
            If your ERP has an API but can't call ours, we'll call yours instead: send it each new sales order, and fetch order status, prices and machines every 15 minutes. It works with any REST API that answers in JSON.
          </p>
        </div>
        {data && !editing && (
          <button className={data.connection ? 'secondary' : 'primary'} onClick={() => setEditing(true)}>
            {data.connection ? 'Change settings' : 'Set up'}
          </button>
        )}
      </div>

      {!data ? (
        <Loading error={error} />
      ) : editing ? (
        <ErpForm
          slug={slug}
          data={data}
          onSaved={reload}
          onClose={() => setEditing(false)}
        />
      ) : data.connection ? (
        <Summary slug={slug} data={data} connection={data.connection} onChange={reload} />
      ) : (
        <p className="muted">Not connected.</p>
      )}

      {data && data.runs.length > 0 && <Runs slug={slug} runs={data.runs} onChange={reload} />}
    </section>
  )
}

// ---- What's connected, with Sync now ----

function Summary({ slug, data, connection, onChange }: { slug: string; data: ErpData; connection: Connection; onChange: () => void }) {
  const [note, setNote] = useState<string | null>(null)

  async function syncNow(sync: Pull, full = false) {
    if (full && !window.confirm('Fetch everything from your ERP again, not just what changed since last time?')) return
    setNote(null)
    try {
      const r = await api.post<{ message: string }>(`/m/${slug}/integrations/erp/sync/${sync}`, { full })
      setNote(r.message)
      onChange()
    } catch (err) {
      setNote(asApiError(err).message)
    }
  }

  async function disconnect() {
    if (!window.confirm('Disconnect from your ERP? Nothing more is sent or fetched. What was already synced stays.')) return
    await api.delete(`/m/${slug}/integrations/erp`).catch(() => {})
    onChange()
  }

  const syncs: SyncName[] = ['orders_push', 'order_status', 'prices', 'machines']
  return (
    <>
      <p>
        <code className="hook-url">{connection.base_url}</code>{' '}
        <span className="muted small">
          · signs in with {connection.auth_header}
          {connection.token_set ? ` (token ${connection.token_hint ?? 'saved'})` : ', no token'}
        </span>
      </p>
      {note && (
        <p className="notice" role="status">
          {note}
        </p>
      )}
      <ul className="hooks">
        {syncs.map((s) => {
          const on = connection.syncs[s]?.on
          return (
            <li key={s} className={on ? undefined : 'is-paused'}>
              <div className="hook-main">
                <strong>{data.syncs[s]}</strong>
                {!on && <span className="badge badge-suspended">Off</span>}
                {on && s !== 'orders_push' && (
                  <span className="muted small">{connection.last_since[s as Pull] ? `Changes since ${formatDateTime(connection.last_since[s as Pull])}` : 'Next run fetches everything'}</span>
                )}
              </div>
              {on && s !== 'orders_push' && (
                <div className="hook-actions">
                  <button className="secondary" onClick={() => syncNow(s as Pull)}>
                    Sync now
                  </button>
                  <button className="link" onClick={() => syncNow(s as Pull, true)}>
                    Fetch everything again
                  </button>
                </div>
              )}
            </li>
          )
        })}
      </ul>
      <button className="link link-danger" onClick={disconnect}>
        Disconnect
      </button>
    </>
  )
}

// ---- The settings ----

/** Stays open after saving, so each sync can be tried straight away. */
function ErpForm({ slug, data, onSaved, onClose }: { slug: string; data: ErpData; onSaved: () => void; onClose: () => void }) {
  const c = data.connection
  const [baseUrl, setBaseUrl] = useState(c?.base_url ?? '')
  const [header, setHeader] = useState(c?.auth_header ?? 'Authorization')
  const [prefix, setPrefix] = useState(c ? (c.auth_prefix ?? '') : 'Bearer')
  const [token, setToken] = useState('')
  const [syncs, setSyncs] = useState<Syncs>(() => ({
    orders_push: c?.syncs.orders_push ?? { on: false, path: '', ref_field: '' },
    order_status: { match: 'erp_ref', ...emptyPull(data.fields.order_status), ...c?.syncs.order_status },
    prices: { price_list_id: data.price_lists[0]?.id ?? null, ...emptyPull(data.fields.prices), ...c?.syncs.prices },
    machines: { ...emptyPull(data.fields.machines), ...c?.syncs.machines },
  }))
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const [testNote, setTestNote] = useState<{ ok: boolean; message: string } | null>(null)
  const [justSaved, setJustSaved] = useState(false)

  // Syncs changed since the last save: "Try it" runs what's saved, so it waits for a save.
  const [dirty, setDirty] = useState<Set<keyof Syncs>>(() => new Set(c ? [] : (['orders_push', 'order_status', 'prices', 'machines'] as (keyof Syncs)[])))
  const setSync = <K extends keyof Syncs>(name: K, patch: Partial<Syncs[K]>) => {
    setSyncs((s) => ({ ...s, [name]: { ...s[name], ...patch } }))
    setDirty((d) => new Set(d).add(name))
  }

  async function save(): Promise<boolean> {
    setBusy(true)
    setError(null)
    setJustSaved(false)
    try {
      await api.post(`/m/${slug}/integrations/erp`, { base_url: baseUrl.trim(), auth_header: header.trim(), auth_prefix: prefix.trim(), token: token.trim(), syncs })
      setToken('')
      setDirty(new Set())
      setJustSaved(true)
      onSaved()
      return true
    } catch (err) {
      setError(asApiError(err))
      return false
    } finally {
      setBusy(false)
    }
  }

  async function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    await save()
  }

  async function test() {
    setTestNote(null)
    if (!(await save())) return
    try {
      setTestNote(await api.post<{ ok: boolean; message: string }>(`/m/${slug}/integrations/erp/test`))
    } catch (err) {
      setTestNote({ ok: false, message: asApiError(err).message })
    }
  }

  const err = (name: string) => error?.field(name)

  return (
    <form className="integration-form erp-form" onSubmit={onSubmit} noValidate>
      <FormError error={error} />

      <fieldset className="erp-group">
        <legend>Where your ERP is</legend>
        <div className="field">
          <label htmlFor="erp-url">Its API address</label>
          <input id="erp-url" type="url" value={baseUrl} onChange={(e) => setBaseUrl(e.target.value)} maxLength={500} placeholder="https://erp.yourcompany.com/api/v2" aria-invalid={!!err('base_url')} required />
          <p className="hint">Each sync's path is added to the end of this. It must be https and reachable from the internet.</p>
          {err('base_url') && <p className="field-error">{err('base_url')}</p>}
        </div>
        <div className="erp-row">
          <div className="field">
            <label htmlFor="erp-header">Sign-in header</label>
            <input id="erp-header" value={header} onChange={(e) => setHeader(e.target.value)} maxLength={60} aria-invalid={!!err('auth_header')} />
            {err('auth_header') && <p className="field-error">{err('auth_header')}</p>}
          </div>
          <div className="field">
            <label htmlFor="erp-prefix">Before the token</label>
            <input id="erp-prefix" value={prefix} onChange={(e) => setPrefix(e.target.value)} maxLength={30} placeholder="Nothing" aria-invalid={!!err('auth_prefix')} />
            {err('auth_prefix') && <p className="field-error">{err('auth_prefix')}</p>}
          </div>
        </div>
        <p className="hint">
          Most ERPs want <code>Authorization: Bearer &lt;token&gt;</code>. For one that wants <code>X-API-Key: &lt;token&gt;</code>, put X-API-Key as the header and leave "Before the token" empty.
        </p>
        <div className="field">
          <label htmlFor="erp-token">Token or API key</label>
          <input id="erp-token" type="password" autoComplete="off" value={token} onChange={(e) => setToken(e.target.value)} maxLength={4000} placeholder={c?.token_set ? `Saved${c.token_hint ? ` (${c.token_hint})` : ''}. Leave empty to keep it.` : 'From your ERP'} aria-invalid={!!err('token')} />
          <p className="hint">It's stored encrypted and never shown again. Give us a key that can only do what the syncs below need.</p>
          {err('token') && <p className="field-error">{err('token')}</p>}
        </div>
        <div className="form-actions">
          <button type="button" className="secondary" onClick={test} disabled={busy}>
            Save and test the connection
          </button>
          {testNote && (
            <span className={testNote.ok ? 'notice-inline' : 'field-error'} role="status">
              {testNote.message}
            </span>
          )}
        </div>
      </fieldset>

      <fieldset className="erp-group">
        <legend>
          <SyncSwitch label={data.syncs.orders_push} on={syncs.orders_push.on} onChange={(on) => setSync('orders_push', { on })} />
        </legend>
        <p className="hint">The moment a quote is won, we POST the new sales order to your ERP as JSON (the same order the API and webhooks give). If your ERP doesn't answer, we try again for about 9 hours.</p>
        {syncs.orders_push.on && (
          <div className="erp-row">
            <TextField id="push-path" label="Path to POST to" value={syncs.orders_push.path} onChange={(path) => setSync('orders_push', { path })} placeholder="/salesOrders" error={err('syncs.orders_push.path')} />
            <TextField id="push-ref" label="Its order number, in the reply" value={syncs.orders_push.ref_field} onChange={(ref_field) => setSync('orders_push', { ref_field })} placeholder="e.g. id or data.number" hint="We save it on the order." error={err('syncs.orders_push.ref_field')} />
          </div>
        )}
      </fieldset>

      {(['order_status', 'prices', 'machines'] as Pull[]).map((p) => (
        <PullFields key={p} slug={slug} name={p} data={data} value={syncs[p]} onChange={(patch) => setSync(p, patch)} error={error} saved={!dirty.has(p)} />
      ))}

      <div className="form-actions">
        <button className="primary" disabled={busy}>
          {busy ? 'Saving…' : 'Save'}
        </button>
        {justSaved && (
          <span className="notice-inline" role="status">
            Saved
          </span>
        )}
        <button type="button" className="link" onClick={onClose}>
          {dirty.size > 0 && data.connection ? 'Close without saving' : 'Close'}
        </button>
      </div>
    </form>
  )
}

function SyncSwitch({ label, on, onChange }: { label: string; on: boolean; onChange: (on: boolean) => void }) {
  return (
    <label className="switch">
      <input type="checkbox" role="switch" checked={on} onChange={(e) => onChange(e.target.checked)} />
      <span className="switch-track" aria-hidden />
      <strong>{label}</strong>
    </label>
  )
}

function TextField({ id, label, value, onChange, placeholder, hint, error }: { id: string; label: string; value: string | null | undefined; onChange: (v: string) => void; placeholder?: string; hint?: string; error?: string }) {
  return (
    <div className="field">
      <label htmlFor={id}>{label}</label>
      <input id={id} className="mono" value={value ?? ''} onChange={(e) => onChange(e.target.value)} placeholder={placeholder} maxLength={500} aria-invalid={!!error} />
      {hint && <p className="hint">{hint}</p>}
      {error && <p className="field-error">{error}</p>}
    </div>
  )
}

function PullFields({ slug, name, data, value, onChange, error, saved }: { slug: string; name: Pull; data: ErpData; value: PullSettings; onChange: (patch: Partial<PullSettings>) => void; error: ApiError | null; saved: boolean }) {
  const [preview, setPreview] = useState<Preview | null>(null)
  const [trying, setTrying] = useState(false)
  const err = (f: string) => error?.field(`syncs.${name}.${f}`)
  const statuses = name === 'order_status' ? data.order_statuses : name === 'machines' ? data.machine_statuses : null

  async function tryIt() {
    setTrying(true)
    setPreview(null)
    try {
      setPreview(await api.post<Preview>(`/m/${slug}/integrations/erp/preview/${name}`))
    } catch (e) {
      setPreview({ ok: false, message: asApiError(e).message })
    } finally {
      setTrying(false)
    }
  }

  const fields = Object.entries(data.fields[name]).filter(([f]) => !(name === 'order_status' && f === 'erp_ref' && value.match !== 'number'))

  return (
    <fieldset className="erp-group">
      <legend>
        <SyncSwitch label={data.syncs[name]} on={value.on} onChange={(on) => onChange({ on })} />
      </legend>
      <p className="hint">{PULL_HELP[name]}</p>
      {value.on && (
        <>
          <TextField
            id={`${name}-path`}
            label="Path to GET"
            value={value.path}
            onChange={(path) => onChange({ path })}
            placeholder={`/${name === 'order_status' ? 'salesOrders' : name}?modifiedSince={since}`}
            hint="{since} is replaced with when the last sync ran (ISO 8601, in UTC), so only changes are fetched. The first run, and Fetch everything again, send 1970-01-01T00:00:00Z."
            error={err('path')}
          />
          <div className="erp-row">
            <TextField id={`${name}-list`} label="Where the list is in the reply" value={value.list} onChange={(list) => onChange({ list })} placeholder="Empty if the reply is the list; e.g. value or data.items" error={err('list')} />
            <TextField id={`${name}-next`} label="Next page link (optional)" value={value.next} onChange={(next) => onChange({ next })} placeholder="e.g. @odata.nextLink or links.next" error={err('next')} />
          </div>

          {name === 'order_status' && (
            <div className="field">
              <label htmlFor="match">Your ERP knows our orders by</label>
              <select id="match" value={value.match ?? 'erp_ref'} onChange={(e) => onChange({ match: e.target.value as 'erp_ref' | 'number' })}>
                <option value="erp_ref">Its own order number (the one saved on our order)</option>
                <option value="number">Our number (SO-1001)</option>
              </select>
            </div>
          )}
          {name === 'prices' && (
            <div className="field">
              <label htmlFor="price-list">Price list</label>
              <select id="price-list" value={value.price_list_id ?? ''} onChange={(e) => onChange({ price_list_id: e.target.value ? Number(e.target.value) : null })} aria-invalid={!!err('price_list_id')}>
                <option value="">Choose…</option>
                {data.price_lists.map((l) => (
                  <option key={l.id} value={l.id}>
                    {l.name}
                  </option>
                ))}
              </select>
              {err('price_list_id') && <p className="field-error">{err('price_list_id')}</p>}
            </div>
          )}

          <h4 className="erp-subhead">Where each field is in an item</h4>
          <div className="map-rows">
            {fields.map(([f, required]) => (
              <label key={f} className="map-row">
                <span>
                  {FIELD_LABELS[f] ?? words(f)}
                  {required && <span className="muted small"> · needed</span>}
                  {err(`fields.${f}`) && <span className="block field-error">{err(`fields.${f}`)}</span>}
                </span>
                <input value={value.fields[f] ?? ''} onChange={(e) => onChange({ fields: { ...value.fields, [f]: e.target.value } })} maxLength={200} placeholder={required ? 'e.g. No' : 'Not used'} aria-label={`Where ${FIELD_LABELS[f] ?? words(f)} is`} />
              </label>
            ))}
          </div>

          {name === 'machines' && (
            <div className="field">
              <label className="check">
                <input type="checkbox" checked={!!value.add_models} onChange={(e) => onChange({ add_models: e.target.checked })} />
                Add models we don't have yet
              </label>
              <p className="hint">
                A model's ERP number also covers longer item numbers that start with it (1233A covers 1233A_2001_SS). A machine whose model still isn't found is normally skipped. With this on, its model is added instead,
                named from "Model (by name)" or else the item number, in a product line called "From your ERP" that you can tidy up later. Leave it off if your ERP lists many items you don't sell through dealers.
              </p>
            </div>
          )}

          {statuses && <StatusPairs pairs={value.statuses ?? []} ours={statuses} onChange={(s) => onChange({ statuses: s })} />}

          <div className="form-actions">
            <button type="button" className="secondary" onClick={tryIt} disabled={trying || !saved} title={saved ? undefined : 'Save first'}>
              {trying ? 'Fetching…' : 'Try it'}
            </button>
            <span className="muted small">{saved ? 'Fetches the first page and shows what would happen. Nothing changes.' : 'Save to try your changes.'}</span>
          </div>
          {preview && <PreviewResult preview={preview} />}
        </>
      )}
    </fieldset>
  )
}

/** Their status words to ours. Words that already match ours (any case) need no row. */
function StatusPairs({ pairs, ours, onChange }: { pairs: StatusPair[]; ours: string[]; onChange: (p: StatusPair[]) => void }) {
  const set = (i: number, patch: Partial<StatusPair>) => onChange(pairs.map((p, j) => (j === i ? { ...p, ...patch } : p)))
  return (
    <div className="erp-statuses">
      <h4 className="erp-subhead">Match your ERP's statuses to ours</h4>
      <p className="hint">Anything not matched here, and not already one of ours ({ours.map(words).join(', ')}), is left alone and listed in the sync's problems.</p>
      {pairs.map((p, i) => (
        <div key={i} className="erp-pair">
          <input value={p.erp} onChange={(e) => set(i, { erp: e.target.value })} maxLength={100} placeholder="Your ERP says" aria-label="Your ERP's status" />
          <span aria-hidden>→</span>
          <select value={p.ours} onChange={(e) => set(i, { ours: e.target.value })} aria-label="Our status">
            {ours.map((s) => (
              <option key={s} value={s}>
                {words(s)}
              </option>
            ))}
          </select>
          <button type="button" className="link" onClick={() => onChange(pairs.filter((_, j) => j !== i))} aria-label="Remove this match">
            <X size={16} aria-hidden />
          </button>
        </div>
      ))}
      <button type="button" className="link" onClick={() => onChange([...pairs, { erp: '', ours: ours[0] }])}>
        <Plus size={14} aria-hidden /> Add a match
      </button>
    </div>
  )
}

function PreviewResult({ preview }: { preview: Preview }) {
  if (!preview.ok) {
    return (
      <p className="form-error" role="alert">
        {preview.message}
      </p>
    )
  }
  return (
    <div className="erp-preview" role="status">
      <p>
        <strong>
          Found {preview.found} item{preview.found === 1 ? '' : 's'} on the first page.
        </strong>
      </p>
      {preview.rows && preview.rows.length > 0 && (
        <div className="table-wrap flat">
          <table>
            <thead>
              <tr>
                <th>What we read</th>
                <th>What would happen</th>
              </tr>
            </thead>
            <tbody>
              {preview.rows.map((r, i) => (
                <tr key={i}>
                  <td className="wrap small">
                    {Object.keys(r.fields).length === 0 ? (
                      <span className="muted">Nothing: check the field paths</span>
                    ) : (
                      Object.entries(r.fields).map(([k, v]) => (
                        <span key={k} className="block">
                          {FIELD_LABELS[k] ?? words(k)}: <code>{v ?? 'null'}</code>
                        </span>
                      ))
                    )}
                  </td>
                  <td className="wrap">{r.outcome}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {preview.sample && (
        <details>
          <summary>The first item, as your ERP sent it</summary>
          <pre className="erp-sample">{preview.sample}</pre>
        </details>
      )}
    </div>
  )
}

// ---- Recent syncs ----

function Runs({ slug, runs, onChange }: { slug: string; runs: Run[]; onChange: () => void }) {
  async function retry(r: Run) {
    await api.post(`/m/${slug}/integrations/erp/runs/${r.id}/retry`).catch(() => {})
    onChange()
  }

  const badge = (s: Run['status']) => (s === 'ok' ? ['delivered', 'Done'] : s === 'partial' ? ['pending', 'Done, with problems'] : s === 'failed' ? ['failed', 'Failed'] : ['pending', 'Running'])

  return (
    <>
      <h3 className="subhead">Recent syncs</h3>
      <div className="table-wrap flat">
        <table>
          <thead>
            <tr>
              <th>When</th>
              <th>What</th>
              <th>Result</th>
              <th>
                <span className="sr-only">Retry</span>
              </th>
            </tr>
          </thead>
          <tbody>
            {runs.map((r) => {
              const [cls, label] = badge(r.status)
              return (
                <tr key={r.id}>
                  <td>{formatDateTime(r.created_at)}</td>
                  <td>
                    {RUN_LABELS[r.sync] ?? r.sync}
                    {r.sales_order && <span className="block muted small">{r.sales_order.number}</span>}
                  </td>
                  <td className="wrap">
                    <span className={`badge badge-call-${cls}`}>{label}</span>
                    {r.message && <span className="block small">{r.message}</span>}
                    {r.attempts > 1 && <span className="block muted small">{r.attempts} tries</span>}
                    {r.problems.length > 0 && (
                      <details className="small">
                        <summary>
                          {r.problems.length} problem{r.problems.length === 1 ? '' : 's'}
                        </summary>
                        <ul className="erp-problems">
                          {r.problems.map((p, i) => (
                            <li key={i}>{p}</li>
                          ))}
                        </ul>
                      </details>
                    )}
                  </td>
                  <td className="num">
                    {r.sync === 'orders_push' && r.status === 'failed' && (
                      <button className="link" onClick={() => retry(r)}>
                        Send again
                      </button>
                    )}
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
    </>
  )
}
