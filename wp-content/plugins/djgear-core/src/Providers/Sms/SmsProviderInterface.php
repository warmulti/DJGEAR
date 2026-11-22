<?php

declare(strict_types=1);

namespace DJGear\Core\Providers\Sms;

use DJGear\Core\Providers\ProviderResult;

/**
 * Replaceable SMS gateway contract (implemented by Mock adapter locally and real gateways in SMS Center).
 */
interface SmsProviderInterface
{
    /**
     * Unique provider slug (e.g., "mock", "kavenegar").
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
     * Whether the provider is configured and safe for the current environment.
     */
    public function isAvailable(): bool;

    /**
     * Dispatch an outbound SMS message.
     */
    public function send(SmsMessage $message): ProviderResult;
}
