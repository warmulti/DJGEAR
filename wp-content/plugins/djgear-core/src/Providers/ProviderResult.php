<?php

declare(strict_types=1);

namespace DJGear\Core\Providers;

/**
 * Normalized immutable outcome returned by email, SMS, and payment providers.
 */
final readonly class ProviderResult
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public bool $success,
        public string $providerId,
        public string $status,
        public string $referenceId = '',
        public string $message = '',
        public array $meta = [],
    ) {
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function ok(
        string $providerId,
        string $referenceId = '',
        string $message = '',
        array $meta = [],
    ): self {
        return new self(
            success: true,
            providerId: $providerId,
            status: 'ok',
            referenceId: $referenceId,
            message: $message,
            meta: $meta,
        );
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function failure(
        string $providerId,
        string $message,
        string $status = 'failed',
        array $meta = [],
    ): self {
        return new self(
            success: false,
            providerId: $providerId,
            status: $status,
            referenceId: '',
            message: $message,
            meta: $meta,
        );
    }
}
