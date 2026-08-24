<?php

namespace ByJGTest\Gluo\Builder;

use ByJGTest\Gluo\Fixture\ExposedScripts;
use ParseError;
use PHPUnit\Framework\TestCase;

class CodegenTest extends TestCase
{
    protected ExposedScripts $scripts;
    protected string $workdir;

    protected function setUp(): void
    {
        $this->scripts = new ExposedScripts();
        // Point workdir to an empty dir so template lookup falls back to the package templates
        $this->workdir = sys_get_temp_dir() . '/gluo-codegen-' . uniqid();
        mkdir($this->workdir, 0755, true);
        $this->scripts->setWorkdir($this->workdir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->workdir)) {
            rmdir($this->workdir);
        }
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

    /**
     * Pins the template-variable contract documented in the byjg/gluo starter
     * (docs/guides/templates.md). Renaming, adding or removing a key must fail
     * here so the docs and custom user templates are updated together.
     */
    public function testCodegenDataMatchesDocumentedContract(): void
    {
        $data = $this->buildData();

        $this->assertEqualsCanonicalizing([
            'namespace', 'className', 'tableName', 'varTableName', 'restPath', 'restTag',
            'fields', 'primaryKeys', 'nullableFields', 'nonNullableFields', 'indexes',
            'autoIncrement', 'activerecord', 'hasCreatedAt', 'hasUpdatedAt', 'hasDeletedAt',
        ], array_keys($data));

        foreach ($data['fields'] as $field) {
            $this->assertEqualsCanonicalizing([
                'field', 'property', 'type', 'php_type', 'openapi_type', 'openapi_format',
                'null', 'key', 'default', 'extra', 'parent_table',
            ], array_keys($field), "Field variable contract changed for column '{$field['field']}'");
        }

        $this->assertFalse($data['activerecord']);
        $this->assertTrue($this->buildData(true)['activerecord']);
    }

    public function testForeignKeyParentTable(): void
    {
        // A table with two FK columns: an int FK and a binary(16) UUID FK.
        $tableDefinition = [
            ['field' => 'id', 'type' => 'int(11)', 'null' => 'NO', 'key' => 'PRI', 'default' => null, 'extra' => 'auto_increment'],
            ['field' => 'category_id', 'type' => 'int(11)', 'null' => 'NO', 'key' => 'MUL', 'default' => null, 'extra' => ''],
            ['field' => 'owner_id', 'type' => 'binary(16)', 'null' => 'NO', 'key' => 'MUL', 'default' => null, 'extra' => ''],
            ['field' => 'name', 'type' => 'varchar(120)', 'null' => 'NO', 'key' => '', 'default' => null, 'extra' => ''],
        ];
        $foreignKeys = [
            ['column_name' => 'category_id', 'referenced_table_name' => 'category'],
            ['column_name' => 'owner_id', 'referenced_table_name' => 'users'],
        ];

        $data = $this->scripts->callBuildCodegenData('product', $tableDefinition, [], false, $foreignKeys);
        $byField = array_column($data['fields'], null, 'field');

        // FK columns carry the referenced table; everything else is empty.
        $this->assertSame('category', $byField['category_id']['parent_table']);
        $this->assertSame('users', $byField['owner_id']['parent_table']);
        $this->assertSame('', $byField['name']['parent_table']);
        $this->assertSame('', $byField['id']['parent_table']);

        // The model template emits parentTable for FK columns (Uuid variant for the binary FK).
        $code = $this->scripts->callRenderCodegenTemplate('model.php', $data);
        $this->assertValidPhp($code);
        $this->assertStringContainsString('#[FieldAttribute(fieldName: "category_id", parentTable: "category")]', $code);
        $this->assertStringContainsString('#[FieldUuidAttribute(fieldName: "owner_id", parentTable: "users")]', $code);
        // A non-FK column stays plain.
        $this->assertStringContainsString('#[FieldAttribute(fieldName: "name")]', $code);
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
        $code = $this->scripts->callRenderCodegenTemplate('controller.php', $this->buildData());

        $this->assertValidPhp($code);
        $this->assertStringContainsString('use ByJG\Gluo\Attribute\RequireAuthenticated;', $code);
        $this->assertStringContainsString('use ByJG\Gluo\Attribute\RequireRole;', $code);
        $this->assertStringContainsString('use ByJG\Gluo\Attribute\ValidateRequest;', $code);
        $this->assertStringContainsString('class ProductItemController', $code);

        // The Server resolves controllers from the container, so a generated controller
        // takes its service through the constructor instead of pulling it per method.
        $this->assertStringContainsString(
            'public function __construct(protected ProductItemService $productItemService)',
            $code
        );
        $this->assertStringContainsString('$this->productItemService->getOrFail(', $code);
        $this->assertStringNotContainsString('Config::get(', $code);
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

        $rest = $this->scripts->callRenderCodegenTemplate('controlleractiverecord.php', $data);
        $this->assertValidPhp($rest);
        $this->assertStringContainsString('use ByJG\Gluo\Attribute\RequireAuthenticated;', $rest);
        $this->assertStringContainsString('class ProductItemController', $rest);
    }
}
