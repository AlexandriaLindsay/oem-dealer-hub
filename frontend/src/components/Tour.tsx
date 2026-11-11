import { useCallback, useEffect, useLayoutEffect, useRef, useState, type CSSProperties } from 'react'
import type { TourStep } from '../lib/tours'

/** Shows one tour's steps (lib/tours.ts) as a small dialog pointing at each target. */
const WIDTH = 330

const targetOf = (step: TourStep) => (step.target ? document.querySelector<HTMLElement>(`[data-tour="${step.target}"]`) : null)
const isAvailable = (step: TourStep) => !step.target || targetOf(step) !== null

export function Tour({ steps, onClose }: { steps: TourStep[]; onClose: (finished: boolean) => void }) {
  const all = useRef(steps).current

  // Which steps are available is worked out on every render, and the tour
  // re-renders whenever the page changes. So a target that arrives late - the
  // dashboard's checklist, after its data loads on a slow connection - joins
  // the tour when it appears, and a missing one (a nav item this person
  // doesn't have) is never shown. Deciding once, after a delay, guessed
  // wrong on slow connections.
  const [, setPageVersion] = useState(0)
  useEffect(() => {
    let frame = 0
    const observer = new MutationObserver(() => {
      cancelAnimationFrame(frame)
      frame = requestAnimationFrame(() => setPageVersion((v) => v + 1))
    })
    observer.observe(document.body, { childList: true, subtree: true })
    return () => {
      observer.disconnect()
      cancelAnimationFrame(frame)
    }
  }, [])

  // Start once the page has drawn, so the first step has its bearings.
  const [started, setStarted] = useState(false)
  useEffect(() => {
    const timer = window.setTimeout(() => setStarted(true), 300)
    return () => window.clearTimeout(timer)
  }, [])

  // The current step itself (not a position), so steps joining the list don't move it.
  const [current, setCurrent] = useState<TourStep>(all[0])
  const available = all.filter(isAvailable)
  const position = available.indexOf(current)
  // If the current step's target has gone (the page changed), carry on from the next one that's there.
  const step = position >= 0 ? current : (all.slice(all.indexOf(current) + 1).find(isAvailable) ?? available[available.length - 1])
  const at = available.indexOf(step)
  const next = available[at + 1]
  const previous = available[at - 1]

  const [style, setStyle] = useState<CSSProperties>({ visibility: 'hidden' })
  const box = useRef<HTMLDivElement>(null)

  const place = useCallback(() => {
    const el = step ? targetOf(step) : null
    const vw = window.innerWidth
    const vh = window.innerHeight
    const height = box.current?.offsetHeight ?? 200
    const width = Math.min(WIDTH, vw - 32)

    if (!el) {
      setStyle({ top: Math.max(16, (vh - height) / 2), left: (vw - width) / 2, width })
      return
    }
    const r = el.getBoundingClientRect()
    // Beside the target when there's room (the desktop sidebar), otherwise below it (the phone's nav row).
    if (r.right + 16 + width <= vw - 16) {
      setStyle({ top: Math.min(Math.max(16, r.top - 8), vh - height - 16), left: r.right + 16, width })
    } else {
      const below = r.bottom + 12
      setStyle({ top: below + height > vh - 16 ? Math.max(16, r.top - height - 12) : below, left: Math.min(Math.max(16, r.left), vw - width - 16), width })
    }
  }, [step])

  // Highlight the target, bring it into view, and move focus to the dialog.
  useLayoutEffect(() => {
    if (!started || !step) return
    const el = targetOf(step)
    el?.classList.add('tour-target')
    el?.scrollIntoView({ block: 'nearest', inline: 'nearest' })
    place()
    // A frame later: the dialog starts hidden until it's placed, and a browser
    // won't focus a hidden element - focus would stay behind, and Escape and
    // screen readers wouldn't reach the tour.
    const frame = requestAnimationFrame(() => box.current?.focus({ preventScroll: true }))
    return () => {
      cancelAnimationFrame(frame)
      el?.classList.remove('tour-target')
    }
  }, [started, step, place])

  useEffect(() => {
    window.addEventListener('resize', place)
    window.addEventListener('scroll', place, true)
    return () => {
      window.removeEventListener('resize', place)
      window.removeEventListener('scroll', place, true)
    }
  }, [place])

  if (!started || !step) return null

  return (
    <div
      ref={box}
      className="tour"
      style={style}
      role="dialog"
      aria-modal="false"
      aria-labelledby="tour-title"
      aria-describedby="tour-body"
      tabIndex={-1}
      onKeyDown={(e) => {
        if (e.key === 'Escape') onClose(false)
      }}
    >
      <p className="tour-progress">
        {at + 1} of {available.length}
      </p>
      <h2 id="tour-title">{step.title}</h2>
      <p id="tour-body">{step.body}</p>
      <div className="tour-actions">
        <button className="link" onClick={() => onClose(false)}>
          Skip tour
        </button>
        <span>
          {previous && (
            <button className="secondary" onClick={() => setCurrent(previous)}>
              Back
            </button>
          )}
          <button className="primary" onClick={() => (next ? setCurrent(next) : onClose(true))}>
            {next ? 'Next' : 'Done'}
          </button>
        </span>
      </div>
    </div>
  )
}
