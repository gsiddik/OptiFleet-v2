import type { CSSProperties } from 'react';
import { t } from '../i18n/i18n';

/**
 * OptiFleet wordmark (512x188 source, transparent PNG). object-fit: contain
 * keeps the aspect ratio intact at any sidebar width/height — never
 * stretched, never cropped to fill both dimensions at once.
 *
 * `src` lets a tenant's own uploaded logo (Company Profile) replace the
 * wordmark in the sidebar without touching the default asset used
 * everywhere else (login screen, tenants with no logo uploaded yet).
 */
export function Logo({ height = 36, style, src = '/logo-optifleet.png' }: { height?: number; style?: CSSProperties; src?: string }) {
  return (
    <img
      src={src}
      alt={t('common.tooltips.optiFleet')}
      style={{
        height,
        width: 'auto',
        maxWidth: '100%',
        objectFit: 'contain',
        display: 'block',
        ...style,
      }}
    />
  );
}
