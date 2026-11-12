import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { ApiError, download, type DealerRow, type FleetSummary, type ProductModelRow } from '../lib/api'
import { useApi } from '../lib/useApi'
import { useSlug } from '../lib/tenant'
import { useCurrentManufacturer } from '../components/Layout'
import { Loading } from '../components/AddForm'
import { AgingBars, AgingLegend, AgingTable, ChartCard, StackedBars, StackedTable, StatusDonut, StatusLegend, type AgingRow, type StackedRow } from '../components/charts'

const fmt = new Intl.NumberFormat()

/** Remounts per manufacturer, so the report never shows one brand's numbers under another's name. */
export function FleetPage() {
  const slug = useSlug()
  return <FleetReport key={slug} />
}

function FleetReport() {
  const slug = useSlug()
  const current = useCurrentManufacturer()
  const [params, setParams] = useSearchParams()
  const [exporting, setExporting] = useState(false)
  const [exportError, setExportError] = useState<string | null>(null)

  const query = new URLSearchParams()
  for (const key of ['line', 'model', 'dealer']) {
    const value = params.get(key)
    if (value) query.set(key, value)
  }
  const qs = query.size ? `?${query}` : ''

  const { data, error, loading } = useApi<FleetSummary>(`/m/${slug}/fleet${qs}`, { keepPrevious: true })
  const lines = useApi<{ id: number; name: string }[]>(`/m/${slug}/product-lines`).data ?? []
  const models = useApi<ProductModelRow[]>(`/m/${slug}/product-models`).data ?? []
  const dealers = useApi<DealerRow[]>(current?.features.includes('dealers') ? `/m/${slug}/dealers` : null).data ?? []

  function setFilter(key: string, value: string) {
    const next = new URLSearchParams(params)
    if (value) next.set(key, value)
    else next.delete(key)
    // A model from another line would match nothing.
    if (key === 'line') next.delete('model')
    setParams(next, { replace: true })
  }

  async function onExport() {
    setExporting(true)
    setExportError(null)
    try {
      await download(`/m/${slug}/fleet/export${qs}`, `${slug}-fleet.xlsx`)
    } catch (e) {
      setExportError(e instanceof ApiError ? e.message : 'Could not reach OEM Dealer Hub.')
    } finally {
      setExporting(false)
    }
  }

  const line = params.get('line')
  const shownModels = line ? models.filter((m) => String(m.product_line.id) === line) : models

  /** The machine list, filtered to one row, keeping the report's other filters. */
  const machinesLink = (extra: Record<string, string>) => {
    const p = new URLSearchParams(query)
    for (const [k, v] of Object.entries(extra)) p.set(k, v)
    return `../machines?${p}`
  }

  const dealerRows: StackedRow[] = (data?.by_dealer ?? []).map((d) => ({
    key: String(d.id ?? 'factory'),
    name: d.name,
    to: machinesLink({ dealer: d.id === null ? 'none' : String(d.id) }),
    total: d.total,
    statuses: d.statuses,
  }))
  const modelRows: StackedRow[] = (data?.by_model ?? []).map((m) => ({
    key: String(m.id),
    name: m.name,
    detail: m.line,
    to: machinesLink({ model: String(m.id) }),
    total: m.total,
    statuses: m.statuses,
  }))

  const atFactory = data?.by_dealer.find((d) => d.id === null)?.total ?? 0

  // Unsold stock by how long it's sat, per dealer; each links to that dealer's stock over 180 days.
  const agingRows: AgingRow[] = (data?.aging.by_dealer ?? []).map((d) => ({
    key: String(d.id ?? 'factory'),
    name: d.name,
    to: machinesLink({ dealer: d.id === null ? 'none' : String(d.id), aged: '180', view: 'list' }),
    counts: d.counts,
    total: d.total,
    oldest_days: d.oldest_days,
  }))
  const aged = data ? data.aging.totals[2] + data.aging.totals[3] : 0
  const dealersWithStock = data?.by_dealer.filter((d) => d.id !== null).length ?? 0

  return (
    <>
      <div className="page-head">
        <h1>Fleet report</h1>
        <button className="secondary" onClick={onExport} disabled={exporting || !data || data.total === 0}>
          {exporting ? 'Preparing…' : 'Export to Excel'}
        </button>
      </div>
      <p className="lede">Every {current?.name} machine across all your dealers. The filters apply to the charts and to the export.</p>
      {exportError && (
        <p className="form-error" role="alert">
          {exportError}
        </p>
      )}

      {/* One filter row, above everything it scopes. */}
      <div className="filters">
        <Select label="Product line" value={line ?? ''} onChange={(v) => setFilter('line', v)} options={[['', 'All product lines'], ...lines.map((l) => [String(l.id), l.name] as [string, string])]} />
        <Select label="Model" value={params.get('model') ?? ''} onChange={(v) => setFilter('model', v)} options={[['', 'All models'], ...shownModels.map((m) => [String(m.id), m.name] as [string, string])]} />
        {dealers.length > 0 && (
          <Select
            label="Dealer"
            value={params.get('dealer') ?? ''}
            onChange={(v) => setFilter('dealer', v)}
            options={[['', 'All dealers'], ['none', 'At the factory'], ...dealers.map((d) => [String(d.id), d.name] as [string, string])]}
          />
        )}
        {query.size > 0 && (
          <button className="link" onClick={() => setParams(new URLSearchParams(), { replace: true })}>
            Clear filters
          </button>
        )}
      </div>

      {!data ? (
        <Loading error={error} />
      ) : (
        // While a new filter loads, the last result stays, dimmed - no flash.
        <div className={loading ? 'is-refreshing' : undefined} aria-busy={loading}>
          <div className="kpis">
            <div className="tile">
              <span className="tile-label">Machines</span>
              <span className="tile-value">{fmt.format(data.total)}</span>
              <span className="tile-sub">{query.size ? 'matching these filters' : 'across the whole fleet'}</span>
            </div>
            <div className="tile">
              <span className="tile-label">At dealers</span>
              <span className="tile-value">{fmt.format(data.total - atFactory)}</span>
              <span className="tile-sub">{data.total ? `${Math.round(((data.total - atFactory) / data.total) * 100)}% of these machines` : 'none yet'}</span>
            </div>
            <div className="tile">
              <span className="tile-label">Dealers with stock</span>
              <span className="tile-value">{fmt.format(dealersWithStock)}</span>
              <span className="tile-sub">{dealers.length ? `of ${fmt.format(dealers.length)} dealers` : 'holding machines'}</span>
            </div>
            <div className="tile">
              <span className="tile-label">At the factory</span>
              <span className="tile-value">{fmt.format(atFactory)}</span>
              <span className="tile-sub">not with a dealer yet</span>
            </div>
          </div>

          <section className="panel" aria-labelledby="fleet-status">
            <div className="card-head">
              <div>
                <h2 id="fleet-status">By status</h2>
                <p>Each machine counted once, by where it is in its life.</p>
              </div>
            </div>
            {data.total > 0 ? <StatusDonut counts={data.by_status} linkTo={(s) => machinesLink({ status: s })} /> : <p className="muted">No machines match these filters.</p>}
          </section>

          {data.total > 0 && (
            <>
              {agingRows.length > 0 && (
                <ChartCard title="How long it's been sitting" table={<AgingTable rows={agingRows} bands={data.aging.bands} />}>
                  <p className="muted small chart-note">
                    Unsold machines (new, demo and used), by days since they arrived at the dealer.{' '}
                    {aged > 0 ? <strong className="aging-callout">{aged} over 180 days.</strong> : 'None over 180 days.'} Dealers with the oldest stock come first.
                  </p>
                  <AgingLegend bands={data.aging.bands} />
                  <AgingBars rows={agingRows} bands={data.aging.bands} />
                </ChartCard>
              )}
              <ChartCard title="By dealer" table={<StackedTable rows={dealerRows} first="Dealer" />}>
                <StatusLegend />
                <StackedBars rows={dealerRows} label="Machines by dealer" />
              </ChartCard>
              <ChartCard title="By model" table={<StackedTable rows={modelRows} first="Model" />}>
                <StatusLegend />
                <StackedBars rows={modelRows} label="Machines by model" />
              </ChartCard>
            </>
          )}
        </div>
      )}
    </>
  )
}

function Select(props: { label: string; value: string; onChange: (value: string) => void; options: [string, string][] }) {
  return (
    <label className="filter-select">
      <span className="sr-only">{props.label}</span>
      <select value={props.value} onChange={(e) => props.onChange(e.target.value)}>
        {props.options.map(([value, label]) => (
          <option key={value} value={value}>
            {label}
          </option>
        ))}
      </select>
    </label>
  )
}
