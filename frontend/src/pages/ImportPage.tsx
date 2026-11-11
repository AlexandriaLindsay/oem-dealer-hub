import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { api, ApiError, download, type ImportPreview, type ImportRecord, type ImportRow, type ImportSummary } from '../lib/api'
import { useApi } from '../lib/useApi'
import { formatDateTime, useSlug } from '../lib/tenant'
import { useCurrentManufacturer } from '../components/Layout'

type Mode = 'add' | 'add_update'

function asApiError(e: unknown): ApiError {
  return e instanceof ApiError ? e : new ApiError(0, { message: 'Could not reach DealerDock.' })
}

const fmt = new Intl.NumberFormat()

/** The columns, as the template has them - what to put in each. */
const COLUMNS: [string, string, string][] = [
  ['Serial number', 'Required', 'One row per machine. Also read: Serial, S/N.'],
  ['Model', 'Required', 'Exactly as under Products. Add any missing models there first.'],
  ['Production number', '', 'E.g. the work order it was built on.'],
  ['Dealer', '', 'Your dealer code (best) or the dealer\'s name. Blank: at the factory. Write "factory" to move one back.'],
  ['Status', '', 'inventory, demo, rental, sold or used. Blank: inventory for a new machine.'],
  ['Built on', '', 'A date: 2026-03-15 or 3/15/2026.'],
  ['Shipped on', '', 'A date. Left blank, it stays unknown.'],
]

export function ImportPage() {
  const slug = useSlug()
  const current = useCurrentManufacturer()
  const history = useApi<ImportRecord[]>(`/m/${slug}/machine-imports`)
  const [file, setFile] = useState<File | null>(null)
  const [mode, setMode] = useState<Mode>('add')
  const [preview, setPreview] = useState<ImportPreview | null>(null)
  const [done, setDone] = useState<ImportRecord | null>(null)
  const [busy, setBusy] = useState<'check' | 'import' | null>(null)
  const [error, setError] = useState<ApiError | null>(null)
  // Bumped after an import, so the file picker empties too (a file input can't be cleared by state).
  const [pickerKey, setPickerKey] = useState(0)

  function body(): FormData {
    const form = new FormData()
    form.set('file', file!)
    form.set('mode', mode)
    return form
  }

  async function onCheck(e: FormEvent) {
    e.preventDefault()
    if (!file) return
    setBusy('check')
    setError(null)
    setDone(null)
    try {
      setPreview(await api.post<ImportPreview>(`/m/${slug}/machines/import/preview`, body()))
    } catch (err) {
      setPreview(null)
      setError(asApiError(err))
    } finally {
      setBusy(null)
    }
  }

  async function onImport() {
    setBusy('import')
    setError(null)
    try {
      const result = await api.post<ImportRecord>(`/m/${slug}/machines/import`, body())
      setDone(result)
      setPreview(null)
      setFile(null)
      setPickerKey((k) => k + 1)
      history.reload()
    } catch (err) {
      setError(asApiError(err))
    } finally {
      setBusy(null)
    }
  }

  /** A new file or mode means the last check no longer applies. */
  function reset() {
    setPreview(null)
    setDone(null)
    setError(null)
  }

  const toWrite = preview ? preview.summary.create + preview.summary.update : 0

  return (
    <>
      <Link to=".." relative="path" className="back">
        ← Machines
      </Link>
      <h1>Import machines</h1>
      <p className="lede">
        Bring in the {current?.name} machines you already have, or update them from a spreadsheet. You'll see what every row would do before anything changes.
      </p>

      {done && <DoneCallout slug={slug} record={done} />}

      <section className="panel">
        <div className="chart-head">
          <h2>1. Your file</h2>
          <button className="link" onClick={() => download(`/m/${slug}/machines/import/template`, 'machine-import-template.csv').catch((e) => setError(asApiError(e)))}>
            Download the template
          </button>
        </div>
        <details className="columns-help">
          <summary>What goes in each column</summary>
          <div className="table-wrap">
            <table>
              <tbody>
                {COLUMNS.map(([name, required, help]) => (
                  <tr key={name}>
                    <th scope="row">{name}</th>
                    <td>{required}</td>
                    <td className="wrap">{help}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </details>

        <form onSubmit={onCheck} noValidate>
          <div className="field">
            <label htmlFor="import-file">CSV or Excel file</label>
            <input
              key={pickerKey}
              id="import-file"
              type="file"
              accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
              onChange={(e) => {
                setFile(e.target.files?.[0] ?? null)
                reset()
              }}
              aria-invalid={!!error?.field('file')}
            />
            {error?.field('file') ? <p className="field-error">{error.field('file')}</p> : <p className="hint">Up to 5,000 machines and 5 MB. Only the first sheet of an Excel file is read.</p>}
          </div>
          <fieldset className="choice">
            <legend>Machines already in DealerDock</legend>
            <label className="check">
              <input type="radio" name="mode" checked={mode === 'add'} onChange={() => {
                  setMode('add')
                  reset()
                }} /> Leave them as they are (add new machines only)
            </label>
            <label className="check">
              <input type="radio" name="mode" checked={mode === 'add_update'} onChange={() => {
                  setMode('add_update')
                  reset()
                }} /> Update them from the file
            </label>
          </fieldset>
          {mode === 'add_update' && <p className="hint">Blank cells never clear anything, and a machine's model is never changed.</p>}
          {error && !error.field('file') && (
            <p className="form-error" role="alert">
              {error.message}
            </p>
          )}
          <button className="primary" disabled={!file || busy !== null}>
            {busy === 'check' ? 'Checking…' : 'Check the file'}
          </button>
        </form>
      </section>

      {preview && (
        <section className="panel">
          <h2>2. What would happen</h2>
          <SummaryTiles summary={preview.summary} />
          {preview.columns.ignored.length > 0 && (
            <p className="hint">
              Ignored columns (not used by DealerDock): {preview.columns.ignored.join(', ')}.
            </p>
          )}

          <RowTable title="Problems - these rows won't be imported" rows={preview.rows.filter((r) => r.action === 'error')} />
          <RowTable title="Changes to existing machines" rows={preview.rows.filter((r) => r.action === 'update')} />
          <RowTable title="Skipped - already in DealerDock" rows={preview.rows.filter((r) => r.action === 'skip')} collapsed />
          <RowTable title="New machines" rows={preview.rows.filter((r) => r.action === 'create')} collapsed />

          <div className="form-actions import-go">
            <button className="primary" disabled={toWrite === 0 || busy !== null} onClick={onImport}>
              {busy === 'import' ? 'Importing…' : toWrite === 0 ? 'Nothing to import' : `Import ${fmt.format(toWrite)} machine${toWrite === 1 ? '' : 's'}`}
            </button>
            {preview.summary.error > 0 && toWrite > 0 && <span className="muted">Rows with problems are left out. Fix them and import the file again: rows already in will be skipped or show as unchanged.</span>}
          </div>
        </section>
      )}

      <h2 className="section-title">Past imports</h2>
      {!history.data ? null : history.data.length === 0 ? (
        <p className="muted">No imports yet.</p>
      ) : (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>When</th>
                <th>File</th>
                <th>By</th>
                <th>Result</th>
                <th>
                  <span className="sr-only">Report</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {history.data.map((h) => (
                <tr key={h.id}>
                  <td>{formatDateTime(h.at)}</td>
                  <td>{h.file_name}</td>
                  <td>{h.by ?? '—'}</td>
                  <td>{resultText(h.summary)}</td>
                  <td>
                    <button className="link" onClick={() => download(`/m/${slug}/machine-imports/${h.id}/report`, `import-${h.id}-report.xlsx`).catch((e) => setError(asApiError(e)))}>
                      Report
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  )
}

function resultText(s: ImportSummary): string {
  const parts = [
    s.create && `${fmt.format(s.create)} added`,
    s.update && `${fmt.format(s.update)} updated`,
    s.unchanged && `${fmt.format(s.unchanged)} unchanged`,
    s.skip && `${fmt.format(s.skip)} skipped`,
    s.error && `${fmt.format(s.error)} not imported`,
  ].filter(Boolean)
  return parts.join(', ') || 'Nothing in the file'
}

function SummaryTiles({ summary }: { summary: ImportSummary }) {
  const tiles: [string, number][] = [
    ['New', summary.create],
    ['Updated', summary.update],
    ['Unchanged', summary.unchanged],
    ['Skipped', summary.skip],
    ['Problems', summary.error],
  ]
  return (
    <div className="tiles import-tiles">
      {tiles.map(([label, n]) => (
        <div key={label} className={`tile${label === 'Problems' && n > 0 ? ' tile-problem' : ''}`}>
          <span className="tile-label">{label}</span>
          <span className="tile-value">{fmt.format(n)}</span>
        </div>
      ))}
    </div>
  )
}

/** Rows of one kind. Long lists start folded; problems and changes start open. */
function RowTable({ title, rows, collapsed = false }: { title: string; rows: ImportRow[]; collapsed?: boolean }) {
  if (rows.length === 0) return null
  const shown = rows.slice(0, 500)

  return (
    <details className="import-rows" open={!collapsed}>
      <summary>
        {title} <span className="muted">({fmt.format(rows.length)})</span>
      </summary>
      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Row</th>
              <th>Serial</th>
              <th>Details</th>
            </tr>
          </thead>
          <tbody>
            {shown.map((r) => (
              <tr key={r.line}>
                <td>{r.line}</td>
                <td>{r.serial || <span className="muted">(none)</span>}</td>
                <td className="wrap">
                  {r.changes.map((c) => (
                    <div key={c.field}>
                      {c.field}: <span className="muted">{c.from ?? '(blank)'}</span> → <strong>{c.to ?? '(blank)'}</strong>
                    </div>
                  ))}
                  {r.messages.map((m) => (
                    <div key={m}>{m}</div>
                  ))}
                  {r.action === 'create' && r.messages.length === 0 && <span className="muted">New machine</span>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {rows.length > shown.length && <p className="hint">Showing the first 500. The report after importing lists every row.</p>}
    </details>
  )
}

function DoneCallout({ slug, record }: { slug: string; record: ImportRecord }) {
  const [error, setError] = useState<string | null>(null)
  return (
    <div className="callout callout-ok" role="status">
      <strong>Import #{record.id} done.</strong> {resultText(record.summary)}.{' '}
      <button className="link" onClick={() => download(`/m/${slug}/machine-imports/${record.id}/report`, `import-${record.id}-report.xlsx`).catch((e) => setError(asApiError(e).message))}>
        Download the report
      </button>
      {error && <p className="form-error">{error}</p>}
    </div>
  )
}
