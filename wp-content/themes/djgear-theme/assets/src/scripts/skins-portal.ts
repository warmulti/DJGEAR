import { SkinsApiClient, SkinsApiError } from './skins-api';
import { getCopy, pickBilingual, resolveLocale, type SkinsCopy, type SkinsLocale } from './skins-i18n';
import type { CustomerArtworkRevision, CustomerSkinJob, DpiReport } from './skins-types';

interface PortalJobDetailState {
  loading: boolean;
  job: CustomerSkinJob;
  comment: string;
  selectedFile: File | null;
  latestArtwork: CustomerArtworkRevision | null;
  latestDpiReport: DpiReport | null;
  ackChecked: boolean;
  ackRecorded: boolean;
  busy: boolean;
  feedback: string;
  error: string;
  cartUrl: string;
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

function statusBadgeClass(status: string): string {
  if (['approved_for_production', 'shipped', 'completed'].includes(status)) {
    return 'djg-skin-badge djg-skin-badge--pass';
  }
  if (['proof_sent', 'revision_requested', 'on_hold', 'awaiting_payment'].includes(status)) {
    return 'djg-skin-badge djg-skin-badge--warning';
  }
  if (status === 'cancelled') {
    return 'djg-skin-badge djg-skin-badge--danger';
  }
  return 'djg-skin-badge';
}

function dpiBadgeClass(dpiStatus: string): string {
  if (dpiStatus === 'pass') {
    return 'djg-skin-badge djg-skin-badge--pass';
  }
  if (dpiStatus === 'warning' || dpiStatus === 'manual_review_required') {
    return 'djg-skin-badge djg-skin-badge--warning';
  }
  if (dpiStatus === 'rejected') {
    return 'djg-skin-badge djg-skin-badge--danger';
  }
  return 'djg-skin-badge';
}

function triggerBlobDownload(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename || 'skin-revision';
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}

export function initSkinsPortal(root: HTMLElement): void {
  if (root.dataset.djgMounted === '1') {
    return;
  }

  const restBase = root.dataset.restBase;
  const nonce = root.dataset.nonce || '';
  if (!restBase) {
    return;
  }
  root.dataset.djgMounted = '1';

  const dir = root.getAttribute('dir') === 'rtl' || document.documentElement.dir === 'rtl' ? 'rtl' : 'ltr';
  const lang: SkinsLocale = resolveLocale(dir, document.documentElement.lang || 'en_US');
  const copy: SkinsCopy = getCopy(lang);
  const api = new SkinsApiClient('/wp-json/djgear/v1/catalog/public/devices', restBase, nonce);

  let jobs: CustomerSkinJob[] = [];
  const expanded = new Map<string, PortalJobDetailState>();

  async function refreshJobDetail(uuid: string): Promise<void> {
    const entry = expanded.get(uuid);
    if (!entry) {
      return;
    }
    entry.loading = true;
    entry.error = '';
    renderPortal();

    try {
      const fresh = await api.getMyJob(uuid);
      entry.job = fresh;
      entry.loading = false;
      jobs = jobs.map((j) => (j.job_uuid === uuid ? fresh : j));
      renderPortal();
    } catch (err) {
      entry.loading = false;
      entry.error = err instanceof SkinsApiError ? err.message : copy.errorGeneric;
      renderPortal();
    }
  }

  function renderJobDetails(entry: PortalJobDetailState): HTMLElement {
    const job = entry.job;
    const panel = el('div', 'djg-portal-card__details');

    if (entry.loading) {
      panel.appendChild(el('p', 'djg-skin-option__meta', copy.loadingJobs));
      return panel;
    }

    if (entry.error) {
      const errBox = el('div', 'djg-skin-alert djg-skin-alert--danger');
      errBox.setAttribute('role', 'alert');
      errBox.appendChild(el('p', undefined, entry.error));
      panel.appendChild(errBox);
    }

    if (entry.feedback) {
      const okBox = el('div', 'djg-skin-alert djg-skin-alert--pass');
      okBox.setAttribute('role', 'status');
      okBox.appendChild(el('p', undefined, entry.feedback));
      if (entry.cartUrl) {
        const cartLink = el('a', 'djg-btn djg-btn--primary', copy.viewCartButton);
        cartLink.href = entry.cartUrl;
        okBox.appendChild(cartLink);
      }
      panel.appendChild(okBox);
    }

    // Proof Approval / Revision Request Panel (STRICTLY when job.status === 'proof_sent')
    if (job.status === 'proof_sent') {
      const proofBox = el('div', 'djg-proof-actions');
      proofBox.dataset.section = 'proof-approval';
      proofBox.appendChild(el('strong', undefined, copy.approveProofTitle));

      const textarea = el('textarea', 'djg-textarea');
      textarea.rows = 2;
      textarea.placeholder = copy.commentPlaceholder;
      textarea.value = entry.comment;
      textarea.addEventListener('input', () => {
        entry.comment = textarea.value;
      });
      proofBox.appendChild(textarea);

      const actionRow = el('div', 'djg-proof-actions__row');
      const approveBtn = el('button', 'djg-btn djg-btn--primary', copy.approveProofButton);
      approveBtn.type = 'button';
      approveBtn.dataset.action = 'approve-proof';
      approveBtn.disabled = entry.busy;
      approveBtn.addEventListener('click', async () => {
        entry.busy = true;
        entry.error = '';
        entry.feedback = '';
        renderPortal();
        try {
          const updated = await api.approveProof(job.job_uuid, entry.comment.trim());
          entry.job = updated;
          entry.comment = '';
          entry.busy = false;
          entry.feedback = copy.statuses[updated.status] || updated.status;
          jobs = jobs.map((j) => (j.job_uuid === job.job_uuid ? updated : j));
          renderPortal();
        } catch (err) {
          entry.busy = false;
          entry.error = err instanceof SkinsApiError ? err.message : copy.errorGeneric;
          renderPortal();
        }
      });
      actionRow.appendChild(approveBtn);

      if ((job.snapshot.plan?.max_revisions ?? 0) > 0) {
        const revBtn = el('button', 'djg-btn djg-btn--secondary', copy.requestRevisionButton);
        revBtn.type = 'button';
        revBtn.dataset.action = 'request-revision';
        revBtn.disabled = entry.busy;
        revBtn.addEventListener('click', async () => {
          entry.busy = true;
          entry.error = '';
          entry.feedback = '';
          renderPortal();
          try {
            const updated = await api.requestRevision(job.job_uuid, entry.comment.trim());
            entry.job = updated;
            entry.comment = '';
            entry.busy = false;
            entry.feedback = copy.statuses[updated.status] || updated.status;
            jobs = jobs.map((j) => (j.job_uuid === job.job_uuid ? updated : j));
            renderPortal();
          } catch (err) {
            entry.busy = false;
            entry.error = err instanceof SkinsApiError ? err.message : copy.errorGeneric;
            renderPortal();
          }
        });
        actionRow.appendChild(revBtn);
      }

      proofBox.appendChild(actionRow);
      panel.appendChild(proofBox);
    }

    // Revised Artwork Upload (ONLY when plan allows upload AND status is draft or revision_requested)
    const canUploadRevision =
      Boolean(job.snapshot.plan?.allows_artwork_upload) &&
      (job.status === 'draft' || job.status === 'revision_requested');

    if (canUploadRevision) {
      const uploadBox = el('div', 'djg-skin-upload-box');
      uploadBox.dataset.section = 'portal-revision-upload';
      uploadBox.appendChild(el('strong', undefined, copy.uploadRevisionTitle));

      const row = el('div', 'djg-skin-upload-row');
      const fileInput = el('input', 'djg-input');
      fileInput.type = 'file';
      fileInput.accept = '.png,.jpg,.jpeg,.tif,.tiff,.pdf';
      fileInput.setAttribute('aria-label', copy.chooseFile);
      fileInput.addEventListener('change', () => {
        entry.selectedFile = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
        renderPortal();
      });
      row.appendChild(fileInput);

      const uploadBtn = el('button', 'djg-btn djg-btn--secondary', entry.busy ? copy.uploading : copy.uploadButton);
      uploadBtn.type = 'button';
      uploadBtn.dataset.action = 'upload-revision';
      uploadBtn.disabled = !entry.selectedFile || entry.busy;
      uploadBtn.addEventListener('click', async () => {
        if (!entry.selectedFile) {
          return;
        }
        entry.busy = true;
        entry.error = '';
        entry.feedback = '';
        entry.ackChecked = false;
        entry.ackRecorded = false;
        renderPortal();
        try {
          const result = await api.uploadArtwork(job.job_uuid, entry.selectedFile);
          entry.job = result.job;
          entry.latestArtwork = result.artwork;
          entry.latestDpiReport = result.dpi_report;
          entry.ackRecorded = Boolean(result.artwork.low_dpi_acknowledged);
          entry.selectedFile = null;
          entry.busy = false;
          jobs = jobs.map((j) => (j.job_uuid === job.job_uuid ? result.job : j));
          renderPortal();
        } catch (err) {
          entry.busy = false;
          entry.error = err instanceof SkinsApiError ? err.message : copy.errorGeneric;
          renderPortal();
        }
      });
      row.appendChild(uploadBtn);
      uploadBox.appendChild(row);

      if (entry.latestDpiReport) {
        const rep = entry.latestDpiReport;
        const msg = pickBilingual(lang, rep.message_fa, rep.message_en);
        const alertCls =
          rep.dpi_status === 'pass'
            ? 'djg-skin-alert--pass'
            : rep.dpi_status === 'rejected'
              ? 'djg-skin-alert--danger'
              : 'djg-skin-alert--warning';
        const alertBox = el('div', `djg-skin-alert ${alertCls}`);
        alertBox.appendChild(el('p', undefined, msg || rep.dpi_status));

        const needsAck =
          (rep.dpi_status === 'warning' || rep.dpi_status === 'manual_review_required') && entry.latestArtwork;
        if (needsAck && entry.latestArtwork) {
          if (entry.ackRecorded) {
            alertBox.appendChild(el('span', 'djg-skin-badge djg-skin-badge--pass', copy.ackConfirmedBadge));
          } else {
            const ackLabel = el('label', 'djg-skin-ack-label');
            const ackCb = el('input');
            ackCb.type = 'checkbox';
            ackCb.checked = entry.ackChecked;
            ackCb.addEventListener('change', () => {
              entry.ackChecked = ackCb.checked;
              renderPortal();
            });
            ackLabel.appendChild(ackCb);
            ackLabel.appendChild(el('span', undefined, copy.ackCheckboxLabel));
            alertBox.appendChild(ackLabel);

            const ackBtn = el('button', 'djg-btn djg-btn--secondary', copy.ackSubmitButton);
            ackBtn.type = 'button';
            ackBtn.disabled = !entry.ackChecked || entry.busy;
            ackBtn.addEventListener('click', async () => {
              if (!entry.latestArtwork) {
                return;
              }
              entry.busy = true;
              entry.error = '';
              renderPortal();
              try {
                const updated = await api.acknowledgeDpi(
                  job.job_uuid,
                  entry.latestArtwork.id,
                  entry.latestArtwork.sha256_hash
                );
                entry.job = updated;
                entry.ackRecorded = true;
                entry.busy = false;
                jobs = jobs.map((j) => (j.job_uuid === job.job_uuid ? updated : j));
                renderPortal();
              } catch (err) {
                entry.busy = false;
                entry.error = err instanceof SkinsApiError ? err.message : copy.errorGeneric;
                renderPortal();
              }
            });
            alertBox.appendChild(ackBtn);
          }
        }
        uploadBox.appendChild(alertBox);
      }

      panel.appendChild(uploadBox);
    }

    // Resume Draft -> Add to Cart
    if (job.status === 'draft') {
      const resumeBtn = el('button', 'djg-btn djg-btn--primary', copy.resumeCartButton);
      resumeBtn.type = 'button';
      resumeBtn.disabled = entry.busy;
      resumeBtn.addEventListener('click', async () => {
        entry.busy = true;
        entry.error = '';
        entry.feedback = '';
        renderPortal();
        try {
          const res = await api.addJobToCart(job.job_uuid);
          entry.job = res.job;
          entry.cartUrl = res.cart_url;
          entry.feedback = copy.addedSuccessTitle;
          entry.busy = false;
          jobs = jobs.map((j) => (j.job_uuid === job.job_uuid ? res.job : j));
          renderPortal();
        } catch (err) {
          entry.busy = false;
          entry.error = err instanceof SkinsApiError ? err.message : copy.errorGeneric;
          renderPortal();
        }
      });
      panel.appendChild(resumeBtn);
    }

    // Revisions List (Artwork + Designer Proofs)
    if (Array.isArray(job.artworks) && job.artworks.length > 0) {
      const revSection = el('div');
      revSection.appendChild(el('h4', 'djg-portal-card__title', copy.revisionsHeading));
      const ul = el('ul', 'djg-revision-list');

      for (const rev of job.artworks) {
        const li = el('li', 'djg-revision-row');
        const left = el('div', 'djg-portal-card__info');
        const typeLabel =
          rev.revision_type === 'designer_proof' ? copy.designerProofTag : copy.customerArtworkTag;
        left.appendChild(el('strong', undefined, `${typeLabel} · v${rev.version_number} · ${rev.original_filename}`));

        const metaRow = el('div', 'djg-portal-card__controls');
        if (rev.dpi_status && rev.dpi_status !== 'not_applicable') {
          const dpiLabel = rev.computed_dpi ? `${rev.dpi_status} (${rev.computed_dpi} DPI)` : rev.dpi_status;
          metaRow.appendChild(el('span', dpiBadgeClass(rev.dpi_status), dpiLabel));
        }
        if (rev.low_dpi_acknowledged) {
          metaRow.appendChild(el('span', 'djg-skin-badge djg-skin-badge--pass', copy.ackConfirmedBadge));
        }
        left.appendChild(metaRow);
        li.appendChild(left);

        if (rev.download_url) {
          const dlBtn = el('button', 'djg-btn djg-btn--secondary', copy.downloadFile);
          dlBtn.type = 'button';
          dlBtn.addEventListener('click', async () => {
            try {
              const blob = await api.downloadRevisionBlob(rev.download_url);
              triggerBlobDownload(blob, rev.original_filename);
            } catch (err) {
              entry.error = err instanceof SkinsApiError ? err.message : copy.errorGeneric;
              renderPortal();
            }
          });
          li.appendChild(dlBtn);
        }

        ul.appendChild(li);
      }
      revSection.appendChild(ul);
      panel.appendChild(revSection);
    }

    // Production Timeline
    if (Array.isArray(job.status_history) && job.status_history.length > 0) {
      const tlSection = el('div');
      tlSection.appendChild(el('h4', 'djg-portal-card__title', copy.timelineHeading));
      const ol = el('ol', 'djg-skin-timeline');

      for (const step of job.status_history) {
        const li = el('li', 'djg-skin-timeline__item');
        li.appendChild(el('span', 'djg-skin-timeline__status', copy.statuses[step.to_status] || step.to_status));
        const ts = el('span', 'djg-skin-timeline__meta', step.created_at);
        ts.setAttribute('dir', 'ltr');
        li.appendChild(ts);
        if (step.comment) {
          li.appendChild(el('span', 'djg-skin-timeline__meta', step.comment));
        }
        ol.appendChild(li);
      }

      tlSection.appendChild(ol);
      panel.appendChild(tlSection);
    }

    return panel;
  }

  function renderPortal(): void {
    const container = el('div');
    container.appendChild(el('h3', 'djg-skin-section__title', copy.myJobsTitle));

    if (jobs.length === 0) {
      container.appendChild(el('p', 'djg-skin-option__meta', copy.myJobsEmpty));
      root.replaceChildren(container);
      return;
    }

    const list = el('div', 'djg-portal-list');

    for (const job of jobs) {
      const card = el('article', 'djg-portal-card');
      card.dataset.jobUuid = job.job_uuid;

      const head = el('div', 'djg-portal-card__head');
      const info = el('div', 'djg-portal-card__info');

      const devName = pickBilingual(lang, job.snapshot.device?.name_fa, job.snapshot.device?.name_en) || '—';
      const varName = [
        pickBilingual(lang, job.snapshot.variant?.model_name_fa, job.snapshot.variant?.model_name_en),
        pickBilingual(lang, job.snapshot.variant?.color_name_fa, job.snapshot.variant?.color_name_en),
      ]
        .filter(Boolean)
        .join(' — ');
      info.appendChild(el('h4', 'djg-portal-card__title', varName ? `${devName} · ${varName}` : devName));

      const planName = pickBilingual(lang, job.snapshot.plan?.name_fa, job.snapshot.plan?.name_en);
      const sub = el('div', 'djg-portal-card__sub');
      const subParts: string[] = [];
      if (planName) {
        subParts.push(planName);
      }
      if (!job.wc_order_id) {
        subParts.push(copy.draftLabel);
      }
      sub.textContent = subParts.join(' · ');

      if (job.wc_order_id) {
        const sep = sub.textContent ? ' · ' : '';
        sub.appendChild(document.createTextNode(`${sep}${copy.orderLabel} `));
        if (job.wc_order_url) {
          const orderLink = el('a', undefined, `#${job.wc_order_id}`);
          orderLink.href = job.wc_order_url;
          sub.appendChild(orderLink);
        } else {
          sub.appendChild(document.createTextNode(`#${job.wc_order_id}`));
        }
      }

      if (job.tracking_code) {
        sub.appendChild(document.createTextNode(` · ${copy.trackingLabel}: `));
        const codeSpan = el('span', undefined, job.tracking_code);
        codeSpan.setAttribute('dir', 'ltr');
        sub.appendChild(codeSpan);
      }

      info.appendChild(sub);
      head.appendChild(info);

      const controls = el('div', 'djg-portal-card__controls');
      controls.appendChild(el('span', statusBadgeClass(job.status), copy.statuses[job.status] || job.status));

      const isExpanded = expanded.has(job.job_uuid);
      const toggleBtn = el('button', 'djg-btn djg-btn--secondary', isExpanded ? copy.hideDetails : copy.viewDetails);
      toggleBtn.type = 'button';
      toggleBtn.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
      toggleBtn.dataset.action = 'toggle-job-details';
      toggleBtn.addEventListener('click', () => {
        if (expanded.has(job.job_uuid)) {
          expanded.delete(job.job_uuid);
          renderPortal();
        } else {
          expanded.set(job.job_uuid, {
            loading: true,
            job,
            comment: '',
            selectedFile: null,
            latestArtwork: null,
            latestDpiReport: null,
            ackChecked: false,
            ackRecorded: false,
            busy: false,
            feedback: '',
            error: '',
            cartUrl: '',
          });
          void refreshJobDetail(job.job_uuid);
        }
      });
      controls.appendChild(toggleBtn);
      head.appendChild(controls);
      card.appendChild(head);

      const detailState = expanded.get(job.job_uuid);
      if (detailState) {
        card.appendChild(renderJobDetails(detailState));
      }

      list.appendChild(card);
    }

    container.appendChild(list);
    root.replaceChildren(container);
  }

  async function boot(): Promise<void> {
    try {
      jobs = await api.listMyJobs();
      renderPortal();
    } catch {
      // Keep the server-rendered fallback HTML intact if REST hydration fails.
    }
  }

  void boot();
}
