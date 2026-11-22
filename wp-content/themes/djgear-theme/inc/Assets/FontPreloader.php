<?php

declare(strict_types=1);

namespace DJGear\Theme\Assets;

/**
 * Emits <link rel="preload" as="font"> strictly for the active locale's self-hosted WOFF2 variable font.
 */
final class FontPreloader
{
    public function __construct(
        private readonly AssetManager $assets,
    ) {
    }

    public function register(): void
    {
        add_action('wp_head', [$this, 'emitPreloadLinks'], 2);
    }

    public function emitPreloadLinks(): void
    {
        $manifest = $this->assets->getManifest();
        if ($manifest === null) {
            return;
        }

        $mainEntry = $manifest['assets/src/scripts/main.ts'] ?? null;
        if (!is_array($mainEntry) || !isset($mainEntry['assets']) || !is_array($mainEntry['assets'])) {
            return;
        }

        $isRtl = is_rtl();
        $distUrl = $this->assets->distBaseUri();

        foreach ($mainEntry['assets'] as $assetFile) {
            if (!is_string($assetFile) || !str_ends_with($assetFile, '.woff2')) {
                continue;
            }

            $lower = strtolower($assetFile);
            $shouldPreload = $isRtl
                ? str_contains($lower, 'vazirmatn-arabic-wght')
                : str_contains($lower, 'inter-latin-wght');

            if (!$shouldPreload) {
                continue;
            }

            echo '<link rel="preload" href="' . esc_url($distUrl . ltrim($assetFile, '/')) . '" as="font" type="font/woff2" crossorigin="anonymous">' . "\n";
        }
    }
}
