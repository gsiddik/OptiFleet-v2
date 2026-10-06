import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import './index.css';
import './i18n/setup';
import { I18nextProvider } from 'react-i18next';
import { changeLocale, i18n } from './i18n/i18n';
import { browserLocale, cachedLocale } from './i18n/locale';
import App from './App.tsx';

// The last resolved language (or the browser's) is loaded before the first render — no language flash.
// After sign-in the user / tenant preference is applied (AuthContext).
void changeLocale(cachedLocale() ?? browserLocale()).finally(() => createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <I18nextProvider i18n={i18n}>
      <App />
    </I18nextProvider>
  </StrictMode>,
));
