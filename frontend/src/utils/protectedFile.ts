import { apiClient } from '../api/client';

/**
 * Documents are served only by authorized API endpoints (Bearer token + tenant header), so a
 * plain <a href> cannot open them. These fetch the file through the API client and hand the
 * browser a short-lived object URL.
 */
export async function fetchBlobUrl(path: string): Promise<string> {
  const res = await apiClient.get(path, { responseType: 'blob' });
  return URL.createObjectURL(res.data as Blob);
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
