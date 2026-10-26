<?php

namespace Tests\Service;

use ByJG\AnyDataset\Db\DatabaseExecutor;
use ByJG\AnyDataset\Db\Factory;
use ByJG\RestServer\Exception\Error404Exception;
use ByJG\RestServer\Exception\Error422Exception;
use Tests\Fixture\Product;
use Tests\Fixture\ProductRepository;
use Tests\Fixture\ProductService;
use PHPUnit\Framework\TestCase;

class BaseServiceTest extends TestCase
{
    protected string $dbFile;
    protected ProductService $service;

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
        $this->service = new ProductService(new ProductRepository($executor));
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    public function testCreatePersistsAndReturnsModel(): void
    {
        $model = $this->service->create(['name' => 'Widget', 'price' => 10.5]);

        $this->assertInstanceOf(Product::class, $model);
        $this->assertNotEmpty($model->getId());

        $found = $this->service->get($model->getId());
        $this->assertSame('Widget', $found->getName());
    }

    public function testCreateRejectsPayloadWithPrimaryKey(): void
    {
        $this->expectException(Error422Exception::class);
        $this->expectExceptionMessage('Create should not include primary key field: id');

        $this->service->create(['id' => 1, 'name' => 'Widget']);
    }

    public function testUpdateFailsForMissingId(): void
    {
        $this->expectException(Error404Exception::class);

        $this->service->update(999, ['name' => 'Ghost']);
    }

    public function testUpdateChangesOnlyProvidedFields(): void
    {
        $model = $this->service->create(['name' => 'Widget', 'price' => 10.5]);

        $updated = $this->service->update($model->getId(), ['price' => 20.0]);

        $this->assertEqualsWithDelta(20.0, $updated->getPrice(), 0.001);
        $this->assertSame('Widget', $updated->getName());

        $found = $this->service->getOrFail($model->getId());
        $this->assertEqualsWithDelta(20.0, $found->getPrice(), 0.001);
    }

    public function testAKeyInThePayloadDoesNotRedirectTheUpdate(): void
    {
        $widget = $this->service->create(['name' => 'Widget', 'price' => 10.5]);
        $gadget = $this->service->create(['name' => 'Gadget', 'price' => 5.0]);

        $this->service->update($widget->getId(), ['id' => $gadget->getId(), 'price' => 20.0]);

        $this->assertEqualsWithDelta(20.0, $this->service->getOrFail($widget->getId())->getPrice(), 0.001);
        $this->assertEqualsWithDelta(5.0, $this->service->getOrFail($gadget->getId())->getPrice(), 0.001);
    }

    public function testGetOrFailThrows404(): void
    {
        $this->expectException(Error404Exception::class);
        $this->expectExceptionMessage('Id not found');

        $this->service->getOrFail(999);
    }

    public function testListUsesDefaultsForNullArguments(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->service->create(['name' => "Product $i", 'price' => (float)$i]);
        }

        $this->assertCount(20, $this->service->list(null, null));
        $this->assertCount(5, $this->service->list(1, 20));
    }

    public function testListPassesOrderByToTheRepository(): void
    {
        foreach (['Banana' => 2.0, 'Apple' => 1.0, 'Cherry' => 3.0] as $name => $price) {
            $this->service->create(['name' => $name, 'price' => $price]);
        }

        $names = fn (array $models) => array_map(fn ($model) => $model->getName(), $models);

        $this->assertSame(['Apple', 'Banana', 'Cherry'], $names($this->service->list(0, 10, ['name'])));
        $this->assertSame(['Cherry', 'Banana', 'Apple'], $names($this->service->list(0, 10, ['price DESC'])));
        // Without it the rows come back in insertion order, as before.
        $this->assertSame(['Banana', 'Apple', 'Cherry'], $names($this->service->list(0, 10)));
    }

    public function testDeleteRemovesRow(): void
    {
        $model = $this->service->create(['name' => 'Widget', 'price' => 10.5]);

        $this->service->delete($model->getId());

        $this->assertEmpty($this->service->get($model->getId()));
    }
}
