<?php

namespace Tests\Console;

use ByJG\Gluo\Console\Launcher;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixture\LauncherCommands;

class LauncherTest extends LauncherTestCase
{
    protected const TARGET = 'Tests/Fixture/LauncherCommands::cleanOldRecords';

    public function testNoArgumentsListsBuiltInAndProjectCommands(): void
    {
        $this->commands = ['user:cleanup' => self::TARGET, 'mail:welcome' => 'Tests/Fixture/LauncherCommands::fail'];

        [$exitCode, $stdOut, $stdErr] = $this->gluo();

        $this->assertSame(0, $exitCode);
        $this->assertSame('', $stdErr);
        foreach (array_keys(Launcher::BUILT_IN) as $name) {
            $this->assertMatchesRegularExpression('/^  ' . preg_quote($name, '/') . ' +\S/m', $stdOut);
        }
        // The PHPDoc summary describes a command; the target is shown when there is none.
        $this->assertStringContainsString("  user:cleanup           Delete records older than the given number of days.\n", $stdOut);
        $this->assertStringContainsString("  mail:welcome           Tests/Fixture/LauncherCommands::fail\n", $stdOut);
        $this->assertMatchesRegularExpression('/^  install +Install a project command as a system service/m', $stdOut);
        // Project commands are sorted by name and come after the built-in group.
        $this->assertGreaterThan(strpos($stdOut, "\nProject\n"), strpos($stdOut, 'mail:welcome'));
        $this->assertGreaterThan(strpos($stdOut, 'mail:welcome'), strpos($stdOut, 'user:cleanup'));
        $this->assertSame($stdOut, $this->gluo('list')[1]);
        $this->assertSame([], $this->scripts->calls);
    }

    public function testListWithoutProjectCommands(): void
    {
        [$exitCode, $stdOut] = $this->gluo('list');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('(none: add them to Tests\Fixture\ArrayCommands)', $stdOut);
    }

    public static function builtInProvider(): array
    {
        return [
            'migrate:update' => [['migrate:update'], 'runMigrate', ['update']],
            'options pass unchanged' => [['migrate:reset', '--version=5', '--force'], 'runMigrate', ['reset', '--version=5', '--force']],
            'verbosity reaches migrate' => [['migrate:up', '-vv'], 'runMigrate', ['up', '-vv']],
            'alias migrate:status' => [['migrate:status'], 'runMigrate', ['version']],
            'alias migrate:install' => [['migrate:install'], 'runMigrate', ['create']],
            'composer alias form' => [['migrate', 'update', '--version=3'], 'runMigrate', ['update', '--version=3']],
            'composer alias form, option first' => [['migrate', '-vv', 'down'], 'runMigrate', ['down', '-vv']],
            'composer alias form, sub alias' => [['migrate', 'status'], 'runMigrate', ['version']],
            'openapi' => [['openapi'], 'runGenOpenApiDocs', []],
            'openapi:check' => [['openapi:check'], 'runCheckOpenApi', []],
            'codegen:all' => [['codegen:all', '--table=users', '--save'], 'runCodeGenerator', ['all', '--table=users', '--save']],
            'codegen:test' => [['codegen:test', '--table=users'], 'runCodeGenerator', ['test', '--table=users']],
            'codegen:controller' => [['codegen:controller', '--table=users', '--activerecord'], 'runCodeGenerator', ['controller', '--table=users', '--activerecord']],
            'codegen:model' => [['codegen:model', '--table=users'], 'runCodeGenerator', ['model', '--table=users']],
            'codegen:service' => [['codegen:service', '--table=users'], 'runCodeGenerator', ['service', '--table=users']],
            'alias codegen:repo' => [['codegen:repo', '--table=users'], 'runCodeGenerator', ['repository', '--table=users']],
            'codegen composer alias form' => [['codegen', '--table=users', 'model', 'controller', '--save'], 'runCodeGenerator', ['--table=users', 'model', 'controller', '--save']],
        ];
    }

    #[DataProvider('builtInProvider')]
    public function testBuiltInRunsThroughProjectScripts(array $arguments, string $method, array $expectedArguments): void
    {
        [$exitCode, , $stdErr] = $this->gluo(...$arguments);

        $this->assertSame('', $stdErr);
        $this->assertSame(0, $exitCode);
        $this->assertSame([[$method, $expectedArguments, false]], $this->scripts->calls);
    }

    public static function envProvider(): array
    {
        return [
            '--env=x before' => [['--env=staging', 'migrate:update']],
            '--env x before' => [['--env', 'staging', 'migrate:update']],
            '-e x before' => [['-e', 'staging', 'migrate:update']],
            '--env=x after' => [['migrate:update', '--env=staging']],
            '--env x after' => [['migrate:update', '--env', 'staging']],
            '-e x after' => [['migrate:update', '-e', 'staging']],
        ];
    }

    #[DataProvider('envProvider')]
    public function testEnvOptionIsConsumedAndExportedBeforeTheCommandRuns(array $arguments): void
    {
        [$exitCode] = $this->gluo(...$arguments);

        $this->assertSame(0, $exitCode);
        // The value never reaches the command as an argument (the "--env dev" bug in runMigrate).
        $this->assertSame([['runMigrate', ['update'], 'staging']], $this->scripts->calls);
    }

    public function testExistingAppEnvAppliesWhenOptionIsAbsent(): void
    {
        putenv('APP_ENV=prod');

        $this->gluo('migrate:version');

        $this->assertSame([['runMigrate', ['version'], 'prod']], $this->scripts->calls);
    }

    public function testEnvOptionWithoutValueFails(): void
    {
        [$exitCode, , $stdErr] = $this->gluo('migrate:update', '--env');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--env option requires a value', $stdErr);
        $this->assertSame([], $this->scripts->calls);
    }

    public function testComposerAliasFormNeverReachesProjectCommands(): void
    {
        $this->commands = ['migrate:seed' => self::TARGET];

        [$exitCode, $stdOut, $stdErr] = $this->gluo('migrate', 'seed');

        $this->assertSame(1, $exitCode);
        $this->assertSame('', $stdOut);
        $this->assertStringContainsString('Unknown migrate command: seed', $stdErr);

        // The same command runs with the colon form: built-in groups are open.
        [$exitCode, $stdOut] = $this->gluo('migrate:seed', '7');
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('days=7', $stdOut);
        $this->assertSame([], $this->scripts->calls);
    }

    public function testComposerAliasFormWithoutSubcommandFails(): void
    {
        [$exitCode, , $stdErr] = $this->gluo('migrate');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Command is required.', $stdErr);
        $this->assertStringContainsString('./gluo migrate:<command>', $stdErr);
    }

    public function testProjectCommandRunsThroughTheContainerWithPositionalArguments(): void
    {
        $this->commands = ['user:cleanup' => self::TARGET];

        [$exitCode, $stdOut, $stdErr] = $this->gluo('--env=prod', 'user:cleanup', '30', 'audit_log');

        $this->assertSame('', $stdErr);
        $this->assertSame(0, $exitCode);
        // "30" was coerced to int by the method signature, and the object came from the container.
        $this->assertSame("built by container: days=30 table='audit_log' env=prod\n", $stdOut);
    }

    public function testClassTargetCommandsRunThroughTheContainer(): void
    {
        $this->commands = ['tool' => 'Tests/Fixture/LauncherCommands'];

        [$exitCode, $stdOut] = $this->gluo('list');
        $this->assertSame(0, $exitCode);
        $project = substr($stdOut, (int) strpos($stdOut, "\nProject\n"));
        $this->assertSame(
            "\nProject\n"
            . "  tool:clean-old-records Delete records older than the given number of days.\n"
            . "  tool:fail              Tests\\Fixture\\LauncherCommands::fail\n",
            $project
        );

        [$exitCode, $stdOut, $stdErr] = $this->gluo('tool:clean-old-records', '30', 'audit_log');
        $this->assertSame('', $stdErr);
        $this->assertSame(0, $exitCode);
        $this->assertSame("built by container: days=30 table='audit_log' env=\n", $stdOut);
    }

    public function testProjectCommandHelpUsesLauncherSyntax(): void
    {
        $this->commands = ['user:cleanup' => self::TARGET];

        [$exitCode, $stdOut] = $this->gluo('user:cleanup', '--help');

        $this->assertSame(0, $exitCode);
        $this->assertSame(
            "Delete records older than the given number of days.\n\n@param int \$days\n@param string|null \$table\n"
            . "\nUsage: \n./gluo user:cleanup <days> [table] \n",
            $stdOut
        );
    }

    public function testBuiltInHelpDoesNotRunTheCommand(): void
    {
        [$exitCode, $stdOut] = $this->gluo('migrate:reset', '--help');
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('./gluo --env=<environment> migrate:<command> [options]', $stdOut);

        foreach (['codegen:all', 'codegen:repo', 'codegen'] as $command) {
            [$exitCode, $stdOut] = $this->gluo($command, '--help');
            $this->assertSame(0, $exitCode);
            $this->assertStringContainsString('./gluo --env=<environment> codegen:<component> --table=<table_name>', $stdOut);
        }

        [$exitCode, $stdOut] = $this->gluo('openapi', '--help');
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString("Usage:\n  ./gluo openapi\n", $stdOut);

        $this->assertSame([], $this->scripts->calls);
    }

    public function testUnknownCommand(): void
    {
        [$exitCode, $stdOut, $stdErr] = $this->gluo('nope:nothing');

        $this->assertSame(1, $exitCode);
        $this->assertSame('', $stdOut);
        $this->assertStringContainsString('Command "nope:nothing" is not defined', $stdErr);
    }

    public function testBuiltInNameUsedByProjectIsRefused(): void
    {
        $this->commands = ['migrate:update' => self::TARGET];

        [$exitCode, $stdOut, $stdErr] = $this->gluo('migrate:update');

        $this->assertSame(1, $exitCode);
        $this->assertSame('', $stdOut);
        $this->assertStringContainsString('"migrate:update" is reserved and cannot be used as a command name. Rename it in Tests\Fixture\ArrayCommands.', $stdErr);
        $this->assertSame([], $this->scripts->calls);
    }

    public function testBuiltInDoesNotLoadTheContainer(): void
    {
        // The Config container: this package has no config/, so building it would fail.
        $this->container = null;

        [$exitCode, , $stdErr] = $this->gluo('migrate:update');

        $this->assertSame(0, $exitCode);
        $this->assertSame('', $stdErr);
    }
}
