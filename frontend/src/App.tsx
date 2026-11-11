import type { ReactNode } from 'react'
import { BrowserRouter, Navigate, Route, Routes, useParams } from 'react-router-dom'
import { AuthProvider, needsTwoFactorSetup, useAuth } from './lib/auth'
import { Layout } from './components/Layout'
import { ForgotPasswordPage, LoginPage, ResetPasswordPage, SignupPage, TwoFactorSetupPage } from './pages/AuthPages'
import { DashboardPage, DealersPage, ProductsPage, StaffPage } from './pages/TenantPages'
import { MachinePage, MachinesPage } from './pages/MachinePages'
import { FleetPage } from './pages/FleetPage'
import { RegistrationPage, RegistrationsPage } from './pages/RegistrationPages'
import { LibraryPage } from './pages/LibraryPages'
import { ImportPage } from './pages/ImportPage'

/** Signed out -> sign in; no two-factor yet -> set it up; otherwise the page. */
function RequireAuth({ children, allowWithoutTwoFactor = false }: { children: ReactNode; allowWithoutTwoFactor?: boolean }) {
  const { me } = useAuth()

  if (me === undefined) return <p className="muted center">Loading…</p>
  if (me === null) return <Navigate to="/login" replace />
  if (!allowWithoutTwoFactor && needsTwoFactorSetup(me)) return <Navigate to="/setup-2fa" replace />

  return <>{children}</>
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
          <Route path="/setup-2fa" element={<RequireAuth allowWithoutTwoFactor><TwoFactorSetupPage /></RequireAuth>} />
          <Route path="/" element={<RequireAuth><Home /></RequireAuth>} />
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
          </Route>
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </BrowserRouter>
    </AuthProvider>
  )
}
