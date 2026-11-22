<?php

declare(strict_types=1);

namespace DJGear\DeviceCatalog\Security;

use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Prevents engineering dieline attachments from ever leaking via public Media Library URLs,
 * WordPress attachment pages, or native wp/v2/media REST endpoints.
 */
final class DielineProtection
{
    public const META_PRIVATE_DIELINE = '_djgear_private_dieline';
    public const PRIVATE_SUBDIR       = 'djgear-private';

    public function registerHooks(): void
    {
        // Hide private dieline attachments from public WP REST media collections
        add_filter('rest_attachment_query', [$this, 'filterRestMediaQuery']);

        // Block direct /wp/v2/media/{id} requests for unauthorized callers before dispatch
        add_filter('rest_pre_dispatch', [$this, 'guardRestMediaRoute'], 10, 3);

        // Block public attachment permalink pages (/?attachment_id=...)
        add_action('template_redirect', [$this, 'guardAttachmentPermalink']);

        // Never leak direct /wp-content/uploads/... URLs for private dieline attachments
        add_filter('wp_get_attachment_url', [$this, 'filterAttachmentUrl'], 10, 2);
    }

    public static function markPrivateDieline(?int $attachmentId): void
    {
        if ($attachmentId === null || $attachmentId <= 0 || !function_exists('update_post_meta')) {
            return;
        }
        update_post_meta($attachmentId, self::META_PRIVATE_DIELINE, '1');
        self::ensurePrivateDirectoryProtection();
    }

    public static function isPrivateDieline(int $attachmentId): bool
    {
        if ($attachmentId <= 0 || !function_exists('get_post_meta')) {
            return false;
        }

        return (string) get_post_meta($attachmentId, self::META_PRIVATE_DIELINE, true) === '1';
    }

    public static function ensurePrivateDirectoryProtection(): void
    {
        if (!function_exists('wp_upload_dir')) {
            return;
        }
        $uploads = wp_upload_dir();
        $baseDir = $uploads['basedir'];
        if ($baseDir === '') {
            return;
        }

        $privateDir = rtrim($baseDir, '/\\') . '/' . self::PRIVATE_SUBDIR;
        if (!is_dir($privateDir)) {
            wp_mkdir_p($privateDir);
        }

        $htaccess = $privateDir . '/.htaccess';
        if (is_dir($privateDir) && !file_exists($htaccess)) {
            @file_put_contents($htaccess, "Order deny,allow\nDeny from all\nRequire all denied\n");
        }

        $indexPhp = $privateDir . '/index.php';
        if (is_dir($privateDir) && !file_exists($indexPhp)) {
            @file_put_contents($indexPhp, "<?php // Silence is golden.\n");
        }
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function filterRestMediaQuery(array $args): array
    {
        if (current_user_can(CatalogCapabilities::MANAGE_SKIN_DEFINITIONS)) {
            return $args;
        }

        $metaQuery = isset($args['meta_query']) && is_array($args['meta_query']) ? $args['meta_query'] : [];
        $metaQuery[] = [
            'relation' => 'OR',
            [
                'key'     => self::META_PRIVATE_DIELINE,
                'compare' => 'NOT EXISTS',
            ],
            [
                'key'     => self::META_PRIVATE_DIELINE,
                'value'   => '1',
                'compare' => '!=',
            ],
        ];
        $args['meta_query'] = $metaQuery;

        return $args;
    }

    /**
     * @param mixed $result
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function guardRestMediaRoute(mixed $result, WP_REST_Server $server, WP_REST_Request $request): mixed
    {
        if (preg_match('#^/wp/v2/media/(\d+)$#', $request->get_route(), $matches) !== 1) {
            return $result;
        }

        $attachmentId = (int) $matches[1];
        if (self::isPrivateDieline($attachmentId) && !current_user_can(CatalogCapabilities::MANAGE_SKIN_DEFINITIONS)) {
            return new WP_Error(
                'djgear_private_dieline_forbidden',
                'Access to private engineering dieline files is restricted.',
                ['status' => is_user_logged_in() ? 403 : 401]
            );
        }

        return $result;
    }

    public function guardAttachmentPermalink(): void
    {
        if (!function_exists('is_attachment') || !is_attachment()) {
            return;
        }
        $id = (int) get_queried_object_id();
        if (self::isPrivateDieline($id) && !current_user_can(CatalogCapabilities::MANAGE_SKIN_DEFINITIONS)) {
            status_header(403);
            wp_die('Access to private engineering dieline files is restricted.', 403);
        }
    }

    public function filterAttachmentUrl(string $url, int $attachmentId): string
    {
        if (!self::isPrivateDieline($attachmentId)) {
            return $url;
        }

        return '';
    }
}
