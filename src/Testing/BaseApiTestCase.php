<?php

namespace ByJG\Gluo\Testing;

use ByJG\ApiTools\Base\Schema;
use ByJG\ApiTools\OpenApiValidation;
use ByJG\Config\Config;
use ByJG\DbMigration\Migration;
use ByJG\Gluo\Builder\BaseScripts;
use ByJG\Util\Uri;
use ByJG\WebRequest\Psr7\Request;
use Exception;
use Override;
use PHPUnit\Framework\TestCase;

abstract class BaseApiTestCase extends TestCase
{
    use OpenApiValidation;

    protected static bool $databaseReset = false;

    #[Override]
    protected function setUp(): void
    {
        $openApiPath = $this->getOpenApiPath();
        $openApiJson = file_get_contents($openApiPath);
        if ($openApiJson === false) {
            throw new Exception("Could not read the OpenAPI definition at $openApiPath");
        }

        $this->setSchema(Schema::fromJson($openApiJson));
        $this->resetDb();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->setSchema(null);
    }

    /**
     * Override to return the path to your openapi.json file.
     */
    abstract protected function getOpenApiPath(): string;

    /**
     * The migration database class used for the reset. By default (null) every class in
     * BaseScripts::MIGRATION_DATABASES is registered and the scheme of DBDRIVER_CONNECTION picks
     * one, so a MySQL, PostgreSQL or SQLite project needs no override.
     *
     * @return class-string|null
     */
    protected function getDatabaseClass(): ?string
    {
        return null;
    }

    /**
     * Override to change the path to your db/ migrations directory.
     */
    protected function getMigrationsPath(): string
    {
        return dirname($this->getOpenApiPath(), 3) . '/db';
    }

    public function getPsr7Request(): Request
    {
        $uri = Uri::getInstanceFromString()
            ->withScheme(Config::get("API_SCHEMA"))
            ->withHost(Config::get("API_SERVER"));

        return Request::getInstance($uri);
    }

    public function resetDb(): void
    {
        if (!self::$databaseReset) {
            if (Config::definition()?->getCurrentEnvironment() != "test") {
                throw new Exception("This test can only be executed in test environment");
            }
            $databaseClass = $this->getDatabaseClass();
            foreach ($databaseClass !== null ? [$databaseClass] : BaseScripts::MIGRATION_DATABASES as $class) {
                Migration::registerDatabase($class);
            }
            $migration = new Migration(new Uri(Config::get('DBDRIVER_CONNECTION')), $this->getMigrationsPath());
            $migration->prepareEnvironment();
            $migration->reset();
            self::$databaseReset = true;
        }
    }
}