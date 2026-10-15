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
        $this->writeCommands(['user:cleanup' => self::TARGET, 'mail:welcome' => 'Tests/Fixture/LauncherCommands::fail']);

        [$exitCode, $stdOut, $stdErr] = $this->gluo();

        $this->assertSame(0, $exitCode);
        $this->assertSame('', $stdErr);
        foreach (array_keys(Launcher::BUILT_IN) as $name) {
            $this->assertMatchesRegularExpression('/^  ' . preg_quote($name, '/') . ' +\S/m', $stdOut);
        }
        // The PHPDoc summary describes a command; the target is shown when there is none.
        $this->assertStringContainsString("  user:cleanup           Delete records older than the given number of days.\n", $stdOut);
        $this->assertStringContainsString("  mail:welcome           Tests/Fixture/LauncherCommands::fail\n", $stdOut);
        // Project commands are sorted by name and come after the built-in group.
        $this->assertGreaterThan(strpos($stdOut, "\nProject\n"), strpos($stdOut, 'mail:welcome'));
        $this->assertGreaterThan(strpos($stdOut, 'mail:welcome'), strpos($stdOut, 'user:cleanup'));
        $this->assertSame($stdOut, $this->gluo('list')[1]);
        $this->assertSame([], $this->scripts->calls);
    }

    public function testListWithoutRegistryFile(): void
    {
        [$exitCode, $stdOut] = $this->gluo('list');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('(none: add them to config/commands.php)', $stdOut);
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
        $this->writeCommands(['migrate:seed' => self::TARGET]);

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
        $this->writeCommands(['user:cleanup' => self::TARGET]);

        [$exitCode, $stdOut, $stdErr] = $this->gluo('--env=prod', 'user:cleanup', '30', 'audit_log');

        $this->assertSame('', $stdErr);
        $this->assertSame(0, $exitCode);
        // "30" was coerced to int by the method signature, and the object came from the container.
        $this->assertSame("built by container: days=30 table='audit_log' env=prod\n", $stdOut);
    }

    public function testProjectCommandOptionalArgumentAndBackslashTarget(): void
    {
        $this->writeCommands(['user:cleanup' => '\\' . LauncherCommands::class . '::cleanOldRecords']);

        [$exitCode, $stdOut] = $this->gluo('user:cleanup', '30');

        $this->assertSame(0, $exitCode);
        $this->assertSame("built by container: days=30 table=NULL env=\n", $stdOut);
    }

    public function testClassTargetExposesEachPublicMethodAsACommand(): void
    {
        $this->writeCommands(['tool' => 'Tests/Fixture/LauncherCommands']);

        [$exitCode, $stdOut, $stdErr] = $this->gluo('list');

        $this->assertSame(0, $exitCode);
        $this->assertSame('', $stdErr);
        $project = substr($stdOut, (int) strpos($stdOut, "\nProject\n"));
        $this->assertSame(
            "\nProject\n"
            . "  tool:clean-old-records Delete records older than the given number of days.\n"
            . "  tool:fail              Tests/Fixture/LauncherCommands::fail\n",
            $project
        );

        [$exitCode, $stdOut, $stdErr] = $this->gluo('tool:clean-old-records', '30', 'audit_log');

        $this->assertSame('', $stdErr);
        $this->assertSame(0, $exitCode);
        $this->assertSame("built by container: days=30 table='audit_log' env=\n", $stdOut);

        [$exitCode, $stdOut] = $this->gluo('tool:clean-old-records', '--help');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString("Usage: \n./gluo tool:clean-old-records <days> [table] \n", $stdOut);
    }

    public function testClassTargetSkipsInheritedStaticAndMagicMethods(): void
    {
        $this->writeCommands(['child' => 'Tests\Fixture\LauncherChildCommands']);

        [$exitCode, $stdOut] = $this->gluo('list');

        $this->assertSame(0, $exitCode);
        $project = substr($stdOut, (int) strpos($stdOut, "\nProject\n"));
        $this->assertSame("\nProject\n  child:own-command      Run the child's own command.\n", $project);
    }

    public function testClassTargetCollidingWithAnExplicitCommandIsRefused(): void
    {
        $this->writeCommands(['tool' => 'Tests/Fixture/LauncherCommands', 'tool:fail' => self::TARGET]);

        foreach ([['list'], ['tool:fail'], ['tool:clean-old-records', '1']] as $arguments) {
            [$exitCode, $stdOut, $stdErr] = $this->gluo(...$arguments);

            $this->assertSame(1, $exitCode);
            $this->assertStringNotContainsString('built by container', $stdOut);
            $this->assertStringContainsString('Command "tool:fail" is defined more than once in config/commands.php', $stdErr);
        }
    }

    public function testClassTargetWithTwoMethodsOfTheSameCommandNameIsRefused(): void
    {
        $this->writeCommands(['mail' => 'Tests/Fixture/LauncherCollidingCommands']);

        [$exitCode, , $stdErr] = $this->gluo('list');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Command "mail:send-mail" is defined more than once', $stdErr);
    }

    public function testProjectCommandHelpUsesLauncherSyntax(): void
    {
        $this->writeCommands(['user:cleanup' => self::TARGET]);

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
        $this->writeCommands(['migrate:update' => self::TARGET]);

        [$exitCode, $stdOut, $stdErr] = $this->gluo('migrate:update');

        $this->assertSame(1, $exitCode);
        $this->assertSame('', $stdOut);
        $this->assertStringContainsString('"migrate:update" is a built-in command', $stdErr);
        $this->assertSame([], $this->scripts->calls);
    }

    public function testInvalidNameAndInvalidTargetAreRefused(): void
    {
        $this->writeCommands(['User:Cleanup' => self::TARGET, 'no:method' => 'Tests/Fixture/DoesNotExist']);

        [$exitCode, , $stdErr] = $this->gluo('User:Cleanup', '1');
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Invalid command name "User:Cleanup"', $stdErr);

        [$exitCode, , $stdErr] = $this->gluo('no:method');
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Invalid target for "no:method"', $stdErr);
    }

    public function testClassMissingFromContainerIsRefusedNotBuiltWithNew(): void
    {
        $this->writeCommands(['other:run' => 'Tests/Fixture/RecordingScripts::runCheckOpenApi']);

        [$exitCode, , $stdErr] = $this->gluo('other:run');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('the container has no entry for "Tests\Fixture\RecordingScripts"', $stdErr);
    }

    public function testBootstrapMustReturnAContainer(): void
    {
        $this->writeCommands(['user:cleanup' => self::TARGET]);
        $this->writeBootstrap('return 1;');

        [$exitCode, , $stdErr] = $this->gluo('user:cleanup', '1');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('config/scriptify-bootstrap.php must return a PSR-11 container', $stdErr);
    }

    public function testBuiltInDoesNotLoadTheContainer(): void
    {
        $this->writeBootstrap('throw new \RuntimeException("container must not be loaded");');

        [$exitCode, , $stdErr] = $this->gluo('migrate:update');

        $this->assertSame(0, $exitCode);
        $this->assertSame('', $stdErr);
    }

    public function testCommandExceptionGoesToStdErrWithTraceOnlyWhenVerbose(): void
    {
        $this->writeCommands(['boom:now' => 'Tests/Fixture/LauncherCommands::fail']);

        [$exitCode, $stdOut, $stdErr] = $this->gluo('boom:now');
        $this->assertSame(1, $exitCode);
        $this->assertSame('', $stdOut);
        $this->assertSame("Something went wrong\n", $stdErr);

        [$exitCode, , $stdErr] = $this->gluo('boom:now', '-v');
        $this->assertSame(1, $exitCode);
        $this->assertStringStartsWith("Something went wrong\n\n#0 ", $stdErr);
        $this->assertStringContainsString('LauncherCommands->fail()', $stdErr);
    }

    public function testRegistryMustReturnAnArray(): void
    {
        file_put_contents($this->rootDir . '/config/commands.php', '<?php return "nope";');

        [$exitCode, , $stdErr] = $this->gluo('list');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('config/commands.php must return an array', $stdErr);
    }
}
