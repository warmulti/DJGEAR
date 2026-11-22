import { useCallback, useEffect, useState } from 'react';
import { RefreshCw, Trash2 } from 'lucide-react';
import type { DjgearApi } from '../api/djgearApi';
import { ApiError } from '../api/httpClient';
import { Badge } from '../components/ui/Badge';
import { Card } from '../components/ui/Card';
import { DataTable } from '../components/ui/DataTable';
import { PermissionDeniedState } from '../components/ui/StateFeedback';
import { useI18n } from '../i18n/I18nContext';
import type { CatalogSkinDefinition } from '../types/api';

export function CatalogSkinsPage({ api }: { api: DjgearApi }) {
  const { t, formatNumber } = useI18n();
  const [skins, setSkins] = useState<CatalogSkinDefinition[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [forbidden, setForbidden] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    setForbidden(false);
    try {
      const res = await api.listCatalogSkins();
      setSkins(res.data.items);
    } catch (err) {
      if (err instanceof ApiError && (err.isForbidden() || err.isUnauthorized())) {
        setForbidden(true);
      } else {
        setError(err instanceof Error ? err.message : 'Failed to load skin definitions');
      }
    } finally {
      setLoading(false);
    }
  }, [api]);

  useEffect(() => {
    void load();
  }, [load]);

  if (forbidden) {
    return <PermissionDeniedState capability="djgear_manage_skin_definitions" />;
  }

  return (
    <>
      <div className="djg-page-header">
        <div>
          <h1 className="djg-page-title">{t('catalogSkinsTitle')}</h1>
          <p className="djg-page-subtitle">{t('catalogSkinsSubtitle')}</p>
        </div>
        <button type="button" className="djg-btn" onClick={() => void load()}>
          <RefreshCw size={14} />
          <span>{t('refresh')}</span>
        </button>
      </div>

      <Card>
        <DataTable<CatalogSkinDefinition>
          rows={skins}
          rowKey={(s) => s.id}
          loading={loading}
          error={error}
          onRetry={() => void load()}
          columns={[
            {
              key: 'code',
              header: 'Template',
              render: (s) => (
                <div>
                  <strong className="djg-mono">{s.template_code}</strong>
                  <div className="djg-mono" style={{ fontSize: 12, color: 'var(--djg-text-secondary)' }}>
                    v{s.template_version}
                  </div>
                </div>
              ),
            },
            {
              key: 'status',
              header: t('colStatus'),
              render: (s) => (
                <Badge tone={s.status === 'active' ? 'success' : s.status === 'deprecated' ? 'danger' : 'warning'}>
                  {s.status}
                </Badge>
              ),
            },
            {
              key: 'dims',
              header: 'Dimensions (mm / DPI)',
              render: (s) => (
                <span className="djg-mono">
                  {formatNumber(s.width_mm)} × {formatNumber(s.height_mm)} mm · +{formatNumber(s.bleed_mm)}mm · {formatNumber(s.min_dpi)} DPI
                </span>
              ),
            },
            {
              key: 'devices',
              header: t('navCatalogDevices'),
              render: (s) => <Badge tone="info">{formatNumber(s.compatible_device_ids.length)} device(s)</Badge>,
            },
            {
              key: 'finishes',
              header: 'Finishes',
              render: (s) => s.supported_finishes.join(', '),
            },
            {
              key: 'actions',
              header: t('colActions'),
              render: (s) => (
                <button
                  type="button"
                  className="djg-btn"
                  onClick={() => void api.deleteCatalogSkin(s.id).then(() => load())}
                >
                  <Trash2 size={13} />
                </button>
              ),
            },
          ]}
        />
      </Card>
    </>
  );
}
