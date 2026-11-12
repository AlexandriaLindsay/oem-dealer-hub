import { useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import QRCode from 'qrcode'
import { api, ApiError, type Me } from '../lib/api'
import { useAuth } from '../lib/auth'
import { Field, FormError, formValues } from '../components/Form'
import { Turnstile } from '../components/Turnstile'
import { useSite } from '../lib/site'

function AuthCard({ title, children, footer }: { title: string; children: ReactNode; footer?: ReactNode }) {
  return (
    <main className="auth">
      <div className="auth-card">
        <div className="brand">
          <img src="/favicon.svg" alt="" width={28} height={28} />
          <span>OEM Dealer Hub</span>
        </div>
        <h1>{title}</h1>
        {children}
      </div>
      {footer && <p className="auth-footer">{footer}</p>}
    </main>
  )
}

/** Runs a form submit, keeping the busy flag and the API error. */
function useSubmit(onError?: () => void) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)

  async function run(action: () => Promise<void>) {
    setBusy(true)
    setError(null)
    try {
      await action()
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(0, { message: 'Could not reach OEM Dealer Hub. Check your connection.' }))
      onError?.()
    } finally {
      setBusy(false)
    }
  }

  return { busy, error, run }
}

/** The bot check's token for a form, refreshed after each failed submit (a token works once). */
function useBotCheck() {
  const site = useSite()
  const [token, setToken] = useState('')
  const [resetKey, setResetKey] = useState(0)
  const siteKey = site?.turnstile_site_key ?? null
  return {
    siteKey,
    token,
    setToken,
    resetKey,
    reset: () => setResetKey((k) => k + 1),
    /** Waiting on the check: the submit button stays off until it's done. */
    waiting: siteKey !== null && token === '',
    field: siteKey ? { turnstile_token: token } : {},
  }
}

export function LoginPage() {
  const { setMe } = useAuth()
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const [step, setStep] = useState<'password' | 'code'>('password')
  const bot = useBotCheck()
  const { busy, error, run } = useSubmit(bot.reset)

  // The staff console sends signed-out staff here with ?next=console. Only
  // that one value, and only to the address the API gives staff, so this
  // can't be used to send anyone to another site.
  function signedIn(me: Me) {
    setMe(me)
    if (params.get('next') === 'console' && me.user.console_url && me.user.two_factor) {
      window.location.assign(me.user.console_url)
    } else {
      navigate('/')
    }
  }

  function onPassword(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const v = formValues(e.currentTarget)
    run(async () => {
      const result = await api.post<Me | { two_factor: true }>('/login', { email: v.email, password: v.password, remember: v.remember === 'on', ...bot.field })
      if ('two_factor' in result) {
        setStep('code')
      } else {
        signedIn(result)
      }
    })
  }

  function onCode(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const v = formValues(e.currentTarget)
    run(async () => {
      signedIn(await api.post<Me>('/login/two-factor', { code: v.code }))
    })
  }

  if (step === 'code') {
    return (
      <AuthCard title="Enter your code">
        {/* Its own key: without it React reuses the password step's input, so the
            password would show here in plain text and fill the code box. */}
        <form key="code" onSubmit={onCode} noValidate>
          <p className="lede">Open your authenticator app and enter the 6-digit code for OEM Dealer Hub.</p>
          <Field label="Code" name="code" error={error} inputMode="numeric" autoComplete="one-time-code" autoFocus required maxLength={7} />
          <FormError error={error} />
          <button className="primary" disabled={busy}>
            {busy ? 'Checking…' : 'Sign in'}
          </button>
          <button type="button" className="link" onClick={() => setStep('password')}>
            Use a different account
          </button>
        </form>
      </AuthCard>
    )
  }

  return (
    <AuthCard
      title="Sign in"
      footer={
        <>
          New manufacturer? <Link to="/signup">Start a free trial</Link>
        </>
      }
    >
      <form key="password" onSubmit={onPassword} noValidate>
        <Field label="Email" name="email" type="email" error={error} autoComplete="email" autoFocus required />
        <Field label="Password" name="password" type="password" error={error} autoComplete="current-password" required />
        <label className="check">
          <input type="checkbox" name="remember" /> Keep me signed in
        </label>
        <Turnstile siteKey={bot.siteKey} onToken={bot.setToken} resetKey={bot.resetKey} error={error?.field('turnstile_token')} />
        <FormError error={error} />
        <button className="primary" disabled={busy || bot.waiting}>
          {busy ? 'Signing in…' : 'Sign in'}
        </button>
        <Link to="/forgot-password" className="small-link">
          Forgot your password?
        </Link>
      </form>
    </AuthCard>
  )
}

export function SignupPage() {
  const { setMe } = useAuth()
  const navigate = useNavigate()
  const site = useSite()
  const bot = useBotCheck()
  const { busy, error, run } = useSubmit(bot.reset)

  function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const v = formValues(e.currentTarget)
    run(async () => {
      setMe(await api.post<Me>('/register', { ...v, accept_terms: v.accept_terms === 'on', ...bot.field }))
      navigate('/')
    })
  }

  return (
    <AuthCard
      title="Start your free trial"
      footer={
        <>
          Already on OEM Dealer Hub? <Link to="/login">Sign in</Link>
        </>
      }
    >
      <p className="lede">{site?.trial_days ?? 14} days of Pro, free. No card needed. Your dealers never pay.</p>
      <form onSubmit={onSubmit} noValidate>
        <Field label="Company name" name="company" error={error} autoComplete="organization" autoFocus required />
        <Field label="Your name" name="name" error={error} autoComplete="name" required />
        <Field label="Work email" name="email" type="email" error={error} autoComplete="email" required />
        <Field label="Password" name="password" type="password" error={error} autoComplete="new-password" hint="At least 10 characters." required />
        <div className="field">
          <label className="check terms-check">
            <input type="checkbox" name="accept_terms" aria-invalid={!!error?.field('accept_terms')} />
            <span>
              I agree to the{' '}
              <Link to="/terms" target="_blank">
                terms
              </Link>{' '}
              and{' '}
              <Link to="/privacy" target="_blank">
                privacy policy
              </Link>
              , for my company and myself.
            </span>
          </label>
          {error?.field('accept_terms') && <p className="field-error">{error.field('accept_terms')}</p>}
        </div>
        <Turnstile siteKey={bot.siteKey} onToken={bot.setToken} resetKey={bot.resetKey} error={error?.field('turnstile_token')} />
        <FormError error={error} />
        <button className="primary" disabled={busy || bot.waiting}>
          {busy ? 'Creating your account…' : 'Start free trial'}
        </button>
      </form>
    </AuthCard>
  )
}

export function ForgotPasswordPage() {
  const [sent, setSent] = useState<string | null>(null)
  const bot = useBotCheck()
  const { busy, error, run } = useSubmit(bot.reset)

  function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const v = formValues(e.currentTarget)
    run(async () => {
      const r = await api.post<{ message: string }>('/forgot-password', { email: v.email, ...bot.field })
      setSent(r.message)
    })
  }

  return (
    <AuthCard title="Reset your password" footer={<Link to="/login">Back to sign in</Link>}>
      {sent ? (
        <p className="notice">{sent}</p>
      ) : (
        <form onSubmit={onSubmit} noValidate>
          <p className="lede">We'll email you a link to set a new password.</p>
          <Field label="Email" name="email" type="email" error={error} autoComplete="email" autoFocus required />
          <Turnstile siteKey={bot.siteKey} onToken={bot.setToken} resetKey={bot.resetKey} error={error?.field('turnstile_token')} />
          <FormError error={error} />
          <button className="primary" disabled={busy || bot.waiting}>
            {busy ? 'Sending…' : 'Send reset link'}
          </button>
        </form>
      )}
    </AuthCard>
  )
}

/** Also where invited people land to set their first password. */
export function ResetPasswordPage() {
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const { busy, error, run } = useSubmit()

  function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const v = formValues(e.currentTarget)
    run(async () => {
      await api.post('/reset-password', { token: params.get('token'), email: params.get('email'), password: v.password })
      navigate('/login', { replace: true })
    })
  }

  return (
    <AuthCard title="Set your password">
      <form onSubmit={onSubmit} noValidate>
        <p className="lede">For {params.get('email')}.</p>
        <Field label="New password" name="password" type="password" error={error} autoComplete="new-password" hint="At least 10 characters." autoFocus required />
        {error?.field('email') && <p className="form-error">{error.field('email')}</p>}
        <FormError error={error} />
        <button className="primary" disabled={busy}>
          {busy ? 'Saving…' : 'Set password'}
        </button>
      </form>
    </AuthCard>
  )
}

export function TwoFactorSetupPage() {
  const { refresh, logout } = useAuth()
  const navigate = useNavigate()
  const [setup, setSetup] = useState<{ secret: string; qr: string } | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const { busy, error, run } = useSubmit()

  useEffect(() => {
    api
      .post<{ secret: string; otpauth_url: string }>('/two-factor/setup')
      .then(async (r) => setSetup({ secret: r.secret, qr: await QRCode.toDataURL(r.otpauth_url, { margin: 1, width: 200 }) }))
      .catch((e) => setLoadError(e instanceof ApiError ? e.message : 'Could not start setup.'))
  }, [])

  function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const v = formValues(e.currentTarget)
    run(async () => {
      await api.post('/two-factor/confirm', { code: v.code })
      await refresh()
      navigate('/', { replace: true })
    })
  }

  return (
    <AuthCard
      title="Set up two-factor sign-in"
      footer={
        <button type="button" className="link" onClick={() => logout()}>
          Sign out
        </button>
      }
    >
      <p className="lede">
        OEM Dealer Hub protects your company's dealer data with a second step at sign-in. Scan this code with an authenticator app (Microsoft Authenticator,
        Google Authenticator, 1Password…), then enter the code it shows.
      </p>
      {loadError && <p className="form-error">{loadError}</p>}
      {setup && (
        <>
          <img className="qr" src={setup.qr} alt="QR code for your authenticator app" width={200} height={200} />
          <details className="secret">
            <summary>Can't scan it?</summary>
            <p>
              Enter this key in the app instead: <code>{setup.secret.match(/.{1,4}/g)?.join(' ')}</code>
            </p>
          </details>
          <form onSubmit={onSubmit} noValidate>
            <Field label="Code from the app" name="code" error={error} inputMode="numeric" autoComplete="one-time-code" required maxLength={7} />
            <FormError error={error} />
            <button className="primary" disabled={busy}>
              {busy ? 'Checking…' : 'Turn on two-factor sign-in'}
            </button>
          </form>
        </>
      )}
    </AuthCard>
  )
}
