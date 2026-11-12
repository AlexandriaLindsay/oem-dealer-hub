import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Monitor, ShieldCheck } from 'lucide-react'
import { api, ApiError } from '../lib/api'
import { useApi } from '../lib/useApi'
import { useAuth } from '../lib/auth'
import { formatDateTime } from '../lib/tenant'
import { Loading } from '../components/AddForm'

/*
 * Your own account: how you get sign-in codes, and the browsers you've told
 * to remember you (which skip the code for 30 days). Forget one if it's not
 * yours any more, or all of them after losing a laptop.
 */

interface Security {
  two_factor_method: 'totp' | 'email' | null
  email: string
  trusted_browsers: { id: number; label: string; last_used_at: string | null; expires_at: string; current: boolean }[]
}

export function AccountPage() {
  const { me } = useAuth()
  const { data, error, reload } = useApi<Security>('/account/security')
  const [note, setNote] = useState<string | null>(null)

  async function forget(id?: number) {
    if (!id && !window.confirm('Forget every remembered browser? Each one will ask for a code at its next sign-in.')) return
    try {
      await api.delete(id ? `/account/trusted-browsers/${id}` : '/account/trusted-browsers')
      setNote(id ? 'Forgotten. That browser will ask for a code next time.' : 'Every browser forgotten.')
    } catch (e) {
      setNote(e instanceof ApiError ? e.message : 'Could not reach OEM Dealer Hub.')
    }
    reload()
  }

  if (!data) return <Loading error={error} />

  return (
    <>
      <div className="page-head">
        <div>
          <span className="eyebrow">Your account</span>
          <h1>{me?.user.name}</h1>
        </div>
      </div>
      <p className="lede">{me?.user.email}</p>

      <section className="panel" aria-labelledby="signin-title">
        <div className="card-head">
          <div>
            <h2 id="signin-title">
              <ShieldCheck size={16} aria-hidden className="h-icon" /> How you sign in
            </h2>
            <p>Your password, then a 6-digit code.</p>
          </div>
        </div>
        {data.two_factor_method === 'email' ? (
          <p>
            Codes come <strong>by email</strong>, to {data.email}.{' '}
            <Link to="/setup-2fa">Use an authenticator app instead</Link>. It's faster, and it works without signal or email.
          </p>
        ) : data.two_factor_method === 'totp' ? (
          <p>
            Codes come from your <strong>authenticator app</strong>. If your phone isn't with you, choose "Email me a code" at sign-in.
          </p>
        ) : (
          <p>
            Two-factor sign-in isn't on. <Link to="/setup-2fa">Turn it on</Link>.
          </p>
        )}
      </section>

      <section className="panel" aria-labelledby="browsers-title">
        <div className="card-head">
          <div>
            <h2 id="browsers-title">
              <Monitor size={16} aria-hidden className="h-icon" /> Remembered browsers
            </h2>
            <p>These skip the code for 30 days after you tick "Remember this browser". They still need your password.</p>
          </div>
          {data.trusted_browsers.length > 1 && (
            <button className="secondary" onClick={() => forget()}>
              Forget them all
            </button>
          )}
        </div>
        {note && (
          <p className="notice" role="status">
            {note}
          </p>
        )}
        {data.trusted_browsers.length === 0 ? (
          <p className="muted">None. Every sign-in asks for a code.</p>
        ) : (
          <ul className="list">
            {data.trusted_browsers.map((b) => (
              <li key={b.id} className="list-row">
                <span>
                  <strong>{b.label}</strong>
                  {b.current && <span className="badge badge-active browser-current">This browser</span>}
                  <span className="block muted small">
                    Last used {formatDateTime(b.last_used_at)} · remembered until {formatDateTime(b.expires_at)}
                  </span>
                </span>
                <button className="link link-danger" onClick={() => forget(b.id)}>
                  Forget
                </button>
              </li>
            ))}
          </ul>
        )}
      </section>
    </>
  )
}
