import { useCallback, useEffect, useState } from 'react';
import { Blocks, Package, RefreshCw, ShoppingCart, Users } from 'lucide-react';
import type { DjgearApi } from '../api/djgearApi';
import { ApiError } from '../api/httpClient';
import { Badge } from '../components/ui/Badge';
import { Card, KpiCard } from '../components/ui/Card';
import { DataTable } from '../components/ui/DataTable';
import { ErrorState, LoadingState, PermissionDeniedState } from '../components/ui/StateFeedback';
import { useI18n } from '../i18n/I18nContext';
import type { AuditLogItem, HealthData, OverviewData } from '../types/api';

export interface OverviewPageProps {
  api: DjgearApi;
}

export function OverviewPage({ api }: OverviewPageProps) {
  const { t, formatNumber } = useI18n();
  const [health, setHealth] = useState<HealthData | null>(null);
  const [overview, setOverview] = useState<OverviewData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [forbidden, setForbidden] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    setForbidden(false);
    try {
      const [hRes, oRes] = await Promise.all([api.getHealth(), api.getOverview()]);
      setHealth(hRes.data);
      setOverview(oRes.data);
    } catch (err) {
      if (err instanceof ApiError && (err.isForbidden() || err.isUnauthorized())) {
        setForbidden(true);
      } else {
        setError(err instanceof Error ? err.message : 'Unexpected error');
      }
    } finally {
      setLoading(false);
    }
  }, [api]);

  useEffect(() => {
    void load();
  }, [load]);

  if (loading) {
    return <LoadingState />;
  }

  if (forbidden) {
    return <PermissionDeniedState capability="djgear_view_dashboard" />;
  }

  if (error || !health || !overview) {
    return <ErrorState message={error ?? 'No health data received'} onRetry={() => void load()} />;
  }

  return (
    <>
      <div className="djg-page-header">
        <div>
          <h1 className="djg-page-title">{t('overviewTitle')}</h1>
          <p className="djg-page-subtitle">{t('overviewSubtitle')}</p>
        </div>
        <button type="button" className="djg-btn" onClick={() => void load()}>
          <RefreshCw size={14} />
          <span>{t('refresh')}</span>
        </button>
      </div>

      <div className="djg-kpi-grid">
        <KpiCard
          label={t('kpiProducts')}
          value={formatNumber(overview.commerce.products_total)}
          meta={`${t('activeBadge')}: ${formatNumber(overview.commerce.products_published)}`}
          icon={<Package size={18} color="#818cf8" />}
        />
        <KpiCard
          label={t('kpiOrders')}
          value={formatNumber(overview.commerce.orders_total)}
          meta={`${formatNumber(overview.commerce.orders_processing)} / ${formatNumber(overview.commerce.orders_completed)}`}
          icon={<ShoppingCart size={18} color="#22d3ee" />}
        />
        <KpiCard
          label={t('kpiCustomers')}
          value={formatNumber(overview.commerce.users_total)}
          meta={`${t('navCustomers')}: ${formatNumber(overview.commerce.customers_total)}`}
          icon={<Users size={18} color="#34d399" />}
        />
        <KpiCard
          label={t('kpiModules')}
          value={formatNumber(overview.modules.registered_count)}
          meta={overview.modules.booted_ids.join(', ') || '—'}
          icon={<Blocks size={18} color="#fbbf24" />}
        />
      </div>

      <div className="djg-two-col">
        <Card
          title={t('systemHealthCard')}
          action={<Badge tone="success">{t('healthyBadge')}</Badge>}
        >
          <div className="djg-kv-list">
            <div className="djg-kv-row">
              <span>{t('wordPressVersion')}</span>
              <strong className="djg-mono">{health.core.wp_version}</strong>
            </div>
            <div className="djg-kv-row">
              <span>{t('wooCommerceVersion')}</span>
              <span style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                <strong className="djg-mono">{health.woocommerce.version ?? '—'}</strong>
                {health.woocommerce.hpos_compatible && (
                  <Badge tone="success">{t('hposCompatible')}</Badge>
                )}
              </span>
            </div>
            <div className="djg-kv-row">
              <span>{t('phpVersion')}</span>
              <strong className="djg-mono">{health.core.php_version}</strong>
            </div>
            <div className="djg-kv-row">
              <span>{t('environment')}</span>
              <Badge tone="info">{health.core.environment}</Badge>
            </div>
            <div className="djg-kv-row">
              <span>{t('objectCache')}</span>
              <Badge tone={health.object_cache.external ? 'success' : 'warning'}>
                {health.object_cache.external ? t('redisActive') : t('redisInactive')}
              </Badge>
            </div>
            <div className="djg-kv-row">
              <span>{t('sourceOfTruth')}</span>
              <span>{t('sourceOfTruthValue')}</span>
            </div>
          </div>
        </Card>

        <Card
          title={t('recentAuditCard')}
          action={
            <Badge tone="neutral">
              {t('totalItems')}: {formatNumber(overview.recent_audit.total)}
            </Badge>
          }
        >
          <DataTable<AuditLogItem>
            rows={overview.recent_audit.items}
            rowKey={(item) => item.id ?? `${item.action}-${item.created_at}`}
            columns={[
              {
                key: 'action',
                header: t('colAction'),
                render: (row) => <code className="djg-mono">{row.action}</code>,
              },
              {
                key: 'resource',
                header: t('colResource'),
                render: (row) => `${row.resource_type}${row.resource_id ? `:${row.resource_id}` : ''}`,
              },
              {
                key: 'created',
                header: t('colTimestamp'),
                render: (row) => <span className="djg-mono">{row.created_at ?? '—'}</span>,
              },
            ]}
          />
        </Card>
      </div>
    </>
  );
}
