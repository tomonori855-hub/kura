<?php

namespace Kura\Tests\Loader;

use Kura\Loader\CsvVersionResolver;
use Kura\Loader\VersionedCsvLoader;
use PHPUnit\Framework\TestCase;

/**
 * Feature: Load versioned records from shared CSV files.
 *
 * Given a directory with data/, definitions/, indexes/ subdirectories,
 * When loading records for a resolved version,
 * Then only rows with version < active version should be included,
 * with the highest version per primary key winning.
 */
class VersionedCsvLoaderTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir().'/kura_test_'.uniqid();
        mkdir("{$this->basePath}/data", 0777, true);
        mkdir("{$this->basePath}/definitions", 0777, true);
        mkdir("{$this->basePath}/indexes", 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->basePath);
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = "{$dir}/{$item}";
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * @param  list<list<string>>  $rows  header + data rows
     */
    private function writeCsv(string $path, array $rows): void
    {
        $fp = fopen($path, 'w');
        assert($fp !== false);
        foreach ($rows as $row) {
            fputcsv($fp, $row, escape: '');
        }
        fclose($fp);
    }

    private function writeVersionsCsv(string $version, string $startAt = '2020-01-01 00:00:00'): void
    {
        $this->writeCsv("{$this->basePath}/versions.csv", [
            ['id', 'version', 'activated_at'],
            ['1', $version, $startAt],
        ]);
    }

    private function makeLoader(string $table = 'products'): VersionedCsvLoader
    {
        $resolver = new CsvVersionResolver("{$this->basePath}/versions.csv");

        return new VersionedCsvLoader(
            basePath: $this->basePath,
            table: $table,
            resolver: $resolver,
        );
    }

    // =========================================================================
    // Basic loading
    // =========================================================================

    public function test_loads_records_with_version_less_than_active(): void
    {
        // Given: active version is v2.0.0, data has v1.0.0 and v2.0.0 rows
        $this->writeVersionsCsv('v2.0.0');

        $this->writeCsv("{$this->basePath}/data/products.csv", [
            ['id', 'name', 'version'],
            ['1', 'Alpha', 'v1.0.0'],     // < v2.0.0 → included
            ['2', 'Beta', 'v2.0.0'],      // >= v2.0.0 → excluded
            ['3', 'Gamma', 'v1.5.0'],     // < v2.0.0 → included
        ]);

        // When
        $loader = $this->makeLoader();
        $records = iterator_to_array($loader->load(), false);

        // Then
        $this->assertCount(2, $records, 'Only records with version < active should be loaded');
        $names = array_column($records, 'name');
        sort($names);
        $this->assertSame(['Alpha', 'Gamma'], $names);
    }

    // =========================================================================
    // Definitions (column types)
    // =========================================================================

    public function test_applies_column_types_from_definitions(): void
    {
        // Given: definitions specify id=int, price=int
        $this->writeVersionsCsv('v2.0.0');

        $this->writeCsv("{$this->basePath}/definitions/products.csv", [
            ['column', 'type'],
            ['id', 'int'],
            ['name', 'string'],
            ['price', 'int'],
        ]);

        $this->writeCsv("{$this->basePath}/data/products.csv", [
            ['id', 'name', 'price', 'version'],
            ['1', 'Alpha', '500', 'v1.0.0'],
        ]);

        // When
        $loader = $this->makeLoader();
        $records = iterator_to_array($loader->load(), false);

        // Then
        $this->assertSame(1, $records[0]['id'], 'id should be cast to int');
        $this->assertSame(500, $records[0]['price'], 'price should be cast to int');
        $this->assertSame('Alpha', $records[0]['name'], 'name should remain string');
    }

    public function test_columns_returns_definition_map(): void
    {
        $this->writeVersionsCsv('v2.0.0');

        $this->writeCsv("{$this->basePath}/definitions/products.csv", [
            ['column', 'type'],
            ['id', 'int'],
            ['name', 'string'],
        ]);

        $loader = $this->makeLoader();

        $this->assertSame(
            ['id' => 'int', 'name' => 'string'],
            $loader->columns(),
            'columns() should return the definition map',
        );
    }

    // =========================================================================
    // Indexes
    // =========================================================================

    public function test_loads_index_definitions_from_csv(): void
    {
        $this->writeVersionsCsv('v2.0.0');

        $this->writeCsv("{$this->basePath}/indexes/products.csv", [
            ['columns', 'unique'],
            ['category', 'false'],
            ['email', 'true'],
            ['country|category', 'false'],  // composite
        ]);

        $loader = $this->makeLoader();

        $this->assertSame(
            [
                ['columns' => ['category'], 'unique' => false],
                ['columns' => ['email'], 'unique' => true],
                ['columns' => ['country', 'category'], 'unique' => false],
            ],
            $loader->indexes(),
            'indexes() should parse CSV into index definitions',
        );
    }

    // =========================================================================
    // version()
    // =========================================================================

    public function test_version_returns_resolved_version(): void
    {
        $this->writeVersionsCsv('v3.0.0');

        $loader = $this->makeLoader();

        $this->assertSame('v3.0.0', $loader->version());
    }

    // =========================================================================
    // Edge cases
    // =========================================================================

    public function test_returns_empty_when_no_version_resolved(): void
    {
        // Given: versions.csv with future activated_at
        $this->writeCsv("{$this->basePath}/versions.csv", [
            ['id', 'version', 'activated_at'],
            ['1', 'v1.0.0', '2099-01-01 00:00:00'],
        ]);

        $loader = $this->makeLoader();
        $records = iterator_to_array($loader->load(), false);

        $this->assertSame([], $records, 'Should return empty when no version is active');
    }

    public function test_returns_empty_when_data_file_missing(): void
    {
        $this->writeVersionsCsv('v1.0.0');
        // No data/products.csv

        $loader = $this->makeLoader();
        $records = iterator_to_array($loader->load(), false);

        $this->assertSame([], $records, 'Should return empty when data file is missing');
    }

    public function test_empty_string_value_becomes_null(): void
    {
        $this->writeVersionsCsv('v2.0.0');

        $this->writeCsv("{$this->basePath}/data/products.csv", [
            ['id', 'name', 'description', 'version'],
            ['1', 'Alpha', '', 'v1.0.0'],
        ]);

        $loader = $this->makeLoader();
        $records = iterator_to_array($loader->load(), false);

        $this->assertNull($records[0]['description'], 'Empty string should be cast to null');
    }

    // =========================================================================
    // E2E: full flow with KuraManager
    // =========================================================================

    public function test_end_to_end_with_definitions_and_indexes(): void
    {
        $this->writeVersionsCsv('v3.0.0');

        $this->writeCsv("{$this->basePath}/definitions/products.csv", [
            ['column', 'type'],
            ['id', 'int'],
            ['name', 'string'],
            ['price', 'int'],
            ['version', 'string'],
        ]);

        $this->writeCsv("{$this->basePath}/indexes/products.csv", [
            ['columns', 'unique'],
            ['name', 'false'],
        ]);

        $this->writeCsv("{$this->basePath}/data/products.csv", [
            ['id', 'name', 'price', 'version'],
            ['1', 'Alpha', '100', 'v1.0.0'],
            ['2', 'Beta', '200', 'v2.0.0'],
            ['3', 'Gamma', '300', 'v3.0.0'],   // excluded (>= v3.0.0)
        ]);

        $loader = $this->makeLoader();

        // Verify load
        $records = iterator_to_array($loader->load(), false);
        $this->assertCount(2, $records);

        $byId = array_column($records, null, 'id');
        $this->assertSame('Alpha', $byId[1]['name']);
        $this->assertSame(100, $byId[1]['price']);
        $this->assertSame('Beta', $byId[2]['name']);

        // Verify columns
        $this->assertSame('int', $loader->columns()['id']);

        // Verify indexes
        $this->assertCount(1, $loader->indexes());
        $this->assertSame(['name'], $loader->indexes()[0]['columns']);

        // Verify version
        $this->assertSame('v3.0.0', $loader->version());
    }
}
