import { useState, type FormEvent } from 'react'
import { useSearchParams } from 'react-router-dom'
import { api, ApiError, download, type DocumentKind, type LibraryDocument, type LibraryPage, type ProductModelRow, type SerialLookup } from '../lib/api'
import { useApi } from '../lib/useApi'
import { formatDate, useSlug } from '../lib/tenant'
import { useCurrentManufacturer } from '../components/Layout'
import { Loading } from '../components/AddForm'
import { Field, FormError } from '../components/Form'

const KIND_LABEL: Record<DocumentKind, string> = {
  manual: 'Manual',
  parts_book: 'Parts book',
  bulletin: 'Service bulletin',
  brochure: 'Brochure',
  other: 'Other',
}

function asApiError(e: unknown): ApiError {
  return e instanceof ApiError ? e : new ApiError(0, { message: 'Could not reach OEM Dealer Hub.' })
}

function formatSize(bytes: number): string {
  return bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

/** Which machines a document covers, in words. */
function Covers({ doc }: { doc: LibraryDocument }) {
  if (doc.covers.length === 0) return <span className="muted">All machines</span>
  return (
    <ul className="chips chips-small">
      {doc.covers.map((c) => (
        <li key={c.model_id}>
          {c.model}
          {c.range && <span className="muted"> · {c.range}</span>}
        </li>
      ))}
    </ul>
  )
}

/** Open (in a new tab, sandboxed by the API) and Download, for one document. */
function DocumentActions({ slug, doc, onError }: { slug: string; doc: LibraryDocument; onError: (message: string) => void }) {
  const viewable = ['application/pdf', 'image/jpeg', 'image/png'].includes(doc.mime)
  return (
    <span className="doc-actions">
      {viewable && (
        // noopener, not noreferrer: the API needs the Referer to recognise the signed-in session.
        <a href={`/api/m/${slug}/documents/${doc.id}/file?inline=1`} target="_blank" rel="noopener">
          Open
        </a>
      )}
      <button className="link" onClick={() => download(`/m/${slug}/documents/${doc.id}/file`, doc.name).catch((e) => onError(asApiError(e).message))}>
        Download
      </button>
    </span>
  )
}

// ---- The library ----

export function LibraryPage() {
  const slug = useSlug()
  const current = useCurrentManufacturer()
  const canManage = !!current?.features.includes('manage_documents')
  const isDealer = current?.acting_as === 'dealer'
  const [params, setParams] = useSearchParams()
  const [editing, setEditing] = useState<LibraryDocument | 'new' | null>(null)
  const [actionError, setActionError] = useState<string | null>(null)

  const query = new URLSearchParams()
  for (const key of ['q', 'kind', 'model', 'page']) {
    const value = params.get(key)
    if (value) query.set(key, value)
  }
  const { data, error, reload } = useApi<LibraryPage>(`/m/${slug}/documents${query.size ? `?${query}` : ''}`)
  const models = useApi<ProductModelRow[]>(`/m/${slug}/product-models`).data ?? []

  function setFilter(key: string, value: string) {
    const next = new URLSearchParams(params)
    if (value) next.set(key, value)
    else next.delete(key)
    if (key !== 'page') next.delete('page')
    setParams(next, { replace: true })
  }

  return (
    <>
      <div className="page-head">
        <h1>Manuals and documents</h1>
        {canManage && !editing && (
          <button className="primary" onClick={() => setEditing('new')}>
            Add document
          </button>
        )}
      </div>
      <p className="lede">
        {isDealer ? `Manuals, parts books and service bulletins from ${current?.name}.` : 'Your manuals, parts books and bulletins. Dealers see everything not marked staff only.'}
      </p>

      {editing && (
        <DocumentForm
          slug={slug}
          doc={editing === 'new' ? null : editing}
          models={models}
          onCancel={() => setEditing(null)}
          onSaved={() => {
            setEditing(null)
            reload()
          }}
        />
      )}

      <SerialLookupPanel slug={slug} onError={setActionError} />

      {actionError && (
        <p className="form-error" role="alert">
          {actionError}
        </p>
      )}

      <div className="filters">
        <form
          className="filter-search"
          role="search"
          onSubmit={(e) => {
            e.preventDefault()
            setFilter('q', String(new FormData(e.currentTarget).get('q') ?? '').trim())
          }}
        >
          <label htmlFor="doc-search" className="sr-only">
            Search titles and descriptions
          </label>
          <input id="doc-search" name="q" type="search" placeholder="Search titles and descriptions" defaultValue={params.get('q') ?? ''} key={params.get('q') ?? ''} />
          <button className="secondary">Search</button>
        </form>
        <label className="filter-select">
          <span className="sr-only">Kind</span>
          <select value={params.get('kind') ?? ''} onChange={(e) => setFilter('kind', e.target.value)}>
            <option value="">All kinds</option>
            {Object.entries(KIND_LABEL).map(([k, label]) => (
              <option key={k} value={k}>
                {label}
              </option>
            ))}
          </select>
        </label>
        <label className="filter-select">
          <span className="sr-only">Model</span>
          <select value={params.get('model') ?? ''} onChange={(e) => setFilter('model', e.target.value)}>
            <option value="">All models</option>
            {models.map((m) => (
              <option key={m.id} value={m.id}>
                {m.name}
              </option>
            ))}
          </select>
        </label>
      </div>

      {!data ? (
        <Loading error={error} />
      ) : data.total === 0 ? (
        <p className="muted">{query.size ? 'No documents match.' : canManage ? 'No documents yet. Add your first manual.' : 'No documents yet.'}</p>
      ) : (
        <>
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Document</th>
                  <th>Kind</th>
                  <th>Covers</th>
                  <th>Updated</th>
                  <th>
                    <span className="sr-only">Actions</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                {data.data.map((d) => (
                  <tr key={d.id}>
                    <td className="wrap">
                      <strong>{d.title}</strong>
                      {d.audience === 'staff' && <span className="badge badge-staff">Staff only</span>}
                      {d.description && <div className="muted small">{d.description}</div>}
                    </td>
                    <td>{KIND_LABEL[d.kind]}</td>
                    <td className="wrap">
                      <Covers doc={d} />
                    </td>
                    <td>{formatDate(d.updated_at)}</td>
                    <td>
                      <DocumentActions slug={slug} doc={d} onError={setActionError} />
                      {canManage && (
                        <button className="link" onClick={() => setEditing(d)}>
                          Edit
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="pager">
            <span className="muted">
              {data.total} document{data.total === 1 ? '' : 's'}
              {data.last_page > 1 && ` · page ${data.page} of ${data.last_page}`}
            </span>
            {data.last_page > 1 && (
              <span>
                <button className="link" disabled={data.page <= 1} onClick={() => setFilter('page', String(data.page - 1))}>
                  Previous
                </button>
                <button className="link" disabled={data.page >= data.last_page} onClick={() => setFilter('page', String(data.page + 1))}>
                  Next
                </button>
              </span>
            )}
          </div>
        </>
      )}
    </>
  )
}

/** "What goes with this serial number?" - the service shop's question. */
function SerialLookupPanel({ slug, onError }: { slug: string; onError: (message: string) => void }) {
  const [result, setResult] = useState<SerialLookup | null>(null)
  const [message, setMessage] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  async function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const serial = String(new FormData(e.currentTarget).get('serial') ?? '').trim()
    if (!serial) return
    setBusy(true)
    setMessage(null)
    setResult(null)
    try {
      setResult(await api.get<SerialLookup>(`/m/${slug}/documents/for-serial?serial=${encodeURIComponent(serial)}`))
    } catch (err) {
      setMessage(asApiError(err).message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <section className="panel">
      <h2>Find by serial number</h2>
      <form className="filter-search" onSubmit={onSubmit} role="search">
        <label htmlFor="serial-lookup" className="sr-only">
          Serial number
        </label>
        <input id="serial-lookup" name="serial" placeholder="Serial number from the machine's plate, e.g. AS4-24001" autoComplete="off" />
        <button className="primary" disabled={busy}>
          {busy ? 'Looking…' : 'Find documents'}
        </button>
      </form>
      {message && <p className="muted lookup-result">{message}</p>}
      {result && (
        <div className="lookup-result">
          <p>
            <strong>{result.machine.serial_no}</strong> is an <strong>{result.machine.model}</strong> <span className="muted">({result.machine.product_line})</span>.{' '}
            {result.documents.length === 0 && 'No documents cover it yet.'}
          </p>
          {result.documents.length > 0 && <DocumentList slug={slug} docs={result.documents} onError={onError} />}
        </div>
      )}
    </section>
  )
}

function DocumentList({ slug, docs, onError }: { slug: string; docs: LibraryDocument[]; onError: (message: string) => void }) {
  return (
    <ul className="docs">
      {docs.map((d) => (
        <li key={d.id}>
          <span>
            <strong>{d.title}</strong>
            <span className="muted">
              {' '}
              · {KIND_LABEL[d.kind]} · {formatSize(d.size)}
            </span>
          </span>
          <DocumentActions slug={slug} doc={d} onError={onError} />
        </li>
      ))}
    </ul>
  )
}

// ---- Add or change a document ----

interface CoverDraft {
  on: boolean
  from: string
  to: string
}

function DocumentForm({ slug, doc, models, onSaved, onCancel }: { slug: string; doc: LibraryDocument | null; models: ProductModelRow[]; onSaved: () => void; onCancel: () => void }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const [covers, setCovers] = useState<Record<number, CoverDraft>>(() =>
    Object.fromEntries((doc?.covers ?? []).map((c) => [c.model_id, { on: true, from: c.serial_from ?? '', to: c.serial_to ?? '' }])),
  )
  const chosen = models.filter((m) => covers[m.id]?.on)

  function setCover(id: number, patch: Partial<CoverDraft>) {
    const blank: CoverDraft = { on: false, from: '', to: '' }
    setCovers((all) => ({ ...all, [id]: { ...blank, ...all[id], ...patch } }))
  }

  async function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const form = new FormData(e.currentTarget)
    const body = new FormData()
    for (const key of ['title', 'kind', 'audience', 'description']) body.set(key, String(form.get(key) ?? '').trim())
    const file = form.get('file')
    if (file instanceof File && file.size > 0) body.set('file', file)
    chosen.forEach((m, i) => {
      body.set(`models[${i}][id]`, String(m.id))
      body.set(`models[${i}][serial_from]`, covers[m.id].from.trim())
      body.set(`models[${i}][serial_to]`, covers[m.id].to.trim())
    })

    setBusy(true)
    setError(null)
    try {
      await api.post(doc ? `/m/${slug}/documents/${doc.id}` : `/m/${slug}/documents`, body)
      onSaved()
    } catch (err) {
      setError(asApiError(err))
    } finally {
      setBusy(false)
    }
  }

  async function onDelete() {
    if (!doc || !window.confirm(`Delete "${doc.title}"? Dealers will no longer see it, and the file is removed.`)) return
    setBusy(true)
    try {
      await api.delete(`/m/${slug}/documents/${doc.id}`)
      onSaved()
    } catch (err) {
      setError(asApiError(err))
      setBusy(false)
    }
  }

  // A serial-range error for model n of the chosen list.
  const coverError = (i: number) => error?.field(`models.${i}.serial_to`) ?? error?.field(`models.${i}.serial_from`) ?? error?.field(`models.${i}.id`)

  return (
    <section className="panel">
      <h2>{doc ? `Change "${doc.title}"` : 'Add a document'}</h2>
      <form onSubmit={onSubmit} noValidate className="grid-form">
        <Field label="Title" name="title" required defaultValue={doc?.title} error={error} />
        <div className="field">
          <label htmlFor="doc-kind">Kind</label>
          <select id="doc-kind" name="kind" defaultValue={doc?.kind ?? 'manual'}>
            {Object.entries(KIND_LABEL).map(([k, label]) => (
              <option key={k} value={k}>
                {label}
              </option>
            ))}
          </select>
        </div>
        <div className="field">
          <label htmlFor="doc-audience">Who can see it</label>
          <select id="doc-audience" name="audience" defaultValue={doc?.audience ?? 'everyone'}>
            <option value="everyone">Dealers and staff</option>
            <option value="staff">Staff only</option>
          </select>
        </div>
        <Field
          label={doc ? 'Replace the file (optional)' : 'File'}
          name="file"
          type="file"
          accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
          required={!doc}
          error={error}
          hint={doc ? `Now: ${doc.name}. Leave empty to keep it.` : 'PDF, JPG or PNG, up to 40 MB. Checked for viruses before it\'s saved.'}
        />
        <div className="field span-all">
          <label htmlFor="doc-description">Description</label>
          <textarea id="doc-description" name="description" rows={2} maxLength={2000} defaultValue={doc?.description ?? ''} placeholder="Optional - what's in it, what changed." />
        </div>

        <fieldset className="covers span-all">
          <legend>Which machines does it cover?</legend>
          <p className="hint">Pick none for something that covers every machine, like a warranty policy. Leave a serial number blank for no limit at that end.</p>
          {models.length === 0 && <p className="muted">Add models under Products to link documents to them.</p>}
          {models.map((m) => {
            const c = covers[m.id]
            const i = chosen.findIndex((x) => x.id === m.id)
            return (
              <div key={m.id} className="cover-row">
                <label className="check">
                  <input type="checkbox" checked={!!c?.on} onChange={(e) => setCover(m.id, { on: e.target.checked })} /> {m.name}
                  <span className="muted"> · {m.product_line.name}</span>
                </label>
                {c?.on && (
                  <span className="cover-range">
                    <input aria-label={`${m.name}: first serial number`} placeholder="From S/N" value={c.from} onChange={(e) => setCover(m.id, { from: e.target.value })} />
                    <span aria-hidden>–</span>
                    <input aria-label={`${m.name}: last serial number`} placeholder="To S/N" value={c.to} onChange={(e) => setCover(m.id, { to: e.target.value })} />
                  </span>
                )}
                {i >= 0 && coverError(i) && <p className="field-error">{coverError(i)}</p>}
              </div>
            )
          })}
        </fieldset>

        <div className="form-actions">
          <FormError error={error} />
          <button className="primary" disabled={busy}>
            {busy ? 'Uploading and checking…' : doc ? 'Save changes' : 'Add document'}
          </button>
          <button type="button" className="link" onClick={onCancel}>
            Cancel
          </button>
          {doc && (
            <button type="button" className="link danger-link" onClick={onDelete} disabled={busy}>
              Delete document
            </button>
          )}
        </div>
      </form>
    </section>
  )
}

// ---- On a machine's page ----

export function MachineDocuments({ machineId }: { machineId: number }) {
  const slug = useSlug()
  const { data } = useApi<LibraryDocument[]>(`/m/${slug}/machines/${machineId}/documents`)
  const [error, setError] = useState<string | null>(null)

  return (
    <>
      <h2 className="section-title">Manuals and documents</h2>
      {error && (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}
      {!data ? null : data.length === 0 ? (
        <p className="muted">No documents cover this machine yet.</p>
      ) : (
        <section className="panel">
          <DocumentList slug={slug} docs={data} onError={setError} />
        </section>
      )}
    </>
  )
}
