import type { ReactNode, UIEvent } from 'react';

export interface ScrollColumn<T> {
  header: string;
  cell: (row: T) => ReactNode;
}

const ROW_HEIGHT = 37;

/**
 * Compact table that shows at most `maxRows` rows and scrolls internally beyond that, with a sticky
 * header. `onReachEnd` lets the parent fetch the next server page when the user scrolls to the end.
 * Shared by the Wheels Configuration nested vehicles, Tire Detail inventory and import results.
 */
export function ScrollTable<T>({
  columns,
  rows,
  rowKey,
  maxRows = 5,
  emptyLabel = 'No records.',
  onReachEnd,
  dataAttr,
}: {
  columns: ScrollColumn<T>[];
  rows: T[];
  rowKey: (row: T) => string;
  maxRows?: number;
  emptyLabel?: string;
  onReachEnd?: () => void;
  /** value of the container's data-scroll-table attribute (tests, styling hooks) */
  dataAttr?: string;
}) {
  const th = { textAlign: 'left' as const, padding: '8px 10px', fontSize: 12, color: '#374151', background: '#f9fafb', borderBottom: '1px solid #e5e7eb', whiteSpace: 'nowrap' as const, position: 'sticky' as const, top: 0, zIndex: 1 };
  const onScroll = (e: UIEvent<HTMLDivElement>) => {
    const el = e.currentTarget;
    if (onReachEnd && el.scrollTop + el.clientHeight >= el.scrollHeight - ROW_HEIGHT) onReachEnd();
  };
  return (
    <div data-scroll-table={dataAttr} onScroll={onScroll} style={{ maxHeight: ROW_HEIGHT * (maxRows + 1) + 2, overflow: 'auto', border: '1px solid #e5e7eb', borderRadius: 6, background: '#fff' }}>
      <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
        <thead>
          <tr>
            {columns.map((c) => (
              <th key={c.header} style={th}>
                {c.header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.length === 0 ? (
            <tr>
              <td colSpan={columns.length} style={{ padding: 10, color: '#6b7280' }}>
                {emptyLabel}
              </td>
            </tr>
          ) : (
            rows.map((r) => (
              <tr key={rowKey(r)} data-row-key={rowKey(r)} style={{ height: ROW_HEIGHT }}>
                {columns.map((c) => (
                  <td key={c.header} style={{ padding: '6px 10px', borderBottom: '1px solid #f3f4f6', whiteSpace: 'nowrap' }}>
                    {c.cell(r)}
                  </td>
                ))}
              </tr>
            ))
          )}
        </tbody>
      </table>
    </div>
  );
}
