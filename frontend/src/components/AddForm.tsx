import { useState, type FormEvent } from 'react'
import { api, ApiError } from '../lib/api'
import { Field, FormError, formValues } from './Form'

export interface Option {
  value: string
  label: string
}

export interface AddField {
  name: string
  label: string
  type?: string
  required?: boolean
  hint?: string
  /** Makes it a select. */
  options?: Option[]
}

/** Shows an API error or "Loading…" while a page's data isn't there yet. */
export function Loading({ error }: { error: ApiError | null }) {
  return error ? <p className="form-error">{error.message}</p> : <p className="muted">Loading…</p>
}

/** A small form that POSTs its fields to `path`. Empty fields aren't sent. */
export function AddForm<T = unknown>(props: {
  path: string
  fields: AddField[]
  button: string
  onAdded: (result: T) => void
  onCancel?: () => void
  inline?: boolean
}) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)

  async function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const form = e.currentTarget
    const values = Object.fromEntries(Object.entries(formValues(form)).filter(([, v]) => v !== ''))
    setBusy(true)
    setError(null)
    try {
      const result = await api.post<T>(props.path, values)
      form.reset()
      props.onAdded(result)
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError(0, { message: 'Could not reach OEM Dealer Hub.' }))
    } finally {
      setBusy(false)
    }
  }

  return (
    <form onSubmit={onSubmit} noValidate className={props.inline ? 'inline-form' : 'grid-form'}>
      {props.fields.map((f) =>
        f.options ? (
          <SelectField key={f.name} field={f} error={error} />
        ) : (
          <Field key={f.name} label={f.label} name={f.name} type={f.type ?? 'text'} required={f.required} hint={f.hint} error={error} />
        ),
      )}
      <div className="form-actions">
        <FormError error={error} />
        <button className="primary" disabled={busy}>
          {busy ? 'Saving…' : props.button}
        </button>
        {props.onCancel && (
          <button type="button" className="link" onClick={props.onCancel}>
            Cancel
          </button>
        )}
      </div>
    </form>
  )
}

function SelectField({ field, error }: { field: AddField; error: ApiError | null }) {
  const id = `field-${field.name}`
  const message = error?.field(field.name)

  return (
    <div className="field">
      <label htmlFor={id}>{field.label}</label>
      <select id={id} name={field.name} aria-invalid={!!message} aria-describedby={message ? `${id}-error` : undefined}>
        {field.options!.map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </select>
      {field.hint && !message && <p className="hint">{field.hint}</p>}
      {message && (
        <p className="field-error" id={`${id}-error`}>
          {message}
        </p>
      )}
    </div>
  )
}
