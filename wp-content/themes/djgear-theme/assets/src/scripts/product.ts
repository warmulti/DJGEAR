import '../styles/11-single-product.css';

import { initProductControls } from './product-controls';

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => {
    initProductControls(document);
  });
} else {
  initProductControls(document);
}
