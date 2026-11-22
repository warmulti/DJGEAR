<?php

declare(strict_types=1);

namespace DJGear\Dashboard\Rest;

use DJGear\Core\I18n\Translator;
use DJGear\Core\Rest\Response;
use DJGear\Core\Rest\RouteInterface;
use DJGear\Core\Security\Authorizer;
use DJGear\Core\Security\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Persists the authenticated WordPress user's own locale preference (fa_IR / en_US).
 */
final class PreferencesController implements RouteInterface
{
    /** @var list<string> */
    private const ALLOWED_LOCALES = ['fa_IR', 'en_US'];

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly Translator $translator,
    ) {
    }

    public function registerRoutes(string $namespace): void
    {
        register_rest_route($namespace, '/dashboard/preferences/locale', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handle'],
            'permission_callback' => $this->authorizer->restPermissionCallback(Capabilities::VIEW_DASHBOARD),
            'args'                => [
                'locale' => [
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function handle(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $locale = is_string($request->get_param('locale')) ? trim((string) $request->get_param('locale')) : '';
        if (!in_array($locale, self::ALLOWED_LOCALES, true)) {
            return Response::error(
                'djgear_invalid_locale',
                'Supported locales are fa_IR and en_US.',
                400
            );
        }

        $userId = get_current_user_id();
        if ($userId > 0) {
            update_user_meta($userId, 'locale', $locale);
        }

        return Response::ok([
            'i18n' => $this->translator->metadata($locale),
        ]);
    }
}
