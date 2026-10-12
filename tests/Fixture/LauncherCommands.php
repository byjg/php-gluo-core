<?php

namespace Tests\Fixture;

use RuntimeException;

/**
 * Target of the project commands used by the launcher tests. The constructor argument
 * makes it impossible to build with `new`, so a passing test proves the container was used.
 */
class LauncherCommands
{
    public function __construct(private string $prefix)
    {
    }

    /**
     * Delete records older than the given number of days.
     *
     * @param int $days
     * @param string|null $table
     */
    public function cleanOldRecords(int $days, ?string $table = null): void
    {
        echo "{$this->prefix}: days=" . var_export($days, true) . " table=" . var_export($table, true)
            . " env=" . getenv('APP_ENV') . "\n";
    }

    public function fail(): void
    {
        throw new RuntimeException('Something went wrong');
    }

    protected function hidden(): void
    {
    }
}
