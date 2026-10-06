// Unit tests run TypeScript sources directly with Node's type stripping. Source files import siblings
// without an extension (bundler resolution); this hook retries such relative imports with `.ts`.
import { register } from 'node:module';

register('./resolve-ts.mjs', import.meta.url);
