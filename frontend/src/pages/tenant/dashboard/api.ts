import { useCallback, useEffect, useRef, useState } from 'react';
import { apiClient } from '../../../api/client';
import { t } from '../../../i18n/i18n';
import type { DetailEnvelope, WidgetEnvelope } from './types';

export { widgetParams } from './params';

export type LoadState = 'loading' | 'ready' | 'error' | 'forbidden';

/** Localized error text: the backend's coded dashboard errors first, then a generic message. */
export function errorText(error: unknown): { message: string; status: number | null } {
  const err = error as { response?: { status?: number; data?: { code?: string; message?: string } } };
  const status = err.response?.status ?? null;
  const code = err.response?.data?.code;
  if (code && code.startsWith('dashboard.errors.')) return { message: t(code), status };
  return { message: t('dashboard.states.loadFailed'), status };
}

/** One widget's payload with its own loading / error / retry lifecycle. */
export function useWidget<T>(id: string, params: Record<string, unknown>) {
  const [state, setState] = useState<LoadState>('loading');
  const [envelope, setEnvelope] = useState<WidgetEnvelope<T> | null>(null);
  const [error, setError] = useState<string | null>(null);
  const key = JSON.stringify(params);
  const seq = useRef(0);

  const load = useCallback(
    (refresh = false) => {
      const run = ++seq.current;
      setState('loading');
      setError(null);
      apiClient
        .get(`/app/dashboard/widgets/${id}`, { params: { ...JSON.parse(key), ...(refresh ? { refresh: 1 } : {}) } })
        .then((res) => {
          if (run !== seq.current) return;
          setEnvelope(res.data.data as WidgetEnvelope<T>);
          setState('ready');
        })
        .catch((e) => {
          if (run !== seq.current) return;
          const { message, status } = errorText(e);
          setError(message);
          setState(status === 403 ? 'forbidden' : 'error');
        });
    },
    [id, key],
  );

  useEffect(() => {
    load();
  }, [load]);

  return { state, envelope, error, reload: () => load(true) };
}

/** A widget's paginated drill-down rows (same metric and scope rules as the widget). */
export function useDetails<Row>(id: string, params: Record<string, unknown> | null) {
  const [page, setPage] = useState(1);
  const [state, setState] = useState<LoadState>('loading');
  const [result, setResult] = useState<DetailEnvelope<Row> | null>(null);
  const [error, setError] = useState<string | null>(null);
  const key = params === null ? null : JSON.stringify(params);

  useEffect(() => setPage(1), [key]);

  const load = useCallback(() => {
    if (key === null) return;
    let cancelled = false;
    setState('loading');
    setError(null);
    apiClient
      .get(`/app/dashboard/widgets/${id}/details`, { params: { ...JSON.parse(key), page, per_page: 10 } })
      .then((res) => {
        if (cancelled) return;
        setResult(res.data.data as DetailEnvelope<Row>);
        setState('ready');
      })
      .catch((e) => {
        if (cancelled) return;
        const { message, status } = errorText(e);
        setError(message);
        setState(status === 403 ? 'forbidden' : 'error');
      });
    return () => {
      cancelled = true;
    };
  }, [id, key, page]);

  useEffect(() => load(), [load]);

  return { state, result, error, page, setPage, reload: load };
}
