import React, { useCallback, useEffect, useState } from 'react';
import { Plus, RefreshCw, Trash2 } from 'lucide-react';
import type { DjgearApi } from '../api/djgearApi';
import { ApiError } from '../api/httpClient';
import { Badge } from '../components/ui/Badge';
import { Card } from '../components/ui/Card';
import { DataTable } from '../components/ui/DataTable';
import { PermissionDeniedState } from '../components/ui/StateFeedback';
import { useI18n } from '../i18n/I18nContext';
import type { CatalogBrand, CatalogCategory } from '../types/api';

export function CatalogTaxonomiesPage({ api }: { api: DjgearApi }) {
  const { t, formatNumber } = useI18n();
  const [brands, setBrands] = useState<CatalogBrand[]>([]);
  const [categories, setCategories] = useState<CatalogCategory[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [forbidden, setForbidden] = useState(false);

  const [bEn, setBEn] = useState('');
  const [bFa, setBFa] = useState('');
  const [cEn, setCEn] = useState('');
  const [cFa, setCFa] = useState('');

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    setForbidden(false);
    try {
      const [br, cat] = await Promise.all([api.listCatalogBrands(), api.listCatalogCategories()]);
      setBrands(br.data.items);
      setCategories(cat.data.items);
    } catch (err) {
      if (err instanceof ApiError && (err.isForbidden() || err.isUnauthorized())) {
        setForbidden(true);
      } else {
        setError(err instanceof Error ? err.message : 'Failed to load taxonomies');
      }
    } finally {
      setLoading(false);
    }
  }, [api]);

  useEffect(() => {
    void load();
  }, [load]);

  const addBrand = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      await api.createCatalogBrand({ name_en: bEn, name_fa: bFa || bEn });
      setBEn('');
      setBFa('');
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to create brand');
    }
  };

  const addCategory = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      await api.createCatalogCategory({ name_en: cEn, name_fa: cFa || cEn });
      setCEn('');
      setCFa('');
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to create category');
    }
  };

  if (forbidden) {
    return <PermissionDeniedState capability="djgear_manage_catalog" />;
  }

  return (
    <>
      <div className="djg-page-header">
        <div>
          <h1 className="djg-page-title">{t('catalogTaxonomiesTitle')}</h1>
          <p className="djg-page-subtitle">{t('catalogTaxonomiesSubtitle')}</p>
        </div>
        <button type="button" className="djg-btn" onClick={() => void load()}>
          <RefreshCw size={14} />
          <span>{t('refresh')}</span>
        </button>
      </div>

      <div className="djg-two-col">
        <Card title={t('brandsHeading')}>
          <form onSubmit={(e) => void addBrand(e)} className="djg-toolbar">
            <input className="djg-input" placeholder="Brand (EN)" value={bEn} onChange={(e) => setBEn(e.target.value)} required />
            <input className="djg-input" placeholder="برند (FA)" value={bFa} onChange={(e) => setBFa(e.target.value)} />
            <button type="submit" className="djg-btn djg-btn-primary">
              <Plus size={14} />
              <span>{t('createLabel')}</span>
            </button>
          </form>
          <DataTable<CatalogBrand>
            rows={brands}
            rowKey={(b) => b.id}
            loading={loading}
            error={error}
            onRetry={() => void load()}
            columns={[
              {
                key: 'name',
                header: t('brandsHeading'),
                render: (b) => (
                  <div>
                    <strong>{b.name_en}</strong> / {b.name_fa}
                    <div className="djg-mono" style={{ fontSize: 12, color: 'var(--djg-text-secondary)' }}>
                      {b.slug}
                    </div>
                  </div>
                ),
              },
              { key: 'count', header: t('totalItems'), render: (b) => <Badge tone="info">{formatNumber(b.device_count)}</Badge> },
              {
                key: 'del',
                header: t('colActions'),
                render: (b) => (
                  <button
                    type="button"
                    className="djg-btn"
                    onClick={() => void api.deleteCatalogBrand(b.id).then(() => load())}
                  >
                    <Trash2 size={13} />
                  </button>
                ),
              },
            ]}
          />
        </Card>

        <Card title={t('categoriesHeading')}>
          <form onSubmit={(e) => void addCategory(e)} className="djg-toolbar">
            <input className="djg-input" placeholder="Category (EN)" value={cEn} onChange={(e) => setCEn(e.target.value)} required />
            <input className="djg-input" placeholder="دسته‌بندی (FA)" value={cFa} onChange={(e) => setCFa(e.target.value)} />
            <button type="submit" className="djg-btn djg-btn-primary">
              <Plus size={14} />
              <span>{t('createLabel')}</span>
            </button>
          </form>
          <DataTable<CatalogCategory>
            rows={categories}
            rowKey={(c) => c.id}
            loading={loading}
            error={error}
            onRetry={() => void load()}
            columns={[
              {
                key: 'name',
                header: t('categoriesHeading'),
                render: (c) => (
                  <div>
                    <strong>{c.name_en}</strong> / {c.name_fa}
                    <div className="djg-mono" style={{ fontSize: 12, color: 'var(--djg-text-secondary)' }}>
                      {c.slug}
                    </div>
                  </div>
                ),
              },
              { key: 'count', header: t('totalItems'), render: (c) => <Badge tone="info">{formatNumber(c.device_count)}</Badge> },
              {
                key: 'del',
                header: t('colActions'),
                render: (c) => (
                  <button
                    type="button"
                    className="djg-btn"
                    onClick={() => void api.deleteCatalogCategory(c.id).then(() => load())}
                  >
                    <Trash2 size={13} />
                  </button>
                ),
              },
            ]}
          />
        </Card>
      </div>
    </>
  );
}
