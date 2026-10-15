<?php

namespace Tests\Fixture;

/**
 * A class target for the launcher tests: only ownCommand() is a command. The inherited,
 * static and magic public methods are not.
 */
class LauncherChildCommands extends LauncherCommands
{
    /** Run the child's own command. */
    public function ownCommand(): void
    {
    }

    public static function create(): self
    {
        return new self('static');
    }

    public function __toString(): string
    {
        return self::class;
    }
}
