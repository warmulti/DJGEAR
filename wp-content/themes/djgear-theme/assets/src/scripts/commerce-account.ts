import '../styles/12-commerce-account.css';

import { initCommerceAccountControls } from './commerce-account-controls';

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => {
    initCommerceAccountControls(document);
  });
} else {
  initCommerceAccountControls(document);
}
