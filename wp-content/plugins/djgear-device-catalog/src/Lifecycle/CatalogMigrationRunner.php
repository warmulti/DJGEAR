<?php

declare(strict_types=1);

namespace DJGear\DeviceCatalog\Lifecycle;

use wpdb;

/**
 * Runs and rolls back versioned djgear-device-catalog migrations idempotently under the restricted WP DB user.
 */
final class CatalogMigrationRunner
{
    public const OPTION_KEY = 'djgear_catalog_migrations';

    /**
     * @param list<ReversibleMigrationInterface> $migrations
     */
    public function __construct(
        private readonly wpdb $wpdb,
        private readonly array $migrations,
    ) {
    }

    /**
     * @return list<string> Newly applied migration versions.
     */
    public function runPending(): array
    {
        $applied = $this->appliedVersions();
        $newlyApplied = [];

        foreach ($this->migrations as $migration) {
            $version = $migration->version();
            if (in_array($version, $applied, true)) {
                continue;
            }

            $migration->up($this->wpdb);
            $applied[] = $version;
            $newlyApplied[] = $version;
        }

        if ($newlyApplied !== [] && function_exists('update_option')) {
            update_option(self::OPTION_KEY, $applied, false);
        }

        return $newlyApplied;
    }

    public const ROLLBACK_CONFIRMATION_TOKEN = 'CONFIRM_DESTRUCTIVE_CATALOG_ROLLBACK';

    /**
     * Roll back the most recently applied migration ONLY when explicitly authorized via
     * both `DJGEAR_ALLOW_DESTRUCTIVE_ROLLBACK === true` and the confirmation token.
     */
    public function rollbackLast(string $confirmationToken = ''): ?string
    {
        $allowedByEnv = defined('DJGEAR_ALLOW_DESTRUCTIVE_ROLLBACK')
            && constant('DJGEAR_ALLOW_DESTRUCTIVE_ROLLBACK') === true;

        if (!$allowedByEnv || !hash_equals(self::ROLLBACK_CONFIRMATION_TOKEN, $confirmationToken)) {
            throw new \RuntimeException(
                'Destructive catalog migration rollback is blocked unless DJGEAR_ALLOW_DESTRUCTIVE_ROLLBACK is true and the explicit confirmation token is provided.'
            );
        }

        $applied = $this->appliedVersions();
        if ($applied === []) {
            return null;
        }

        $targetVersion = array_pop($applied);
        foreach ($this->migrations as $migration) {
            if ($migration->version() === $targetVersion) {
                $migration->down($this->wpdb);
                if (function_exists('update_option')) {
                    update_option(self::OPTION_KEY, $applied, false);
                }

                return $targetVersion;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function appliedVersions(): array
    {
        if (!function_exists('get_option')) {
            return [];
        }

        $stored = get_option(self::OPTION_KEY, []);
        if (!is_array($stored)) {
            return [];
        }

        $versions = [];
        foreach ($stored as $item) {
            if (is_string($item) && $item !== '') {
                $versions[] = $item;
            }
        }

        return $versions;
    }
}
