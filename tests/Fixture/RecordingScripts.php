<?php

namespace Tests\Fixture;

use ByJG\Gluo\Builder\BaseScripts;

/**
 * Records what the launcher asks the project's Scripts to run, instead of running it.
 */
class RecordingScripts extends BaseScripts
{
    /** @var array<int, array{0: string, 1: array, 2: string|false}> [method, arguments, APP_ENV] */
    public array $calls = [];

    public int $testExitCode = 0;

    #[\Override]
    public function runMigrate(array $arguments): void
    {
        $this->calls[] = ['runMigrate', $arguments, getenv('APP_ENV')];
    }

    #[\Override]
    public function runGenOpenApiDocs(array $arguments): void
    {
        $this->calls[] = ['runGenOpenApiDocs', $arguments, getenv('APP_ENV')];
    }

    #[\Override]
    public function runCodeGenerator(array $arguments): void
    {
        $this->calls[] = ['runCodeGenerator', $arguments, getenv('APP_ENV')];
    }

    #[\Override]
    public function runTest(array $arguments): int
    {
        $this->calls[] = ['runTest', $arguments, getenv('APP_ENV')];
        return $this->testExitCode;
    }

    public function runCheckOpenApi(): void
    {
        $this->calls[] = ['runCheckOpenApi', [], getenv('APP_ENV')];
    }
}
