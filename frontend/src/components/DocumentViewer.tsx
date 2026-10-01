import { useEffect, useState } from 'react';
import { extractApiError } from '../api/client';
import { Modal } from './Modal';
import { fetchProtectedFile } from '../utils/protectedFile';

/**
 * Opens a protected document in a modal according to its ACTUAL type — the Content-Type the
 * backend returned for the stored file (falling back to the stored MIME metadata), never an
 * assumption from the context it was uploaded in:
 *   image/*          → inline image preview
 *   application/pdf  → embedded PDF viewer (+ open in a new tab)
 *   anything else    → download (e.g. .doc / .docx)
 * Download is always available. Files are fetched through the authorized API only.
 */
export function DocumentViewer({ path, title, fileName, mimeType, onClose }: { path: string; title: string; fileName?: string | null; mimeType?: string | null; onClose: () => void }) {
  const [file, setFile] = useState<{ url: string; type: string; name: string | null } | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let url: string | null = null;
    let cancelled = false;
    fetchProtectedFile(path)
      .then(({ blob, name }) => {
        if (cancelled) return;
        url = URL.createObjectURL(blob);
        setFile({ url, type: blob.type || mimeType || 'application/octet-stream', name });
      })
      .catch((err) => !cancelled && setError(extractApiError(err).message));
    return () => {
      cancelled = true;
      if (url) URL.revokeObjectURL(url);
    };
  }, [path, mimeType]);

  const name = fileName ?? file?.name ?? 'document';
  const isImage = file?.type.startsWith('image/');
  const isPdf = file?.type === 'application/pdf';

  return (
    <Modal open title={title} onClose={onClose} width={860}>
      <div style={{ fontSize: 12, color: '#6b7280', marginTop: -6, marginBottom: 10 }}>
        {name}
        {file && <span> · {file.type}</span>}
      </div>
      {error && <div style={{ color: '#b91c1c', fontSize: 13 }}>{error}</div>}
      {!error && !file && <div style={{ fontSize: 13 }}>Loading…</div>}
      {file && isImage && <img src={file.url} alt={name} style={{ display: 'block', maxWidth: '100%', maxHeight: '70vh', margin: '0 auto', border: '1px solid #e5e7eb' }} />}
      {file && isPdf && <iframe title={name} src={file.url} style={{ width: '100%', height: '70vh', border: '1px solid #e5e7eb' }} />}
      {file && !isImage && !isPdf && <div style={{ fontSize: 13 }}>This file type cannot be previewed in the browser — download it to open it.</div>}
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 14 }}>
        {file && isPdf && (
          <a className="btn-secondary" href={file.url} target="_blank" rel="noreferrer">
            Open in new tab
          </a>
        )}
        {file && (
          <a className="btn-secondary" href={file.url} download={name}>
            Download
          </a>
        )}
        <button className="btn-primary" onClick={onClose}>
          Close
        </button>
      </div>
    </Modal>
  );
}
