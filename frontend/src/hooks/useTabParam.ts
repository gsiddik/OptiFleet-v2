import { useCallback, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { resolveTabId, type TabDef } from '../utils/tabs';

/**
 * Active tab kept by stable id and mirrored in `?tab=<id>`.
 *
 * - The initial tab comes from `?tab=`; legacy label links resolve through `resolveTabId`.
 * - Selecting a tab replaces the history entry (no extra Back step) and keeps every other
 *   query parameter. The default tab removes `?tab=`.
 */
export function useTabParam<Id extends string>(
  tabs: readonly TabDef<Id>[],
  fallback: Id,
  aliases: Readonly<Record<string, Id>> = {},
): [Id, (id: Id) => void] {
  const [searchParams, setSearchParams] = useSearchParams();
  const [tab, setTab] = useState<Id>(() => resolveTabId(tabs, searchParams.get('tab'), fallback, aliases));

  const selectTab = useCallback(
    (id: Id) => {
      setTab(id);
      setSearchParams(
        (prev) => {
          const next = new URLSearchParams(prev);
          if (id === fallback) next.delete('tab');
          else next.set('tab', id);
          return next;
        },
        { replace: true },
      );
    },
    [fallback, setSearchParams],
  );

  return [tab, selectTab];
}
