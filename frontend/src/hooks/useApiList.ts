import { useCallback, useEffect, useState } from 'react';
import { apiClient, extractApiError } from '../api/client';
import type { PaginationMeta } from '../components/Pagination';

export function useApiList<T>(endpoint: string, params: Record<string, unknown>, reloadKey = 0) {
  const [data, setData] = useState<T[]>([]);
  const [meta, setMeta] = useState<PaginationMeta | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const paramsKey = JSON.stringify(params);

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    apiClient
      .get(endpoint, { params })
      .then((res) => {
        setData(res.data.data);
        setMeta(res.data.meta ?? null);
      })
      .catch((err) => setError(extractApiError(err).message))
      .finally(() => setLoading(false));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [endpoint, paramsKey, reloadKey]);

  useEffect(() => {
    load();
  }, [load]);

  return { data, meta, loading, error, reload: load };
}
