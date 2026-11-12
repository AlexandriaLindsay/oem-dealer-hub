import { useParams, useSearchParams } from 'react-router-dom'
import { Globe, MapPin, Phone } from 'lucide-react'
import { useApi } from '../lib/useApi'

/*
 * "In stock near you": the page a manufacturer embeds on its own website
 * (<iframe src=".../stock/acme">). Which dealers have which models right
 * now - counts only, no sign-in. Filters stay in the address, so a link like
 * /stock/acme?model=3 can go straight to one model.
 */

interface PublicStock {
  manufacturer: { name: string }
  confirmed_only: boolean
  updated_at: string
  models: { id: number; name: string; line: string | null }[]
  regions: string[]
  dealers: {
    name: string
    city: string | null
    region: string | null
    country: string | null
    phone: string | null
    website: string | null
    total: number
    models: { id: number; name: string | null; new: number; demo: number; used: number }[]
  }[]
}

export function PublicStockPage() {
  const { slug } = useParams()
  const [params, setParams] = useSearchParams()
  const model = params.get('model') ?? ''
  const region = params.get('region') ?? ''
  const query = new URLSearchParams()
  if (model) query.set('model', model)
  if (region) query.set('region', region)
  const { data, error, loading } = useApi<PublicStock>(`/public/${slug}/stock${query.size ? `?${query}` : ''}`, { keepPrevious: true })

  function set(key: string, value: string) {
    const next = new URLSearchParams(params)
    if (value) next.set(key, value)
    else next.delete(key)
    setParams(next, { replace: true })
  }

  if (!data) {
    return (
      <main className="stock-page">
        <p className="muted">{error ? "There's no stock list here." : 'Loading…'}</p>
      </main>
    )
  }

  return (
    <main className="stock-page">
      <h1>Find {data.manufacturer.name} equipment near you</h1>
      <p className="muted">Machines at dealers right now. Call the dealer to check it's still there, or to arrange a look.</p>

      <div className="filters stock-filters">
        <label>
          <span className="sr-only">Model</span>
          <select value={model} onChange={(e) => set('model', e.target.value)}>
            <option value="">Every model</option>
            {data.models.map((m) => (
              <option key={m.id} value={m.id}>
                {m.name}
              </option>
            ))}
          </select>
        </label>
        {data.regions.length > 1 && (
          <label>
            <span className="sr-only">Region</span>
            <select value={region} onChange={(e) => set('region', e.target.value)}>
              <option value="">Everywhere</option>
              {data.regions.map((r) => (
                <option key={r} value={r}>
                  {r}
                </option>
              ))}
            </select>
          </label>
        )}
      </div>

      <div className={loading ? 'is-refreshing' : undefined} aria-busy={loading}>
        {data.dealers.length === 0 ? (
          <p className="notice">None in stock{model || region ? ' for that choice' : ''} right now. A dealer can still order one for you.</p>
        ) : (
          <ul className="stock-dealers">
            {data.dealers.map((d) => (
              <li key={d.name + d.city} className="stock-dealer">
                <div className="stock-dealer-head">
                  <h2>{d.name}</h2>
                  {(d.city || d.region) && (
                    <span className="muted small">
                      <MapPin size={14} aria-hidden /> {[d.city, d.region].filter(Boolean).join(', ')}
                    </span>
                  )}
                </div>
                <ul className="stock-models">
                  {d.models.map((m) => (
                    <li key={m.id}>
                      <strong>{m.name}</strong>
                      <span>{[m.new && `${m.new} new`, m.demo && `${m.demo} demo`, m.used && `${m.used} used`].filter(Boolean).join(' · ')}</span>
                    </li>
                  ))}
                </ul>
                <div className="stock-contact">
                  {d.phone && (
                    <a className="button-primary" href={`tel:${d.phone.replace(/[^\d+]/g, '')}`}>
                      <Phone size={15} aria-hidden /> {d.phone}
                    </a>
                  )}
                  {d.website && /^https?:\/\//i.test(d.website) && (
                    <a className="button-secondary" href={d.website} target="_blank" rel="noopener noreferrer">
                      <Globe size={15} aria-hidden /> Website
                    </a>
                  )}
                </div>
              </li>
            ))}
          </ul>
        )}
      </div>
      <p className="muted small stock-foot">
        {data.confirmed_only ? 'Only machines each dealer has confirmed on their lot recently. ' : ''}Updated {new Date(data.updated_at).toLocaleString()}
      </p>
    </main>
  )
}
