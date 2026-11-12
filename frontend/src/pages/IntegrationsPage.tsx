import { useState, type FormEvent } from 'react'
import { Check, Copy, KeyRound, Link2, Webhook as WebhookIcon } from 'lucide-react'
import { api, ApiError } from '../lib/api'
import { useApi } from '../lib/useApi'
import { formatDateTime, useSlug } from '../lib/tenant'
import { Loading } from '../components/AddForm'
import { FormError } from '../components/Form'

/*
 * Connecting a manufacturer's ERP, self-serve (owner and admin, Pro):
 * API keys their system reads with, webhooks we call when something
 * happens, and matching dealers, models and options to the ERP's numbers.
 * A key or a signing secret is shown once, right after it's made.
 */

interface ApiKeyRow {
  id: number
  name: string
  prefix: string
  abilities: string[]
  last_used_at: string | null
  created_at: string | null
}

interface WebhookRow {
  id: number
  url: string
  events: string[]
  active: boolean
  created_at: string | null
}

interface DeliveryRow {
  id: number
  uuid: string
  webhook_url: string | null
  event: string
  status: 'pending' | 'delivered' | 'failed'
  attempts: number
  response_code: number | null
  error: string | null
  created_at: string | null
  delivered_at: string | null
}

interface Integrations {
  api_base: string
  abilities: Record<string, string>
  events: Record<string, string>
  api_keys: ApiKeyRow[]
  webhooks: WebhookRow[]
  deliveries: DeliveryRow[]
  erp_owns: { order_status: boolean; prices: boolean }
}

function asApiError(e: unknown): ApiError {
  return e instanceof ApiError ? e : new ApiError(0, { message: 'Could not reach OEM Dealer Hub.' })
}

export function IntegrationsPage() {
  const slug = useSlug()
  const { data, error, reload } = useApi<Integrations>(`/m/${slug}/integrations`)
  // A key or secret just made: shown once, until dismissed.
  const [reveal, setReveal] = useState<{ title: string; value: string; help: string } | null>(null)

  if (!data) return <Loading error={error} />

  return (
    <>
      <div className="page-head">
        <h1>Integrations</h1>
      </div>
      <p className="lede">
        Connect your ERP, or any system of yours, to OEM Dealer Hub. Your IT team, or a tool like Power Automate or Make, can do it from here: no meeting needed. If you'd like us to set it up for you, that's quoted separately.
      </p>

      {reveal && <Reveal {...reveal} onDone={() => setReveal(null)} />}

      <ApiKeys slug={slug} data={data} onChange={reload} onMade={(key) => setReveal({ title: 'Your new API key', value: key, help: "Copy it now and store it somewhere safe, like your ERP's settings or a password manager. It won't be shown again. If it's lost, revoke it and make another." })} />
      <Webhooks slug={slug} data={data} onChange={reload} onSecret={(secret) => setReveal({ title: 'Your signing secret', value: secret, help: 'Your system uses this to check that each call really came from OEM Dealer Hub (see "For your IT team" below). Copy it now, because it won\'t be shown again. You can make a new one at any time.' })} />
      <Mapping slug={slug} />
      <WhoOwns slug={slug} owns={data.erp_owns} onChange={reload} />
      <ForYourIt apiBase={data.api_base} events={data.events} />
    </>
  )
}

/** A key or secret, shown once with a copy button. */
function Reveal({ title, value, help, onDone }: { title: string; value: string; help: string; onDone: () => void }) {
  const [copied, setCopied] = useState(false)
  return (
    <section className="callout callout-ok reveal" role="status">
      <strong>{title}</strong>
      <p>{help}</p>
      <div className="reveal-row">
        <code>{value}</code>
        <button
          className="secondary"
          onClick={() =>
            navigator.clipboard?.writeText(value).then(
              () => setCopied(true),
              () => setCopied(false),
            )
          }
        >
          {copied ? <Check size={16} aria-hidden /> : <Copy size={16} aria-hidden />} {copied ? 'Copied' : 'Copy'}
        </button>
        <button className="link" onClick={onDone}>
          I've saved it
        </button>
      </div>
    </section>
  )
}

// ---- API keys ----

function ApiKeys({ slug, data, onChange, onMade }: { slug: string; data: Integrations; onChange: () => void; onMade: (key: string) => void }) {
  const [adding, setAdding] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)

  async function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const f = new FormData(e.currentTarget)
    setBusy(true)
    setError(null)
    try {
      const made = await api.post<ApiKeyRow & { key: string }>(`/m/${slug}/integrations/api-keys`, { name: String(f.get('name') ?? '').trim(), abilities: f.getAll('abilities') })
      setAdding(false)
      onMade(made.key)
      onChange()
    } catch (err) {
      setError(asApiError(err))
    } finally {
      setBusy(false)
    }
  }

  async function revoke(k: ApiKeyRow) {
    if (!window.confirm(`Revoke "${k.name}"? Anything using it stops working straight away.`)) return
    await api.delete(`/m/${slug}/integrations/api-keys/${k.id}`).catch(() => {})
    onChange()
  }

  return (
    <section className="panel" aria-labelledby="keys-title">
      <div className="card-head">
        <div>
          <h2 id="keys-title">
            <KeyRound size={16} aria-hidden className="h-icon" /> API keys
          </h2>
          <p>For your system to read from OEM Dealer Hub. Make one per system, so you can revoke one without touching the others.</p>
        </div>
        {!adding && (
          <button className="primary" onClick={() => setAdding(true)}>
            New key
          </button>
        )}
      </div>

      {adding && (
        <form className="integration-form" onSubmit={onSubmit} noValidate>
          <FormError error={error} />
          <div className="field">
            <label htmlFor="key-name">What's it for?</label>
            <input id="key-name" name="name" maxLength={100} placeholder="e.g. Nightly ERP sync" aria-invalid={!!error?.field('name')} required />
            {error?.field('name') && <p className="field-error">{error.field('name')}</p>}
          </div>
          <fieldset className="choice-group">
            <legend>It can</legend>
            {Object.entries(data.abilities).map(([key, label]) => (
              <label key={key} className="check">
                <input type="checkbox" name="abilities" value={key} defaultChecked />
                {label}
              </label>
            ))}
            {error?.field('abilities') && <p className="field-error">{error.field('abilities')}</p>}
          </fieldset>
          <div className="form-actions">
            <button className="primary" disabled={busy}>
              {busy ? 'Making…' : 'Make the key'}
            </button>
            <button type="button" className="link" onClick={() => setAdding(false)}>
              Cancel
            </button>
          </div>
        </form>
      )}

      {data.api_keys.length === 0 ? (
        !adding && <p className="muted">No keys yet.</p>
      ) : (
        <div className="table-wrap flat">
          <table>
            <thead>
              <tr>
                <th>Name</th>
                <th>Key</th>
                <th>Can</th>
                <th>Last used</th>
                <th>
                  <span className="sr-only">Revoke</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {data.api_keys.map((k) => (
                <tr key={k.id}>
                  <td>{k.name}</td>
                  <td>
                    <code>{k.prefix}…</code>
                  </td>
                  <td>{k.abilities.map((a) => data.abilities[a] ?? a).join(', ')}</td>
                  <td>{k.last_used_at ? formatDateTime(k.last_used_at) : <span className="muted">Never</span>}</td>
                  <td className="num">
                    <button className="link link-danger" onClick={() => revoke(k)}>
                      Revoke
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )
}

// ---- Webhooks ----

function Webhooks({ slug, data, onChange, onSecret }: { slug: string; data: Integrations; onChange: () => void; onSecret: (secret: string) => void }) {
  const [adding, setAdding] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const [note, setNote] = useState<string | null>(null)

  async function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const f = new FormData(e.currentTarget)
    setBusy(true)
    setError(null)
    try {
      const made = await api.post<WebhookRow & { secret: string }>(`/m/${slug}/integrations/webhooks`, { url: String(f.get('url') ?? '').trim(), events: f.getAll('events') })
      setAdding(false)
      onSecret(made.secret)
      onChange()
    } catch (err) {
      setError(asApiError(err))
    } finally {
      setBusy(false)
    }
  }

  async function act(w: WebhookRow, what: 'test' | 'rotate' | 'pause' | 'resume' | 'delete') {
    setNote(null)
    try {
      if (what === 'delete') {
        if (!window.confirm(`Stop sending to ${w.url} and remove it?`)) return
        await api.delete(`/m/${slug}/integrations/webhooks/${w.id}`)
      } else if (what === 'rotate') {
        if (!window.confirm('Make a new signing secret? The old one stops working straight away, so update your system right after.')) return
        const r = await api.post<{ secret: string }>(`/m/${slug}/integrations/webhooks/${w.id}/rotate`)
        onSecret(r.secret)
      } else if (what === 'test') {
        await api.post(`/m/${slug}/integrations/webhooks/${w.id}/test`)
        setNote(`Test sent to ${w.url}. It shows under Recent calls below.`)
      } else {
        await api.post(`/m/${slug}/integrations/webhooks/${w.id}`, { active: what === 'resume' })
      }
      onChange()
    } catch (err) {
      setNote(asApiError(err).message)
    }
  }

  async function retry(d: DeliveryRow) {
    await api.post(`/m/${slug}/integrations/deliveries/${d.id}/retry`).catch(() => {})
    onChange()
  }

  return (
    <section className="panel" aria-labelledby="hooks-title">
      <div className="card-head">
        <div>
          <h2 id="hooks-title">
            <WebhookIcon size={16} aria-hidden className="h-icon" /> Webhooks
          </h2>
          <p>We call your address the moment something happens, so your ERP doesn't have to keep asking.</p>
        </div>
        {!adding && (
          <button className="primary" onClick={() => setAdding(true)}>
            Add webhook
          </button>
        )}
      </div>

      {adding && (
        <form className="integration-form" onSubmit={onSubmit} noValidate>
          <FormError error={error} />
          <div className="field">
            <label htmlFor="hook-url">Your address</label>
            <input id="hook-url" name="url" type="url" maxLength={500} placeholder="https://erp.yourcompany.com/oem-dealer-hub" aria-invalid={!!error?.field('url')} required />
            {error?.field('url') && <p className="field-error">{error.field('url')}</p>}
          </div>
          <fieldset className="choice-group">
            <legend>Tell it when</legend>
            {Object.entries(data.events).map(([key, label]) => (
              <label key={key} className="check">
                <input type="checkbox" name="events" value={key} defaultChecked />
                {label}
              </label>
            ))}
            {error?.field('events') && <p className="field-error">{error.field('events')}</p>}
          </fieldset>
          <div className="form-actions">
            <button className="primary" disabled={busy}>
              {busy ? 'Adding…' : 'Add webhook'}
            </button>
            <button type="button" className="link" onClick={() => setAdding(false)}>
              Cancel
            </button>
          </div>
        </form>
      )}

      {note && (
        <p className="notice" role="status">
          {note}
        </p>
      )}

      {data.webhooks.length === 0 ? (
        !adding && <p className="muted">No webhooks yet.</p>
      ) : (
        <ul className="hooks">
          {data.webhooks.map((w) => (
            <li key={w.id} className={w.active ? undefined : 'is-paused'}>
              <div className="hook-main">
                <code className="hook-url">{w.url}</code>
                {!w.active && <span className="badge badge-suspended">Paused</span>}
                <span className="muted small">{w.events.map((e) => e.replace('sales_order.', 'sales order ').replace('_', ' ')).join(' · ')}</span>
              </div>
              <div className="hook-actions">
                <button className="secondary" onClick={() => act(w, 'test')}>
                  Send a test
                </button>
                <button className="link" onClick={() => act(w, w.active ? 'pause' : 'resume')}>
                  {w.active ? 'Pause' : 'Resume'}
                </button>
                <button className="link" onClick={() => act(w, 'rotate')}>
                  New secret
                </button>
                <button className="link link-danger" onClick={() => act(w, 'delete')}>
                  Remove
                </button>
              </div>
            </li>
          ))}
        </ul>
      )}

      {data.deliveries.length > 0 && (
        <>
          <h3 className="subhead">Recent calls</h3>
          <div className="table-wrap flat">
            <table>
              <thead>
                <tr>
                  <th>When</th>
                  <th>What</th>
                  <th>To</th>
                  <th>Result</th>
                  <th>
                    <span className="sr-only">Retry</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                {data.deliveries.map((d) => (
                  <tr key={d.id}>
                    <td>{formatDateTime(d.created_at)}</td>
                    <td>
                      <code>{d.event}</code>
                    </td>
                    <td className="wrap small">{d.webhook_url}</td>
                    <td className="wrap">
                      <span className={`badge badge-call-${d.status}`}>{d.status === 'delivered' ? 'Delivered' : d.status === 'failed' ? 'Failed' : 'Trying'}</span>
                      {d.response_code && <span className="muted small"> {d.response_code}</span>}
                      {d.status !== 'delivered' && d.error && <span className="block muted small">{d.error}</span>}
                      {d.attempts > 1 && <span className="block muted small">{d.attempts} tries</span>}
                    </td>
                    <td className="num">
                      {d.status === 'failed' && (
                        <button className="link" onClick={() => retry(d)}>
                          Send again
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
    </section>
  )
}

// ---- Matching to the ERP's numbers ----

interface MappingData {
  dealers: { id: number; name: string; ref: string | null }[]
  models: { id: number; name: string; ref: string | null; options: { id: number; name: string; ref: string | null }[] }[]
}

function Mapping({ slug }: { slug: string }) {
  const { data, error, reload } = useApi<MappingData>(`/m/${slug}/integrations/mapping`)
  const [busy, setBusy] = useState(false)
  const [saveError, setSaveError] = useState<ApiError | null>(null)
  const [saved, setSaved] = useState(false)

  async function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const f = new FormData(e.currentTarget)
    const pick = (prefix: string) => Object.fromEntries([...f.entries()].filter(([k]) => k.startsWith(prefix)).map(([k, v]) => [k.slice(prefix.length), String(v)]))
    setBusy(true)
    setSaveError(null)
    setSaved(false)
    try {
      await api.post(`/m/${slug}/integrations/mapping`, { dealers: pick('dealer-'), models: pick('model-'), options: pick('option-') })
      setSaved(true)
      reload()
    } catch (err) {
      setSaveError(asApiError(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <section className="panel" aria-labelledby="mapping-title">
      <div className="card-head">
        <div>
          <h2 id="mapping-title">
            <Link2 size={16} aria-hidden className="h-icon" /> Match your ERP's numbers
          </h2>
          <p>The number your ERP uses for each dealer, model and option. They go out with every sales order, so your system knows what's what without guessing from names.</p>
        </div>
      </div>
      {!data ? (
        <Loading error={error} />
      ) : (
        <form onSubmit={onSubmit} noValidate>
          <FormError error={saveError} />
          <details className="mapping-group" open={data.dealers.length <= 15}>
            <summary>
              Dealers <span className="muted">· {data.dealers.filter((d) => d.ref).length} of {data.dealers.length} matched</span>
            </summary>
            <MapRows rows={data.dealers.map((d) => ({ key: `dealer-${d.id}`, name: d.name, ref: d.ref }))} label="Customer number" />
          </details>
          <details className="mapping-group" open={data.models.length <= 15}>
            <summary>
              Models and options <span className="muted">· {data.models.filter((m) => m.ref).length} of {data.models.length} models matched</span>
            </summary>
            <MapRows
              rows={data.models.flatMap((m) => [
                { key: `model-${m.id}`, name: m.name, ref: m.ref },
                ...m.options.map((o) => ({ key: `option-${o.id}`, name: o.name, ref: o.ref, indent: true })),
              ])}
              label="Item number"
            />
          </details>
          <div className="form-actions">
            <button className="primary" disabled={busy}>
              {busy ? 'Saving…' : 'Save numbers'}
            </button>
            {saved && (
              <span className="notice-inline" role="status">
                Saved
              </span>
            )}
          </div>
        </form>
      )}
    </section>
  )
}

function MapRows({ rows, label }: { rows: { key: string; name: string; ref: string | null; indent?: boolean }[]; label: string }) {
  if (rows.length === 0) return <p className="muted small">None yet.</p>
  return (
    <div className="map-rows">
      {rows.map((r) => (
        <label key={r.key} className={`map-row${r.indent ? ' is-option' : ''}`}>
          <span>{r.name}</span>
          <input name={r.key} defaultValue={r.ref ?? ''} maxLength={100} placeholder={label} aria-label={`${label} for ${r.name}`} />
        </label>
      ))}
    </div>
  )
}

// ---- Who's in charge ----

/**
 * One owner per kind of data, so the two systems never disagree. On: that
 * data changes only through the API, and the screens here show it read-only.
 */
function WhoOwns({ slug, owns, onChange }: { slug: string; owns: Integrations['erp_owns']; onChange: () => void }) {
  const [busy, setBusy] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  async function toggle(what: keyof Integrations['erp_owns'], on: boolean) {
    setBusy(what)
    setError(null)
    try {
      await api.post(`/m/${slug}/integrations/settings`, { erp_owns: { [what]: on } })
      onChange()
    } catch (err) {
      setError(asApiError(err).message)
    } finally {
      setBusy(null)
    }
  }

  const rows: { key: keyof Integrations['erp_owns']; title: string; on: string; off: string }[] = [
    {
      key: 'order_status',
      title: 'Sales order status comes from our ERP',
      on: 'Orders move only when your ERP says so, through the API. The board shows where each one is, without move buttons.',
      off: 'Your team moves orders on the Sales orders board. Your ERP can move them too, through the API.',
    },
    {
      key: 'prices',
      title: 'Prices come from our ERP',
      on: 'Prices change only when your ERP sends them. Price lists show them read-only.',
      off: 'Your team types prices into the price lists. Your ERP can send them too, through the API.',
    },
  ]

  return (
    <section className="panel" aria-labelledby="owns-title">
      <div className="card-head">
        <div>
          <h2 id="owns-title">Who's in charge</h2>
          <p>Pick one place to change each of these, so OEM Dealer Hub and your ERP never disagree.</p>
        </div>
      </div>
      {error && (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}
      <ul className="owns">
        {rows.map((r) => (
          <li key={r.key}>
            <label className="switch">
              <input type="checkbox" role="switch" checked={owns[r.key]} disabled={busy === r.key} onChange={(e) => toggle(r.key, e.target.checked)} />
              <span className="switch-track" aria-hidden />
              <span>
                <strong>{r.title}</strong>
                <span className="block muted small">{owns[r.key] ? r.on : r.off}</span>
              </span>
            </label>
          </li>
        ))}
      </ul>
    </section>
  )
}

// ---- For their IT team ----

function ForYourIt({ apiBase, events }: { apiBase: string; events: Record<string, string> }) {
  return (
    <section className="panel" aria-labelledby="it-title">
      <details>
        <summary>
          <h2 id="it-title" className="inline-h2">
            For your IT team
          </h2>
        </summary>
        <div className="it-docs">
          <h3>Reading sales orders</h3>
          <p>
            Send the key as <code>Authorization: Bearer odh_…</code>. Up to 120 requests a minute per key.
          </p>
          <pre>
            {`GET ${apiBase}/sales-orders?updated_since=2026-11-01T00:00:00Z&page=1
GET ${apiBase}/sales-orders?status=received
GET ${apiBase}/sales-orders/SO-1001`}
          </pre>
          <p>Lists are oldest change first, 100 to a page. Keep the newest updated_at you've seen and ask for anything since. URL-encode the date, because a "+" in it must be sent as %2B.</p>

          <h3>Sending things back</h3>
          <p>Each needs its own ability on the key. Errors come back as 422 with a message per field, in plain words.</p>
          <pre>
            {`GET   ${apiBase}/catalog
      Dealers (with customer numbers), models and options (with item numbers), price lists.

PATCH ${apiBase}/sales-orders/SO-1001
      { "erp_ref": "SO-55120", "status": "in_production" }
      Status: received → in_production → shipped → delivered, or "cancelled" with a "cancel_reason".

POST  ${apiBase}/machines
      { "machines": [ { "serial_no": "AS4-30001", "model": "ITEM-S400",
                        "dealer_code": "C-1042", "status": "inventory" } ] }
      Up to 500. Matched by serial: new ones added, known ones updated. Each gets its own result.
      Leave dealer_code out to keep the dealer; null sends it back to the factory.

POST  ${apiBase}/prices
      { "price_list": 3, "prices": [ { "item": "ITEM-S400", "price": "48500.00" },
                                     { "item": "OPT-TILT", "price": null } ] }
      By your item numbers. All or nothing. null = price on request.`}
          </pre>
          <p>
            Webhook calls carry <code>"source": "api"</code> when the change came in through a key, so your ERP can ignore the echo of its own update.
          </p>

          <h3>Webhooks</h3>
          <p>
            We POST JSON to your address: <code>{'{ "id", "event", "created_at", "manufacturer", "data" }'}</code>. The data is the same sales order you'd get from the API. Answer with any 2xx within 10 seconds. If you don't, we try again after 1 minute, 5 minutes, 30 minutes, 2 hours and 6 hours. The id is the same on every try, so you can ignore a repeat.
          </p>
          <p>Events: {Object.keys(events).map((e, i) => <span key={e}>{i > 0 && ', '}<code>{e}</code></span>)}, and <code>ping</code> for the test button.</p>
          <p>
            <strong>Check every call is really from us:</strong> the <code>X-OEMDH-Signature</code> header is <code>t=&lt;unix time&gt;,v1=&lt;signature&gt;</code>. Work out HMAC-SHA256 of <code>t + "." + the raw body</code> with your signing secret, compare it to v1, and reject calls more than 5 minutes old.
          </p>
          <pre>
            {`// Node.js
const [t, v1] = header.split(',').map(p => p.split('=')[1])
const expected = crypto.createHmac('sha256', secret).update(t + '.' + rawBody).digest('hex')
const ok = crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(v1))
  && Math.abs(Date.now() / 1000 - Number(t)) < 300`}
          </pre>
        </div>
      </details>
    </section>
  )
}
