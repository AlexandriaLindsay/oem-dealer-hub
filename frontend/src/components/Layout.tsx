import { useState } from 'react'
import { NavLink, Outlet, useNavigate, useParams } from 'react-router-dom'
import { api, type ManufacturerAccess } from '../lib/api'
import { useAuth } from '../lib/auth'
import { tourFor } from '../lib/tours'
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
]

export function useCurrentManufacturer(): ManufacturerAccess | undefined {
  const { me } = useAuth()
  const { slug } = useParams()
  return me?.manufacturers.find((m) => m.slug === slug)
}

export function Layout() {
  const { me, setMe, logout } = useAuth()
  const navigate = useNavigate()
  const current = useCurrentManufacturer()
  // null: not decided yet this visit; true: showing; false: closed.
  const [touring, setTouring] = useState<boolean | null>(null)

  if (!me || !current) return null

  const tour = tourFor(current)
  // Starts by itself once per person, the first time they land here.
  const showTour = touring ?? !me.user.tours_seen.includes(tour.key)

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
        {NAV.filter((item) => current.features.includes(item.feature)).map((item) => (
          // Machines stays highlighted on a machine's own page; the dashboard only on itself.
          <NavLink key={item.to} to={item.to} end={item.to === ''} data-tour={`nav-${item.to || 'dashboard'}`}>
            {item.label}
          </NavLink>
        ))}
        <button className="link tour-again" onClick={() => setTouring(true)}>
          Take the tour
        </button>
      </nav>

      <main className="content">
        <Outlet />
      </main>

      {/* key: a different brand (switcher) starts its own tour from the first step. */}
      {showTour && <Tour key={`${current.slug}:${tour.key}`} steps={tour.steps} onClose={closeTour} />}
    </div>
  )
}
