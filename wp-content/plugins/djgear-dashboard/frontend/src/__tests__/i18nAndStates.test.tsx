import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { I18nProvider, useI18n } from '../i18n/I18nContext';
import {
  EmptyState,
  ErrorState,
  LoadingState,
  PermissionDeniedState,
  PlannedModuleBanner,
} from '../components/ui/StateFeedback';

function LocaleProbe() {
  const { locale, direction, formatNumber, toggleLocale, t } = useI18n();
  return (
    <div dir={direction} data-testid="probe">
      <span data-testid="locale">{locale}</span>
      <span data-testid="dir">{direction}</span>
      <span data-testid="num">{formatNumber(1234)}</span>
      <span data-testid="title">{t('overviewTitle')}</span>
      <button type="button" onClick={() => void toggleLocale()}>
        toggle
      </button>
    </div>
  );
}

describe('I18n RTL/LTR & Feedback States', () => {
  it('switches cleanly between Persian RTL and English LTR', async () => {
    const user = userEvent.setup();
    render(
      <I18nProvider initialLocale="fa_IR">
        <LocaleProbe />
      </I18nProvider>
    );

    expect(screen.getByTestId('dir')).toHaveTextContent('rtl');
    expect(screen.getByTestId('num')).toHaveTextContent('۱,۲۳۴');
    expect(screen.getByTestId('title')).toHaveTextContent('نمای کلی پلتفرم');

    await user.click(screen.getByRole('button', { name: 'toggle' }));

    expect(screen.getByTestId('locale')).toHaveTextContent('en_US');
    expect(screen.getByTestId('dir')).toHaveTextContent('ltr');
    expect(screen.getByTestId('num')).toHaveTextContent('1,234');
    expect(screen.getByTestId('title')).toHaveTextContent('Platform Overview');
  });

  it('renders loading, empty, error, permission-denied, and planned module states', async () => {
    const onRetry = vi.fn();
    const user = userEvent.setup();

    render(
      <I18nProvider initialLocale="en_US">
        <LoadingState />
        <EmptyState />
        <ErrorState message="Backend offline" onRetry={onRetry} />
        <PermissionDeniedState capability="djgear_manage_core" />
        <PlannedModuleBanner targetModuleId="djgear-sms-center" contractSummary="SmsProviderInterface" />
      </I18nProvider>
    );

    expect(screen.getByText('Loading live data...')).toBeInTheDocument();
    expect(screen.getByText('No records found')).toBeInTheDocument();
    expect(screen.getByText('Backend offline')).toBeInTheDocument();
    expect(screen.getByText('Permission Denied')).toBeInTheDocument();
    expect(screen.getByText(/djgear_manage_core/)).toBeInTheDocument();
    expect(screen.getByText('Planned — Not Yet Implemented')).toBeInTheDocument();
    expect(screen.getByText('djgear-sms-center')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Try again' }));
    expect(onRetry).toHaveBeenCalledTimes(1);
  });
});
