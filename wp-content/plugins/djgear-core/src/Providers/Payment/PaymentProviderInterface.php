<?php

declare(strict_types=1);

namespace DJGear\Core\Providers\Payment;

use DJGear\Core\Providers\ProviderResult;

/**
 * Replaceable payment gateway adapter contract (implemented by Sandbox and real gateways in Payment Manager).
 *
 * WooCommerce remains the sole source of truth for orders and payment status transitions.
 */
interface PaymentProviderInterface
{
    /**
     * Unique gateway slug (e.g., "sandbox", "zarinpal").
     *
     * @return non-empty-string
     */
    public function id(): string;

    /**
     * Human-readable gateway display name.
     *
     * @return non-empty-string
     */
    public function name(): string;

    /**
     * Whether this gateway operates strictly in sandbox/test mode.
     */
    public function isSandbox(): bool;

    /**
     * Initiate a payment session and return redirect URL / authority reference in ProviderResult.
     */
    public function initiate(PaymentRequest $request): ProviderResult;

    /**
     * Verify a payment callback against the gateway before WooCommerce marks the order paid.
     */
    public function verify(PaymentVerification $verification): ProviderResult;
}
