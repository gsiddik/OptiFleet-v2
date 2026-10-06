import { useBackNavigation } from '../navigation/useBackNavigation';
import { t } from '../i18n/i18n';

export function BackButton({ fallbackTo, label = '← Back' }: { fallbackTo: string; label?: string }) {
  const goBack = useBackNavigation(fallbackTo);

  return (
    <button type="button" className="btn-secondary" onClick={goBack} style={{ marginBottom: 14 }} aria-label={t('common.actions.goBack')}>
      {label}
    </button>
  );
}
