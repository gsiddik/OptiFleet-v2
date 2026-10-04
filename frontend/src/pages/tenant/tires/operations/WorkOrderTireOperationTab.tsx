import { useNavigate } from 'react-router-dom';
import { FormField, inputStyle } from '../../../../components/FormField';
import { StatusBadge } from '../../../../components/StatusBadge';
import { PositionLabel } from '../../../../components/tires/PositionLabel';
import { useAuth } from '../../../../auth/AuthContext';
import { WheelConfigurationPreview } from '../wheel-configuration/WheelConfigurationPreview';
import { bodyStyleFor, type VehicleType } from '../wheel-configuration/vehicleTypes';
import { ReplacementArrow, RotationArrows, TireOperationCard } from './TireOperationParts';
import { INSPECTION_COLOR, REPLACEMENT_COLOR, ROTATION_PALETTE, formatKm } from './tireOperationFormat';
import { OPERATION_TYPE_LABEL, type TireOperationDetail } from './tireOperationTypes';

const TYPE_PERMISSION = { REPLACEMENT: 'tire.install', ROTATION: 'tire.rotate', INSPECTION: 'tire.inspect' } as const;

/**
 * Work Order → Tire Operations: the same information as Add New Tire Operations, read-only (inputs
 * disabled, preview not interactive). Edit opens the Edit Tire Operation page when the backend
 * still allows it.
 */
export function WorkOrderTireOperationTab({ operation }: { operation: TireOperationDetail }) {
  const navigate = useNavigate();
  const { hasPermission } = useAuth();
  const pairs = new Map<number, TireOperationDetail['items']>();
  operation.items.forEach((i) => pairs.set(i.pair_number ?? 0, [...(pairs.get(i.pair_number ?? 0) ?? []), i]));
  const pairColor = (n: number) => ROTATION_PALETTE[(n - 1) % ROTATION_PALETTE.length];
  const colors: Record<string, string> = {};
  operation.items.forEach((i) => {
    colors[i.position_code] = operation.operation_type === 'ROTATION' ? pairColor(i.pair_number ?? 1) : operation.operation_type === 'REPLACEMENT' ? REPLACEMENT_COLOR : INSPECTION_COLOR;
  });
  const configuration = operation.configuration;
  const readonly = { ...inputStyle, background: '#f3f4f6' };
  const canEdit = operation.can_edit && hasPermission('work_order.update') && hasPermission(TYPE_PERMISSION[operation.operation_type]);

  return (
    <div data-wo-tire-operation={operation.id}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 10, flexWrap: 'wrap', marginBottom: 12 }}>
        <span style={{ fontSize: 13, display: 'inline-flex', gap: 8, alignItems: 'center' }}>
          Tire Operations Status <StatusBadge status={operation.status} />
        </span>
        {canEdit && (
          <button type="button" className="btn-primary" onClick={() => navigate(`/app/tire-operations/${operation.id}/edit`)}>
            Edit
          </button>
        )}
      </div>
      <div className="split-layout">
        <div style={{ display: 'grid', gap: 16, minWidth: 0 }}>
          <section className="card">
            <h3 style={{ marginTop: 0, fontSize: 15 }}>Tire Operations Info</h3>
            <fieldset disabled style={{ border: 'none', padding: 0, margin: 0 }}>
              <FormField label="Registration">
                <input aria-label="Registration" value={operation.vehicle.registration_number ?? ''} readOnly style={readonly} />
              </FormField>
              <FormField label="Config Code">
                <input aria-label="Config Code" value={operation.config_code} readOnly style={{ ...readonly, fontFamily: 'monospace', fontWeight: 700 }} />
              </FormField>
              <FormField label="Tire Operations">
                <input aria-label="Tire Operations" value={OPERATION_TYPE_LABEL[operation.operation_type]} readOnly style={readonly} />
              </FormField>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: '0 12px' }}>
                <FormField label="Tire Operations Date">
                  <input aria-label="Tire Operations Date" type="date" value={operation.operated_date} readOnly style={readonly} />
                </FormField>
                <FormField label="Tire Operations Time">
                  <input aria-label="Tire Operations Time" value={operation.operated_time} readOnly style={readonly} />
                </FormField>
              </div>
              <FormField label="KM at Tire Operations">
                <input aria-label="KM at Tire Operations" value={formatKm(operation.odometer)} readOnly style={readonly} />
              </FormField>
            </fieldset>
            {operation.cancellation_reason && <p style={{ fontSize: 13, color: '#6b7280', marginBottom: 0 }}>Cancellation reason: {operation.cancellation_reason}</p>}
          </section>
          <section className="card">
            <h3 style={{ marginTop: 0, fontSize: 15 }}>{OPERATION_TYPE_LABEL[operation.operation_type]}</h3>
            <div style={{ display: 'grid', gap: 18 }}>
              {operation.operation_type === 'ROTATION'
                ? [...pairs.entries()].map(([n, [a, b]]) => (
                    <div key={n} data-rotation-pair={n}>
                      <strong style={{ fontSize: 13, color: pairColor(n) }}>
                        Pair {n}: <PositionLabel code={a.position_code} /> ↔ <PositionLabel code={b?.position_code} />
                      </strong>
                      <TireOperationCard title="To be Rotated" code={a.position_code} tire={a.tire} accent={pairColor(n)} />
                      <RotationArrows color={pairColor(n)} />
                      {b && <TireOperationCard title="Rotating With" code={b.position_code} tire={b.tire} accent={pairColor(n)} />}
                    </div>
                  ))
                : operation.items.map((i) =>
                    operation.operation_type === 'INSPECTION' ? (
                      <TireOperationCard key={i.id} title="Installed Tire" code={i.position_code} tire={i.tire} accent={INSPECTION_COLOR}>
                        <FormField label="Tread Depth (mm)">
                          <input aria-label={`Tread Depth ${i.position_code}`} value={i.tread_depth_mm ?? '—'} readOnly disabled style={readonly} />
                        </FormField>
                      </TireOperationCard>
                    ) : (
                      <div key={i.id}>
                        <TireOperationCard title="Installed Tire" code={i.position_code} tire={i.tire} accent={REPLACEMENT_COLOR} />
                        <ReplacementArrow />
                        <section data-replacing-with={i.position_code} style={{ border: `1px dashed ${REPLACEMENT_COLOR}`, borderRadius: 12, padding: '10px 14px' }}>
                          <h4 style={{ margin: '0 0 8px', fontSize: 13, color: REPLACEMENT_COLOR, textTransform: 'uppercase' }}>Replacing With</h4>
                          <FormField label="Serial Number">
                            <input aria-label={`Serial Number ${i.position_code}`} value={i.replacement_tire?.serial_number ?? ''} readOnly disabled style={{ ...readonly, fontFamily: 'monospace' }} />
                          </FormField>
                          {i.applied_at && <span style={{ fontSize: 12, color: '#15803d' }}>Installed</span>}
                        </section>
                      </div>
                    ),
                  )}
            </div>
          </section>
        </div>
        <section className="card" style={{ position: 'sticky', top: 12 }}>
          <h3 style={{ marginTop: 0, fontSize: 15 }}>Vehicle Preview</h3>
          {configuration && (
            <WheelConfigurationPreview
              readOnly
              bodyStyle={bodyStyleFor(configuration.vehicle_type as VehicleType, configuration.truck_configuration_type)}
              input={{ front: configuration.front_axles, rear: configuration.rear_axles, spareTires: configuration.spare_tires }}
              positionColors={colors}
            />
          )}
        </section>
      </div>
    </div>
  );
}
