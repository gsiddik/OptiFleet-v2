import { useMemo, useState, type ReactNode } from 'react';
import { apiClient, extractApiError } from '../api/client';
import { Modal } from './Modal';
import { ScrollTable, type ScrollColumn } from './ScrollTable';
import { downloadProtectedFile } from '../utils/protectedFile';
import type { ExcelImportPreview, ExcelImportPreviewRow, ExcelImportResult, ExcelImportRowStatus } from '../types';
import { t } from '../i18n/i18n';

const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
const MAX_BYTES = 5 * 1024 * 1024;
const STATUS_COLOR: Record<ExcelImportRowStatus, string> = { VALID: '#15803d', DUPLICATE: '#b45309', INVALID: '#b91c1c' };

/**
 * Shared Excel import flow (Rim serials, Vehicles, Products): Download Template → upload the filled
 * workbook → preview (every row VALID / DUPLICATE / INVALID with its reasons, columns as the server
 * reports them) → select rows → import → result. The server reads only the "Fill Here" sheet and
 * validates every selected row again; this screen pre-checks only the file (.xlsx, size, signature).
 * Only VALID rows can be selected: invalid and duplicate rows are listed with their reasons.
 */
export function ExcelImportModal({
  title,
  intro,
  templatePath,
  templateFilename,
  previewPath,
  importPath,
  query = {},
  onClose,
  onImported,
  templateControls,
}: {
  title: string;
  intro?: ReactNode;
  templatePath: string;
  templateFilename: string;
  previewPath: string;
  importPath: string;
  /** extra query parameters for the three endpoints (e.g. mode, item_type) */
  query?: Record<string, string>;
  onClose: () => void;
  onImported: () => void;
  /** optional controls above the download button (e.g. an Item Type select) */
  templateControls?: ReactNode;
}) {
  const [file, setFile] = useState<File | null>(null);
  const [fileError, setFileError] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [preview, setPreview] = useState<ExcelImportPreview | null>(null);
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [result, setResult] = useState<ExcelImportResult | null>(null);
  const qs = new URLSearchParams(query).toString();
  const withQuery = (path: string) => (qs ? `${path}?${qs}` : path);

  const rows = preview?.rows ?? null;
  const validRows = useMemo(() => (rows ?? []).filter((r) => r.status === 'VALID'), [rows]);

  async function downloadTemplate() {
    setError(null);
    try {
      await downloadProtectedFile(withQuery(templatePath), templateFilename);
    } catch (e) {
      setError(extractApiError(e).message);
    }
  }

  async function pickFile(next: File | null) {
    setFile(null);
    setFileError(null);
    setPreview(null);
    setResult(null);
    if (!next) return;
    const problem = await checkXlsx(next);
    if (problem) setFileError(problem);
    else setFile(next);
  }

  async function upload() {
    if (!file) return;
    setBusy(true);
    setError(null);
    setFileError(null);
    try {
      const form = new FormData();
      form.append('file', file);
      const res = await apiClient.post(withQuery(previewPath), form, { headers: { 'Content-Type': 'multipart/form-data' } });
      const data: ExcelImportPreview = res.data.data;
      setPreview(data);
      setSelected(new Set(data.rows.filter((r) => r.status === 'VALID').map((r) => r.row)));
    } catch (e) {
      const api = extractApiError(e);
      setFileError(Object.values(api.errors ?? {})[0]?.[0] ?? api.message);
    } finally {
      setBusy(false);
    }
  }

  async function runImport() {
    if (!rows) return;
    setBusy(true);
    setError(null);
    try {
      const payload = rows.filter((r) => selected.has(r.row)).map((r) => ({ row: r.row, values: r.values }));
      const res = await apiClient.post(withQuery(importPath), { rows: payload });
      const outcome: ExcelImportResult = res.data.data;
      setResult(outcome);
      if (outcome.imported > 0) onImported();
    } catch (e) {
      setError(extractApiError(e).message);
    } finally {
      setBusy(false);
    }
  }

  const toggle = (row: number) =>
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(row)) next.delete(row);
      else next.add(row);
      return next;
    });
  const allSelected = validRows.length > 0 && selected.size === validRows.length;

  const previewColumns: ScrollColumn<ExcelImportPreviewRow>[] = [
    {
      header: 'select',
      headerCell: (
        <input
          type="checkbox"
          aria-label={t('excelImport.fields.selectAllValidRows')}
          checked={allSelected}
          disabled={validRows.length === 0}
          onChange={() => setSelected(allSelected ? new Set() : new Set(validRows.map((r) => r.row)))}
        />
      ),
      cell: (r) => (
        <input type="checkbox" aria-label={t('excelImport.fields.selectRow', { row: r.row })} checked={selected.has(r.row)} disabled={r.status !== 'VALID'} onChange={() => toggle(r.row)} />
      ),
    },
    { header: t('excelImport.fields.row'), cell: (r) => r.row },
    ...(preview?.columns ?? []).map<ScrollColumn<ExcelImportPreviewRow>>((c) => ({ header: c.header, cell: (r) => showValue(r.values[c.id]) })),
    {
      header: t('excelImport.fields.check'),
      cell: (r) => (
        <span data-row-status={r.status} style={{ color: STATUS_COLOR[r.status], whiteSpace: 'normal' }}>
          {r.status === 'VALID' ? t('excelImport.status.ready') : `${t(r.status === 'DUPLICATE' ? 'excelImport.status.duplicate' : 'excelImport.status.invalid')}: ${r.errors.join(' ')}`}
        </span>
      ),
    },
  ];

  const failedColumns: ScrollColumn<ExcelImportResult['failed'][number]>[] = [
    { header: t('excelImport.fields.row'), cell: (r) => r.row },
    { header: t('excelImport.fields.reason'), cell: (r) => <span style={{ color: STATUS_COLOR[r.status], whiteSpace: 'normal' }}>{r.reason}</span> },
  ];

  return (
    <Modal open title={title} onClose={onClose} width={880}>
      {intro && <div style={{ fontSize: 13, marginTop: 0, marginBottom: 10, color: '#374151' }}>{intro}</div>}
      <p style={{ fontSize: 12, marginTop: 0, color: '#6b7280' }}>{t('excelImport.help.sheets')}</p>
      {error && <Notice tone="error">{error}</Notice>}

      {!result && (
        <>
          {templateControls}
          <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'center', marginBottom: 12 }}>
            <button type="button" className="btn-secondary" onClick={downloadTemplate} data-download-template>
              {t('excelImport.actions.downloadTemplate')}
            </button>
            <input type="file" aria-label={t('excelImport.fields.excelFile')} accept={`.xlsx,${XLSX_MIME}`} onChange={(e) => pickFile(e.target.files?.[0] ?? null)} style={{ fontSize: 13, maxWidth: '100%' }} />
            <button type="button" className="btn-primary" disabled={!file || busy} onClick={upload}>
              {busy && !rows ? t('excelImport.actions.reading') : t('excelImport.actions.uploadPreview')}
            </button>
          </div>
        </>
      )}
      {fileError && (
        <div data-file-error>
          <Notice tone="error">{fileError}</Notice>
        </div>
      )}

      {rows && !result && preview && (
        <div data-import-preview>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8, flexWrap: 'wrap', fontSize: 13, marginBottom: 6 }}>
            <span data-import-summary>
              {t('excelImport.help.previewSummary', { total: preview.summary.total, valid: preview.summary.valid, duplicate: preview.summary.duplicate, invalid: preview.summary.invalid, selected: selected.size })}
            </span>
            <span style={{ display: 'flex', gap: 10 }}>
              <button type="button" className="btn-link" disabled={validRows.length === 0} onClick={() => setSelected(new Set(validRows.map((r) => r.row)))}>
                {t('excelImport.actions.selectAllValid')}
              </button>
              <button type="button" className="btn-link" onClick={() => setSelected(new Set())}>
                {t('excelImport.actions.deselectAll')}
              </button>
            </span>
          </div>
          <ScrollTable dataAttr="import-preview" columns={previewColumns} rows={rows} rowKey={(r) => String(r.row)} maxRows={8} />
          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 14 }}>
            <button type="button" className="btn-secondary" onClick={onClose}>
              {t('common.actions.cancel')}
            </button>
            <button type="button" className="btn-primary" disabled={busy || selected.size === 0} onClick={runImport} data-import-confirm>
              {busy ? t('excelImport.actions.importing') : t('excelImport.actions.importSelected', { count: selected.size })}
            </button>
          </div>
        </div>
      )}

      {result && (
        <div data-import-result={result.status}>
          {result.status === 'SUCCESS' && <Notice tone="success">{t('excelImport.help.resultSuccess', { count: result.imported })}</Notice>}
          {result.status === 'PARTIAL' && <Notice tone="warning">{t('excelImport.help.resultPartial', { imported: result.imported, failed: result.failed.length })}</Notice>}
          {result.status === 'FAILED' && <Notice tone="error">{t('excelImport.help.resultFailed', { count: result.failed.length })}</Notice>}
          {result.failed.length > 0 && <ScrollTable dataAttr="import-failed" columns={failedColumns} rows={result.failed} rowKey={(r) => String(r.row)} maxRows={5} />}
          <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 14 }}>
            <button type="button" className="btn-primary" onClick={onClose}>
              {t('common.actions.close')}
            </button>
          </div>
        </div>
      )}
    </Modal>
  );
}

function Notice({ tone, children }: { tone: 'success' | 'warning' | 'error'; children: string }) {
  const palette = {
    success: { color: '#166534', background: '#f0fdf4', border: '#bbf7d0' },
    warning: { color: '#92400e', background: '#fffbeb', border: '#fde68a' },
    error: { color: '#991b1b', background: '#fef2f2', border: '#fecaca' },
  }[tone];
  return (
    <div role={tone === 'error' ? 'alert' : 'status'} style={{ fontSize: 13, color: palette.color, background: palette.background, border: `1px solid ${palette.border}`, borderRadius: 6, padding: '8px 12px', marginBottom: 10 }}>
      {children}
    </div>
  );
}

function showValue(value: string | number | boolean | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—';
  if (typeof value === 'boolean') return value ? t('common.fields.yes') : t('common.fields.no');
  return String(value);
}

/** Pre-upload check: .xlsx name, size, and the zip signature every .xlsx starts with. */
async function checkXlsx(file: File): Promise<string | null> {
  if (!/\.xlsx$/i.test(file.name)) return t('excelImport.errors.onlyXlsx');
  if (file.size === 0) return t('excelImport.errors.fileEmpty');
  if (file.size > MAX_BYTES) return t('excelImport.errors.fileTooLarge', { maxMb: 5 });
  const head = new Uint8Array(await file.slice(0, 4).arrayBuffer());
  if (head[0] !== 0x50 || head[1] !== 0x4b || head[2] !== 0x03 || head[3] !== 0x04) return t('excelImport.errors.fileInvalid');
  return null;
}
