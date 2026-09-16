import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';

interface BreadcrumbLabelContextValue {
  labels: Record<string, string>;
  setLabel: (segmentValue: string, label: string | null | undefined) => void;
}

const BreadcrumbLabelContext = createContext<BreadcrumbLabelContextValue | null>(null);

/**
 * Holds human-readable labels for dynamic route segments (record ids), so
 * the Breadcrumb can show e.g. a tenant's name instead of its uuid. Pages
 * register their own record's label via useBreadcrumbLabel; nothing here
 * assumes a specific route shape.
 */
export function BreadcrumbLabelProvider({ children }: { children: ReactNode }) {
  const [labels, setLabels] = useState<Record<string, string>>({});

  const setLabel = useCallback((segmentValue: string, label: string | null | undefined) => {
    setLabels((prev) => {
      if (!label) {
        if (!(segmentValue in prev)) return prev;
        const next = { ...prev };
        delete next[segmentValue];
        return next;
      }
      if (prev[segmentValue] === label) return prev;
      return { ...prev, [segmentValue]: label };
    });
  }, []);

  const value = useMemo(() => ({ labels, setLabel }), [labels, setLabel]);

  return <BreadcrumbLabelContext.Provider value={value}>{children}</BreadcrumbLabelContext.Provider>;
}

/**
 * Call from a detail/edit page once the record has loaded, e.g.
 * useBreadcrumbLabel(tenant?.id, tenant?.name). While the record is still
 * loading (id undefined) the breadcrumb safely falls back to a generic
 * label — this never throws and never blocks rendering.
 */
export function useBreadcrumbLabel(segmentValue: string | undefined | null, label: string | undefined | null): void {
  const ctx = useContext(BreadcrumbLabelContext);
  const registeredRef = useRef<string | null>(null);

  useEffect(() => {
    if (!ctx || !segmentValue || !label) return;
    ctx.setLabel(segmentValue, label);
    registeredRef.current = segmentValue;
    return () => {
      ctx.setLabel(segmentValue, null);
      registeredRef.current = null;
    };
  }, [ctx, segmentValue, label]);
}

export function useBreadcrumbLabels(): Record<string, string> {
  const ctx = useContext(BreadcrumbLabelContext);
  return ctx?.labels ?? {};
}
