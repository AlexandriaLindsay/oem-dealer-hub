import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { api, ApiError, type TerritoriesData } from '../lib/api'
import { useApi } from '../lib/useApi'
import { useSlug } from '../lib/tenant'
import { AddForm, Loading } from '../components/AddForm'

const ROLE_NAMES: Record<string, string> = { sales_rep: 'Sales rep', inside_sales: 'Inside sales' }

type Territory = TerritoriesData['territories'][number]

/**
 * Territories (Pro, owner and admins): group dealers, and choose which sales
 * staff cover each group. Changes save as they're made.
 */
export function TerritoriesPage() {
  const slug = useSlug()
  const { data, error, reload } = useApi<TerritoriesData>(`/m/${slug}/territories`)
  const [adding, setAdding] = useState(false)
  const [saveError, setSaveError] = useState<string | null>(null)

  /** Runs a save, then reloads; a failure shows at the top rather than vanishing. */
  async function save(run: () => Promise<unknown>) {
    setSaveError(null)
    try {
      await run()
    } catch (e) {
      setSaveError(e instanceof ApiError ? e.message : 'Could not reach DealerDock.')
    }
    reload()
  }

  const unassigned = data?.dealers.filter((d) => d.territory_id === null).length ?? 0

  return (
    <>
      <div className="page-head">
        <h1>Territories</h1>
        {data && !adding && (
          <button className="primary" onClick={() => setAdding(true)}>
            Add territory
          </button>
        )}
      </div>
      <p className="muted territory-intro">
        Group your dealers into territories and choose which sales reps cover each. A rep in a territory sees only its dealers - their machines, registrations, quotes and parts orders - plus your own stock. Reps in no territory, and everyone else on your staff, see every dealer.
      </p>

      {adding && (
        <section className="panel">
          <h2>Add a territory</h2>
          <AddForm
            path={`/m/${slug}/territories`}
            fields={[{ name: 'name', label: 'Name', required: true, hint: 'For example "Prairies" or "Upper Midwest".' }]}
            button="Add territory"
            onAdded={() => {
              setAdding(false)
              reload()
            }}
            onCancel={() => setAdding(false)}
          />
        </section>
      )}

      {saveError && (
        <p className="form-error" role="alert">
          {saveError}
        </p>
      )}

      {!data ? (
        <Loading error={error} />
      ) : (
        <>
          {data.territories.length === 0 ? (
            !adding && <p className="muted">No territories yet. Until there are, every rep sees every dealer.</p>
          ) : (
            <div className="territories">
              {data.territories.map((t) => (
                <TerritoryCard key={t.id} territory={t} reps={data.reps} onSave={save} slug={slug} />
              ))}
            </div>
          )}

          <section className="panel">
            <h2>Dealers</h2>
            {data.territories.length > 0 && unassigned > 0 && (
              <p className="muted">
                {unassigned} {unassigned === 1 ? "dealer isn't" : "dealers aren't"} in a territory. Only staff who see every dealer see {unassigned === 1 ? 'it' : 'them'}.
              </p>
            )}
            {data.dealers.length === 0 ? (
              <p className="muted">
                No dealers yet. <Link to="../dealers">Add dealers</Link> first.
              </p>
            ) : (
              <div className="table-wrap flat">
                <table className="territory-dealers">
                  <thead>
                    <tr>
                      <th>Dealer</th>
                      <th>Location</th>
                      <th>Territory</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.dealers.map((d) => (
                      <tr key={d.id}>
                        <td>
                          {d.name}
                          {d.status !== 'active' && <span className="badge badge-suspended territory-status">{d.status}</span>}
                        </td>
                        <td>{d.place ?? '—'}</td>
                        <td>
                          <label className="sr-only" htmlFor={`terr-${d.id}`}>
                            Territory for {d.name}
                          </label>
                          <select
                            id={`terr-${d.id}`}
                            className="compact-select"
                            value={d.territory_id ?? ''}
                            disabled={data.territories.length === 0}
                            onChange={(e) => save(() => api.post(`/m/${slug}/dealers/${d.id}/territory`, { territory_id: e.target.value ? Number(e.target.value) : null }))}
                          >
                            <option value="">No territory</option>
                            {data.territories.map((t) => (
                              <option key={t.id} value={t.id}>
                                {t.name}
                              </option>
                            ))}
                          </select>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>
        </>
      )}
    </>
  )
}

function TerritoryCard({ territory, reps, onSave, slug }: { territory: Territory; reps: TerritoriesData['reps']; onSave: (run: () => Promise<unknown>) => Promise<void>; slug: string }) {
  const [renaming, setRenaming] = useState(false)
  const [renameError, setRenameError] = useState<string | null>(null)

  function toggleRep(id: number, on: boolean) {
    const ids = on ? [...territory.rep_ids, id] : territory.rep_ids.filter((r) => r !== id)
    onSave(() => api.put(`/m/${slug}/territories/${territory.id}/reps`, { membership_ids: ids }))
  }

  async function rename(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const name = String(new FormData(e.currentTarget).get('name') ?? '')
    try {
      await api.patch(`/m/${slug}/territories/${territory.id}`, { name })
      setRenaming(false)
      setRenameError(null)
      onSave(async () => {})
    } catch (err) {
      setRenameError(err instanceof ApiError ? (err.field('name') ?? err.message) : 'Could not reach DealerDock.')
    }
  }

  function remove() {
    const reps = territory.rep_ids.length
    if (!window.confirm(`Delete ${territory.name}? Its ${territory.dealer_count} dealers end up in no territory${reps ? `, and its ${reps === 1 ? 'rep sees' : 'reps see'} every dealer unless they cover another territory` : ''}.`)) return
    onSave(() => api.delete(`/m/${slug}/territories/${territory.id}`))
  }

  return (
    <section className="panel territory">
      {renaming ? (
        <form className="inline-form" onSubmit={rename}>
          <label className="sr-only" htmlFor={`tname-${territory.id}`}>
            Territory name
          </label>
          <input id={`tname-${territory.id}`} name="name" defaultValue={territory.name} maxLength={120} required autoFocus aria-invalid={!!renameError} />
          <button className="primary">Save</button>
          <button type="button" className="link" onClick={() => setRenaming(false)}>
            Cancel
          </button>
          {renameError && <p className="field-error">{renameError}</p>}
        </form>
      ) : (
        <div className="territory-head">
          <h2>{territory.name}</h2>
          <span className="muted">
            {territory.dealer_count} {territory.dealer_count === 1 ? 'dealer' : 'dealers'}
          </span>
          <span className="territory-actions">
            <button className="link" onClick={() => setRenaming(true)}>
              Rename
            </button>
            <button className="link link-danger" onClick={remove}>
              Delete
            </button>
          </span>
        </div>
      )}

      <fieldset className="territory-reps">
        <legend>Covered by</legend>
        {reps.length === 0 ? (
          <p className="muted">
            No sales reps or inside sales staff yet. <Link to="../staff">Add them under Staff</Link>.
          </p>
        ) : (
          reps.map((r) => (
            <label key={r.id} className="check">
              <input type="checkbox" checked={territory.rep_ids.includes(r.id)} onChange={(e) => toggleRep(r.id, e.target.checked)} />
              {r.name} <span className="muted">· {ROLE_NAMES[r.role] ?? r.role}</span>
            </label>
          ))
        )}
        {reps.length > 0 && territory.rep_ids.length === 0 && <p className="hint">Nobody yet. Its dealers are seen by staff who see every dealer.</p>}
      </fieldset>
    </section>
  )
}
