import type { CSSProperties } from 'react';

/**
 * OptiFleet wordmark (512x188 source, transparent PNG). object-fit: contain
 * keeps the aspect ratio intact at any sidebar width/height — never
 * stretched, never cropped to fill both dimensions at once.
 */
export function Logo({ height = 36, style }: { height?: number; style?: CSSProperties }) {
  return (
    <img
      src="/logo-optifleet.png"
      alt="OptiFleet"
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
