import { useCallback, useEffect, useState } from 'react';
import { RefreshCw } from 'lucide-react';
import type { DjgearApi } from '../api/djgearApi';
import { ApiError } from '../api/httpClient';
import { Badge } from '../components/ui/Badge';
import { Card } from '../components/ui/Card';
import { DataTable } from '../components/ui/DataTable';
import { PermissionDeniedState } from '../components/ui/StateFeedback';
import { useI18n } from '../i18n/I18nContext';
import type { ModuleItem } from '../types/api';

export function ModulesPage({ api }: { api: DjgearApi }) {
  const { t } = useI18n();
  const [modules, setModules] = useState<ModuleItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [forbidden, setForbidden] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    setForbidden(false);
    try {
      const res = await api.getModules();
      setModules(res.data.modules);
    } catch (err) {
      if (err instanceof ApiError && (err.isForbidden() || err.isUnauthorized())) {
        setForbidden(true);
      } else {
        setError(err instanceof Error ? err.message : 'Failed to load modules');
      }
    } finally {
      setLoading(false);
    }
  }, [api]);

  useEffect(() => {
    void load();
  }, [load]);

  if (forbidden) {
    return <PermissionDeniedState capability="djgear_manage_modules" />;
  }

  return (
    <>
      <div className="djg-page-header">
        <div>
          <h1 className="djg-page-title">{t('modulesTitle')}</h1>
          <p className="djg-page-subtitle">{t('modulesSubtitle')}</p>
        </div>
        <button type="button" className="djg-btn" onClick={() => void load()}>
          <RefreshCw size={14} />
          <span>{t('refresh')}</span>
        </button>
      </div>

      <Card>
        <DataTable<ModuleItem>
          rows={modules}
          rowKey={(row) => row.id}
          loading={loading}
          error={error}
          onRetry={() => void load()}
          columns={[
            {
              key: 'name',
              header: t('colModule'),
              render: (row) => (
                <div>
                  <strong>{row.name}</strong>
                  <div className="djg-mono" style={{ fontSize: 12, color: 'var(--djg-text-secondary)' }}>
                    {row.id}
                  </div>
                </div>
              ),
            },
            { key: 'version', header: t('colVersion'), render: (row) => <code className="djg-mono">{row.version}</code> },
            {
              key: 'deps',
              header: t('colDependencies'),
              render: (row) => (row.dependencies.length > 0 ? row.dependencies.join(', ') : '—'),
            },
            {
              key: 'state',
              header: t('colState'),
              render: (row) => (
                <div style={{ display: 'flex', gap: 6 }}>
                  <Badge tone={row.enabled ? 'success' : 'neutral'}>
                    {row.enabled ? t('activeBadge') : t('disabledBadge')}
                  </Badge>
                  {row.booted && <Badge tone="info">{t('bootedBadge')}</Badge>}
                </div>
              ),
            },
          ]}
        />
      </Card>
    </>
  );
}
