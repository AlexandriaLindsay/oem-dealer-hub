import { useEffect } from 'react'
import { useLocation } from 'react-router-dom'
import { api } from './api'

/**
 * The areas the usage report counts, by the first part of the page's
 * address, with plain names for the report. The server keeps the same list
 * (UsageDay::AREAS) and decides whether a visit counts: only on plans with
 * the report, and only for areas the person can use.
 */
export const USAGE_AREAS: Record<string, string> = {
  dashboard: 'Dashboard',
  machines: 'Machines',
  registrations: 'Registrations',
  fleet: 'Fleet report',
  documents: 'Manuals',
  prices: 'Prices',
  'price-lists': 'Price lists',
  quotes: 'Quotes',
  'parts-orders': 'Parts orders',
  announcements: 'Announcements',
  broadcasts: 'Broadcasts',
  products: 'Products',
  dealers: 'Dealers',
}

let lastSent: string | null = null

/**
 * Tells the server each time someone opens a page (machines, a quote, ...).
 * Only the area is sent - not the address, filters or what's on screen.
 * Changing a filter isn't a new visit; the same page counted twice in a row
 * (React runs effects twice in development) is skipped.
 */
export function useRecordVisit(slug: string | undefined, paused: boolean) {
  const { pathname } = useLocation()

  useEffect(() => {
    if (!slug || paused) return
    // /m/acme/machines/12 -> "machines"; /m/acme -> the dashboard.
    const area = pathname.split('/')[3] || 'dashboard'
    if (!(area in USAGE_AREAS) || lastSent === `${slug}${pathname}`) return
    lastSent = `${slug}${pathname}`
    api.post(`/m/${slug}/usage/visits`, { area }).catch(() => {
      // A missed count isn't worth bothering anyone about.
    })
  }, [slug, paused, pathname])
}
