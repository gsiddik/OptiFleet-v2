import { useEffect, useState } from 'react';
import { apiClient, extractApiError } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { ConfigurationSetManager } from './ConfigurationSetManager';
import type { ConfigurationSetItem } from '../../../types';

const DOCUMENT_TYPES = [
  'work_order', 'maintenance_report', 'inspection_report', 'vehicle_transfer', 'stock_transfer',
  'purchase_request', 'purchase_order', 'goods_receipt', 'warranty_claim', 'invoice',
];

export function DocumentTemplateConfigPage() {
  const [selectedType, setSelectedType] = useState('work_order');
  const [variables, setVariables] = useState<{ scalars: string[]; sections: Record<string, string[]> } | null>(null);
  const [preview, setPreview] = useState<{ code: string; html: string } | null>(null);

  useEffect(() => {
    apiClient.get('/app/configuration/metadata', { params: { type: 'TEMPLATE', code: selectedType } }).then((res) => setVariables(res.data.data.variables));
  }, [selectedType]);

  async function handlePreview(set: ConfigurationSetItem, payload: Record<string, unknown>) {
    try {
      const res = await apiClient.post('/app/configuration/preview', { type: 'TEMPLATE', code: set.code, html: (payload as { html?: string }).html ?? '' });
      setPreview({ code: set.code, html: res.data.data.html });
    } catch (err) {
      alert(extractApiError(err).message);
    }
  }

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 4 }}>Document Templates</h1>
      <p style={{ color: '#6b7280', fontSize: 13, marginBottom: 16 }}>
        Configure the printable HTML template for each document type. Payload is <code>{'{"html": "..."}'}</code>, using{' '}
        <code>{'{{variable}}'}</code> substitution and <code>{'{{#section}}...{{/section}}'}</code> repeating sections — only variables
        listed below are allowed; publish is rejected if the template references anything else.
      </p>

      <FormField label="Preview variables for document type">
        <select value={selectedType} onChange={(e) => setSelectedType(e.target.value)} style={{ ...inputStyle, maxWidth: 280 }}>
          {DOCUMENT_TYPES.map((t) => (
            <option key={t} value={t}>
              {t}
            </option>
          ))}
        </select>
      </FormField>
      {variables && (
        <div style={{ marginBottom: 20, fontSize: 12, color: '#6b7280', background: '#f9fafb', padding: 10, borderRadius: 6 }}>
          <div>
            <strong>Variables:</strong>{' '}
            {variables.scalars.map((v) => (
              <code key={v} style={{ background: '#fff', border: '1px solid #e5e7eb', padding: '1px 5px', borderRadius: 4, marginRight: 4, marginBottom: 4, display: 'inline-block' }}>
                {`{{${v}}}`}
              </code>
            ))}
          </div>
          {Object.entries(variables.sections).map(([section, fields]) => (
            <div key={section} style={{ marginTop: 6 }}>
              <strong>{`{{#${section}}}...{{/${section}}}`}</strong> fields:{' '}
              {fields.map((f) => (
                <code key={f} style={{ background: '#fff', border: '1px solid #e5e7eb', padding: '1px 5px', borderRadius: 4, marginRight: 4 }}>
                  {`{{${f}}}`}
                </code>
              ))}
            </div>
          ))}
        </div>
      )}

      <ConfigurationSetManager
        type="TEMPLATE"
        manageParm="document_template.manage"
        publishPermission="document_template.publish"
        codeLabel="Document Type"
        codePlaceholder="e.g. work_order, purchase_order"
        payloadHelp={<div style={{ marginBottom: 6, fontSize: 12, color: '#6b7280' }}>Payload shape: {'{"html": "<div>...</div>"}'}</div>}
        onPreview={handlePreview}
      />

      {preview && (
        <Modal open title={`Preview — ${preview.code}`} onClose={() => setPreview(null)} width={700}>
          <div style={{ border: '1px solid #e5e7eb', borderRadius: 6, padding: 16, maxHeight: '60vh', overflow: 'auto' }} dangerouslySetInnerHTML={{ __html: preview.html }} />
        </Modal>
      )}
    </div>
  );
}
