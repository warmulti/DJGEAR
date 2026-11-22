<?php

declare(strict_types=1);

namespace DJGear\Core\Providers\Sms;

/**
 * Immutable outbound SMS message payload.
 */
final readonly class SmsMessage
{
    /**
     * @param array<string, string> $parameters Template token substitutions (e.g., OTP or order number).
     */
    public function __construct(
        public string $recipient,
        public string $body,
        public ?string $templateId = null,
        public array $parameters = [],
    ) {
    }
}
