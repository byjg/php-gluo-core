<?php

namespace Tests\Util;

use ByJG\AnyDataset\Db\DatabaseExecutor;
use ByJG\AnyDataset\Db\Factory;
use ByJG\ApiTools\Base\Schema;
use ByJG\Gluo\Util\SchemaValidator;
use ByJG\MicroOrm\Repository;
use ByJG\RestServer\Exception\Error400Exception;
use OpenApi\Annotations\OpenApi;
use OpenApi\Attributes as OA;
use OpenApi\Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\Product;
use Tests\Fixture\ValidatedProduct;

class SchemaValidatorTest extends TestCase
{
    protected string $dbFile;
    protected DatabaseExecutor $executor;
    protected Repository $repository;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/gluo-validator-' . uniqid() . '.db';
        $this->executor = DatabaseExecutor::using(Factory::getDbInstance('sqlite://' . $this->dbFile));
        $this->executor->execute(
            'CREATE TABLE gluo_product (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(120) NULL,
                price FLOAT NULL,
                created_at DATETIME NULL
            )'
        );

        // The specification is generated from the model's attributes, as `php gluo openapi` does.
        $openapi = (new Generator())
            ->setVersion(OpenApi::VERSION_3_1_0)
            ->generate([__DIR__ . '/../Fixture/ValidatedProduct.php', __DIR__ . '/../../src/Trait'], null, false);
        $schema = Schema::fromJson($openapi->toJson());

        // The model's attribute declares the validator; it is given the specification here
        // because these tests have no application container.
        $this->repository = new Repository($this->executor, ValidatedProduct::class);
        $this->repository->setValidator(new SchemaValidator($schema));
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    protected function rowCount(): int
    {
        return (int)$this->executor->getScalar('select count(*) from gluo_product');
    }

    public function testAValidModelIsSavedWithoutItsGeneratedKey(): void
    {
        $product = (new ValidatedProduct())->setName('Keyboard')->setPrice(49.9);
        $this->repository->save($product);

        // The key is readOnly: not required on insert, and filled in by the database.
        $this->assertNotEmpty($product->getId());
        $this->assertSame(1, $this->rowCount());
    }

    public static function invalidProductProvider(): array
    {
        return [
            'empty name' => ['', 10.0, 'is shorter than minLength 1'],
            'name longer than the column' => [str_repeat('a', 121), 10.0, 'is longer than maxLength 120'],
            'negative price' => ['Keyboard', -5.0, 'is less than minimum 0'],
            'price above the range' => ['Keyboard', 100000000.0, 'is greater than maximum 99999999.99'],
            'missing name' => [null, 10.0, "Required property 'name'"],
        ];
    }

    #[DataProvider('invalidProductProvider')]
    public function testAnInvalidModelIsNotInserted(?string $name, float $price, string $message): void
    {
        try {
            $this->repository->save((new ValidatedProduct())->setName($name)->setPrice($price));
            $this->fail('The invalid product was saved');
        } catch (Error400Exception $ex) {
            $this->assertStringContainsString($message, $ex->getMessage());
        }

        $this->assertSame(0, $this->rowCount());
    }

    public function testAnInvalidChangeIsNotUpdated(): void
    {
        $product = (new ValidatedProduct())->setName('Keyboard')->setPrice(49.9);
        $this->repository->save($product);

        $product->setPrice(-1.0);
        try {
            $this->repository->save($product);
            $this->fail('The invalid change was saved');
        } catch (Error400Exception $ex) {
            $this->assertStringContainsString('is less than minimum 0', $ex->getMessage());
        }

        $this->assertEquals(49.9, $this->repository->get($product->getId())->getPrice());
    }

    public function testTheSchemaIsNamedAfterTheClass(): void
    {
        $this->assertSame('ValidatedProduct', SchemaValidator::schemaName(ValidatedProduct::class));
        $this->assertSame('Product', SchemaValidator::schemaName(Product::class));
    }

    public function testTheSchemaNameOfTheAttributeWins(): void
    {
        $model = new #[OA\Schema(schema: 'Item')] class {
        };

        $this->assertSame('Item', SchemaValidator::schemaName($model::class));
    }
}
