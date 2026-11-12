import type { ReactNode } from 'react'
import { Link, NavLink } from 'react-router-dom'
import { useAuth } from '../lib/auth'
import { useSite } from '../lib/site'

/** The public site's frame: landing, pricing, privacy, terms. */
export function PublicLayout({ children }: { children: ReactNode }) {
  const { me } = useAuth()
  const site = useSite()
  const company = site?.legal.company_name
  const email = site?.legal.contact_email

  return (
    <div className="public">
      <header className="public-header">
        <Link to="/" className="brand">
          <img src="/favicon.svg" alt="" width={26} height={26} />
          <span>OEM Dealer Hub</span>
        </Link>
        <nav aria-label="Site">
          <NavLink to="/pricing">Pricing</NavLink>
          {me ? (
            <Link to="/" className="button-primary">
              Open OEM Dealer Hub
            </Link>
          ) : (
            <>
              <NavLink to="/login">Sign in</NavLink>
              <Link to="/signup" className="button-primary">
                Start free trial
              </Link>
            </>
          )}
        </nav>
      </header>

      <main className="public-main">{children}</main>

      <footer className="public-footer">
        <nav aria-label="Legal">
          <Link to="/privacy">Privacy</Link>
          <Link to="/terms">Terms</Link>
          {email && <a href={`mailto:${email}`}>{email}</a>}
        </nav>
        <p className="muted small">
          © {new Date().getFullYear()} {company ?? 'OEM Dealer Hub'}
        </p>
      </footer>
    </div>
  )
}
