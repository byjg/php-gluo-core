<?php

namespace ByJGTest\Gluo\Builder;

use ByJGTest\Gluo\Fixture\ExposedScripts;
use ParseError;
use PHPUnit\Framework\TestCase;

class CodegenTest extends TestCase
{
    protected ExposedScripts $scripts;

    protected function setUp(): void
    {
        $this->scripts = new ExposedScripts();
        // Point workdir to an empty dir so template lookup falls back to the package templates
        $workdir = sys_get_temp_dir() . '/gluo-codegen-' . uniqid();
        mkdir($workdir, 0755, true);
        $this->scripts->setWorkdir($workdir);
    }

    /**
     * EXPLAIN-style rows as the anydataset iterator returns them (lowercase keys).
     */
    protected function tableDefinition(): array
    {
        return [
            ['field' => 'id', 'type' => 'int(11)', 'null' => 'NO', 'key' => 'PRI', 'default' => null, 'extra' => 'auto_increment'],
            ['field' => 'name', 'type' => 'varchar(120)', 'null' => 'NO', 'key' => '', 'default' => null, 'extra' => ''],
            ['field' => 'price', 'type' => 'decimal(10,2)', 'null' => 'YES', 'key' => '', 'default' => null, 'extra' => ''],
            ['field' => 'is_active', 'type' => 'tinyint(1)', 'null' => 'NO', 'key' => '', 'default' => '1', 'extra' => ''],
            ['field' => 'quantity', 'type' => 'bigint(20)', 'null' => 'YES', 'key' => '', 'default' => null, 'extra' => ''],
            ['field' => 'created_at', 'type' => 'timestamp', 'null' => 'YES', 'key' => '', 'default' => null, 'extra' => ''],
            ['field' => 'updated_at', 'type' => 'datetime', 'null' => 'YES', 'key' => '', 'default' => null, 'extra' => ''],
            ['field' => 'deleted_at', 'type' => 'datetime', 'null' => 'YES', 'key' => '', 'default' => null, 'extra' => ''],
        ];
    }

    protected function tableIndexes(): array
    {
        return [
            ['key_name' => 'PRIMARY', 'column_name' => 'id', 'non_unique' => 0],
            ['key_name' => 'idx_is_active', 'column_name' => 'is_active', 'non_unique' => 1],
        ];
    }

    protected function buildData(bool $isActiveRecord = false): array
    {
        return $this->scripts->callBuildCodegenData('product_item', $this->tableDefinition(), $this->tableIndexes(), $isActiveRecord);
    }

    public function testNamingDerivation(): void
    {
        $data = $this->buildData();

        $this->assertSame('ProductItem', $data['className']);
        $this->assertSame('productItem', $data['varTableName']);
        $this->assertSame('product_item', $data['tableName']);
        $this->assertSame('product/item', $data['restPath']);
        $this->assertSame('Product', $data['restTag']);
        $this->assertSame('App', $data['namespace']);
        $this->assertSame('yes', $data['autoIncrement']);
    }

    public function testTypeMapping(): void
    {
        $data = $this->buildData();
        $byField = array_column($data['fields'], null, 'field');

        $this->assertSame(['int', 'integer', 'int32'], [$byField['id']['php_type'], $byField['id']['openapi_type'], $byField['id']['openapi_format']]);
        $this->assertSame(['string', 'string', 'string'], [$byField['name']['php_type'], $byField['name']['openapi_type'], $byField['name']['openapi_format']]);
        $this->assertSame(['float', 'number', 'double'], [$byField['price']['php_type'], $byField['price']['openapi_type'], $byField['price']['openapi_format']]);
        $this->assertSame(['int', 'integer', 'int32'], [$byField['is_active']['php_type'], $byField['is_active']['openapi_type'], $byField['is_active']['openapi_format']]);
        $this->assertSame(['int', 'integer', 'int64'], [$byField['quantity']['php_type'], $byField['quantity']['openapi_type'], $byField['quantity']['openapi_format']]);
        $this->assertSame(['string', 'string', 'date-time'], [$byField['created_at']['php_type'], $byField['created_at']['openapi_type'], $byField['created_at']['openapi_format']]);
    }

    public function testFieldClassification(): void
    {
        $data = $this->buildData();

        $this->assertSame(['id'], $data['primaryKeys']);
        $this->assertSame(['price', 'quantity', 'createdAt', 'updatedAt', 'deletedAt'], $data['nullableFields']);
        $this->assertSame(['name', 'isActive'], $data['nonNullableFields']);
        $this->assertTrue($data['hasCreatedAt']);
        $this->assertTrue($data['hasUpdatedAt']);
        $this->assertTrue($data['hasDeletedAt']);
        $this->assertSame('isActive', $data['indexes'][1]['camelColumnName']);
    }

    protected function assertValidPhp(string $code): void
    {
        try {
            token_get_all($code, TOKEN_PARSE);
            $this->addToAssertionCount(1);
        } catch (ParseError $e) {
            $this->fail("Generated code is not valid PHP: {$e->getMessage()}\n{$code}");
        }
    }

    public function testRenderModelTemplate(): void
    {
        $code = $this->scripts->callRenderCodegenTemplate('model.php', $this->buildData());

        $this->assertValidPhp($code);
        $this->assertStringContainsString('namespace App\Model;', $code);
        $this->assertStringContainsString('class ProductItem', $code);
        $this->assertStringContainsString('#[TableAttribute("product_item")]', $code);
        $this->assertStringContainsString('use ByJG\Gluo\Trait\OaCreatedAt;', $code);
        $this->assertStringContainsString('use ByJG\Gluo\Trait\OaUpdatedAt;', $code);
        $this->assertStringContainsString('use ByJG\Gluo\Trait\OaDeletedAt;', $code);
    }

    public function testRenderRepositoryTemplate(): void
    {
        $code = $this->scripts->callRenderCodegenTemplate('repository.php', $this->buildData());

        $this->assertValidPhp($code);
        $this->assertStringContainsString('namespace App\Repository;', $code);
        $this->assertStringContainsString('use ByJG\Gluo\Repository\BaseRepository;', $code);
        $this->assertStringContainsString('class ProductItemRepository extends BaseRepository', $code);
    }

    public function testRenderServiceTemplate(): void
    {
        $code = $this->scripts->callRenderCodegenTemplate('service.php', $this->buildData());

        $this->assertValidPhp($code);
        $this->assertStringContainsString('use ByJG\Gluo\Service\BaseService;', $code);
        $this->assertStringContainsString('class ProductItemService extends BaseService', $code);
    }

    public function testRenderRestTemplate(): void
    {
        $code = $this->scripts->callRenderCodegenTemplate('rest.php', $this->buildData());

        $this->assertValidPhp($code);
        $this->assertStringContainsString('use ByJG\Gluo\Attribute\RequireAuthenticated;', $code);
        $this->assertStringContainsString('use ByJG\Gluo\Attribute\RequireRole;', $code);
        $this->assertStringContainsString('use ByJG\Gluo\Attribute\ValidateRequest;', $code);
        $this->assertStringContainsString('class ProductItemController', $code);
    }

    public function testRenderTestTemplate(): void
    {
        $code = $this->scripts->callRenderCodegenTemplate('test.php', $this->buildData());

        $this->assertValidPhp($code);
        $this->assertStringContainsString('use ByJG\Gluo\Util\FakeApiRequester;', $code);
        $this->assertStringContainsString('class ProductItemTest', $code);
    }

    public function testRenderActiveRecordTemplates(): void
    {
        $data = $this->buildData(true);

        $model = $this->scripts->callRenderCodegenTemplate('model.php', $data);
        $this->assertValidPhp($model);
        $this->assertStringContainsString('use ByJG\MicroOrm\Trait\ActiveRecord;', $model);

        $rest = $this->scripts->callRenderCodegenTemplate('restactiverecord.php', $data);
        $this->assertValidPhp($rest);
        $this->assertStringContainsString('use ByJG\Gluo\Attribute\RequireAuthenticated;', $rest);
        $this->assertStringContainsString('class ProductItemController', $rest);
    }
}
