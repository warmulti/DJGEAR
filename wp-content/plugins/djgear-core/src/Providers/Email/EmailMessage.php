<?php

declare(strict_types=1);

namespace DJGear\Core\Providers\Email;

/**
 * Immutable outbound email message payload.
 */
final readonly class EmailMessage
{
    /**
     * @param list<string> $to
     * @param array<string, string> $headers
     * @param list<string> $attachments
     */
    public function __construct(
        public array $to,
        public string $subject,
        public string $body,
        public bool $isHtml = true,
        public array $headers = [],
        public array $attachments = [],
    ) {
    }
}
