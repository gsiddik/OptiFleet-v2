import { useState } from 'react';
import { Toolbar } from '../../../../components/Toolbar';
import type { TireActivityType } from '../../../../types';
import { TireActivityTable } from './TireWorkflowTabs';
import { labelText, t } from '../../../../i18n/i18n';

const FILTERS: { value: TireActivityType | ''; label: string; labelKey?: string }[] = [
  { value: '', label: 'All', labelKey: 'common.actions.all' },
  { value: 'INSTALLATION', label: 'Installation', labelKey: 'tire.filters.installation' },
  { value: 'ROTATION', label: 'Rotation', labelKey: 'tire.filters.rotation' },
  { value: 'INSPECTION', label: 'Inspection', labelKey: 'tire.filters.inspection' },
  { value: 'REMOVAL', label: 'Removal', labelKey: 'tire.filters.removal' },
  { value: 'RETREAD', label: 'Retread', labelKey: 'tire.filters.retread' },
  { value: 'REPAIR', label: 'Repair', labelKey: 'inventory.fields.repair' },
  { value: 'SCRAP', label: 'Scrapped', labelKey: 'tire.filters.scrapped' },
];

/** Tire History: every recorded tire event, newest first, filterable by event and serial / vehicle / product. */
export function TireHistoryPage() {
  const [type, setType] = useState<TireActivityType | ''>('');
  const [search, setSearch] = useState('');
  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>{t('tire.titles.tireHistory')}</h1>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {FILTERS.map((f) => (
          <button key={labelText(f)} onClick={() => setType(f.value)} className={type === f.value ? 'btn-primary' : 'btn-secondary'} style={{ padding: '4px 10px', fontSize: 12 }}>
            {labelText(f)}
          </button>
        ))}
      </div>
      <Toolbar search={search} onSearchChange={setSearch} />
      <div className="card">
        <TireActivityTable key={`${type}|${search}`} types={type ? [type] : []} search={search} />
      </div>
    </div>
  );
}
