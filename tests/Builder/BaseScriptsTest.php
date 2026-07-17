<?php

namespace ByJGTest\Gluo\Builder;

use ByJGTest\Gluo\Fixture\ExposedScripts;
use Exception;
use PHPUnit\Framework\TestCase;

class BaseScriptsTest extends TestCase
{
    protected ExposedScripts $scripts;
    protected string|false $originalAppEnv;
    protected array $tempPaths = [];

    protected function setUp(): void
    {
        $this->scripts = new ExposedScripts();
        $this->originalAppEnv = getenv('APP_ENV');
        putenv('APP_ENV');
    }

    protected function tearDown(): void
    {
        if ($this->originalAppEnv === false) {
            putenv('APP_ENV');
        } else {
            putenv('APP_ENV=' . $this->originalAppEnv);
        }

        foreach ($this->tempPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->removeDir($path);
            }
        }
    }

    protected function removeDir(string $dir): void
    {
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    protected function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/gluo-scripts-' . uniqid();
        mkdir($dir, 0755, true);
        $this->tempPaths[] = $dir;
        return $dir;
    }

    protected function makeConfigFile(): string
    {
        $baseFile = tempnam(sys_get_temp_dir(), 'gluo-config-');
        $configFile = $baseFile . '.php';
        $this->tempPaths[] = $baseFile;
        $this->tempPaths[] = $configFile;
        file_put_contents($configFile, "<?php\n\nuse ByJG\\Config\\DependencyInjection as DI;\n\nreturn [\n];\n");
        return $configFile;
    }

    public function testDefaultAppNamespace(): void
    {
        $this->assertSame('App', $this->scripts->callGetAppNamespace());
    }

    public function testExtractArgumentsParsesFlagsAndValues(): void
    {
        $result = $this->scripts->callExtractArguments(['--env=dev', '--save', 'model'], false);

        $this->assertSame('dev', $result['--env']);
        $this->assertTrue($result['--save']);
        $this->assertArrayNotHasKey('command', $result);
    }

    public function testExtractArgumentsCapturesCommand(): void
    {
        $result = $this->scripts->callExtractArguments(['--env=dev', 'update', 'ignored'], true);

        $this->assertSame('update', $result['command']);
        $this->assertSame('dev', $result['--env']);
    }

    public function testGetEnvironmentFromArguments(): void
    {
        $this->assertSame('dev', $this->scripts->callGetEnvironment(['--env' => 'dev']));
    }

    public function testGetEnvironmentFallsBackToAppEnv(): void
    {
        putenv('APP_ENV=staging');

        $this->assertSame('staging', $this->scripts->callGetEnvironment(['--env' => null]));
    }

    public function testGetEnvironmentThrowsWhenMissing(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Environment is required');

        $this->scripts->callGetEnvironment(['--env' => null]);
    }

    public function testCodegenTemplatePathPrefersScaffoldOverride(): void
    {
        $workdir = $this->makeTempDir();
        mkdir($workdir . '/templates/codegen', 0755, true);
        $this->scripts->setWorkdir($workdir);

        $this->assertSame($workdir . '/templates/codegen', $this->scripts->callGetCodegenTemplatePath());
    }

    public function testCodegenTemplatePathFallsBackToPackage(): void
    {
        $workdir = $this->makeTempDir();
        $this->scripts->setWorkdir($workdir);

        $path = $this->scripts->callGetCodegenTemplatePath();

        $this->assertDirectoryExists($path);
        $this->assertFileExists($path . '/model.php.jinja');
    }

    public function testAddToConfigInsertsUseStatementAndBinding(): void
    {
        $configFile = $this->makeConfigFile();

        $this->scripts->callAddToConfig($configFile, 'ProductRepository', 'App');

        $contents = file_get_contents($configFile);
        $this->assertStringContainsString('use App\\Repository\\ProductRepository;', $contents);
        $this->assertStringContainsString('ProductRepository::class => DI::bind(ProductRepository::class)', $contents);
        $this->assertStringContainsString('->withInjectedConstructor()', $contents);
        $this->assertStringContainsString('->toSingleton(),', $contents);
    }

    public function testAddToConfigUsesServiceNamespaceForServices(): void
    {
        $configFile = $this->makeConfigFile();

        $this->scripts->callAddToConfig($configFile, 'ProductService', 'App');

        $contents = file_get_contents($configFile);
        $this->assertStringContainsString('use App\\Service\\ProductService;', $contents);
    }

    public function testAddToConfigIsIdempotent(): void
    {
        $configFile = $this->makeConfigFile();

        $this->scripts->callAddToConfig($configFile, 'ProductRepository', 'App');
        $afterFirstRun = file_get_contents($configFile);

        $this->scripts->callAddToConfig($configFile, 'ProductRepository', 'App');
        $afterSecondRun = file_get_contents($configFile);

        $this->assertSame($afterFirstRun, $afterSecondRun);
    }
}
