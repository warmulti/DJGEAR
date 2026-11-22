import React, { createContext, useCallback, useContext, useMemo, useState } from 'react';
import type { DjgearApi } from '../api/djgearApi';
import type { LocaleCode, TextDirection } from '../types/api';
import { getDictionary, type TranslationKey } from './dictionaries';

const PERSIAN_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

export interface I18nContextValue {
  locale: LocaleCode;
  direction: TextDirection;
  isRtl: boolean;
  isSwitching: boolean;
  t: (key: TranslationKey) => string;
  formatNumber: (value: number | string) => string;
  toggleLocale: () => Promise<void>;
  setLocale: (next: LocaleCode) => Promise<void>;
}

const I18nContext = createContext<I18nContextValue | null>(null);

export interface I18nProviderProps {
  initialLocale: string;
  api?: DjgearApi;
  children: React.ReactNode;
}

export function normalizeLocale(raw: string): LocaleCode {
  return raw.toLowerCase().startsWith('fa') ? 'fa_IR' : 'en_US';
}

export function I18nProvider({ initialLocale, api, children }: I18nProviderProps) {
  const [locale, setLocaleState] = useState<LocaleCode>(() => normalizeLocale(initialLocale));
  const [isSwitching, setIsSwitching] = useState(false);

  const direction: TextDirection = locale === 'fa_IR' ? 'rtl' : 'ltr';
  const isRtl = direction === 'rtl';

  const setLocale = useCallback(
    async (next: LocaleCode) => {
      setLocaleState(next);
      if (!api) {
        return;
      }
      setIsSwitching(true);
      try {
        await api.switchUserLocale(next);
      } catch {
        // Keep client-side visual toggle responsive even if preference persistence fails.
      } finally {
        setIsSwitching(false);
      }
    },
    [api]
  );

  const toggleLocale = useCallback(async () => {
    const next: LocaleCode = locale === 'fa_IR' ? 'en_US' : 'fa_IR';
    await setLocale(next);
  }, [locale, setLocale]);

  const dictionary = useMemo(() => getDictionary(locale), [locale]);

  const t = useCallback((key: TranslationKey): string => dictionary[key] ?? key, [dictionary]);

  const formatNumber = useCallback(
    (value: number | string): string => {
      const str = typeof value === 'number' ? value.toLocaleString('en-US') : String(value);
      if (!isRtl) {
        return str;
      }
      return str.replace(/\d/g, (d) => PERSIAN_DIGITS[Number(d)] ?? d);
    },
    [isRtl]
  );

  const ctx = useMemo<I18nContextValue>(
    () => ({
      locale,
      direction,
      isRtl,
      isSwitching,
      t,
      formatNumber,
      toggleLocale,
      setLocale,
    }),
    [locale, direction, isRtl, isSwitching, t, formatNumber, toggleLocale, setLocale]
  );

  return <I18nContext.Provider value={ctx}>{children}</I18nContext.Provider>;
}

export function useI18n(): I18nContextValue {
  const ctx = useContext(I18nContext);
  if (!ctx) {
    throw new Error('useI18n must be used within an I18nProvider');
  }
  return ctx;
}
