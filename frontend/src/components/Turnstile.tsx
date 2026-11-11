import { useEffect, useRef } from 'react'

/*
 * Cloudflare Turnstile, the "verify you are human" check on sign-up, sign-in
 * and password reset. Renders nothing when the server hasn't switched it on
 * (no site key). Each token works once, so bump `resetKey` after a failed
 * submit to get a fresh one.
 */

interface TurnstileApi {
  render: (el: HTMLElement, options: Record<string, unknown>) => string
  reset: (id: string) => void
  remove: (id: string) => void
}

declare global {
  interface Window {
    turnstile?: TurnstileApi
  }
}

let script: Promise<TurnstileApi> | null = null

function loadScript(): Promise<TurnstileApi> {
  script ??= new Promise((resolve, reject) => {
    const el = document.createElement('script')
    el.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit'
    el.async = true
    el.onload = () => (window.turnstile ? resolve(window.turnstile) : reject(new Error('Turnstile did not load')))
    el.onerror = () => {
      script = null
      reject(new Error('Turnstile did not load'))
    }
    document.head.appendChild(el)
  })
  return script
}

export function Turnstile({ siteKey, onToken, resetKey = 0, error }: { siteKey: string | null; onToken: (token: string) => void; resetKey?: number; error?: string }) {
  const box = useRef<HTMLDivElement>(null)
  const widget = useRef<string | null>(null)
  const callback = useRef(onToken)
  callback.current = onToken

  useEffect(() => {
    if (!siteKey || !box.current) return
    let cancelled = false
    loadScript()
      .then((ts) => {
        if (cancelled || !box.current) return
        widget.current = ts.render(box.current, {
          sitekey: siteKey,
          theme: 'auto',
          callback: (token: string) => callback.current(token),
          'expired-callback': () => callback.current(''),
          'error-callback': () => callback.current(''),
        })
      })
      .catch(() => callback.current(''))
    return () => {
      cancelled = true
      if (widget.current) window.turnstile?.remove(widget.current)
      widget.current = null
    }
  }, [siteKey])

  // A used token can't be sent again: get a new one after a failed submit.
  useEffect(() => {
    if (resetKey > 0 && widget.current) {
      window.turnstile?.reset(widget.current)
      callback.current('')
    }
  }, [resetKey])

  if (!siteKey) return null
  return (
    <div className="field">
      <div ref={box} className="turnstile" />
      {error && <p className="field-error">{error}</p>}
    </div>
  )
}
