<?php

declare(strict_types=1);

namespace DJGear\Core\Providers\Payment;

/**
 * Immutable payment verification payload received on gateway callback.
 */
final readonly class PaymentVerification
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public int $orderId,
        public string $referenceId,
        public int $expectedAmountMinor,
        public string $currency,
        public array $payload = [],
    ) {
    }
}
