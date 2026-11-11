import type { InputHTMLAttributes, ReactNode } from 'react'
import { ApiError } from '../lib/api'

interface FieldProps extends InputHTMLAttributes<HTMLInputElement> {
  label: string
  name: string
  error?: ApiError | null
  hint?: ReactNode
  /** The API's key for this field's errors when it differs from the input name (e.g. customer[email] -> customer.email). */
  errorKey?: string
}

/** A labelled input that shows its own validation error from the API. */
export function Field({ label, name, error, hint, errorKey, ...input }: FieldProps) {
  const message = error?.field(errorKey ?? name)
  const id = `field-${name.replace(/[^a-zA-Z0-9_-]+/g, '-')}`

  return (
    <div className="field">
      <label htmlFor={id}>{label}</label>
      <input id={id} name={name} aria-invalid={!!message} aria-describedby={message ? `${id}-error` : undefined} {...input} />
      {hint && !message && <p className="hint">{hint}</p>}
      {message && (
        <p className="field-error" id={`${id}-error`}>
          {message}
        </p>
      )}
    </div>
  )
}

/** An error that isn't about one field - shown above the submit button. */
export function FormError({ error }: { error: ApiError | null }) {
  if (!error || Object.keys(error.errors).length > 0) return null
  return (
    <p className="form-error" role="alert">
      {error.message}
    </p>
  )
}

export function formValues(form: HTMLFormElement): Record<string, string> {
  return Object.fromEntries(Array.from(new FormData(form).entries()).map(([k, v]) => [k, String(v).trim()]))
}
