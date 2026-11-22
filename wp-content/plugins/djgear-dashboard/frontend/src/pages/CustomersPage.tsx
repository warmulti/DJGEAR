import { useCallback, useEffect, useState } from 'react';
import { ExternalLink, RefreshCw } from 'lucide-react';
import type { DjgearApi } from '../api/djgearApi';
import { ApiError } from '../api/httpClient';
import { Badge } from '../components/ui/Badge';
import { Card } from '../components/ui/Card';
import { DataTable } from '../components/ui/DataTable';
import { PermissionDeniedState, PlannedModuleBanner } from '../components/ui/StateFeedback';
import { useI18n } from '../i18n/I18nContext';
import type { CustomerItem } from '../types/api';

export function CustomersPage({ api }: { api: DjgearApi }) {
  const { t, formatNumber } = useI18n();
  const [items, setItems] = useState<CustomerItem[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [forbidden, setForbidden] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    setForbidden(false);
    try {
      const res = await api.getCustomers({ page, per_page: 10, search });
      setItems(res.data.items);
      setTotal(res.meta?.total ?? res.data.items.length);
    } catch (err) {
      if (err instanceof ApiError && (err.isForbidden() || err.isUnauthorized())) {
        setForbidden(true);
      } else {
        setError(err instanceof Error ? err.message : 'Failed to load customers');
      }
    } finally {
      setLoading(false);
    }
  }, [api, page, search]);

  useEffect(() => {
    void load();
  }, [load]);

  if (forbidden) {
    return <PermissionDeniedState capability="list_users" />;
  }

  return (
    <>
      <div className="djg-page-header">
        <div>
          <h1 className="djg-page-title">{t('customersTitle')}</h1>
          <p className="djg-page-subtitle">{t('customersSubtitle')}</p>
        </div>
        <button type="button" className="djg-btn" onClick={() => void load()}>
          <RefreshCw size={14} />
          <span>{t('refresh')}</span>
        </button>
      </div>

      <PlannedModuleBanner
        targetModuleId="djgear-crm"
        contractSummary={t('crmRoadmapNote')}
      />

      <Card>
        <div className="djg-toolbar">
          <input
            type="search"
            className="djg-input"
            placeholder={t('searchPlaceholder')}
            value={search}
            onChange={(e) => {
              setPage(1);
              setSearch(e.target.value);
            }}
          />
        </div>

        <DataTable<CustomerItem>
          rows={items}
          rowKey={(row) => row.id}
          loading={loading}
          error={error}
          onRetry={() => void load()}
          columns={[
            { key: 'user', header: t('colUser'), render: (row) => <strong>{row.display_name}</strong> },
            { key: 'email', header: t('colEmail'), render: (row) => <span className="djg-mono">{row.email}</span> },
            {
              key: 'roles',
              header: t('colRoles'),
              render: (row) => (
                <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                  {row.roles.map((r) => (
                    <Badge key={r} tone="info">
                      {r}
                    </Badge>
                  ))}
                </div>
              ),
            },
            {
              key: 'orders',
              header: t('colOrdersCount'),
              render: (row) => formatNumber(row.order_count),
            },
            {
              key: 'spent',
              header: t('colTotalSpent'),
              render: (row) => formatNumber(row.total_spent),
            },
            {
              key: 'actions',
              header: t('colActions'),
              render: (row) => (
                <a className="djg-btn" href={row.edit_url}>
                  <span>{t('editInWpAdmin')}</span>
                  <ExternalLink size={13} />
                </a>
              ),
            },
          ]}
        />

        <div className="djg-pagination">
          <span>
            {t('totalItems')}: {formatNumber(total)}
          </span>
          <div style={{ display: 'flex', gap: 8 }}>
            <button type="button" className="djg-btn" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
              {t('previous')}
            </button>
            <button
              type="button"
              className="djg-btn"
              disabled={page * 10 >= total}
              onClick={() => setPage((p) => p + 1)}
            >
              {t('next')}
            </button>
          </div>
        </div>
      </Card>
    </>
  );
}
