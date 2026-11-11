import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from './api'

/**
 * GET `path` and keep the result; reload() fetches again (after a change).
 *
 * keepPrevious: while a new path loads (a filter changed), keep showing the
 * last result instead of blanking - `loading` says it's refreshing. Only for
 * pages that remount on a manufacturer switch (key={slug}), so one
 * manufacturer's numbers never sit under another's name.
 */
export function useApi<T>(path: string | null, { keepPrevious = false }: { keepPrevious?: boolean } = {}) {
  const [data, setData] = useState<T | null>(null)
  const [error, setError] = useState<ApiError | null>(null)
  const [loading, setLoading] = useState(path !== null)

  const reload = useCallback(async () => {
    if (path === null) return
    setLoading(true)
    try {
      setData(await api.get<T>(path))
      setError(null)
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(0, { message: 'Could not reach DealerDock.' }))
    } finally {
      setLoading(false)
    }
  }, [path])

  useEffect(() => {
    // A new path (another manufacturer) starts from nothing, so the last
    // one's rows never flash on screen.
    if (!keepPrevious) setData(null)
    reload()
  }, [reload, keepPrevious])

  return { data, error, loading, reload }
}
