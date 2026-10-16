<?php

namespace Tests\Util;

use ByJG\Gluo\Util\OrderBy;
use ByJG\RestServer\Exception\Error400Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\Product;

class OrderByTest extends TestCase
{
    public static function validProvider(): array
    {
        return [
            'absent' => [null, []],
            'empty' => ['', []],
            'blank' => ['   ', []],
            'one field' => ['name', ['name']],
            'explicit asc' => ['name asc', ['name']],
            'desc' => ['price desc', ['price DESC']],
            'direction is case-insensitive' => ['price DESC', ['price DESC']],
            'several fields keep their order' => ['price desc,name', ['price DESC', 'name']],
            'spaces around terms' => [' price   desc , name ', ['price DESC', 'name']],
            'property name maps to the column' => ['createdAt desc', ['created_at DESC']],
            'column name is accepted too' => ['created_at', ['created_at']],
            'primary key' => ['id desc', ['id DESC']],
        ];
    }

    #[DataProvider('validProvider')]
    public function testValid(?string $orderBy, array $expected): void
    {
        $this->assertSame($expected, OrderBy::fromQueryString($orderBy, Product::class));
    }

    public static function invalidProvider(): array
    {
        return [
            'unknown field' => ['password', "unknown field 'password'"],
            'unknown field after a valid one' => ['name,nope desc', "unknown field 'nope'"],
            'field names are case-sensitive' => ['NAME', "unknown field 'NAME'"],
            'unknown direction' => ['name sideways', "Invalid orderBy: 'name sideways'"],
            'empty term' => ['name,', "Invalid orderBy: ''"],
            'sql injection in the field' => ['name; drop table gluo_product', 'Invalid orderBy'],
            'sql expression' => ['(select 1)', 'Invalid orderBy'],
            'function call' => ['sleep(5)', 'Invalid orderBy'],
            'quoted identifier' => ['`name`', 'Invalid orderBy'],
            'qualified name' => ['gluo_product.name', 'Invalid orderBy'],
            'comment' => ['name -- x', 'Invalid orderBy'],
            'direction followed by more' => ['name desc nulls last', 'Invalid orderBy'],
        ];
    }

    #[DataProvider('invalidProvider')]
    public function testInvalidIsRejectedWith400(string $orderBy, string $message): void
    {
        $this->expectException(Error400Exception::class);
        $this->expectExceptionMessage($message);

        OrderBy::fromQueryString($orderBy, Product::class);
    }
}
