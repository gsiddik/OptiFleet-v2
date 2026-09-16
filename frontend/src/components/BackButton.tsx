import { useBackNavigation } from '../navigation/useBackNavigation';

export function BackButton({ fallbackTo, label = '← Back' }: { fallbackTo: string; label?: string }) {
  const goBack = useBackNavigation(fallbackTo);

  return (
    <button type="button" className="btn-secondary" onClick={goBack} style={{ marginBottom: 14 }} aria-label="Go back">
      {label}
    </button>
  );
}
