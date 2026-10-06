import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useAuth } from '../auth/AuthContext';
import { SUPPORTED_LOCALES, type AppLocale } from '../i18n/locale';

/**
 * Language selector (English / Bahasa Indonesia). The UI switches at once; when signed in the choice is
 * saved as the user's preferred locale, so it follows them across reloads and sign-ins. Language names
 * are shown in their own language.
 */
export function LanguageSelector({ compact = false }: { compact?: boolean }) {
  const { t, i18n } = useTranslation();
  const { setLanguage } = useAuth();
  const [error, setError] = useState<string | null>(null);

  async function choose(locale: AppLocale) {
    setError(null);
    try {
      await setLanguage(locale);
    } catch {
      setError(t('common.language.saveFailed'));
    }
  }

  return (
    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }} data-language-selector>
      {!compact && <label htmlFor="app-language" style={{ fontSize: 13, color: '#6b7280' }}>{t('common.language.label')}</label>}
      <select
        id="app-language"
        aria-label={t('common.language.label')}
        value={i18n.language}
        onChange={(e) => void choose(e.target.value as AppLocale)}
        style={{ fontSize: 13, padding: '4px 6px', borderRadius: 6, border: '1px solid #d1d5db' }}
      >
        {SUPPORTED_LOCALES.map((locale) => (
          <option key={locale} value={locale} lang={locale}>
            {t(`common.language.${locale}`)}
          </option>
        ))}
      </select>
      {error && <span role="alert" style={{ fontSize: 12, color: '#b91c1c' }}>{error}</span>}
    </span>
  );
}
