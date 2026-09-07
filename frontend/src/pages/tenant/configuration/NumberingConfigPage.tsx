import { useEffect, useState } from 'react';
import { apiClient, extractApiError } from '../../../api/client';
import { Modal } from '../../../components/Modal';
import { ConfigurationSetManager } from './ConfigurationSetManager';
import type { ConfigurationSetItem } from '../../../types';

export function NumberingConfigPage() {
  const [tokens, setTokens] = useState<string[]>([]);
  const [preview, setPreview] = useState<{ set: ConfigurationSetItem; text: string } | null>(null);

  useEffect(() => {
    apiClient.get('/app/configuration/metadata', { params: { type: 'NUMBERING' } }).then((res) => setTokens(res.data.data.tokens));
  }, []);

  async function handlePreview(set: ConfigurationSetItem, payload: Record<string, unknown>) {
    try {
      const res = await apiClient.post('/app/configuration/preview', { type: 'NUMBERING', code: set.code, payload });
      setPreview({ set, text: res.data.data.preview });
    } catch (err) {
      alert(extractApiError(err).message);
    }
  }

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 4 }}>Document Numbering</h1>
      <p style={{ color: '#6b7280', fontSize: 13, marginBottom: 16 }}>
        Configure the document number format for each document type (Work Order, Purchase Order, Maintenance Request, etc). Publishing a
        new version keeps the running sequence — republishing a format tweak does not restart numbering.
      </p>
      <ConfigurationSetManager
        type="NUMBERING"
        manageParm="numbering.manage"
        publishPermission="numbering.publish"
        codeLabel="Document Type"
        codePlaceholder="e.g. work_order, purchase_order"
        payloadHelp={
          <div style={{ marginBottom: 6, fontSize: 12, color: '#6b7280' }}>
            Tokens:{' '}
            {tokens.map((t) => (
              <code key={t} style={{ background: '#f3f4f6', padding: '1px 5px', borderRadius: 4, marginRight: 4 }}>
                {t}
              </code>
            ))}
            <br />
            Example payload: <code>{'{"format":"{DOC}/{BRANCH}/{YYYY}/{MM}/{SEQ:6}","doc_code":"WO","reset_rule":"YEARLY"}'}</code>
          </div>
        }
        onPreview={handlePreview}
      />

      {preview && (
        <Modal open title={`Preview — ${preview.set.code}`} onClose={() => setPreview(null)}>
          <p style={{ color: '#6b7280', fontSize: 13 }}>Sample number this format would produce (does not consume the real sequence):</p>
          <div style={{ fontFamily: 'ui-monospace, monospace', fontSize: 18, padding: 12, background: '#f9fafb', borderRadius: 6, textAlign: 'center' }}>
            {preview.text}
          </div>
        </Modal>
      )}
    </div>
  );
}
