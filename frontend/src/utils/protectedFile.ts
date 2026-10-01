import { apiClient } from '../api/client';

/**
 * Documents are served only by authorized API endpoints (Bearer token + tenant header), so a
 * plain <a href> cannot open them. These fetch the file through the API client and hand the
 * browser a short-lived object URL.
 */
/** File name from a Content-Disposition header (RFC 5987 `filename*` preferred). */
function fileNameFrom(disposition: string | undefined): string | null {
  if (!disposition) return null;
  const star = /filename\*=(?:UTF-8'')?([^;]+)/i.exec(disposition);
  if (star) {
    try {
      return decodeURIComponent(star[1].trim().replace(/^"|"$/g, ''));
    } catch {
      // fall through to the plain filename parameter
    }
  }
  const plain = /filename="?([^";]+)"?/i.exec(disposition);
  return plain ? plain[1].trim() : null;
}

/**
 * The file as a Blob whose type is the Content-Type the backend served (the stored file's real
 * MIME), plus the file name the backend served it under.
 */
export async function fetchProtectedFile(path: string): Promise<{ blob: Blob; name: string | null }> {
  const res = await apiClient.get(path, { responseType: 'blob' });
  return { blob: res.data as Blob, name: fileNameFrom(res.headers['content-disposition'] as string | undefined) };
}

export async function fetchBlobUrl(path: string): Promise<string> {
  return URL.createObjectURL((await fetchProtectedFile(path)).blob);
}

/** Opens the document in a new tab (PDF viewer / image preview). */
export async function openProtectedFile(path: string): Promise<void> {
  const tab = window.open('', '_blank');
  const url = await fetchBlobUrl(path);
  if (tab) tab.location.href = url;
  else window.location.assign(url);
  setTimeout(() => URL.revokeObjectURL(url), 60_000);
}

/** Downloads the document under `filename`. */
export async function downloadProtectedFile(path: string, filename: string): Promise<void> {
  const url = await fetchBlobUrl(path);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(url), 60_000);
}
