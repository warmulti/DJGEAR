import { describe, expect, it, vi, beforeEach } from 'vitest';
import { initShopControls } from '../shop-controls';
import { initThemeChrome } from '../ui-chrome';

describe('DJGear Shop Controls & Mobile Filter Drawer', () => {
  beforeEach(() => {
    document.body.innerHTML = `
      <button id="open-filters" data-djg-drawer-open="shop-filters" aria-expanded="false">Filters</button>
      <form id="sort-form" method="get" action="/shop/">
        <input type="hidden" name="filter_brand[]" value="pioneer" />
        <select id="sort-select" name="orderby" data-djg-sort-select>
          <option value="menu_order">Default</option>
          <option value="price">Price: low to high</option>
        </select>
      </form>
      <div id="filter-drawer" data-djg-drawer="shop-filters" hidden>
        <button id="close-filters" data-djg-drawer-close>Close</button>
        <form id="filter-form" method="get" action="/shop/" data-djg-filter-form="drawer">
          <input id="min-price" name="min_price" type="number" value="" />
          <input id="max-price" name="max_price" type="number" value="2500" />
          <button type="submit">Apply</button>
        </form>
      </div>
    `;
    document.body.className = '';
  });

  it('auto-submits sorting form when orderby changes', () => {
    const teardown = initShopControls(document);
    const form = document.getElementById('sort-form') as HTMLFormElement;
    const select = document.getElementById('sort-select') as HTMLSelectElement;
    const submitSpy = vi.spyOn(form, 'submit').mockImplementation(() => undefined);

    select.value = 'price';
    select.dispatchEvent(new Event('change'));

    expect(submitSpy).toHaveBeenCalledTimes(1);
    teardown();
  });

  it('disables empty price fields on filter submit while keeping populated price values active', () => {
    const teardown = initShopControls(document);
    const form = document.getElementById('filter-form') as HTMLFormElement;
    const minInput = document.getElementById('min-price') as HTMLInputElement;
    const maxInput = document.getElementById('max-price') as HTMLInputElement;

    form.dispatchEvent(new Event('submit', { cancelable: true }));

    expect(minInput.disabled).toBe(true);
    expect(maxInput.disabled).toBe(false);
    teardown();
  });

  it('opens mobile filter drawer with scroll lock and restores focus on Escape', () => {
    const teardown = initThemeChrome(document);
    const trigger = document.getElementById('open-filters') as HTMLButtonElement;
    const drawer = document.getElementById('filter-drawer') as HTMLElement;

    trigger.click();
    expect(drawer.hasAttribute('hidden')).toBe(false);
    expect(trigger.getAttribute('aria-expanded')).toBe('true');
    expect(document.body.classList.contains('djg-scroll-locked')).toBe(true);

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    expect(drawer.hasAttribute('hidden')).toBe(true);
    expect(trigger.getAttribute('aria-expanded')).toBe('false');
    expect(document.body.classList.contains('djg-scroll-locked')).toBe(false);
    expect(document.activeElement?.id).toBe('open-filters');

    teardown();
  });
});
