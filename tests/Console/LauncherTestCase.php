<?php

namespace Tests\Console;

use ByJG\Gluo\Console\Launcher;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Tests\Fixture\ArrayCommands;
use Tests\Fixture\LauncherCommands;
use Tests\Fixture\RecordingScripts;

abstract class LauncherTestCase extends TestCase
{
    protected string $rootDir;
    protected RecordingScripts $scripts;
    /** @var resource */
    protected $stdErr;
    protected string|false $originalAppEnv;

    /** @var array<string, string> The project commands. */
    protected array $commands = [];

    /** The Gluo container; null for the Config one. */
    protected ?ContainerInterface $container = null;

    #[\Override]
    protected function setUp(): void
    {
        $this->originalAppEnv = getenv('APP_ENV');
        putenv('APP_ENV');

        $this->rootDir = sys_get_temp_dir() . '/gluo-launcher-' . uniqid();
        mkdir($this->rootDir . '/config', 0777, true);

        $this->scripts = new RecordingScripts();
        $this->stdErr = fopen('php://memory', 'w+');

        // The container knows LauncherCommands only; anything else is "not registered".
        $this->container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                return new LauncherCommands('built by container');
            }

            public function has(string $id): bool
            {
                return $id === LauncherCommands::class;
            }
        };
    }

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->originalAppEnv === false) {
            putenv('APP_ENV');
        } else {
            putenv('APP_ENV=' . $this->originalAppEnv);
        }

        fclose($this->stdErr);
        foreach (glob($this->rootDir . '/config/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->rootDir . '/config');
        rmdir($this->rootDir);
    }

    /**
     * Run `php gluo <arguments>` and return [exit code, stdout, stderr].
     *
     * @return array{0: int, 1: string, 2: string}
     */
    protected function gluo(string ...$arguments): array
    {
        ftruncate($this->stdErr, 0);
        rewind($this->stdErr);

        ob_start();
        try {
            $exitCode = (new Launcher($this->rootDir, new ArrayCommands($this->commands), $this->scripts, $this->stdErr, $this->container))->run(['gluo', ...$arguments]);
        } finally {
            $stdOut = (string) ob_get_clean();
        }

        rewind($this->stdErr);

        return [$exitCode, $stdOut, (string) stream_get_contents($this->stdErr)];
    }
}
