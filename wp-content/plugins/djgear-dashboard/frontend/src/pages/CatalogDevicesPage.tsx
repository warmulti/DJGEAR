import React, { useCallback, useEffect, useState } from 'react';
import { Plus, RefreshCw, Trash2 } from 'lucide-react';
import type { DjgearApi } from '../api/djgearApi';
import { ApiError } from '../api/httpClient';
import { Badge } from '../components/ui/Badge';
import { Card } from '../components/ui/Card';
import { DataTable } from '../components/ui/DataTable';
import { PermissionDeniedState } from '../components/ui/StateFeedback';
import { useI18n } from '../i18n/I18nContext';
import type { CatalogBrand, CatalogCategory, CatalogDevice } from '../types/api';

export interface CatalogDevicesPageProps {
  api: DjgearApi;
  canManage: boolean;
}

export function CatalogDevicesPage({ api, canManage }: CatalogDevicesPageProps) {
  const { t, isRtl, formatNumber } = useI18n();
  const [devices, setDevices] = useState<CatalogDevice[]>([]);
  const [brands, setBrands] = useState<CatalogBrand[]>([]);
  const [categories, setCategories] = useState<CatalogCategory[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [q, setQ] = useState('');
  const [brandFilter, setBrandFilter] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [forbidden, setForbidden] = useState(false);

  // Quick-create state
  const [showCreate, setShowCreate] = useState(false);
  const [brandId, setBrandId] = useState('');
  const [catId, setCatId] = useState('');
  const [modelCode, setModelCode] = useState('');
  const [nameEn, setNameEn] = useState('');
  const [nameFa, setNameFa] = useState('');
  const [submitErr, setSubmitErr] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    setForbidden(false);
    try {
      const [devRes, brRes, catRes] = await Promise.all([
        api.listCatalogDevices({ q, brand: brandFilter, status: statusFilter, page, per_page: 10 }),
        api.listCatalogBrands(),
        api.listCatalogCategories(),
      ]);
      setDevices(devRes.data.items);
      setTotal(devRes.meta?.total ?? devRes.data.items.length);
      setBrands(brRes.data.items);
      setCategories(catRes.data.items);
    } catch (err) {
      if (err instanceof ApiError && (err.isForbidden() || err.isUnauthorized())) {
        setForbidden(true);
      } else {
        setError(err instanceof Error ? err.message : 'Failed to load device catalog');
      }
    } finally {
      setLoading(false);
    }
  }, [api, q, brandFilter, statusFilter, page]);

  useEffect(() => {
    void load();
  }, [load]);

  const handleCreate = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitErr(null);
    try {
      await api.createCatalogDevice({
        brand_term_id: Number(brandId),
        category_term_id: Number(catId),
        model_code: modelCode,
        name_en: nameEn,
        name_fa: nameFa || nameEn,
        lifecycle_status: 'active',
      });
      setModelCode('');
      setNameEn('');
      setNameFa('');
      setShowCreate(false);
      await load();
    } catch (err) {
      setSubmitErr(err instanceof Error ? err.message : 'Failed to create device');
    }
  };

  const handleDelete = async (id: number) => {
    try {
      await api.deleteCatalogDevice(id);
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Delete failed');
    }
  };

  if (forbidden) {
    return <PermissionDeniedState capability="djgear_view_catalog" />;
  }

  return (
    <>
      <div className="djg-page-header">
        <div>
          <h1 className="djg-page-title">{t('catalogDevicesTitle')}</h1>
          <p className="djg-page-subtitle">{t('catalogDevicesSubtitle')}</p>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          {canManage && (
            <button type="button" className="djg-btn djg-btn-primary" onClick={() => setShowCreate((v) => !v)}>
              <Plus size={14} />
              <span>{t('createLabel')}</span>
            </button>
          )}
          <button type="button" className="djg-btn" onClick={() => void load()}>
            <RefreshCw size={14} />
            <span>{t('refresh')}</span>
          </button>
        </div>
      </div>

      {showCreate && canManage && (
        <Card title={t('createLabel')}>
          <form onSubmit={(e) => void handleCreate(e)} className="djg-form-grid">
            <select className="djg-select" value={brandId} onChange={(e) => setBrandId(e.target.value)} required>
              <option value="">{t('brandsHeading')}</option>
              {brands.map((b) => (
                <option key={b.id} value={b.id}>
                  {isRtl ? b.name_fa : b.name_en}
                </option>
              ))}
            </select>
            <select className="djg-select" value={catId} onChange={(e) => setCatId(e.target.value)} required>
              <option value="">{t('categoriesHeading')}</option>
              {categories.map((c) => (
                <option key={c.id} value={c.id}>
                  {isRtl ? c.name_fa : c.name_en}
                </option>
              ))}
            </select>
            <input
              className="djg-input"
              placeholder="Model Code (e.g. DDJ-FLX4)"
              value={modelCode}
              onChange={(e) => setModelCode(e.target.value)}
              required
            />
            <input
              className="djg-input"
              placeholder="Name (EN)"
              value={nameEn}
              onChange={(e) => setNameEn(e.target.value)}
              required
            />
            <input
              className="djg-input"
              placeholder="نام فارسی (FA)"
              value={nameFa}
              onChange={(e) => setNameFa(e.target.value)}
            />
            <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
              <button type="submit" className="djg-btn djg-btn-primary">
                {t('createLabel')}
              </button>
              {submitErr && <span style={{ color: 'var(--djg-danger)', fontSize: 12.5 }}>{submitErr}</span>}
            </div>
          </form>
        </Card>
      )}

      <Card>
        <div className="djg-toolbar">
          <input
            type="search"
            className="djg-input"
            placeholder={t('searchPlaceholder')}
            value={q}
            onChange={(e) => {
              setPage(1);
              setQ(e.target.value);
            }}
          />
          <select
            className="djg-select"
            value={brandFilter}
            onChange={(e) => {
              setPage(1);
              setBrandFilter(e.target.value);
            }}
          >
            <option value="">{t('brandsHeading')}</option>
            {brands.map((b) => (
              <option key={b.id} value={b.slug}>
                {isRtl ? b.name_fa : b.name_en}
              </option>
            ))}
          </select>
          <select
            className="djg-select"
            value={statusFilter}
            onChange={(e) => {
              setPage(1);
              setStatusFilter(e.target.value);
            }}
          >
            <option value="">{t('allStatuses')}</option>
            <option value="active">active</option>
            <option value="draft">draft</option>
            <option value="upcoming">upcoming</option>
            <option value="discontinued">discontinued</option>
          </select>
        </div>

        <DataTable<CatalogDevice>
          rows={devices}
          rowKey={(row) => row.id}
          loading={loading}
          error={error}
          onRetry={() => void load()}
          columns={[
            {
              key: 'model',
              header: t('navCatalogDevices'),
              render: (row) => (
                <div>
                  <strong>{isRtl ? row.name_fa : row.name_en}</strong>
                  <div className="djg-mono" style={{ fontSize: 12, color: 'var(--djg-text-secondary)' }}>
                    {row.model_code} · {row.canonical_key}
                  </div>
                </div>
              ),
            },
            {
              key: 'brand',
              header: t('brandsHeading'),
              render: (row) => (row.brand ? (isRtl ? row.brand.name_fa : row.brand.name_en) : '—'),
            },
            {
              key: 'category',
              header: t('categoriesHeading'),
              render: (row) => (row.category ? (isRtl ? row.category.name_fa : row.category.name_en) : '—'),
            },
            {
              key: 'status',
              header: t('colStatus'),
              render: (row) => (
                <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                  <Badge tone={row.lifecycle_status === 'active' ? 'success' : 'neutral'}>
                    {row.lifecycle_status}
                  </Badge>
                  <Badge tone={row.skin_ready ? 'info' : 'neutral'}>
                    {row.skin_ready ? t('skinReadyYes') : t('skinReadyNo')}
                  </Badge>
                </div>
              ),
            },
            {
              key: 'actions',
              header: t('colActions'),
              render: (row) =>
                canManage ? (
                  <button type="button" className="djg-btn" onClick={() => void handleDelete(row.id)}>
                    <Trash2 size={13} />
                    <span>{t('deleteLabel')}</span>
                  </button>
                ) : (
                  <span>—</span>
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
