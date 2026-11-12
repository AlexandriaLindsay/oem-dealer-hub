import { useEffect } from 'react'

/*
 * A manufacturer's own colour (Pro) becomes the app's accent: buttons, links,
 * the current page in the menu, focus rings. Any colour can be picked, so it
 * is adjusted - darkened for light mode, lightened for dark mode, keeping its
 * hue - just enough that text stays readable (WCAG AA, 4.5:1):
 *   - links and accent text on the page and on panels,
 *   - button text on the colour,
 *   - the current menu item's text on its soft tint.
 */

type Rgb = [number, number, number]

/** The surfaces the accent sits on (index.css), light and dark. */
const LIGHT = { bg: '#f4f3ef', surface: '#ffffff' }
const DARK = { bg: '#0f1012', surface: '#18191c', ink: '#0b1020' }
const AA = 4.5

const toRgb = (hex: string): Rgb => [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16)) as Rgb
const toHex = (rgb: Rgb) => '#' + rgb.map((v) => Math.round(v).toString(16).padStart(2, '0')).join('')
const mix = (a: string, b: string, t: number) => toHex(toRgb(a).map((v, i) => v + (toRgb(b)[i] - v) * t) as Rgb)

function luminance(hex: string): number {
  const [r, g, b] = toRgb(hex).map((v) => {
    const c = v / 255
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
  })
  return 0.2126 * r + 0.7152 * g + 0.0722 * b
}

/** WCAG contrast ratio between two colours, 1 to 21. */
export function contrast(a: string, b: string): number {
  const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x)
  return (hi + 0.05) / (lo + 0.05)
}

/** The least mix of `from` toward `toward` that passes `ok` (in 2% steps). */
function nudge(from: string, toward: string, ok: (c: string) => boolean): string {
  for (let t = 0; t <= 1.0001; t += 0.02) {
    const c = mix(from, toward, t)
    if (ok(c)) return c
  }
  return toward
}

export interface BrandTokens {
  accent: string
  accentText: string
  accentSoft: string
}

export interface Brand {
  light: BrandTokens
  dark: BrandTokens
  /** Whether the colour had to change to stay readable, per mode. */
  adjusted: { light: boolean; dark: boolean }
}

export const isHexColor = (value: string) => /^#[0-9a-fA-F]{6}$/.test(value)

/** Accent tokens for light and dark mode from one brand colour ("#rrggbb"). */
export function brandTokens(hex: string): Brand {
  const color = hex.toLowerCase()

  // Light: readable on the page background (the harder of the two light surfaces); white text on it.
  const light = nudge(color, '#000000', (c) => contrast(c, LIGHT.bg) >= AA)
  const lightSoft = nudge(mix(light, '#ffffff', 0.88), '#ffffff', (s) => contrast(light, s) >= AA)

  // Dark: readable on panels (the lighter of the two dark surfaces); dark ink on it when that reads, else white.
  const dark = nudge(color, '#ffffff', (c) => contrast(c, DARK.surface) >= AA)
  const darkText = contrast(DARK.ink, dark) >= AA ? DARK.ink : '#ffffff'
  const darkSoft = nudge(mix(dark, DARK.surface, 0.78), DARK.bg, (s) => contrast(dark, s) >= AA)

  return {
    light: { accent: light, accentText: '#ffffff', accentSoft: lightSoft },
    dark: { accent: dark, accentText: darkText, accentSoft: darkSoft },
    adjusted: { light: light !== color, dark: dark !== color },
  }
}

/**
 * Puts the brand's colour on the whole page while someone works in that
 * manufacturer, and takes it off again when they leave (or switch to a brand
 * without one). On the root, so menus and overlays outside the app frame get it too.
 */
export function useBrandColor(hex: string | null | undefined) {
  useEffect(() => {
    const root = document.documentElement
    if (!hex || !isHexColor(hex)) return
    const { light, dark } = brandTokens(hex)
    const vars: Record<string, string> = {
      '--brand-accent': light.accent,
      '--brand-accent-text': light.accentText,
      '--brand-accent-soft': light.accentSoft,
      '--brand-dark-accent': dark.accent,
      '--brand-dark-accent-text': dark.accentText,
      '--brand-dark-accent-soft': dark.accentSoft,
    }
    for (const [k, v] of Object.entries(vars)) root.style.setProperty(k, v)
    root.dataset.brand = ''
    return () => {
      for (const k of Object.keys(vars)) root.style.removeProperty(k)
      delete root.dataset.brand
    }
  }, [hex])
}
