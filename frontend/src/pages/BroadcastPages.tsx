import { useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api, ApiError, type Announcement, type BroadcastDetail, type BroadcastRow, type DealerRow } from '../lib/api'
import { useApi } from '../lib/useApi'
import { formatDate, formatDateTime, useSlug } from '../lib/tenant'
import { useCurrentManufacturer } from '../components/Layout'
import { Loading } from '../components/AddForm'
import { Field, FormError } from '../components/Form'

function asApiError(e: unknown): ApiError {
  return e instanceof ApiError ? e : new ApiError(0, { message: 'Could not reach OEM Dealer Hub.' })
}

// ---- Staff: send and look back ----

export function BroadcastsPage() {
  const slug = useSlug()
  const navigate = useNavigate()
  const { data, error } = useApi<BroadcastRow[]>(`/m/${slug}/broadcasts`)
  const [composing, setComposing] = useState(false)

  return (
    <>
      <div className="page-head">
        <h1>Broadcasts</h1>
        {!composing && (
          <button className="primary" onClick={() => setComposing(true)}>
            New broadcast
          </button>
        )}
      </div>
      <p className="lede">Announcements to your dealers. They see them under Announcements in OEM Dealer Hub, and by email if you choose.</p>

      {composing && <Compose slug={slug} onCancel={() => setComposing(false)} onSent={(id) => navigate(String(id))} />}

      {!data ? (
        <Loading error={error} />
      ) : data.length === 0 ? (
        <p className="muted">Nothing sent yet.</p>
      ) : (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Broadcast</th>
                <th>Sent</th>
                <th>By</th>
                <th>Read</th>
              </tr>
            </thead>
            <tbody>
              {data.map((b) => (
                <tr key={b.id}>
                  <td>
                    <Link to={String(b.id)}>{b.title}</Link>
                  </td>
                  <td>{formatDate(b.sent_at)}</td>
                  <td>{b.sent_by ?? '—'}</td>
                  <td>
                    {b.read_by_dealers} of {b.recipients_count} dealers
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

function Compose({ slug, onCancel, onSent }: { slug: string; onCancel: () => void; onSent: (id: number) => void }) {
  const dealers = (useApi<DealerRow[]>(`/m/${slug}/dealers`).data ?? []).filter((d) => d.status === 'active')
  // Someone limited by territories sends to their own dealers only - say so.
  const limited = !!useCurrentManufacturer()?.territories
  const [audience, setAudience] = useState<'all' | 'selected'>('all')
  const [chosen, setChosen] = useState<number[]>([])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)

  async function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const f = new FormData(e.currentTarget)
    const count = audience === 'all' ? dealers.length : chosen.length
    if (!window.confirm(`Send this to ${count} dealer${count === 1 ? '' : 's'}? It can be withdrawn later, but emails can't be unsent.`)) return
    setBusy(true)
    setError(null)
    try {
      const sent = await api.post<BroadcastDetail>(`/m/${slug}/broadcasts`, {
        title: String(f.get('title') ?? '').trim(),
        body: String(f.get('body') ?? '').trim(),
        audience,
        dealer_link_ids: audience === 'selected' ? chosen : undefined,
        email: f.get('email') === 'on',
      })
      onSent(sent.id)
    } catch (err) {
      setError(asApiError(err))
      setBusy(false)
    }
  }

  return (
    <section className="panel">
      <h2>New broadcast</h2>
      <form onSubmit={onSubmit} noValidate>
        <Field label="Title" name="title" required maxLength={191} error={error} />
        <div className="field">
          <label htmlFor="bc-body">Message</label>
          <textarea id="bc-body" name="body" rows={6} maxLength={10000} aria-invalid={!!error?.field('body')} />
          {error?.field('body') ? <p className="field-error">{error.field('body')}</p> : <p className="hint">Plain text. Leave a blank line between paragraphs.</p>}
        </div>
        <fieldset className="choice">
          <legend>Send to</legend>
          <label className="check">
            <input type="radio" name="audience" checked={audience === 'all'} onChange={() => setAudience('all')} /> All {dealers.length} active dealers{limited ? ' in your territories' : ''}
          </label>
          <label className="check">
            <input type="radio" name="audience" checked={audience === 'selected'} onChange={() => setAudience('selected')} /> Dealers I choose
          </label>
        </fieldset>
        {audience === 'selected' && (
          <div className="dealer-picks">
            {dealers.map((d) => (
              <label key={d.id} className="check">
                <input type="checkbox" checked={chosen.includes(d.id)} onChange={(e) => setChosen(e.target.checked ? [...chosen, d.id] : chosen.filter((x) => x !== d.id))} /> {d.name}
                {d.city && <span className="muted"> · {d.city}</span>}
              </label>
            ))}
            {error?.field('dealer_link_ids') && <p className="field-error">{error.field('dealer_link_ids')}</p>}
          </div>
        )}
        {error?.field('audience') && <p className="field-error">{error.field('audience')}</p>}
        <label className="check">
          <input type="checkbox" name="email" /> Also email it to everyone at these dealers
        </label>
        <div className="form-actions">
          <FormError error={error} />
          <button className="primary" disabled={busy}>
            {busy ? 'Sending…' : 'Send'}
          </button>
          <button type="button" className="link" onClick={onCancel}>
            Cancel
          </button>
        </div>
      </form>
    </section>
  )
}

export function BroadcastPage() {
  const slug = useSlug()
  const { id } = useParams()
  const { data, error, reload } = useApi<BroadcastDetail>(`/m/${slug}/broadcasts/${id}`)

  if (!data) return <Loading error={error} />
  const read = data.recipients.filter((r) => r.read_by.length > 0).length

  return (
    <>
      <Link to=".." relative="path" className="back">
        ← Broadcasts
      </Link>
      <div className="page-head">
        <h1>{data.title}</h1>
        {!data.withdrawn_at && (
          <button
            className="link danger-link"
            onClick={() => window.confirm('Withdraw it? Dealers will no longer see it. Emails already sent stay sent.') && api.post(`/m/${slug}/broadcasts/${id}/withdraw`).then(reload)}
          >
            Withdraw
          </button>
        )}
      </div>
      <p className="lede">
        Sent {formatDateTime(data.sent_at)}
        {data.sent_by && ` by ${data.sent_by}`} · {data.audience === 'all' ? 'all dealers' : 'chosen dealers'}
        {data.emailed && ' · also emailed'}
      </p>
      {data.withdrawn_at && (
        <div className="callout callout-danger" role="status">
          <strong>Withdrawn {formatDate(data.withdrawn_at)}.</strong> Dealers no longer see it.
        </div>
      )}
      <section className="panel">
        <p className="prewrap">{data.body}</p>
      </section>
      <h2 className="section-title">
        Read by {read} of {data.recipients.length} dealers
      </h2>
      {data.other_recipients > 0 && (
        <p className="muted">
          Also sent to {data.other_recipients} {data.other_recipients === 1 ? 'dealer' : 'dealers'} outside your territories.
        </p>
      )}
      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Dealer</th>
              <th>Read by</th>
            </tr>
          </thead>
          <tbody>
            {data.recipients.map((r) => (
              <tr key={r.dealer_link_id}>
                <td>{r.dealer}</td>
                <td className="wrap">
                  {r.read_by.length === 0 ? <span className="muted">Not opened yet</span> : r.read_by.map((p) => `${p.name ?? 'Someone'} (${formatDate(p.at)})`).join(', ')}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </>
  )
}

// ---- Dealers: what was sent to them ----

export function AnnouncementsPage() {
  const slug = useSlug()
  const current = useCurrentManufacturer()
  const { data, error } = useApi<Announcement[]>(`/m/${slug}/announcements`)

  return (
    <>
      <h1>Announcements</h1>
      <p className="lede">News from {current?.name}.</p>
      {!data ? (
        <Loading error={error} />
      ) : data.length === 0 ? (
        <p className="muted">Nothing yet.</p>
      ) : (
        <ul className="announcements">
          {data.map((a) => (
            <li key={a.id} className={a.read ? '' : 'is-unread'}>
              <Link to={String(a.id)}>
                {!a.read && <span className="unread-dot" aria-label="New" />}
                <strong>{a.title}</strong>
              </Link>
              <span className="muted small">{formatDate(a.sent_at)}</span>
              <p className="muted">{a.excerpt}</p>
            </li>
          ))}
        </ul>
      )}
    </>
  )
}

export function AnnouncementPage() {
  const slug = useSlug()
  const { id } = useParams()
  const { data, error } = useApi<BroadcastDetail>(`/m/${slug}/announcements/${id}`)

  return (
    <>
      <Link to=".." relative="path" className="back">
        ← Announcements
      </Link>
      {!data ? (
        error?.status === 404 ? <p className="muted">That announcement isn't here any more.</p> : <Loading error={error} />
      ) : (
        <>
          <h1>{data.title}</h1>
          <p className="lede">
            {formatDateTime(data.sent_at)}
            {data.sent_by && ` · ${data.sent_by}`}
          </p>
          <section className="panel">
            <p className="prewrap">{data.body}</p>
          </section>
        </>
      )}
    </>
  )
}
