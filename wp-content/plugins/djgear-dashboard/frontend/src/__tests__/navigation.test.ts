import { describe, expect, it } from 'vitest';
import { getRouteById, hasRoutePermission, ROUTES } from '../navigation/routes';

describe('Dashboard Route Permissions', () => {
  it('includes all required modular sections', () => {
    const ids = ROUTES.map((r) => r.id);
    expect(ids).toEqual(
      expect.arrayContaining([
        'overview',
        'products',
        'orders',
        'customers',
        'sms',
        'email',
        'forms',
        'seo',
        'analytics',
        'payments',
        'backup',
        'modules',
        'settings',
      ])
    );
  });

  it('denies shop_manager from sensitive settings, modules, and payment management', () => {
    const shopManagerCaps: Record<string, boolean> = {
      djgear_view_dashboard: true,
      djgear_view_logs: true,
      djgear_manage_core: false,
      djgear_manage_modules: false,
      djgear_manage_integrations: false,
      edit_products: true,
      edit_shop_orders: true,
      list_users: true,
      manage_woocommerce: true,
    };

    expect(hasRoutePermission(getRouteById('overview'), shopManagerCaps)).toBe(true);
    expect(hasRoutePermission(getRouteById('products'), shopManagerCaps)).toBe(true);
    expect(hasRoutePermission(getRouteById('orders'), shopManagerCaps)).toBe(true);
    expect(hasRoutePermission(getRouteById('backup'), shopManagerCaps)).toBe(true);

    expect(hasRoutePermission(getRouteById('settings'), shopManagerCaps)).toBe(false);
    expect(hasRoutePermission(getRouteById('modules'), shopManagerCaps)).toBe(false);
    expect(hasRoutePermission(getRouteById('payments'), shopManagerCaps)).toBe(false);
  });
});
