import { useCallback, useEffect, useState } from 'react';
import { RefreshCw } from 'lucide-react';
import type { DjgearApi } from '../api/djgearApi';
import { ApiError } from '../api/httpClient';
import { Badge } from '../components/ui/Badge';
import { Card } from '../components/ui/Card';
import { DataTable } from '../components/ui/DataTable';
import { ErrorState, LoadingState, PermissionDeniedState, PlannedModuleBanner } from '../components/ui/StateFeedback';
import { useI18n } from '../i18n/I18nContext';
import type { RouteDefinition } from '../navigation/routes';
import type { AuditLogItem, HealthData } from '../types/api';

export interface ModuleHubPageProps {
  api: DjgearApi;
  route: RouteDefinition;
  canViewLogs: boolean;
}

/**
 * Honest hub for planned Phase 3+ modules (SMS, Email, Forms, SEO, Analytics, Payments, Backup & Security).
 * Displays only REAL djgear-core contract & audit telemetry and clearly marks unimplemented functionality.
 */
export function ModuleHubPage({ api, route, canViewLogs }: ModuleHubPageProps) {
  const { t } = useI18n();
  const [health, setHealth] = useState<HealthData | null>(null);
  const [auditLogs, setAuditLogs] = useState<AuditLogItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [forbidden, setForbidden] = useState(false);

  const isBackupSecurity = route.id === 'backup';

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    setForbidden(false);
    try {
      const healthRes = await api.getHealth();
      setHealth(healthRes.data);

      if (isBackupSecurity && canViewLogs) {
        const logsRes = await api.getAuditLogs({ page: 1, per_page: 20 });
        setAuditLogs(logsRes.data.items);
      } else {
        setAuditLogs([]);
      }
    } catch (err) {
      if (err instanceof ApiError && (err.isForbidden() || err.isUnauthorized())) {
        setForbidden(true);
      } else {
        setError(err instanceof Error ? err.message : 'Failed to load core diagnostics');
      }
    } finally {
      setLoading(false);
    }
  }, [api, isBackupSecurity, canViewLogs]);

  useEffect(() => {
    void load();
  }, [load]);

  if (loading) {
    return <LoadingState />;
  }

  if (forbidden) {
    return <PermissionDeniedState capability={route.requiredCapability} />;
  }

  if (error || !health) {
    return <ErrorState message={error ?? 'Diagnostics unavailable'} onRetry={() => void load()} />;
  }

  const providerBucket =
    route.id === 'sms'
      ? health.providers.sms
      : route.id === 'email'
        ? health.providers.email
        : route.id === 'payments'
          ? health.providers.payment
          : null;

  return (
    <>
      <div className="djg-page-header">
        <div>
          <h1 className="djg-page-title">{t(route.labelKey)}</h1>
          <p className="djg-page-subtitle">
            {t('targetModuleId')}: <code className="djg-mono">{route.targetModuleId ?? route.id}</code>
          </p>
        </div>
        <button type="button" className="djg-btn" onClick={() => void load()}>
          <RefreshCw size={14} />
          <span>{t('refresh')}</span>
        </button>
      </div>

      <PlannedModuleBanner
        targetModuleId={route.targetModuleId ?? `djgear-${route.id}`}
        contractSummary={route.contractSummary}
      />

      <Card title={t('coreContractsReady')} action={<Badge tone="info">djgear/v1</Badge>}>
        <div className="djg-kv-list">
          <div className="djg-kv-row">
            <span>{t('targetModuleId')}</span>
            <code className="djg-mono">{route.targetModuleId ?? route.id}</code>
          </div>
          <div className="djg-kv-row">
            <span>{t('environment')}</span>
            <Badge tone="info">{health.core.environment}</Badge>
          </div>
          {providerBucket !== null && (
            <div className="djg-kv-row">
              <span>{t('registeredProviders')}</span>
              <span>
                {providerBucket.length > 0 ? providerBucket.join(', ') : t('noneRegisteredYet')}
              </span>
            </div>
          )}
          <div className="djg-kv-row">
            <span>{t('sourceOfTruth')}</span>
            <span>{t('sourceOfTruthValue')}</span>
          </div>
        </div>
      </Card>

      {isBackupSecurity && (
        <Card title={t('auditTrailSection')} action={<Badge tone="success">{t('liveBadge')}</Badge>}>
          {!canViewLogs ? (
            <PermissionDeniedState capability="djgear_view_logs" />
          ) : (
            <DataTable<AuditLogItem>
              rows={auditLogs}
              rowKey={(row) => row.id ?? `${row.action}-${row.created_at}`}
              columns={[
                { key: 'action', header: t('colAction'), render: (r) => <code className="djg-mono">{r.action}</code> },
                {
                  key: 'resource',
                  header: t('colResource'),
                  render: (r) => `${r.resource_type}${r.resource_id ? `:${r.resource_id}` : ''}`,
                },
                { key: 'actor', header: t('colActor'), render: (r) => (r.actor_id !== null ? String(r.actor_id) : 'system') },
                { key: 'ip', header: t('colIp'), render: (r) => <span className="djg-mono">{r.ip_address || '—'}</span> },
                { key: 'ts', header: t('colTimestamp'), render: (r) => <span className="djg-mono">{r.created_at ?? '—'}</span> },
              ]}
            />
          )}
        </Card>
      )}
    </>
  );
}
