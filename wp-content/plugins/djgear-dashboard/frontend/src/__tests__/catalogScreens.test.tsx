import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { App } from '../App';
import type { DjgearApi } from '../api/djgearApi';
import type { DashboardBootConfig } from '../types/api';

function makeConfig(role: 'administrator' | 'shop_manager'): DashboardBootConfig {
  const isAdmin = role === 'administrator';
  return {
    version: '0.1.0',
    restUrl: 'http://localhost:8080/wp-json/',
    nonce: 'nonce',
    adminUrl: 'http://localhost:8080/wp-admin/',
    siteTitle: 'DJGear',
    environment: 'development',
    i18n: {
      locale: 'en_US',
      direction: 'ltr',
      is_rtl: false,
      text_domain: 'djgear-core',
      supported_locales: ['fa_IR', 'en_US'],
    },
    currentUser: {
      id: isAdmin ? 1 : 2,
      displayName: role,
      email: `${role}@djgear.local`,
      roles: [role],
      capabilities: {
        djgear_view_dashboard: true,
        djgear_view_logs: true,
        djgear_manage_core: isAdmin,
        djgear_manage_modules: isAdmin,
        djgear_manage_integrations: isAdmin,
        djgear_view_catalog: true,
        djgear_manage_catalog: true,
        djgear_manage_skin_definitions: isAdmin,
        djgear_import_export_catalog: isAdmin,
      },
    },
  };
}

function makeMockApi(): DjgearApi {
  return {
    listCatalogDevices: vi.fn().mockResolvedValue({
      success: true,
      data: {
        items: [
          {
            id: 10,
            slug: 'pioneer-dj-ddj-flx4',
            brand_term_id: 1,
            category_term_id: 2,
            brand: { id: 1, slug: 'pioneer-dj', name_en: 'Pioneer DJ', name_fa: 'پایونیر دی‌جی', logo_attachment_id: null, logo_url: null, country_code: 'JP', website_url: '', device_count: 1 },
            category: { id: 2, slug: 'controllers', parent_id: 0, name_en: 'Controllers', name_fa: 'کنترلرها', icon_key: '', sort_order: 0, device_count: 1 },
            model_code: 'DDJ-FLX4',
            canonical_key: 'pioneer-dj::ddjflx4',
            mpn: null,
            name_en: 'Pioneer DJ DDJ-FLX4',
            name_fa: 'کنترلر پایونیر DDJ-FLX4',
            summary_en: '',
            summary_fa: '',
            release_year: 2022,
            lifecycle_status: 'active',
            skin_ready: true,
            primary_wc_product_id: null,
            hero_image_url: null,
            updated_at: '2026-11-22 12:00:00',
          },
        ],
      },
      meta: { total: 1, page: 1, per_page: 10 },
    }),
    listCatalogBrands: vi.fn().mockResolvedValue({ success: true, data: { items: [] } }),
    listCatalogCategories: vi.fn().mockResolvedValue({ success: true, data: { items: [] } }),
    listCatalogSkins: vi.fn().mockResolvedValue({ success: true, data: { items: [] } }),
    switchUserLocale: vi.fn().mockResolvedValue({
      success: true,
      data: { i18n: { locale: 'fa_IR', direction: 'rtl', is_rtl: true, text_domain: 'djgear-core', supported_locales: ['fa_IR', 'en_US'] } },
    }),
  } as unknown as DjgearApi;
}

describe('Phase 3 Device Catalog Dashboard Screens', () => {
  it('allows shop_manager to view devices but blocks Skin Definitions and Import/Export', async () => {
    const user = userEvent.setup();
    render(<App config={makeConfig('shop_manager')} api={makeMockApi()} initialRoute="catalog-devices" />);

    await waitFor(() => {
      expect(screen.getByText('Pioneer DJ DDJ-FLX4')).toBeInTheDocument();
    });
    expect(screen.getByText('Skin Ready')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Skin Definitions' }));
    expect(screen.getByText('Permission Denied')).toBeInTheDocument();
    expect(screen.getByText(/djgear_manage_skin_definitions/)).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Catalog Import / Export' }));
    expect(screen.getByText('Permission Denied')).toBeInTheDocument();
    expect(screen.getByText(/djgear_import_export_catalog/)).toBeInTheDocument();
  });

  it('allows administrator to access Skin Definitions and switches to Persian RTL', async () => {
    const user = userEvent.setup();
    render(<App config={makeConfig('administrator')} api={makeMockApi()} initialRoute="catalog-skins" />);

    await waitFor(() => {
      expect(screen.getByText('Compatible Skin Engineering Definitions')).toBeInTheDocument();
    });

    await user.click(screen.getByTestId('language-switcher'));
    await waitFor(() => {
      expect(screen.getByText('تعاریف مهندسی اسکین‌های سازگار')).toBeInTheDocument();
    });
    expect(screen.getByTestId('djgear-shell')).toHaveAttribute('dir', 'rtl');
  });
});
