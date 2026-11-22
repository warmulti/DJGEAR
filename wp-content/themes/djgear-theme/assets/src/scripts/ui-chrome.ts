import { getFocusableElements, trapFocusKeydown } from './focus-trap';

export interface ActiveDialogState {
  element: HTMLElement;
  trigger: HTMLElement | null;
}

export function initThemeChrome(root: Document = document): () => void {
  let activeDialog: ActiveDialogState | null = null;
  const header = root.querySelector<HTMLElement>('[data-djg-header]');

  const closeActiveDialog = (): void => {
    if (!activeDialog) {
      return;
    }

    const { element, trigger } = activeDialog;
    element.setAttribute('hidden', '');
    root.body.classList.remove('djg-scroll-locked');

    if (trigger) {
      trigger.setAttribute('aria-expanded', 'false');
      trigger.focus();
    }

    activeDialog = null;
  };

  const openDialog = (element: HTMLElement, trigger: HTMLElement | null): void => {
    if (activeDialog && activeDialog.element !== element) {
      closeActiveDialog();
    }

    element.removeAttribute('hidden');
    root.body.classList.add('djg-scroll-locked');
    if (trigger) {
      trigger.setAttribute('aria-expanded', 'true');
    }

    activeDialog = { element, trigger };

    const preferredFocus =
      element.querySelector<HTMLElement>('input[type="search"], input:not([type="hidden"])') ??
      getFocusableElements(element)[0];

    preferredFocus?.focus();
  };

  const handleClick = (event: Event): void => {
    const target = event.target;
    if (!(target instanceof Element)) {
      return;
    }

    const drawerTrigger = target.closest<HTMLElement>('[data-djg-drawer-open]');
    if (drawerTrigger) {
      const name = drawerTrigger.getAttribute('data-djg-drawer-open');
      const drawer = name ? root.querySelector<HTMLElement>(`[data-djg-drawer="${name}"]`) : null;
      if (drawer) {
        event.preventDefault();
        openDialog(drawer, drawerTrigger);
      }
      return;
    }

    const overlayTrigger = target.closest<HTMLElement>('[data-djg-overlay-open]');
    if (overlayTrigger) {
      const name = overlayTrigger.getAttribute('data-djg-overlay-open');
      const overlay = name ? root.querySelector<HTMLElement>(`[data-djg-overlay="${name}"]`) : null;
      if (overlay) {
        event.preventDefault();
        openDialog(overlay, overlayTrigger);
      }
      return;
    }

    if (target.closest('[data-djg-drawer-close], [data-djg-overlay-close]')) {
      event.preventDefault();
      closeActiveDialog();
    }
  };

  const handleKeydown = (event: KeyboardEvent): void => {
    if (!activeDialog) {
      return;
    }

    if (event.key === 'Escape') {
      event.preventDefault();
      closeActiveDialog();
      return;
    }

    trapFocusKeydown(event, activeDialog.element);
  };

  const handleScroll = (): void => {
    if (!header) {
      return;
    }
    header.classList.toggle('is-scrolled', window.scrollY > 8);
  };

  root.addEventListener('click', handleClick);
  root.addEventListener('keydown', handleKeydown);
  window.addEventListener('scroll', handleScroll, { passive: true });
  handleScroll();

  return () => {
    root.removeEventListener('click', handleClick);
    root.removeEventListener('keydown', handleKeydown);
    window.removeEventListener('scroll', handleScroll);
  };
}
