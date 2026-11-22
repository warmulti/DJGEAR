<?php

declare(strict_types=1);

namespace DJGear\Theme;

use DJGear\Theme\Assets\AssetManager;
use DJGear\Theme\Assets\FontPreloader;
use DJGear\Theme\Security\Hardening;
use DJGear\Theme\Setup\I18n;
use DJGear\Theme\Setup\Menus;
use DJGear\Theme\Setup\ThemeSupport;
use DJGear\Theme\WooCommerce\SkinPortalIntegration;
use DJGear\Theme\WooCommerce\WooCommerceSetup;

/**
 * Main DJGear Theme orchestrator.
 *
 * Registers presentation-layer hooks cleanly without duplicating any
 * WordPress, WooCommerce, or DJGear plugin business logic.
 */
final class Theme
{
    private static ?self $instance = null;

    private bool $booted = false;

    private function __construct()
    {
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        $assetManager = new AssetManager();

        (new I18n())->register();
        (new ThemeSupport())->register();
        (new Menus())->register();
        $assetManager->register();
        (new FontPreloader($assetManager))->register();
        (new WooCommerceSetup())->register();
        (new SkinPortalIntegration())->register();
        (new Hardening())->register();
    }
}
