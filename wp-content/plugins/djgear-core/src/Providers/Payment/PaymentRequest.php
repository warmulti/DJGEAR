<?php

declare(strict_types=1);

namespace DJGear\Core\Providers\Payment;

/**
 * Immutable payment initiation request linked to a WooCommerce order ID (source of truth).
 */
final readonly class PaymentRequest
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public int $orderId,
        public int $amountMinor,
        public string $currency,
        public string $callbackUrl,
        public string $description = '',
        public array $metadata = [],
    ) {
    }
}
