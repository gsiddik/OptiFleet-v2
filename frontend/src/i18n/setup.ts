/** Application i18n bootstrap: English up front, Indonesian on demand. */
import { initI18n, registerLocaleLoader } from './i18n';
import { englishResources, loadIndonesianResources } from './resources';

initI18n(englishResources, { warnMissing: import.meta.env.DEV });
registerLocaleLoader('id', loadIndonesianResources);
