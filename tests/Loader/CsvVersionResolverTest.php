<?php

namespace Kura\Tests\Loader;

use DateTimeImmutable;
use Kura\Loader\CsvVersionResolver;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests (AAA format) for CsvVersionResolver.
 *
 * versions.csv format:  id,version,activated_at
 * Resolution rule:      latest version whose activated_at <= $now
 */
class CsvVersionResolverTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/kura_version_test_'.uniqid();
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmpDir.'/*') ?: []);
        rmdir($this->tmpDir);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** @param list<array{id: int, version: string, activated_at: string}> $rows */
    private function writeVersionsCsv(array $rows): void
    {
        $path = $this->tmpDir.'/versions.csv';
        $fp = fopen($path, 'w');
        assert($fp !== false);
        fputcsv($fp, ['id', 'version', 'activated_at'], escape: '');
        foreach ($rows as $row) {
            fputcsv($fp, [$row['id'], $row['version'], $row['activated_at']], escape: '');
        }
        fclose($fp);
    }

    private function resolver(): CsvVersionResolver
    {
        return new CsvVersionResolver($this->tmpDir.'/versions.csv');
    }

    // =========================================================================
    // Basic resolution
    // =========================================================================

    public function test_returns_only_version_when_single_entry_is_active(): void
    {
        // Arrange
        $this->writeVersionsCsv([
            ['id' => 1, 'version' => 'v1.0.0', 'activated_at' => '2024-01-01 00:00:00'],
        ]);
        $now = new DateTimeImmutable('2024-06-01 12:00:00');

        // Act
        $version = $this->resolver()->resolveVersion($now);

        // Assert
        $this->assertSame('v1.0.0', $version);
    }

    public function test_returns_latest_active_version_among_multiple(): void
    {
        // Arrange
        $this->writeVersionsCsv([
            ['id' => 1, 'version' => 'v1.0.0', 'activated_at' => '2024-01-01 00:00:00'],
            ['id' => 2, 'version' => 'v1.1.0', 'activated_at' => '2024-03-01 00:00:00'],
            ['id' => 3, 'version' => 'v2.0.0', 'activated_at' => '2024-07-01 00:00:00'],
        ]);
        $now = new DateTimeImmutable('2024-05-15 00:00:00');

        // Act
        $version = $this->resolver()->resolveVersion($now);

        // Assert — v2.0.0 not yet active, v1.1.0 is the latest active
        $this->assertSame('v1.1.0', $version);
    }

    public function test_returns_null_when_no_version_is_active_yet(): void
    {
        // Arrange — all versions are in the future
        $this->writeVersionsCsv([
            ['id' => 1, 'version' => 'v1.0.0', 'activated_at' => '2025-01-01 00:00:00'],
        ]);
        $now = new DateTimeImmutable('2024-01-01 00:00:00');

        // Act
        $version = $this->resolver()->resolveVersion($now);

        // Assert
        $this->assertNull($version);
    }

    public function test_version_becomes_active_exactly_at_activated_at(): void
    {
        // Arrange
        $this->writeVersionsCsv([
            ['id' => 1, 'version' => 'v1.0.0', 'activated_at' => '2024-06-01 00:00:00'],
        ]);
        $now = new DateTimeImmutable('2024-06-01 00:00:00');

        // Act
        $version = $this->resolver()->resolveVersion($now);

        // Assert — boundary: activated_at == now is active
        $this->assertSame('v1.0.0', $version);
    }

    public function test_version_is_not_active_one_second_before_activated_at(): void
    {
        // Arrange
        $this->writeVersionsCsv([
            ['id' => 1, 'version' => 'v1.0.0', 'activated_at' => '2024-06-01 00:00:00'],
        ]);
        $now = new DateTimeImmutable('2024-05-31 23:59:59');

        // Act
        $version = $this->resolver()->resolveVersion($now);

        // Assert
        $this->assertNull($version);
    }

    public function test_rows_out_of_id_order_resolved_by_activated_at(): void
    {
        // Arrange — rows intentionally stored in reverse id order
        $this->writeVersionsCsv([
            ['id' => 3, 'version' => 'v2.0.0', 'activated_at' => '2024-09-01 00:00:00'],
            ['id' => 1, 'version' => 'v1.0.0', 'activated_at' => '2024-01-01 00:00:00'],
            ['id' => 2, 'version' => 'v1.1.0', 'activated_at' => '2024-04-01 00:00:00'],
        ]);
        $now = new DateTimeImmutable('2024-10-01 00:00:00');

        // Act
        $version = $this->resolver()->resolveVersion($now);

        // Assert — latest by activated_at, not by id order in file
        $this->assertSame('v2.0.0', $version);
    }

    // =========================================================================
    // Edge cases
    // =========================================================================

    public function test_empty_versions_csv_returns_null(): void
    {
        // Arrange — header only, no data rows
        $this->writeVersionsCsv([]);
        $now = new DateTimeImmutable('2024-01-01 00:00:00');

        // Act
        $version = $this->resolver()->resolveVersion($now);

        // Assert
        $this->assertNull($version);
    }

    public function test_returns_null_when_file_does_not_exist(): void
    {
        // Arrange — resolver pointing at non-existent file
        $resolver = new CsvVersionResolver($this->tmpDir.'/nonexistent.csv');
        $now = new DateTimeImmutable('2024-01-01 00:00:00');

        // Act
        $version = $resolver->resolveVersion($now);

        // Assert
        $this->assertNull($version);
    }

    public function test_latest_version_when_all_are_active(): void
    {
        // Arrange
        $this->writeVersionsCsv([
            ['id' => 1, 'version' => 'v1.0.0', 'activated_at' => '2023-01-01 00:00:00'],
            ['id' => 2, 'version' => 'v1.1.0', 'activated_at' => '2023-06-01 00:00:00'],
            ['id' => 3, 'version' => 'v1.2.0', 'activated_at' => '2024-01-01 00:00:00'],
        ]);
        $now = new DateTimeImmutable('2025-01-01 00:00:00');

        // Act
        $version = $this->resolver()->resolveVersion($now);

        // Assert
        $this->assertSame('v1.2.0', $version);
    }
}
