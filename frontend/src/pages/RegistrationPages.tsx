import { useState, type FormEvent } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { api, ApiError, download, type MachineDetail, type RegistrationDetail, type RegistrationPage, type RegistrationStatus } from '../lib/api'
import { useApi } from '../lib/useApi'
import { formatDate, formatDateTime, useSlug } from '../lib/tenant'
import { useCurrentManufacturer } from '../components/Layout'
import { Loading } from '../components/AddForm'
import { Field, FormError } from '../components/Form'

const STATUS_LABEL: Record<RegistrationStatus, string> = { pending: 'Waiting for review', approved: 'Approved', rejected: 'Rejected' }
const KIND_LABEL = { bill_of_sale: 'Bill of sale', pdi: 'PDI checklist', other: 'Other document' } as const
const TYPE_LABEL = { sale: 'Sale', rental: 'Rental' } as const

/** The words carry the meaning; the colour only backs them up. */
export function RegistrationBadge({ status }: { status: RegistrationStatus }) {
  const icon = status === 'approved' ? '✓ ' : status === 'rejected' ? '✕ ' : ''
  return (
    <span className={`badge badge-reg-${status}`}>
      {icon && <span aria-hidden>{icon}</span>}
      {STATUS_LABEL[status]}
    </span>
  )
}

function asApiError(e: unknown): ApiError {
  return e instanceof ApiError ? e : new ApiError(0, { message: 'Could not reach OEM Dealer Hub.' })
}

function formatSize(bytes: number): string {
  return bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

// ---- The list / review queue ----

const TABS: { key: string; label: string }[] = [
  { key: 'pending', label: 'Waiting for review' },
  { key: 'approved', label: 'Approved' },
  { key: 'rejected', label: 'Rejected' },
  { key: '', label: 'All' },
]

export function RegistrationsPage() {
  const slug = useSlug()
  const current = useCurrentManufacturer()
  const isDealer = current?.acting_as === 'dealer'
  const [params, setParams] = useSearchParams()
  const status = params.get('status') ?? 'pending'
  const page = params.get('page') ?? '1'

  const query = new URLSearchParams({ page })
  if (status) query.set('status', status)
  const { data, error } = useApi<RegistrationPage>(`/m/${slug}/registrations?${query}`)

  return (
    <>
      <h1>Registrations</h1>
      <p className="lede">
        {isDealer
          ? `Sales and rentals you've registered with ${current?.name}. To register one, open the machine from Your machines.`
          : 'Sales and rentals your dealers have registered. Approving one marks the machine sold or rented and adds the customer to your records.'}
      </p>

      <div className="tabs" role="tablist" aria-label="Registration status">
        {TABS.map((t) => (
          <button key={t.key || 'all'} role="tab" aria-selected={status === t.key} className={status === t.key ? 'is-on' : ''} onClick={() => setParams(t.key ? { status: t.key } : { status: '' }, { replace: true })}>
            {t.label}
          </button>
        ))}
      </div>

      {!data ? (
        <Loading error={error} />
      ) : data.total === 0 ? (
        <p className="muted">{status === 'pending' ? 'Nothing waiting for review.' : 'No registrations here yet.'}</p>
      ) : (
        <>
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Machine</th>
                  <th>Type</th>
                  <th>Customer</th>
                  {!isDealer && <th>Dealer</th>}
                  <th>Delivered</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {data.data.map((r) => (
                  <tr key={r.id}>
                    <td>
                      <Link to={`${r.id}`}>{r.machine.serial_no}</Link> <span className="muted">· {r.machine.model}</span>
                    </td>
                    <td>{TYPE_LABEL[r.type]}</td>
                    <td>{r.customer}</td>
                    {!isDealer && <td>{r.dealer}</td>}
                    <td>{formatDate(r.delivered_on)}</td>
                    <td>
                      <RegistrationBadge status={r.status} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {data.last_page > 1 && (
            <div className="pager">
              <span className="muted">
                Page {data.page} of {data.last_page}
              </span>
              <span>
                <button className="link" disabled={data.page <= 1} onClick={() => setParams({ status, page: String(data.page - 1) })}>
                  Previous
                </button>
                <button className="link" disabled={data.page >= data.last_page} onClick={() => setParams({ status, page: String(data.page + 1) })}>
                  Next
                </button>
              </span>
            </div>
          )}
        </>
      )}
    </>
  )
}

// ---- One registration ----

export function RegistrationPage() {
  const slug = useSlug()
  const { id } = useParams()
  const current = useCurrentManufacturer()
  const { data, error, reload } = useApi<RegistrationDetail>(`/m/${slug}/registrations/${id}`)
  const [downloadError, setDownloadError] = useState<string | null>(null)

  if (!data) {
    return (
      <>
        <Link to=".." relative="path" className="back">
          ← Registrations
        </Link>
        {error?.status === 404 ? <p className="muted">That registration isn't here.</p> : <Loading error={error} />}
      </>
    )
  }

  const c = data.customer_details
  const address = [c.address, c.city, c.region, c.postal_code, c.country].filter(Boolean).join(', ')

  return (
    <>
      <Link to=".." relative="path" className="back">
        ← Registrations
      </Link>
      <div className="page-head">
        <h1>
          {TYPE_LABEL[data.type]} of {data.machine.serial_no}
        </h1>
        <RegistrationBadge status={data.status} />
      </div>
      <p className="lede">
        {data.machine.model} · {data.product_line} ·{' '}
        {current?.features.includes('machines') && (
          <Link to={`../../machines/${data.machine.id}`} relative="path">
            View machine
          </Link>
        )}
      </p>

      {data.status === 'rejected' && (
        <div className="callout callout-danger" role="status">
          <strong>Not approved</strong>
          <p>{data.rejection_reason}</p>
          <p className="muted">
            {data.reviewed_by} · {formatDateTime(data.reviewed_at)}. Fix what's asked and register the machine again from its page.
          </p>
        </div>
      )}
      {data.status === 'approved' && (
        <div className="callout callout-ok" role="status">
          <strong>Approved</strong> by {data.reviewed_by} · {formatDateTime(data.reviewed_at)}
        </div>
      )}

      <dl className="facts">
        <div>
          <dt>Customer</dt>
          <dd>
            {c.company && <>{c.company}<br /></>}
            {c.contact_name}
          </dd>
        </div>
        <div>
          <dt>Contact</dt>
          <dd>
            {c.email && <>{c.email}<br /></>}
            {c.phone}
            {!c.email && !c.phone && '—'}
          </dd>
        </div>
        <div>
          <dt>Address</dt>
          <dd>{address || '—'}</dd>
        </div>
        <div>
          <dt>Delivered</dt>
          <dd>{formatDate(data.delivered_on)}</dd>
        </div>
        <div>
          <dt>Dealer</dt>
          <dd>{data.dealer}</dd>
        </div>
        <div>
          <dt>Submitted</dt>
          <dd>
            {formatDateTime(data.submitted_at)}
            {data.submitted_by && <><br /><span className="muted">{data.submitted_by}</span></>}
          </dd>
        </div>
      </dl>

      {data.notes && (
        <section className="panel">
          <h2>Dealer's notes</h2>
          <p className="prewrap">{data.notes}</p>
        </section>
      )}

      <section className="panel">
        <h2>Documents</h2>
        {downloadError && (
          <p className="form-error" role="alert">
            {downloadError}
          </p>
        )}
        {data.documents.length === 0 ? (
          <p className="muted">No documents attached.</p>
        ) : (
          <ul className="docs">
            {data.documents.map((d) => (
              <li key={d.id}>
                <span>
                  <strong>{KIND_LABEL[d.kind]}</strong>
                  <span className="muted">
                    {' '}
                    · {d.name} · {formatSize(d.size)}
                    {d.scan_status === 'clean' ? ' · virus scan passed' : ' · not scanned (local development)'}
                  </span>
                </span>
                <button
                  className="link"
                  onClick={() => download(`/m/${slug}/registrations/${data.id}/documents/${d.id}`, d.name).catch((e) => setDownloadError(asApiError(e).message))}
                >
                  Download
                </button>
              </li>
            ))}
          </ul>
        )}
      </section>

      {data.status === 'pending' && current?.features.includes('review_registrations') && <ReviewPanel slug={slug} id={data.id} type={data.type} onDone={reload} />}
    </>
  )
}

function ReviewPanel({ slug, id, type, onDone }: { slug: string; id: number; type: 'sale' | 'rental'; onDone: () => void }) {
  const [rejecting, setRejecting] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)

  async function act(path: string, body?: unknown) {
    setBusy(true)
    setError(null)
    try {
      await api.post(path, body)
      onDone()
    } catch (e) {
      setError(asApiError(e))
    } finally {
      setBusy(false)
    }
  }

  function onReject(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    act(`/m/${slug}/registrations/${id}/reject`, { reason: String(new FormData(e.currentTarget).get('reason') ?? '').trim() })
  }

  return (
    <section className="panel">
      <h2>Review</h2>
      <p className="muted">
        Approving marks the machine {type === 'sale' ? 'sold' : 'out on rental'}, adds the customer to your records, and emails the dealer.
      </p>
      {error?.field('registration') && (
        <p className="form-error" role="alert">
          {error.field('registration')}
        </p>
      )}
      <FormError error={error} />
      {rejecting ? (
        <form onSubmit={onReject} noValidate>
          <div className="field">
            <label htmlFor="reject-reason">What needs fixing?</label>
            <textarea id="reject-reason" name="reason" rows={3} maxLength={1000} aria-invalid={!!error?.field('reason')} placeholder="e.g. The bill of sale isn't signed." />
            {error?.field('reason') ? <p className="field-error">{error.field('reason')}</p> : <p className="hint">The dealer sees this and gets it by email.</p>}
          </div>
          <div className="form-actions">
            <button className="danger" disabled={busy}>
              {busy ? 'Saving…' : 'Reject registration'}
            </button>
            <button type="button" className="link" onClick={() => setRejecting(false)}>
              Cancel
            </button>
          </div>
        </form>
      ) : (
        <div className="form-actions">
          <button className="primary" disabled={busy} onClick={() => act(`/m/${slug}/registrations/${id}/approve`)}>
            {busy ? 'Saving…' : 'Approve'}
          </button>
          <button className="secondary" disabled={busy} onClick={() => setRejecting(true)}>
            Reject…
          </button>
        </div>
      )}
    </section>
  )
}

// ---- On a machine's page: its registrations, and the form to register it ----

export function MachineRegistrations({ machine }: { machine: MachineDetail }) {
  const slug = useSlug()
  const current = useCurrentManufacturer()
  const { data, reload } = useApi<RegistrationPage>(`/m/${slug}/registrations?machine=${machine.id}`)
  const [open, setOpen] = useState(false)

  const pending = data?.data.some((r) => r.status === 'pending')
  const canRegister = current?.features.includes('register_machines') && machine.dealer !== null && machine.status !== 'sold' && data && !pending

  return (
    <>
      <div className="page-head section-head">
        <h2>Registrations</h2>
        {canRegister && !open && (
          <button className="primary" onClick={() => setOpen(true)}>
            Register a sale or rental
          </button>
        )}
      </div>
      {open && (
        <RegisterForm
          slug={slug}
          machine={machine}
          onCancel={() => setOpen(false)}
          onDone={() => {
            setOpen(false)
            reload()
          }}
        />
      )}
      {!data || (open && data.data.length === 0) ? null : data.data.length === 0 ? (
        <p className="muted">
          {machine.dealer === null ? 'Not registered. A machine is registered by the dealer that sells or rents it.' : 'Not registered yet.'}
        </p>
      ) : (
        <ul className="list">
          {data.data.map((r) => (
            <li key={r.id} className="list-row">
              <span>
                <Link to={`../../registrations/${r.id}`} relative="path">
                  {TYPE_LABEL[r.type]} to {r.customer}
                </Link>
                <span className="muted"> · delivered {formatDate(r.delivered_on)}</span>
              </span>
              <RegistrationBadge status={r.status} />
            </li>
          ))}
        </ul>
      )}
    </>
  )
}

function RegisterForm({ slug, machine, onDone, onCancel }: { slug: string; machine: MachineDetail; onDone: () => void; onCancel: () => void }) {
  const [type, setType] = useState<'sale' | 'rental'>('sale')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  // Which picker each sent file came from, so "documents.1.file" errors can say "PDI checklist: ...".
  const [sentKinds, setSentKinds] = useState<string[]>([])
  const today = new Date().toLocaleDateString('en-CA') // YYYY-MM-DD, local

  async function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const form = new FormData(e.currentTarget)
    const body = new FormData()
    body.set('machine_id', String(machine.id))
    body.set('type', type)
    for (const [key, value] of form.entries()) {
      // Files go in as documents[n][kind] + documents[n][file]; empty pickers are skipped.
      if (value instanceof File) continue
      if (String(value).trim() !== '') body.set(key, String(value).trim())
    }
    let n = 0
    const kinds: string[] = []
    for (const kind of ['bill_of_sale', 'pdi', 'other'] as const) {
      for (const file of form.getAll(`file_${kind}`)) {
        if (file instanceof File && file.size > 0) {
          body.set(`documents[${n}][kind]`, kind)
          body.set(`documents[${n}][file]`, file)
          kinds.push(kind)
          n++
        }
      }
    }

    setSentKinds(kinds)
    setBusy(true)
    setError(null)
    try {
      await api.post(`/m/${slug}/registrations`, body)
      onDone()
    } catch (err) {
      setError(asApiError(err))
    } finally {
      setBusy(false)
    }
  }

  // Every documents error, labelled with the picker it came from.
  const documentErrors = Object.entries(error?.errors ?? {})
    .filter(([key]) => key === 'documents' || key.startsWith('documents.'))
    .map(([key, messages]) => {
      const kind = sentKinds[Number(key.split('.')[1])] as keyof typeof KIND_LABEL | undefined
      return kind ? `${KIND_LABEL[kind]}: ${messages[0]}` : messages[0]
    })

  return (
    <section className="panel">
      <h2>Register {machine.serial_no}</h2>
      <form onSubmit={onSubmit} noValidate className="grid-form">
        <fieldset className="choice span-all">
          <legend>What happened?</legend>
          {(['sale', 'rental'] as const).map((t) => (
            <label key={t} className="check">
              <input type="radio" name="_type" checked={type === t} onChange={() => setType(t)} /> {t === 'sale' ? 'Sold to a customer' : 'Rented out to a customer'}
            </label>
          ))}
        </fieldset>

        <Field label={type === 'sale' ? 'Delivered on' : 'Rental starts'} name="delivered_on" type="date" max={today} defaultValue={today} required error={error} />

        <h3 className="span-all">Customer</h3>
        <Field label="Company" name="customer[company]" errorKey="customer.company" error={error} hint="Leave blank for a private buyer." />
        <Field label="Contact name" name="customer[contact_name]" errorKey="customer.contact_name" required error={error} />
        <Field label="Email" name="customer[email]" errorKey="customer.email" type="email" error={error} />
        <Field label="Phone" name="customer[phone]" errorKey="customer.phone" type="tel" error={error} />
        <Field label="Street address" name="customer[address]" errorKey="customer.address" error={error} />
        <Field label="City" name="customer[city]" errorKey="customer.city" error={error} />
        <Field label="State / province" name="customer[region]" errorKey="customer.region" error={error} />
        <Field label="ZIP / postal code" name="customer[postal_code]" errorKey="customer.postal_code" error={error} />

        <h3 className="span-all">Documents</h3>
        <p className="hint span-all">PDF, JPG or PNG, up to 20 MB each. Every file is checked for viruses before it's saved.</p>
        <FileField kind="bill_of_sale" label={type === 'sale' ? 'Bill of sale (required)' : 'Rental agreement or bill of sale'} />
        <FileField kind="pdi" label="PDI checklist" />
        <FileField kind="other" label="Anything else" multiple />
        {documentErrors.map((message) => (
          <p key={message} className="field-error span-all" role="alert">
            {message}
          </p>
        ))}

        <div className="field span-all">
          <label htmlFor="reg-notes">Notes for the reviewer</label>
          <textarea id="reg-notes" name="notes" rows={3} maxLength={2000} placeholder="Optional - anything the reviewer should know." />
        </div>

        <div className="form-actions">
          <FormError error={error} />
          {error?.field('machine_id') && (
            <p className="form-error" role="alert">
              {error.field('machine_id')}
            </p>
          )}
          <button className="primary" disabled={busy}>
            {busy ? 'Uploading and checking…' : 'Submit for review'}
          </button>
          <button type="button" className="link" onClick={onCancel}>
            Cancel
          </button>
        </div>
      </form>
    </section>
  )
}

function FileField({ kind, label, multiple = false }: { kind: string; label: string; multiple?: boolean }) {
  return (
    <div className="field">
      <label htmlFor={`file-${kind}`}>{label}</label>
      <input id={`file-${kind}`} name={`file_${kind}`} type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" multiple={multiple} />
    </div>
  )
}
