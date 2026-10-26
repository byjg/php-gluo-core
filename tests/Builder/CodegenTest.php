<?php

namespace Tests\Builder;

use ByJG\AnyDataset\Db\DatabaseExecutor;
use ByJG\AnyDataset\Db\Factory;
use ByJG\AnyDataset\Db\Metadata\ColumnMetadata;
use ByJG\AnyDataset\Db\Metadata\ForeignKeyMetadata;
use ByJG\AnyDataset\Db\Metadata\IndexMetadata;
use Tests\Fixture\ExposedScripts;
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
     * A column as the anydataset-db dialect describes it.
     */
    protected function column(
        string $name,
        string $dbType,
        bool $required,
        string $phpType = 'string',
        ?int $length = null,
        bool $primaryKey = false,
        bool $autoIncrement = false,
        bool $generated = false,
        mixed $default = null
    ): ColumnMetadata {
        return new ColumnMetadata($name, $dbType, $required, $default, $phpType, $length, null, $primaryKey, $autoIncrement, $generated);
    }

    /**
     * @param list<ColumnMetadata> $columns
     * @return array<string, ColumnMetadata> keyed by name, as getTableMetadata() returns them
     */
    protected function byName(array $columns): array
    {
        return array_column(array_map(fn ($column) => ['name' => $column->name, 'column' => $column], $columns), 'column', 'name');
    }

    protected function tableDefinition(): array
    {
        return $this->byName([
            $this->column('id', 'int(11)', true, 'integer', primaryKey: true, autoIncrement: true),
            $this->column('name', 'varchar(120)', true, 'string', 120),
            $this->column('price', 'decimal(10,2)', false, 'float', 10),
            $this->column('is_active', 'tinyint(1)', true, 'integer', default: '1'),
            $this->column('quantity', 'bigint(20)', false, 'integer'),
            $this->column('created_at', 'timestamp', false),
            $this->column('updated_at', 'datetime', false),
            $this->column('deleted_at', 'datetime', false),
        ]);
    }

    protected function tableIndexes(): array
    {
        return [
            new IndexMetadata('idx_is_active', ['is_active'], false),
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

    public function testMaxLengthComesFromCharColumns(): void
    {
        $definition = $this->tableDefinition() + $this->byName([
            $this->column('code', 'char(3)', true, 'string', 3),
            $this->column('owner_id', 'binary(16)', true),
            $this->column('notes', 'text', false),
        ]);

        $data = $this->scripts->callBuildCodegenData('product_item', $definition, $this->tableIndexes(), false);
        $byField = array_column($data['fields'], null, 'field');

        $this->assertSame(120, $byField['name']['openapi_max_length']);
        $this->assertSame(3, $byField['code']['openapi_max_length']);
        // Lengths that are not a character limit must not become maxLength.
        $this->assertSame(0, $byField['id']['openapi_max_length']);
        $this->assertSame(0, $byField['price']['openapi_max_length']);
        $this->assertSame(0, $byField['owner_id']['openapi_max_length']);
        $this->assertSame(0, $byField['notes']['openapi_max_length']);
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
        $this->assertSame([
            ['key_name' => 'idx_is_active', 'column_name' => 'is_active', 'non_unique' => 1, 'camelColumnName' => 'isActive'],
        ], $data['indexes']);
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
                'null', 'key', 'default', 'extra', 'parent_table', 'openapi_max_length', 'openapi_min_length',
                'openapi_arguments',
            ], array_keys($field), "Field variable contract changed for column '{$field['field']}'");
        }

        $this->assertFalse($data['activerecord']);
        $this->assertTrue($this->buildData(true)['activerecord']);
    }

    public function testForeignKeyParentTable(): void
    {
        // A table with two FK columns: an int FK and a binary(16) UUID FK.
        $tableDefinition = $this->byName([
            $this->column('id', 'int(11)', true, 'integer', primaryKey: true, autoIncrement: true),
            $this->column('category_id', 'int(11)', true, 'integer'),
            $this->column('owner_id', 'binary(16)', true),
            $this->column('name', 'varchar(120)', true, 'string', 120),
        ]);
        $foreignKeys = [
            new ForeignKeyMetadata(['category_id'], 'category', ['id']),
            new ForeignKeyMetadata(['owner_id'], 'users', ['id']),
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

    public function testUuidKeyTableUsesTableUuidPKAttribute(): void
    {
        $definition = $this->byName([
            $this->column('id', 'binary(16)', true, primaryKey: true),
            $this->column('name', 'varchar(120)', true, 'string', 120),
        ]);

        $data = $this->scripts->callBuildCodegenData('product_item', $definition, [], false);
        $code = $this->scripts->callRenderCodegenTemplate('model.php', $data);

        $this->assertValidPhp($code);
        $this->assertSame('no', $data['autoIncrement']);
        $this->assertStringContainsString('use ByJG\MicroOrm\Attributes\TableUuidPKAttribute;', $code);
        $this->assertStringContainsString('#[TableUuidPKAttribute("product_item", validator: SchemaValidator::class)]', $code);
        // The UUID is seeded by the attribute, so the key is generated: readOnly
        $this->assertStringContainsString('#[OA\Property(type: "string", format: "string", readOnly: true)]', $code);
        $this->assertStringContainsString('#[FieldUuidAttribute(primaryKey: true, fieldName: "id")]', $code);
        $this->assertStringNotContainsString('MySql', $code);
    }

    public function testGeneratedColumnIsNotWritten(): void
    {
        $definition = $this->tableDefinition() + $this->byName([
            $this->column('total', 'int', false, 'integer', generated: true),
        ]);

        $data = $this->scripts->callBuildCodegenData('product_item', $definition, [], false);
        $byField = array_column($data['fields'], null, 'field');
        $this->assertSame('VIRTUAL GENERATED', $byField['total']['extra']);
        $this->assertSame('auto_increment', $byField['id']['extra']);

        $model = $this->scripts->callRenderCodegenTemplate('model.php', $data);
        $this->assertStringContainsString('#[FieldAttribute(fieldName: "total", syncWithDb: false)]', $model);
        $controller = $this->scripts->callRenderCodegenTemplate('controller.php', $data);
        $this->assertStringNotContainsString('property: "total"', $controller);
    }

    /**
     * The codegen reads the table through the anydataset-db dialect, so it is not tied to MySQL.
     */
    public function testReadsTheTableFromSqlite(): void
    {
        $dbFile = $this->workdir . '/codegen.db';
        $executor = DatabaseExecutor::using(Factory::getDbInstance('sqlite://' . $dbFile));
        $executor->execute('CREATE TABLE category (id INTEGER PRIMARY KEY, name VARCHAR(50) NOT NULL)');
        $executor->execute(
            'CREATE TABLE product_item (
                id INTEGER PRIMARY KEY,
                category_id INTEGER NOT NULL REFERENCES category (id),
                name VARCHAR(120) NOT NULL,
                price NUMERIC(10,2),
                name_length INTEGER GENERATED ALWAYS AS (length(name)) VIRTUAL,
                created_at DATETIME
            )'
        );
        $executor->execute('CREATE INDEX ix_product_item_name ON product_item (name)');

        try {
            $data = $this->scripts->callBuildCodegenDataFromDatabase($executor, 'product_item', false);
        } finally {
            unlink($dbFile);
        }

        $byField = array_column($data['fields'], null, 'field');
        $this->assertSame(['id', 'category_id', 'name', 'price', 'name_length', 'created_at'], array_keys($byField));
        $this->assertSame(['id'], $data['primaryKeys']);
        $this->assertSame('yes', $data['autoIncrement']);
        $this->assertSame('category', $byField['category_id']['parent_table']);
        $this->assertSame(120, $byField['name']['openapi_max_length']);
        $this->assertSame(['float', 'number'], [$byField['price']['php_type'], $byField['price']['openapi_type']]);
        $this->assertSame('date-time', $byField['created_at']['openapi_format']);
        $this->assertSame(['categoryId', 'name'], $data['nonNullableFields']);
        $this->assertSame('name', $data['indexes'][0]['column_name']);

        $model = $this->scripts->callRenderCodegenTemplate('model.php', $data);
        $this->assertValidPhp($model);
        $this->assertStringContainsString('#[FieldAttribute(primaryKey: true, fieldName: "id")]', $model);
        $this->assertStringContainsString('#[FieldAttribute(fieldName: "category_id", parentTable: "category")]', $model);
        $this->assertStringContainsString('#[FieldAttribute(fieldName: "name_length", syncWithDb: false)]', $model);

        $repository = $this->scripts->callRenderCodegenTemplate('repository.php', $data);
        $this->assertValidPhp($repository);
        $this->assertStringContainsString('public function getByName($name)', $repository);
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

    public function testMinLengthOnlyForRequiredTextWithoutDefault(): void
    {
        $definition = $this->tableDefinition() + $this->byName([
            $this->column('code', 'char(3)', true, 'string', 3),
            $this->column('description', 'text', true),
            $this->column('nickname', 'varchar(30)', false, 'string', 30),
            $this->column('status', 'varchar(10)', true, 'string', 10, default: 'new'),
            $this->column('slug', 'varchar(60)', true, 'string', 60, generated: true),
            $this->column('owner_id', 'binary(16)', true),
            $this->column('sku', 'varchar(20)', true, 'string', 20, primaryKey: true),
        ]);

        $data = $this->scripts->callBuildCodegenData('product_item', $definition, $this->tableIndexes(), false);
        $byField = array_column($data['fields'], 'openapi_min_length', 'field');

        // NOT NULL text the client must send: an empty string is refused like a missing value.
        $this->assertSame(1, $byField['name']);
        $this->assertSame(1, $byField['code']);
        $this->assertSame(1, $byField['description']);
        // Nullable, defaulted, generated, key and non-text columns are left alone.
        $this->assertSame(0, $byField['nickname']);
        $this->assertSame(0, $byField['status']);
        $this->assertSame(0, $byField['slug']);
        $this->assertSame(0, $byField['owner_id']);
        $this->assertSame(0, $byField['sku']);
        $this->assertSame(0, $byField['is_active']);
        $this->assertSame(0, $byField['price']);
    }

    public function testPropertyArgumentsFollowTheAttributeOrder(): void
    {
        // Named arguments in the order OA\Property declares them (type, format, readOnly,
        // nullable, maxLength, minLength), so IDE inspections do not flag the generated code.
        $definition = $this->tableDefinition() + $this->byName([
            $this->column('nickname', 'varchar(30)', false, 'string', 30),
        ]);
        $data = $this->scripts->callBuildCodegenData('product_item', $definition, $this->tableIndexes(), false);

        $model = $this->scripts->callRenderCodegenTemplate('model.php', $data);
        $this->assertStringContainsString('#[OA\Property(type: "string", format: "string", nullable: true, maxLength: 30)]', $model);
        $this->assertStringContainsString('#[OA\Property(type: "string", format: "string", maxLength: 120, minLength: 1)]', $model);

        $rest = $this->scripts->callRenderCodegenTemplate('controller.php', $data);
        $this->assertStringContainsString('new OA\Property(property: "nickname", type: "string", format: "string", nullable: true, maxLength: 30)', $rest);
    }

    public function testRenderModelTemplate(): void
    {
        $code = $this->scripts->callRenderCodegenTemplate('model.php', $this->buildData());

        $this->assertValidPhp($code);
        $this->assertStringContainsString('namespace App\Model;', $code);
        $this->assertStringContainsString('class ProductItem', $code);
        // Validated against its own OpenAPI schema on every save
        $this->assertStringContainsString('#[TableAttribute("product_item", validator: SchemaValidator::class)]', $code);
        $this->assertStringContainsString('use ByJG\Gluo\Util\SchemaValidator;', $code);
        // The auto-increment key is generated: readOnly
        $this->assertStringContainsString('#[OA\Property(type: "integer", format: "int32", readOnly: true)]', $code);
        $this->assertStringContainsString('use ByJG\Gluo\Trait\OaCreatedAt;', $code);
        $this->assertStringContainsString('use ByJG\Gluo\Trait\OaUpdatedAt;', $code);
        $this->assertStringContainsString('use ByJG\Gluo\Trait\OaDeletedAt;', $code);
        $this->assertStringContainsString('#[OA\Property(type: "string", format: "string", maxLength: 120, minLength: 1)]', $code);
        $this->assertStringContainsString('#[OA\Property(type: "number", format: "double", nullable: true)]', $code);
        $this->assertSame(1, substr_count($code, 'maxLength'));
        $this->assertSame(1, substr_count($code, 'minLength'));
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
        $this->assertStringContainsString('new OA\Property(property: "name", type: "string", format: "string", maxLength: 120, minLength: 1)', $code);

        // PUT takes the key from the path, and the same body as POST: the writable fields only
        $this->assertStringContainsString('path: "/product/item/{id}",' . "\n" . '        security', $code);
        $this->assertSame(2, substr_count($code, 'new OA\Property(property: "name",'));
        // The key appears once: in the POST response, never in a request body
        $this->assertSame(1, substr_count($code, 'property: "id"'));
        $this->assertStringNotContainsString('property: "createdAt"', $code);
        $this->assertStringContainsString("\$this->productItemService->update(\$request->attribute('id'), ValidateRequest::getPayload());", $code);

        // The list endpoint only documents the query parameters it applies.
        $this->assertStringContainsString("OrderBy::fromQueryString(\$request->queryString('orderBy'), ProductItem::class)", $code);
        $this->assertStringContainsString('use App\Model\ProductItem;', $code);
        $this->assertStringContainsString('use ByJG\Gluo\Util\OrderBy;', $code);
        $this->assertStringContainsString('name: "orderBy"', $code);
        $this->assertStringNotContainsString('name: "filter"', $code);
    }

    public function testRenderTestTemplate(): void
    {
        $code = $this->scripts->callRenderCodegenTemplate('test.php', $this->buildData());

        $this->assertValidPhp($code);
        $this->assertStringContainsString('use ByJG\Gluo\Util\FakeApiRequester;', $code);
        $this->assertStringContainsString('class ProductItemTest', $code);
        // The invalid bodies reach the server, on POST and on PUT: the key in the body, the
        // missing required fields, and one case per required text column.
        $this->assertStringContainsString('public function testInvalidPayloadIsRejectedByTheServer()', $code);
        $this->assertStringContainsString("'POST with the key in the body' => ['POST', \$collection, \$sample + ['id' => 1]],", $code);
        $this->assertStringContainsString("'PUT with the key in the body' => ['PUT', \$item, \$sample + ['id' => 1]],", $code);
        $this->assertStringContainsString("'POST without the required fields' => ['POST', \$collection, (object)[]],", $code);
        $this->assertStringContainsString("'PUT without the required fields' => ['PUT', \$item, (object)[]],", $code);
        $this->assertStringContainsString("'POST with an empty name' => ['POST', \$collection, array_merge(\$sample, ['name' => ''])],", $code);
        $this->assertStringContainsString("'PUT with an empty name' => ['PUT', \$item, array_merge(\$sample, ['name' => ''])],", $code);
        // PUT sends the key in the path, an integer for an int(11) key
        $this->assertStringContainsString('->withPath("/product/item/1")', $code);
        $this->assertStringNotContainsString("->withMethod('PUT')\n            ->withPath(\"/product/item\")", $code);
        $this->assertStringContainsString('$request->send();', $code);
    }

    public function testRenderActiveRecordTemplates(): void
    {
        $data = $this->buildData(true);

        $model = $this->scripts->callRenderCodegenTemplate('model.php', $data);
        $this->assertValidPhp($model);
        // Gluo's ActiveRecord: MicroOrm's, plus getOrFail()
        $this->assertStringContainsString('use ByJG\Gluo\Trait\ActiveRecord;', $model);

        $rest = $this->scripts->callRenderCodegenTemplate('controlleractiverecord.php', $data);
        $this->assertValidPhp($rest);
        $this->assertStringContainsString('use ByJG\Gluo\Attribute\RequireAuthenticated;', $rest);
        $this->assertStringContainsString('class ProductItemController', $rest);
        $this->assertStringContainsString('new OA\Property(property: "name", type: "string", format: "string", maxLength: 120, minLength: 1)', $rest);
        $this->assertSame(2, substr_count($rest, 'new OA\Property(property: "name",'));
        // The lookup answers 404 itself: no null check in the controller
        $this->assertStringContainsString("\$response->write(ProductItem::getOrFail(\$request->attribute('id')));", $rest);
        $this->assertStringContainsString("\$model = ProductItem::getOrFail(\$request->attribute('id'));", $rest);
        $this->assertStringNotContainsString('is_null($model)', $rest);

        $this->assertStringContainsString("->orderBy(OrderBy::fromQueryString(\$request->queryString('orderBy'), ProductItem::class))", $rest);
        $this->assertStringContainsString('use ByJG\Gluo\Util\OrderBy;', $rest);
        $this->assertStringContainsString('use ByJG\MicroOrm\Query;', $rest);
        $this->assertStringContainsString('name: "orderBy"', $rest);
        $this->assertStringNotContainsString('name: "filter"', $rest);
    }
}
