import { useState } from 'react';
import { Toolbar } from '../../../../components/Toolbar';
import type { TireActivityType } from '../../../../types';
import { TireActivityTable } from './TireWorkflowTabs';

const FILTERS: { value: TireActivityType | ''; label: string }[] = [
  { value: '', label: 'All' },
  { value: 'INSTALLATION', label: 'Installation' },
  { value: 'ROTATION', label: 'Rotation' },
  { value: 'INSPECTION', label: 'Inspection' },
  { value: 'REMOVAL', label: 'Removal' },
  { value: 'RETREAD', label: 'Retread' },
  { value: 'REPAIR', label: 'Repair' },
  { value: 'SCRAP', label: 'Scrapped' },
];

/** Tire History: every recorded tire event, newest first, filterable by event and serial / vehicle / product. */
export function TireHistoryPage() {
  const [type, setType] = useState<TireActivityType | ''>('');
  const [search, setSearch] = useState('');
  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Tire History</h1>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {FILTERS.map((f) => (
          <button key={f.label} onClick={() => setType(f.value)} className={type === f.value ? 'btn-primary' : 'btn-secondary'} style={{ padding: '4px 10px', fontSize: 12 }}>
            {f.label}
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
