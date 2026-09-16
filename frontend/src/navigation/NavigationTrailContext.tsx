import { createContext, useContext, useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { useLocation } from 'react-router-dom';

interface NavigationTrailContextValue {
  canGoBack: boolean;
}

const NavigationTrailContext = createContext<NavigationTrailContextValue>({ canGoBack: false });

/**
 * Tracks whether the user has navigated at least once inside this SPA
 * session (in-memory only, never persisted). A direct load, bookmark, or
 * hard refresh always starts this at false, which is exactly the signal
 * useBackNavigation needs to decide between history.back() and a fallback
 * route — no reliance on document.referrer or history.length, both of
 * which are unreliable across browsers/refreshes.
 */
export function NavigationTrailProvider({ children }: { children: ReactNode }) {
  const location = useLocation();
  const visitCount = useRef(0);
  const [canGoBack, setCanGoBack] = useState(false);

  useEffect(() => {
    visitCount.current += 1;
    if (visitCount.current > 1 && !canGoBack) {
      setCanGoBack(true);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [location.key]);

  return <NavigationTrailContext.Provider value={{ canGoBack }}>{children}</NavigationTrailContext.Provider>;
}

export function useCanGoBack(): boolean {
  return useContext(NavigationTrailContext).canGoBack;
}
