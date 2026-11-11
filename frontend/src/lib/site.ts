import { useEffect, useState } from 'react'
import { api } from './api'

/** Facts for the public pages, from GET /api/site (prices from App\Support\Plan, legal details from config). */
export interface Site {
  trial_days: number
  plans: {
    key: 'starter' | 'pro'
    label: string
    monthly_usd: number
    annual_usd: number
    limits: { dealers: number | null; staff: number | null; storage_gb: number | null }
    /** What the plan adds over Starter; coming = not built yet. */
    extras: { label: string; coming: boolean }[]
  }[]
  legal: {
    version: string
    reviewed: boolean
    company_name: string | null
    company_address: string | null
    contact_email: string | null
    governing_law: string | null
  }
  turnstile_site_key: string | null
}

let cached: Promise<Site> | null = null

/** The site facts, fetched once per page load and shared. */
export function useSite(): Site | null {
  const [site, setSite] = useState<Site | null>(null)
  useEffect(() => {
    cached ??= api.get<Site>('/site')
    cached.then(setSite).catch(() => {
      cached = null
    })
  }, [])
  return site
}
