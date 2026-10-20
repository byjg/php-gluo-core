<?php

namespace Tests\Console;

use ByJG\Config\Config;
use ByJG\Gluo\Console\Checker;
use ByJG\Config\Definition;
use ByJG\Config\Environment;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Container\ContainerInterface;
use RuntimeException;

class CheckerTest extends LauncherTestCase
{
    protected string $configDir;
    protected string $sqliteFile;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->configDir = sys_get_temp_dir() . '/gluo-checker-' . uniqid();
        $this->sqliteFile = $this->configDir . '/check.db';
        mkdir($this->configDir . '/dev', 0777, true);
        Config::reset();
    }

    #[\Override]
    protected function tearDown(): void
    {
        Config::reset();
        foreach ([$this->configDir . '/dev/config.php', $this->sqliteFile] as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        rmdir($this->configDir . '/dev');
        rmdir($this->configDir);
        parent::tearDown();
    }

    /**
     * Initialise Config for the "dev" environment with the given values.
     */
    protected function withConfig(array $values): void
    {
        file_put_contents($this->configDir . '/dev/config.php', '<?php return ' . var_export($values, true) . ';');
        putenv('APP_ENV=dev');
        Config::initialize(
            (new Definition())->withBaseDir($this->configDir)->addEnvironment(Environment::create('dev')),
            'dev'
        );
    }

    /**
     * @return array<string, string[]> command => the columns of its row
     */
    protected function rows(string $stdOut): array
    {
        $rows = [];
        foreach (explode("\n", $stdOut) as $line) {
            if (str_starts_with($line, '  ')) {
                $columns = preg_split('/\s+/', trim($line), 3);
                $rows[array_shift($columns)] = $columns;
            }
        }
        return $rows;
    }

    public function testEverythingOkExitsZeroAndBuildsNothing(): void
    {
        $this->withConfig(['DBDRIVER_CONNECTION' => 'sqlite://' . $this->sqliteFile]);
        $this->commands = ['user:cleanup' => 'Tests/Fixture/LauncherCommands::cleanOldRecords'];
        // has() answers, get() must never be called by --check.
        $this->container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new RuntimeException('--check must not build');
            }

            public function has(string $id): bool
            {
                return true;
            }
        };

        [$exitCode, $stdOut, $stdErr] = $this->gluo('list', '--check');
        $rows = $this->rows($stdOut);

        $this->assertSame('', $stdErr);
        $this->assertStringStartsWith("Environment: dev\n\nBuilt-in\n", $stdOut);
        foreach (['codegen:all', 'codegen:test', 'migrate:create', 'migrate:update', 'openapi', 'openapi:check', 'terminal'] as $name) {
            $this->assertSame(['OK'], $rows[$name], $name);
        }
        $this->assertSame(['OK', 'Tests/Fixture/LauncherCommands::cleanOldRecords'], $rows['user:cleanup']);
        $this->assertSame(0, $exitCode);
        // Nothing was executed through the project's Scripts either.
        $this->assertSame([], $this->scripts->calls);
    }

    public function testNoEnvironment(): void
    {
        [$exitCode, $stdOut] = $this->gluo('list', '--check');
        $rows = $this->rows($stdOut);

        $this->assertSame(1, $exitCode);
        $this->assertStringStartsWith("Environment: (not set)\n", $stdOut);
        $this->assertSame('NO_ENV', $rows['migrate:update'][0]);
        $this->assertSame('NO_ENV', $rows['codegen:all'][0]);
        // Commands that need no environment are unaffected.
        $this->assertSame(['OK'], $rows['openapi']);
    }

    public function testEnvOptionSelectsTheCheckedEnvironment(): void
    {
        [, $stdOut] = $this->gluo('--env=staging', 'list', '--check');

        $this->assertStringStartsWith("Environment: staging\n", $stdOut);
    }

    public function testConfigError(): void
    {
        // No Config was initialised and this package has no config/ConfigBootstrap.php.
        putenv('APP_ENV=dev');

        [$exitCode, $stdOut] = $this->gluo('list', '--check');
        $rows = $this->rows($stdOut);

        $this->assertSame(1, $exitCode);
        $this->assertSame('CONFIG_ERROR', $rows['migrate:update'][0]);
        $this->assertStringContainsString('ConfigBootstrap.php', $rows['migrate:update'][1]);
        $this->assertSame($rows['migrate:update'], $rows['codegen:all']);
    }

    public function testNoDatabaseConfig(): void
    {
        $this->withConfig(['SOMETHING_ELSE' => 'x']);

        [$exitCode, $stdOut] = $this->gluo('list', '--check');
        $rows = $this->rows($stdOut);

        $this->assertSame(1, $exitCode);
        $this->assertSame('NO_DB_CONFIG', $rows['migrate:update'][0]);
        $this->assertSame('NO_DB_CONFIG', $rows['codegen:all'][0]);
    }

    public function testDatabaseUnreachableMasksThePassword(): void
    {
        // Port 1 on localhost refuses the connection.
        $this->withConfig(['DBDRIVER_CONNECTION' => 'mysql://root:s3cr3tpw@127.0.0.1:1/nothing']);

        [$exitCode, $stdOut] = $this->gluo('list', '--check');
        $rows = $this->rows($stdOut);

        $this->assertSame(1, $exitCode);
        foreach (['codegen:all', 'codegen:controller', 'codegen:model', 'codegen:repository', 'codegen:service', 'codegen:test', 'migrate:create', 'migrate:down', 'migrate:reset', 'migrate:up', 'migrate:update', 'migrate:version'] as $name) {
            $this->assertSame('DB_UNREACHABLE', $rows[$name][0], $name);
            $this->assertNotEmpty($rows[$name][1], $name);
        }
        $this->assertStringNotContainsString('s3cr3tpw', $stdOut);
        $this->assertSame(['OK'], $rows['openapi']);
    }

    public static function maskPasswordProvider(): array
    {
        $refused = 'SQLSTATE[HY000] [2002] Connection refused (127.0.0.1:3306)';
        // connection, message, expected
        return [
            'short password in a URL' => [
                'mysql://root:0@127.0.0.1:3306/db',
                'Cannot open mysql://root:0@127.0.0.1:3306/db',
                'Cannot open mysql://root:****@127.0.0.1:3306/db',
            ],
            'password-only URL' => [
                'redis://:0@127.0.0.1:6379',
                'Cannot open redis://:0@127.0.0.1:6379',
                'Cannot open redis://:****@127.0.0.1:6379',
            ],
            'password "0" outside a URL' => ['mysql://root:0@127.0.0.1:3306/db', $refused, $refused],
            'short password outside a URL' => ['mysql://root:200@127.0.0.1:3306/db', $refused, $refused],
            'long password outside a URL' => [
                'mysql://root:s3cr3tpw@127.0.0.1/db',
                "Access denied, password 's3cr3tpw'",
                "Access denied, password '****'",
            ],
            'long password encoded outside a URL' => [
                'mysql://root:p%40ss1@127.0.0.1/db',
                "Access denied, password 'p%40ss1' / 'p@ss1'",
                "Access denied, password '****' / '****'",
            ],
        ];
    }

    #[DataProvider('maskPasswordProvider')]
    public function testMaskPassword(string $connection, string $message, string $expected): void
    {
        $checker = new class extends Checker {
            public static function mask(string $message, string $connection): string
            {
                return self::maskPassword($message, $connection);
            }
        };

        $this->assertSame($expected, $checker::mask($message, $connection));
    }

    /**
     * The statuses themselves are Scriptify's (its LauncherTest); here, what Gluo adds: the
     * built-in names are reserved, the rest of a built-in group is open, and the Gluo
     * container answers has().
     */
    public function testProjectStatuses(): void
    {
        $this->withConfig(['DBDRIVER_CONNECTION' => 'sqlite://' . $this->sqliteFile]);
        $this->commands = [
            'user:cleanup' => 'Tests/Fixture/LauncherCommands::cleanOldRecords',
            'migrate:update' => 'Tests/Fixture/LauncherCommands::cleanOldRecords',
            'install' => 'Tests/Fixture/LauncherCommands::cleanOldRecords',
            'migrate:seed' => 'Tests/Fixture/LauncherCommands::cleanOldRecords',
            'typo:method' => 'Tests/Fixture/LauncherCommands::cleanOldRecord',
            'not:registered' => 'Tests/Fixture/RecordingScripts::runCheckOpenApi',
        ];

        [$exitCode, $stdOut] = $this->gluo('list', '--check');
        $rows = $this->rows($stdOut);

        $this->assertSame(1, $exitCode);
        $this->assertSame(['OK', 'Tests/Fixture/LauncherCommands::cleanOldRecords'], $rows['user:cleanup']);
        $this->assertSame('OK', $rows['migrate:seed'][0]);
        $this->assertSame(['MISSING_METHOD', 'Tests/Fixture/LauncherCommands::cleanOldRecord'], $rows['typo:method']);
        $this->assertSame('NOT_IN_CONTAINER', $rows['not:registered'][0]);
        $this->assertStringContainsString("  migrate:update         RESERVED ", $stdOut);
        $this->assertStringContainsString("  install                RESERVED ", $stdOut);
    }

    /**
     * Without a container given, the project rows ask the Config container.
     */
    public function testTheConfigContainerAnswersByDefault(): void
    {
        $this->withConfig(['DBDRIVER_CONNECTION' => 'sqlite://' . $this->sqliteFile]);
        $this->commands = [
            'user:cleanup' => 'Tests/Fixture/LauncherCommands::cleanOldRecords',
            'no:class' => 'Tests/Fixture/DoesNotExist::run',
        ];
        // The Config container: the configuration has no definition for the command class.
        $this->container = null;

        [$exitCode, $stdOut] = $this->gluo('list', '--check');
        $rows = $this->rows($stdOut);

        $this->assertSame(1, $exitCode);
        $this->assertSame('NOT_IN_CONTAINER', $rows['user:cleanup'][0]);
        $this->assertSame('MISSING_CLASS', $rows['no:class'][0]);
        // The built-ins are checked independently of the container.
        $this->assertSame(['OK'], $rows['migrate:update']);
    }
}
