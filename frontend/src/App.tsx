import type { ReactNode } from 'react'
import { BrowserRouter, Navigate, Route, Routes, useParams } from 'react-router-dom'
import { AuthProvider, needsTwoFactorSetup, useAuth } from './lib/auth'
import { Layout } from './components/Layout'
import { ForgotPasswordPage, LoginPage, ResetPasswordPage, SignupPage, TwoFactorSetupPage } from './pages/AuthPages'
import { DashboardPage, DealersPage, ProductsPage, StaffPage } from './pages/TenantPages'
import { MachinePage, MachinesPage } from './pages/MachinePages'
import { FleetPage } from './pages/FleetPage'
import { UsagePage } from './pages/UsagePage'
import { RegistrationPage, RegistrationsPage } from './pages/RegistrationPages'
import { LibraryPage } from './pages/LibraryPages'
import { ImportPage } from './pages/ImportPage'
import { SettingsPage } from './pages/SetupPages'
import { BillingPage } from './pages/BillingPage'
import { CatalogPage, PriceListPage, PriceListsPage } from './pages/PricingPages'
import { QuotePage, QuotePrintPage, QuotesPage } from './pages/QuotePages'
import { NewPartsOrderPage, PartsOrderPage, PartsOrdersPage } from './pages/PartsOrderPages'
import { AnnouncementPage, AnnouncementsPage, BroadcastPage, BroadcastsPage } from './pages/BroadcastPages'
import { LandingPage, PricingPage } from './pages/PublicPages'
import { AcceptTermsPage, PrivacyPage, TermsPage } from './pages/LegalPages'

/**
 * Signed out -> sign in; no two-factor yet -> set it up; the current terms
 * not agreed yet -> agree; otherwise the page. The API enforces the same
 * steps; this just goes straight to the right page.
 */
function RequireAuth({ children, allowWithoutTwoFactor = false, allowWithoutTerms = false }: { children: ReactNode; allowWithoutTwoFactor?: boolean; allowWithoutTerms?: boolean }) {
  const { me } = useAuth()

  if (me === undefined) return <p className="muted center">Loading…</p>
  if (me === null) return <Navigate to="/login" replace />
  if (!allowWithoutTwoFactor && needsTwoFactorSetup(me)) return <Navigate to="/setup-2fa" replace />
  if (!allowWithoutTerms && me.user.terms_version !== me.terms_version) return <Navigate to="/accept-terms" replace />

  return <>{children}</>
}

/** "/": the landing page for visitors; straight into the app for people signed in. */
function Root() {
  const { me } = useAuth()
  if (me === undefined) return <p className="muted center">Loading…</p>
  if (me === null) return <LandingPage />
  return (
    <RequireAuth>
      <Home />
    </RequireAuth>
  )
}

/** Signed-in pages for signed-out people only (sign in, sign up). */
function GuestOnly({ children }: { children: ReactNode }) {
  const { me } = useAuth()
  if (me === undefined) return <p className="muted center">Loading…</p>
  return me ? <Navigate to="/" replace /> : <>{children}</>
}

/** "/" - straight into the first manufacturer they work with. */
function Home() {
  const { me } = useAuth()
  const first = me?.manufacturers[0]

  if (!first) {
    return (
      <main className="auth">
        <div className="auth-card">
          <h1>No access yet</h1>
          <p className="lede">Your account isn't linked to a manufacturer or dealer yet. Ask whoever invited you to check your access.</p>
        </div>
      </main>
    )
  }

  return <Navigate to={`/m/${first.slug}`} replace />
}

/** A manufacturer the user doesn't work with goes back to "/". */
function KnownManufacturer({ children }: { children: ReactNode }) {
  const { me } = useAuth()
  const { slug } = useParams()
  return me?.manufacturers.some((m) => m.slug === slug) ? <>{children}</> : <Navigate to="/" replace />
}

export default function App() {
  return (
    <AuthProvider>
      <BrowserRouter>
        <Routes>
          <Route path="/login" element={<GuestOnly><LoginPage /></GuestOnly>} />
          <Route path="/signup" element={<GuestOnly><SignupPage /></GuestOnly>} />
          <Route path="/forgot-password" element={<ForgotPasswordPage />} />
          <Route path="/reset-password" element={<ResetPasswordPage />} />
          <Route path="/setup-2fa" element={<RequireAuth allowWithoutTwoFactor allowWithoutTerms><TwoFactorSetupPage /></RequireAuth>} />
          <Route path="/accept-terms" element={<RequireAuth allowWithoutTerms><AcceptTermsPage /></RequireAuth>} />
          <Route path="/" element={<Root />} />
          {/* Public: anyone can read these, signed in or not. */}
          <Route path="/pricing" element={<PricingPage />} />
          <Route path="/privacy" element={<PrivacyPage />} />
          <Route path="/terms" element={<TermsPage />} />
          <Route
            path="/m/:slug"
            element={
              <RequireAuth>
                <KnownManufacturer>
                  <Layout />
                </KnownManufacturer>
              </RequireAuth>
            }
          >
            <Route index element={<DashboardPage />} />
            <Route path="products" element={<ProductsPage />} />
            <Route path="machines" element={<MachinesPage />} />
            <Route path="machines/import" element={<ImportPage />} />
            <Route path="machines/:id" element={<MachinePage />} />
            <Route path="fleet" element={<FleetPage />} />
            <Route path="registrations" element={<RegistrationsPage />} />
            <Route path="registrations/:id" element={<RegistrationPage />} />
            <Route path="documents" element={<LibraryPage />} />
            <Route path="dealers" element={<DealersPage />} />
            <Route path="staff" element={<StaffPage />} />
            <Route path="settings" element={<SettingsPage />} />
            <Route path="billing" element={<BillingPage />} />
            <Route path="prices" element={<CatalogPage />} />
            <Route path="quotes" element={<QuotesPage />} />
            <Route path="quotes/:id" element={<QuotePage />} />
            <Route path="parts-orders" element={<PartsOrdersPage />} />
            <Route path="parts-orders/new" element={<NewPartsOrderPage />} />
            <Route path="parts-orders/:id" element={<PartsOrderPage />} />
            <Route path="broadcasts" element={<BroadcastsPage />} />
            <Route path="usage" element={<UsagePage />} />
            <Route path="broadcasts/:id" element={<BroadcastPage />} />
            <Route path="announcements" element={<AnnouncementsPage />} />
            <Route path="announcements/:id" element={<AnnouncementPage />} />
            <Route path="price-lists" element={<PriceListsPage />} />
            <Route path="price-lists/:id" element={<PriceListPage />} />
          </Route>
          {/* The printable quote: no app frame around it, same sign-in checks. */}
          <Route
            path="/m/:slug/quotes/:id/print"
            element={
              <RequireAuth>
                <KnownManufacturer>
                  <QuotePrintPage />
                </KnownManufacturer>
              </RequireAuth>
            }
          />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </BrowserRouter>
    </AuthProvider>
  )
}
