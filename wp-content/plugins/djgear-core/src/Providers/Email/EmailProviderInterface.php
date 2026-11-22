<?php

declare(strict_types=1);

namespace DJGear\Core\Providers\Email;

use DJGear\Core\Providers\ProviderResult;

/**
 * Replaceable email delivery provider contract (implemented in Email Center / Mailpit adapters).
 */
interface EmailProviderInterface
{
    /**
     * Unique provider slug (e.g., "mailpit", "smtp").
     *
     * @return non-empty-string
     */
    public function id(): string;

    /**
     * Human-readable provider display name.
     *
     * @return non-empty-string
     */
    public function name(): string;

    /**
     * Whether the provider is configured and safe to invoke in the current environment.
     */
    public function isAvailable(): bool;

    /**
     * Dispatch an outbound email message.
     */
    public function send(EmailMessage $message): ProviderResult;
}
