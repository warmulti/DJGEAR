import '../styles/10-shop.css';

import { initShopControls } from './shop-controls';

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => {
    initShopControls(document);
  });
} else {
  initShopControls(document);
}
