<?php

namespace Kura\Tests\Index;

use Kura\Index\IndexBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Feature: Build index entries from records
 *
 * Given a set of records and index definitions,
 * When building indexes,
 * Then sorted [[value, [ids]], ...] entries should be produced,
 * with optional chunk splitting.
 */
class IndexBuilderTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $records;

    protected function setUp(): void
    {
        $this->records = [
            ['id' => 1, 'name' => 'Alice', 'country' => 'JP', 'category' => 'A', 'price' => 500],
            ['id' => 2, 'name' => 'Bob', 'country' => 'US', 'category' => 'B', 'price' => 200],
            ['id' => 3, 'name' => 'Charlie', 'country' => 'JP', 'category' => 'A', 'price' => 100],
            ['id' => 4, 'name' => 'Dave', 'country' => 'DE', 'category' => 'C', 'price' => 700],
            ['id' => 5, 'name' => 'Eve', 'country' => 'US', 'category' => 'A', 'price' => 500],
        ];
    }

    // =========================================================================
    // Single column index (non-unique)
    // =========================================================================

    public function test_build_single_column_index(): void
    {
        // Given records with 'country' column
        $builder = new IndexBuilder('id');

        // When building a non-unique index on 'country'
        $result = $builder->buildColumn($this->records, 'country');

        // Then entries should be sorted by value and contain correct IDs
        $this->assertSame(
            [
                ['DE', [4]],
                ['JP', [1, 3]],
                ['US', [2, 5]],
            ],
            $result,
            'Single column index should group IDs by value, sorted ascending',
        );
    }

    public function test_build_index_values_sorted_ascending(): void
    {
        $builder = new IndexBuilder('id');

        $result = $builder->buildColumn($this->records, 'price');

        $values = array_column($result, 0);
        $this->assertSame([100, 200, 500, 700], $values, 'Index values should be sorted in ascending order');
    }

    public function test_build_index_groups_duplicate_values(): void
    {
        $builder = new IndexBuilder('id');

        $result = $builder->buildColumn($this->records, 'price');

        // price=500 has IDs 1 and 5
        $price500 = null;
        foreach ($result as $entry) {
            if ($entry[0] === 500) {
                $price500 = $entry;
                break;
            }
        }

        $this->assertNotNull($price500, 'Should find entry for price=500');
        sort($price500[1]);
        $this->assertSame([1, 5], $price500[1], 'Duplicate values should group all IDs together');
    }

    // =========================================================================
    // Chunk splitting
    // =========================================================================

    public function test_chunk_splits_by_unique_value_count(): void
    {
        $builder = new IndexBuilder('id');

        // 4 unique values: 100, 200, 500, 700 → chunk_size=2 → 2 chunks
        $chunks = $builder->buildColumnChunked($this->records, 'price', chunkSize: 2);

        $this->assertCount(2, $chunks, 'Should produce 2 chunks for 4 unique values with chunk_size=2');
    }

    public function test_chunk_entries_are_sorted_within_chunk(): void
    {
        $builder = new IndexBuilder('id');

        $chunks = $builder->buildColumnChunked($this->records, 'price', chunkSize: 2);

        // Chunk 0: values 100, 200
        $values0 = array_column($chunks[0]['entries'], 0);
        $this->assertSame([100, 200], $values0, 'First chunk should contain first 2 values in order');

        // Chunk 1: values 500, 700
        $values1 = array_column($chunks[1]['entries'], 0);
        $this->assertSame([500, 700], $values1, 'Second chunk should contain next 2 values in order');
    }

    public function test_chunk_meta_has_correct_min_max(): void
    {
        $builder = new IndexBuilder('id');

        $chunks = $builder->buildColumnChunked($this->records, 'price', chunkSize: 2);

        $this->assertSame(100, $chunks[0]['min'], 'First chunk min should be 100');
        $this->assertSame(200, $chunks[0]['max'], 'First chunk max should be 200');
        $this->assertSame(500, $chunks[1]['min'], 'Second chunk min should be 500');
        $this->assertSame(700, $chunks[1]['max'], 'Second chunk max should be 700');
    }

    public function test_no_chunking_when_chunk_size_exceeds_unique_values(): void
    {
        $builder = new IndexBuilder('id');

        // 4 unique values with chunk_size=10 → 1 chunk
        $chunks = $builder->buildColumnChunked($this->records, 'price', chunkSize: 10);

        $this->assertCount(1, $chunks, 'Should produce 1 chunk when chunk_size exceeds unique value count');
    }

    public function test_chunk_preserves_all_ids(): void
    {
        $builder = new IndexBuilder('id');

        $chunks = $builder->buildColumnChunked($this->records, 'price', chunkSize: 2);

        // Collect all IDs from all chunks
        $allIds = [];
        foreach ($chunks as $chunk) {
            foreach ($chunk['entries'] as $entry) {
                $allIds = array_merge($allIds, $entry[1]);
            }
        }
        sort($allIds);

        $this->assertSame([1, 2, 3, 4, 5], $allIds, 'All IDs should be preserved across chunks');
    }

    // =========================================================================
    // Build all indexes from definitions
    // =========================================================================

    public function test_build_all_creates_indexes_for_each_definition(): void
    {
        $builder = new IndexBuilder('id');

        $definitions = [
            ['columns' => ['country'], 'unique' => false],
            ['columns' => ['category'], 'unique' => false],
        ];

        $result = $builder->buildAll($this->records, $definitions);

        $this->assertArrayHasKey('country', $result, 'Should create index for country');
        $this->assertArrayHasKey('category', $result, 'Should create index for category');
    }

    public function test_build_all_composite_auto_creates_single_column_indexes(): void
    {
        $builder = new IndexBuilder('id');

        $definitions = [
            ['columns' => ['country', 'category'], 'unique' => false],
        ];

        $result = $builder->buildAll($this->records, $definitions);

        // Composite index should auto-generate single-column indexes
        $this->assertArrayHasKey('country', $result, 'Should auto-create single column index for first column');
        $this->assertArrayHasKey('category', $result, 'Should auto-create single column index for second column');
    }

    // =========================================================================
    // Empty records
    // =========================================================================

    public function test_build_column_with_empty_records(): void
    {
        $builder = new IndexBuilder('id');

        $result = $builder->buildColumn([], 'country');

        $this->assertSame([], $result, 'Building index on empty records should return empty array');
    }

    // =========================================================================
    // Null values in column
    // =========================================================================

    // =========================================================================
    // Composite index building
    // =========================================================================

    public function test_build_composite_indexes_returns_hashmap(): void
    {
        $builder = new IndexBuilder('id');

        $definitions = [
            ['columns' => ['country', 'category'], 'unique' => false],
        ];

        $result = $builder->buildCompositeIndexes($this->records, $definitions);

        $this->assertArrayHasKey('country|category', $result, 'Should create composite index with pipe-separated name');

        $map = $result['country|category'];
        $this->assertArrayHasKey('JP|A', $map, 'Should have combined key JP|A');
        $this->assertSame([1, 3], $map['JP|A'], 'JP|A should map to IDs 1 and 3');
        $this->assertSame([2], $map['US|B'], 'US|B should map to ID 2');
        $this->assertSame([4], $map['DE|C'], 'DE|C should map to ID 4');
        $this->assertSame([5], $map['US|A'], 'US|A should map to ID 5');
    }

    public function test_build_composite_indexes_skips_single_column_definitions(): void
    {
        $builder = new IndexBuilder('id');

        $definitions = [
            ['columns' => ['country'], 'unique' => false],
        ];

        $result = $builder->buildCompositeIndexes($this->records, $definitions);

        $this->assertSame([], $result, 'Single column definitions should not produce composite indexes');
    }

    public function test_build_composite_indexes_skips_null_values(): void
    {
        $builder = new IndexBuilder('id');

        /** @var list<array<string, mixed>> $records */
        $records = [
            ['id' => 1, 'country' => 'JP', 'category' => 'A'],
            ['id' => 2, 'country' => 'US', 'category' => null],
            ['id' => 3, 'country' => null, 'category' => 'B'],
        ];

        $definitions = [
            ['columns' => ['country', 'category'], 'unique' => false],
        ];

        $result = $builder->buildCompositeIndexes($records, $definitions);

        $map = $result['country|category'];
        $this->assertCount(1, $map, 'Only records with all non-null columns should be indexed');
        $this->assertSame([1], $map['JP|A'], 'Only complete records should be in composite index');
    }

    public function test_build_composite_indexes_with_empty_records(): void
    {
        $builder = new IndexBuilder('id');

        $definitions = [
            ['columns' => ['country', 'category'], 'unique' => false],
        ];

        $result = $builder->buildCompositeIndexes([], $definitions);

        $this->assertSame([], $result, 'Empty records should return empty composite indexes');
    }

    public function test_build_composite_indexes_with_no_definitions(): void
    {
        $builder = new IndexBuilder('id');

        $result = $builder->buildCompositeIndexes($this->records, []);

        $this->assertSame([], $result, 'No definitions should return empty composite indexes');
    }

    // =========================================================================
    // Null values in column
    // =========================================================================

    public function test_null_values_are_excluded_from_index(): void
    {
        $builder = new IndexBuilder('id');

        /** @var list<array<string, mixed>> $records */
        $records = [
            ['id' => 1, 'country' => 'JP'],
            ['id' => 2, 'country' => null],
            ['id' => 3, 'country' => 'US'],
        ];

        $result = $builder->buildColumn($records, 'country');

        // null values should not be in the index
        $values = array_column($result, 0);
        $this->assertNotContains(null, $values, 'Null values should be excluded from index entries');
        $this->assertCount(2, $result, 'Only non-null values should appear in index');
    }
}
