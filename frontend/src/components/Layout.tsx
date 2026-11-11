import { NavLink, Outlet, useNavigate, useParams } from 'react-router-dom'
import type { ManufacturerAccess } from '../lib/api'
import { useAuth } from '../lib/auth'

/** The nav, each item shown only to people with its feature. */
const NAV: { to: string; label: string; feature: string }[] = [
  { to: '', label: 'Dashboard', feature: 'dashboard' },
  { to: 'machines', label: 'Machines', feature: 'machines' },
  { to: 'products', label: 'Products', feature: 'products' },
  { to: 'dealers', label: 'Dealers', feature: 'dealers' },
  { to: 'staff', label: 'Staff', feature: 'manage_staff' },
]

export function useCurrentManufacturer(): ManufacturerAccess | undefined {
  const { me } = useAuth()
  const { slug } = useParams()
  return me?.manufacturers.find((m) => m.slug === slug)
}

export function Layout() {
  const { me, logout } = useAuth()
  const navigate = useNavigate()
  const current = useCurrentManufacturer()

  if (!me || !current) return null

  return (
    <div className="shell">
      <header className="topbar">
        <div className="brand">
          <img src="/favicon.svg" alt="" width={24} height={24} />
          <span>DealerDock</span>
        </div>

        {/* The brand switcher - one login across every manufacturer. */}
        {me.manufacturers.length > 1 ? (
          <label className="switcher">
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
          <NavLink key={item.to} to={item.to} end={item.to === ''}>
            {item.label}
          </NavLink>
        ))}
      </nav>

      <main className="content">
        <Outlet />
      </main>
    </div>
  )
}
