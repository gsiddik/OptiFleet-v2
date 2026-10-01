import { useEffect, useState } from 'react';
import { apiClient } from '../api/client';

/**
 * Loads private images through the authenticated API (never a public URL) and exposes them as
 * object URLs for <img> previews; revoked on unmount.
 */
export function useAuthorizedPreviews(showUrl: (id: string) => string, ids: string[]) {
  const [previews, setPreviews] = useState<Record<string, string>>({});

  useEffect(() => {
    let cancelled = false;
    const urls: string[] = [];
    Promise.all(
      ids
        .filter((id) => !previews[id])
        .map((id) =>
          apiClient.get(showUrl(id), { responseType: 'blob' }).then((res) => {
            const url = URL.createObjectURL(res.data);
            urls.push(url);
            if (!cancelled) setPreviews((prev) => ({ ...prev, [id]: url }));
          }),
        ),
    ).catch(() => {});
    return () => {
      cancelled = true;
      urls.forEach((u) => URL.revokeObjectURL(u));
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ids.join(',')]);

  return previews;
}
