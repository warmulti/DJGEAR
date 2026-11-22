<?php

declare(strict_types=1);

namespace DJGear\Core\Lifecycle;

use wpdb;

/**
 * Tracks and executes pending DJGear schema migrations idempotently.
 */
final class MigrationRunner
{
    public const OPTION_KEY = 'djgear_core_migrations';

    /**
     * @param list<MigrationInterface> $migrations
     */
    public function __construct(
        private readonly wpdb $wpdb,
        private readonly array $migrations,
    ) {
    }

    /**
     * Run all unapplied migrations and return the list of newly applied version keys.
     *
     * @return list<string>
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

        $result = [];
        foreach ($stored as $item) {
            if (is_string($item) && $item !== '') {
                $result[] = $item;
            }
        }

        return $result;
    }
}
