/**
 * Money arrives from the API as exact strings ("1234.50"); null = price on
 * request. Display formats them; CAD shows "CA$" so it can't be mistaken for
 * US dollars.
 */
export function money(price: string | null, currency: string): string {
  if (price === null) return 'Price on request'
  return new Intl.NumberFormat('en-US', { style: 'currency', currency }).format(Number(price))
}

/**
 * Typed text -> cents, for previewing totals as someone types (the server
 * works out the real ones). Null when it isn't a price.
 */
export function toCents(text: string): number | null {
  const clean = text.replace(/[,$\s]/g, '')
  if (!/^\d{1,8}(\.\d{1,2})?$/.test(clean)) return null
  const [whole, frac = ''] = clean.split('.')
  return Number(whole) * 100 + Number(frac.padEnd(2, '0'))
}

export function fromCents(cents: number): string {
  return `${Math.floor(cents / 100)}.${String(cents % 100).padStart(2, '0')}`
}
