import { useEffect, useState } from 'react'
import { Link, NavLink, Outlet, useLocation, useNavigate, useParams } from 'react-router-dom'
import {
  BookOpen,
  Boxes,
  ChartPie,
  CircleHelp,
  ClipboardCheck,
  CreditCard,
  FileText,
  LayoutDashboard,
  LogOut,
  Map,
  Megaphone,
  Menu,
  PackageCheck,
  Plug,
  Radio,
  Settings,
  ShieldCheck,
  Store,
  Tag,
  Tractor,
  TrendingUp,
  Users,
  Wrench,
  X,
  type LucideIcon,
} from 'lucide-react'
import { api, type ManufacturerAccess } from '../lib/api'
import { useAuth } from '../lib/auth'
import { tourFor } from '../lib/tours'
import { trialEndText } from '../lib/tenant'
import { useRecordVisit } from '../lib/usage'
import { useBrandColor } from '../lib/brand'
import { Tour } from './Tour'

interface NavItem {
  to: string
  label: string
  feature: string
  icon: LucideIcon
}

/**
 * The nav, in groups, each item shown only to people with its feature (a
 * group with nothing in it isn't shown). data-tour names come from `nav-` + its path.
 */
const NAV: { title: string; items: NavItem[] }[] = [
  {
    title: 'Overview',
    items: [
      { to: '', label: 'Dashboard', feature: 'dashboard', icon: LayoutDashboard },
      { to: 'fleet', label: 'Fleet report', feature: 'fleet_report', icon: ChartPie },
      { to: 'usage', label: 'Usage', feature: 'usage_analytics', icon: TrendingUp },
    ],
  },
  {
    title: 'Machines',
    items: [
      { to: 'machines', label: 'Machines', feature: 'machines', icon: Tractor },
      { to: 'registrations', label: 'Registrations', feature: 'registrations', icon: ClipboardCheck },
      { to: 'documents', label: 'Manuals', feature: 'documents', icon: BookOpen },
      { to: 'products', label: 'Products', feature: 'products', icon: Boxes },
    ],
  },
  {
    title: 'Sales and parts',
    items: [
      // Price lists (manage_pricing) are reached from the Prices page.
      { to: 'prices', label: 'Prices', feature: 'catalog', icon: Tag },
      { to: 'quotes', label: 'Quotes', feature: 'quotes', icon: FileText },
      { to: 'sales-orders', label: 'Sales orders', feature: 'sales_orders', icon: PackageCheck },
      { to: 'parts-orders', label: 'Parts orders', feature: 'parts_orders', icon: Wrench },
    ],
  },
  {
    title: 'Dealers',
    items: [
      { to: 'dealers', label: 'Dealers', feature: 'dealers', icon: Store },
      { to: 'territories', label: 'Territories', feature: 'territories', icon: Map },
      { to: 'broadcasts', label: 'Broadcasts', feature: 'broadcasts', icon: Radio },
      { to: 'announcements', label: 'Announcements', feature: 'announcements', icon: Megaphone },
    ],
  },
  {
    title: 'Account',
    items: [
      { to: 'staff', label: 'Staff', feature: 'manage_staff', icon: Users },
      { to: 'settings', label: 'Settings', feature: 'setup', icon: Settings },
      { to: 'integrations', label: 'Integrations', feature: 'erp_integration', icon: Plug },
      { to: 'billing', label: 'Billing', feature: 'billing', icon: CreditCard },
    ],
  },
]

const ROLE_NAMES: Record<string, string> = { sales_rep: 'Sales rep', inside_sales: 'Inside sales' }
const roleName = (role: string) => ROLE_NAMES[role] ?? role.charAt(0).toUpperCase() + role.slice(1).replace(/_/g, ' ')

const initials = (name: string) =>
  name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w[0].toUpperCase())
    .join('')

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
  // The menu on a phone, where the sidebar folds away.
  const [menuOpen, setMenuOpen] = useState(false)
  useRecordVisit(current?.slug, current?.standing === 'lapsed')
  useBrandColor(current?.brand_color)

  // Picking a page closes the phone menu.
  useEffect(() => setMenuOpen(false), [location.pathname])

  if (!me || !current) return null

  const canBill = current.features.includes('billing')
  // A paused account shows only Billing (to the owner) - everything else would just fail.
  const paused = current.standing === 'lapsed'
  const onBilling = location.pathname.endsWith('/billing')
  const groups = NAV.map((g) => ({ ...g, items: g.items.filter((item) => current.features.includes(item.feature) && (!paused || item.to === 'billing')) })).filter((g) => g.items.length > 0)

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
    // On a phone the tour points at menu items, so the menu stays open while it runs.
    <div className={`shell${menuOpen || showTour ? ' menu-open' : ''}`}>
      <aside className="sidebar">
        <div className="side-head">
          <div className="side-brand">
            {/* A logo that won't load just isn't shown - no broken-image box on every page. */}
            {current.logo_url ? (
              <span className="side-logo">
                <img src={current.logo_url} alt="" onError={(e) => (e.currentTarget.parentElement!.style.display = 'none')} />
              </span>
            ) : (
              <img className="side-mark" src="/favicon.svg" alt="" width={28} height={28} />
            )}
            <span className="side-names">
              <strong>{current.name}</strong>
              <small>OEM Dealer Hub</small>
            </span>
          </div>
          <button className="side-menu-button" aria-expanded={menuOpen} aria-controls="side-nav" onClick={() => setMenuOpen((o) => !o)}>
            {menuOpen ? <X size={20} aria-hidden /> : <Menu size={20} aria-hidden />}
            <span className="sr-only">Menu</span>
          </button>
        </div>

        {/* The brand switcher - one login across every manufacturer. */}
        {me.manufacturers.length > 1 && (
          <label className="switcher" data-tour="switcher">
            <span className="side-label">Brand</span>
            <select value={current.slug} onChange={(e) => navigate(`/m/${e.target.value}`)}>
              {me.manufacturers.map((m) => (
                <option key={m.slug} value={m.slug}>
                  {m.name}
                </option>
              ))}
            </select>
          </label>
        )}

        <nav className="side-nav" id="side-nav" aria-label="Main">
          {groups.map((group) => (
            <div className="side-group" key={group.title}>
              <span className="side-label">{group.title}</span>
              {group.items.map(({ to, label, icon: Icon }) => (
                // Machines stays highlighted on a machine's own page; the dashboard only on itself.
                <NavLink key={to} to={to} end={to === ''} data-tour={`nav-${to || 'dashboard'}`}>
                  <Icon size={18} strokeWidth={1.75} aria-hidden />
                  <span>{label}</span>
                </NavLink>
              ))}
            </div>
          ))}
        </nav>

        <div className="side-foot">
          {me.user.console_url && (
            <a className="side-tour" href={me.user.console_url}>
              <ShieldCheck size={18} strokeWidth={1.75} aria-hidden />
              <span>Staff console</span>
            </a>
          )}
          {!paused && (
            <button className="side-tour" onClick={() => setTouring(true)}>
              <CircleHelp size={18} strokeWidth={1.75} aria-hidden />
              <span>Take the tour</span>
            </button>
          )}
          <div className="side-user">
            <span className="avatar" aria-hidden>
              {initials(me.user.name)}
            </span>
            <span className="side-names">
              <strong>{me.user.name}</strong>
              <small>{current.dealer ? current.dealer.name : roleName(current.role)}</small>
            </span>
            <button className="side-signout" onClick={() => logout().then(() => navigate('/login'))} title="Sign out">
              <LogOut size={18} strokeWidth={1.75} aria-hidden />
              <span className="sr-only">Sign out</span>
            </button>
          </div>
        </div>
      </aside>

      <main className="content">
        {/* Not on Billing itself, which says the same thing in full. */}
        {!onBilling && <StandingBanner current={current} canBill={canBill} />}
        {/* A rep limited to territories should never wonder where the other dealers went. */}
        {current.territories && (
          <p className="muted territory-note">
            You see the dealers in {current.territories.length === 1 ? 'your territory' : 'your territories'}: {current.territories.join(', ')}.
          </p>
        )}
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
      <h1 id="paused-title">The OEM Dealer Hub account for {current.name} is paused</h1>
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
