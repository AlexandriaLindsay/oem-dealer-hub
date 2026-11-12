import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { api, ApiError, type Billing, type BillingPlan } from '../lib/api'
import { useAuth } from '../lib/auth'
import { useApi } from '../lib/useApi'
import { formatDate, trialEndText, useSlug } from '../lib/tenant'
import { useCurrentManufacturer } from '../components/Layout'
import { Loading } from '../components/AddForm'

type Interval = 'monthly' | 'annual'

function asApiError(e: unknown): ApiError {
  return e instanceof ApiError ? e : new ApiError(0, { message: 'Could not reach OEM Dealer Hub.' })
}

const limitText = (n: number | null, unit: string) => (n === null ? `Unlimited ${unit}` : `Up to ${n} ${unit}`)

export function BillingPage() {
  const slug = useSlug()
  const current = useCurrentManufacturer()
  const { refresh } = useAuth()
  const [params, setParams] = useSearchParams()
  const { data, error, reload } = useApi<Billing>(`/m/${slug}/billing`, { keepPrevious: true })
  const [interval, setPeriod] = useState<Interval>('monthly')
  const [busy, setBusy] = useState<string | null>(null)
  const [actionError, setActionError] = useState<string | null>(null)
  const returning = params.get('checkout')
  const [confirming, setConfirming] = useState(returning === 'done')

  // Back from Stripe Checkout: the subscription arrives by webhook a moment
  // later, so check again every 2 seconds for up to 30.
  useEffect(() => {
    if (!confirming) return
    let tries = 0
    const timer = window.setInterval(async () => {
      tries++
      const fresh = await api.get<Billing>(`/m/${slug}/billing`).catch(() => null)
      if ((fresh && fresh.subscription) || tries >= 15) {
        window.clearInterval(timer)
        setConfirming(false)
        reload()
        refresh().catch(() => {})
      }
    }, 2000)
    return () => window.clearInterval(timer)
  }, [confirming, slug, reload, refresh])

  useEffect(() => {
    if (data?.interval) setPeriod(data.interval)
  }, [data?.interval])

  async function go(what: 'checkout' | 'change' | 'portal', plan?: BillingPlan) {
    setBusy(plan ? `${what}:${plan.key}` : what)
    setActionError(null)
    try {
      if (what === 'change') {
        await api.post(`/m/${slug}/billing/change`, { plan: plan!.key, interval })
        reload()
        await refresh()
      } else {
        const body = what === 'checkout' ? { plan: plan!.key, interval } : undefined
        const { url } = await api.post<{ url: string }>(`/m/${slug}/billing/${what}`, body)
        // Off to Stripe's own page; it sends people back here.
        window.location.assign(url)
        return
      }
    } catch (e) {
      const err = asApiError(e)
      setActionError(err.field('plan') ?? err.field('portal') ?? err.message)
    }
    setBusy(null)
  }

  if (!data) return <Loading error={error} />

  const subscribed = data.subscription !== null
  const paused = data.standing === 'lapsed'

  return (
    <>
      <h1>Plan and billing</h1>
      <p className="lede">The OEM Dealer Hub plan for {current?.name}. Dealers are always free.</p>

      {returning === 'cancelled' && (
        <div className="callout" role="status">
          No changes made - you left the payment page before finishing.{' '}
          <button className="link" onClick={() => setParams({}, { replace: true })}>
            OK
          </button>
        </div>
      )}
      {confirming && (
        <div className="callout callout-ok" role="status">
          <strong>Thanks!</strong> Confirming your payment with Stripe…
        </div>
      )}

      <StandingCallout billing={data} />

      {!data.configured && (
        <div className="callout" role="status">
          Payments aren't set up on this server yet (Stripe keys and prices), so plans can't be chosen here.
        </div>
      )}

      {data.managed_outside ? (
        <section className="panel">
          <h2>Billed by invoice</h2>
          <p className="muted">
            Your {data.plan === 'pro' ? 'Pro' : 'Starter'} plan is billed outside the app. To change it, contact OEM Dealer Hub.
          </p>
        </section>
      ) : (
        <>
          <div className="interval-toggle" role="group" aria-label="Billing period">
            {(['monthly', 'annual'] as const).map((i) => (
              <button key={i} className={interval === i ? 'is-on' : ''} aria-pressed={interval === i} onClick={() => setPeriod(i)}>
                {i === 'monthly' ? 'Monthly' : 'Annual - 2 months free'}
              </button>
            ))}
          </div>

          {actionError && (
            <p className="form-error" role="alert">
              {actionError}
            </p>
          )}

          <div className="plan-cards">
            {data.plans.map((p) => {
              const isCurrent = subscribed && data.plan === p.key && data.interval === interval
              const price = interval === 'monthly' ? p.monthly_usd : p.annual_usd
              const label = subscribed ? (data.plan === p.key ? `Switch to ${interval}` : `Switch to ${p.label}`) : `Choose ${p.label}`
              return (
                <section key={p.key} className={`panel plan-card${data.plan === p.key && !paused ? ' is-current' : ''}`} aria-labelledby={`plan-${p.key}`}>
                  <h2 id={`plan-${p.key}`}>
                    {p.label}
                    {data.plan === p.key && !paused && data.standing !== 'trialing' && <span className="badge badge-active">Your plan</span>}
                  </h2>
                  <p className="plan-price">
                    <strong>${price}</strong> / {interval === 'monthly' ? 'month' : 'year'}
                  </p>
                  <ul className="plan-features">
                    <li>{limitText(p.limits.dealers, 'dealers')}</li>
                    <li>{limitText(p.limits.staff, 'staff users')}</li>
                    <li>{p.limits.storage_gb} GB of file storage</li>
                    <li>Machines, fleet report, registrations, manuals</li>
                    {p.extras.map((x) => (
                      <li key={x.label}>
                        {x.label}
                        {x.coming && <span className="coming"> - coming soon</span>}
                      </li>
                    ))}
                  </ul>
                  {p.blockers.length > 0 && (
                    <div className="plan-blockers">
                      <strong>Not yet:</strong>
                      <ul>
                        {p.blockers.map((b) => (
                          <li key={b}>{b}</li>
                        ))}
                      </ul>
                    </div>
                  )}
                  <button
                    className="primary"
                    disabled={isCurrent || p.blockers.length > 0 || !data.configured || busy !== null}
                    onClick={() => go(subscribed ? 'change' : 'checkout', p)}
                  >
                    {isCurrent ? 'Your current plan' : busy === `checkout:${p.key}` || busy === `change:${p.key}` ? 'One moment…' : label}
                  </button>
                </section>
              )
            })}
          </div>
          {!subscribed && data.standing === 'trialing' && data.trial_ends_at && (
            <p className="hint">Choosing a plan now doesn't charge you until your trial ends on {formatDate(data.trial_ends_at)}.</p>
          )}
          {subscribed && <p className="hint">Changing plans takes effect straight away; Stripe credits or charges the difference on your next invoice.</p>}

          {(subscribed || data.card) && (
            <section className="panel">
              <h2>Payment and invoices</h2>
              <p className="muted">
                {data.card ? `Paying with ${data.card.brand ? data.card.brand.charAt(0).toUpperCase() + data.card.brand.slice(1) : 'a card'} ending ${data.card.last_four}. ` : ''}
                Update your card, download invoices or cancel on Stripe's secure page.
              </p>
              <button className="secondary" disabled={busy !== null} onClick={() => go('portal')}>
                {busy === 'portal' ? 'One moment…' : 'Manage payment and invoices'}
              </button>
            </section>
          )}
        </>
      )}

      <section className="panel">
        <h2>What you use</h2>
        <dl className="facts">
          <div>
            <dt>Active dealers</dt>
            <dd>{data.usage.dealers}</dd>
          </div>
          <div>
            <dt>Staff users</dt>
            <dd>{data.usage.staff}</dd>
          </div>
          <div>
            <dt>File storage</dt>
            <dd>{data.usage.storage_gb} GB</dd>
          </div>
        </dl>
      </section>
    </>
  )
}

function StandingCallout({ billing }: { billing: Billing }) {
  const s = billing.subscription
  switch (billing.standing) {
    case 'trialing':
      return (
        <div className="callout" role="status">
          <strong>You're on a free Pro trial that ends {trialEndText(billing.trial_ends_at)}.</strong> Choose a plan before then to keep going without a break.
        </div>
      )
    case 'past_due':
      return (
        <div className="callout callout-danger" role="alert">
          <strong>Your last payment didn't go through.</strong> Everything still works while Stripe tries again. Update your card under "Manage payment and invoices" so nothing is paused.
        </div>
      )
    case 'lapsed':
      return (
        <div className="callout callout-danger" role="alert">
          <strong>Your account is paused.</strong> Your staff and dealers can't use it until you choose a plan. Nothing has been deleted.
        </div>
      )
    default:
      return s?.ends_at ? (
        <div className="callout" role="status">
          <strong>Cancelled.</strong> Your plan stays active until {formatDate(s.ends_at)}. To keep it, resume it under "Manage payment and invoices".
        </div>
      ) : null
  }
}
