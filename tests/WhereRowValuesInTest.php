<?php

namespace Kura\Tests;

use Kura\CacheProcessor;
use Kura\CacheRepository;
use Kura\ReferenceQueryBuilder;
use Kura\Store\ArrayStore;
use Kura\Tests\Support\InMemoryLoader;
use PHPUnit\Framework\TestCase;

/**
 * Feature: ROW constructor IN — (col1, col2) IN ((v1, v2), ...)
 *
 * Given records cached in APCu,
 * When querying with whereRowValuesIn() (multi-column tuple match),
 * Then only records matching the specified column-value tuples should be returned.
 *
 * This is a Kura extension equivalent to MySQL's ROW constructor syntax:
 *   SELECT * FROM t WHERE (col1, col2) IN ((v1a, v2a), (v1b, v2b))
 *
 * Laravel's Query\Builder does not provide this — it requires whereRaw().
 */
class WhereRowValuesInTest extends TestCase
{
    private ArrayStore $store;

    /** @var list<array<string, mixed>> */
    private array $records = [
        ['id' => 1, 'user_id' => 1, 'item_id' => 10, 'qty' => 2],
        ['id' => 2, 'user_id' => 1, 'item_id' => 20, 'qty' => 1],
        ['id' => 3, 'user_id' => 2, 'item_id' => 10, 'qty' => 3],
        ['id' => 4, 'user_id' => 2, 'item_id' => 20, 'qty' => 5],
        ['id' => 5, 'user_id' => 3, 'item_id' => 30, 'qty' => 1],
    ];

    protected function setUp(): void
    {
        $this->store = new ArrayStore;
    }

    private function buildAndQuery(): ReferenceQueryBuilder
    {
        $loader = new InMemoryLoader($this->records);
        $repo = new CacheRepository(
            table: 'cart',
            primaryKey: 'id',
            store: $this->store,
            loader: $loader,
        );
        $repo->rebuild();

        $processor = new CacheProcessor($repo, $this->store);

        return new ReferenceQueryBuilder(
            table: 'cart',
            repository: $repo,
            processor: $processor,
        );
    }

    // =========================================================================
    // Basic whereRowValuesIn
    // =========================================================================

    public function test_matches_exact_tuples(): void
    {
        // Given: cart records with (user_id, item_id) combinations
        $builder = $this->buildAndQuery();

        // When: WHERE (user_id, item_id) IN ((1, 10), (2, 20))
        $result = $builder
            ->whereRowValuesIn(['user_id', 'item_id'], [[1, 10], [2, 20]])
            ->get();

        // Then: records 1 (user=1, item=10) and 4 (user=2, item=20)
        $ids = array_column($result, 'id');
        sort($ids);
        $this->assertSame([1, 4], $ids, 'Should match only records where both columns match a tuple');
    }

    public function test_single_tuple(): void
    {
        $builder = $this->buildAndQuery();

        $result = $builder
            ->whereRowValuesIn(['user_id', 'item_id'], [[3, 30]])
            ->get();

        $this->assertCount(1, $result, 'Single tuple should match single record');
        $this->assertSame(5, $result[0]['id'], 'Should match user=3, item=30');
    }

    public function test_no_matching_tuples(): void
    {
        $builder = $this->buildAndQuery();

        $result = $builder
            ->whereRowValuesIn(['user_id', 'item_id'], [[99, 99]])
            ->get();

        $this->assertSame([], $result, 'Non-matching tuples should return empty');
    }

    public function test_empty_tuples(): void
    {
        $builder = $this->buildAndQuery();

        $result = $builder
            ->whereRowValuesIn(['user_id', 'item_id'], [])
            ->get();

        $this->assertSame([], $result, 'Empty tuples list should return empty');
    }

    public function test_all_records_match(): void
    {
        $builder = $this->buildAndQuery();

        $result = $builder
            ->whereRowValuesIn(['user_id', 'item_id'], [
                [1, 10], [1, 20], [2, 10], [2, 20], [3, 30],
            ])
            ->get();

        $this->assertCount(5, $result, 'All tuples matching should return all records');
    }

    // =========================================================================
    // whereRowValuesNotIn
    // =========================================================================

    public function test_not_in_excludes_matching_tuples(): void
    {
        $builder = $this->buildAndQuery();

        // When: WHERE (user_id, item_id) NOT IN ((1, 10), (2, 20))
        $result = $builder
            ->whereRowValuesNotIn(['user_id', 'item_id'], [[1, 10], [2, 20]])
            ->get();

        // Then: records 2, 3, 5 (everything except records 1 and 4)
        $ids = array_column($result, 'id');
        sort($ids);
        $this->assertSame([2, 3, 5], $ids, 'NOT IN should exclude matching tuples');
    }

    public function test_not_in_with_no_matches_returns_all(): void
    {
        $builder = $this->buildAndQuery();

        $result = $builder
            ->whereRowValuesNotIn(['user_id', 'item_id'], [[99, 99]])
            ->get();

        $this->assertCount(5, $result, 'NOT IN with no matches should return all records');
    }

    // =========================================================================
    // OR variants
    // =========================================================================

    public function test_or_where_row_values_in(): void
    {
        $builder = $this->buildAndQuery();

        // WHERE user_id = 3 OR (user_id, item_id) IN ((1, 10))
        $result = $builder
            ->where('user_id', 3)
            ->orWhereRowValuesIn(['user_id', 'item_id'], [[1, 10]])
            ->get();

        $ids = array_column($result, 'id');
        sort($ids);
        $this->assertSame([1, 5], $ids, 'OR variant should union conditions');
    }

    public function test_or_where_row_values_not_in(): void
    {
        $builder = $this->buildAndQuery();

        // WHERE qty > 4 OR (user_id, item_id) NOT IN ((1,10),(1,20),(2,10),(2,20),(3,30))
        // qty > 4: record 4 only
        // NOT IN all: nothing (all excluded)
        // Result: record 4
        $result = $builder
            ->where('qty', '>', 4)
            ->orWhereRowValuesNotIn(['user_id', 'item_id'], [
                [1, 10], [1, 20], [2, 10], [2, 20], [3, 30],
            ])
            ->get();

        $this->assertCount(1, $result, 'OR NOT IN should work correctly');
        $this->assertSame(4, $result[0]['id'], 'Should match qty > 4');
    }

    // =========================================================================
    // Combined with other WHERE conditions
    // =========================================================================

    public function test_combined_with_where(): void
    {
        $builder = $this->buildAndQuery();

        // WHERE (user_id, item_id) IN ((1, 10), (1, 20), (2, 10)) AND qty >= 2
        $result = $builder
            ->whereRowValuesIn(['user_id', 'item_id'], [[1, 10], [1, 20], [2, 10]])
            ->where('qty', '>=', 2)
            ->get();

        // Records matching tuples: 1(qty=2), 2(qty=1), 3(qty=3)
        // After qty >= 2: 1 and 3
        $ids = array_column($result, 'id');
        sort($ids);
        $this->assertSame([1, 3], $ids, 'Should filter by both row values and additional conditions');
    }

    // =========================================================================
    // NULL handling (DB semantics)
    // =========================================================================

    public function test_null_column_value_never_matches(): void
    {
        // Given: records with NULL values
        $this->records = [
            ['id' => 1, 'user_id' => 1, 'item_id' => 10, 'qty' => 1],
            ['id' => 2, 'user_id' => null, 'item_id' => 10, 'qty' => 1],
            ['id' => 3, 'user_id' => 1, 'item_id' => null, 'qty' => 1],
        ];

        $builder = $this->buildAndQuery();

        // In MySQL: (NULL, 10) IN ((NULL, 10)) → false (NULL propagation)
        $result = $builder
            ->whereRowValuesIn(['user_id', 'item_id'], [[1, 10]])
            ->get();

        $this->assertCount(1, $result, 'Only non-null complete matches should be returned');
        $this->assertSame(1, $result[0]['id'], 'Record with all non-null matching values');
    }

    public function test_null_column_value_not_in_returns_false(): void
    {
        // Given: record with NULL user_id
        $this->records = [
            ['id' => 1, 'user_id' => null, 'item_id' => 10, 'qty' => 1],
            ['id' => 2, 'user_id' => 1, 'item_id' => 10, 'qty' => 1],
        ];

        $builder = $this->buildAndQuery();

        // In MySQL: (NULL, 10) NOT IN ((1, 10)) → NULL (false, not included)
        $result = $builder
            ->whereRowValuesNotIn(['user_id', 'item_id'], [[1, 10]])
            ->get();

        // Only record 2 is checked; record 1 has NULL → always false regardless of NOT
        $this->assertSame([], $result, 'NULL in any column should return false even for NOT IN');
    }

    // =========================================================================
    // Three-column tuples
    // =========================================================================

    public function test_three_column_tuples(): void
    {
        $builder = $this->buildAndQuery();

        // WHERE (user_id, item_id, qty) IN ((1, 10, 2), (3, 30, 1))
        $result = $builder
            ->whereRowValuesIn(['user_id', 'item_id', 'qty'], [[1, 10, 2], [3, 30, 1]])
            ->get();

        $ids = array_column($result, 'id');
        sort($ids);
        $this->assertSame([1, 5], $ids, 'Should support 3-column tuple matching');
    }

    // =========================================================================
    // Composite index acceleration
    // =========================================================================

    public function test_composite_index_accelerates_lookup(): void
    {
        // Given: composite index on (user_id, item_id)
        $loader = new InMemoryLoader(
            records: $this->records,
            columns: ['id' => 'int', 'user_id' => 'int', 'item_id' => 'int', 'qty' => 'int'],
            indexes: [
                ['columns' => ['user_id', 'item_id'], 'unique' => false],
            ],
        );

        $repo = new CacheRepository(
            table: 'cart',
            primaryKey: 'id',
            store: $this->store,
            loader: $loader,
        );
        $repo->rebuild();

        $processor = new CacheProcessor($repo, $this->store);
        $builder = new ReferenceQueryBuilder(
            table: 'cart',
            repository: $repo,
            processor: $processor,
        );

        // When: querying with whereRowValuesIn matching the composite index
        $result = $builder
            ->whereRowValuesIn(['user_id', 'item_id'], [[1, 10], [2, 20]])
            ->get();

        // Then: same result, but resolved via composite index (O(n) lookups)
        $ids = array_column($result, 'id');
        sort($ids);
        $this->assertSame([1, 4], $ids, 'Composite index should produce same results as full scan');
    }

    public function test_composite_index_with_no_matching_tuples(): void
    {
        $loader = new InMemoryLoader(
            records: $this->records,
            columns: ['id' => 'int', 'user_id' => 'int', 'item_id' => 'int', 'qty' => 'int'],
            indexes: [
                ['columns' => ['user_id', 'item_id'], 'unique' => false],
            ],
        );

        $repo = new CacheRepository(
            table: 'cart',
            primaryKey: 'id',
            store: $this->store,
            loader: $loader,
        );
        $repo->rebuild();

        $processor = new CacheProcessor($repo, $this->store);
        $builder = new ReferenceQueryBuilder(
            table: 'cart',
            repository: $repo,
            processor: $processor,
        );

        $result = $builder
            ->whereRowValuesIn(['user_id', 'item_id'], [[99, 99]])
            ->get();

        $this->assertSame([], $result, 'Composite index with no matches should return empty');
    }

    public function test_not_in_falls_back_to_full_scan_even_with_index(): void
    {
        // NOT IN cannot use composite index — should still produce correct results via full scan
        $loader = new InMemoryLoader(
            records: $this->records,
            columns: ['id' => 'int', 'user_id' => 'int', 'item_id' => 'int', 'qty' => 'int'],
            indexes: [
                ['columns' => ['user_id', 'item_id'], 'unique' => false],
            ],
        );

        $repo = new CacheRepository(
            table: 'cart',
            primaryKey: 'id',
            store: $this->store,
            loader: $loader,
        );
        $repo->rebuild();

        $processor = new CacheProcessor($repo, $this->store);
        $builder = new ReferenceQueryBuilder(
            table: 'cart',
            repository: $repo,
            processor: $processor,
        );

        $result = $builder
            ->whereRowValuesNotIn(['user_id', 'item_id'], [[1, 10], [2, 20]])
            ->get();

        $ids = array_column($result, 'id');
        sort($ids);
        $this->assertSame([2, 3, 5], $ids, 'NOT IN should work via full scan even with composite index');
    }
}
