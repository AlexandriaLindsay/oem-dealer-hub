import { useState, type ReactNode } from 'react'
import type { ApiError } from '../lib/api'

/*
 * A Kanban board: one column per status, a card per row. Moving a card is a
 * button on the card (keyboard, touch, screen readers); dragging it onto a
 * column is the same move, for a mouse. A column that's not a legal move for
 * the card being dragged doesn't light up and won't take the drop.
 */

export type BoardTone = 'neutral' | 'accent' | 'ok' | 'danger'

export interface BoardColumn<T> {
  key: string
  title: string
  tone: BoardTone
  items: T[] | null
  /** Every row with this status, not just the ones loaded. */
  total: number
  error?: ApiError | null
  /** A line under the column's cards, e.g. "showing the latest 50". */
  footer?: ReactNode
  /** A figure beside the count, e.g. the column's value. */
  aside?: ReactNode
  /** The dot's colour, when the status has its own (machine statuses); otherwise the tone's. */
  color?: string
}

export function Board<T>({
  columns,
  cardKey,
  renderCard,
  movesFor,
  onMove,
  empty = 'Nothing here.',
  label,
}: {
  columns: BoardColumn<T>[]
  cardKey: (item: T) => string | number
  renderCard: (item: T) => ReactNode
  /** The column keys this card can move to (empty: it can't move). */
  movesFor: (item: T) => string[]
  onMove: (item: T, to: string) => void
  empty?: string
  label: string
}) {
  const [dragging, setDragging] = useState<T | null>(null)
  const [over, setOver] = useState<string | null>(null)
  const allowed = dragging ? movesFor(dragging) : []

  return (
    <div className="board" role="list" aria-label={label}>
      {columns.map((col) => {
        const canTake = allowed.includes(col.key)
        return (
          <section
            key={col.key}
            role="listitem"
            className={`board-col tone-${col.tone}${canTake ? ' can-take' : ''}${canTake && over === col.key ? ' is-over' : ''}${dragging && !canTake ? ' is-dim' : ''}`}
            aria-labelledby={`col-${col.key}`}
            onDragOver={(e) => {
              if (!canTake) return
              e.preventDefault()
              e.dataTransfer.dropEffect = 'move'
              setOver(col.key)
            }}
            onDragLeave={(e) => {
              if (!e.currentTarget.contains(e.relatedTarget as Node)) setOver((o) => (o === col.key ? null : o))
            }}
            onDrop={(e) => {
              e.preventDefault()
              if (dragging && canTake) onMove(dragging, col.key)
              setDragging(null)
              setOver(null)
            }}
          >
            <header className="board-col-head">
              <span className="board-dot" style={col.color ? { background: col.color } : undefined} aria-hidden />
              <h2 id={`col-${col.key}`}>{col.title}</h2>
              <span className="board-count">{col.total}</span>
              {col.aside && <span className="board-aside">{col.aside}</span>}
            </header>
            <div className="board-cards">
              {col.error ? (
                <p className="form-error">{col.error.message}</p>
              ) : col.items === null ? (
                <p className="muted board-empty">Loading…</p>
              ) : col.items.length === 0 ? (
                <p className="muted board-empty">{empty}</p>
              ) : (
                col.items.map((item) => {
                  const movable = movesFor(item).length > 0
                  return (
                    <article
                      key={cardKey(item)}
                      className={`board-card${movable ? ' is-movable' : ''}${dragging === item ? ' is-dragging' : ''}`}
                      draggable={movable}
                      onDragStart={(e) => {
                        e.dataTransfer.effectAllowed = 'move'
                        // Firefox won't start a drag without some data.
                        e.dataTransfer.setData('text/plain', String(cardKey(item)))
                        setDragging(item)
                      }}
                      onDragEnd={() => {
                        setDragging(null)
                        setOver(null)
                      }}
                    >
                      {renderCard(item)}
                    </article>
                  )
                })
              )}
            </div>
            {col.footer && <p className="board-foot muted">{col.footer}</p>}
          </section>
        )
      })}
    </div>
  )
}

/** "List / Board", kept in the address so a link or refresh keeps the view. */
export function ViewSwitch({ view, onChange }: { view: 'list' | 'board'; onChange: (v: 'list' | 'board') => void }) {
  return (
    <div className="toggle" role="group" aria-label="View as">
      <button className={view === 'board' ? 'is-on' : ''} aria-pressed={view === 'board'} onClick={() => onChange('board')}>
        Board
      </button>
      <button className={view === 'list' ? 'is-on' : ''} aria-pressed={view === 'list'} onClick={() => onChange('list')}>
        List
      </button>
    </div>
  )
}
