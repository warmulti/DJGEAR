import { getFocusableElements, trapFocusKeydown } from './focus-trap';

/**
 * Progressive enhancement for the WooCommerce Single Product page:
 * 1. Accessible thumbnail gallery switching (click + ArrowLeft/ArrowRight).
 * 2. Accessible lightbox dialog (Escape close, focus trap, focus restoration, scroll lock).
 * 3. Accessible ARIA tablist switching (click, Arrow/Home/End keys, `#tab-reviews` jump link).
 * 4. Native quantity stepper buttons respecting min/max/step attributes.
 */
export function initProductControls(root: Document = document): () => void {
  const cleanups: Array<() => void> = [];
  const isRtl = root.documentElement.dir === 'rtl';

  // 1. Gallery thumbnail switcher & lightbox
  const gallery = root.querySelector<HTMLElement>('[data-djg-gallery]');
  if (gallery) {
    const mainImg = gallery.querySelector<HTMLImageElement>('[data-djg-gallery-main]');
    const thumbs = Array.from(gallery.querySelectorAll<HTMLButtonElement>('[data-djg-gallery-thumb]'));
    const lightbox = gallery.querySelector<HTMLElement>('[data-djg-lightbox]');
    const lightboxImg = gallery.querySelector<HTMLImageElement>('[data-djg-lightbox-img]');
    const lightboxOpenBtn = gallery.querySelector<HTMLButtonElement>('[data-djg-lightbox-open]');

    const activateThumb = (thumb: HTMLButtonElement): void => {
      if (!mainImg) {
        return;
      }
      const nextSrc = thumb.getAttribute('data-main-src') ?? '';
      const nextSrcset = thumb.getAttribute('data-main-srcset') ?? '';
      const nextFull = thumb.getAttribute('data-full-src') ?? nextSrc;
      const nextAlt = thumb.getAttribute('data-alt') ?? '';

      if (nextSrc !== '') {
        mainImg.src = nextSrc;
      }
      if (nextSrcset !== '') {
        mainImg.setAttribute('srcset', nextSrcset);
      } else {
        mainImg.removeAttribute('srcset');
      }
      mainImg.setAttribute('data-full-src', nextFull);
      if (nextAlt !== '') {
        mainImg.alt = nextAlt;
      }

      thumbs.forEach((btn) => {
        const active = btn === thumb;
        btn.classList.toggle('is-active', active);
        btn.setAttribute('aria-selected', active ? 'true' : 'false');
      });
    };

    thumbs.forEach((thumb, index) => {
      const onClick = (): void => activateThumb(thumb);
      const onKeydown = (event: KeyboardEvent): void => {
        const nextKey = isRtl ? 'ArrowLeft' : 'ArrowRight';
        const prevKey = isRtl ? 'ArrowRight' : 'ArrowLeft';
        let targetIdx: number | null = null;
        if (event.key === nextKey) {
          targetIdx = (index + 1) % thumbs.length;
        } else if (event.key === prevKey) {
          targetIdx = (index - 1 + thumbs.length) % thumbs.length;
        }
        if (targetIdx !== null) {
          event.preventDefault();
          const target = thumbs[targetIdx];
          target.focus();
          activateThumb(target);
        }
      };

      thumb.addEventListener('click', onClick);
      thumb.addEventListener('keydown', onKeydown);
      cleanups.push(() => {
        thumb.removeEventListener('click', onClick);
        thumb.removeEventListener('keydown', onKeydown);
      });
    });

    if (lightbox && lightboxOpenBtn) {
      let opener: HTMLElement | null = null;

      const closeLightbox = (): void => {
        if (lightbox.hasAttribute('hidden')) {
          return;
        }
        lightbox.setAttribute('hidden', '');
        root.body.classList.remove('djg-scroll-locked');
        opener?.focus();
        opener = null;
      };

      const openLightbox = (): void => {
        if (lightboxImg && mainImg) {
          lightboxImg.src = mainImg.getAttribute('data-full-src') || mainImg.currentSrc || mainImg.src;
          lightboxImg.alt = mainImg.alt;
        }
        opener = lightboxOpenBtn;
        lightbox.removeAttribute('hidden');
        root.body.classList.add('djg-scroll-locked');
        getFocusableElements(lightbox)[0]?.focus();
      };

      const onLightboxClick = (event: Event): void => {
        if (event.target instanceof Element && event.target.closest('[data-djg-lightbox-close]')) {
          event.preventDefault();
          closeLightbox();
        }
      };

      const onLightboxKeydown = (event: KeyboardEvent): void => {
        if (lightbox.hasAttribute('hidden')) {
          return;
        }
        if (event.key === 'Escape') {
          event.preventDefault();
          closeLightbox();
          return;
        }
        trapFocusKeydown(event, lightbox);
      };

      lightboxOpenBtn.addEventListener('click', openLightbox);
      lightbox.addEventListener('click', onLightboxClick);
      root.addEventListener('keydown', onLightboxKeydown);
      cleanups.push(() => {
        lightboxOpenBtn.removeEventListener('click', openLightbox);
        lightbox.removeEventListener('click', onLightboxClick);
        root.removeEventListener('keydown', onLightboxKeydown);
      });
    }
  }

  // 2. Accessible ARIA Tabs
  const tabRoot = root.querySelector<HTMLElement>('[data-djg-tabs]');
  if (tabRoot) {
    const triggers = Array.from(tabRoot.querySelectorAll<HTMLButtonElement>('[data-djg-tab-trigger]'));
    const panels = Array.from(tabRoot.querySelectorAll<HTMLElement>('[data-djg-tab-panel]'));

    const activateTab = (key: string, focusTrigger = false): void => {
      triggers.forEach((btn) => {
        const active = btn.getAttribute('data-djg-tab-trigger') === key;
        btn.classList.toggle('is-active', active);
        btn.setAttribute('aria-selected', active ? 'true' : 'false');
        btn.setAttribute('tabindex', active ? '0' : '-1');
        if (active && focusTrigger) {
          btn.focus();
        }
      });

      panels.forEach((panel) => {
        const active = panel.getAttribute('data-djg-tab-panel') === key;
        if (active) {
          panel.removeAttribute('hidden');
        } else {
          panel.setAttribute('hidden', '');
        }
      });
    };

    triggers.forEach((btn, idx) => {
      const onClick = (): void => {
        const key = btn.getAttribute('data-djg-tab-trigger');
        if (key) {
          activateTab(key);
        }
      };

      const onKeydown = (event: KeyboardEvent): void => {
        const nextKey = isRtl ? 'ArrowLeft' : 'ArrowRight';
        const prevKey = isRtl ? 'ArrowRight' : 'ArrowLeft';
        let targetIdx: number | null = null;

        if (event.key === nextKey) {
          targetIdx = (idx + 1) % triggers.length;
        } else if (event.key === prevKey) {
          targetIdx = (idx - 1 + triggers.length) % triggers.length;
        } else if (event.key === 'Home') {
          targetIdx = 0;
        } else if (event.key === 'End') {
          targetIdx = triggers.length - 1;
        }

        if (targetIdx !== null) {
          event.preventDefault();
          const key = triggers[targetIdx].getAttribute('data-djg-tab-trigger');
          if (key) {
            activateTab(key, true);
          }
        }
      };

      btn.addEventListener('click', onClick);
      btn.addEventListener('keydown', onKeydown);
      cleanups.push(() => {
        btn.removeEventListener('click', onClick);
        btn.removeEventListener('keydown', onKeydown);
      });
    });

    // Rating link (`#tab-reviews`) activates Reviews tab cleanly
    const jumpLinks = Array.from(root.querySelectorAll<HTMLElement>('[data-djg-open-tab]'));
    jumpLinks.forEach((link) => {
      const onJump = (): void => {
        const key = link.getAttribute('data-djg-open-tab');
        if (key) {
          activateTab(key);
        }
      };
      link.addEventListener('click', onJump);
      cleanups.push(() => link.removeEventListener('click', onJump));
    });

    if (window.location.hash.startsWith('#tab-')) {
      const hashKey = window.location.hash.slice('#tab-'.length);
      if (triggers.some((t) => t.getAttribute('data-djg-tab-trigger') === hashKey)) {
        activateTab(hashKey);
      }
    }
  }

  // 3. Progressive Quantity Stepper (- / +) respecting native WooCommerce min/max/step
  const qtyContainers = Array.from(root.querySelectorAll<HTMLElement>('.djg-product-summary__purchase .quantity'));
  qtyContainers.forEach((container) => {
    const input = container.querySelector<HTMLInputElement>('input.qty');
    if (!input || input.type === 'hidden' || container.hasAttribute('data-djg-qty-ready')) {
      return;
    }
    container.setAttribute('data-djg-qty-ready', '1');

    const decBtn = root.createElement('button');
    decBtn.type = 'button';
    decBtn.className = 'djg-qty-btn';
    decBtn.setAttribute('aria-label', 'Decrease quantity');
    decBtn.textContent = '−';

    const incBtn = root.createElement('button');
    incBtn.type = 'button';
    incBtn.className = 'djg-qty-btn';
    incBtn.setAttribute('aria-label', 'Increase quantity');
    incBtn.textContent = '+';

    const stepQty = (delta: number): void => {
      const step = Number.parseFloat(input.step) || 1;
      const min = input.min !== '' ? Number.parseFloat(input.min) : 0;
      const max = input.max !== '' ? Number.parseFloat(input.max) : Number.POSITIVE_INFINITY;
      const current = Number.parseFloat(input.value) || min;
      const next = Math.min(max, Math.max(min, current + delta * step));
      input.value = String(next);
      input.dispatchEvent(new Event('change', { bubbles: true }));
    };

    const onDec = (): void => stepQty(-1);
    const onInc = (): void => stepQty(1);

    decBtn.addEventListener('click', onDec);
    incBtn.addEventListener('click', onInc);
    container.insertBefore(decBtn, input);
    container.appendChild(incBtn);

    cleanups.push(() => {
      decBtn.removeEventListener('click', onDec);
      incBtn.removeEventListener('click', onInc);
    });
  });

  return () => {
    cleanups.forEach((fn) => fn());
  };
}
