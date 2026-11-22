import { useCallback, useEffect, useState } from 'react';
import { ExternalLink, RefreshCw } from 'lucide-react';
import type { DjgearApi } from '../api/djgearApi';
import { ApiError } from '../api/httpClient';
import { Badge, type BadgeTone } from '../components/ui/Badge';
import { Card } from '../components/ui/Card';
import { DataTable } from '../components/ui/DataTable';
import { PermissionDeniedState } from '../components/ui/StateFeedback';
import { useI18n } from '../i18n/I18nContext';
import type { OrderItem } from '../types/api';

function orderStatusTone(status: string): BadgeTone {
  if (status === 'completed') return 'success';
  if (status === 'processing' || status === 'on-hold') return 'info';
  if (status === 'failed' || status === 'cancelled') return 'danger';
  return 'warning';
}

export function OrdersPage({ api }: { api: DjgearApi }) {
  const { t, formatNumber } = useI18n();
  const [items, setItems] = useState<OrderItem[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [forbidden, setForbidden] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    setForbidden(false);
    try {
      const res = await api.getOrders({ page, per_page: 10, status });
      setItems(res.data.items);
      setTotal(res.meta?.total ?? res.data.items.length);
    } catch (err) {
      if (err instanceof ApiError && (err.isForbidden() || err.isUnauthorized())) {
        setForbidden(true);
      } else {
        setError(err instanceof Error ? err.message : 'Failed to load orders');
      }
    } finally {
      setLoading(false);
    }
  }, [api, page, status]);

  useEffect(() => {
    void load();
  }, [load]);

  if (forbidden) {
    return <PermissionDeniedState capability="edit_shop_orders" />;
  }

  return (
    <>
      <div className="djg-page-header">
        <div>
          <h1 className="djg-page-title">{t('ordersTitle')}</h1>
          <p className="djg-page-subtitle">{t('ordersSubtitle')}</p>
        </div>
        <button type="button" className="djg-btn" onClick={() => void load()}>
          <RefreshCw size={14} />
          <span>{t('refresh')}</span>
        </button>
      </div>

      <Card>
        <div className="djg-toolbar">
          <select
            className="djg-select"
            value={status}
            onChange={(e) => {
              setPage(1);
              setStatus(e.target.value);
            }}
          >
            <option value="">{t('allStatuses')}</option>
            <option value="pending">pending</option>
            <option value="processing">processing</option>
            <option value="on-hold">on-hold</option>
            <option value="completed">completed</option>
            <option value="cancelled">cancelled</option>
            <option value="refunded">refunded</option>
            <option value="failed">failed</option>
          </select>
        </div>

        <DataTable<OrderItem>
          rows={items}
          rowKey={(row) => row.id}
          loading={loading}
          error={error}
          onRetry={() => void load()}
          columns={[
            {
              key: 'order',
              header: t('colOrder'),
              render: (row) => <strong className="djg-mono">#{formatNumber(row.order_number)}</strong>,
            },
            {
              key: 'customer',
              header: t('colCustomer'),
              render: (row) => (
                <div>
                  <div>{row.customer_name}</div>
                  {row.customer_email && (
                    <div style={{ fontSize: 12, color: 'var(--djg-text-secondary)' }}>{row.customer_email}</div>
                  )}
                </div>
              ),
            },
            {
              key: 'status',
              header: t('colStatus'),
              render: (row) => <Badge tone={orderStatusTone(row.status)}>{row.status}</Badge>,
            },
            {
              key: 'items',
              header: t('colItems'),
              render: (row) => formatNumber(row.item_count),
            },
            {
              key: 'total',
              header: t('colTotal'),
              render: (row) => `${formatNumber(row.total)} ${row.currency}`,
            },
            {
              key: 'date',
              header: t('colDate'),
              render: (row) => <span className="djg-mono">{row.created_at ?? '—'}</span>,
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
