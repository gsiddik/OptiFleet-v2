import { useMemo, useState } from 'react';
import { apiClient, extractApiError } from '../../../api/client';
import { Modal } from '../../../components/Modal';
import { ScrollTable, type ScrollColumn } from '../../../components/ScrollTable';
import { formatDate } from '../../../utils/date';
import { downloadProtectedFile } from '../../../utils/protectedFile';
import type { ProductItem } from '../../../types';
import { message } from '../../../i18n/messages';

type RowStatus = 'VALID' | 'DUPLICATE' | 'INVALID';

interface PreviewRow {
  row: number;
  serial_number: string;
  manufacture_date_code: string | null;
  purchase_date: string | null;
  status: RowStatus;
  errors: string[];
}

interface FailedRow {
  row: number;
  serial_number: string;
  manufacture_date_code: string | null;
  purchase_date: string | null;
  status: RowStatus;
  reason: string;
}

interface ImportResult {
  status: 'SUCCESS' | 'PARTIAL' | 'FAILED';
  imported: number;
  failed: FailedRow[];
}

const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
const MAX_BYTES = 5 * 1024 * 1024;
const STATUS_COLOR: Record<RowStatus, string> = { VALID: '#15803d', DUPLICATE: '#b45309', INVALID: '#b91c1c' };

/**
 * New Stock import: download the template, upload the filled workbook, review the preview (select
 * all / some / none) and import. The backend validates the file and every row again — this
 * screen only pre-checks the file type so a wrong file is reported before it is uploaded.
 */
export function ImportTiresModal({ product, onClose, onImported }: { product: ProductItem; onClose: () => void; onImported: () => void }) {
  const [file, setFile] = useState<File | null>(null);
  const [fileError, setFileError] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [rows, setRows] = useState<PreviewRow[] | null>(null);
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [result, setResult] = useState<ImportResult | null>(null);

  const base = `/app/tire-products/${product.id}`;
  const counts = useMemo(() => {
    const c = { VALID: 0, DUPLICATE: 0, INVALID: 0 };
    rows?.forEach((r) => (c[r.status] += 1));
    return c;
  }, [rows]);

  async function downloadTemplate() {
    setError(null);
    try {
      await downloadProtectedFile(`${base}/import-template`, `tire-import-${product.code ?? 'template'}.xlsx`);
    } catch (e) {
      setError(extractApiError(e).message);
    }
  }

  async function pickFile(next: File | null) {
    setFile(null);
    setFileError(null);
    setRows(null);
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
      const res = await apiClient.post(`${base}/import/preview`, form, { headers: { 'Content-Type': 'multipart/form-data' } });
      const preview: PreviewRow[] = res.data.data.rows;
      setRows(preview);
      setSelected(new Set(preview.filter((r) => r.status === 'VALID').map((r) => r.row)));
    } catch (e) {
      const api = extractApiError(e);
      setFileError(api.errors?.file?.[0] ?? api.errors?.product?.[0] ?? api.message);
    } finally {
      setBusy(false);
    }
  }

  async function runImport() {
    if (!rows) return;
    setBusy(true);
    setError(null);
    try {
      const payload = rows
        .filter((r) => selected.has(r.row))
        .map((r) => ({ row: r.row, serial_number: r.serial_number, manufacture_date_code: r.manufacture_date_code, purchase_date: r.purchase_date }));
      const res = await apiClient.post(`${base}/import`, { rows: payload });
      const outcome: ImportResult = res.data.data;
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
  const allSelected = !!rows && rows.length > 0 && selected.size === rows.length;

  const previewColumns: ScrollColumn<PreviewRow>[] = [
    {
      header: 'select',
      headerCell: (
        <input
          type="checkbox"
          aria-label="Select all rows"
          checked={allSelected}
          onChange={() => setSelected(allSelected ? new Set() : new Set(rows?.map((r) => r.row)))}
        />
      ),
      cell: (r) => <input type="checkbox" aria-label={`Select row ${r.row}`} checked={selected.has(r.row)} onChange={() => toggle(r.row)} />,
    },
    { header: 'Row', cell: (r) => r.row },
    { header: 'Serial Number', cell: (r) => <span style={{ fontFamily: 'monospace' }}>{r.serial_number || '—'}</span> },
    { header: 'Manufacture Date Code', cell: (r) => r.manufacture_date_code ?? '—' },
    { header: 'Purchase Date', cell: (r) => showDate(r.purchase_date) },
    {
      header: 'Check',
      cell: (r) => (
        <span data-row-status={r.status} style={{ color: STATUS_COLOR[r.status], whiteSpace: 'normal' }}>
          {r.status === 'VALID'
            ? message('tire.fields.ready')
            : message(r.status === 'DUPLICATE' ? 'tire.import.rowStatusDuplicate' : 'tire.import.rowStatusInvalid', { errors: r.errors.join(' ') })}
        </span>
      ),
    },
  ];

  const failedColumns: ScrollColumn<FailedRow>[] = [
    { header: 'Row', cell: (r) => r.row },
    { header: 'Serial Number', cell: (r) => <span style={{ fontFamily: 'monospace' }}>{r.serial_number || '—'}</span> },
    { header: 'Reason', cell: (r) => <span style={{ color: STATUS_COLOR[r.status], whiteSpace: 'normal' }}>{r.reason}</span> },
  ];

  return (
    <Modal open title="Import New Stock" onClose={onClose} width={820}>
      <p style={{ fontSize: 13, marginTop: 0, color: '#374151' }}>
        Register many tires of <strong>{product.name}</strong> at once. Download the template, fill the sheet <em>Fill Here</em> (Serial Number, Manufacture Date Code, Purchase
        Date) and upload it.
      </p>
      {error && <Notice tone="error">{error}</Notice>}

      {!result && (
        <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'center', marginBottom: 12 }}>
          <button type="button" className="btn-secondary" onClick={downloadTemplate} data-download-template>
            Download Template
          </button>
          <input type="file" aria-label="Excel file" accept={`.xlsx,${XLSX_MIME}`} onChange={(e) => pickFile(e.target.files?.[0] ?? null)} style={{ fontSize: 13, maxWidth: '100%' }} />
          <button type="button" className="btn-primary" disabled={!file || busy} onClick={upload}>
            {busy && !rows ? 'Reading…' : 'Upload'}
          </button>
        </div>
      )}
      {fileError && (
        <div data-file-error>
          <Notice tone="error">{fileError}</Notice>
        </div>
      )}

      {rows && !result && (
        <div data-import-preview>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8, flexWrap: 'wrap', fontSize: 13, marginBottom: 6 }}>
            <span>
              {rows.length} rows — <span style={{ color: STATUS_COLOR.VALID }}>{counts.VALID} ready</span>, <span style={{ color: STATUS_COLOR.DUPLICATE }}>{counts.DUPLICATE} duplicate</span>,{' '}
              <span style={{ color: STATUS_COLOR.INVALID }}>{counts.INVALID} invalid</span> · {selected.size} selected
            </span>
            <span style={{ display: 'flex', gap: 10 }}>
              <button type="button" className="btn-link" onClick={() => setSelected(new Set(rows.map((r) => r.row)))}>
                Select all
              </button>
              <button type="button" className="btn-link" onClick={() => setSelected(new Set())}>
                Deselect all
              </button>
            </span>
          </div>
          <ScrollTable dataAttr="import-preview" columns={previewColumns} rows={rows} rowKey={(r) => String(r.row)} maxRows={8} />
          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 14 }}>
            <button type="button" className="btn-secondary" onClick={onClose}>
              Cancel
            </button>
            <button type="button" className="btn-primary" disabled={busy || selected.size === 0} onClick={runImport}>
              {busy ? 'Importing…' : `Import ${selected.size} ${selected.size === 1 ? 'tire' : 'tires'}`}
            </button>
          </div>
        </div>
      )}

      {result && (
        <div data-import-result={result.status}>
          {result.status === 'SUCCESS' && <Notice tone="success">{`${result.imported} ${result.imported === 1 ? 'tire' : 'tires'} imported to New Stock.`}</Notice>}
          {result.status === 'PARTIAL' && (
            <Notice tone="warning">{`${result.imported} imported to New Stock, ${result.failed.length} not imported — see the rows below.`}</Notice>
          )}
          {result.status === 'FAILED' && (
            <Notice tone="error">{`No tires were imported: all ${result.failed.length} selected rows are duplicates or invalid. Nothing was saved.`}</Notice>
          )}
          {result.failed.length > 0 && <ScrollTable dataAttr="import-failed" columns={failedColumns} rows={result.failed} rowKey={(r) => String(r.row)} maxRows={5} />}
          <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 14 }}>
            <button type="button" className="btn-primary" onClick={onClose}>
              Close
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

/** A parsed date is formatted; an unparseable value (flagged invalid) is shown as entered. */
function showDate(value: string | null): string {
  if (!value) return '—';
  return /^\d{4}-\d{2}-\d{2}$/.test(value) ? formatDate(value) : value;
}

/** Pre-upload check: .xlsx name, size, and the zip signature every .xlsx starts with. */
async function checkXlsx(file: File): Promise<string | null> {
  if (!/\.xlsx$/i.test(file.name)) return 'Only Excel files (.xlsx) can be imported.';
  if (file.size === 0) return 'The file is empty.';
  if (file.size > MAX_BYTES) return 'The file may not be larger than 5 MB.';
  const head = new Uint8Array(await file.slice(0, 4).arrayBuffer());
  if (head[0] !== 0x50 || head[1] !== 0x4b || head[2] !== 0x03 || head[3] !== 0x04) return 'The file is not a valid Excel (.xlsx) workbook.';
  return null;
}
