import '@fontsource-variable/inter';
import '@fontsource-variable/vazirmatn';

import '../styles/00-tokens.css';
import '../styles/01-reset.css';
import '../styles/02-base.css';
import '../styles/03-layout.css';
import '../styles/04-components.css';
import '../styles/05-header-footer.css';

import { initThemeChrome } from './ui-chrome';

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => {
    initThemeChrome(document);
  });
} else {
  initThemeChrome(document);
}
