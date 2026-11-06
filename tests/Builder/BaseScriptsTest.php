<?php

namespace Tests\Builder;

use Tests\Fixture\ExposedScripts;
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

    public function testRunTestRunsPhpUnitWithTheArgumentsAndReturnsItsExitCode(): void
    {
        $dir = $this->makeTempDir();
        mkdir($dir . '/vendor/bin', 0755, true);
        file_put_contents($dir . '/vendor/bin/phpunit', "<?php\necho json_encode(array_slice(\$argv, 1));\nexit(3);\n");
        $this->scripts->setWorkdir($dir);

        ob_start();
        $exitCode = $this->scripts->runTest(['--filter=a b', '--testdox']);
        $output = ob_get_clean();

        $this->assertSame(3, $exitCode);
        $this->assertSame('["--filter=a b","--testdox"]', $output);
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

    /**
     * A project whose src has one class with OpenAPI attributes; the scripts work in it.
     */
    protected function makeSpecProject(): string
    {
        $dir = $this->makeTempDir();
        mkdir($dir . '/src', 0755, true);
        file_put_contents($dir . '/src/SpecVersionProbe.php', <<<'PHP'
<?php

namespace Tests\Probe;

use ByJG\Gluo\Trait\OaCreatedAt;
use ByJG\Gluo\Trait\OaDeletedAt;
use ByJG\Gluo\Trait\OaUpdatedAt;
use OpenApi\Attributes as OA;

#[OA\Info(version: "1.0.0", title: "Probe")]
#[OA\Schema(required: ["name"], type: "object")]
class SpecVersionProbe
{
    // As a generated model does; the scan always includes these traits.
    use OaCreatedAt, OaUpdatedAt, OaDeletedAt;

    #[OA\Property(type: "string", format: "string", maxLength: 100, minLength: 1)]
    public ?string $name = null;

    #[OA\Property(type: "string", format: "string", nullable: true)]
    public ?string $note = null;

    #[OA\Get(path: "/probe", responses: [new OA\Response(response: 200, description: "OK")])]
    public function get(): void
    {
    }
}
PHP);
        // swagger-php reads the attributes through reflection, so the class has to be loadable,
        // once per run: another temporary copy would declare it again.
        if (!class_exists('Tests\\Probe\\SpecVersionProbe', false)) {
            require_once $dir . '/src/SpecVersionProbe.php';
        }
        $this->scripts->setWorkdir($dir);
        return $dir;
    }

    public function testOpenApiIsGeneratedAsVersion31(): void
    {
        $dir = $this->makeSpecProject();

        $this->scripts->runGenOpenApiDocs([]);

        $spec = json_decode(file_get_contents($dir . '/public/docs/openapi.json'), true);
        $this->assertSame('3.1.0', $spec['openapi']);
        $properties = $spec['components']['schemas']['SpecVersionProbe']['properties'];
        // 3.1 writes nullable as a type union instead of the 3.0 "nullable" keyword.
        $this->assertSame(['string', 'null'], $properties['note']['type']);
        $this->assertArrayNotHasKey('nullable', $properties['note']);
        $this->assertSame(1, $properties['name']['minLength']);
        $this->assertSame(100, $properties['name']['maxLength']);
    }

    public function testASpecJustGeneratedIsCurrent(): void
    {
        $this->makeSpecProject();
        $this->scripts->runGenOpenApiDocs([]);

        $this->assertNull($this->scripts->runCheckOpenApi());
    }

    /**
     * git rewrites the dates of the files it checks out: a source newer than the spec is not
     * a stale spec when it generates the same.
     */
    public function testASourceNewerThanTheSpecIsNotAStaleSpec(): void
    {
        $dir = $this->makeSpecProject();
        $this->scripts->runGenOpenApiDocs([]);
        touch($dir . '/src/SpecVersionProbe.php', time() + 3600);

        $this->assertNull($this->scripts->runCheckOpenApi());
    }

    public function testAMissingSpecIsReported(): void
    {
        $this->makeSpecProject();

        $this->assertSame([
            'public/docs/openapi.json does not exist.',
            'Routes are read from it, so every endpoint will 404.',
        ], $this->scripts->runCheckOpenApi());
    }

    /**
     * The committed spec, generated before the sources changed: `$change` edits it.
     */
    protected function staleSpec(callable $change): void
    {
        $dir = $this->makeSpecProject();
        $this->scripts->runGenOpenApiDocs([]);
        $file = $dir . '/public/docs/openapi.json';
        $spec = json_decode(file_get_contents($file), true);
        $spec = $change($spec);
        file_put_contents($file, json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function testAnEndpointThatMovedIsReportedAsRemovedAndAdded(): void
    {
        $this->staleSpec(function (array $spec): array {
            $spec['paths']['/old-probe'] = $spec['paths']['/probe'];
            unset($spec['paths']['/probe']);
            return $spec;
        });

        $this->assertSame([
            'public/docs/openapi.json is not what the sources generate:',
            '  removed  GET /old-probe',
            '  added    GET /probe',
            'New or changed endpoints will 404 until it is rebuilt.',
        ], $this->scripts->runCheckOpenApi());
    }

    public function testAChangedEndpointAndSchemaAreReported(): void
    {
        $this->staleSpec(function (array $spec): array {
            $spec['paths']['/probe']['get']['responses']['200']['description'] = 'Fine';
            $spec['components']['schemas']['SpecVersionProbe']['properties']['name']['maxLength'] = 50;
            return $spec;
        });

        $this->assertSame([
            'public/docs/openapi.json is not what the sources generate:',
            '  changed  GET /probe',
            '  changed  schema SpecVersionProbe',
            'New or changed endpoints will 404 until it is rebuilt.',
        ], $this->scripts->runCheckOpenApi());
    }

    public function testAChangeOutsideTheEndpointsAndSchemasIsReported(): void
    {
        $this->staleSpec(function (array $spec): array {
            $spec['info']['title'] = 'Old title';
            return $spec;
        });

        $this->assertSame([
            'public/docs/openapi.json is not what the sources generate:',
            '  changed  the rest (info, servers, security...)',
            'New or changed endpoints will 404 until it is rebuilt.',
        ], $this->scripts->runCheckOpenApi());
    }

    public function testASpecThatIsNotJsonIsReported(): void
    {
        $dir = $this->makeSpecProject();
        mkdir($dir . '/public/docs', 0755, true);
        file_put_contents($dir . '/public/docs/openapi.json', '{"openapi": ');

        $this->assertSame(['public/docs/openapi.json is not valid JSON.'], $this->scripts->runCheckOpenApi());
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

    /**
     * `gluo openapi` also writes the routes (generated/routes.php) and the spec as a PHP array
     * (generated/openapi.php): a web request loads them from OPcache instead of reading openapi.json
     */
    public function testTheRoutesAndTheSpecAreCompiledWithTheSpec(): void
    {
        $dir = $this->makeSpecProject();

        $this->scripts->runGenOpenApiDocs([]);

        $routes = new \ByJG\RestServer\Route\CompiledRouteList($dir . '/generated/routes.php');
        $route = $routes->getRoute('GET', '/probe');
        $this->assertNotNull($route);
        $this->assertSame(['Tests\\Probe\\SpecVersionProbe', 'get'], $route->getClass());
        $this->assertSame(\ByJG\RestServer\OutputProcessor\JsonCleanOutputProcessor::class, $route->getOutputProcessor());

        $this->assertSame(
            json_decode(file_get_contents($dir . '/public/docs/openapi.json'), true),
            require $dir . '/generated/openapi.php'
        );
    }

    public function testAMissingCompiledFileIsReported(): void
    {
        $dir = $this->makeSpecProject();
        $this->scripts->runGenOpenApiDocs([]);
        unlink($dir . '/generated/routes.php');

        $this->assertSame([
            'generated/routes.php does not exist.',
            'Routes are read from it, so every endpoint will 404.',
        ], $this->scripts->runCheckOpenApi());
    }

    public function testAStaleCompiledFileIsReported(): void
    {
        $dir = $this->makeSpecProject();
        $this->scripts->runGenOpenApiDocs([]);
        file_put_contents($dir . '/generated/openapi.php', "<?php return [];\n");

        $this->assertSame([
            'generated/openapi.php is not what public/docs/openapi.json generates.',
            'Requests are validated against it.',
        ], $this->scripts->runCheckOpenApi());
    }

    /**
     * A project whose models do not use the Oa* traits: one controller, one model, no trait
     */
    protected function makeSpecProjectWithoutTraits(): string
    {
        $dir = $this->makeTempDir();
        mkdir($dir . '/src', 0755, true);
        file_put_contents($dir . '/src/PlainProbe.php', <<<'PHP'
<?php

namespace Tests\PlainProbe;

use OpenApi\Attributes as OA;

#[OA\Info(version: "1.0.0", title: "Plain probe")]
#[OA\Schema(required: ["name"], type: "object")]
class PlainProbe
{
    #[OA\Property(type: "string")]
    public ?string $name = null;

    #[OA\Get(path: "/plain", operationId: "GET::/plain::Tests\\PlainProbe\\PlainProbe::get", responses: [new OA\Response(response: 200, description: "OK")])]
    public function get(): void
    {
    }
}
PHP);
        if (!class_exists('Tests\\PlainProbe\\PlainProbe', false)) {
            require_once $dir . '/src/PlainProbe.php';
        }
        $this->scripts->setWorkdir($dir);
        return $dir;
    }

    /**
     * The warnings swagger-php triggers while the spec is generated
     *
     * @return string[]
     */
    protected function warningsOf(callable $generate): array
    {
        $warnings = [];
        set_error_handler(function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;
            return true;
        }, E_USER_WARNING | E_USER_NOTICE | E_WARNING);
        try {
            $generate();
        } finally {
            restore_error_handler();
        }
        return $warnings;
    }

    /**
     * A model that uses the Oa* traits has their properties in its schema
     */
    public function testTheTraitsPropertiesAreInTheSchemaOfAModelThatUsesThem(): void
    {
        $dir = $this->makeSpecProject();

        $warnings = $this->warningsOf(fn() => $this->scripts->runGenOpenApiDocs([]));

        $spec = json_decode(file_get_contents($dir . '/public/docs/openapi.json'), true);
        $properties = $spec['components']['schemas']['SpecVersionProbe']['properties'];
        $this->assertArrayHasKey('createdAt', $properties);
        $this->assertArrayHasKey('updatedAt', $properties);
        $this->assertArrayHasKey('deletedAt', $properties);
        $this->assertSame([], $warnings);
    }

    /**
     * When no class uses the Oa* traits, their properties belong to no schema: the spec is generated without
     * swagger-php's "Unexpected @OA\Property()" warnings
     */
    public function testTheTraitsAreNotScannedWhenNoClassUsesThem(): void
    {
        $dir = $this->makeSpecProjectWithoutTraits();

        $warnings = $this->warningsOf(fn() => $this->scripts->runGenOpenApiDocs([]));

        $this->assertSame([], $warnings);
        $spec = json_decode(file_get_contents($dir . '/public/docs/openapi.json'), true);
        $this->assertSame(['name'], array_keys($spec['components']['schemas']['PlainProbe']['properties']));
        $this->assertNull($this->scripts->runCheckOpenApi());
    }

    /**
     * Only the Oa* traits a class uses are scanned: one that no class uses would have a property in no schema
     */
    public function testOnlyTheTraitsAClassUsesAreScanned(): void
    {
        $dir = $this->makeTempDir();
        mkdir($dir . '/src', 0755, true);
        file_put_contents($dir . '/src/PartialProbe.php', <<<'PHP'
<?php

namespace Tests\PartialProbe;

use ByJG\Gluo\Trait\OaCreatedAt;
use OpenApi\Attributes as OA;

#[OA\Info(version: "1.0.0", title: "Partial probe")]
#[OA\Schema(required: ["name"], type: "object")]
class PartialProbe
{
    use OaCreatedAt;

    #[OA\Property(type: "string")]
    public ?string $name = null;

    #[OA\Get(path: "/partial", operationId: "GET::/partial::Tests\\PartialProbe\\PartialProbe::get", responses: [new OA\Response(response: 200, description: "OK")])]
    public function get(): void
    {
    }
}
PHP);
        if (!class_exists('Tests\\PartialProbe\\PartialProbe', false)) {
            require_once $dir . '/src/PartialProbe.php';
        }
        $this->scripts->setWorkdir($dir);

        $warnings = $this->warningsOf(fn() => $this->scripts->runGenOpenApiDocs([]));

        $this->assertSame([], $warnings);
        $spec = json_decode(file_get_contents($dir . '/public/docs/openapi.json'), true);
        $this->assertSame(['createdAt', 'name'], array_keys($spec['components']['schemas']['PartialProbe']['properties']));
    }
}
