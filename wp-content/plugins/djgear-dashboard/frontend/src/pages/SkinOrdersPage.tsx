import { useCallback, useEffect, useState } from 'react';
import { RefreshCw } from 'lucide-react';
import type { DjgearApi } from '../api/djgearApi';
import { ApiError } from '../api/httpClient';
import { Badge } from '../components/ui/Badge';
import { Card } from '../components/ui/Card';
import { DataTable } from '../components/ui/DataTable';
import { PermissionDeniedState } from '../components/ui/StateFeedback';
import { useI18n } from '../i18n/I18nContext';
import type { SkinOrderJob } from '../types/api';

const QUEUE_FILTERS = [
  '',
  'submitted_for_review',
  'in_design_review',
  'proof_sent',
  'approved_for_production',
  'queued_for_production',
  'in_production',
  'quality_check',
  'packed',
  'shipped',
  'completed',
];

export function SkinOrdersPage({ api, canManage }: { api: DjgearApi; canManage: boolean }) {
  const { t, locale, formatNumber } = useI18n();
  const [jobs, setJobs] = useState<SkinOrderJob[]>([]);
  const [statusFilter, setStatusFilter] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [forbidden, setForbidden] = useState(false);
  const [trackingByJob, setTrackingByJob] = useState<Record<number, string>>({});

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    setForbidden(false);
    try {
      const res = await api.listSkinJobs(statusFilter ? { status: statusFilter } : undefined);
      setJobs(res.data.items);
    } catch (err) {
      if (err instanceof ApiError && (err.isForbidden() || err.isUnauthorized())) {
        setForbidden(true);
      } else {
        setError(err instanceof Error ? err.message : 'Failed to load skin orders');
      }
    } finally {
      setLoading(false);
    }
  }, [api, statusFilter]);

  useEffect(() => {
    void load();
  }, [load]);

  const handleTransition = async (job: SkinOrderJob, nextStatus: string) => {
    if (!nextStatus) {
      return;
    }
    try {
      await api.transitionSkinJob(job.id, {
        to_status: nextStatus,
        tracking_code: trackingByJob[job.id] || undefined,
      });
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to update job status');
    }
  };

  if (forbidden) {
    return <PermissionDeniedState capability="djgear_view_skin_orders" />;
  }

  return (
    <>
      <div className="djg-page-header">
        <div>
          <h1 className="djg-page-title">{t('skinOrdersTitle')}</h1>
          <p className="djg-page-subtitle">{t('skinOrdersSubtitle')}</p>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <select className="djg-input" value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)} aria-label={t('colStatus')}>
            {QUEUE_FILTERS.map((st) => (
              <option key={st || 'all'} value={st}>
                {st || t('allStatuses')}
              </option>
            ))}
          </select>
          <button type="button" className="djg-btn" onClick={() => void load()}>
            <RefreshCw size={14} />
            <span>{t('refresh')}</span>
          </button>
        </div>
      </div>

      <Card>
        <DataTable<SkinOrderJob>
          rows={jobs}
          rowKey={(j) => j.id}
          loading={loading}
          error={error}
          onRetry={() => void load()}
          columns={[
            {
              key: 'device',
              header: 'Device & Skin',
              render: (j) => {
                const dev = j.snapshot.device;
                const variant = j.snapshot.variant;
                const devName = locale === 'fa_IR' ? (dev?.name_fa || dev?.name_en || `#${j.device_id}`) : (dev?.name_en || dev?.name_fa || `#${j.device_id}`);
                const modelName = locale === 'fa_IR' ? (variant?.model_name_fa || variant?.model_name_en || '') : (variant?.model_name_en || variant?.model_name_fa || '');
                const colorName = locale === 'fa_IR' ? (variant?.color_name_fa || variant?.color_name_en || '') : (variant?.color_name_en || variant?.color_name_fa || '');
                return (
                  <div>
                    <strong>{devName}</strong>
                    <div style={{ fontSize: 12, color: 'var(--djg-text-secondary)' }}>
                      {modelName} · {colorName}
                    </div>
                  </div>
                );
              },
            },
            {
              key: 'plan',
              header: 'Plan & Order',
              render: (j) => {
                const plan = j.snapshot.plan;
                const planName = locale === 'fa_IR' ? (plan?.name_fa || plan?.name_en || '') : (plan?.name_en || plan?.name_fa || '');
                return (
                  <div>
                    <div>{planName}</div>
                    <div className="djg-mono" style={{ fontSize: 12, color: 'var(--djg-text-secondary)' }}>
                      {j.wc_order_id ? `WC #${formatNumber(j.wc_order_id)}` : 'Draft'} · {j.customer_name || `#${j.customer_user_id}`}
                    </div>
                  </div>
                );
              },
            },
            {
              key: 'status',
              header: t('colStatus'),
              render: (j) => (
                <div>
                  <Badge tone={j.status === 'completed' || j.status === 'shipped' ? 'success' : j.status === 'cancelled' || j.status === 'on_hold' ? 'danger' : 'info'}>
                    {j.status}
                  </Badge>
                  {j.tracking_code && (
                    <div className="djg-mono" style={{ fontSize: 11, marginTop: 4 }}>
                      {j.tracking_code}
                    </div>
                  )}
                </div>
              ),
            },
            {
              key: 'actions',
              header: t('colActions'),
              render: (j) =>
                canManage && j.allowed_next.length > 0 ? (
                  <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                    <input
                      className="djg-input"
                      style={{ maxWidth: 150 }}
                      placeholder={t('trackingPlaceholder')}
                      value={trackingByJob[j.id] ?? j.tracking_code ?? ''}
                      onChange={(e) => setTrackingByJob((prev) => ({ ...prev, [j.id]: e.target.value }))}
                    />
                    <select
                      className="djg-input"
                      defaultValue=""
                      aria-label={t('advanceStatus')}
                      onChange={(e) => void handleTransition(j, e.target.value)}
                    >
                      <option value="">{t('advanceStatus')}...</option>
                      {j.allowed_next.map((next) => (
                        <option key={next} value={next}>
                          {next}
                        </option>
                      ))}
                    </select>
                  </div>
                ) : (
                  <span style={{ color: 'var(--djg-text-secondary)', fontSize: 12 }}>—</span>
                ),
            },
          ]}
        />
      </Card>
    </>
  );
}
