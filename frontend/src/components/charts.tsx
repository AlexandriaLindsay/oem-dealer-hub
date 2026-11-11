import { useLayoutEffect, useRef, useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { MACHINE_STATUSES, STATUS_LABELS, type MachineStatus } from '../lib/api'

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

/** Clean axis ticks from 0 to at least `max`: steps of 1, 2 or 5 times a power of ten. */
function ticks(max: number): number[] {
  if (max <= 0) return [0, 1]
  const rough = max / 4
  const power = 10 ** Math.floor(Math.log10(rough))
  const step = [1, 2, 5, 10].map((m) => m * power).find((s) => s >= rough) ?? power * 10
  const out: number[] = []
  for (let v = 0; v < max + step; v += step) out.push(v)
  return out
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
