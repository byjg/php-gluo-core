<?php

namespace Tests\Console;

use ByJG\Scriptify\Scriptify;
use ByJG\Scriptify\ServiceWriter;

/**
 * `php gluo install`: a service that runs the gluo script itself, its command line written in full.
 */
class InstallTest extends LauncherTestCase
{
    protected const TARGET = 'Tests/Fixture/LauncherCommands::cleanOldRecords';

    protected string $serviceDir;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->serviceDir = sys_get_temp_dir() . '/gluo-services-' . uniqid();
        mkdir($this->serviceDir);
        Scriptify::setWriter(new ServiceWriter($this->serviceDir));
        $this->commands = ['payment:sweep' => self::TARGET, 'other:run' => 'Tests/Fixture/RecordingScripts::runCheckOpenApi'];
    }

    #[\Override]
    protected function tearDown(): void
    {
        Scriptify::setWriter(null);
        foreach (glob($this->serviceDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->serviceDir);
        parent::tearDown();
    }

    /**
     * @return string[] The files written.
     */
    protected function written(): array
    {
        return array_map('basename', glob($this->serviceDir . '/*') ?: []);
    }

    protected function serviceName(): string
    {
        return basename($this->rootDir) . '-payment-sweep';
    }

    public function testASystemdServiceRunsGluoWithTheEnvironmentTheCommandAndItsArguments(): void
    {
        [$exitCode, $stdOut, $stdErr] = $this->gluo('--env=prod', 'install', 'payment:sweep', '30', 'audit log');

        $command = PHP_BINARY . " '{$this->rootDir}/gluo' --env='prod' payment:sweep '30' 'audit log'";
        $this->assertSame('', $stdErr);
        $this->assertSame(0, $exitCode);
        $this->assertSame("Installed the systemd service \"{$this->serviceName()}\": $command\n", $stdOut);

        $service = (string) file_get_contents("{$this->serviceDir}/{$this->serviceName()}.service");
        $this->assertStringContainsString("WorkingDirectory={$this->rootDir}\n", $service);
        $this->assertStringContainsString("ExecStart=$command --daemon\n", $service);
        $this->assertStringContainsString("Description=payment:sweep (prod)\n", $service);
        // The environment is on the command line; the service's variables file is empty.
        $this->assertSame('', file_get_contents("{$this->serviceDir}/{$this->serviceName()}.env"));
        // Installing runs nothing.
        $this->assertStringNotContainsString('built by container', $stdOut);
    }

    public function testTheTemplateTheNameAndTheDescriptionCanBeChosen(): void
    {
        putenv('APP_ENV=staging');

        [$exitCode, , $stdErr] = $this->gluo('install', '--template=crond', '--name', 'sweeper', '--description=Nightly sweep', 'payment:sweep');

        $this->assertSame('', $stdErr);
        $this->assertSame(0, $exitCode);
        $this->assertSame(['sweeper', 'sweeper.env'], $this->written());
        $cron = (string) file_get_contents("{$this->serviceDir}/sweeper");
        $this->assertStringContainsString(
            "root     cd \"{$this->rootDir}\" &&  " . PHP_BINARY . " '{$this->rootDir}/gluo' --env='staging' payment:sweep \n",
            $cron
        );
    }

    public function testWithoutAnEnvironmentNothingIsInstalled(): void
    {
        [$exitCode, , $stdErr] = $this->gluo('install', 'payment:sweep');

        $this->assertSame(1, $exitCode);
        $this->assertSame("The service needs an environment: php gluo --env=<env> install payment:sweep\n", $stdErr);
        $this->assertSame([], $this->written());
    }

    public function testACommandThatIsNotOkIsNotInstalled(): void
    {
        [$exitCode, , $stdErr] = $this->gluo('--env=prod', 'install', 'other:run');
        $this->assertSame(1, $exitCode);
        $this->assertStringStartsWith('Cannot install "other:run": NOT_IN_CONTAINER ', $stdErr);

        [$exitCode, , $stdErr] = $this->gluo('--env=prod', 'install', 'nope');
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Command "nope" is not defined', $stdErr);

        [$exitCode, , $stdErr] = $this->gluo('--env=prod', 'install', '--user=root', 'payment:sweep');
        $this->assertSame(1, $exitCode);
        $this->assertSame("Unknown option --user. Use: --template, --name, --description.\n", $stdErr);

        $this->assertSame([], $this->written());
    }

    public function testUninstallFindsTheServiceByTheSameName(): void
    {
        // Scriptify removes from /etc, where these tests write nothing: the name it looked for is the point.
        [$exitCode, , $stdErr] = $this->gluo('uninstall', 'payment:sweep');

        $this->assertSame(1, $exitCode);
        $this->assertSame("Service '{$this->serviceName()}' does not exists\n", $stdErr);
    }

    public function testHelpShowsTheUsage(): void
    {
        [$exitCode, $stdOut] = $this->gluo('install', '--help');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('php gluo --env=<env> install [--template=systemd|crond|upstart|initd] [--name=<service>]', $stdOut);
        $this->assertSame([], $this->written());
    }
}
