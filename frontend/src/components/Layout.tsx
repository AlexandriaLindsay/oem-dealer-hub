import { useEffect, useRef, useState, type RefObject } from 'react'
import { Link, NavLink, Outlet, useLocation, useNavigate, useParams } from 'react-router-dom'
import {
  BookOpen,
  Boxes,
  ChartPie,
  Check,
  ChevronDown,
  ChevronRight,
  ChevronsLeft,
  ChevronsRight,
  ChevronUp,
  CircleHelp,
  ClipboardCheck,
  ListChecks,
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
  UserRound,
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
  /** Other pages that belong to this item, so it stays highlighted there (e.g. price lists under Prices). */
  also?: string[]
}

interface NavGroup {
  title: string
  icon: LucideIcon
  items: NavItem[]
}

/**
 * The nav, in groups that fold open, each item shown only to people with its
 * feature. A group with nothing in it isn't shown, and a group with one item
 * is just that item, so nobody opens a folder to find a single page.
 * data-tour names come from `nav-` + its path.
 */
const NAV: NavGroup[] = [
  {
    title: 'Dashboard',
    icon: LayoutDashboard,
    items: [{ to: '', label: 'Dashboard', feature: 'dashboard', icon: LayoutDashboard }],
  },
  {
    title: 'Reports',
    icon: ChartPie,
    items: [
      { to: 'fleet', label: 'Fleet report', feature: 'fleet_report', icon: ChartPie },
      { to: 'usage', label: 'Usage', feature: 'usage_analytics', icon: TrendingUp },
    ],
  },
  {
    title: 'Machines',
    icon: Tractor,
    items: [
      { to: 'machines', label: 'Machines', feature: 'machines', icon: Tractor },
      { to: 'registrations', label: 'Registrations', feature: 'registrations', icon: ClipboardCheck },
      { to: 'documents', label: 'Manuals', feature: 'documents', icon: BookOpen },
      { to: 'stock-checks', label: 'Stock checks', feature: 'stock_checks', icon: ListChecks },
      { to: 'products', label: 'Products', feature: 'products', icon: Boxes },
    ],
  },
  {
    title: 'Sales and parts',
    icon: Tag,
    items: [
      // Price lists (manage_pricing) are reached from the Prices page.
      { to: 'prices', label: 'Prices', feature: 'catalog', icon: Tag, also: ['price-lists'] },
      { to: 'quotes', label: 'Quotes', feature: 'quotes', icon: FileText },
      { to: 'sales-orders', label: 'Sales orders', feature: 'sales_orders', icon: PackageCheck },
      { to: 'parts-orders', label: 'Parts orders', feature: 'parts_orders', icon: Wrench },
    ],
  },
  {
    title: 'Dealers',
    icon: Store,
    items: [
      { to: 'dealers', label: 'Dealers', feature: 'dealers', icon: Store },
      { to: 'territories', label: 'Territories', feature: 'territories', icon: Map },
      { to: 'broadcasts', label: 'Broadcasts', feature: 'broadcasts', icon: Radio },
      { to: 'announcements', label: 'Announcements', feature: 'announcements', icon: Megaphone },
    ],
  },
  {
    title: 'Account',
    icon: Settings,
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

/** Closes a pop-up menu on a click outside it or on Escape (focus goes back to its button). */
function useDismiss(ref: RefObject<HTMLElement | null>, open: boolean, close: () => void) {
  useEffect(() => {
    if (!open) return
    const onDown = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) close()
    }
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        close()
        ref.current?.querySelector<HTMLElement>('[aria-expanded]')?.focus()
      }
    }
    document.addEventListener('mousedown', onDown)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onDown)
      document.removeEventListener('keydown', onKey)
    }
  }, [ref, open, close])
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
  // The desktop sidebar folded down to icons. Kept for this visit only: the privacy policy says nothing goes in local storage.
  const [collapsed, setCollapsed] = useState(false)
  // Which group is unfolded. Opening one folds the others, like an accordion.
  const [openGroup, setOpenGroup] = useState<string | null>(null)
  const [brandsOpen, setBrandsOpen] = useState(false)
  const [userOpen, setUserOpen] = useState(false)
  const brandsRef = useRef<HTMLDivElement>(null)
  const userRef = useRef<HTMLDivElement>(null)
  useDismiss(brandsRef, brandsOpen, () => setBrandsOpen(false))
  useDismiss(userRef, userOpen, () => setUserOpen(false))
  useRecordVisit(current?.slug, current?.standing === 'lapsed')
  useBrandColor(current?.brand_color)

  // The page's own section, e.g. "machines" for /m/acme/machines/12.
  const section = current ? location.pathname.slice(`/m/${current.slug}`.length).split('/')[1] ?? '' : ''
  const matches = (item: NavItem) => item.to === section || (item.also?.includes(section) ?? false)
  const activeGroup = NAV.find((g) => g.items.some(matches))?.title ?? null

  // Picking a page closes the phone menu and the pop-ups, and unfolds the group it's in.
  useEffect(() => {
    setMenuOpen(false)
    setBrandsOpen(false)
    setUserOpen(false)
  }, [location.pathname])
  useEffect(() => setOpenGroup(activeGroup), [activeGroup])

  if (!me || !current) return null

  const canBill = current.features.includes('billing')
  // A paused account shows only Billing (to the owner) - everything else would just fail.
  const paused = current.standing === 'lapsed'
  const onBilling = location.pathname.endsWith('/billing')
  const groups = NAV.map((g) => ({ ...g, items: g.items.filter((item) => current.features.includes(item.feature) && (!paused || item.to === 'billing')) })).filter((g) => g.items.length > 0)

  const tour = tourFor(current)
  // Starts by itself once per person, the first time they land here.
  // Staff looking in never get the welcome tour by themselves (it's the customer's); "Take the tour" still works.
  const showTour = !paused && (touring ?? (!current.staff_view && !me.user.tours_seen.includes(tour.key)))
  // The tour points at menu items, so while it runs every group is open and the sidebar full width.
  const rail = collapsed && !showTour

  const activeItem = groups.flatMap((g) => g.items.map((item) => ({ group: g, item }))).find(({ item }) => matches(item))
  const many = me.manufacturers.length > 1
  const who = current.staff_view ? 'OEM Dealer Hub staff' : current.dealer ? current.dealer.name : roleName(current.role)

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

  function toggleGroup(title: string) {
    // On the icon rail a group has no room to unfold, so the sidebar opens out first.
    if (rail) {
      setCollapsed(false)
      setOpenGroup(title)
      return
    }
    setOpenGroup((open) => (open === title ? null : title))
  }

  const navLink = (item: NavItem, withIcon: boolean) => (
    // Machines stays highlighted on a machine's own page; the dashboard only on itself.
    <NavLink
      key={item.to}
      to={item.to}
      end={item.to === ''}
      className={({ isActive }) => (isActive || matches(item) ? 'active' : '')}
      data-tour={`nav-${item.to || 'dashboard'}`}
      title={rail ? item.label : undefined}
    >
      {withIcon && <item.icon size={18} strokeWidth={1.75} aria-hidden />}
      <span className="side-text">{item.label}</span>
    </NavLink>
  )

  return (
    // On a phone the tour points at menu items, so the menu stays open while it runs.
    <div className={`shell${menuOpen || showTour ? ' menu-open' : ''}${rail ? ' side-collapsed' : ''}`}>
      <aside className="sidebar">
        <div className="side-head">
          <span className="side-product">
            <img className="side-mark" src="/favicon.svg" alt="" width={26} height={26} />
            <span className="side-text">OEM Dealer Hub</span>
          </span>
          <button className="side-collapse" onClick={() => setCollapsed((c) => !c)} title={rail ? 'Expand the sidebar' : 'Collapse the sidebar'}>
            {rail ? <ChevronsRight size={18} aria-hidden /> : <ChevronsLeft size={18} aria-hidden />}
            <span className="sr-only">{rail ? 'Expand the sidebar' : 'Collapse the sidebar'}</span>
          </button>
          <button className="side-menu-button" aria-expanded={menuOpen} aria-controls="side-nav" onClick={() => setMenuOpen((o) => !o)}>
            {menuOpen ? <X size={20} aria-hidden /> : <Menu size={20} aria-hidden />}
            <span className="sr-only">Menu</span>
          </button>
        </div>

        {/* Whose account this is. With more than one brand it's also the switcher - one login across every manufacturer. */}
        <div className="side-brand-wrap" ref={brandsRef}>
          <span className="side-label side-text">{current.acting_as === 'dealer' ? 'Dealer portal' : 'Manufacturer'}</span>
          {(() => {
            const card = (
              <>
                {/* A logo that won't load just isn't shown - no broken-image box on every page. */}
                {current.logo_url ? (
                  <span className="side-logo">
                    <img src={current.logo_url} alt="" onError={(e) => (e.currentTarget.style.display = 'none')} />
                  </span>
                ) : (
                  <span className="side-logo side-logo-initials" aria-hidden>
                    {initials(current.name)}
                  </span>
                )}
                <span className="side-names side-text">
                  <strong>{current.name}</strong>
                  <small>{current.dealer ? current.dealer.name : current.plan === 'trial' ? 'Free trial' : current.plan === 'pro' ? 'Pro plan' : 'Starter plan'}</small>
                </span>
                {many && <ChevronRight className="side-text" size={16} aria-hidden />}
              </>
            )
            return many ? (
              <button className="side-brand-card" data-tour="switcher" aria-haspopup="menu" aria-expanded={brandsOpen} onClick={() => setBrandsOpen((o) => !o)} title={rail ? current.name : 'Switch brand'}>
                {card}
              </button>
            ) : (
              <div className="side-brand-card" title={rail ? current.name : undefined}>
                {card}
              </div>
            )
          })()}
          {brandsOpen && (
            <div className="side-pop side-pop-brands" role="menu" aria-label="Switch brand">
              <span className="side-pop-label">Switch brand</span>
              {me.manufacturers.map((m) => (
                <button key={m.slug} role="menuitem" className={m.slug === current.slug ? 'is-current' : ''} onClick={() => navigate(`/m/${m.slug}`)}>
                  <span>{m.name}</span>
                  {m.slug === current.slug && <Check size={16} aria-hidden />}
                </button>
              ))}
            </div>
          )}
        </div>

        <nav className="side-nav" id="side-nav" aria-label="Main">
          {groups.map((group) => {
            if (group.items.length === 1) return navLink(group.items[0], true)
            const open = showTour || (!rail && openGroup === group.title)
            const isActive = activeGroup === group.title
            const id = `nav-group-${group.title.replace(/\W+/g, '-').toLowerCase()}`
            return (
              <div className={`side-group${open ? ' is-open' : ''}${isActive ? ' is-active' : ''}`} key={group.title}>
                <button className="side-group-head" aria-expanded={open} aria-controls={id} onClick={() => toggleGroup(group.title)} title={rail ? group.title : undefined}>
                  <group.icon size={18} strokeWidth={1.75} aria-hidden />
                  <span className="side-text">{group.title}</span>
                  <ChevronDown className="side-caret side-text" size={16} aria-hidden />
                </button>
                {open && (
                  <div className="side-sub" id={id}>
                    {group.items.map((item) => navLink(item, false))}
                  </div>
                )}
              </div>
            )
          })}
        </nav>

        <div className="side-foot" ref={userRef}>
          {userOpen && (
            <div className="side-pop side-pop-user" role="menu" aria-label="Your menu">
              {/* Their own account: how they sign in, remembered browsers. */}
              <Link role="menuitem" to="account">
                <UserRound size={16} aria-hidden /> Your account
              </Link>
              {!paused && (
                <button
                  role="menuitem"
                  onClick={() => {
                    setUserOpen(false)
                    setTouring(true)
                  }}
                >
                  <CircleHelp size={16} aria-hidden /> Take the tour
                </button>
              )}
              {me.user.console_url && (
                <a role="menuitem" href={me.user.console_url}>
                  <ShieldCheck size={16} aria-hidden /> Staff console
                </a>
              )}
              <button role="menuitem" onClick={() => logout().then(() => navigate('/login'))}>
                <LogOut size={16} aria-hidden /> Sign out
              </button>
            </div>
          )}
          <button className="side-user" aria-haspopup="menu" aria-expanded={userOpen} onClick={() => setUserOpen((o) => !o)} title={rail ? me.user.name : undefined}>
            <span className="avatar" aria-hidden>
              {initials(me.user.name)}
            </span>
            <span className="side-names side-text">
              <strong>{me.user.name}</strong>
              <small>{who}</small>
            </span>
            <ChevronUp className="side-text" size={16} aria-hidden />
          </button>
        </div>
      </aside>

      <div className="page-area">
        {/* Where you are: the section's icon, then group › page. */}
        <div className="app-bar">
          <nav className="crumbs" aria-label="You are here">
            {activeItem ? (
              <>
                <activeItem.group.icon size={16} strokeWidth={1.75} aria-hidden />
                {activeItem.group.items.length > 1 && (
                  <>
                    <span>{activeItem.group.title}</span>
                    <ChevronRight size={14} aria-hidden className="crumb-sep" />
                  </>
                )}
                <strong>{activeItem.item.label}</strong>
              </>
            ) : section === 'account' ? (
              <>
                <UserRound size={16} strokeWidth={1.75} aria-hidden />
                <strong>Your account</strong>
              </>
            ) : null}
          </nav>
        </div>

        <main className="content">
          {/* Staff looking in must never mistake it for their own account, or think a change went through. */}
          {current.staff_view && (
            <p className="staff-view-note" role="note">
              <ShieldCheck size={16} strokeWidth={2} aria-hidden />
              <span>
                You're viewing <strong>{current.name}</strong> as OEM Dealer Hub staff. It's read-only, except on Integrations, where you can make changes after saying why.
              </span>
            </p>
          )}
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
      </div>

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
