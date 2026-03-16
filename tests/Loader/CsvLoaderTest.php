<?php

namespace Kura\Tests\Loader;

use DateTimeImmutable;
use Kura\Loader\CsvLoader;
use Kura\Loader\CsvVersionResolver;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests (AAA format) for CsvLoader.
 *
 * Directory layout under a "table directory":
 *   {tableDir}/
 *     {version}.csv   — data snapshot for that version
 *     defines.csv     — column,type,description
 *     indexes.csv     — columns,unique
 *
 * Global:
 *   versions.csv      — id,version,activated_at  (sibling of table dirs)
 *
 * CsvLoader resolves the active version via CsvVersionResolver, then
 * streams records from {tableDir}/{version}.csv as an associative array
 * generator, casting values according to defines.csv.
 */
class CsvLoaderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/kura_csvloader_test_'.uniqid();
        mkdir($this->tmpDir.'/products', recursive: true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    private function removeDirectory(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $item) {
            is_dir($item) ? $this->removeDirectory($item) : unlink($item);
        }
        rmdir($dir);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** @param list<array{id: int, version: string, activated_at: string}> $rows */
    private function writeVersionsCsv(array $rows): void
    {
        $fp = fopen($this->tmpDir.'/versions.csv', 'w');
        assert($fp !== false);
        fputcsv($fp, ['id', 'version', 'activated_at'], escape: '');
        foreach ($rows as $row) {
            fputcsv($fp, [$row['id'], $row['version'], $row['activated_at']], escape: '');
        }
        fclose($fp);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<mixed>>  $rows
     */
    private function writeCsv(string $path, array $headers, array $rows): void
    {
        $fp = fopen($path, 'w');
        assert($fp !== false);
        fputcsv($fp, $headers, escape: '');
        foreach ($rows as $row) {
            fputcsv($fp, $row, escape: '');
        }
        fclose($fp);
    }

    private function makeResolver(DateTimeImmutable $now): CsvVersionResolver
    {
        return new CsvVersionResolver(
            $this->tmpDir.'/versions.csv',
            $now,
        );
    }

    // =========================================================================
    // Basic loading
    // =========================================================================

    public function test_load_yields_all_records_from_active_version_csv(): void
    {
        // Arrange
        $this->writeVersionsCsv([
            ['id' => 1, 'version' => 'v1.0.0', 'activated_at' => '2024-01-01 00:00:00'],
        ]);
        $this->writeCsv(
            $this->tmpDir.'/products/defines.csv',
            ['column', 'type', 'description'],
            [['id', 'int', 'Primary key'], ['name', 'string', 'Product name'], ['price', 'int', 'Price']],
        );
        $this->writeCsv(
            $this->tmpDir.'/products/v1.0.0.csv',
            ['id', 'name', 'price'],
            [[1, 'Widget', 100], [2, 'Gadget', 250]],
        );

        $loader = new CsvLoader(
            tableDirectory: $this->tmpDir.'/products',
            resolver: $this->makeResolver(new DateTimeImmutable('2024-06-01')),
        );

        // Act
        $records = iterator_to_array($loader->load());

        // Assert
        $this->assertCount(2, $records);
        $this->assertSame(['id' => 1, 'name' => 'Widget', 'price' => 100], $records[0]);
        $this->assertSame(['id' => 2, 'name' => 'Gadget', 'price' => 250], $records[1]);
    }

    public function test_load_casts_types_according_to_defines(): void
    {
        // Arrange — CSV stores everything as strings; defines.csv specifies types
        $this->writeVersionsCsv([
            ['id' => 1, 'version' => 'v1.0.0', 'activated_at' => '2024-01-01 00:00:00'],
        ]);
        $this->writeCsv(
            $this->tmpDir.'/products/defines.csv',
            ['column', 'type', 'description'],
            [
                ['id',     'int',   'Primary key'],
                ['name',   'string', 'Name'],
                ['price',  'float', 'Price'],
                ['active', 'bool',  'Is active'],
            ],
        );
        $this->writeCsv(
            $this->tmpDir.'/products/v1.0.0.csv',
            ['id', 'name', 'price', 'active'],
            [['1', 'Widget', '9.99', '1']],
        );

        $loader = new CsvLoader(
            tableDirectory: $this->tmpDir.'/products',
            resolver: $this->makeResolver(new DateTimeImmutable('2024-06-01')),
        );

        // Act
        $records = iterator_to_array($loader->load());

        // Assert
        $record = $records[0];
        $this->assertSame(1, $record['id']);
        $this->assertSame('Widget', $record['name']);
        $this->assertSame(9.99, $record['price']);
        $this->assertTrue($record['active']);
    }

    public function test_load_uses_later_version_when_available(): void
    {
        // Arrange
        $this->writeVersionsCsv([
            ['id' => 1, 'version' => 'v1.0.0', 'activated_at' => '2024-01-01 00:00:00'],
            ['id' => 2, 'version' => 'v1.1.0', 'activated_at' => '2024-06-01 00:00:00'],
        ]);
        $this->writeCsv(
            $this->tmpDir.'/products/defines.csv',
            ['column', 'type', 'description'],
            [['id', 'int', 'Primary key'], ['name', 'string', 'Name']],
        );
        $this->writeCsv(
            $this->tmpDir.'/products/v1.0.0.csv',
            ['id', 'name'],
            [[1, 'Old Widget']],
        );
        $this->writeCsv(
            $this->tmpDir.'/products/v1.1.0.csv',
            ['id', 'name'],
            [[1, 'New Widget'], [2, 'Gadget']],
        );

        $loader = new CsvLoader(
            tableDirectory: $this->tmpDir.'/products',
            resolver: $this->makeResolver(new DateTimeImmutable('2024-07-01')),
        );

        // Act
        $records = iterator_to_array($loader->load());

        // Assert — v1.1.0 loaded (full snapshot, not diff)
        $this->assertCount(2, $records);
        $this->assertSame('New Widget', $records[0]['name']);
    }

    public function test_load_yields_nothing_when_no_version_is_active(): void
    {
        // Arrange — version starts in the future
        $this->writeVersionsCsv([
            ['id' => 1, 'version' => 'v1.0.0', 'activated_at' => '2025-01-01 00:00:00'],
        ]);
        $this->writeCsv(
            $this->tmpDir.'/products/defines.csv',
            ['column', 'type', 'description'],
            [['id', 'int', 'Primary key']],
        );

        $loader = new CsvLoader(
            tableDirectory: $this->tmpDir.'/products',
            resolver: $this->makeResolver(new DateTimeImmutable('2024-01-01')),
        );

        // Act
        $records = iterator_to_array($loader->load());

        // Assert
        $this->assertCount(0, $records);
    }

    public function test_load_yields_nothing_when_data_csv_is_missing(): void
    {
        // Arrange — version resolved but matching data file does not exist
        $this->writeVersionsCsv([
            ['id' => 1, 'version' => 'v1.0.0', 'activated_at' => '2024-01-01 00:00:00'],
        ]);
        $this->writeCsv(
            $this->tmpDir.'/products/defines.csv',
            ['column', 'type', 'description'],
            [['id', 'int', 'Primary key']],
        );
        // v1.0.0.csv intentionally not created

        $loader = new CsvLoader(
            tableDirectory: $this->tmpDir.'/products',
            resolver: $this->makeResolver(new DateTimeImmutable('2024-06-01')),
        );

        // Act
        $records = iterator_to_array($loader->load());

        // Assert
        $this->assertCount(0, $records);
    }

    public function test_load_is_a_generator(): void
    {
        // Arrange
        $this->writeVersionsCsv([
            ['id' => 1, 'version' => 'v1.0.0', 'activated_at' => '2024-01-01 00:00:00'],
        ]);
        $this->writeCsv(
            $this->tmpDir.'/products/defines.csv',
            ['column', 'type', 'description'],
            [['id', 'int', 'Primary key']],
        );
        $this->writeCsv(
            $this->tmpDir.'/products/v1.0.0.csv',
            ['id'],
            [[1]],
        );

        $loader = new CsvLoader(
            tableDirectory: $this->tmpDir.'/products',
            resolver: $this->makeResolver(new DateTimeImmutable('2024-06-01')),
        );

        // Act / Assert
        $this->assertInstanceOf(\Generator::class, $loader->load());
    }
}
