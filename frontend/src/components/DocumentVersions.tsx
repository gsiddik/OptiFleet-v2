import { useEffect, useState } from 'react';
import { apiClient, extractApiError } from '../api/client';
import { Modal } from './Modal';
import { formatDateTime } from '../utils/date';
import { t } from '../i18n/i18n';

/** One generated printed document: its language and template version are fixed when it is generated. */
interface DocumentGeneration {
  id: string;
  sequence: number;
  locale: 'en' | 'id';
  template_version: number | null;
  generated_at: string;
}

/** A language's own name (endonym), shown the same in every UI language. */
const languageLabel = (locale: string): string => (locale === 'en' || locale === 'id' ? t(`common.language.${locale}`) : locale);

/** Opens one generation of a printed document (a reprint: its own language and template version). */
async function openDocumentGeneration(printPath: string, generationId?: string): Promise<void> {
  const res = await apiClient.get(printPath, { params: generationId ? { generation: generationId } : undefined, responseType: 'blob' });
  window.open(URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' })), '_blank');
}

/**
 * "Versions" for a printed document (`printPath` is its GET …/print endpoint). Print always reprints
 * the latest generation unchanged; this lists every generation and adds a new one in a chosen
 * language from the current template. Older generations are never changed.
 */
export function DocumentVersionsButton({ printPath, disabled }: { printPath: string; disabled?: boolean }) {
  const [open, setOpen] = useState(false);
  return (
    <>
      <button className="btn-secondary" disabled={disabled} onClick={() => setOpen(true)} data-document-versions={printPath}>
        {t('documents.versions.button')}
      </button>
      {open && <DocumentVersionsModal printPath={printPath} onClose={() => setOpen(false)} />}
    </>
  );
}

function DocumentVersionsModal({ printPath, onClose }: { printPath: string; onClose: () => void }) {
  const [rows, setRows] = useState<DocumentGeneration[] | null>(null);
  const [locale, setLocale] = useState<'' | DocumentGeneration['locale']>('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  function load() {
    apiClient
      .get<{ data: DocumentGeneration[] }>(`${printPath}/generations`)
      .then((res) => setRows(res.data.data))
      .catch((err) => setError(extractApiError(err).message));
  }
  useEffect(load, [printPath]);

  async function run(task: () => Promise<void>) {
    setBusy(true);
    setError(null);
    try {
      await task();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  const generate = () =>
    run(async () => {
      const res = await apiClient.post<{ data: DocumentGeneration }>(`${printPath}/generations`, locale ? { locale } : {});
      load();
      await openDocumentGeneration(printPath, res.data.data.id);
    });

  return (
    <Modal open title={t('documents.versions.title')} onClose={onClose} width={560}>
      {error && <div style={{ color: '#b91c1c', fontSize: 13, marginBottom: 8 }}>{error}</div>}
      <div style={{ display: 'flex', gap: 8, alignItems: 'flex-end', marginBottom: 16 }}>
        <label style={{ fontSize: 13, flex: 1 }}>
          {t('common.language.label')}
          <select value={locale} onChange={(e) => setLocale(e.target.value as typeof locale)} style={{ display: 'block', width: '100%', marginTop: 4 }}>
            <option value="">{t('common.language.default')}</option>
            <option value="en">{languageLabel('en')}</option>
            <option value="id">{languageLabel('id')}</option>
          </select>
        </label>
        <button className="btn-primary" disabled={busy} onClick={generate} data-generate-version>
          {t('documents.versions.generateNew')}
        </button>
      </div>
      {rows === null ? (
        <div style={{ fontSize: 13, color: '#6b7280' }}>{t('common.actions.loading')}</div>
      ) : rows.length === 0 ? (
        <div style={{ fontSize: 13, color: '#6b7280' }}>{t('documents.versions.notPrinted')}</div>
      ) : (
        <table style={{ width: '100%', fontSize: 13 }} data-document-generations>
          <thead>
            <tr>
              <th style={{ textAlign: 'left' }}>{t('configuration.fields.version')}</th>
              <th style={{ textAlign: 'left' }}>{t('common.language.label')}</th>
              <th style={{ textAlign: 'left' }}>{t('configuration.labels.templateVersion')}</th>
              <th style={{ textAlign: 'left' }}>{t('documents.versions.generated')}</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {rows.map((g) => (
              <tr key={g.id} data-generation={g.id}>
                <td>{g.sequence}</td>
                <td>{languageLabel(g.locale)}</td>
                <td>{g.template_version ?? t('common.language.default')}</td>
                <td>{formatDateTime(g.generated_at)}</td>
                <td style={{ textAlign: 'right' }}>
                  <button className="btn-secondary" disabled={busy} onClick={() => run(() => openDocumentGeneration(printPath, g.id))}>
                    {t('documents.versions.open')}
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </Modal>
  );
}
