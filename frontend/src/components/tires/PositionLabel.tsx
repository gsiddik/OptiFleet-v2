import { describePositionCode, parsePositionCode } from '../../utils/tirePosition';

/**
 * A tire position: the code in monospace with its plain-language description.
 *   inline  → 1FL1 (Front Left, Axle 1, Pos. 1)
 *   stacked → 1FL1
 *             Front Left, Axle 1, Pos. 1
 */
export function PositionLabel({ code, variant = 'inline' }: { code: string | null | undefined; variant?: 'inline' | 'stacked' }) {
  if (!code) return <>—</>;
  const parsed = parsePositionCode(code);
  const description = describePositionCode(code);
  if (parsed.kind === 'LEGACY') return <span data-position-code={code}>{description}</span>;
  if (variant === 'stacked') {
    return (
      <span data-position-code={parsed.code} style={{ display: 'inline-flex', flexDirection: 'column', lineHeight: 1.3 }}>
        <span style={{ fontFamily: 'monospace', fontWeight: 700 }}>{parsed.code}</span>
        <span style={{ fontSize: 12, color: '#6b7280' }}>{description}</span>
      </span>
    );
  }
  return (
    <span data-position-code={parsed.code}>
      <span style={{ fontFamily: 'monospace', fontWeight: 600 }}>{parsed.code}</span> <span style={{ color: '#6b7280' }}>({description})</span>
    </span>
  );
}
