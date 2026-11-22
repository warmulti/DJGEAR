import { useCallback, useEffect, useState } from 'react';
import { ExternalLink, RefreshCw } from 'lucide-react';
import type { DjgearApi } from '../api/djgearApi';
import { ApiError } from '../api/httpClient';
import { Badge } from '../components/ui/Badge';
import { Card } from '../components/ui/Card';
import { DataTable } from '../components/ui/DataTable';
import { PermissionDeniedState } from '../components/ui/StateFeedback';
import { useI18n } from '../i18n/I18nContext';
import type { ProductItem } from '../types/api';

export function ProductsPage({ api }: { api: DjgearApi }) {
  const { t, formatNumber } = useI18n();
  const [items, setItems] = useState<ProductItem[]>([]);
  const [currency, setCurrency] = useState('');
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [forbidden, setForbidden] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    setForbidden(false);
    try {
      const res = await api.getProducts({ page, per_page: 10, search, status });
      setItems(res.data.items);
      setCurrency(res.data.currency);
      setTotal(res.meta?.total ?? res.data.items.length);
    } catch (err) {
      if (err instanceof ApiError && (err.isForbidden() || err.isUnauthorized())) {
        setForbidden(true);
      } else {
        setError(err instanceof Error ? err.message : 'Failed to load products');
      }
    } finally {
      setLoading(false);
    }
  }, [api, page, search, status]);

  useEffect(() => {
    void load();
  }, [load]);

  if (forbidden) {
    return <PermissionDeniedState capability="edit_products" />;
  }

  return (
    <>
      <div className="djg-page-header">
        <div>
          <h1 className="djg-page-title">{t('productsTitle')}</h1>
          <p className="djg-page-subtitle">{t('productsSubtitle')}</p>
        </div>
        <button type="button" className="djg-btn" onClick={() => void load()}>
          <RefreshCw size={14} />
          <span>{t('refresh')}</span>
        </button>
      </div>

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
          <select
            className="djg-select"
            value={status}
            onChange={(e) => {
              setPage(1);
              setStatus(e.target.value);
            }}
          >
            <option value="">{t('allStatuses')}</option>
            <option value="publish">publish</option>
            <option value="draft">draft</option>
            <option value="pending">pending</option>
            <option value="private">private</option>
          </select>
        </div>

        <DataTable<ProductItem>
          rows={items}
          rowKey={(row) => row.id}
          loading={loading}
          error={error}
          onRetry={() => void load()}
          columns={[
            { key: 'name', header: t('colProduct'), render: (row) => <strong>{row.name}</strong> },
            { key: 'sku', header: t('colSku'), render: (row) => <code className="djg-mono">{row.sku || '—'}</code> },
            {
              key: 'status',
              header: t('colStatus'),
              render: (row) => <Badge tone={row.status === 'publish' ? 'success' : 'neutral'}>{row.status}</Badge>,
            },
            {
              key: 'price',
              header: t('colPrice'),
              render: (row) => (row.price ? `${formatNumber(row.price)} ${currency}` : '—'),
            },
            {
              key: 'stock',
              header: t('colStock'),
              render: (row) => (
                <Badge tone={row.stock_status === 'instock' ? 'success' : 'warning'}>
                  {row.stock_status}
                  {row.stock_quantity !== null ? ` (${formatNumber(row.stock_quantity)})` : ''}
                </Badge>
              ),
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
