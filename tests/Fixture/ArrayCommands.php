<?php

namespace Tests\Fixture;

use ByJG\Scriptify\CommandProvider;

/**
 * A command provider for the given commands.
 */
class ArrayCommands implements CommandProvider
{
    /** @param array<string, string> $commands */
    public function __construct(private array $commands)
    {
    }

    public function commands(): array
    {
        return $this->commands;
    }
}
