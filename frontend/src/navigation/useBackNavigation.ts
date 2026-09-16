import { useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { useCanGoBack } from './NavigationTrailContext';

/**
 * Returns a function that navigates back to wherever the user actually
 * came from within OptiFleet, or to a caller-supplied fallback route when
 * there is no such history (direct load, bookmark, refresh, or the tab was
 * just opened). Never falls through to leaving the app, and never relies
 * on history.back() alone.
 *
 * `fallbackTo` must already be a route the current user can reach (the
 * caller picks it based on its own portal/permission context, e.g. a
 * Tenant Detail page falls back to '/platform/tenants', a Vehicle Detail
 * page falls back to '/app/vehicles').
 */
export function useBackNavigation(fallbackTo: string) {
  const navigate = useNavigate();
  const canGoBack = useCanGoBack();

  return useCallback(() => {
    if (canGoBack) {
      navigate(-1);
    } else {
      navigate(fallbackTo, { replace: true });
    }
  }, [canGoBack, fallbackTo, navigate]);
}
