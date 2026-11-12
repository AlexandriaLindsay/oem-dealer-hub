import { useState, type FormEvent } from 'react'
import { ShieldCheck } from 'lucide-react'
import { api, ApiError } from '../lib/api'
import { formatDateTime } from '../lib/tenant'

/*
 * OEM Dealer Hub staff on a customer's Integrations page (StaffWriteAccess on
 * the server). Staff say why before they can change anything, and get 2
 * hours. Every change is logged, and the customer sees a list of them, so
 * nothing about how their systems connect changes without them knowing.
 */

export interface StaffChange {
  what: string
  by: string
  at: string | null
}

/** For staff looking in: say why to make changes, or how long changes stay open. */
export function StaffAccessBar({ slug, customer, until, onOpened }: { slug: string; customer: string; until: string | null; onOpened: () => void }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const reason = String(new FormData(e.currentTarget).get('reason') ?? '').trim()
    setBusy(true)
    setError(null)
    try {
      await api.post(`/m/${slug}/staff-access/integrations`, { reason })
      onOpened()
    } catch (err) {
      setError(err instanceof ApiError ? (err.field('reason') ?? err.message) : 'Could not reach OEM Dealer Hub.')
    } finally {
      setBusy(false)
    }
  }

  if (until) {
    return (
      <p className="staff-view-note" role="status">
        <ShieldCheck size={16} strokeWidth={2} aria-hidden />
        <span>
          You can change {customer}'s integrations until <strong>{new Date(until).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', timeZone: 'America/New_York' })}</strong> (Eastern). Each change goes into our activity log, and {customer} sees a list of them on this page.
        </span>
      </p>
    )
  }

  return (
    <form className="staff-access" onSubmit={onSubmit} noValidate>
      <label htmlFor="staff-reason">
        <ShieldCheck size={16} strokeWidth={2} aria-hidden /> To make changes here for {customer}, say why. It goes into our activity log. {customer} sees what you change, not your reason.
      </label>
      <div className="staff-access-row">
        <input id="staff-reason" name="reason" maxLength={500} placeholder="e.g. Setting up their ERP on today's call, ticket 4411" aria-invalid={!!error} />
        <button className="primary" disabled={busy}>
          {busy ? 'Opening…' : 'Allow changes for 2 hours'}
        </button>
      </div>
      {error && <p className="field-error">{error}</p>}
    </form>
  )
}

/** For everyone: what OEM Dealer Hub staff changed here in the last 90 days. */
export function StaffChanges({ changes }: { changes: StaffChange[] }) {
  if (changes.length === 0) return null
  return (
    <section className="panel" aria-labelledby="staff-changes-title">
      <div className="card-head">
        <div>
          <h2 id="staff-changes-title">
            <ShieldCheck size={16} aria-hidden className="h-icon" /> Changed by OEM Dealer Hub staff
          </h2>
          <p>What our team changed here for you in the last 90 days. If anything looks wrong, contact us.</p>
        </div>
      </div>
      <ul className="staff-changes">
        {changes.map((c, i) => (
          <li key={i}>
            <span>{c.what}</span>
            <span className="muted small">
              {c.by} · {formatDateTime(c.at)}
            </span>
          </li>
        ))}
      </ul>
    </section>
  )
}
