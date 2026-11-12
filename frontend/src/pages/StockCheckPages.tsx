import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { CheckCircle2, CircleAlert } from 'lucide-react'
import { api, ApiError } from '../lib/api'
import { useApi } from '../lib/useApi'
import { formatDate, formatDateTime, useSlug } from '../lib/tenant'
import { useCurrentManufacturer } from '../components/Layout'
import { Loading } from '../components/AddForm'

/*
 * Stock checks: dealers confirm the machines on their lot - "everything's
 * here" in one click, or machine by machine with "not here" and a note.
 * Staff see who's answered and what's missing. The same list works signed
 * in (StockChecksPage) and from the email link (PublicStockCheckPage).
 */

export interface CheckItem {
  machine_id: number
  serial_no: string
  model: string | null
  status: string
  answer: 'here' | 'not_here' | null
  note: string | null
}

export interface CheckDetail {
  id: number
  status: 'open' | 'done' | 'expired'
  dealer: string
  manufacturer?: string
  due_on: string
  created_at: string | null
  completed_at: string | null
  items: CheckItem[]
}

interface CheckSummary {
  id: number
  dealer_link_id: number
  dealer: string
  status: 'open' | 'done' | 'expired'
  machines: number
  here: number
  not_here: number
  unanswered: number
  due_on: string
  created_at: string | null
  completed_at: string | null
}

function asApiError(e: unknown): ApiError {
  return e instanceof ApiError ? e : new ApiError(0, { message: 'Could not reach OEM Dealer Hub.' })
}

export function StockChecksPage() {
  const current = useCurrentManufacturer()
  return current?.acting_as === 'dealer' ? <DealerStockChecks /> : <StaffStockChecks />
}

// ---- A dealer answering ----

function DealerStockChecks() {
  const slug = useSlug()
  const current = useCurrentManufacturer()
  const { data, error, reload } = useApi<{ open: CheckDetail | null; history: CheckSummary[] }>(`/m/${slug}/stock-checks`)

  if (!data) return <Loading error={error} />

  return (
    <>
      <div className="page-head">
        <h1>Stock check</h1>
      </div>
      <p className="lede">
        {current?.name} asks now and then which of its machines are still on your lot, so their records match yours. Usually it's one click.
      </p>
      {data.open ? (
        <CheckAnswers check={data.open} base={`/m/${slug}/stock-checks/${data.open.id}`} onChange={reload} />
      ) : (
        <p className="notice">Nothing to check right now. You'll get an email when there is.</p>
      )}
      {data.history.filter((h) => h.status !== 'open').length > 0 && (
        <>
          <h2 className="section-title">Earlier checks</h2>
          <ul className="list">
            {data.history
              .filter((h) => h.status !== 'open')
              .map((h) => (
                <li key={h.id} className="list-row">
                  <span>
                    {formatDate(h.created_at)} · {h.machines} machines
                  </span>
                  <span className="muted">{h.status === 'done' ? `Done ${formatDate(h.completed_at)}` : 'Closed before it was finished'}</span>
                </li>
              ))}
          </ul>
        </>
      )}
    </>
  )
}

/**
 * The list to answer: "Everything's here" for all of it, or Here / Not here
 * per machine. Posts to `base` + /confirm-all or /answers (signed in, or the email link).
 */
export function CheckAnswers({ check, base, onChange }: { check: CheckDetail; base: string; onChange: (c?: CheckDetail) => void }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  // A "not here" being explained: which machine, and the note so far.
  const [explaining, setExplaining] = useState<{ id: number; note: string } | null>(null)
  const open = check.status === 'open'
  const left = check.items.filter((i) => i.answer === null).length

  async function post(path: string, body?: object) {
    setBusy(true)
    setError(null)
    try {
      onChange(await api.post<CheckDetail>(base + path, body))
      setExplaining(null)
    } catch (e) {
      const err = asApiError(e)
      setError(err.field('answers') ?? err.message)
    } finally {
      setBusy(false)
    }
  }

  const answer = (id: number, value: 'here' | 'not_here', note?: string) => post('/answers', { answers: [{ machine_id: id, answer: value, note: note || null }] })

  return (
    <section className="panel stock-check" aria-labelledby="check-title">
      <div className="card-head">
        <div>
          <h2 id="check-title">{check.items.length} machines at {check.dealer}</h2>
          <p>
            {check.status === 'done'
              ? `All answered ${formatDate(check.completed_at)}. Thank you!`
              : check.status === 'expired'
                ? 'This check closed before it was finished.'
                : `Please answer by ${formatDate(check.due_on)}. ${left === check.items.length ? '' : `${left} left.`}`}
          </p>
        </div>
        {open && left > 0 && (
          <button className="primary" disabled={busy} onClick={() => post('/confirm-all')}>
            {left === check.items.length ? "Everything's here" : `The other ${left} are here`}
          </button>
        )}
      </div>
      {error && (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}
      <ul className="check-items">
        {check.items.map((i) => (
          <li key={i.machine_id} className={i.answer ? `is-${i.answer}` : undefined}>
            <span className="check-machine">
              <strong>{i.serial_no}</strong>
              <span className="muted small">
                {i.model} · {i.status}
              </span>
            </span>
            {i.answer === 'here' && (
              <span className="check-answer ok">
                <CheckCircle2 size={16} aria-hidden /> Here
              </span>
            )}
            {i.answer === 'not_here' && (
              <span className="check-answer bad">
                <CircleAlert size={16} aria-hidden /> Not here{i.note ? `: ${i.note}` : ''}
              </span>
            )}
            {open && explaining?.id !== i.machine_id && (
              <span className="check-buttons">
                {i.answer !== 'here' && (
                  <button className="secondary" disabled={busy} onClick={() => answer(i.machine_id, 'here')}>
                    Here
                  </button>
                )}
                {i.answer !== 'not_here' && (
                  <button className="secondary" disabled={busy} onClick={() => setExplaining({ id: i.machine_id, note: '' })}>
                    Not here
                  </button>
                )}
              </span>
            )}
            {explaining?.id === i.machine_id && (
              <form
                className="check-note"
                onSubmit={(e) => {
                  e.preventDefault()
                  answer(i.machine_id, 'not_here', explaining.note.trim())
                }}
              >
                <label htmlFor={`note-${i.machine_id}`} className="sr-only">
                  Where is it?
                </label>
                <input id={`note-${i.machine_id}`} autoFocus maxLength={500} placeholder="Where is it? e.g. sold, at our other store, on loan" value={explaining.note} onChange={(e) => setExplaining({ id: i.machine_id, note: e.target.value })} />
                <button className="primary" disabled={busy}>
                  Save
                </button>
                <button type="button" className="link" onClick={() => setExplaining(null)}>
                  Cancel
                </button>
              </form>
            )}
          </li>
        ))}
      </ul>
    </section>
  )
}

// ---- Staff: who's answered, what's missing ----

function StaffStockChecks() {
  const slug = useSlug()
  const { data, error, reload } = useApi<{ monthly: boolean; can_manage: boolean; dealers: CheckSummary[]; not_here: { machine_id: number; serial_no: string; model: string | null; dealer: string; note: string | null; answered_at: string | null }[] }>(`/m/${slug}/stock-checks`)
  const [note, setNote] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  async function startAll() {
    setBusy(true)
    try {
      const r = await api.post<{ started: number; skipped: number }>(`/m/${slug}/stock-checks/start`)
      setNote(
        r.started === 0
          ? 'No new checks: every dealer either has one open, or has no machines to check.'
          : `Sent to ${r.started} dealer${r.started === 1 ? '' : 's'}.${r.skipped ? ` ${r.skipped} skipped (one already open, or nothing to check).` : ''}`,
      )
      reload()
    } catch (e) {
      setNote(asApiError(e).message)
    } finally {
      setBusy(false)
    }
  }

  async function setMonthly(on: boolean) {
    try {
      await api.post(`/m/${slug}/stock-checks/settings`, { monthly: on })
      setNote(on ? 'Monthly checks are on.' : 'Monthly checks are off.')
    } catch (e) {
      // Said, not swallowed - the switch would otherwise just flip back with no reason given.
      setNote(asApiError(e).message)
    }
    reload()
  }

  if (!data) return <Loading error={error} />

  const done = data.dealers.filter((d) => d.status === 'done').length

  return (
    <>
      <div className="page-head">
        <h1>Stock checks</h1>
        {data.can_manage && (
          <button className="primary" disabled={busy} onClick={startAll}>
            {busy ? 'Sending…' : 'Check all dealers now'}
          </button>
        )}
      </div>
      <p className="lede">
        Each dealer confirms the machines we show at their lot: one click if everything's there. A machine marked "not here" is listed below, so your records stay true without dealers typing.
      </p>
      {note && (
        <p className="notice" role="status">
          {note}
        </p>
      )}
      {data.can_manage && (
        <section className="panel">
          <label className="switch">
            <input type="checkbox" role="switch" checked={data.monthly} onChange={(e) => setMonthly(e.target.checked)} />
            <span className="switch-track" aria-hidden />
            <span>
              <strong>Check every month</strong>
              <span className="block muted small">
                {data.monthly ? 'On: each active dealer gets a check early each month, with two weeks to answer.' : "Off: checks go out only when you send them."}
              </span>
            </span>
          </label>
        </section>
      )}

      {data.not_here.length > 0 && (
        <section className="panel" aria-labelledby="missing-title">
          <div className="card-head">
            <div>
              <h2 id="missing-title">
                <CircleAlert size={16} aria-hidden className="h-icon" /> Not on the lot
              </h2>
              <p>Machines a dealer says aren't there. Update each one (sold, moved), or ask the dealer.</p>
            </div>
          </div>
          <div className="table-wrap flat">
            <table>
              <thead>
                <tr>
                  <th>Serial</th>
                  <th>Model</th>
                  <th>Dealer</th>
                  <th>Their note</th>
                  <th>Said</th>
                </tr>
              </thead>
              <tbody>
                {data.not_here.map((m) => (
                  <tr key={m.machine_id}>
                    <td>
                      <Link to={`../machines/${m.machine_id}`} relative="path">
                        {m.serial_no}
                      </Link>
                    </td>
                    <td>{m.model}</td>
                    <td>{m.dealer}</td>
                    <td className="wrap">{m.note ?? <span className="muted">No note</span>}</td>
                    <td>{formatDateTime(m.answered_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}

      <section className="panel" aria-labelledby="dealers-title">
        <div className="card-head">
          <div>
            <h2 id="dealers-title">By dealer</h2>
            <p>{data.dealers.length ? `Latest check for each dealer: ${done} of ${data.dealers.length} finished.` : 'No checks yet.'}</p>
          </div>
        </div>
        {data.dealers.length > 0 && (
          <div className="table-wrap flat">
            <table className="numeric">
              <thead>
                <tr>
                  <th>Dealer</th>
                  <th>Sent</th>
                  <th>Machines</th>
                  <th>Here</th>
                  <th>Not here</th>
                  <th>Not answered</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {data.dealers.map((d) => (
                  <tr key={d.id}>
                    <td>{d.dealer}</td>
                    <td>{formatDate(d.created_at)}</td>
                    <td>{d.machines}</td>
                    <td>{d.here}</td>
                    <td>{d.not_here ? <strong className="check-bad-count">{d.not_here}</strong> : 0}</td>
                    <td>{d.unanswered}</td>
                    <td>
                      <span className={`badge badge-check-${d.status}`}>{d.status === 'done' ? 'Done' : d.status === 'open' ? `Due ${formatDate(d.due_on)}` : 'Closed unfinished'}</span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </>
  )
}

// ---- The email link: no sign-in ----

export function PublicStockCheckPage() {
  const { token } = useParams()
  const { data, error } = useApi<CheckDetail>(`/stock-check/${token}`)
  const [check, setCheck] = useState<CheckDetail | null>(null)
  const shown = check ?? data

  return (
    <main className="auth public-check">
      <div className="auth-card wide">
        <div className="brand">
          <img src="/favicon.svg" alt="" width={28} height={28} />
          <span>OEM Dealer Hub</span>
        </div>
        {!shown ? (
          error ? (
            <>
              <h1>That link doesn't work</h1>
              <p className="lede">{error.status === 404 ? "It may be from an older stock check. Sign in to see the current one." : error.message}</p>
              <Link to="/login" className="button-primary">
                Sign in
              </Link>
            </>
          ) : (
            <p className="muted">Loading…</p>
          )
        ) : (
          <>
            <h1>Stock check for {shown.manufacturer}</h1>
            <CheckAnswers check={shown} base={`/stock-check/${token}`} onChange={(c) => c && setCheck(c)} />
          </>
        )}
      </div>
    </main>
  )
}
