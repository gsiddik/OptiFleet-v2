import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
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
  // Depend on the stable setLabel function itself, not the context value
  // object — that object is recreated every time any label changes (it
  // wraps the labels map), so depending on it directly would make this
  // effect's own cleanup-then-rerun cycle re-register on every update,
  // looping forever (register -> new ctx -> cleanup unregisters -> effect
  // re-runs and re-registers -> new ctx -> ...).
  const setLabel = useContext(BreadcrumbLabelContext)?.setLabel;

  useEffect(() => {
    if (!setLabel || !segmentValue || !label) return;
    setLabel(segmentValue, label);
    return () => {
      setLabel(segmentValue, null);
    };
  }, [setLabel, segmentValue, label]);
}

export function useBreadcrumbLabels(): Record<string, string> {
  const ctx = useContext(BreadcrumbLabelContext);
  return ctx?.labels ?? {};
}
