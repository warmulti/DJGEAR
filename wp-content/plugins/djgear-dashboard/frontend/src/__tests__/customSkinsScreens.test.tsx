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
        djgear_import_export_catalog: isAdmin,
        djgear_view_skin_orders: true,
        djgear_manage_skin_orders: true,
        djgear_manage_skin_catalog: true,
        djgear_manage_skin_plans: isAdmin,
      },
    },
  };
}

function makeMockApi(): DjgearApi {
  return {
    listSkinVariants: vi.fn().mockResolvedValue({
      success: true,
      data: {
        items: [
          {
            id: 1,
            device_id: 10,
            wc_product_id: 55,
            wc_variation_id: null,
            wc_product_name: 'DDJ-FLX4 Skin',
            model_code: 'stealth-carbon',
            model_name_fa: 'کربن استلت',
            model_name_en: 'Stealth Carbon',
            color_code: 'matte-black',
            color_name_fa: 'مشکی مات',
            color_name_en: 'Matte Black',
            color_hex: '#111827',
            finish: 'carbon',
            preview_attachment_id: null,
            preview_image_url: null,
            print_width_mm: 482,
            print_height_mm: 272,
            status: 'active',
            sort_order: 1,
            base_price: 1500000,
            currency: 'IRT',
            in_stock: true,
            updated_at: '2026-11-22 12:00:00',
          },
        ],
      },
    }),
    listSkinPlans: vi.fn().mockResolvedValue({
      success: true,
      data: {
        items: [
          {
            id: 1,
            slug: 'standard',
            name_fa: 'پلن استاندارد',
            name_en: 'Standard Plan',
            description_fa: '',
            description_en: '',
            plan_type: 'standard',
            allows_artwork_upload: false,
            requires_artwork: false,
            requires_proof_approval: false,
            max_revisions: 0,
            price_surcharge: 0,
            estimated_lead_days: 2,
            features_fa: [],
            features_en: [],
            is_active: true,
            sort_order: 10,
            updated_at: '2026-11-22 12:00:00',
          },
          {
            id: 2,
            slug: 'custom-design',
            name_fa: 'پلن طراحی اختصاصی',
            name_en: 'Custom Design Plan',
            description_fa: '',
            description_en: '',
            plan_type: 'custom_design',
            allows_artwork_upload: true,
            requires_artwork: true,
            requires_proof_approval: true,
            max_revisions: 3,
            price_surcharge: 250000,
            estimated_lead_days: 4,
            features_fa: [],
            features_en: [],
            is_active: true,
            sort_order: 20,
            updated_at: '2026-11-22 12:00:00',
          },
        ],
      },
    }),
    listSkinJobs: vi.fn().mockResolvedValue({
      success: true,
      data: {
        items: [
          {
            id: 101,
            job_uuid: '11111111-2222-4333-8444-555555555555',
            customer_user_id: 9,
            customer_name: 'DJ Arash',
            wc_order_id: 901,
            wc_order_status: 'processing',
            wc_order_admin_url: null,
            device_id: 10,
            skin_variant_id: 1,
            plan_id: 2,
            snapshot: {
              device: { name_en: 'Pioneer DJ DDJ-FLX4', name_fa: 'پایونیر DDJ-FLX4' },
              variant: { model_name_en: 'Stealth Carbon', model_name_fa: 'کربن استلت', color_name_en: 'Matte Black', color_name_fa: 'مشکی مات' },
              plan: { slug: 'custom-design', name_en: 'Custom Design Plan', name_fa: 'پلن طراحی اختصاصی', plan_type: 'custom_design' },
            },
            status: 'submitted_for_review',
            allowed_next: ['in_design_review', 'on_hold', 'cancelled'],
            current_artwork_id: 5,
            current_proof_id: null,
            approved_proof_id: null,
            approved_at: null,
            customer_notes: null,
            internal_notes: null,
            tracking_code: null,
            created_at: '2026-11-22 12:00:00',
            updated_at: '2026-11-22 12:00:00',
          },
        ],
        counts: { submitted_for_review: 1 },
      },
    }),
    switchUserLocale: vi.fn().mockResolvedValue({
      success: true,
      data: { i18n: { locale: 'fa_IR', direction: 'rtl', is_rtl: true, text_domain: 'djgear-core', supported_locales: ['fa_IR', 'en_US'] } },
    }),
  } as unknown as DjgearApi;
}

describe('Phase 4 Commercial Custom Skins Dashboard Screens', () => {
  it('allows shop_manager to manage Skin Models & Colors and Skin Orders, but blocks Ordering Plans', async () => {
    const user = userEvent.setup();
    render(<App config={makeConfig('shop_manager')} api={makeMockApi()} initialRoute="skins-variants" />);

    await waitFor(() => {
      expect(screen.getByText('Stealth Carbon')).toBeInTheDocument();
    });

    await user.click(screen.getByRole('button', { name: 'Skin Orders & Production Queue' }));
    await waitFor(() => {
      expect(screen.getByText('Pioneer DJ DDJ-FLX4')).toBeInTheDocument();
    });
    expect(screen.getAllByText('submitted_for_review').length).toBeGreaterThanOrEqual(2);

    await user.click(screen.getByRole('button', { name: 'Ordering Plans' }));
    expect(screen.getByText('Permission Denied')).toBeInTheDocument();
    expect(screen.getByText(/djgear_manage_skin_plans/)).toBeInTheDocument();
  });

  it('allows administrator to manage Ordering Plans and switches cleanly to Persian RTL', async () => {
    const user = userEvent.setup();
    render(<App config={makeConfig('administrator')} api={makeMockApi()} initialRoute="skins-plans" />);

    await waitFor(() => {
      expect(screen.getByText('Standard Plan')).toBeInTheDocument();
      expect(screen.getByText('Custom Design Plan')).toBeInTheDocument();
    });
    expect(screen.getByText('No Upload')).toBeInTheDocument();
    expect(screen.getByText('Upload Enabled')).toBeInTheDocument();

    await user.click(screen.getByTestId('language-switcher'));
    await waitFor(() => {
      expect(screen.getByText('پلن‌های سفارش اسکین')).toBeInTheDocument();
      expect(screen.getByText('پلن استاندارد')).toBeInTheDocument();
      expect(screen.getByText('پلن طراحی اختصاصی')).toBeInTheDocument();
    });
    expect(screen.getByTestId('djgear-shell')).toHaveAttribute('dir', 'rtl');
  });
});
