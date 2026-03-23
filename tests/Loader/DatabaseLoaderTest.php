<?php

namespace Kura\Tests\Loader;

use Illuminate\Database\Schema\Blueprint;
use Kura\KuraServiceProvider;
use Kura\Loader\EloquentLoader;
use Kura\Loader\QueryBuilderLoader;
use Kura\Loader\StaticVersionResolver;
use Kura\Store\ArrayStore;
use Kura\Store\StoreInterface;
use Kura\Tests\Support\ProductModel;
use Orchestra\Testbench\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Feature: EloquentLoader and QueryBuilderLoader load records from DB
 *
 * Given a products table with test records in SQLite,
 * When loading via EloquentLoader or QueryBuilderLoader,
 * Then records should be yielded as associative arrays via generator.
 */
class DatabaseLoaderTest extends TestCase
{
    private string $tmpDir;

    protected function getPackageProviders($app): array
    {
        return [KuraServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app->singleton(StoreInterface::class, fn () => new ArrayStore);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/kura_dbloader_test_'.uniqid();
        mkdir($this->tmpDir.'/products', recursive: true);

        $this->writeYaml($this->tmpDir.'/products/table.yaml', [
            'columns' => [
                'id' => 'int',
                'name' => 'string',
                'country' => 'string',
                'price' => 'int',
            ],
        ]);

        assert($this->app !== null);
        $schema = $this->app['db']->connection()->getSchemaBuilder();
        $schema->create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('country');
            $table->integer('price');
        });

        $this->app['db']->table('products')->insert([
            ['name' => 'Widget A', 'country' => 'JP', 'price' => 500],
            ['name' => 'Widget B', 'country' => 'US', 'price' => 200],
            ['name' => 'Widget C', 'country' => 'JP', 'price' => 100],
        ]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDirectory($this->tmpDir);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeYaml(string $path, array $data): void
    {
        file_put_contents($path, Yaml::dump($data, 4));
    }

    private function removeDirectory(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $item) {
            is_dir($item) ? $this->removeDirectory($item) : unlink($item);
        }
        rmdir($dir);
    }

    // =========================================================================
    // EloquentLoader
    // =========================================================================

    public function test_eloquent_loader_yields_all_records(): void
    {
        // Given: a products table with 3 records
        $loader = new EloquentLoader(
            query: ProductModel::query(),
            tableDirectory: $this->tmpDir.'/products',
            resolver: new StaticVersionResolver('v1.0.0'),
        );

        // When: loading records
        $records = iterator_to_array($loader->load(), preserve_keys: false);

        // Then: all 3 records should be returned
        $this->assertCount(3, $records, 'EloquentLoader should yield all records');
        $this->assertSame('Widget A', $records[0]['name'], 'First record should be Widget A');
    }

    public function test_eloquent_loader_returns_columns_from_table_yaml(): void
    {
        // Given
        $loader = new EloquentLoader(
            query: ProductModel::query(),
            tableDirectory: $this->tmpDir.'/products',
        );

        // When / Then
        $this->assertSame(
            ['id' => 'int', 'name' => 'string', 'country' => 'string', 'price' => 'int'],
            $loader->columns(),
            'columns() should return definitions read from table.yaml',
        );
    }

    public function test_eloquent_loader_returns_indexes_from_table_yaml(): void
    {
        // Given
        $this->writeYaml($this->tmpDir.'/products/table.yaml', [
            'columns' => ['id' => 'int', 'name' => 'string', 'country' => 'string', 'price' => 'int'],
            'indexes' => [
                ['columns' => ['country'], 'unique' => false],
            ],
        ]);
        $loader = new EloquentLoader(
            query: ProductModel::query(),
            tableDirectory: $this->tmpDir.'/products',
        );

        // When / Then
        $this->assertSame(
            [['columns' => ['country'], 'unique' => false]],
            $loader->indexes(),
            'indexes() should return definitions read from table.yaml',
        );
    }

    public function test_eloquent_loader_returns_empty_indexes_when_no_indexes_section(): void
    {
        // Given — table.yaml has no indexes section
        $loader = new EloquentLoader(
            query: ProductModel::query(),
            tableDirectory: $this->tmpDir.'/products',
        );

        // When / Then
        $this->assertSame([], $loader->indexes(), 'indexes() should return empty array when indexes section is absent');
    }

    public function test_eloquent_loader_returns_primary_key_from_table_yaml(): void
    {
        // Given — table.yaml with explicit primary_key
        $this->writeYaml($this->tmpDir.'/products/table.yaml', [
            'primary_key' => 'code',
            'columns' => ['code' => 'string', 'name' => 'string'],
        ]);
        $loader = new EloquentLoader(
            query: ProductModel::query(),
            tableDirectory: $this->tmpDir.'/products',
        );

        // When / Then
        $this->assertSame('code', $loader->primaryKey(), 'primaryKey() should return the value from table.yaml');
    }

    public function test_eloquent_loader_returns_default_primary_key_when_not_specified(): void
    {
        // Given — table.yaml without primary_key field
        $loader = new EloquentLoader(
            query: ProductModel::query(),
            tableDirectory: $this->tmpDir.'/products',
        );

        // When / Then
        $this->assertSame('id', $loader->primaryKey(), 'primaryKey() should default to "id" when not specified');
    }

    public function test_eloquent_loader_returns_version_from_resolver(): void
    {
        $loader = new EloquentLoader(
            query: ProductModel::query(),
            tableDirectory: $this->tmpDir.'/products',
            resolver: new StaticVersionResolver('v2.0.0'),
        );

        $this->assertSame('v2.0.0', $loader->version(), 'version() should return the version resolved by the resolver');
    }

    public function test_eloquent_loader_with_query_scope(): void
    {
        // Given: a query scoped to country=JP
        $loader = new EloquentLoader(
            query: ProductModel::query()->where('country', 'JP'),
            tableDirectory: $this->tmpDir.'/products',
        );

        // When: loading
        $records = iterator_to_array($loader->load(), preserve_keys: false);

        // Then: only JP records
        $this->assertCount(2, $records, 'Scoped query should yield only matching records');
    }

    // =========================================================================
    // QueryBuilderLoader
    // =========================================================================

    public function test_query_builder_loader_yields_all_records(): void
    {
        // Given: a products table with 3 records
        assert($this->app !== null);
        $loader = new QueryBuilderLoader(
            query: $this->app['db']->table('products'),
            tableDirectory: $this->tmpDir.'/products',
            resolver: new StaticVersionResolver('v1.0.0'),
        );

        // When: loading records
        $records = iterator_to_array($loader->load(), preserve_keys: false);

        // Then: all 3 records should be returned
        $this->assertCount(3, $records, 'QueryBuilderLoader should yield all records');
        $this->assertSame('Widget A', $records[0]['name'], 'First record should be Widget A');
    }

    public function test_query_builder_loader_returns_columns_from_table_yaml(): void
    {
        // Given
        assert($this->app !== null);
        $loader = new QueryBuilderLoader(
            query: $this->app['db']->table('products'),
            tableDirectory: $this->tmpDir.'/products',
        );

        // When / Then
        $this->assertSame(
            ['id' => 'int', 'name' => 'string', 'country' => 'string', 'price' => 'int'],
            $loader->columns(),
            'columns() should return definitions read from table.yaml',
        );
    }

    public function test_query_builder_loader_returns_indexes_from_table_yaml(): void
    {
        // Given
        $this->writeYaml($this->tmpDir.'/products/table.yaml', [
            'columns' => ['id' => 'int', 'name' => 'string', 'country' => 'string', 'price' => 'int'],
            'indexes' => [
                ['columns' => ['price'], 'unique' => false],
            ],
        ]);
        assert($this->app !== null);
        $loader = new QueryBuilderLoader(
            query: $this->app['db']->table('products'),
            tableDirectory: $this->tmpDir.'/products',
        );

        // When / Then
        $this->assertSame(
            [['columns' => ['price'], 'unique' => false]],
            $loader->indexes(),
            'indexes() should return definitions read from table.yaml',
        );
    }

    public function test_query_builder_loader_returns_primary_key_from_table_yaml(): void
    {
        // Given — table.yaml with explicit primary_key
        $this->writeYaml($this->tmpDir.'/products/table.yaml', [
            'primary_key' => 'sku',
            'columns' => ['sku' => 'string', 'name' => 'string'],
        ]);
        assert($this->app !== null);
        $loader = new QueryBuilderLoader(
            query: $this->app['db']->table('products'),
            tableDirectory: $this->tmpDir.'/products',
        );

        // When / Then
        $this->assertSame('sku', $loader->primaryKey(), 'primaryKey() should return the value from table.yaml');
    }

    public function test_query_builder_loader_returns_default_primary_key_when_not_specified(): void
    {
        // Given — table.yaml without primary_key field
        assert($this->app !== null);
        $loader = new QueryBuilderLoader(
            query: $this->app['db']->table('products'),
            tableDirectory: $this->tmpDir.'/products',
        );

        // When / Then
        $this->assertSame('id', $loader->primaryKey(), 'primaryKey() should default to "id" when not specified');
    }

    public function test_query_builder_loader_returns_version_from_resolver(): void
    {
        assert($this->app !== null);
        $loader = new QueryBuilderLoader(
            query: $this->app['db']->table('products'),
            tableDirectory: $this->tmpDir.'/products',
            resolver: new StaticVersionResolver('v3.0.0'),
        );

        $this->assertSame('v3.0.0', $loader->version(), 'version() should return the version resolved by the resolver');
    }

    public function test_query_builder_loader_with_where_clause(): void
    {
        // Given: query with price > 150
        assert($this->app !== null);
        $loader = new QueryBuilderLoader(
            query: $this->app['db']->table('products')->where('price', '>', 150),
            tableDirectory: $this->tmpDir.'/products',
        );

        // When: loading
        $records = iterator_to_array($loader->load(), preserve_keys: false);

        // Then: only records with price > 150
        $this->assertCount(2, $records, 'Filtered query should yield only matching records');
    }
}
