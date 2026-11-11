import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react'
import { api, ApiError, type Me } from './api'

interface AuthState {
  /** undefined while the first /me is loading; null when signed out. */
  me: Me | null | undefined
  setMe: (me: Me | null) => void
  refresh: () => Promise<void>
  logout: () => Promise<void>
}

const AuthContext = createContext<AuthState | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [me, setMe] = useState<Me | null | undefined>(undefined)

  const refresh = useCallback(async () => {
    try {
      setMe(await api.get<Me>('/me'))
    } catch (e) {
      if (e instanceof ApiError && e.status === 401) setMe(null)
      else throw e
    }
  }, [])

  const logout = useCallback(async () => {
    await api.post('/logout')
    setMe(null)
  }, [])

  useEffect(() => {
    refresh().catch(() => setMe(null))
  }, [refresh])

  // An API call came back "account paused" (402): fetch /me again so the app shows it.
  useEffect(() => {
    const onChange = () => refresh().catch(() => {})
    window.addEventListener('dealerdock:standing-changed', onChange)
    return () => window.removeEventListener('dealerdock:standing-changed', onChange)
  }, [refresh])

  return <AuthContext.Provider value={{ me, setMe, refresh, logout }}>{children}</AuthContext.Provider>
}

export function useAuth(): AuthState {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth outside AuthProvider')
  return ctx
}

/** Whether this user still has to set up two-factor before reaching any data. */
export function needsTwoFactorSetup(me: Me): boolean {
  return me.user.two_factor_required && !me.user.two_factor
}
