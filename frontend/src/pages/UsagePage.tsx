import { useRef, useState } from 'react'
import type { UsageReport } from '../lib/api'
import { useApi } from '../lib/useApi'
import { formatDate, useSlug } from '../lib/tenant'
import { Loading } from '../components/AddForm'
import { ChartCard } from '../components/charts'
import { ticks } from '../lib/ticks'
import { USAGE_AREAS } from '../lib/usage'

const fmt = new Intl.NumberFormat()

const areaName = (a: string) => USAGE_AREAS[a] ?? a

/** "Nov 11" - the year is in the page heading's range already. */
const shortDay = (d: string) => new Date(`${d}T12:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })

/** Remounts per manufacturer, so one brand's numbers never show under another's name. */
export function UsagePage() {
  const slug = useSlug()
  return <Usage key={slug} />
}

function Usage() {
  const slug = useSlug()
  const [days, setDays] = useState<30 | 90>(30)
  const { data, error } = useApi<UsageReport>(`/m/${slug}/usage?days=${days}`, { keepPrevious: true })

  return (
    <>
      <div className="page-head usage-head">
        <div>
          <h1>Usage</h1>
          <p className="muted">
            Which dealers use DealerDock, and for what. Pages opened, counted per person per day - never what was on them.
          </p>
        </div>
        <div className="toggle" role="group" aria-label="Period: the last">
          {([30, 90] as const).map((d) => (
            <button key={d} className={days === d ? 'is-on' : ''} aria-pressed={days === d} onClick={() => setDays(d)}>
              {d} days
            </button>
          ))}
        </div>
      </div>

      {!data ? (
        <Loading error={error} />
      ) : (
        <>
          <div className="tiles usage-tiles">
            <div className="tile">
              <span className="tile-label">Dealers who used it</span>
              <span className="tile-value">
                {fmt.format(data.summary.dealers_active)} <small>of {fmt.format(data.summary.dealers_total)}</small>
              </span>
            </div>
            <div className="tile">
              <span className="tile-label">People (dealers and staff)</span>
              <span className="tile-value">{fmt.format(data.summary.people_active)}</span>
            </div>
            <div className="tile">
              <span className="tile-label">Page visits</span>
              <span className="tile-value">{fmt.format(data.summary.visits)}</span>
            </div>
          </div>
          <p className="muted usage-range">
            {formatDate(data.from)} to today
          </p>

          <ChartCard title="Dealers using DealerDock each day" table={<DailyTable daily={data.daily} />}>
            <DailyColumns daily={data.daily} />
          </ChartCard>

          <DealerTable report={data} />
          <AreaTable areas={data.areas} />
        </>
      )}
    </>
  )
}

/**
 * One column per day, height = how many dealers used it that day. A single
 * series, so the title names it and there's no legend. Hover a day for its count.
 */
function DailyColumns({ daily }: { daily: UsageReport['daily'] }) {
  const frame = useRef<HTMLDivElement>(null)
  const [tip, setTip] = useState<{ i: number; x: number } | null>(null)
  const axis = ticks(Math.max(...daily.map((d) => d.dealers), 0))
  const max = axis[axis.length - 1]
  const most = Math.max(...daily.map((d) => d.dealers), 0)
  const daysUsed = daily.filter((d) => d.dealers > 0).length
  // Dates under the axis: the first, the middle and the last day.
  const labelled = new Set([0, Math.floor((daily.length - 1) / 2), daily.length - 1])

  function show(i: number, e: { clientX: number }) {
    const box = frame.current?.getBoundingClientRect()
    if (!box) return
    // Kept inside the chart, so it never pushes the page sideways on a phone.
    setTip({ i, x: Math.max(0, Math.min(e.clientX - box.left + 12, box.width - 150)) })
  }

  // No "busiest day": with a handful of dealers, many days tie.
  const summary =
    daysUsed > 0
      ? `Dealers used it on ${daysUsed} of ${daily.length} days, at most ${most} dealer${most === 1 ? '' : 's'} in a day.`
      : 'No dealer used DealerDock in this period.'

  return (
    <div className="cols" ref={frame} onPointerLeave={() => setTip(null)}>
      <div className="cols-plot" role="img" aria-label={`Dealers using DealerDock each day. ${summary} The table view lists every day.`}>
        <div className="cols-axis" aria-hidden>
          {axis.map((t) => (
            <span key={t} style={{ bottom: `${(t / max) * 100}%` }}>
              {fmt.format(t)}
            </span>
          ))}
        </div>
        <div className="cols-area">
          {axis.slice(1).map((t) => (
            <span key={t} className="cols-grid" style={{ bottom: `${(t / max) * 100}%` }} aria-hidden />
          ))}
          {daily.map((d, i) => (
            // The whole day's slot is the hover target, not just the (maybe tiny) column.
            <span key={d.day} className={`cols-slot${tip?.i === i ? ' is-hot' : ''}`} onPointerMove={(e) => show(i, e)}>
              {d.dealers > 0 && <span className="cols-bar" style={{ height: `${(d.dealers / max) * 100}%` }} />}
            </span>
          ))}
        </div>
      </div>
      <div className="cols-dates" aria-hidden>
        {daily.map((d, i) => (
          <span key={d.day}>{labelled.has(i) ? shortDay(d.day) : ''}</span>
        ))}
      </div>
      <p className="muted cols-note">{summary}</p>
      {tip && (
        <div className="tooltip" style={{ left: tip.x, top: 8 }} role="presentation">
          <div className="tooltip-title">{formatDate(daily[tip.i].day)}</div>
          <div className="tooltip-row">
            <span className="tooltip-key usage-key" />
            <strong>{fmt.format(daily[tip.i].dealers)}</strong>
            <span>{daily[tip.i].dealers === 1 ? 'dealer' : 'dealers'}</span>
          </div>
        </div>
      )}
    </div>
  )
}

/** The chart's table view: newest day first. */
function DailyTable({ daily }: { daily: UsageReport['daily'] }) {
  return (
    <div className="table-wrap usage-daily">
      <table className="numeric">
        <thead>
          <tr>
            <th>Day</th>
            <th>Dealers</th>
          </tr>
        </thead>
        <tbody>
          {[...daily].reverse().map((d) => (
            <tr key={d.day}>
              <td>{formatDate(d.day)}</td>
              <td>{fmt.format(d.dealers)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

/** Every active dealer, busiest first. The ones at the bottom are the ones worth a call. */
function DealerTable({ report }: { report: UsageReport }) {
  const unused = report.dealers.filter((d) => d.visits === 0).length

  return (
    <section className="panel">
      <h2>By dealer</h2>
      {report.dealers.length === 0 ? (
        <p className="muted">No active dealers yet.</p>
      ) : (
        <>
          {unused > 0 && (
            <p className="muted">
              {unused} of {report.dealers.length} dealers haven't used DealerDock in the last {report.days} days. They're at the bottom of
              the list - it may be worth checking they can sign in.
            </p>
          )}
          <div className="table-wrap flat">
            <table className="numeric usage-dealers">
              <thead>
                <tr>
                  <th>Dealer</th>
                  <th>People</th>
                  <th>Days used</th>
                  <th>Visits</th>
                  <th>Last used</th>
                  <th>Used most</th>
                </tr>
              </thead>
              <tbody>
                {report.dealers.map((d) =>
                  d.visits === 0 ? (
                    <tr key={d.dealer_link_id} className="is-unused">
                      <td>{d.name}</td>
                      <td colSpan={5} className="muted">
                        Not used in the last {report.days} days
                      </td>
                    </tr>
                  ) : (
                    <tr key={d.dealer_link_id}>
                      <td>{d.name}</td>
                      <td>{fmt.format(d.people)}</td>
                      <td>{fmt.format(d.days_active)}</td>
                      <td>{fmt.format(d.visits)}</td>
                      <td>{formatDate(d.last_active)}</td>
                      <td>{d.top_areas.map(areaName).join(', ')}</td>
                    </tr>
                  ),
                )}
              </tbody>
            </table>
          </div>
        </>
      )}
    </section>
  )
}

/** What people use it for - dealers and your own staff counted apart, since they do different jobs in it. */
function AreaTable({ areas }: { areas: UsageReport['areas'] }) {
  return (
    <section className="panel">
      <h2>By area</h2>
      {areas.length === 0 ? (
        <p className="muted">Nothing used in this period.</p>
      ) : (
        <div className="table-wrap flat">
          <table className="numeric">
            <thead>
              <tr>
                <th>Area</th>
                <th>By dealers</th>
                <th>By your staff</th>
              </tr>
            </thead>
            <tbody>
              {areas.map((a) => (
                <tr key={a.area}>
                  <td>{areaName(a.area)}</td>
                  <td>{fmt.format(a.dealer_visits)}</td>
                  <td>{fmt.format(a.staff_visits)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )
}
