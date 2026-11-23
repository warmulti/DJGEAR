import { describe, expect, it, beforeEach } from 'vitest';
import { initProductControls } from '../product-controls';

describe('DJGear Single Product Controls (Gallery, Lightbox, ARIA Tabs, Quantity Stepper)', () => {
  beforeEach(() => {
    document.documentElement.dir = 'ltr';
    document.body.className = '';
    document.body.innerHTML = `
      <div data-djg-gallery>
        <img data-djg-gallery-main src="/img-1.jpg" data-full-src="/img-1-full.jpg" alt="View 1" />
        <button id="zoom-btn" data-djg-lightbox-open>Zoom</button>
        <button id="thumb-1" class="is-active" aria-selected="true" data-djg-gallery-thumb data-main-src="/img-1.jpg" data-full-src="/img-1-full.jpg" data-alt="View 1"></button>
        <button id="thumb-2" aria-selected="false" data-djg-gallery-thumb data-main-src="/img-2.jpg" data-full-src="/img-2-full.jpg" data-alt="View 2"></button>
        <div id="lightbox" data-djg-lightbox hidden>
          <button id="lightbox-close" data-djg-lightbox-close>Close</button>
          <img id="lightbox-img" data-djg-lightbox-img src="/img-1-full.jpg" alt="View 1" />
        </div>
      </div>

      <a id="jump-reviews" href="#tab-reviews" data-djg-open-tab="reviews">Reviews</a>

      <div class="djg-product-summary__purchase">
        <div class="quantity">
          <input id="qty-input" class="qty" type="number" min="1" max="3" step="1" value="1" />
        </div>
      </div>

      <section data-djg-tabs>
        <button id="t-desc" class="is-active" aria-selected="true" tabindex="0" data-djg-tab-trigger="description">Description</button>
        <button id="t-rev" aria-selected="false" tabindex="-1" data-djg-tab-trigger="reviews">Reviews</button>
        <div id="p-desc" data-djg-tab-panel="description">Desc body</div>
        <div id="p-rev" data-djg-tab-panel="reviews" hidden>Rev body</div>
      </section>
    `;
  });

  it('switches main gallery image on thumbnail click and expands/closes lightbox with Escape', () => {
    const teardown = initProductControls(document);
    const mainImg = document.querySelector('[data-djg-gallery-main]') as HTMLImageElement;
    const thumb2 = document.getElementById('thumb-2') as HTMLButtonElement;
    const zoomBtn = document.getElementById('zoom-btn') as HTMLButtonElement;
    const lightbox = document.getElementById('lightbox') as HTMLElement;
    const lightboxImg = document.getElementById('lightbox-img') as HTMLImageElement;

    thumb2.click();
    expect(mainImg.src).toContain('/img-2.jpg');
    expect(thumb2.getAttribute('aria-selected')).toBe('true');

    zoomBtn.click();
    expect(lightbox.hasAttribute('hidden')).toBe(false);
    expect(lightboxImg.src).toContain('/img-2-full.jpg');
    expect(document.body.classList.contains('djg-scroll-locked')).toBe(true);

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    expect(lightbox.hasAttribute('hidden')).toBe(true);
    expect(document.body.classList.contains('djg-scroll-locked')).toBe(false);
    expect(document.activeElement?.id).toBe('zoom-btn');

    teardown();
  });

  it('switches ARIA tabs via keyboard arrows and rating jump link', () => {
    const teardown = initProductControls(document);
    const descTab = document.getElementById('t-desc') as HTMLButtonElement;
    const revTab = document.getElementById('t-rev') as HTMLButtonElement;
    const descPanel = document.getElementById('p-desc') as HTMLElement;
    const revPanel = document.getElementById('p-rev') as HTMLElement;

    descTab.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight' }));
    expect(revTab.getAttribute('aria-selected')).toBe('true');
    expect(revPanel.hasAttribute('hidden')).toBe(false);
    expect(descPanel.hasAttribute('hidden')).toBe(true);

    descTab.click();
    expect(descPanel.hasAttribute('hidden')).toBe(false);

    (document.getElementById('jump-reviews') as HTMLAnchorElement).click();
    expect(revPanel.hasAttribute('hidden')).toBe(false);

    teardown();
  });

  it('adds quantity stepper buttons respecting min and max bounds', () => {
    const teardown = initProductControls(document);
    const input = document.getElementById('qty-input') as HTMLInputElement;
    const buttons = document.querySelectorAll<HTMLButtonElement>('.djg-qty-btn');

    expect(buttons.length).toBe(2);
    const [decBtn, incBtn] = Array.from(buttons);

    decBtn.click();
    expect(input.value).toBe('1'); // clamped at min=1

    incBtn.click();
    incBtn.click();
    incBtn.click();
    expect(input.value).toBe('3'); // clamped at max=3

    teardown();
  });
});
