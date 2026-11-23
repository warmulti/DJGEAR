/**
 * Progressive enhancement for WooCommerce Shop & Product Category controls.
 *
 * Strictly presentation UX:
 * 1. Auto-submits sorting <select> on change (works without JS via <noscript> button).
 * 2. Strips empty min_price / max_price fields before GET submission to keep URLs clean.
 */
export function initShopControls(root: Document = document): () => void {
  const sortSelects = Array.from(root.querySelectorAll<HTMLSelectElement>('[data-djg-sort-select]'));
  const filterForms = Array.from(root.querySelectorAll<HTMLFormElement>('[data-djg-filter-form]'));

  const handleSortChange = (event: Event): void => {
    const select = event.currentTarget;
    if (select instanceof HTMLSelectElement && select.form) {
      select.form.submit();
    }
  };

  const handleFilterSubmit = (event: Event): void => {
    const form = event.currentTarget;
    if (!(form instanceof HTMLFormElement)) {
      return;
    }

    const numericInputs = form.querySelectorAll<HTMLInputElement>('input[name="min_price"], input[name="max_price"]');
    numericInputs.forEach((input) => {
      if (input.value.trim() === '') {
        input.disabled = true;
      }
    });
  };

  sortSelects.forEach((select) => select.addEventListener('change', handleSortChange));
  filterForms.forEach((form) => form.addEventListener('submit', handleFilterSubmit));

  return () => {
    sortSelects.forEach((select) => select.removeEventListener('change', handleSortChange));
    filterForms.forEach((form) => form.removeEventListener('submit', handleFilterSubmit));
  };
}
