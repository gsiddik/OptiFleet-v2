import { useRef, useState } from 'react';
import { extractApiError } from '../api/client';
import { t } from '../i18n/i18n';

export interface UploadedImage {
  id: string;
  previewUrl: string;
  name?: string;
}

/**
 * Reusable "Image Placeholder -> JPG/PNG Upload -> Preview" control, used
 * anywhere a plain Image URL text field would otherwise be required
 * (Work Order Return/Removed-Component evidence, and any future upload
 * surfaces this session's image-URL audit finds). The component itself
 * is presentational only — upload/remove are delegated to the caller so
 * this stays reusable across different backends/endpoints; the caller is
 * responsible for resolving each image's `previewUrl` (a blob URL for a
 * private/authenticated endpoint, or a plain public URL).
 */
export function ImageUploadField({
  images,
  onUpload,
  onRemove,
  disabled = false,
  multiple = true,
  label = 'JPG or PNG',
  maxSizeBytes,
  uploadLabel = 'Upload Image',
}: {
  images: UploadedImage[];
  onUpload: (file: File) => Promise<void>;
  onRemove?: (id: string) => Promise<void>;
  disabled?: boolean;
  multiple?: boolean;
  label?: string;
  /** Client-side size limit (the backend enforces its own limit regardless). */
  maxSizeBytes?: number;
  uploadLabel?: string;
}) {
  const [uploading, setUploading] = useState(false);
  const [removingId, setRemovingId] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const inputRef = useRef<HTMLInputElement>(null);

  async function handleFiles(files: FileList | null) {
    if (!files || files.length === 0) return;
    setError(null);
    setUploading(true);
    try {
      for (const file of Array.from(files)) {
        if (!['image/jpeg', 'image/png'].includes(file.type) || !/\.(jpe?g|png)$/i.test(file.name)) {
          setError(t('common.errors.onlyJpgPngImagesAccepted'));
          continue;
        }
        if (maxSizeBytes && file.size > maxSizeBytes) {
          setError(t('common.errors.nameExceedsValueMbMaximumSize', { name: file.name, value: Math.round(maxSizeBytes / 1024 / 1024) }));
          continue;
        }
        await onUpload(file);
      }
    } catch (err) {
      const apiError = extractApiError(err);
      setError(apiError.errors ? (Object.values(apiError.errors).flat()[0] ?? apiError.message) : apiError.message || t('common.errors.uploadFailedPleaseTryAgain'));
    } finally {
      setUploading(false);
      if (inputRef.current) inputRef.current.value = '';
    }
  }

  async function handleRemove(id: string) {
    if (!onRemove) return;
    setRemovingId(id);
    setError(null);
    try {
      await onRemove(id);
    } catch {
      setError(t('common.errors.couldNotRemoveImage'));
    } finally {
      setRemovingId(null);
    }
  }

  return (
    <div>
      {images.length === 0 && (
        <div
          style={{
            border: '2px dashed #d1d5db',
            borderRadius: 8,
            padding: '20px 16px',
            textAlign: 'center',
            color: '#9ca3af',
            fontSize: 13,
            marginBottom: 8,
          }}
        >
          <div style={{ marginBottom: 8 }}>{t('common.empty.noImageSelected')}</div>
          {!disabled && (
            <button type="button" className="btn-secondary" disabled={uploading} onClick={() => inputRef.current?.click()}>
              {uploading ? t('common.actions.uploading') : uploadLabel}
            </button>
          )}
          <div style={{ marginTop: 6, fontSize: 11 }}>{label}</div>
        </div>
      )}

      {images.length > 0 && (
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 8 }}>
          {images.map((img) => (
            <div key={img.id} style={{ position: 'relative' }}>
              <img
                src={img.previewUrl}
                alt={img.name ?? t('common.tooltips.evidence')}
                style={{ width: 90, height: 90, objectFit: 'cover', borderRadius: 6, border: '1px solid #e5e7eb', display: 'block' }}
              />
              {onRemove && !disabled && (
                <button
                  type="button"
                  aria-label={t('common.actions.removeValue', { value: img.name ?? 'image' })}
                  disabled={removingId === img.id}
                  onClick={() => handleRemove(img.id)}
                  style={{
                    position: 'absolute',
                    top: -6,
                    right: -6,
                    width: 20,
                    height: 20,
                    borderRadius: '50%',
                    border: 'none',
                    background: '#ef4444',
                    color: '#fff',
                    fontSize: 12,
                    lineHeight: '20px',
                    cursor: 'pointer',
                    padding: 0,
                  }}
                >
                  ×
                </button>
              )}
            </div>
          ))}
          {!disabled && (multiple || images.length === 0) && (
            <button
              type="button"
              className="btn-secondary"
              disabled={uploading}
              onClick={() => inputRef.current?.click()}
              style={{ width: 90, height: 90, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 12 }}
            >
              {uploading ? '…' : t('common.actions.add')}
            </button>
          )}
        </div>
      )}

      <input
        ref={inputRef}
        type="file"
        accept=".jpg,.jpeg,.png,image/jpeg,image/png"
        multiple={multiple}
        style={{ display: 'none' }}
        onChange={(e) => handleFiles(e.target.files)}
      />
      {error && <div style={{ color: '#dc2626', fontSize: 12, marginTop: 4 }}>{error}</div>}
    </div>
  );
}
