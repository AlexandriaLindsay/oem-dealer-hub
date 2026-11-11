import { useParams } from 'react-router-dom'

/** The current manufacturer's slug, from /m/:slug/... */
export function useSlug(): string {
  return useParams().slug!
}

/** "2026-11-11" or an ISO time -> "Nov 11, 2026". */
export function formatDate(value: string | null): string {
  if (!value) return '—'
  // A bare date is a calendar day - don't let the time zone shift it a day back.
  const date = value.length === 10 ? new Date(`${value}T12:00:00`) : new Date(value)
  return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
}

/** An ISO time -> "Nov 11, 2026, 2:18 PM". */
export function formatDateTime(value: string | null): string {
  if (!value) return '—'
  return new Date(value).toLocaleString(undefined, { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' })
}
