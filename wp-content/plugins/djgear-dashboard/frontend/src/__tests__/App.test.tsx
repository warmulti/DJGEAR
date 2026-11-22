import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { App } from '../App';
import type { DjgearApi } from '../api/djgearApi';
import type { DashboardBootConfig } from '../types/api';

function makeBootConfig(overrides?: Partial<DashboardBootConfig>): DashboardBootConfig {
  return {
    version: '0.1.0',
    restUrl: 'http://localhost:8080/wp-json/',
    nonce: 'test-nonce',
    adminUrl: 'http://localhost:8080/wp-admin/',
    siteTitle: 'DJGear Local',
    environment: 'development',
    i18n: {
      locale: 'en_US',
      direction: 'ltr',
      is_rtl: false,
      text_domain: 'djgear-core',
      supported_locales: ['fa_IR', 'en_US'],
    },
    currentUser: {
      id: 1,
      displayName: 'Shop Manager',
      email: 'shop@djgear.local',
      roles: ['shop_manager'],
      capabilities: {
        djgear_view_dashboard: true,
        djgear_view_logs: true,
        djgear_manage_core: false,
        djgear_manage_modules: false,
        djgear_manage_integrations: false,
        edit_products: true,
        edit_shop_orders: true,
        list_users: true,
        manage_woocommerce: true,
      },
    },
    ...overrides,
  };
}

function makeMockApi(): DjgearApi {
  return {
    getHealth: vi.fn().mockResolvedValue({
      success: true,
      data: {
        status: 'healthy',
        core: { version: '0.1.0', environment: 'development', php_version: '8.3.37', wp_version: '7.1.5' },
        woocommerce: {
          active: true,
          version: '11.3.1',
          hpos_compatible: true,
          hpos_tables_enabled: false,
          source_of_truth_policy: 'wordpress_woocommerce_only',
        },
        i18n: {
          locale: 'en_US',
          direction: 'ltr',
          is_rtl: false,
          text_domain: 'djgear-core',
          supported_locales: ['fa_IR', 'en_US'],
        },
        providers: { email: [], sms: [], payment: [] },
        object_cache: { external: true },
      },
    }),
    getOverview: vi.fn().mockResolvedValue({
      success: true,
      data: {
        commerce: {
          woocommerce_active: true,
          currency: 'IRR',
          products_total: 4,
          products_published: 3,
          orders_total: 2,
          orders_processing: 1,
          orders_completed: 1,
          customers_total: 5,
          users_total: 6,
        },
        modules: { registered_count: 1, booted_ids: ['djgear-dashboard'] },
        recent_audit: { total: 1, items: [] },
      },
    }),
    getModules: vi.fn(),
    getAuditLogs: vi.fn().mockResolvedValue({ success: true, data: { items: [] }, meta: { total: 0 } }),
    getProducts: vi.fn().mockResolvedValue({ success: true, data: { items: [], currency: 'IRR' }, meta: { total: 0 } }),
    getOrders: vi.fn().mockResolvedValue({ success: true, data: { items: [] }, meta: { total: 0 } }),
    getCustomers: vi.fn().mockResolvedValue({ success: true, data: { items: [] }, meta: { total: 0 } }),
    getSettings: vi.fn(),
    updateSettings: vi.fn(),
    switchUserLocale: vi.fn().mockResolvedValue({
      success: true,
      data: {
        i18n: {
          locale: 'fa_IR',
          direction: 'rtl',
          is_rtl: true,
          text_domain: 'djgear-core',
          supported_locales: ['fa_IR', 'en_US'],
        },
      },
    }),
  } as unknown as DjgearApi;
}

describe('DJGear Dashboard App', () => {
  it('loads overview health, switches to Persian RTL, and enforces permission-denied for shop_manager on Settings', async () => {
    const user = userEvent.setup();
    const api = makeMockApi();

    render(<App config={makeBootConfig()} api={api} />);

    await waitFor(() => {
      expect(screen.getByText('Platform Overview & System Health')).toBeInTheDocument();
    });
    expect(screen.getByText('11.3.1')).toBeInTheDocument();
    expect(screen.getByTestId('djgear-shell')).toHaveAttribute('dir', 'ltr');

    // Navigate to Settings as shop_manager -> must display Permission Denied without leaking settings
    await user.click(screen.getByRole('button', { name: 'DJGear Settings' }));
    expect(screen.getByText('Permission Denied')).toBeInTheDocument();
    expect(screen.getByText(/djgear_manage_core/)).toBeInTheDocument();

    // Navigate to SMS Center -> must clearly display Planned — Not Yet Implemented
    await user.click(screen.getByRole('button', { name: /SMS Center/i }));
    await waitFor(() => {
      expect(screen.getByText('Planned — Not Yet Implemented')).toBeInTheDocument();
    });

    // Switch language to Persian RTL
    await user.click(screen.getByTestId('language-switcher'));
    await waitFor(() => {
      expect(screen.getByTestId('djgear-shell')).toHaveAttribute('dir', 'rtl');
    });
    expect(api.switchUserLocale).toHaveBeenCalledWith('fa_IR');
  });
});
