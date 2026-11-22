<?php

declare(strict_types=1);

namespace DJGear\Core\Providers;

use DJGear\Core\Providers\Email\EmailProviderInterface;
use DJGear\Core\Providers\Payment\PaymentProviderInterface;
use DJGear\Core\Providers\Sms\SmsProviderInterface;

/**
 * Central registry for replaceable Email, SMS, and Payment providers.
 */
final class ProviderRegistry
{
    /** @var array<string, EmailProviderInterface> */
    private array $emailProviders = [];

    /** @var array<string, SmsProviderInterface> */
    private array $smsProviders = [];

    /** @var array<string, PaymentProviderInterface> */
    private array $paymentProviders = [];

    public function registerEmailProvider(EmailProviderInterface $provider): void
    {
        $this->emailProviders[$provider->id()] = $provider;
    }

    public function registerSmsProvider(SmsProviderInterface $provider): void
    {
        $this->smsProviders[$provider->id()] = $provider;
    }

    public function registerPaymentProvider(PaymentProviderInterface $provider): void
    {
        $this->paymentProviders[$provider->id()] = $provider;
    }

    public function getEmailProvider(string $id): ?EmailProviderInterface
    {
        return $this->emailProviders[$id] ?? null;
    }

    public function getSmsProvider(string $id): ?SmsProviderInterface
    {
        return $this->smsProviders[$id] ?? null;
    }

    public function getPaymentProvider(string $id): ?PaymentProviderInterface
    {
        return $this->paymentProviders[$id] ?? null;
    }

    /**
     * @return list<string>
     */
    public function emailProviderIds(): array
    {
        return array_keys($this->emailProviders);
    }

    /**
     * @return list<string>
     */
    public function smsProviderIds(): array
    {
        return array_keys($this->smsProviders);
    }

    /**
     * @return list<string>
     */
    public function paymentProviderIds(): array
    {
        return array_keys($this->paymentProviders);
    }

    /**
     * Export non-sensitive provider inventory for diagnostics/health responses.
     *
     * @return array{email: list<string>, sms: list<string>, payment: list<string>}
     */
    public function summary(): array
    {
        return [
            'email'   => $this->emailProviderIds(),
            'sms'     => $this->smsProviderIds(),
            'payment' => $this->paymentProviderIds(),
        ];
    }
}
