import { SkinsApiClient, SkinsApiError } from './skins-api';
import { formatNumber, getCopy, pickBilingual, resolveLocale, type SkinsCopy, type SkinsLocale } from './skins-i18n';
import type {
  AddToCartResponse,
  ConfiguratorBootConfig,
  CustomerArtworkRevision,
  CustomerSkinJob,
  DpiReport,
  PublicDeviceItem,
  PublicSkinPlan,
  PublicSkinVariant,
  SkinsOptionsResponse,
} from './skins-types';

const HEX_COLOR_RE = /^#[0-9a-fA-F]{3,8}$/;

interface ConfiguratorState {
  devices: PublicDeviceItem[];
  devicesLoading: boolean;
  searchQuery: string;
  selectedDeviceId: number;
  options: SkinsOptionsResponse | null;
  optionsLoading: boolean;
  selectedVariantId: number;
  selectedPlanId: number;
  customerNotes: string;
  draftJob: CustomerSkinJob | null;
  latestArtwork: CustomerArtworkRevision | null;
  latestDpiReport: DpiReport | null;
  selectedFile: File | null;
  ackChecked: boolean;
  ackRecorded: boolean;
  uploading: boolean;
  acknowledging: boolean;
  addingToCart: boolean;
  cartResult: AddToCartResponse | null;
  errorMessage: string;
}

function el<K extends keyof HTMLElementTagNameMap>(
  tag: K,
  className?: string,
  text?: string
): HTMLElementTagNameMap[K] {
  const node = document.createElement(tag);
  if (className) {
    node.className = className;
  }
  if (text !== undefined) {
    node.textContent = text;
  }
  return node;
}

function parseBootConfig(root: HTMLElement): ConfiguratorBootConfig | null {
  const raw = root.dataset.config;
  if (!raw) {
    return null;
  }
  try {
    const parsed = JSON.parse(raw) as Partial<ConfiguratorBootConfig>;
    const urlDevice = Number(new URLSearchParams(window.location.search).get('device_id') || '0');
    const deviceId = Number(parsed.deviceId || 0) || (Number.isFinite(urlDevice) && urlDevice > 0 ? urlDevice : 0);
    return {
      deviceId,
      locale: parsed.locale || document.documentElement.lang || 'en_US',
      dir: parsed.dir === 'rtl' || root.getAttribute('dir') === 'rtl' ? 'rtl' : 'ltr',
      isLoggedIn: Boolean(parsed.isLoggedIn),
      loginUrl: String(parsed.loginUrl || '/my-account/'),
      cartUrl: String(parsed.cartUrl || '/cart/'),
      catalogApi: String(parsed.catalogApi || '/wp-json/djgear/v1/catalog/public/devices'),
      skinsApi: String(parsed.skinsApi || '/wp-json/djgear/v1/skins'),
      restNonce: String(parsed.restNonce || ''),
    };
  } catch {
    return null;
  }
}

function renderDpiAlert(
  report: DpiReport,
  artwork: CustomerArtworkRevision | null,
  lang: SkinsLocale,
  copy: SkinsCopy,
  state: ConfiguratorState,
  onToggleAck: (checked: boolean) => void,
  onSubmitAck: () => void
): HTMLElement {
  let alertModifier = 'djg-skin-alert--info';
  let titleText = copy.dpiPassTitle;

  if (report.dpi_status === 'pass') {
    alertModifier = 'djg-skin-alert--pass';
    titleText = copy.dpiPassTitle;
  } else if (report.dpi_status === 'warning') {
    alertModifier = 'djg-skin-alert--warning';
    titleText = copy.dpiWarningTitle;
  } else if (report.dpi_status === 'manual_review_required') {
    alertModifier = 'djg-skin-alert--warning';
    titleText = copy.dpiManualTitle;
  } else if (report.dpi_status === 'rejected') {
    alertModifier = 'djg-skin-alert--danger';
    titleText = copy.dpiRejectedTitle;
  }

  const box = el('div', `djg-skin-alert ${alertModifier}`);
  box.setAttribute('role', report.dpi_status === 'rejected' ? 'alert' : 'status');

  const heading = el('strong', undefined, titleText);
  box.appendChild(heading);

  const serverMessage = pickBilingual(lang, report.message_fa, report.message_en);
  if (serverMessage) {
    box.appendChild(el('p', undefined, serverMessage));
  }

  const metaParts: string[] = [];
  if (artwork?.original_filename) {
    metaParts.push(artwork.original_filename);
  }
  if (report.pixel_width && report.pixel_height) {
    metaParts.push(`${report.pixel_width} × ${report.pixel_height} px`);
  }
  if (typeof report.computed_dpi === 'number' && report.computed_dpi > 0) {
    metaParts.push(`${report.computed_dpi} DPI`);
  }
  if (metaParts.length > 0) {
    const meta = el('p', 'djg-skin-option__meta', metaParts.join(' · '));
    meta.setAttribute('dir', 'ltr');
    box.appendChild(meta);
  }

  const needsAck = report.dpi_status === 'warning' || report.dpi_status === 'manual_review_required';
  if (needsAck && artwork) {
    if (state.ackRecorded || artwork.low_dpi_acknowledged) {
      box.appendChild(el('span', 'djg-skin-badge djg-skin-badge--pass', copy.ackConfirmedBadge));
    } else {
      const label = el('label', 'djg-skin-ack-label');
      const checkbox = el('input');
      checkbox.type = 'checkbox';
      checkbox.checked = state.ackChecked;
      checkbox.addEventListener('change', () => onToggleAck(checkbox.checked));
      label.appendChild(checkbox);
      label.appendChild(el('span', undefined, copy.ackCheckboxLabel));
      box.appendChild(label);

      const ackBtn = el('button', 'djg-btn djg-btn--secondary', copy.ackSubmitButton);
      ackBtn.type = 'button';
      ackBtn.disabled = !state.ackChecked || state.acknowledging;
      ackBtn.addEventListener('click', onSubmitAck);
      box.appendChild(ackBtn);
    }
  }

  return box;
}

export function initSkinsConfigurator(root: HTMLElement): void {
  if (root.dataset.djgMounted === '1') {
    return;
  }

  const parsedConfig = parseBootConfig(root);
  if (!parsedConfig) {
    return;
  }
  const config: ConfiguratorBootConfig = parsedConfig;

  const mount = root.querySelector<HTMLElement>('.djgear-skin-configurator__mount') || root;
  root.dataset.djgMounted = '1';

  const lang = resolveLocale(config.dir, config.locale);
  const copy = getCopy(lang);
  const api = new SkinsApiClient(config.catalogApi, config.skinsApi, config.restNonce);

  const state: ConfiguratorState = {
    devices: [],
    devicesLoading: true,
    searchQuery: '',
    selectedDeviceId: config.deviceId,
    options: null,
    optionsLoading: false,
    selectedVariantId: 0,
    selectedPlanId: 0,
    customerNotes: '',
    draftJob: null,
    latestArtwork: null,
    latestDpiReport: null,
    selectedFile: null,
    ackChecked: false,
    ackRecorded: false,
    uploading: false,
    acknowledging: false,
    addingToCart: false,
    cartResult: null,
    errorMessage: '',
  };

  function getSelectedDevice(): PublicDeviceItem | undefined {
    return state.devices.find((d) => d.id === state.selectedDeviceId);
  }

  function getSelectedVariant(): PublicSkinVariant | undefined {
    return state.options?.variants.find((v) => v.id === state.selectedVariantId);
  }

  function getSelectedPlan(): PublicSkinPlan | undefined {
    return state.options?.plans.find((p) => p.id === state.selectedPlanId);
  }

  function resetDraftState(): void {
    state.draftJob = null;
    state.latestArtwork = null;
    state.latestDpiReport = null;
    state.selectedFile = null;
    state.ackChecked = false;
    state.ackRecorded = false;
    state.cartResult = null;
    state.errorMessage = '';
  }

  async function selectDevice(deviceId: number): Promise<void> {
    state.selectedDeviceId = deviceId;
    state.selectedVariantId = 0;
    state.selectedPlanId = 0;
    state.options = null;
    state.optionsLoading = true;
    resetDraftState();
    render();

    try {
      const opts = await api.getOptionsForDevice(deviceId);
      state.options = opts;
      state.optionsLoading = false;
      render();
    } catch (err) {
      state.optionsLoading = false;
      state.errorMessage = err instanceof SkinsApiError ? err.message : copy.errorGeneric;
      render();
    }
  }

  async function ensureDraftJob(): Promise<CustomerSkinJob> {
    if (state.draftJob) {
      return state.draftJob;
    }
    const created = await api.createDraftJob({
      device_id: state.selectedDeviceId,
      skin_variant_id: state.selectedVariantId,
      plan_id: state.selectedPlanId,
      customer_notes: state.customerNotes.trim(),
    });
    state.draftJob = created;
    return created;
  }

  async function handleArtworkUpload(): Promise<void> {
    if (!state.selectedFile || state.uploading) {
      return;
    }
    state.uploading = true;
    state.errorMessage = '';
    // Invalidate any prior acknowledgment before uploading replacement artwork.
    state.ackChecked = false;
    state.ackRecorded = false;
    state.cartResult = null;
    render();

    try {
      const job = await ensureDraftJob();
      const result = await api.uploadArtwork(job.job_uuid, state.selectedFile);
      state.draftJob = result.job;
      state.latestArtwork = result.artwork;
      state.latestDpiReport = result.dpi_report;
      state.ackRecorded = Boolean(result.artwork.low_dpi_acknowledged);
      state.uploading = false;
      render();
    } catch (err) {
      state.uploading = false;
      state.errorMessage = err instanceof SkinsApiError ? err.message : copy.errorGeneric;
      render();
    }
  }

  async function handleAcknowledgeDpi(): Promise<void> {
    if (!state.draftJob || !state.latestArtwork || !state.ackChecked || state.acknowledging) {
      return;
    }
    state.acknowledging = true;
    state.errorMessage = '';
    render();

    try {
      const updated = await api.acknowledgeDpi(
        state.draftJob.job_uuid,
        state.latestArtwork.id,
        state.latestArtwork.sha256_hash
      );
      state.draftJob = updated;
      state.ackRecorded = true;
      state.acknowledging = false;
      render();
    } catch (err) {
      state.acknowledging = false;
      state.errorMessage = err instanceof SkinsApiError ? err.message : copy.errorGeneric;
      render();
    }
  }

  async function handleAddToCart(): Promise<void> {
    if (state.addingToCart) {
      return;
    }
    state.addingToCart = true;
    state.errorMessage = '';
    render();

    try {
      const job = await ensureDraftJob();
      const res = await api.addJobToCart(job.job_uuid);
      state.draftJob = res.job;
      state.cartResult = res;
      state.addingToCart = false;
      render();
    } catch (err) {
      state.addingToCart = false;
      state.errorMessage = err instanceof SkinsApiError ? err.message : copy.errorGeneric;
      render();
    }
  }

  function canAddToCart(variant?: PublicSkinVariant, plan?: PublicSkinPlan): boolean {
    if (!config?.isLoggedIn || !variant || !plan || !variant.in_stock) {
      return false;
    }
    if (state.uploading || state.acknowledging || state.addingToCart) {
      return false;
    }
    if (plan.allows_artwork_upload) {
      if (plan.requires_artwork && !state.latestArtwork) {
        return false;
      }
      if (state.latestDpiReport) {
        const dpiStatus = state.latestDpiReport.dpi_status;
        if (dpiStatus === 'rejected') {
          return false;
        }
        if ((dpiStatus === 'warning' || dpiStatus === 'manual_review_required') && !state.ackRecorded) {
          return false;
        }
      }
    }
    return true;
  }

  function render(): void {
    const selectedDevice = getSelectedDevice();
    const selectedVariant = getSelectedVariant();
    const selectedPlan = getSelectedPlan();

    const wrapper = el('div', 'djg-skin-wizard');

    // Progress Indicator
    const progress = el('ol', 'djg-skin-progress');
    const steps: Array<{ label: string; done: boolean; active: boolean }> = [
      {
        label: copy.stepDevice,
        done: Boolean(selectedDevice),
        active: !selectedDevice,
      },
      {
        label: copy.stepVariant,
        done: Boolean(selectedVariant),
        active: Boolean(selectedDevice) && !selectedVariant,
      },
      {
        label: copy.stepPlan,
        done: Boolean(selectedPlan),
        active: Boolean(selectedVariant) && !selectedPlan,
      },
    ];

    if (selectedPlan?.allows_artwork_upload) {
      const artworkComplete =
        Boolean(state.latestArtwork) &&
        state.latestDpiReport?.dpi_status !== 'rejected' &&
        (state.latestDpiReport?.dpi_status === 'pass' || state.ackRecorded);
      steps.push({
        label: copy.stepArtwork,
        done: artworkComplete,
        active: Boolean(selectedPlan) && !artworkComplete,
      });
    }

    steps.push({
      label: copy.stepConfirm,
      done: Boolean(state.cartResult),
      active:
        selectedPlan !== undefined && (!selectedPlan.allows_artwork_upload || canAddToCart(selectedVariant, selectedPlan)),
    });

    for (const s of steps) {
      const li = el(
        'li',
        `djg-skin-progress__step${s.done ? ' is-done' : ''}${s.active && !s.done ? ' is-active' : ''}`,
        s.label
      );
      progress.appendChild(li);
    }
    wrapper.appendChild(progress);

    if (state.errorMessage) {
      const errBox = el('div', 'djg-skin-alert djg-skin-alert--danger');
      errBox.setAttribute('role', 'alert');
      errBox.appendChild(el('p', undefined, state.errorMessage));
      wrapper.appendChild(errBox);
    }

    // STEP 1: Device Selection
    const secDevice = el('section', 'djg-skin-section');
    secDevice.appendChild(el('h2', 'djg-skin-section__title', copy.stepDevice));

    if (state.devicesLoading) {
      secDevice.appendChild(el('p', 'djg-skin-option__meta', copy.loadingDevices));
    } else if (state.devices.length === 0) {
      secDevice.appendChild(el('p', 'djg-skin-option__meta', copy.noDevices));
    } else {
      const searchInput = el('input', 'djg-input djg-skin-search');
      searchInput.type = 'search';
      searchInput.placeholder = copy.searchDevices;
      searchInput.setAttribute('aria-label', copy.searchDevices);
      searchInput.value = state.searchQuery;
      searchInput.addEventListener('input', () => {
        state.searchQuery = searchInput.value;
        const pos = searchInput.selectionStart;
        render();
        const freshInput = mount.querySelector<HTMLInputElement>('.djg-skin-search');
        if (freshInput) {
          freshInput.focus();
          if (pos !== null) {
            freshInput.setSelectionRange(pos, pos);
          }
        }
      });
      secDevice.appendChild(searchInput);

      const q = state.searchQuery.trim().toLowerCase();
      const filteredDevices = q
        ? state.devices.filter((d) =>
            `${d.name_en} ${d.name_fa} ${d.model_code} ${d.brand?.name_en || ''} ${d.brand?.name_fa || ''}`
              .toLowerCase()
              .includes(q)
          )
        : state.devices;

      const grid = el('div', 'djg-skin-grid djg-skin-grid--devices');
      for (const dev of filteredDevices) {
        const isSelected = dev.id === state.selectedDeviceId;
        const btn = el('button', `djg-skin-option${isSelected ? ' is-selected' : ''}`);
        btn.type = 'button';
        btn.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
        btn.dataset.deviceId = String(dev.id);
        btn.addEventListener('click', () => {
          void selectDevice(dev.id);
        });

        const head = el('div', 'djg-skin-option__head');
        head.appendChild(el('span', 'djg-skin-option__title', pickBilingual(lang, dev.name_fa, dev.name_en)));
        btn.appendChild(head);

        const brandName = pickBilingual(lang, dev.brand?.name_fa, dev.brand?.name_en);
        const metaText = [brandName, dev.model_code].filter(Boolean).join(' · ');
        if (metaText) {
          btn.appendChild(el('span', 'djg-skin-option__meta', metaText));
        }

        grid.appendChild(btn);
      }
      secDevice.appendChild(grid);
    }
    wrapper.appendChild(secDevice);

    // STEP 2: Variant / Finish & Color Selection
    if (state.selectedDeviceId > 0) {
      const secVariant = el('section', 'djg-skin-section');
      secVariant.appendChild(el('h2', 'djg-skin-section__title', copy.stepVariant));

      if (state.optionsLoading) {
        secVariant.appendChild(el('p', 'djg-skin-option__meta', copy.loadingOptions));
      } else if (!state.options || state.options.variants.length === 0) {
        secVariant.appendChild(el('p', 'djg-skin-option__meta', copy.noVariants));
      } else {
        const grid = el('div', 'djg-skin-grid djg-skin-grid--variants');
        for (const variant of state.options.variants) {
          const isSelected = variant.id === state.selectedVariantId;
          const btn = el('button', `djg-skin-option${isSelected ? ' is-selected' : ''}`);
          btn.type = 'button';
          btn.disabled = !variant.in_stock;
          btn.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
          btn.dataset.variantId = String(variant.id);
          btn.addEventListener('click', () => {
            if (!variant.in_stock) {
              return;
            }
            state.selectedVariantId = variant.id;
            resetDraftState();
            render();
          });

          const head = el('div', 'djg-skin-option__head');
          const labelText = `${pickBilingual(lang, variant.model_name_fa, variant.model_name_en)} — ${pickBilingual(
            lang,
            variant.color_name_fa,
            variant.color_name_en
          )}`;
          head.appendChild(el('span', 'djg-skin-option__title', labelText));

          if (variant.color_hex && HEX_COLOR_RE.test(variant.color_hex)) {
            const swatch = el('span', 'djg-skin-swatch');
            swatch.style.backgroundColor = variant.color_hex;
            swatch.setAttribute('aria-hidden', 'true');
            head.appendChild(swatch);
          }
          btn.appendChild(head);

          const metaBits: string[] = [];
          if (variant.finish) {
            metaBits.push(variant.finish);
          }
          if (variant.print_width_mm && variant.print_height_mm) {
            metaBits.push(`${variant.print_width_mm} × ${variant.print_height_mm} mm`);
          }
          if (metaBits.length > 0) {
            btn.appendChild(el('span', 'djg-skin-option__meta', metaBits.join(' · ')));
          }

          const footer = el('div', 'djg-skin-option__head');
          if (variant.base_price !== null) {
            footer.appendChild(
              el('span', 'djg-skin-option__title', `${formatNumber(variant.base_price, lang)} ${variant.currency}`)
            );
          }
          footer.appendChild(
            el(
              'span',
              `djg-skin-badge ${variant.in_stock ? 'djg-skin-badge--pass' : 'djg-skin-badge--danger'}`,
              variant.in_stock ? copy.inStock : copy.outOfStock
            )
          );
          btn.appendChild(footer);

          grid.appendChild(btn);
        }
        secVariant.appendChild(grid);
      }
      wrapper.appendChild(secVariant);
    }

    // STEP 3: Plan Selection
    if (selectedVariant && state.options) {
      const secPlan = el('section', 'djg-skin-section');
      secPlan.appendChild(el('h2', 'djg-skin-section__title', copy.stepPlan));

      if (state.options.plans.length === 0) {
        secPlan.appendChild(el('p', 'djg-skin-option__meta', copy.noPlans));
      } else {
        const grid = el('div', 'djg-skin-grid');
        for (const plan of state.options.plans) {
          const isSelected = plan.id === state.selectedPlanId;
          const btn = el('button', `djg-skin-option${isSelected ? ' is-selected' : ''}`);
          btn.type = 'button';
          btn.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
          btn.dataset.planId = String(plan.id);
          btn.dataset.planSlug = plan.slug;
          btn.addEventListener('click', () => {
            state.selectedPlanId = plan.id;
            resetDraftState();
            render();
          });

          const head = el('div', 'djg-skin-option__head');
          head.appendChild(el('span', 'djg-skin-option__title', pickBilingual(lang, plan.name_fa, plan.name_en)));
          const surchargeText =
            plan.price_surcharge > 0
              ? `+${formatNumber(plan.price_surcharge, lang)} ${selectedVariant.currency}`
              : copy.included;
          head.appendChild(el('span', 'djg-skin-badge', surchargeText));
          btn.appendChild(head);

          const desc = pickBilingual(lang, plan.description_fa, plan.description_en);
          if (desc) {
            btn.appendChild(el('span', 'djg-skin-option__meta', desc));
          }

          btn.appendChild(
            el(
              'span',
              `djg-skin-badge ${plan.allows_artwork_upload ? 'djg-skin-badge--warning' : 'djg-skin-badge--pass'}`,
              plan.allows_artwork_upload ? copy.uploadPermitted : copy.uploadDisabled
            )
          );

          if (plan.requires_proof_approval) {
            btn.appendChild(el('span', 'djg-skin-option__meta', copy.proofApprovalRequired));
          }

          if (plan.estimated_lead_days > 0) {
            btn.appendChild(
              el('span', 'djg-skin-option__meta', `${copy.leadDays}: ${formatNumber(plan.estimated_lead_days, lang)}`)
            );
          }

          const features = lang === 'fa' ? plan.features_fa : plan.features_en;
          if (Array.isArray(features) && features.length > 0) {
            const ul = el('ul', 'djg-skin-features');
            for (const f of features) {
              ul.appendChild(el('li', undefined, `• ${f}`));
            }
            btn.appendChild(ul);
          }

          grid.appendChild(btn);
        }
        secPlan.appendChild(grid);
      }
      wrapper.appendChild(secPlan);
    }

    // STEP 4: Artwork Upload & DPI Check (STRICTLY ONLY when plan.allows_artwork_upload === true)
    if (selectedVariant && selectedPlan && selectedPlan.allows_artwork_upload) {
      const secArtwork = el('section', 'djg-skin-section');
      secArtwork.dataset.section = 'artwork-upload';
      secArtwork.appendChild(el('h2', 'djg-skin-section__title', copy.stepArtwork));

      if (!config.isLoggedIn) {
        const authAlert = el('div', 'djg-skin-alert djg-skin-alert--info');
        authAlert.appendChild(el('strong', undefined, copy.signInRequiredTitle));
        authAlert.appendChild(el('p', undefined, copy.signInRequiredBody));
        const loginLink = el('a', 'djg-btn djg-btn--primary', copy.signInButton);
        loginLink.href = config.loginUrl;
        authAlert.appendChild(loginLink);
        secArtwork.appendChild(authAlert);
      } else {
        const uploadBox = el('div', 'djg-skin-upload-box');
        uploadBox.appendChild(el('p', 'djg-skin-option__meta', copy.artworkPolicyHint));

        const row = el('div', 'djg-skin-upload-row');
        const fileInput = el('input', 'djg-input');
        fileInput.type = 'file';
        fileInput.accept = '.png,.jpg,.jpeg,.tif,.tiff,.pdf';
        fileInput.setAttribute('aria-label', copy.chooseFile);
        fileInput.addEventListener('change', () => {
          state.selectedFile = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
          render();
        });
        row.appendChild(fileInput);

        const uploadBtnLabel = state.uploading
          ? copy.uploading
          : state.latestArtwork
            ? copy.replaceFileButton
            : copy.uploadButton;
        const uploadBtn = el('button', 'djg-btn djg-btn--secondary', uploadBtnLabel);
        uploadBtn.type = 'button';
        uploadBtn.disabled = !state.selectedFile || state.uploading;
        uploadBtn.addEventListener('click', () => {
          void handleArtworkUpload();
        });
        row.appendChild(uploadBtn);
        uploadBox.appendChild(row);

        if (state.latestDpiReport) {
          uploadBox.appendChild(
            renderDpiAlert(
              state.latestDpiReport,
              state.latestArtwork,
              lang,
              copy,
              state,
              (checked) => {
                state.ackChecked = checked;
                render();
              },
              () => {
                void handleAcknowledgeDpi();
              }
            )
          );
        }

        secArtwork.appendChild(uploadBox);
      }
      wrapper.appendChild(secArtwork);
    }

    // STEP 5: Review, Notes & Add to Cart
    if (selectedDevice && selectedVariant && selectedPlan) {
      const secConfirm = el('section', 'djg-skin-section');
      secConfirm.appendChild(el('h2', 'djg-skin-section__title', copy.stepConfirm));

      const notesLabel = el('label', 'djg-skin-option__meta', copy.notesLabel);
      const notesArea = el('textarea', 'djg-textarea');
      notesArea.rows = 3;
      notesArea.placeholder = copy.notesPlaceholder;
      notesArea.value = state.customerNotes;
      notesArea.addEventListener('input', () => {
        state.customerNotes = notesArea.value;
      });
      secConfirm.appendChild(notesLabel);
      secConfirm.appendChild(notesArea);

      // Configuration Summary (strictly displays server-returned values; no client-side total math)
      const summary = el('div', 'djg-skin-summary-list');
      summary.appendChild(el('strong', undefined, copy.summaryHeading));

      const addSummaryRow = (label: string, val: string): void => {
        const row = el('div', 'djg-skin-summary-row');
        row.appendChild(el('span', 'djg-skin-option__meta', label));
        row.appendChild(el('span', undefined, val));
        summary.appendChild(row);
      };

      addSummaryRow(copy.stepDevice, pickBilingual(lang, selectedDevice.name_fa, selectedDevice.name_en));
      addSummaryRow(
        copy.stepVariant,
        `${pickBilingual(lang, selectedVariant.model_name_fa, selectedVariant.model_name_en)} — ${pickBilingual(
          lang,
          selectedVariant.color_name_fa,
          selectedVariant.color_name_en
        )}`
      );
      addSummaryRow(copy.stepPlan, pickBilingual(lang, selectedPlan.name_fa, selectedPlan.name_en));

      if (selectedVariant.base_price !== null) {
        addSummaryRow(copy.basePrice, `${formatNumber(selectedVariant.base_price, lang)} ${selectedVariant.currency}`);
      }
      addSummaryRow(
        copy.surcharge,
        selectedPlan.price_surcharge > 0
          ? `+${formatNumber(selectedPlan.price_surcharge, lang)} ${selectedVariant.currency}`
          : copy.included
      );
      secConfirm.appendChild(summary);
      secConfirm.appendChild(el('p', 'djg-skin-option__meta', copy.qtyNotice));

      if (!config.isLoggedIn) {
        const authAlert = el('div', 'djg-skin-alert djg-skin-alert--info');
        authAlert.appendChild(el('strong', undefined, copy.signInRequiredTitle));
        authAlert.appendChild(el('p', undefined, copy.signInRequiredBody));
        const loginLink = el('a', 'djg-btn djg-btn--primary', copy.signInButton);
        loginLink.href = config.loginUrl;
        authAlert.appendChild(loginLink);
        secConfirm.appendChild(authAlert);
      } else if (state.cartResult) {
        const successBox = el('div', 'djg-skin-alert djg-skin-alert--pass');
        successBox.setAttribute('role', 'status');
        successBox.appendChild(el('strong', undefined, copy.addedSuccessTitle));

        const actions = el('div', 'djg-proof-actions__row');
        const cartBtn = el('a', 'djg-btn djg-btn--primary', copy.viewCartButton);
        cartBtn.href = state.cartResult.cart_url || config.cartUrl;
        actions.appendChild(cartBtn);

        if (state.cartResult.checkout_url) {
          const checkoutBtn = el('a', 'djg-btn djg-btn--secondary', copy.checkoutButton);
          checkoutBtn.href = state.cartResult.checkout_url;
          actions.appendChild(checkoutBtn);
        }
        successBox.appendChild(actions);
        secConfirm.appendChild(successBox);
      } else {
        const submitBtn = el(
          'button',
          'djg-btn djg-btn--primary',
          state.addingToCart ? copy.addingToCart : copy.addToCartButton
        );
        submitBtn.type = 'button';
        submitBtn.dataset.action = 'add-skin-to-cart';
        submitBtn.disabled = !canAddToCart(selectedVariant, selectedPlan);
        submitBtn.addEventListener('click', () => {
          void handleAddToCart();
        });
        secConfirm.appendChild(submitBtn);
      }

      wrapper.appendChild(secConfirm);
    }

    mount.replaceChildren(wrapper);
  }

  async function boot(): Promise<void> {
    render();
    try {
      const devices = await api.listActiveDevices();
      state.devices = devices;
      state.devicesLoading = false;
      if (state.selectedDeviceId > 0 && devices.some((d) => d.id === state.selectedDeviceId)) {
        await selectDevice(state.selectedDeviceId);
        return;
      }
      state.selectedDeviceId = 0;
      render();
    } catch (err) {
      state.devicesLoading = false;
      state.errorMessage = err instanceof SkinsApiError ? err.message : copy.errorGeneric;
      render();
    }
  }

  void boot();
}
