import { useCallback, useEffect, useState } from 'react';
import { launchProduct, listProducts, type SwitcherProduct } from '../api/products';
import { classifyLaunchFailure, isNavigableUrl, type LaunchFailure } from '../api/productRules';
import type { ApiErrorShape } from '../api/client';
import { useAuth } from '../auth/AuthContext';
import { t } from '../i18n/i18n';

/**
 * The products this person may open from here without signing in again (the OptiNexus session carries over).
 * The list is what OptiNexus answers now (fetched when shown, when the window regains focus and when the list is opened);
 * nothing is shown while OptiNexus cannot be asked. Choosing a product asks again and only then navigates, to the link the
 * server returns.
 */
export function AppSwitcher() {
  const { refresh } = useAuth();
  const [products, setProducts] = useState<SwitcherProduct[]>([]);
  const [opening, setOpening] = useState<SwitcherProduct | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [nonce, setNonce] = useState(0);
  const reload = useCallback(() => setNonce((n) => n + 1), []);

  useEffect(() => {
    let cancelled = false;
    listProducts()
      .then((state) => {
        if (!cancelled) setProducts(state.available ? state.products : []);
      })
      .catch((raw) => {
        if (cancelled) return;
        // Nothing is offered while OptiNexus cannot be asked; an ended session is left to the session handling.
        setProducts([]);
        if (classifyLaunchFailure(raw as ApiErrorShape) === 'session_ended') void refresh();
      });
    return () => {
      cancelled = true;
    };
  }, [nonce, refresh]);

  useEffect(() => {
    const onVisible = () => {
      if (document.visibilityState === 'visible') reload();
    };
    window.addEventListener('focus', onVisible);
    document.addEventListener('visibilitychange', onVisible);
    return () => {
      window.removeEventListener('focus', onVisible);
      document.removeEventListener('visibilitychange', onVisible);
    };
  }, [reload]);

  async function open(product: SwitcherProduct) {
    setNotice(null);
    setOpening(product);
    let failure: LaunchFailure = 'error';
    try {
      const { url } = await launchProduct(product.code);
      if (isNavigableUrl(url)) {
        window.location.assign(url);
        return;
      }
    } catch (raw) {
      failure = classifyLaunchFailure(raw as ApiErrorShape);
    }
    if (failure === 'session_ended') {
      await refresh();
      return;
    }
    if (failure === 'not_available') reload();
    setNotice(t(failure === 'not_available' ? 'auth.apps.notAvailable' : failure === 'unavailable' ? 'auth.apps.unavailable' : 'auth.apps.openFailed'));
    setOpening(null);
  }

  if (products.length === 0 && !opening) return null;

  return (
    <div style={{ position: 'relative' }}>
      <select
        aria-label={t('auth.apps.label')}
        value=""
        disabled={opening !== null}
        onFocus={reload}
        onChange={(e) => {
          const product = products.find((p) => p.code === e.target.value);
          if (product) void open(product);
        }}
        style={{ padding: '6px 10px', borderRadius: 6, border: '1px solid #d1d5db', fontSize: 13 }}
      >
        <option value="">{opening ? t('auth.apps.opening', { name: opening.name }) : t('auth.apps.label')}</option>
        {products.map((product) => (
          <option key={product.code} value={product.code}>
            {product.name}
          </option>
        ))}
      </select>
      <div role="status" aria-live="polite">
        {notice && (
          <div
            style={{
              position: 'absolute', top: '100%', right: 0, marginTop: 6, zIndex: 20, width: 240, padding: '8px 10px', borderRadius: 6,
              background: '#fef2f2', color: '#b91c1c', border: '1px solid #fecaca', fontSize: 12,
            }}
          >
            {notice}
          </div>
        )}
      </div>
    </div>
  );
}
