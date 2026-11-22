import { describe, expect, it, beforeEach } from 'vitest';
import { initThemeChrome } from '../ui-chrome';

describe('DJGear Theme UI Chrome (Drawer, Search Overlay, Focus Management)', () => {
  beforeEach(() => {
    document.body.innerHTML = `
      <header class="djg-header" data-djg-header>
        <button id="open-nav" data-djg-drawer-open="mobile-nav" aria-expanded="false">Menu</button>
        <button id="open-search" data-djg-overlay-open="search" aria-expanded="false">Search</button>
      </header>
      <div id="mobile-drawer" data-djg-drawer="mobile-nav" hidden>
        <div class="backdrop" data-djg-drawer-close></div>
        <button id="close-nav" data-djg-drawer-close>Close</button>
        <a id="first-link" href="/shop/">Shop</a>
      </div>
      <div id="search-overlay" data-djg-overlay="search" hidden>
        <button id="close-search" data-djg-overlay-close>Close</button>
        <input id="search-input" type="search" />
      </div>
    `;
    document.body.className = '';
  });

  it('opens mobile drawer, locks body scroll, updates aria-expanded, and restores focus on Escape', () => {
    const teardown = initThemeChrome(document);
    const openBtn = document.getElementById('open-nav') as HTMLButtonElement;
    const drawer = document.getElementById('mobile-drawer') as HTMLElement;

    openBtn.click();

    expect(drawer.hasAttribute('hidden')).toBe(false);
    expect(openBtn.getAttribute('aria-expanded')).toBe('true');
    expect(document.body.classList.contains('djg-scroll-locked')).toBe(true);
    expect(document.activeElement?.id).toBe('close-nav');

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));

    expect(drawer.hasAttribute('hidden')).toBe(true);
    expect(openBtn.getAttribute('aria-expanded')).toBe('false');
    expect(document.body.classList.contains('djg-scroll-locked')).toBe(false);
    expect(document.activeElement?.id).toBe('open-nav');

    teardown();
  });

  it('opens search overlay and focuses the search input directly, closing on close button click', () => {
    const teardown = initThemeChrome(document);
    const openSearch = document.getElementById('open-search') as HTMLButtonElement;
    const overlay = document.getElementById('search-overlay') as HTMLElement;
    const closeSearch = document.getElementById('close-search') as HTMLButtonElement;

    openSearch.click();

    expect(overlay.hasAttribute('hidden')).toBe(false);
    expect(document.activeElement?.id).toBe('search-input');

    closeSearch.click();

    expect(overlay.hasAttribute('hidden')).toBe(true);
    expect(document.activeElement?.id).toBe('open-search');

    teardown();
  });
});
