import { useLayoutEffect, useRef, useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { MACHINE_STATUSES, STATUS_LABELS, type MachineStatus } from '../lib/api'
import { ticks } from '../lib/ticks'

/*
 * Charts for machine statuses. Each status keeps one colour everywhere
 * (--status-* in index.css: categorical slots 1-5, checked for colour-blind
 * separation in light and dark). Text never wears the status colour - a
 * swatch beside it carries identity - and every chart has a table view.
 */


const fmt = new Intl.NumberFormat()

export function Swatch({ status }: { status: MachineStatus }) {
  return <span className="swatch" style={{ background: `var(--status-${status})` }} aria-hidden />
}

/** Every status, in order - always shown with two or more statuses on screen. */
export function StatusLegend() {
  return (
    <ul className="legend" aria-label="Statuses">
      {MACHINE_STATUSES.map((s) => (
        <li key={s}>
          <Swatch status={s} />
          {STATUS_LABELS[s]}
        </li>
      ))}
    </ul>
  )
}

/**
 * One whole (the machines) split by status, as a ring with the total in the
 * middle and every status's count and share listed beside it - so close
 * values are read from the numbers, not the arcs. Hover or focus a status
 * (arc or row) and the middle shows just that one.
 */
export function StatusDonut({ counts, label = 'machines', linkTo }: { counts: Partial<Record<MachineStatus, number>>; label?: string; linkTo?: (s: MachineStatus) => string }) {
  const [hot, setHot] = useState<MachineStatus | null>(null)
  const value = (s: MachineStatus) => counts[s] ?? 0
  const total = MACHINE_STATUSES.reduce((sum, s) => sum + value(s), 0)
  const size = 168
  const stroke = 24
  const r = (size - stroke) / 2
  const circ = 2 * Math.PI * r
  // A 2px surface gap between arcs, unless there's only one.
  const parts = MACHINE_STATUSES.filter((s) => value(s) > 0)
  const gap = parts.length > 1 ? 2 : 0
  let offset = 0

  const pct = (n: number) => (total ? Math.round((n / total) * 100) : 0)
  const shown = hot ? value(hot) : total

  return (
    <div className="donut" onPointerLeave={() => setHot(null)}>
      <figure className="donut-figure" role="img" aria-label={`${fmt.format(total)} ${label}: ${parts.map((s) => `${STATUS_LABELS[s]} ${fmt.format(value(s))}`).join(', ')}`}>
        <svg viewBox={`0 0 ${size} ${size}`} width={size} height={size} aria-hidden>
          <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="var(--border)" strokeWidth={stroke} opacity={total ? 0 : 1} />
          <g transform={`rotate(-90 ${size / 2} ${size / 2})`}>
            {parts.map((s) => {
              const len = (value(s) / total) * circ
              const dash = Math.max(len - gap, 1)
              const el = (
                <circle
                  key={s}
                  cx={size / 2}
                  cy={size / 2}
                  r={r}
                  fill="none"
                  stroke={`var(--status-${s})`}
                  strokeWidth={hot === s ? stroke + 4 : stroke}
                  strokeDasharray={`${dash} ${circ - dash}`}
                  strokeDashoffset={-offset}
                  opacity={hot && hot !== s ? 0.35 : 1}
                  onPointerEnter={() => setHot(s)}
                  className="donut-arc"
                />
              )
              offset += len
              return el
            })}
          </g>
        </svg>
        <figcaption className="donut-center" aria-hidden>
          <strong>{fmt.format(shown)}</strong>
          <span>{hot ? `${STATUS_LABELS[hot]} · ${pct(value(hot))}%` : label}</span>
        </figcaption>
      </figure>
      <ul className="donut-legend" aria-label="By status">
        {MACHINE_STATUSES.map((s) => {
          const body = (
            <>
              <Swatch status={s} />
              <span className="donut-name">{STATUS_LABELS[s]}</span>
              <span className="donut-pct">{pct(value(s))}%</span>
              <strong className="donut-num">{fmt.format(value(s))}</strong>
            </>
          )
          return (
            <li key={s} className={hot === s ? 'is-hot' : undefined} onPointerEnter={() => setHot(s)}>
              {linkTo ? (
                <Link to={linkTo(s)} onFocus={() => setHot(s)} onBlur={() => setHot(null)}>
                  {body}
                </Link>
              ) : (
                <span tabIndex={0} onFocus={() => setHot(s)} onBlur={() => setHot(null)}>
                  {body}
                </span>
              )}
            </li>
          )
        })}
      </ul>
    </div>
  )
}

/** Tracks an element's width, for deciding whether a label fits inside a mark. */
function useWidth<T extends HTMLElement>() {
  const ref = useRef<T>(null)
  const [width, setWidth] = useState(0)

  useLayoutEffect(() => {
    const el = ref.current
    if (!el) return
    const observer = new ResizeObserver(([entry]) => setWidth(entry.contentRect.width))
    observer.observe(el)
    return () => observer.disconnect()
  }, [])

  return [ref, width] as const
}

/** Roughly how wide a short label is at the chart's 12px type, plus padding. */
function labelFits(text: string, px: number): boolean {
  return px >= text.length * 7.5 + 14
}

/**
 * One whole (the fleet) split by status: a tall segmented bar, each block
 * with its share printed inside when it fits. The legend names the blocks.
 */
export function StatusSplit({ counts, total }: { counts: Record<MachineStatus, number>; total: number }) {
  const [ref, width] = useWidth<HTMLDivElement>()
  const parts = MACHINE_STATUSES.filter((s) => counts[s] > 0)
  const name = parts.map((s) => `${STATUS_LABELS[s]} ${fmt.format(counts[s])} (${Math.round((counts[s] / total) * 100)}%)`).join(', ')

  if (total === 0) return <p className="muted">No machines match these filters.</p>

  return (
    <div className="split" ref={ref} role="img" aria-label={`Machines by status: ${name}`}>
      {parts.map((s) => {
        const share = counts[s] / total
        const label = `${Math.round(share * 100)}%`
        return (
          <span
            key={s}
            className="split-part"
            style={{ flexGrow: counts[s], background: `var(--status-${s})`, color: `var(--status-${s}-ink)` }}
            title={`${STATUS_LABELS[s]}: ${fmt.format(counts[s])} (${label})`}
          >
            {labelFits(label, share * width) ? label : null}
          </span>
        )
      })}
    </div>
  )
}

export interface StackedRow {
  key: string
  name: string
  /** A secondary line under the name (e.g. the product line). */
  detail?: string
  /** Where the row's name links to (the machine list, filtered to it). */
  to?: string
  total: number
  statuses: Record<MachineStatus, number>
}

/**
 * Several wholes on one shared axis: a horizontal bar per row, split by
 * status, total at the tip. Hover or focus a row for every status's count.
 * Shows the first `limit` rows; the table view has them all.
 */
export function StackedBars({ rows, limit = 12, label }: { rows: StackedRow[]; limit?: number; label: string }) {
  const [tip, setTip] = useState<{ row: StackedRow; x: number; y: number; status?: MachineStatus } | null>(null)
  const frame = useRef<HTMLDivElement>(null)
  const shown = rows.slice(0, limit)
  const axis = ticks(Math.max(...shown.map((r) => r.total), 0))
  const max = axis[axis.length - 1]

  function show(row: StackedRow, e: { clientX: number; clientY: number }, status?: MachineStatus) {
    const box = frame.current?.getBoundingClientRect()
    if (!box) return
    // Kept inside the chart, so it never pushes the page sideways on a phone.
    setTip({ row, status, x: Math.max(0, Math.min(e.clientX - box.left + 12, box.width - 190)), y: e.clientY - box.top + 12 })
  }

  function showAtRow(row: StackedRow, el: HTMLElement) {
    const box = frame.current?.getBoundingClientRect()
    const r = el.getBoundingClientRect()
    if (!box) return
    setTip({ row, x: Math.max(0, Math.min(r.left - box.left + 160, box.width - 190)), y: r.top - box.top + r.height })
  }

  return (
    <div className="bars" ref={frame} onPointerLeave={() => setTip(null)}>
      <div className="bars-axis" aria-hidden>
        {axis.map((t) => (
          <span key={t} style={{ left: `${(t / max) * 100}%` }}>
            {fmt.format(t)}
          </span>
        ))}
      </div>
      <ul className="bars-rows" aria-label={label}>
        {shown.map((row) => (
          <li
            key={row.key}
            className="bars-row"
            tabIndex={0}
            aria-label={`${row.name}: ${fmt.format(row.total)} machines - ${MACHINE_STATUSES.filter((s) => row.statuses[s]).map((s) => `${STATUS_LABELS[s]} ${row.statuses[s]}`).join(', ')}`}
            onFocus={(e) => showAtRow(row, e.currentTarget)}
            onBlur={() => setTip(null)}
          >
            <span className="bars-name">
              {row.to ? <Link to={row.to}>{row.name}</Link> : row.name}
              {row.detail && <small>{row.detail}</small>}
            </span>
            <span className="bars-track">
              {/* Hairline gridlines at each tick. */}
              {axis.slice(1).map((t) => (
                <span key={t} className="bars-grid" style={{ left: `${(t / max) * 100}%` }} aria-hidden />
              ))}
              <span className="bars-bar" style={{ width: `${(row.total / max) * 100}%` }} onPointerLeave={() => setTip(null)}>
                {MACHINE_STATUSES.filter((s) => row.statuses[s] > 0).map((s) => (
                  <span
                    key={s}
                    className={`bars-seg${tip?.row.key === row.key && tip.status === s ? ' is-hot' : ''}`}
                    style={{ flexGrow: row.statuses[s], background: `var(--status-${s})` }}
                    onPointerMove={(e) => show(row, e, s)}
                  />
                ))}
              </span>
              <span className="bars-total" style={{ left: `calc(${(row.total / max) * 100}% + 0.5rem)` }}>
                {fmt.format(row.total)}
              </span>
            </span>
          </li>
        ))}
      </ul>
      {rows.length > limit && <p className="muted bars-more">Showing the {limit} largest of {rows.length}. The table view lists them all.</p>}
      {tip && <Tooltip row={tip.row} hot={tip.status} x={tip.x} y={tip.y} />}
    </div>
  )
}

function Tooltip({ row, hot, x, y }: { row: StackedRow; hot?: MachineStatus; x: number; y: number }) {
  return (
    <div className="tooltip" style={{ left: x, top: y }} role="presentation">
      <div className="tooltip-title">{row.name}</div>
      {MACHINE_STATUSES.map((s) => (
        <div key={s} className={`tooltip-row${hot === s ? ' is-hot' : ''}`}>
          <span className="tooltip-key" style={{ background: `var(--status-${s})` }} />
          <strong>{fmt.format(row.statuses[s])}</strong>
          <span>{STATUS_LABELS[s]}</span>
        </div>
      ))}
      <div className="tooltip-row tooltip-total">
        <strong>{fmt.format(row.total)}</strong>
        <span>Total</span>
      </div>
    </div>
  )
}

/** The table view of a stacked chart: every row, every status. */
export function StackedTable({ rows, first }: { rows: StackedRow[]; first: string }) {
  return (
    <div className="table-wrap">
      <table className="numeric">
        <thead>
          <tr>
            <th>{first}</th>
            {MACHINE_STATUSES.map((s) => (
              <th key={s}>
                <Swatch status={s} /> {STATUS_LABELS[s]}
              </th>
            ))}
            <th>Total</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r.key}>
              <td>
                {r.to ? <Link to={r.to}>{r.name}</Link> : r.name}
                {r.detail && <span className="muted"> · {r.detail}</span>}
              </td>
              {MACHINE_STATUSES.map((s) => (
                <td key={s}>{fmt.format(r.statuses[s])}</td>
              ))}
              <td>
                <strong>{fmt.format(r.total)}</strong>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

/*
 * Aging: unsold machines by how long they've sat. The bands are ordered
 * (older is worse), so they're one hue, light to dark (--age-1..4) -
 * deliberately not the status colours, so a band is never read as a status.
 */

export interface AgingRow {
  key: string
  name: string
  to?: string
  counts: number[]
  total: number
  oldest_days: number
}

export function AgingLegend({ bands }: { bands: string[] }) {
  return (
    <ul className="legend" aria-label="How long">
      {bands.map((b, i) => (
        <li key={b}>
          <span className="swatch" style={{ background: `var(--age-${i + 1})` }} aria-hidden />
          {b}
        </li>
      ))}
    </ul>
  )
}

/** A bar per dealer, split by band, on one shared axis; the oldest unit's age at the end. */
export function AgingBars({ rows, bands, limit = 12 }: { rows: AgingRow[]; bands: string[]; limit?: number }) {
  const shown = rows.slice(0, limit)
  const axis = ticks(Math.max(...shown.map((r) => r.total), 0))
  const max = axis[axis.length - 1] || 1

  return (
    <div className="bars aging-bars">
      <div className="bars-axis" aria-hidden>
        {axis.map((t) => (
          <span key={t} style={{ left: `${(t / max) * 100}%` }}>
            {fmt.format(t)}
          </span>
        ))}
      </div>
      <ul className="bars-rows" aria-label="Unsold machines by how long they've been sitting">
        {shown.map((row) => (
          <li key={row.key} className="bars-row" tabIndex={0} aria-label={`${row.name}: ${row.counts.map((c, i) => `${bands[i]} ${c}`).join(', ')}; oldest ${row.oldest_days} days`}>
            <span className="bars-name">
              {row.to ? <Link to={row.to}>{row.name}</Link> : row.name}
              <small>oldest {fmt.format(row.oldest_days)} days</small>
            </span>
            <span className="bars-track">
              {axis.slice(1).map((t) => (
                <span key={t} className="bars-grid" style={{ left: `${(t / max) * 100}%` }} aria-hidden />
              ))}
              <span className="bars-bar" style={{ width: `${(row.total / max) * 100}%` }}>
                {row.counts.map((c, i) =>
                  c > 0 ? <span key={i} className="bars-seg" style={{ flexGrow: c, background: `var(--age-${i + 1})` }} title={`${bands[i]}: ${fmt.format(c)}`} /> : null,
                )}
              </span>
              <span className="bars-total" style={{ left: `calc(${(row.total / max) * 100}% + 0.5rem)` }}>
                {fmt.format(row.total)}
              </span>
            </span>
          </li>
        ))}
      </ul>
      {rows.length > limit && <p className="muted bars-more">Showing the {limit} dealers with the oldest stock, of {rows.length}. The table view lists them all.</p>}
    </div>
  )
}

export function AgingTable({ rows, bands }: { rows: AgingRow[]; bands: string[] }) {
  return (
    <div className="table-wrap">
      <table className="numeric">
        <thead>
          <tr>
            <th>Dealer</th>
            {bands.map((b, i) => (
              <th key={b}>
                <span className="swatch" style={{ background: `var(--age-${i + 1})` }} aria-hidden /> {b}
              </th>
            ))}
            <th>Unsold</th>
            <th>Oldest (days)</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r.key}>
              <td>{r.to ? <Link to={r.to}>{r.name}</Link> : r.name}</td>
              {r.counts.map((c, i) => (
                <td key={i}>{fmt.format(c)}</td>
              ))}
              <td>
                <strong>{fmt.format(r.total)}</strong>
              </td>
              <td>{fmt.format(r.oldest_days)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

/** A chart card with a "Chart / Table" switch; the table is the always-readable twin. */
export function ChartCard({ title, children, table }: { title: string; children: ReactNode; table: ReactNode }) {
  const [asTable, setAsTable] = useState(false)

  return (
    <section className="panel chart-card">
      <div className="chart-head">
        <h2>{title}</h2>
        <div className="toggle" role="group" aria-label={`${title}: view as`}>
          <button className={asTable ? '' : 'is-on'} aria-pressed={!asTable} onClick={() => setAsTable(false)}>
            Chart
          </button>
          <button className={asTable ? 'is-on' : ''} aria-pressed={asTable} onClick={() => setAsTable(true)}>
            Table
          </button>
        </div>
      </div>
      {asTable ? table : children}
    </section>
  )
}
