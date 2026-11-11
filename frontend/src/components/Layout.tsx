import { useState } from 'react'
import { Link, NavLink, Outlet, useLocation, useNavigate, useParams } from 'react-router-dom'
import { api, type ManufacturerAccess } from '../lib/api'
import { useAuth } from '../lib/auth'
import { tourFor } from '../lib/tours'
import { trialEndText } from '../lib/tenant'
import { Tour } from './Tour'

/** The nav, each item shown only to people with its feature. data-tour names come from `nav-` + its path. */
const NAV: { to: string; label: string; feature: string }[] = [
  { to: '', label: 'Dashboard', feature: 'dashboard' },
  { to: 'machines', label: 'Machines', feature: 'machines' },
  { to: 'registrations', label: 'Registrations', feature: 'registrations' },
  { to: 'fleet', label: 'Fleet report', feature: 'fleet_report' },
  { to: 'documents', label: 'Manuals', feature: 'documents' },
  { to: 'products', label: 'Products', feature: 'products' },
  { to: 'dealers', label: 'Dealers', feature: 'dealers' },
  { to: 'staff', label: 'Staff', feature: 'manage_staff' },
  { to: 'settings', label: 'Settings', feature: 'setup' },
  { to: 'billing', label: 'Billing', feature: 'billing' },
]

export function useCurrentManufacturer(): ManufacturerAccess | undefined {
  const { me } = useAuth()
  const { slug } = useParams()
  return me?.manufacturers.find((m) => m.slug === slug)
}

export function Layout() {
  const { me, setMe, logout } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const current = useCurrentManufacturer()
  // null: not decided yet this visit; true: showing; false: closed.
  const [touring, setTouring] = useState<boolean | null>(null)

  if (!me || !current) return null

  const canBill = current.features.includes('billing')
  // A paused account shows only Billing (to the owner) - everything else would just fail.
  const paused = current.standing === 'lapsed'
  const onBilling = location.pathname.endsWith('/billing')
  const nav = NAV.filter((item) => current.features.includes(item.feature) && (!paused || item.to === 'billing'))

  const tour = tourFor(current)
  // Starts by itself once per person, the first time they land here.
  const showTour = !paused && (touring ?? !me.user.tours_seen.includes(tour.key))

  function closeTour() {
    setTouring(false)
    // Finished or skipped, either way it shouldn't start by itself again.
    if (!me!.user.tours_seen.includes(tour.key)) {
      setMe({ ...me!, user: { ...me!.user, tours_seen: [...me!.user.tours_seen, tour.key] } })
      api.post(`/tours/${tour.key}/seen`).catch(() => {
        // Not saved: it would show again next sign-in. Not worth bothering anyone about.
      })
    }
  }

  return (
    <div className="shell">
      <header className="topbar">
        <div className="brand">
          <img src="/favicon.svg" alt="" width={24} height={24} />
          <span>DealerDock</span>
        </div>

        {current.logo_url && <img className="brand-logo" src={current.logo_url} alt={`${current.name} logo`} />}

        {/* The brand switcher - one login across every manufacturer. */}
        {me.manufacturers.length > 1 ? (
          <label className="switcher" data-tour="switcher">
            <span className="sr-only">Manufacturer</span>
            <select value={current.slug} onChange={(e) => navigate(`/m/${e.target.value}`)}>
              {me.manufacturers.map((m) => (
                <option key={m.slug} value={m.slug}>
                  {m.name}
                </option>
              ))}
            </select>
          </label>
        ) : (
          <span className="switcher-name">{current.name}</span>
        )}

        <div className="who">
          <span>
            {me.user.name}
            {current.dealer && <small> · {current.dealer.name}</small>}
          </span>
          <button className="link" onClick={() => logout().then(() => navigate('/login'))}>
            Sign out
          </button>
        </div>
      </header>

      <nav className="sidenav" aria-label="Main">
        {nav.map((item) => (
          // Machines stays highlighted on a machine's own page; the dashboard only on itself.
          <NavLink key={item.to} to={item.to} end={item.to === ''} data-tour={`nav-${item.to || 'dashboard'}`}>
            {item.label}
          </NavLink>
        ))}
        {!paused && (
          <button className="link tour-again" onClick={() => setTouring(true)}>
            Take the tour
          </button>
        )}
      </nav>

      <main className="content">
        {/* Not on Billing itself, which says the same thing in full. */}
        {!onBilling && <StandingBanner current={current} canBill={canBill} />}
        {paused && !(canBill && onBilling) ? <Paused current={current} canBill={canBill} /> : <Outlet />}
      </main>

      {/* key: a different brand (switcher) starts its own tour from the first step. */}
      {showTour && <Tour key={`${current.slug}:${tour.key}`} steps={tour.steps} onClose={closeTour} />}
    </div>
  )
}

/** A paused (lapsed) account: said plainly, to staff and dealers alike. Nothing is deleted. */
function Paused({ current, canBill }: { current: ManufacturerAccess; canBill: boolean }) {
  return (
    <section className="paused" aria-labelledby="paused-title">
      <h1 id="paused-title">The DealerDock account for {current.name} is paused</h1>
      {canBill ? (
        <>
          <p className="lede">Your free trial or subscription has ended. Choose a plan and everyone picks up where they left off: nothing has been deleted.</p>
          <Link to="billing" className="button-primary">
            Choose a plan
          </Link>
        </>
      ) : (
        <p className="lede">
          {current.acting_as === 'dealer'
            ? `Your machines, registrations and manuals with ${current.name} are safe. They'll be back as soon as ${current.name} renews.`
            : `Nothing has been deleted. Ask your account owner to choose a plan.`}
        </p>
      )}
    </section>
  )
}

/** For the owner: a payment that failed, or a trial about to end. Nobody else needs to see it. */
function StandingBanner({ current, canBill }: { current: ManufacturerAccess; canBill: boolean }) {
  if (!canBill) return null
  if (current.standing === 'past_due') {
    return (
      <div className="callout callout-danger" role="alert">
        <strong>Your last payment didn't go through.</strong> Everything still works while it's retried. <Link to="billing">Update your card</Link>
      </div>
    )
  }
  const days = current.standing === 'trialing' && current.trial_ends_at ? Math.ceil((Date.parse(current.trial_ends_at) - Date.now()) / 86_400_000) : null
  if (days !== null && days <= 3) {
    return (
      <div className="callout" role="status">
        <strong>Your free trial ends {trialEndText(current.trial_ends_at)}.</strong> <Link to="billing">Choose a plan</Link> so nothing is paused.
      </div>
    )
  }
  return null
}
