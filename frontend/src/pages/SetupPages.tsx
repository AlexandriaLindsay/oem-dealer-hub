import { useState, type ChangeEvent } from 'react'
import { Link } from 'react-router-dom'
import { api, ApiError } from '../lib/api'
import { useAuth } from '../lib/auth'
import { useApi } from '../lib/useApi'
import { useSlug } from '../lib/tenant'
import { useCurrentManufacturer } from '../components/Layout'
import { brandTokens, isHexColor, type BrandTokens } from '../lib/brand'

interface Onboarding {
  steps: { key: string; title: string; help: string; link: string; done: boolean }[]
  complete: boolean
  dismissed: boolean
}

function asApiError(e: unknown): ApiError {
  return e instanceof ApiError ? e : new ApiError(0, { message: 'Could not reach OEM Dealer Hub.' })
}

/**
 * The "Getting started" checklist on the dashboard, for owners and admins.
 * Every tick comes from real data (App\Support\Onboarding). Gone once it's
 * all done, or when hidden.
 */
export function GettingStarted() {
  const slug = useSlug()
  const current = useCurrentManufacturer()
  const allowed = !!current?.features.includes('setup')
  const { data, reload } = useApi<Onboarding>(allowed ? `/m/${slug}/onboarding` : null)

  if (!data || data.complete || data.dismissed) return null
  const done = data.steps.filter((s) => s.done).length

  return (
    <section className="panel getting-started" data-tour="checklist" aria-labelledby="getting-started-title">
      <div className="chart-head">
        <h2 id="getting-started-title">Getting started</h2>
        <button className="link" onClick={() => api.post(`/m/${slug}/onboarding`, { dismissed: true }).then(reload)}>
          Hide this list
        </button>
      </div>
      <p className="muted">
        {done} of {data.steps.length} done. Each step ticks itself off as you go.
      </p>
      <span className="meter" aria-hidden>
        <span style={{ width: `${(done / data.steps.length) * 100}%` }} />
      </span>
      <ol className="steps">
        {data.steps.map((s) => (
          <li key={s.key} className={s.done ? 'is-done' : ''}>
            <span className="step-mark" aria-hidden>
              {s.done ? '✓' : ''}
            </span>
            <span>
              {s.done ? (
                <>
                  <span className="step-title">{s.title}</span> <span className="sr-only">(done)</span>
                </>
              ) : (
                <Link to={s.link} className="step-title">
                  {s.title}
                </Link>
              )}
              <span className="muted small step-help">{s.help}</span>
            </span>
          </li>
        ))}
      </ol>
    </section>
  )
}

// ---- Settings: logo, and the checklist ----

export function SettingsPage() {
  const slug = useSlug()
  const current = useCurrentManufacturer()
  const { refresh } = useAuth()
  const onboarding = useApi<Onboarding>(`/m/${slug}/onboarding`)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const [pickerKey, setPickerKey] = useState(0)

  async function onLogo(e: ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0]
    if (!file) return
    const body = new FormData()
    body.set('logo', file)
    setBusy(true)
    setError(null)
    try {
      await api.post(`/m/${slug}/logo`, body)
      // /me carries the logo's address, so the top bar picks it up.
      await refresh()
    } catch (err) {
      setError(asApiError(err))
    } finally {
      setBusy(false)
      setPickerKey((k) => k + 1)
    }
  }

  async function onRemove() {
    if (!window.confirm('Remove your logo? OEM Dealer Hub\'s own will show instead.')) return
    setBusy(true)
    try {
      await api.delete(`/m/${slug}/logo`)
      await refresh()
    } catch (err) {
      setError(asApiError(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <h1>Settings</h1>
      <p className="lede">How {current?.name} looks to your staff and dealers.</p>

      <section className="panel">
        <h2>Logo</h2>
        <div className="logo-row">
          <div className="logo-preview">{current?.logo_url ? <img src={current.logo_url} alt={`${current.name} logo`} onError={(e) => (e.currentTarget.style.display = 'none')} /> : <span className="muted">No logo yet</span>}</div>
          <div>
            <div className="field">
              <label htmlFor="logo-file">{current?.logo_url ? 'Replace the logo' : 'Upload a logo'}</label>
              <input key={pickerKey} id="logo-file" type="file" accept=".png,.jpg,.jpeg,image/png,image/jpeg" onChange={onLogo} disabled={busy} aria-invalid={!!error?.field('logo')} />
              {error?.field('logo') ? (
                <p className="field-error">{error.field('logo')}</p>
              ) : (
                <p className="hint">PNG or JPG, under 1 MB. A wide logo on a transparent or white background works best; it shows about 28 pixels tall.</p>
              )}
            </div>
            {error && !error.field('logo') && <p className="form-error">{error.message}</p>}
            {busy && <p className="muted">Uploading and checking…</p>}
            {current?.logo_url && (
              <button className="link danger-link" onClick={onRemove} disabled={busy}>
                Remove the logo
              </button>
            )}
          </div>
        </div>
      </section>

      {current?.features.includes('custom_branding') && <BrandColours />}

      {onboarding.data && (
        <section className="panel">
          <h2>Getting started checklist</h2>
          {onboarding.data.complete ? (
            <p className="muted">Every step is done.</p>
          ) : onboarding.data.dismissed ? (
            <>
              <p className="muted">The checklist is hidden from your dashboard.</p>
              <button className="secondary" onClick={() => api.post(`/m/${slug}/onboarding`, { dismissed: false }).then(onboarding.reload)}>
                Show it again
              </button>
            </>
          ) : (
            <p className="muted">
              It's on your <Link to="..">dashboard</Link>.
            </p>
          )}
        </section>
      )}
    </>
  )
}

/**
 * The brand's own colour (Pro). Previews light and dark exactly as the app
 * will show them - adjusted for readable text where needed - before saving.
 */
function BrandColours() {
  const slug = useSlug()
  const current = useCurrentManufacturer()
  const { refresh } = useAuth()
  const saved = current?.brand_color ?? null
  const [value, setValue] = useState(saved ?? DEALERDOCK_BLUE)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const valid = isHexColor(value)
  const brand = brandTokens(valid ? value : DEALERDOCK_BLUE)
  const changed = valid && value.toLowerCase() !== (saved ?? DEALERDOCK_BLUE)

  async function save(color: string | null) {
    setBusy(true)
    setError(null)
    try {
      await api.post(`/m/${slug}/brand`, { brand_color: color })
      // /me carries the colour, so the whole app picks it up.
      await refresh()
      if (color === null) setValue(DEALERDOCK_BLUE)
    } catch (err) {
      setError(asApiError(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <section className="panel">
      <h2>Colour</h2>
      <p className="muted">Your brand's colour for buttons, links and the menu - for your staff and your dealers.</p>
      <div className="brand-row">
        <div className="field brand-pick">
          <label htmlFor="brand-hex">Colour</label>
          <div className="brand-inputs">
            <input type="color" aria-label="Pick a colour" value={valid ? value : DEALERDOCK_BLUE} onChange={(e) => setValue(e.target.value)} disabled={busy} />
            <input id="brand-hex" value={value} onChange={(e) => setValue(e.target.value.trim())} maxLength={7} spellCheck={false} aria-invalid={!valid || !!error?.field('brand_color')} disabled={busy} />
          </div>
          {!valid ? (
            <p className="field-error">Use a colour like #1d4ed8.</p>
          ) : error ? (
            <p className="field-error">{error.field('brand_color') ?? error.message}</p>
          ) : (
            <p className="hint">{adjustedNote(brand.adjusted)}</p>
          )}
        </div>
        <div className="brand-previews">
          <BrandPreview mode="light" tokens={brand.light} />
          <BrandPreview mode="dark" tokens={brand.dark} />
        </div>
      </div>
      <div className="brand-actions">
        <button className="primary" disabled={busy || !changed} onClick={() => save(value.toLowerCase() === DEALERDOCK_BLUE ? null : value)}>
          {busy ? 'Saving…' : 'Save colour'}
        </button>
        {saved && (
          <button className="link" disabled={busy} onClick={() => save(null)}>
            Use OEM Dealer Hub blue
          </button>
        )}
      </div>
    </section>
  )
}

const DEALERDOCK_BLUE = '#1d4ed8'

function adjustedNote(adjusted: { light: boolean; dark: boolean }): string {
  if (adjusted.light && adjusted.dark) return "Darkened a little in light mode and lightened in dark mode, so text on and around it stays easy to read."
  if (adjusted.light) return 'Darkened a little in light mode, so text on and around it stays easy to read.'
  if (adjusted.dark) return 'Lightened a little in dark mode, so text on and around it stays easy to read.'
  return 'Used as it is - text on and around it reads well in light and dark mode.'
}

/** A small mock of the app in one mode, drawn with that mode's adjusted colours. */
function BrandPreview({ mode, tokens }: { mode: 'light' | 'dark'; tokens: BrandTokens }) {
  return (
    <figure className={`brand-preview brand-preview-${mode}`} aria-label={`${mode === 'light' ? 'Light' : 'Dark'} mode preview`}>
      <span className="brand-preview-nav" style={{ background: tokens.accentSoft, color: tokens.accent }}>
        Machines
      </span>
      <span className="brand-preview-button" style={{ background: tokens.accent, color: tokens.accentText }}>
        Save
      </span>
      <span className="brand-preview-link" style={{ color: tokens.accent }}>
        View machine
      </span>
      <figcaption>
        {mode === 'light' ? 'Light' : 'Dark'} · {tokens.accent}
      </figcaption>
    </figure>
  )
}
