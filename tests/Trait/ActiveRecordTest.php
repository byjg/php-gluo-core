<?php

namespace Tests\Trait;

use ByJG\AnyDataset\Db\DatabaseExecutor;
use ByJG\AnyDataset\Db\Factory;
use ByJG\MicroOrm\ORM;
use ByJG\RestServer\Exception\Error404Exception;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\ActiveProduct;

class ActiveRecordTest extends TestCase
{
    protected string $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/gluo-activerecord-' . uniqid() . '.db';
        $executor = DatabaseExecutor::using(Factory::getDbInstance('sqlite://' . $this->dbFile));
        $executor->execute('CREATE TABLE gluo_product (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(120) NULL)');

        ORM::defaultDbDriver($executor);
        ActiveProduct::reset();
    }

    protected function tearDown(): void
    {
        ORM::resetMemory();
        if (file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    public function testGetOrFailReturnsTheRecord(): void
    {
        $product = ActiveProduct::new(['name' => 'Keyboard']);
        $product->save();

        $this->assertSame('Keyboard', ActiveProduct::getOrFail($product->getId())->getName());
    }

    public function testGetOrFailAnswers404NamingTheModel(): void
    {
        $this->expectException(Error404Exception::class);
        $this->expectExceptionMessage('ActiveProduct not found');

        ActiveProduct::getOrFail(999);
    }

    public function testGetOrFailTakesTheMessageOfThe404(): void
    {
        $this->expectException(Error404Exception::class);
        $this->expectExceptionMessage('There is no product 999');

        ActiveProduct::getOrFail(999, 'There is no product 999');
    }
}
