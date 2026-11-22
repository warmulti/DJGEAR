<?php

declare(strict_types=1);

namespace DJGear\DeviceCatalog\Domain;

use RuntimeException;

/**
 * Typed domain validation or uniqueness conflict exception mapped to HTTP 400/409/422 in REST controllers.
 */
final class ValidationException extends RuntimeException
{
    /**
     * @param array<string, string> $fieldErrors
     */
    public function __construct(
        string $message,
        public readonly int $httpStatus = 422,
        public readonly string $errorCode = 'djgear_catalog_validation_error',
        public readonly array $fieldErrors = [],
    ) {
        parent::__construct($message, $httpStatus);
    }

    public static function invalidField(string $field, string $message): self
    {
        return new self($message, 422, 'djgear_catalog_validation_error', [$field => $message]);
    }

    public static function conflict(string $field, string $message): self
    {
        return new self($message, 409, 'djgear_catalog_duplicate_conflict', [$field => $message]);
    }

    public static function notFound(string $entity, int|string $id): self
    {
        return new self(
            sprintf('%s "%s" was not found.', $entity, (string) $id),
            404,
            'djgear_catalog_not_found'
        );
    }
}
