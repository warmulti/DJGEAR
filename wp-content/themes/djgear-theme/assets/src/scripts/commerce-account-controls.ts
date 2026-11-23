/**
 * Lightweight accessibility & mobile UX polish for Cart, Checkout, and My Account.
 *
 * Strictly presentation only — never touches WooCommerce cart/checkout state,
 * payment gateways, shipping calculations, nonces, or auth submissions.
 */
export function initCommerceAccountControls(root: Document = document): () => void {
  // 1. Ensure classic WooCommerce notices expose explicit ARIA live roles for screen readers
  const errorNotices = root.querySelectorAll<HTMLElement>('.woocommerce-error');
  errorNotices.forEach((el) => {
    if (!el.hasAttribute('role')) {
      el.setAttribute('role', 'alert');
    }
  });

  const statusNotices = root.querySelectorAll<HTMLElement>('.woocommerce-message, .woocommerce-info');
  statusNotices.forEach((el) => {
    if (!el.hasAttribute('role')) {
      el.setAttribute('role', 'status');
      el.setAttribute('aria-live', 'polite');
    }
  });

  // 2. Keep active My Account tab visible inside horizontal mobile nav strip
  const activeNavItem = root.querySelector<HTMLElement>('.woocommerce-MyAccount-navigation li.is-active');
  if (activeNavItem && typeof activeNavItem.scrollIntoView === 'function') {
    activeNavItem.scrollIntoView({ behavior: 'auto', block: 'nearest', inline: 'nearest' });
  }

  return () => {
    // Stateless DOM enhancement; nothing to tear down
  };
}
