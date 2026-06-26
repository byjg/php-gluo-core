<?php

namespace ByJG\Gluo\Testing;

use ByJG\ApiTools\Base\Schema;
use ByJG\ApiTools\OpenApiValidation;
use ByJG\Config\Config;
use ByJG\DbMigration\Database\MySqlDatabase;
use ByJG\DbMigration\Migration;
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
        $this->setSchema(Schema::getInstance(file_get_contents($this->getOpenApiPath())));
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
     * Override to change the database driver used for migration reset.
     */
    protected function getDatabaseClass(): string
    {
        return MySqlDatabase::class;
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
            if (Config::definition()->getCurrentEnvironment() != "test") {
                throw new Exception("This test can only be executed in test environment");
            }
            Migration::registerDatabase($this->getDatabaseClass());
            $migration = new Migration(new Uri(Config::get('DBDRIVER_CONNECTION')), $this->getMigrationsPath());
            $migration->prepareEnvironment();
            $migration->reset();
            self::$databaseReset = true;
        }
    }
}