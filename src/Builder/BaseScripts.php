<?php

namespace ByJG\Gluo\Builder;

use ByJG\AnyDataset\Db\DatabaseExecutor;
use ByJG\Config\Config;
use ByJG\Config\Exception\ConfigException;
use ByJG\Config\Exception\ConfigNotFoundException;
use ByJG\Config\Exception\DependencyInjectionException;
use ByJG\Config\Exception\InvalidDateException;
use ByJG\Config\Exception\KeyNotFoundException;
use ByJG\DbMigration\Database\MySqlDatabase;
use ByJG\DbMigration\Database\PgsqlDatabase;
use ByJG\DbMigration\Database\SqliteDatabase;
use ByJG\DbMigration\Exception\DatabaseIsIncompleteException;
use ByJG\DbMigration\Exception\DatabaseNotVersionedException;
use ByJG\DbMigration\Exception\InvalidMigrationFile;
use ByJG\DbMigration\Migration;
use ByJG\JinjaPhp\Exception\TemplateParseException;
use ByJG\JinjaPhp\Loader\FileSystemLoader;
use ByJG\Util\Uri;
use Exception;
use OpenApi\Generator;
use Psr\SimpleCache\InvalidArgumentException;
use ReflectionException;

abstract class BaseScripts
{
    protected string|false $workdir;

    public function __construct()
    {
        $this->workdir = realpath(__DIR__ . '/../../../../..');
    }

    /**
     * Override to set the application namespace used in code generation.
     */
    protected function getAppNamespace(): string
    {
        return 'App';
    }

    /**
     * Override to change the paths scanned for OpenAPI attributes.
     * The package's Trait directory is always included so that models using
     * the Oa* traits keep their properties in the generated schema.
     */
    protected function getOpenApiScanPaths(): array
    {
        return [
            $this->workdir . '/src',
            __DIR__ . '/../Trait',
        ];
    }

    /**
     * Override to change the output path for openapi.json.
     */
    protected function getOpenApiOutputPath(): string
    {
        return $this->workdir . '/public/docs/openapi.json';
    }

    /**
     * Returns the codegen template directory, with scaffold override taking precedence.
     */
    protected function getCodegenTemplatePath(): string
    {
        $scaffoldPath = $this->workdir . '/templates/codegen';
        if (is_dir($scaffoldPath)) {
            return $scaffoldPath;
        }
        return __DIR__ . '/../../templates/codegen';
    }

    /**
     * Override to register additional migration database drivers.
     */
    protected function registerMigrationDatabases(): void
    {
        Migration::registerDatabase(MySqlDatabase::class);
        Migration::registerDatabase(PgsqlDatabase::class);
        Migration::registerDatabase(SqliteDatabase::class);
    }

    // --- Migrate ---

    /**
     * @throws ConfigNotFoundException
     * @throws InvalidArgumentException
     * @throws KeyNotFoundException
     * @throws ReflectionException
     * @throws Exception
     */
    public function runMigrate(array $arguments): void
    {
        $env = null;
        $filteredArgs = [];
        foreach ($arguments as $arg) {
            if (str_starts_with($arg, '--env=')) {
                $env = substr($arg, 6);
            } elseif ($arg === '--env' || $arg === '-e') {
                continue;
            } else {
                $filteredArgs[] = $arg;
            }
        }

        if (empty($env)) {
            $env = getenv('APP_ENV') ?: null;
        }

        if (empty($env)) {
            throw new Exception("Environment is required. Set APP_ENV or use --env parameter.\n\n" . $this->getMigrateHelp());
        }

        putenv("APP_ENV=$env");
        Config::reset();

        $dbConnection = Config::get('DBDRIVER_CONNECTION');

        echo "> Environment: $env\n";
        echo "> Database: " . preg_replace('/:[^:]+@/', ':****@', $dbConnection) . "\n\n";

        $command = null;
        $version = null;
        $force = false;
        $noTransaction = false;
        $verbosity = 0;

        foreach ($filteredArgs as $arg) {
            if (str_starts_with($arg, '--version=') || str_starts_with($arg, '-u=')) {
                $version = (int) substr($arg, strpos($arg, '=') + 1);
            } elseif ($arg === '--force') {
                $force = true;
            } elseif ($arg === '--no-transaction') {
                $noTransaction = true;
            } elseif ($arg === '-v') {
                $verbosity = 1;
            } elseif ($arg === '-vv') {
                $verbosity = 2;
            } elseif ($arg === '-vvv') {
                $verbosity = 3;
            } elseif (empty($command) && !str_starts_with($arg, '-')) {
                $command = $arg;
            }
        }

        if (empty($command)) {
            throw new Exception("Command is required.\n\n" . $this->getMigrateHelp());
        }

        if ($command === 'status') {
            $command = 'version';
        } elseif ($command === 'install') {
            $command = 'create';
        }

        $this->registerMigrationDatabases();

        $migrationPath = $this->workdir . "/db";
        $migration = new Migration(new Uri($dbConnection), $migrationPath);

        if (!$noTransaction) {
            $migration->withTransactionEnabled(true);
        }

        if ($verbosity > 0) {
            $migration->addCallbackProgress(function ($action, $version, $fileInfo) use ($verbosity) {
                if ($verbosity >= 2) {
                    echo "  [{$action}] Version {$version}: {$fileInfo['description']}\n";
                } else {
                    echo "  [{$action}] Version {$version}\n";
                }
            });
        }

        try {
            switch ($command) {
                case 'version':
                    $currentVersion = $migration->getCurrentVersion();
                    echo "Current version: {$currentVersion['version']}\n";
                    echo "Status: {$currentVersion['status']}\n";
                    break;

                case 'create':
                    echo "Creating migration version table...\n";
                    $migration->prepareEnvironment();
                    $migration->createVersion();
                    echo "Migration table created successfully.\n";
                    break;

                case 'reset':
                    echo "Resetting database...\n";
                    $migration->prepareEnvironment();
                    $migration->reset($version);
                    echo "Database reset successfully" . ($version !== null ? " to version {$version}" : "") . ".\n";
                    break;

                case 'up':
                    echo "Migrating up" . ($version !== null ? " to version {$version}" : " to latest version") . "...\n";
                    $migration->up($version, $force);
                    echo "Migration completed successfully.\n";
                    break;

                case 'down':
                    echo "Migrating down" . ($version !== null ? " to version {$version}" : " to version 0") . "...\n";
                    $migration->down($version, $force);
                    echo "Migration completed successfully.\n";
                    break;

                case 'update':
                    echo "Updating to version " . ($version !== null ? $version : "latest") . "...\n";
                    $migration->update($version, $force);
                    echo "Migration completed successfully.\n";
                    break;

                default:
                    throw new Exception("Unknown command: {$command}\n\n" . $this->getMigrateHelp());
            }
        } catch (DatabaseIsIncompleteException $e) {
            throw new Exception("Database is in an incomplete state. Use --force to override.\n" . $e->getMessage());
        } catch (DatabaseNotVersionedException $e) {
            throw new Exception("Database is not versioned. Run 'create' command first.\n" . $e->getMessage());
        } catch (InvalidMigrationFile $e) {
            throw new Exception("Invalid migration file.\n" . $e->getMessage());
        }
    }

    protected function getMigrateHelp(): string
    {
        return "Usage:\n" .
            "  APP_ENV=<environment> composer migrate -- <command> [options]\n" .
            "  composer migrate -- --env=<environment> <command> [options]\n\n" .
            $this->getEnvironmentHelpText() .
            "Available Commands:\n" .
            "  version               Show current database version (alias: status)\n" .
            "  create                Create migration version table (alias: install)\n" .
            "  reset                 Reset database to base.sql and optionally migrate to a version\n" .
            "  up                    Migrate up to a specific version or latest\n" .
            "  down                  Migrate down to a specific version or 0\n" .
            "  update                Intelligently migrate up or down to a specific version\n\n" .
            "Options:\n" .
            "  -u=N, --version=N     Target version for migration\n" .
            "  --force               Force migration even if database is in partial state\n" .
            "  --no-transaction      Disable transaction support\n" .
            "  -v, -vv, -vvv         Increase verbosity\n\n" .
            "Examples:\n" .
            "  APP_ENV=dev composer migrate -- version\n" .
            "  APP_ENV=dev composer migrate -- reset --version=5\n" .
            "  composer migrate -- --env=dev up -vv\n";
    }

    protected function getEnvironmentHelpText(): string
    {
        return "Environment:\n" .
            "  --env=<environment>   Environment (dev, test, prod) - overrides APP_ENV\n" .
            "  APP_ENV               Environment variable (used if --env not specified)\n\n";
    }

    // --- OpenAPI ---

    public function runGenOpenApiDocs(array $arguments): void
    {
        $outputPath = $this->getOpenApiOutputPath();
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $generator = (new Generator())->setConfig(["operationId.hash" => false]);
        $openapi = $generator->generate($this->getOpenApiScanPaths());
        file_put_contents($outputPath, $openapi->toJson());
    }

    // --- Code Generator ---

    /**
     * @throws ConfigException
     * @throws ConfigNotFoundException
     * @throws DependencyInjectionException
     * @throws InvalidArgumentException
     * @throws InvalidDateException
     * @throws KeyNotFoundException
     * @throws ReflectionException
     * @throws TemplateParseException
     * @throws Exception
     */
    public function runCodeGenerator(array $arguments): void
    {
        $table = null;
        foreach ($arguments as $index => $arg) {
            if (str_starts_with($arg, "--table=")) {
                $table = substr($arg, 8);
                unset($arguments[$index]);
                break;
            } elseif ($arg === "--table") {
                $table = $arguments[$index + 1] ?? null;
                unset($arguments[$index + 1]);
                unset($arguments[$index]);
                break;
            }
        }
        $arguments = array_values($arguments);

        if (empty($table)) {
            throw new Exception("Table name is required.\n\n" . $this->getCodeGeneratorHelp());
        }

        $argumentList = $this->extractArguments($arguments, false);
        $env = $this->getEnvironment($argumentList, $this->getCodeGeneratorHelp());

        echo "Environment: $env\n";

        putenv("APP_ENV=$env");
        Config::reset();

        $isActiveRecord = in_array("--activerecord", $arguments);

        $foundArguments = [];
        $validArguments = ['model', 'repo', 'repository', 'service', 'controller', 'test', 'all', "--save", "--debug", "--env", "--activerecord", "--table"];
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, "--env=") || str_starts_with($argument, "--table=")) {
                continue;
            }
            if (!in_array($argument, $validArguments)) {
                throw new Exception("Invalid argument: $argument\n\n" . $this->getCodeGeneratorHelp());
            }
            $foundArguments[] = $argument;
        }
        if (empty($foundArguments)) {
            throw new Exception("At least one argument is required.\n\n" . $this->getCodeGeneratorHelp());
        }

        $save = in_array("--save", $arguments);

        /** @var DatabaseExecutor $executor */
        $executor = Config::get(DatabaseExecutor::class);

        $tableDefinition = $executor->getIterator("EXPLAIN " . strtolower($table))->toArray();
        $tableIndexes = $executor->getIterator("SHOW INDEX FROM " . strtolower($table))->toArray();

        // Foreign keys, so the generated model can declare parentTable for FK columns.
        // MySQL-specific (information_schema), consistent with the EXPLAIN/SHOW INDEX above.
        $foreignKeys = $executor->getIterator(
            "SELECT column_name, referenced_table_name FROM information_schema.key_column_usage "
            . "WHERE table_schema = database() AND table_name = :t AND referenced_table_name IS NOT NULL",
            ['t' => strtolower($table)]
        )->toArray();

        $data = $this->buildCodegenData($table, $tableDefinition, $tableIndexes, $isActiveRecord, $foreignKeys);

        if (in_array("--debug", $arguments)) {
            print_r($data);
        }

        $this->generateArtifacts($arguments, $data, $isActiveRecord, $save, $table);
    }

    /**
     * Convert raw EXPLAIN/SHOW INDEX rows into the template data array.
     * Kept separate from runCodeGenerator so it can be tested without a database.
     */
    protected function buildCodegenData(string $table, array $tableDefinition, array $tableIndexes, bool $isActiveRecord, array $foreignKeys = []): array
    {
        $autoIncrement = false;

        // Map FK column name -> referenced (parent) table, for parentTable in the model.
        $parentTableByColumn = [];
        foreach ($foreignKeys as $fk) {
            if (!empty($fk['column_name']) && !empty($fk['referenced_table_name'])) {
                $parentTableByColumn[$fk['column_name']] = $fk['referenced_table_name'];
            }
        }

        foreach ($tableDefinition as $key => $field) {
            $type = preg_replace('/\(.*/', '', $field['type']);

            $tableDefinition[$key]['property'] = preg_replace_callback('/_(.?)/', function ($matches) {
                return strtoupper($matches[1]);
            }, $field['field']);

            // Parent table for a foreign-key column (empty string when not an FK).
            $tableDefinition[$key]['parent_table'] = $parentTableByColumn[$field['field']] ?? '';

            if ($field['extra'] == 'auto_increment') {
                $autoIncrement = true;
            }

            switch ($type) {
                case 'int':
                case 'tinyint':
                case 'smallint':
                    $tableDefinition[$key]['php_type'] = 'int';
                    $tableDefinition[$key]['openapi_type'] = 'integer';
                    $tableDefinition[$key]['openapi_format'] = 'int32';
                    break;
                case 'mediumint':
                case 'bigint':
                case 'integer':
                    $tableDefinition[$key]['php_type'] = 'int';
                    $tableDefinition[$key]['openapi_type'] = 'integer';
                    $tableDefinition[$key]['openapi_format'] = 'int64';
                    break;
                case 'float':
                case 'double':
                case 'decimal':
                    $tableDefinition[$key]['php_type'] = 'float';
                    $tableDefinition[$key]['openapi_type'] = 'number';
                    $tableDefinition[$key]['openapi_format'] = 'double';
                    break;
                case 'bool':
                case 'boolean':
                    $tableDefinition[$key]['php_type'] = 'bool';
                    $tableDefinition[$key]['openapi_type'] = 'boolean';
                    $tableDefinition[$key]['openapi_format'] = 'boolean';
                    break;
                case 'date':
                    $tableDefinition[$key]['php_type'] = 'string';
                    $tableDefinition[$key]['openapi_type'] = 'string';
                    $tableDefinition[$key]['openapi_format'] = 'date';
                    break;
                case 'datetime':
                case 'timestamp':
                    $tableDefinition[$key]['php_type'] = 'string';
                    $tableDefinition[$key]['openapi_type'] = 'string';
                    $tableDefinition[$key]['openapi_format'] = 'date-time';
                    break;
                default:
                    $tableDefinition[$key]['php_type'] = 'string';
                    $tableDefinition[$key]['openapi_type'] = 'string';
                    $tableDefinition[$key]['openapi_format'] = 'string';
            }
        }

        $nullableFields = [];
        foreach ($tableDefinition as $field) {
            if ($field['null'] == 'YES') {
                $nullableFields[] = $field["property"];
            }
        }

        $primaryKeys = [];
        foreach ($tableDefinition as $field) {
            if ($field['key'] == 'PRI') {
                $primaryKeys[] = $field["property"];
            }
        }

        $nonNullableFields = [];
        foreach ($tableDefinition as $field) {
            if ($field['null'] == 'NO' && $field['key'] != 'PRI') {
                $nonNullableFields[] = $field["property"];
            }
        }

        foreach ($tableIndexes as $key => $field) {
            $tableIndexes[$key]['camelColumnName'] = preg_replace_callback('/_(.?)/', function ($match) {
                return strtoupper($match[1]);
            }, $field['column_name']);
        }

        $hasCreatedAt = false;
        $hasUpdatedAt = false;
        $hasDeletedAt = false;
        foreach ($tableDefinition as $field) {
            if ($field['field'] == 'created_at') $hasCreatedAt = true;
            if ($field['field'] == 'updated_at') $hasUpdatedAt = true;
            if ($field['field'] == 'deleted_at') $hasDeletedAt = true;
        }

        return [
            'namespace' => $this->getAppNamespace(),
            'autoIncrement' => $autoIncrement ? 'yes' : 'no',
            'restTag' => ucwords(explode('_', strtolower($table))[0]),
            'restPath' => str_replace('_', '/', strtolower($table)),
            'className' => preg_replace_callback('/(?:^|_)(.?)/', function ($match) {
                return strtoupper($match[1]);
            }, $table),
            'varTableName' => preg_replace_callback('/_(.?)/', function ($matches) {
                return strtoupper($matches[1]);
            }, $table),
            'tableName' => strtolower($table),
            'fields' => $tableDefinition,
            'primaryKeys' => $primaryKeys,
            'nullableFields' => $nullableFields,
            'nonNullableFields' => $nonNullableFields,
            'indexes' => $tableIndexes,
            'activerecord' => $isActiveRecord,
            'hasCreatedAt' => $hasCreatedAt,
            'hasUpdatedAt' => $hasUpdatedAt,
            'hasDeletedAt' => $hasDeletedAt,
        ];
    }

    /**
     * Render a single codegen template (e.g. 'model.php') with the given data.
     */
    protected function renderCodegenTemplate(string $templateName, array $data): string
    {
        $loader = new FileSystemLoader($this->getCodegenTemplatePath());
        return $loader->getTemplate($templateName)->render($data);
    }

    /**
     * @throws TemplateParseException
     */
    protected function generateArtifacts(array $arguments, array $data, bool $isActiveRecord, bool $save, string $table): void
    {
        if (in_array('all', $arguments) || in_array('model', $arguments)) {
            $modelType = $isActiveRecord ? "ActiveRecord Model" : "Model";
            echo "Processing $modelType for table $table...\n";
            $rendered = $this->renderCodegenTemplate('model.php', $data);
            if ($save) {
                $file = $this->workdir . '/src/Model/' . $data['className'] . '.php';
                file_put_contents($file, $rendered);
                echo "File saved in $file\n";
            } else {
                print_r($rendered);
            }
        }

        if (!$isActiveRecord && (in_array('all', $arguments) || in_array('repo', $arguments) || in_array('repository', $arguments))) {
            echo "Processing Repository for table $table...\n";
            $rendered = $this->renderCodegenTemplate('repository.php', $data);
            if ($save) {
                $file = $this->workdir . '/src/Repository/' . $data['className'] . 'Repository.php';
                file_put_contents($file, $rendered);
                echo "File saved in $file\n";
                $this->addToConfig($this->workdir . '/config/dev/04-repositories.php', $data['className'] . 'Repository', $data['namespace']);
            } else {
                print_r($rendered);
            }
        }

        if (!$isActiveRecord && (in_array('all', $arguments) || in_array('service', $arguments))) {
            echo "Processing Service for table $table...\n";
            $rendered = $this->renderCodegenTemplate('service.php', $data);
            if ($save) {
                $file = $this->workdir . '/src/Service/' . $data['className'] . 'Service.php';
                file_put_contents($file, $rendered);
                echo "File saved in $file\n";
                $this->addToConfig($this->workdir . '/config/dev/05-services.php', $data['className'] . 'Service', $data['namespace']);
            } else {
                print_r($rendered);
            }
        }

        if (in_array('all', $arguments) || in_array('controller', $arguments)) {
            $restType = $isActiveRecord ? "ActiveRecord Controller" : "Controller";
            $templateName = $isActiveRecord ? 'controlleractiverecord.php' : 'controller.php';
            echo "Processing $restType for table $table...\n";
            $rendered = $this->renderCodegenTemplate($templateName, $data);
            if ($save) {
                $file = $this->workdir . '/src/Controller/' . $data['className'] . 'Controller.php';
                file_put_contents($file, $rendered);
                echo "File saved in $file\n";
            } else {
                print_r($rendered);
            }
        }

        if (in_array('all', $arguments) || in_array('test', $arguments)) {
            echo "Processing Test for table $table...\n";
            $rendered = $this->renderCodegenTemplate('test.php', $data);
            if ($save) {
                $file = $this->workdir . '/tests/Controller/' . $data['className'] . 'Test.php';
                file_put_contents($file, $rendered);
                echo "File saved in $file\n";
            } else {
                print_r($rendered);
            }
        }
    }

    protected function getCodeGeneratorHelp(): string
    {
        return "Usage:\n" .
            "  APP_ENV=<environment> composer codegen -- --table=<table_name> <arguments> [options]\n" .
            "  composer codegen -- --env=<environment> --table=<table_name> <arguments> [options]\n\n" .
            "Required:\n" .
            "  --table=<name>        Database table name\n\n" .
            $this->getEnvironmentHelpText() .
            "Arguments (at least one required):\n" .
            "  all                   Generate all components for the selected pattern\n" .
            "  model                 Generate Model\n" .
            "  repo|repository       Generate Repository (Repository pattern only)\n" .
            "  service               Generate Service (Repository pattern only)\n" .
            "  controller            Generate REST controller\n" .
            "  test                  Generate Test\n\n" .
            "Options:\n" .
            "  --activerecord        Use ActiveRecord pattern instead of Repository pattern\n" .
            "  --save                Save generated files to disk\n" .
            "  --debug               Show debug information\n";
    }

    protected function extractArguments(array $arguments, bool $hasCmd = true): array
    {
        $ret = ['--up-to' => null, '--yes' => null, '--force' => false, '--env' => null];

        $commandFound = false;
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--')) {
                $args = explode("=", $argument, 2);
                $ret[$args[0]] = $args[1] ?? true;
            } elseif ($hasCmd && !$commandFound) {
                $ret['command'] = $argument;
                $commandFound = true;
            }
        }

        return $ret;
    }

    protected function getEnvironment(array $argumentList, string $helpText): string
    {
        $env = $argumentList['--env'] ?? getenv('APP_ENV') ?: null;

        if (empty($env)) {
            throw new \Exception("Environment is required. Set APP_ENV or use --env parameter.\n\n" . $helpText);
        }

        return $env;
    }

    protected function addToConfig(string $configFile, string $className, string $namespace): void
    {
        $contents = file_get_contents($configFile);
        $modified = false;

        $type = str_ends_with($className, 'Repository') ? 'Repository' : 'Service';
        $fullClassName = "$namespace\\{$type}\\$className";
        $useStatement = "use $fullClassName;";

        if (!str_contains($contents, $useStatement)) {
            $lines = explode("\n", $contents);
            $lastUseLine = 0;
            foreach ($lines as $index => $line) {
                if (preg_match('/^use\s+.*?;/', trim($line))) {
                    $lastUseLine = $index;
                }
            }
            array_splice($lines, $lastUseLine + 1, 0, $useStatement);
            $contents = implode("\n", $lines);
            $modified = true;
            echo "Added use statement for $className to " . basename($configFile) . "\n";
        }

        if (!str_contains($contents, "$className::class")) {
            $binding = "\n    $className::class => DI::bind($className::class)\n" .
                "        ->withInjectedConstructor()\n" .
                "        ->toSingleton(),\n";
            $pos = strrpos($contents, '];');
            if ($pos !== false) {
                $contents = substr_replace($contents, $binding, $pos, 0);
                $modified = true;
                echo "Added DI binding for $className to " . basename($configFile) . "\n";
            }
        }

        if ($modified) {
            file_put_contents($configFile, $contents);
        } else {
            echo "$className already exists in " . basename($configFile) . "\n";
        }
    }
}