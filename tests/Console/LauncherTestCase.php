<?php

namespace Tests\Console;

use ByJG\Gluo\Console\Launcher;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\LauncherCommands;
use Tests\Fixture\RecordingScripts;

abstract class LauncherTestCase extends TestCase
{
    protected string $rootDir;
    protected RecordingScripts $scripts;
    /** @var resource */
    protected $stdErr;
    protected string|false $originalAppEnv;

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
        $this->writeBootstrap(
            'return new class implements \Psr\Container\ContainerInterface {'
            . ' public function get(string $id): mixed { return new \\' . LauncherCommands::class . '("built by container"); }'
            . ' public function has(string $id): bool { return $id === \\' . LauncherCommands::class . '::class; }'
            . ' };'
        );
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

    protected function writeBootstrap(string $body): void
    {
        file_put_contents($this->rootDir . '/' . Launcher::BOOTSTRAP_FILE, "<?php\n$body\n");
    }

    protected function writeCommands(array $commands): void
    {
        file_put_contents($this->rootDir . '/' . Launcher::COMMANDS_FILE, '<?php return ' . var_export($commands, true) . ';');
    }

    /**
     * Run `./gluo <arguments>` and return [exit code, stdout, stderr].
     *
     * @return array{0: int, 1: string, 2: string}
     */
    protected function gluo(string ...$arguments): array
    {
        ftruncate($this->stdErr, 0);
        rewind($this->stdErr);

        ob_start();
        try {
            $exitCode = (new Launcher($this->rootDir, $this->scripts, $this->stdErr))->run(['./gluo', ...$arguments]);
        } finally {
            $stdOut = (string) ob_get_clean();
        }

        rewind($this->stdErr);

        return [$exitCode, $stdOut, (string) stream_get_contents($this->stdErr)];
    }
}
