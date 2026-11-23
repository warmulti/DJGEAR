import { describe, expect, it, vi, beforeEach } from 'vitest';
import { initCommerceAccountControls } from '../commerce-account-controls';

describe('DJGear Commerce & Account UX Controls', () => {
  beforeEach(() => {
    document.body.innerHTML = `
      <ul class="woocommerce-error"><li>Billing email is required.</li></ul>
      <div class="woocommerce-message">Cart updated.</div>
      <nav class="woocommerce-MyAccount-navigation">
        <ul>
          <li><a href="/my-account/">Dashboard</a></li>
          <li class="is-active"><a href="/my-account/custom-skins/">Custom Skin Orders</a></li>
        </ul>
      </nav>
    `;
  });

  it('adds accessible ARIA alert/status roles to WooCommerce notices and keeps active mobile nav item in view', () => {
    const activeItem = document.querySelector('.woocommerce-MyAccount-navigation li.is-active') as HTMLElement;
    const scrollSpy = vi.fn();
    activeItem.scrollIntoView = scrollSpy;

    const teardown = initCommerceAccountControls(document);

    expect(document.querySelector('.woocommerce-error')?.getAttribute('role')).toBe('alert');
    expect(document.querySelector('.woocommerce-message')?.getAttribute('role')).toBe('status');
    expect(document.querySelector('.woocommerce-message')?.getAttribute('aria-live')).toBe('polite');
    expect(scrollSpy).toHaveBeenCalledTimes(1);

    teardown();
  });
});
