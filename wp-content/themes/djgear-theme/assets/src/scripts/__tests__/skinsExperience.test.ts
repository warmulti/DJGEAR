import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { initSkinsConfigurator } from '../skins-configurator';
import { initSkinsPortal } from '../skins-portal';
import type { CustomerSkinJob, PublicDeviceItem, SkinsOptionsResponse } from '../skins-types';

const SAMPLE_DEVICES: PublicDeviceItem[] = [
  {
    id: 101,
    slug: 'pioneer-ddj-flx4',
    model_code: 'DDJ-FLX4',
    name_fa: 'پاییونیر DDJ-FLX4',
    name_en: 'Pioneer DJ DDJ-FLX4',
    brand: { name_fa: 'پاییونیر', name_en: 'Pioneer DJ' },
    category: { name_fa: 'کنترلر', name_en: 'DJ Controllers' },
  },
];

const SAMPLE_OPTIONS: SkinsOptionsResponse = {
  device: {
    device_id: 101,
    slug: 'pioneer-ddj-flx4',
    model_code: 'DDJ-FLX4',
    name_fa: 'پاییونیر DDJ-FLX4',
    name_en: 'Pioneer DJ DDJ-FLX4',
  },
  variants: [
    {
      id: 201,
      device_id: 101,
      wc_product_id: 301,
      wc_variation_id: null,
      model_code: 'classic',
      model_name_fa: 'کلاسیک',
      model_name_en: 'Classic',
      color_code: 'matte-black',
      color_name_fa: 'مشکی مات',
      color_name_en: 'Matte Black',
      color_hex: '#111111',
      finish: 'matte',
      preview_image_url: null,
      print_width_mm: 482,
      print_height_mm: 272,
      base_price: 2500000,
      currency: 'IRR',
      in_stock: true,
      sort_order: 1,
    },
    {
      id: 202,
      device_id: 101,
      wc_product_id: 302,
      wc_variation_id: null,
      model_code: 'limited',
      model_name_fa: 'محدود',
      model_name_en: 'Limited',
      color_code: 'chrome',
      color_name_fa: 'کروم',
      color_name_en: 'Chrome',
      color_hex: '#cccccc',
      finish: 'gloss',
      preview_image_url: null,
      print_width_mm: 482,
      print_height_mm: 272,
      base_price: 2900000,
      currency: 'IRR',
      in_stock: false,
      sort_order: 2,
    },
  ],
  plans: [
    {
      id: 1,
      slug: 'standard',
      name_fa: 'طرح استاندارد',
      name_en: 'Standard Skin',
      description_fa: 'رنگ و فینیش آماده',
      description_en: 'Ready-made studio finish',
      plan_type: 'standard',
      allows_artwork_upload: false,
      requires_artwork: false,
      requires_proof_approval: false,
      max_revisions: 0,
      price_surcharge: 0,
      estimated_lead_days: 3,
      features_fa: ['برش دقیق'],
      features_en: ['Precision cut'],
      sort_order: 1,
    },
    {
      id: 2,
      slug: 'custom-design',
      name_fa: 'طراحی اختصاصی',
      name_en: 'Custom Design',
      description_fa: 'بارگذاری فایل و تایید پیش‌نمایش طراح',
      description_en: 'Upload custom artwork with designer proof approval',
      plan_type: 'custom_design',
      allows_artwork_upload: true,
      requires_artwork: true,
      requires_proof_approval: true,
      max_revisions: 2,
      price_surcharge: 850000,
      estimated_lead_days: 5,
      features_fa: ['ارسال فایل اختصاصی'],
      features_en: ['Custom artwork upload'],
      sort_order: 2,
    },
  ],
  dpi_policy: {
    min_dpi: 180,
    recommended_dpi: 300,
    allowed_formats: ['png', 'jpg', 'jpeg', 'tif', 'tiff', 'pdf'],
    max_file_mb: 25,
  },
};

function makeJob(overrides: Partial<CustomerSkinJob> = {}): CustomerSkinJob {
  return {
    job_uuid: 'job-uuid-001',
    wc_order_id: null,
    wc_order_status: null,
    wc_order_url: null,
    device_id: 101,
    skin_variant_id: 201,
    plan_id: 1,
    snapshot: {
      device: { name_fa: 'پاییونیر DDJ-FLX4', name_en: 'Pioneer DJ DDJ-FLX4', model_code: 'DDJ-FLX4' },
      variant: {
        model_name_fa: 'کلاسیک',
        model_name_en: 'Classic',
        color_name_fa: 'مشکی مات',
        color_name_en: 'Matte Black',
      },
      plan: {
        slug: 'custom-design',
        name_fa: 'طراحی اختصاصی',
        name_en: 'Custom Design',
        allows_artwork_upload: true,
        requires_artwork: true,
        requires_proof_approval: true,
        max_revisions: 2,
        price_surcharge: 850000,
      },
    },
    status: 'draft',
    current_artwork_id: null,
    current_proof_id: null,
    approved_proof_id: null,
    approved_at: null,
    customer_notes: '',
    tracking_code: null,
    artworks: [],
    status_history: [
      { id: 1, from_status: null, to_status: 'draft', actor_role: 'customer', comment: null, created_at: '2026-11-23 10:00:00' },
    ],
    created_at: '2026-11-23 10:00:00',
    updated_at: '2026-11-23 10:00:00',
    ...overrides,
  };
}

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}

async function flush(): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, 0));
  await new Promise((resolve) => setTimeout(resolve, 0));
}

describe('Phase 5.6 Custom Skins Experience', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
    document.documentElement.lang = 'en-US';
    document.documentElement.dir = 'ltr';
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('completes Standard plan flow without rendering artwork upload UI and adds to cart', async () => {
    const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = String(input);
      if (url.includes('/catalog/public/devices')) {
        return jsonResponse({ ok: true, data: { items: SAMPLE_DEVICES } });
      }
      if (url.includes('/skins/options')) {
        return jsonResponse({ ok: true, data: SAMPLE_OPTIONS });
      }
      if (url.endsWith('/skins/jobs') && init?.method === 'POST') {
        return jsonResponse({ ok: true, data: makeJob({ plan_id: 1 }) }, 201);
      }
      if (url.includes('/skins/jobs/job-uuid-001/add-to-cart') && init?.method === 'POST') {
        return jsonResponse({
          ok: true,
          data: {
            job_uuid: 'job-uuid-001',
            wc_product_id: 301,
            cart_item_key: 'abc123',
            cart_url: '/cart/',
            checkout_url: '/checkout/',
            job: makeJob({ plan_id: 1 }),
          },
        });
      }
      throw new Error(`Unexpected fetch: ${url}`);
    });
    vi.stubGlobal('fetch', fetchMock);

    const root = document.createElement('section');
    root.className = 'djgear-skin-configurator';
    root.setAttribute('dir', 'ltr');
    root.dataset.config = JSON.stringify({
      deviceId: 0,
      locale: 'en_US',
      dir: 'ltr',
      isLoggedIn: true,
      loginUrl: '/my-account/',
      cartUrl: '/cart/',
      catalogApi: '/wp-json/djgear/v1/catalog/public/devices',
      skinsApi: '/wp-json/djgear/v1/skins',
      restNonce: 'nonce-123',
    });
    root.innerHTML = '<div class="djgear-skin-configurator__mount"></div>';
    document.body.appendChild(root);

    initSkinsConfigurator(root);
    await flush();

    // Select device
    const deviceBtn = root.querySelector<HTMLButtonElement>('[data-device-id="101"]');
    expect(deviceBtn).not.toBeNull();
    deviceBtn!.click();
    await flush();

    // Out-of-stock variant is disabled
    const oosBtn = root.querySelector<HTMLButtonElement>('[data-variant-id="202"]');
    expect(oosBtn?.disabled).toBe(true);

    // Select in-stock variant
    root.querySelector<HTMLButtonElement>('[data-variant-id="201"]')!.click();
    // Select Standard plan (allows_artwork_upload === false)
    root.querySelector<HTMLButtonElement>('[data-plan-slug="standard"]')!.click();

    // Artwork upload section MUST NOT exist for Standard plan
    expect(root.querySelector('[data-section="artwork-upload"]')).toBeNull();

    // Add to cart
    const addBtn = root.querySelector<HTMLButtonElement>('[data-action="add-skin-to-cart"]');
    expect(addBtn?.disabled).toBe(false);
    addBtn!.click();
    await flush();

    expect(root.textContent).toContain('Custom skin added to your WooCommerce cart.');
    expect(root.querySelector('a[href="/cart/"]')).not.toBeNull();
    expect(root.querySelector('a[href="/checkout/"]')).not.toBeNull();
  });

  it('enforces DPI rejection, warning acknowledgment with revision+SHA-256, and allows add-to-cart only after ack', async () => {
    let uploadAttempt = 0;
    const ackPayloads: Array<Record<string, unknown>> = [];

    const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = String(input);
      if (url.includes('/catalog/public/devices')) {
        return jsonResponse({ ok: true, data: { items: SAMPLE_DEVICES } });
      }
      if (url.includes('/skins/options')) {
        return jsonResponse({ ok: true, data: SAMPLE_OPTIONS });
      }
      if (url.endsWith('/skins/jobs') && init?.method === 'POST') {
        return jsonResponse({ ok: true, data: makeJob({ plan_id: 2 }) }, 201);
      }
      if (url.includes('/skins/jobs/job-uuid-001/artwork') && init?.method === 'POST') {
        uploadAttempt += 1;
        if (uploadAttempt === 1) {
          return jsonResponse({
            ok: true,
            data: {
              job: makeJob({ plan_id: 2, current_artwork_id: 501 }),
              artwork: {
                id: 501,
                revision_type: 'customer_artwork',
                version_number: 1,
                original_filename: 'lowres.png',
                mime_type: 'image/png',
                file_size_bytes: 1200,
                sha256_hash: 'sha-low-1',
                pixel_width: 600,
                pixel_height: 400,
                computed_dpi: 110,
                dpi_status: 'rejected',
                low_dpi_acknowledged: false,
                customer_decision: 'pending',
                decision_at: null,
                note_fa: null,
                note_en: null,
                created_at: '2026-11-23 10:01:00',
                download_url: '/download/501',
              },
              dpi_report: {
                pixel_width: 600,
                pixel_height: 400,
                computed_dpi: 110,
                dpi_status: 'rejected',
                message_en: 'Resolution below 180 DPI minimum.',
              },
            },
          });
        }
        return jsonResponse({
          ok: true,
          data: {
            job: makeJob({ plan_id: 2, current_artwork_id: 502 }),
            artwork: {
              id: 502,
              revision_type: 'customer_artwork',
              version_number: 2,
              original_filename: 'medium.png',
              mime_type: 'image/png',
              file_size_bytes: 4200,
              sha256_hash: 'sha-med-2',
              pixel_width: 4000,
              pixel_height: 2400,
              computed_dpi: 220,
              dpi_status: 'warning',
              low_dpi_acknowledged: false,
              customer_decision: 'pending',
              decision_at: null,
              note_fa: null,
              note_en: null,
              created_at: '2026-11-23 10:02:00',
              download_url: '/download/502',
            },
            dpi_report: {
              pixel_width: 4000,
              pixel_height: 2400,
              computed_dpi: 220,
              dpi_status: 'warning',
              message_en: 'Medium resolution (220 DPI). Explicit acknowledgment required.',
            },
          },
        });
      }
      if (url.includes('/skins/jobs/job-uuid-001/acknowledge-dpi') && init?.method === 'POST') {
        ackPayloads.push(JSON.parse(String(init.body)));
        return jsonResponse({ ok: true, data: makeJob({ plan_id: 2, current_artwork_id: 502 }) });
      }
      if (url.includes('/skins/jobs/job-uuid-001/add-to-cart') && init?.method === 'POST') {
        return jsonResponse({
          ok: true,
          data: {
            job_uuid: 'job-uuid-001',
            wc_product_id: 301,
            cart_item_key: 'xyz987',
            cart_url: '/cart/',
            checkout_url: '/checkout/',
            job: makeJob({ plan_id: 2, current_artwork_id: 502 }),
          },
        });
      }
      throw new Error(`Unexpected fetch: ${url}`);
    });
    vi.stubGlobal('fetch', fetchMock);

    const root = document.createElement('section');
    root.className = 'djgear-skin-configurator';
    root.dataset.config = JSON.stringify({
      deviceId: 101,
      locale: 'en_US',
      dir: 'ltr',
      isLoggedIn: true,
      loginUrl: '/my-account/',
      cartUrl: '/cart/',
      catalogApi: '/wp-json/djgear/v1/catalog/public/devices',
      skinsApi: '/wp-json/djgear/v1/skins',
      restNonce: 'nonce-123',
    });
    root.innerHTML = '<div class="djgear-skin-configurator__mount"></div>';
    document.body.appendChild(root);

    initSkinsConfigurator(root);
    await flush();

    root.querySelector<HTMLButtonElement>('[data-variant-id="201"]')!.click();
    root.querySelector<HTMLButtonElement>('[data-plan-slug="custom-design"]')!.click();

    // Artwork section IS rendered; Add to Cart disabled before upload
    expect(root.querySelector('[data-section="artwork-upload"]')).not.toBeNull();
    expect(root.querySelector<HTMLButtonElement>('[data-action="add-skin-to-cart"]')?.disabled).toBe(true);

    // Upload 1: rejected (<180 DPI)
    const fileInput1 = root.querySelector<HTMLInputElement>('input[type="file"]')!;
    const f1 = new File(['low'], 'lowres.png', { type: 'image/png' });
    Object.defineProperty(fileInput1, 'files', { value: [f1] });
    fileInput1.dispatchEvent(new Event('change'));

    const uploadBtn1 = root.querySelector<HTMLButtonElement>('.djg-skin-upload-row button')!;
    uploadBtn1.click();
    await flush();

    expect(root.textContent).toContain('Resolution below 180 DPI minimum.');
    expect(root.querySelector<HTMLButtonElement>('[data-action="add-skin-to-cart"]')?.disabled).toBe(true);
    expect(root.querySelector('input[type="checkbox"]')).toBeNull();

    // Upload 2: warning (220 DPI)
    const fileInput2 = root.querySelector<HTMLInputElement>('input[type="file"]')!;
    const f2 = new File(['med'], 'medium.png', { type: 'image/png' });
    Object.defineProperty(fileInput2, 'files', { value: [f2] });
    fileInput2.dispatchEvent(new Event('change'));

    root.querySelector<HTMLButtonElement>('.djg-skin-upload-row button')!.click();
    await flush();

    expect(root.textContent).toContain('Medium resolution (220 DPI)');
    expect(root.querySelector<HTMLButtonElement>('[data-action="add-skin-to-cart"]')?.disabled).toBe(true);

    // Check acknowledgment checkbox & submit
    const cb = root.querySelector<HTMLInputElement>('input[type="checkbox"]')!;
    cb.checked = true;
    cb.dispatchEvent(new Event('change'));
    const ackBtn = root.querySelector<HTMLButtonElement>('.djg-skin-alert--warning button')!;
    ackBtn.click();
    await flush();

    expect(ackPayloads).toEqual([{ acknowledged: true, artwork_id: 502, sha256_hash: 'sha-med-2' }]);
    expect(root.querySelector<HTMLButtonElement>('[data-action="add-skin-to-cart"]')?.disabled).toBe(false);
  });

  it('gates anonymous users behind sign-in link in Persian RTL mode', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL) => {
        const url = String(input);
        if (url.includes('/catalog/public/devices')) {
          return jsonResponse({ ok: true, data: { items: SAMPLE_DEVICES } });
        }
        return jsonResponse({ ok: true, data: SAMPLE_OPTIONS });
      })
    );

    const root = document.createElement('section');
    root.className = 'djgear-skin-configurator';
    root.setAttribute('dir', 'rtl');
    root.dataset.config = JSON.stringify({
      deviceId: 101,
      locale: 'fa_IR',
      dir: 'rtl',
      isLoggedIn: false,
      loginUrl: '/my-account/?redirect_to=skins',
      cartUrl: '/cart/',
      catalogApi: '/wp-json/djgear/v1/catalog/public/devices',
      skinsApi: '/wp-json/djgear/v1/skins',
      restNonce: '',
    });
    root.innerHTML = '<div class="djgear-skin-configurator__mount"></div>';
    document.body.appendChild(root);

    initSkinsConfigurator(root);
    await flush();

    root.querySelector<HTMLButtonElement>('[data-variant-id="201"]')!.click();
    root.querySelector<HTMLButtonElement>('[data-plan-slug="custom-design"]')!.click();

    expect(root.querySelector('input[type="file"]')).toBeNull();
    expect(root.querySelector('a[href="/my-account/?redirect_to=skins"]')).not.toBeNull();
    expect(root.textContent).toContain('ورود به حساب کاربری');
  });

  it('hydrates My Account tracking portal, displays timeline & proof revisions, and approves designer proof', async () => {
    const proofJob = makeJob({
      job_uuid: 'job-proof-77',
      wc_order_id: 889,
      wc_order_url: '/my-account/view-order/889/',
      status: 'proof_sent',
      tracking_code: 'TRK-5544',
      artworks: [
        {
          id: 901,
          revision_type: 'designer_proof',
          version_number: 1,
          original_filename: 'proof-v1.pdf',
          mime_type: 'application/pdf',
          file_size_bytes: 55000,
          sha256_hash: 'proof-sha',
          pixel_width: null,
          pixel_height: null,
          computed_dpi: null,
          dpi_status: 'not_applicable',
          low_dpi_acknowledged: false,
          customer_decision: 'pending',
          decision_at: null,
          note_fa: null,
          note_en: null,
          created_at: '2026-11-23 11:00:00',
          download_url: '/wp-json/djgear/v1/skins/my-jobs/job-proof-77/artworks/901/download',
        },
      ],
      status_history: [
        { id: 1, from_status: null, to_status: 'submitted_for_review', actor_role: 'system', comment: null, created_at: '2026-11-23 10:00:00' },
        { id: 2, from_status: 'in_design_review', to_status: 'proof_sent', actor_role: 'designer', comment: 'Proof ready', created_at: '2026-11-23 11:00:00' },
      ],
    });

    const approvedJob = { ...proofJob, status: 'approved_for_production' };
    let approveCalled = false;

    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        const url = String(input);
        if (url.includes('/my-jobs?')) {
          return jsonResponse({ ok: true, data: { items: [proofJob] } });
        }
        if (url.endsWith('/my-jobs/job-proof-77') && (!init || init.method === 'GET')) {
          return jsonResponse({ ok: true, data: proofJob });
        }
        if (url.endsWith('/my-jobs/job-proof-77/approve-proof') && init?.method === 'POST') {
          approveCalled = true;
          return jsonResponse({ ok: true, data: approvedJob });
        }
        throw new Error(`Unexpected fetch: ${url}`);
      })
    );

    const portal = document.createElement('div');
    portal.className = 'djgear-my-skins';
    portal.setAttribute('dir', 'ltr');
    portal.dataset.restBase = '/wp-json/djgear/v1/skins';
    portal.dataset.nonce = 'portal-nonce';
    document.body.appendChild(portal);

    initSkinsPortal(portal);
    await flush();

    expect(portal.textContent).toContain('Pioneer DJ DDJ-FLX4');
    expect(portal.textContent).toContain('#889');
    expect(portal.textContent).toContain('TRK-5544');

    // Expand details
    portal.querySelector<HTMLButtonElement>('[data-action="toggle-job-details"]')!.click();
    await flush();

    expect(portal.textContent).toContain('proof-v1.pdf');
    expect(portal.textContent).toContain('Production Timeline');

    // Approve proof
    portal.querySelector<HTMLButtonElement>('[data-action="approve-proof"]')!.click();
    await flush();

    expect(approveCalled).toBe(true);
    expect(portal.textContent).toContain('Approved for Production');
  });
});
