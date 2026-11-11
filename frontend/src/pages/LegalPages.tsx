import { useState, type FormEvent, type ReactNode } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { api, ApiError } from '../lib/api'
import { useAuth } from '../lib/auth'
import { useSite, type Site } from '../lib/site'
import { formatDate } from '../lib/tenant'
import { PublicLayout } from '../components/PublicLayout'

/*
 * The privacy policy and terms.
 *
 * DRAFTS: written in plain language from how DealerDock works today, and NOT
 * yet reviewed by a lawyer. They show a "draft" notice until
 * DEALERDOCK_LEGAL_REVIEWED=true. Company details come from config and show
 * as [placeholders] until set.
 *
 * When either text changes in a way people should agree to again, bump
 * DEALERDOCK_TERMS_VERSION (config/dealerdock.php) - everyone is asked at
 * their next sign-in. Any new cookie or tracking goes into the privacy
 * policy BEFORE it ships (docs/PLAN.md section 4).
 */

/** A company detail, or a visible placeholder until it's set in config. */
function Detail({ value, label }: { value: string | null; label: string }) {
  return value ? <>{value}</> : <mark className="placeholder">[{label}]</mark>
}

function LegalShell({ title, site, children }: { title: string; site: Site | null; children: ReactNode }) {
  return (
    <PublicLayout>
      <article className="legal">
        <h1>{title}</h1>
        {site && <p className="muted">Version {site.legal.version} · last updated {formatDate(site.legal.version)}</p>}
        {site && !site.legal.reviewed && (
          <div className="callout" role="note">
            <strong>Draft.</strong> This hasn't been reviewed by a lawyer yet. It describes how DealerDock works today, but it isn't final.
          </div>
        )}
        {children}
      </article>
    </PublicLayout>
  )
}

export function PrivacyPage() {
  const site = useSite()
  const l = site?.legal ?? null
  const company = <Detail value={l?.company_name ?? null} label="Company legal name" />
  const email = <Detail value={l?.contact_email ?? null} label="Contact email" />

  return (
    <LegalShell title="Privacy policy" site={site}>
      <h2>Who we are</h2>
      <p>
        DealerDock is run by {company}, <Detail value={l?.company_address ?? null} label="Company address" />. Questions about your information: {email}.
      </p>

      <h2>Two kinds of information, two roles</h2>
      <p>
        <strong>Your account</strong> - your name, email and sign-in details. We decide how this is used, and this policy explains it.
      </p>
      <p>
        <strong>What manufacturers and dealers put into DealerDock</strong> - machines, registrations (including the names, contact details and addresses of the customers who bought or rented a machine), and uploaded documents. The manufacturer decides what goes in and who sees it; we store and process it for them. If you're one of their customers, contact that manufacturer or dealer about your information, and we'll help them answer.
      </p>

      <h2>What we collect</h2>
      <ul>
        <li>
          <strong>Account details:</strong> name, email, your role, the company or dealer you belong to. Your password is stored only as a one-way hash. Your two-factor sign-in secret is stored encrypted.
        </li>
        <li>
          <strong>What you add:</strong> company and dealer details, product lines, machines and their history, registrations and their documents, manuals, imports and their reports, and your logo.
        </li>
        <li>
          <strong>Billing:</strong> handled by Stripe. We never see or store full card numbers; we keep the card's brand and last four digits, and your subscription's status.
        </li>
        <li>
          <strong>Technical:</strong> your IP address and the time of each request, in server logs and to limit repeated sign-in attempts. Which guided tours you've seen, and when you agreed to these terms.
        </li>
      </ul>

      <h2>Cookies and tracking - the complete list</h2>
      <p>DealerDock uses no analytics, no advertising, no tracking pixels and no third-party trackers. It stores nothing in your browser's local storage. These are all the cookies and checks involved:</p>
      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>What</th>
              <th>Who</th>
              <th>Why</th>
              <th>How long</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>
                <code>dealerdock-session</code> cookie
              </td>
              <td>DealerDock</td>
              <td className="wrap">Keeps you signed in. Strictly necessary.</td>
              <td className="wrap">Until you sign out, or 2 hours without use</td>
            </tr>
            <tr>
              <td>
                <code>XSRF-TOKEN</code> cookie
              </td>
              <td>DealerDock</td>
              <td className="wrap">Stops other websites from acting as you (cross-site request forgery). Strictly necessary.</td>
              <td className="wrap">Same as the session</td>
            </tr>
            <tr>
              <td>
                <code>remember_web_…</code> cookie
              </td>
              <td>DealerDock</td>
              <td className="wrap">Only if you tick "Keep me signed in": signs you back in on this browser.</td>
              <td className="wrap">Up to 400 days, or until you sign out</td>
            </tr>
            <tr>
              <td>Turnstile check</td>
              <td>Cloudflare</td>
              <td className="wrap">
                On the sign-in, sign-up and password-reset pages only: checks you're a person, not a bot. Cloudflare sees your IP address and signals from your browser. See{' '}
                <a href="https://www.cloudflare.com/privacypolicy/" target="_blank" rel="noreferrer">
                  Cloudflare's privacy policy
                </a>
                .
              </td>
              <td className="wrap">Cloudflare's own terms</td>
            </tr>
            <tr>
              <td>Payment pages</td>
              <td>Stripe</td>
              <td className="wrap">
                Only on Stripe's own checkout and billing pages, when an account owner pays: Stripe uses cookies to prevent fraud. See{' '}
                <a href="https://stripe.com/privacy" target="_blank" rel="noreferrer">
                  Stripe's privacy policy
                </a>
                .
              </td>
              <td className="wrap">Stripe's own terms</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p>Anything new would be added to this list before it's used.</p>

      <h2>Who else handles it</h2>
      <ul>
        <li>
          <strong>Hosting:</strong> <Detail value={null} label="Hosting provider and region, e.g. Microsoft Azure, Canada Central" />.
        </li>
        <li>
          <strong>Payments:</strong> Stripe.
        </li>
        <li>
          <strong>Bot checks:</strong> Cloudflare (Turnstile).
        </li>
        <li>
          <strong>Email:</strong> <Detail value={null} label="Email delivery provider" />, to send sign-in, invitation and registration emails.
        </li>
      </ul>
      <p>Uploaded files are checked for viruses on our own servers; they aren't sent to anyone else to scan. We don't sell information, and we don't share it for advertising.</p>
      <p>
        Inside DealerDock, each manufacturer only ever sees its own information. A dealer sees only its own machines and registrations with each manufacturer it works with - never another dealer's.
      </p>

      <h2>How long we keep it</h2>
      <p>
        While the account is open. If an account is paused (a trial or subscription ends), nothing is deleted straight away; it's kept for <Detail value={null} label="retention period" /> so the account can pick up where it left off, then deleted. Backups are kept for <Detail value={null} label="backup retention period" />.
      </p>

      <h2>How we protect it</h2>
      <p>
        Two-factor sign-in for everyone; encrypted connections; passwords hashed and sign-in secrets encrypted; every upload scanned for viruses; and automated tests that check one company can never see another's information.
      </p>

      <h2>Your rights</h2>
      <p>
        You can ask to see, correct, export or delete your information, and object to how it's used, by emailing {email}. For information a manufacturer put in (for example, as their customer), ask that manufacturer first - we'll help them. You can also complain to your local data protection authority.
      </p>

      <h2>Children</h2>
      <p>DealerDock is a business tool, not meant for anyone under 16.</p>

      <h2>Changes</h2>
      <p>The version above changes whenever this policy does. For changes that matter, we'll ask you to read and agree again the next time you sign in.</p>
    </LegalShell>
  )
}

export function TermsPage() {
  const site = useSite()
  const l = site?.legal ?? null
  const company = <Detail value={l?.company_name ?? null} label="Company legal name" />
  const email = <Detail value={l?.contact_email ?? null} label="Contact email" />
  const starter = site?.plans.find((p) => p.key === 'starter')
  const pro = site?.plans.find((p) => p.key === 'pro')

  return (
    <LegalShell title="Terms of service" site={site}>
      <h2>The agreement</h2>
      <p>
        These terms are between {company} ("we") and the manufacturer that opens a DealerDock account ("you"). Everyone who uses DealerDock - your staff, and the dealers you invite - agrees to them too. By agreeing, you confirm you can accept them for your company.
      </p>

      <h2>The service</h2>
      <p>
        DealerDock is a dealer portal: machines and their history, sale and rental registrations, manuals, fleet reports and the people who use them. We keep improving it, and may change how parts of it work; we won't take away something you rely on without telling you first.
      </p>

      <h2>Accounts</h2>
      <ul>
        <li>Give accurate details, keep sign-in details private, and use two-factor sign-in (it's required).</li>
        <li>You're responsible for who you invite and what they can do. Dealers you invite see only their own business with you.</li>
        <li>Tell us at {email} if you think an account has been misused.</li>
      </ul>

      <h2>Plans, trial and payment</h2>
      <ul>
        <li>
          A new account gets {site?.trial_days ?? 14} days of Pro free, with no card. At the end of the trial, choose a plan to keep going; otherwise the account is paused, not deleted.
        </li>
        <li>
          Starter is ${starter?.monthly_usd ?? 49} a month (or ${starter?.annual_usd ?? 490} a year) for up to {starter?.limits.dealers ?? 10} dealers; Pro is ${pro?.monthly_usd ?? 99} a month (or ${pro?.annual_usd ?? 990} a year) with unlimited dealers. Prices are in US dollars, plus any taxes that apply.
        </li>
        <li>Plans are paid in advance through Stripe and renew automatically until cancelled. You can cancel any time; the plan stays active to the end of the period you've paid for.</li>
        <li>
          Refunds: <Detail value={null} label="refund policy" />.
        </li>
        <li>If a payment fails, everything keeps working while it's retried. If it still can't be collected, the account is paused until it's paid.</li>
        <li>We'll give at least <Detail value={null} label="notice period, e.g. 30 days" /> notice of a price change, which applies from your next renewal.</li>
        <li>Dealers never pay to use DealerDock.</li>
        <li>ERP integration is quoted and agreed separately.</li>
      </ul>

      <h2>Your information</h2>
      <p>
        What you and your dealers put into DealerDock stays yours. You let us store and process it only to run DealerDock for you, as the <Link to="/privacy">privacy policy</Link> describes. You can export your fleet at any time. If your account is paused, nothing is deleted for <Detail value={null} label="retention period" />.
      </p>

      <h2>Fair use</h2>
      <p>Don't use DealerDock to break the law, upload malware or content you don't have the right to share, try to reach other companies' information, get around plan limits or security, or overload the service.</p>

      <h2>Availability and support</h2>
      <p>
        We work to keep DealerDock running and your information safe, but we can't promise it will never be unavailable. Support: {email}.
      </p>

      <h2>Liability</h2>
      <p>
        <Detail value={null} label="Warranty disclaimer and limitation of liability - to be written with a lawyer" />
      </p>

      <h2>Ending the agreement</h2>
      <p>You can cancel any time. We may suspend an account that breaks these terms, after telling you why when we can.</p>

      <h2>Changes to these terms</h2>
      <p>The version above changes whenever these terms do. For changes that matter, everyone is asked to read and agree again at their next sign-in.</p>

      <h2>Law</h2>
      <p>
        These terms are governed by the laws of <Detail value={l?.governing_law ?? null} label="governing law, e.g. the Province of Manitoba, Canada" />.
      </p>
    </LegalShell>
  )
}

/**
 * Before reaching any data: agree to the current terms. First sign-in for
 * invited staff and dealers, and everyone after the terms change.
 */
export function AcceptTermsPage() {
  const { me, refresh, logout } = useAuth()
  const navigate = useNavigate()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const updated = !!me?.user.terms_version

  async function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const accept = new FormData(e.currentTarget).get('accept') === 'on'
    setBusy(true)
    setError(null)
    try {
      await api.post('/terms/accept', { version: me?.terms_version, accept })
      await refresh()
      navigate('/')
    } catch (err) {
      setError(err instanceof ApiError ? (err.field('accept') ? 'Tick the box to agree.' : (err.field('version') ?? err.message)) : 'Could not reach DealerDock.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <main className="auth">
      <div className="auth-card">
        <div className="brand">
          <img src="/favicon.svg" alt="" width={28} height={28} />
          <span>DealerDock</span>
        </div>
        <h1>{updated ? 'Our terms have changed' : 'Before you start'}</h1>
        <p className="lede">
          {updated ? 'Please read the updated ' : 'Please read the '}
          <Link to="/terms" target="_blank">
            terms
          </Link>{' '}
          and{' '}
          <Link to="/privacy" target="_blank">
            privacy policy
          </Link>
          {updated ? ' and agree to keep using DealerDock.' : '. They say how DealerDock works and what happens to your information.'}
        </p>
        <form onSubmit={onSubmit} noValidate>
          <label className="check">
            <input type="checkbox" name="accept" /> I've read and agree to them
          </label>
          {error && (
            <p className="form-error" role="alert">
              {error}
            </p>
          )}
          <button className="primary" disabled={busy}>
            {busy ? 'Saving…' : 'Agree and continue'}
          </button>
          <button type="button" className="link" onClick={() => logout().then(() => navigate('/'))}>
            Sign out
          </button>
        </form>
      </div>
    </main>
  )
}
