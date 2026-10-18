import { useCallback, useEffect, useRef, useState } from 'react';
import { classifyFailure, extractApiError, type FailureKind } from '../api/delegation';
import type { ApiErrorShape } from '../api/client';

export type ResourceState<T> =
  | { status: 'loading'; data: null; error: null }
  | { status: 'ready'; data: T; error: null }
  | { status: FailureKind; data: null; error: ApiErrorShape };

/** Loads one delegation resource and says exactly which of loading / ready / forbidden / unavailable / error the screen is in. */
export function useDelegationResource<T>(load: () => Promise<T>, deps: unknown[]): ResourceState<T> & { reload: () => void } {
  const [state, setState] = useState<ResourceState<T>>({ status: 'loading', data: null, error: null });
  const [nonce, setNonce] = useState(0);
  const loader = useRef(load);
  loader.current = load;

  useEffect(() => {
    let cancelled = false;
    setState((current) => (current.status === 'ready' ? current : { status: 'loading', data: null, error: null }));
    loader
      .current()
      .then((data) => {
        if (!cancelled) setState({ status: 'ready', data, error: null });
      })
      .catch((err) => {
        if (cancelled) return;
        const error = extractApiError(err);
        setState({ status: classifyFailure(error), data: null, error });
      });
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [nonce, ...deps]);

  const reload = useCallback(() => setNonce((n) => n + 1), []);
  return { ...state, reload } as ResourceState<T> & { reload: () => void };
}
