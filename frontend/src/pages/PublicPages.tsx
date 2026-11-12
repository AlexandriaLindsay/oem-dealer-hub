import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useSite, type Site } from '../lib/site'
import { PublicLayout } from '../components/PublicLayout'

/*
 * The public landing and pricing pages. Every price, limit and trial length
 * comes from GET /api/site (App\Support\Plan), so these pages can't drift
 * from what's actually billed. Copy describes only what OEM Dealer Hub does today.
 */

const FEATURES: [string, string][] = [
  ['Every machine, by serial number', 'Where each one is, its status - inventory, demo, rental, sold, used - and its full history, including moves between dealers.'],
  ['Sales and rentals, registered properly', 'Dealers register each sale with the customer and the bill of sale. You approve it, and the customer joins your records.'],
  ['Manuals dealers can find', 'Link manuals and bulletins to models and serial ranges. A service shop types the serial from the plate and gets the right ones.'],
  ['The whole fleet at a glance', 'Machines by status, dealer and model, with an Excel export for the meeting.'],
  ['Bring what you have', 'Import your existing machines from a spreadsheet, with a preview of every row before anything changes, and a report after.'],
  ['One login for dealers', 'A dealer who carries several brands signs in once and switches between them. Each brand sees only its own business.'],
]

const SECURITY = [
  'Two-factor sign-in for everyone',
  'Each manufacturer only ever sees its own information; each dealer only its own machines - checked by automated tests',
  'Every upload scanned for viruses',
  'No ads, no third-party analytics, no trackers',
]

export function LandingPage() {
  const site = useSite()
  const starter = site?.plans.find((p) => p.key === 'starter')

  return (
    <PublicLayout>
      <section className="public-hero">
        <h1>The dealer portal for equipment manufacturers</h1>
        <p className="hero-lede">
          Your machines, your dealers' registrations and your manuals in one place. Dealers use it free; you pay a flat monthly price{starter ? `, from $${starter.monthly_usd}` : ''}.
        </p>
        <div className="hero-actions">
          <Link to="/signup" className="button-primary button-large">
            Start your {site?.trial_days ?? 14}-day free trial
          </Link>
          <Link to="/pricing">See pricing</Link>
        </div>
        <p className="muted small">No card needed. Your dealers never pay.</p>
      </section>

      <section className="feature-grid" aria-label="What it does">
        {FEATURES.map(([title, body]) => (
          <div key={title} className="feature">
            <h2>{title}</h2>
            <p>{body}</p>
          </div>
        ))}
      </section>

      <section className="band">
        <h2>Built to keep everyone's information apart</h2>
        <ul className="ticks">
          {SECURITY.map((s) => (
            <li key={s}>{s}</li>
          ))}
        </ul>
      </section>

      <section className="band">
        <h2>Dealers are always free</h2>
        <p>Your dealers are your network - charging them would only slow it down. Invite as many as your plan includes; they never see a bill.</p>
        <p>
          Run an ERP? On Pro, your IT team can connect it themselves with API keys and webhooks. If you'd rather we set it up, that's quoted separately. <Link to="/pricing">More on pricing</Link>
        </p>
      </section>
    </PublicLayout>
  )
}

export function PricingPage() {
  const site = useSite()
  const [annual, setAnnual] = useState(false)

  return (
    <PublicLayout>
      <section className="public-hero hero-small">
        <h1>Simple pricing. Dealers are free.</h1>
        <p className="hero-lede">Start with {site?.trial_days ?? 14} days of Pro, free, no card. Then pick the plan that fits.</p>
        <div className="interval-toggle" role="group" aria-label="Billing period">
          <button className={annual ? '' : 'is-on'} aria-pressed={!annual} onClick={() => setAnnual(false)}>
            Monthly
          </button>
          <button className={annual ? 'is-on' : ''} aria-pressed={annual} onClick={() => setAnnual(true)}>
            Annual - 2 months free
          </button>
        </div>
      </section>

      {site ? <PlanCards site={site} annual={annual} /> : <p className="muted">Loading…</p>}

      <section className="band faq">
        <h2>Questions</h2>
        <h3>What happens when the trial ends?</h3>
        <p>Choose a plan before it ends and nothing changes. If you don't, the account is paused - nothing is deleted - until you do.</p>
        <h3>Can I change plans or cancel?</h3>
        <p>Any time. Moving to Starter needs your account to fit Starter's limits first, so no dealer is cut off by surprise. Cancelling keeps your plan to the end of the period you've paid for.</p>
        <h3>Do dealers pay?</h3>
        <p>Never.</p>
        <h3>ERP integration?</h3>
        <p>Pro includes API keys and signed webhooks, so your IT team (or a tool like Power Automate or Make) can connect your ERP themselves. New sales orders reach your ERP the moment a quote is won. If you'd like us to do the setup and mapping, that's quoted separately, after a conversation about how your data should flow.</p>
      </section>
    </PublicLayout>
  )
}

function PlanCards({ site, annual }: { site: Site; annual: boolean }) {
  const limit = (n: number | null, unit: string) => (n === null ? `Unlimited ${unit}` : `Up to ${n} ${unit}`)

  return (
    <div className="plan-cards public-plans">
      {site.plans.map((p) => (
        <section key={p.key} className={`panel plan-card${p.key === 'pro' ? ' is-current' : ''}`} aria-labelledby={`price-${p.key}`}>
          <h2 id={`price-${p.key}`}>{p.label}</h2>
          <p className="plan-price">
            <strong>${annual ? p.annual_usd : p.monthly_usd}</strong> / {annual ? 'year' : 'month'}
          </p>
          <ul className="plan-features">
            <li>{limit(p.limits.dealers, 'dealers')}</li>
            <li>{limit(p.limits.staff, 'staff users')}</li>
            <li>{p.limits.storage_gb} GB of file storage</li>
            <li>Machines, fleet report, registrations, manuals, imports</li>
            {p.extras.map((x) => (
              <li key={x.label}>
                {x.label}
                {x.coming && <span className="coming"> - coming soon</span>}
              </li>
            ))}
          </ul>
          <Link to="/signup" className={p.key === 'pro' ? 'button-primary' : 'button-secondary'}>
            Start free trial
          </Link>
        </section>
      ))}
    </div>
  )
}
