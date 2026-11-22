<?php

declare(strict_types=1);

namespace DJGear\CustomSkins\Domain;

use RuntimeException;

/**
 * Domain validation & business rule exception carrying HTTP status and structured error details.
 */
final class SkinValidationException extends RuntimeException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $message,
        public readonly int $statusCode = 422,
        public readonly string $errorCode = 'djgear_skins_validation_error',
        public readonly array $details = [],
    ) {
        parent::__construct($message, $statusCode);
    }

    public static function invalidField(string $field, string $reason): self
    {
        return new self(
            $reason,
            422,
            'djgear_skins_invalid_field',
            ['field' => $field]
        );
    }

    public static function conflict(string $field, string $reason): self
    {
        return new self(
            $reason,
            409,
            'djgear_skins_conflict',
            ['field' => $field]
        );
    }

    public static function notFound(string $resource, string $reason = 'Resource not found.'): self
    {
        return new self(
            $reason,
            404,
            'djgear_skins_not_found',
            ['resource' => $resource]
        );
    }

    public static function forbidden(string $reason = 'You are not permitted to perform this action.'): self
    {
        return new self(
            $reason,
            403,
            'djgear_skins_forbidden'
        );
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function badRequest(string $reason, string $code = 'djgear_skins_bad_request', array $details = []): self
    {
        return new self(
            $reason,
            400,
            $code,
            $details
        );
    }
}
