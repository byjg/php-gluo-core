<?php

namespace ByJGTest\Gluo\Repository;

use ByJG\AnyDataset\Db\DatabaseExecutor;
use ByJG\AnyDataset\Db\Factory;
use ByJG\MicroOrm\Mapper;
use ByJG\MicroOrm\Query;
use ByJG\MicroOrm\Repository;
use ByJGTest\Gluo\Fixture\Product;
use ByJGTest\Gluo\Fixture\ProductRepository;
use PHPUnit\Framework\TestCase;

class BaseRepositoryTest extends TestCase
{
    protected string $dbFile;
    protected ProductRepository $repository;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/gluo-test-' . uniqid() . '.db';
        $driver = Factory::getDbInstance('sqlite://' . $this->dbFile);
        $executor = DatabaseExecutor::using($driver);
        $executor->execute(
            'CREATE TABLE gluo_product (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(120) NOT NULL,
                price FLOAT NULL,
                created_at DATETIME NULL
            )'
        );
        $this->repository = new ProductRepository($executor);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    protected function insertSample(string $name, float $price): Product
    {
        $product = (new Product())->setName($name)->setPrice($price);
        $this->repository->save($product);
        return $product;
    }

    public function testSaveAssignsPrimaryKeyOnInsert(): void
    {
        $product = $this->insertSample('Widget', 10.5);
        $this->assertNotEmpty($product->getId());
    }

    public function testGetReturnsSavedModel(): void
    {
        $saved = $this->insertSample('Widget', 10.5);

        $found = $this->repository->get($saved->getId());

        $this->assertInstanceOf(Product::class, $found);
        $this->assertSame('Widget', $found->getName());
        $this->assertEqualsWithDelta(10.5, $found->getPrice(), 0.001);
    }

    public function testGetReturnsEmptyForMissingId(): void
    {
        $this->assertEmpty($this->repository->get(999));
    }

    public function testSaveUpdatesExistingRow(): void
    {
        $saved = $this->insertSample('Widget', 10.5);

        $saved->setPrice(99.9);
        $this->repository->save($saved);

        $found = $this->repository->get($saved->getId());
        $this->assertEqualsWithDelta(99.9, $found->getPrice(), 0.001);
    }

    public function testListPaginates(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->insertSample("Product $i", (float)$i);
        }

        $this->assertCount(20, $this->repository->list());
        $this->assertCount(10, $this->repository->list(0, 10));
        $this->assertCount(5, $this->repository->list(2, 10));
        $this->assertCount(0, $this->repository->list(3, 10));
    }

    public function testListOrderBy(): void
    {
        $this->insertSample('Banana', 2.0);
        $this->insertSample('Apple', 1.0);
        $this->insertSample('Cherry', 3.0);

        $result = $this->repository->list(0, 10, 'name');

        $this->assertSame('Apple', $result[0]->getName());
        $this->assertSame('Banana', $result[1]->getName());
        $this->assertSame('Cherry', $result[2]->getName());
    }

    public function testListFilter(): void
    {
        $this->insertSample('Cheap', 5.0);
        $this->insertSample('Expensive', 100.0);

        $result = $this->repository->list(filter: [['price > :price', ['price' => 50]]]);

        $this->assertCount(1, $result);
        $this->assertSame('Expensive', $result[0]->getName());
    }

    public function testGetByQueryAppliesModelTable(): void
    {
        $this->insertSample('Widget', 10.5);
        $this->insertSample('Gadget', 20.0);

        $query = Query::getInstance()->where('name = :name', ['name' => 'Gadget']);
        $result = $this->repository->getByQuery($query);

        $this->assertCount(1, $result);
        $this->assertSame('Gadget', $result[0]->getName());
    }

    public function testDeleteRemovesRow(): void
    {
        $saved = $this->insertSample('Widget', 10.5);

        $this->assertTrue($this->repository->delete($saved->getId()));
        $this->assertEmpty($this->repository->get($saved->getId()));
    }

    public function testModelReturnsNewEntityInstance(): void
    {
        $model = $this->repository->model();

        $this->assertInstanceOf(Product::class, $model);
        $this->assertNull($model->getId());
    }

    public function testAccessors(): void
    {
        $this->assertInstanceOf(Repository::class, $this->repository->getRepository());
        $this->assertInstanceOf(Mapper::class, $this->repository->getMapper());
        $this->assertInstanceOf(DatabaseExecutor::class, $this->repository->getExecutor());
        $this->assertSame('gluo_product', $this->repository->getMapper()->getTable());
        $this->assertSame(['id'], $this->repository->getMapper()->getPrimaryKey());
    }
}
