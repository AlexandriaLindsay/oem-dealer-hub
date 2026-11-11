import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api, ApiError, type Catalog, type CatalogLine, type PriceListDetail, type PriceListSummary } from '../lib/api'
import { useApi } from '../lib/useApi'
import { formatDate, useSlug } from '../lib/tenant'
import { useCurrentManufacturer } from '../components/Layout'
import { Loading } from '../components/AddForm'
import { Field, FormError } from '../components/Form'
import { money } from '../lib/money'

function asApiError(e: unknown): ApiError {
  return e instanceof ApiError ? e : new ApiError(0, { message: 'Could not reach DealerDock.' })
}

// ---- The catalog: what everyone with prices sees ----

export function CatalogPage() {
  const slug = useSlug()
  const current = useCurrentManufacturer()
  const canManage = !!current?.features.includes('manage_pricing')
  const lists = useApi<PriceListSummary[]>(canManage ? `/m/${slug}/price-lists` : null).data ?? []
  const [listId, setListId] = useState('')
  const [search, setSearch] = useState('')
  const { data, error } = useApi<Catalog>(`/m/${slug}/catalog${listId ? `?price_list=${listId}` : ''}`)

  const lines = useMemo(() => filterLines(data?.lines ?? [], search), [data, search])
  const currency = data?.price_list?.currency ?? 'USD'

  return (
    <>
      <div className="page-head">
        <h1>Prices</h1>
        {canManage && (
          <Link to="../price-lists" relative="path" className="button-secondary">
            Edit price lists
          </Link>
        )}
      </div>
      <p className="lede">
        {current?.acting_as === 'dealer'
          ? `Your ${current.name} prices, in ${currency === 'CAD' ? 'Canadian' : 'US'} dollars.`
          : data?.price_list
            ? `Showing ${data.price_list.name} (${currency})${data.price_list.is_default ? ', the list dealers get unless they have their own' : ''}.`
            : ''}
      </p>

      <div className="filters">
        <form className="filter-search" role="search" onSubmit={(e) => e.preventDefault()}>
          <label htmlFor="catalog-search" className="sr-only">
            Search models and options
          </label>
          <input id="catalog-search" type="search" placeholder="Search models and options" value={search} onChange={(e) => setSearch(e.target.value)} />
        </form>
        {canManage && lists.length > 1 && (
          <label className="filter-select">
            <span className="sr-only">Price list</span>
            <select value={listId} onChange={(e) => setListId(e.target.value)}>
              {lists.map((l) => (
                <option key={l.id} value={l.is_default ? '' : l.id}>
                  {l.name} ({l.currency}){l.is_default ? ' - default' : ''}
                </option>
              ))}
            </select>
          </label>
        )}
      </div>

      {!data ? (
        <Loading error={error} />
      ) : !data.price_list ? (
        <p className="muted">{canManage ? 'No price list yet. Create one under Price lists.' : 'Prices aren\'t available yet.'}</p>
      ) : lines.length === 0 ? (
        <p className="muted">{search ? 'Nothing matches.' : 'No models yet.'}</p>
      ) : (
        lines.map((line) => (
          <section key={line.id} className="panel price-line">
            <h2>{line.name}</h2>
            <table className="price-table">
              <tbody>
                {line.models.map((m) => (
                  <ModelRows key={m.id} model={m} currency={currency} />
                ))}
              </tbody>
            </table>
          </section>
        ))
      )}
    </>
  )
}

function ModelRows({ model, currency }: { model: CatalogLine['models'][number]; currency: string }) {
  return (
    <>
      <tr className="price-model">
        <th scope="row">
          {model.name}
          {model.category && <span className="muted small"> · {model.category}</span>}
        </th>
        <td className={model.price === null ? 'muted' : ''}>{money(model.price, currency)}</td>
      </tr>
      {model.options.map((o) => (
        <tr key={o.id} className="price-option">
          <th scope="row">+ {o.name}</th>
          <td className={o.price === null ? 'muted' : ''}>{money(o.price, currency)}</td>
        </tr>
      ))}
    </>
  )
}

/** Models whose name, category or an option matches; options narrowed the same way. */
function filterLines(lines: CatalogLine[], search: string): CatalogLine[] {
  const q = search.trim().toLowerCase()
  if (!q) return lines
  return lines
    .map((line) => ({
      ...line,
      models: line.models
        .filter((m) => line.name.toLowerCase().includes(q) || m.name.toLowerCase().includes(q) || (m.category ?? '').toLowerCase().includes(q) || m.options.some((o) => o.name.toLowerCase().includes(q)))
        .map((m) => ({ ...m, options: m.name.toLowerCase().includes(q) || line.name.toLowerCase().includes(q) ? m.options : m.options.filter((o) => o.name.toLowerCase().includes(q)) })),
    }))
    .filter((line) => line.models.length > 0)
}

// ---- Price lists: the manufacturer's staff ----

export function PriceListsPage() {
  const slug = useSlug()
  const navigate = useNavigate()
  const { data, error, reload } = useApi<PriceListSummary[]>(`/m/${slug}/price-lists`)
  const [adding, setAdding] = useState(false)
  const [busy, setBusy] = useState(false)
  const [formError, setFormError] = useState<ApiError | null>(null)

  async function onCreate(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const f = new FormData(e.currentTarget)
    setBusy(true)
    setFormError(null)
    try {
      const created = await api.post<PriceListSummary>(`/m/${slug}/price-lists`, {
        name: String(f.get('name') ?? '').trim(),
        currency: f.get('currency'),
        copy_from: f.get('copy_from') ? Number(f.get('copy_from')) : undefined,
      })
      navigate(String(created.id))
    } catch (err) {
      setFormError(asApiError(err))
    } finally {
      setBusy(false)
    }
  }

  async function makeDefault(id: number) {
    await api.post(`/m/${slug}/price-lists/${id}/default`).catch(() => {})
    reload()
  }

  return (
    <>
      <div className="page-head">
        <h1>Price lists</h1>
        {!adding && (
          <button className="primary" onClick={() => setAdding(true)}>
            New price list
          </button>
        )}
      </div>
      <p className="lede">Each dealer buys from one list: their own, or the default. Assign lists on the Dealers page.</p>

      {adding && (
        <section className="panel">
          <h2>New price list</h2>
          <form onSubmit={onCreate} noValidate className="grid-form">
            <Field label="Name" name="name" required error={formError} hint={`Staff see it; dealers don't. E.g. "Canadian dealers".`} />
            <div className="field">
              <label htmlFor="pl-currency">Currency</label>
              <select id="pl-currency" name="currency" defaultValue="USD">
                <option value="USD">US dollars (USD)</option>
                <option value="CAD">Canadian dollars (CAD)</option>
              </select>
            </div>
            {!!data?.length && (
              <div className="field">
                <label htmlFor="pl-copy">Start from</label>
                <select id="pl-copy" name="copy_from" defaultValue="">
                  <option value="">An empty list</option>
                  {data.map((l) => (
                    <option key={l.id} value={l.id}>
                      A copy of {l.name}
                    </option>
                  ))}
                </select>
                <p className="hint">Copies the numbers as they are - no currency conversion.</p>
              </div>
            )}
            <div className="form-actions">
              <FormError error={formError} />
              <button className="primary" disabled={busy}>
                {busy ? 'Creating…' : 'Create and add prices'}
              </button>
              <button type="button" className="link" onClick={() => setAdding(false)}>
                Cancel
              </button>
            </div>
          </form>
        </section>
      )}

      {!data ? (
        <Loading error={error} />
      ) : data.length === 0 ? (
        <p className="muted">No price lists yet. Your first one becomes the default for every dealer.</p>
      ) : (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>List</th>
                <th>Currency</th>
                <th>Prices set</th>
                <th>Dealers on it</th>
                <th>Updated</th>
                <th>
                  <span className="sr-only">Actions</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {data.map((l) => (
                <tr key={l.id}>
                  <td>
                    <Link to={String(l.id)}>{l.name}</Link>
                    {l.is_default && <span className="badge badge-active">Default</span>}
                  </td>
                  <td>{l.currency}</td>
                  <td>{l.items_count}</td>
                  <td>{l.is_default ? <span className="muted">Everyone else</span> : l.dealers_count}</td>
                  <td>{formatDate(l.updated_at)}</td>
                  <td>
                    {!l.is_default && (
                      <button className="link" onClick={() => makeDefault(l.id)}>
                        Make default
                      </button>
                    )}
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

/** One list: every model and option, with a price box each. Saves only what changed. */
export function PriceListPage() {
  const slug = useSlug()
  const navigate = useNavigate()
  const { id } = useParams()
  const { data, error, reload } = useApi<PriceListDetail>(`/m/${slug}/price-lists/${id}`)
  const [draft, setDraft] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState(false)
  const [saveError, setSaveError] = useState<string | null>(null)
  const [rowErrors, setRowErrors] = useState<Record<string, string>>({})
  const [saved, setSaved] = useState(false)

  // The prices as loaded, keyed "model:12" / "option:7".
  const original = useMemo(() => {
    const map: Record<string, string> = {}
    for (const line of data?.lines ?? []) {
      for (const m of line.models) {
        map[`model:${m.id}`] = m.price ?? ''
        for (const o of m.options) map[`option:${o.id}`] = o.price ?? ''
      }
    }
    return map
  }, [data])
  useEffect(() => setDraft(original), [original])

  const changed = Object.keys(draft).filter((k) => draft[k].trim() !== (original[k] ?? ''))

  async function onSave() {
    setBusy(true)
    setSaveError(null)
    setRowErrors({})
    setSaved(false)
    try {
      await api.post(`/m/${slug}/price-lists/${id}/prices`, {
        prices: changed.map((k) => {
          const [type, itemId] = k.split(':')
          return { type, id: Number(itemId), price: draft[k].trim() || null }
        }),
      })
      reload()
      setSaved(true)
    } catch (err) {
      const e = asApiError(err)
      // prices.N.* -> the Nth changed box.
      const byKey: Record<string, string> = {}
      for (const [field, messages] of Object.entries(e.errors)) {
        const n = Number(field.split('.')[1])
        if (changed[n]) byKey[changed[n]] = messages[0]
      }
      setRowErrors(byKey)
      setSaveError(Object.keys(byKey).length ? 'Some prices need fixing (marked below). Nothing was saved.' : e.message)
    } finally {
      setBusy(false)
    }
  }

  async function onDelete() {
    if (!data || !window.confirm(`Delete "${data.name}"? Dealers on it go back to the default list.`)) return
    try {
      await api.delete(`/m/${slug}/price-lists/${id}`)
      navigate('..', { relative: 'path' })
    } catch (err) {
      setSaveError(asApiError(err).field('price_list') ?? asApiError(err).message)
    }
  }

  if (!data) return <Loading error={error} />

  const box = (key: string, label: string) => (
    <td className="price-input">
      <span className="money-input">
        <span aria-hidden>{data.currency === 'CAD' ? 'CA$' : '$'}</span>
        <input
          aria-label={`${label} price`}
          inputMode="decimal"
          placeholder="On request"
          value={draft[key] ?? ''}
          aria-invalid={!!rowErrors[key]}
          onChange={(e) => {
            setDraft({ ...draft, [key]: e.target.value })
            setSaved(false)
          }}
        />
      </span>
      {rowErrors[key] && <span className="field-error">{rowErrors[key]}</span>}
    </td>
  )

  return (
    <>
      <Link to=".." relative="path" className="back">
        ← Price lists
      </Link>
      <div className="page-head">
        <h1>
          {data.name} {data.is_default && <span className="badge badge-active">Default</span>}
        </h1>
        <button className="link danger-link" onClick={onDelete}>
          Delete list
        </button>
      </div>
      <p className="lede">
        {data.currency === 'CAD' ? 'Canadian dollars' : 'US dollars'} · {data.is_default ? 'dealers without a list of their own buy from this one' : `${data.dealers_count} dealer${data.dealers_count === 1 ? '' : 's'} on it`}. Leave a price empty for "price on request".
      </p>

      {data.lines.length === 0 ? (
        <p className="muted">
          No models yet. Add them under <Link to="../../products" relative="path">Products</Link>.
        </p>
      ) : (
        data.lines.map((line) => (
          <section key={line.id} className="panel price-line">
            <h2>{line.name}</h2>
            <table className="price-table">
              <tbody>
                {line.models.map((m) => (
                  <PriceEditorRows key={m.id} model={m} box={box} />
                ))}
              </tbody>
            </table>
          </section>
        ))
      )}

      <div className="save-bar" role="region" aria-label="Save prices">
        {saveError ? (
          <span className="form-error">{saveError}</span>
        ) : saved && changed.length === 0 ? (
          <span className="notice-inline">Saved.</span>
        ) : (
          <span className="muted">{changed.length ? `${changed.length} unsaved change${changed.length === 1 ? '' : 's'}` : 'No changes'}</span>
        )}
        <button className="primary" disabled={busy || changed.length === 0} onClick={onSave}>
          {busy ? 'Saving…' : 'Save prices'}
        </button>
      </div>
    </>
  )
}

function PriceEditorRows({ model, box }: { model: CatalogLine['models'][number]; box: (key: string, label: string) => ReactNode }) {
  return (
    <>
      <tr className="price-model">
        <th scope="row">
          {model.name}
          {model.category && <span className="muted small"> · {model.category}</span>}
        </th>
        {box(`model:${model.id}`, model.name)}
      </tr>
      {model.options.map((o) => (
        <tr key={o.id} className="price-option">
          <th scope="row">+ {o.name}</th>
          {box(`option:${o.id}`, `${model.name} ${o.name}`)}
        </tr>
      ))}
    </>
  )
}
