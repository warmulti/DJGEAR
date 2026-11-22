<?php

declare(strict_types=1);

namespace DJGear\Core\Rest;

use WP_Error;
use WP_REST_Response;

/**
 * Consistent JSON response envelope builder for djgear/v1 endpoints.
 */
final class Response
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     */
    public static function ok(array $data, int $status = 200, array $meta = []): WP_REST_Response
    {
        $payload = [
            'success' => true,
            'data'    => $data,
        ];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return new WP_REST_Response($payload, $status);
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function error(string $code, string $message, int $status = 400, array $details = []): WP_Error
    {
        $data = ['status' => $status];
        if ($details !== []) {
            $data['details'] = $details;
        }

        return new WP_Error($code, $message, $data);
    }
}
