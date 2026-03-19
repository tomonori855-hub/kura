<?php

namespace Kura\Tests\Index;

use Kura\Index\IndexResolver;
use Kura\Store\ArrayStore;
use PHPUnit\Framework\TestCase;

/**
 * Feature: Resolve candidate IDs from index using where conditions
 *
 * Given index data stored in a Store,
 * When resolving IDs for various where conditions,
 * Then the correct candidate IDs should be returned
 * using binary search on the sorted index entries.
 */
class IndexResolverTest extends TestCase
{
    private ArrayStore $store;

    private IndexResolver $resolver;

    protected function setUp(): void
    {
        $this->store = new ArrayStore;
        $this->resolver = new IndexResolver($this->store, 'products', 'v1');

        // Store index entries for 'country' (no chunk)
        $this->store->putIndex('products', 'v1', 'country', [
            ['DE', [4]],
            ['JP', [1, 3, 6]],
            ['US', [2, 5, 8]],
        ], 3600);

        // Store index entries for 'price' (no chunk)
        $this->store->putIndex('products', 'v1', 'price', [
            [100, [3, 7]],
            [200, [1, 12]],
            [500, [6, 9, 15]],
            [700, [8, 14]],
            [1000, [4, 11]],
        ], 3600);
    }

    // =========================================================================
    // Basic meta structure (no chunks)
    // =========================================================================

    /**
     * @return array<string, mixed>
     */
    private function metaNoChunk(): array
    {
        return [
            'columns' => ['id' => 'int', 'country' => 'string', 'price' => 'int'],
            'indexes' => [
                'country' => [],
                'price' => [],
            ],
        ];
    }

    // =========================================================================
    // Equal (=)
    // =========================================================================

    public function test_resolve_equal(): void
    {
        // Given index for country with JP => [1, 3, 6]
        $meta = $this->metaNoChunk();

        // When resolving where('country', '=', 'JP')
        $where = ['type' => 'basic', 'column' => 'country', 'operator' => '=', 'value' => 'JP', 'boolean' => 'and'];
        $result = $this->resolver->resolveForWhere($where, $meta);

        // Then IDs [1, 3, 6] should be returned
        $this->assertNotNull($result, 'Index should resolve for equal condition');
        sort($result);
        $this->assertSame([1, 3, 6], $result, 'Equal should return IDs matching the value');
    }

    public function test_resolve_equal_no_match(): void
    {
        $meta = $this->metaNoChunk();

        $where = ['type' => 'basic', 'column' => 'country', 'operator' => '=', 'value' => 'FR', 'boolean' => 'and'];
        $result = $this->resolver->resolveForWhere($where, $meta);

        $this->assertNotNull($result, 'Index should resolve even for non-matching value');
        $this->assertSame([], $result, 'Equal with no match should return empty array');
    }

    // =========================================================================
    // Greater than (>, >=)
    // =========================================================================

    public function test_resolve_greater_than(): void
    {
        $meta = $this->metaNoChunk();

        $where = ['type' => 'basic', 'column' => 'price', 'operator' => '>', 'value' => 500, 'boolean' => 'and'];
        $result = $this->resolver->resolveForWhere($where, $meta);

        $this->assertNotNull($result, 'Index should resolve for > condition');
        sort($result);
        $this->assertSame([4, 8, 11, 14], $result, 'Greater than should return IDs for values above threshold');
    }

    public function test_resolve_greater_than_or_equal(): void
    {
        $meta = $this->metaNoChunk();

        $where = ['type' => 'basic', 'column' => 'price', 'operator' => '>=', 'value' => 500, 'boolean' => 'and'];
        $result = $this->resolver->resolveForWhere($where, $meta);

        $this->assertNotNull($result, 'Index should resolve for >= condition');
        sort($result);
        $this->assertSame([4, 6, 8, 9, 11, 14, 15], $result, 'Greater than or equal should include the boundary value');
    }

    // =========================================================================
    // Less than (<, <=)
    // =========================================================================

    public function test_resolve_less_than(): void
    {
        $meta = $this->metaNoChunk();

        $where = ['type' => 'basic', 'column' => 'price', 'operator' => '<', 'value' => 500, 'boolean' => 'and'];
        $result = $this->resolver->resolveForWhere($where, $meta);

        $this->assertNotNull($result, 'Index should resolve for < condition');
        sort($result);
        $this->assertSame([1, 3, 7, 12], $result, 'Less than should return IDs for values below threshold');
    }

    public function test_resolve_less_than_or_equal(): void
    {
        $meta = $this->metaNoChunk();

        $where = ['type' => 'basic', 'column' => 'price', 'operator' => '<=', 'value' => 500, 'boolean' => 'and'];
        $result = $this->resolver->resolveForWhere($where, $meta);

        $this->assertNotNull($result, 'Index should resolve for <= condition');
        sort($result);
        $this->assertSame([1, 3, 6, 7, 9, 12, 15], $result, 'Less than or equal should include the boundary value');
    }

    // =========================================================================
    // Between
    // =========================================================================

    public function test_resolve_between(): void
    {
        $meta = $this->metaNoChunk();

        $where = ['type' => 'between', 'column' => 'price', 'values' => [200, 700], 'not' => false, 'boolean' => 'and'];
        $result = $this->resolver->resolveForWhere($where, $meta);

        $this->assertNotNull($result, 'Index should resolve for between condition');
        sort($result);
        $this->assertSame([1, 6, 8, 9, 12, 14, 15], $result, 'Between should return IDs for values within inclusive range');
    }

    // =========================================================================
    // Non-indexed column returns null
    // =========================================================================

    public function test_resolve_non_indexed_column_returns_null(): void
    {
        $meta = $this->metaNoChunk();

        $where = ['type' => 'basic', 'column' => 'name', 'operator' => '=', 'value' => 'Alice', 'boolean' => 'and'];
        $result = $this->resolver->resolveForWhere($where, $meta);

        $this->assertNull($result, 'Non-indexed column should return null to indicate full scan needed');
    }

    // =========================================================================
    // Unsupported operator returns null
    // =========================================================================

    public function test_resolve_unsupported_operator_returns_null(): void
    {
        $meta = $this->metaNoChunk();

        // 'like' operator is not index-resolvable
        $where = ['type' => 'basic', 'column' => 'country', 'operator' => 'like', 'value' => 'J%', 'boolean' => 'and'];
        $result = $this->resolver->resolveForWhere($where, $meta);

        $this->assertNull($result, 'Unsupported operator should return null to indicate full scan needed');
    }

    // =========================================================================
    // Non-basic where type returns null (filter, nested, etc.)
    // =========================================================================

    public function test_resolve_filter_type_returns_null(): void
    {
        $meta = $this->metaNoChunk();

        $where = ['type' => 'filter', 'callback' => fn ($r) => true, 'boolean' => 'and'];
        $result = $this->resolver->resolveForWhere($where, $meta);

        $this->assertNull($result, 'Filter type should return null — not index-resolvable');
    }

    // =========================================================================
    // Index missing from store returns null
    // =========================================================================

    public function test_resolve_returns_null_when_index_missing_from_store(): void
    {
        $meta = [
            'columns' => ['id' => 'int', 'status' => 'string'],
            'indexes' => ['status' => []], // declared in meta but not stored
        ];

        $where = ['type' => 'basic', 'column' => 'status', 'operator' => '=', 'value' => 'active', 'boolean' => 'and'];
        $result = $this->resolver->resolveForWhere($where, $meta);

        $this->assertNull($result, 'Should return null when index key is missing from store');
    }

    // =========================================================================
    // Intersection (AND of multiple indexed conditions)
    // =========================================================================

    public function test_intersect_combines_multiple_results(): void
    {
        $meta = $this->metaNoChunk();

        $wheres = [
            ['type' => 'basic', 'column' => 'country', 'operator' => '=', 'value' => 'JP', 'boolean' => 'and'],
            ['type' => 'basic', 'column' => 'price', 'operator' => '>=', 'value' => 200, 'boolean' => 'and'],
        ];

        // country=JP → [1, 3, 6], price>=200 → [1, 6, 8, 9, 12, 14, 15, 4, 11]
        // intersection → [1, 6]
        $result = $this->resolver->resolveIds($wheres, $meta);

        $this->assertNotNull($result, 'Should resolve IDs when all conditions are indexed');
        sort($result);
        $this->assertSame([1, 6], $result, 'AND intersection should return IDs present in both index results');
    }

    public function test_resolve_ids_uses_partial_index_when_some_and_conditions_not_indexed(): void
    {
        $meta = $this->metaNoChunk();

        $wheres = [
            ['type' => 'basic', 'column' => 'country', 'operator' => '=', 'value' => 'JP', 'boolean' => 'and'],
            ['type' => 'basic', 'column' => 'name', 'operator' => '=', 'value' => 'Alice', 'boolean' => 'and'],
        ];

        // name is not indexed → skipped (WhereEvaluator handles it)
        // country is indexed → narrows to JP candidates
        $result = $this->resolver->resolveIds($wheres, $meta);

        $this->assertNotNull($result, 'Should use country index even though name has no index');
        sort($result);
        $this->assertSame([1, 3, 6], $result, 'Should return candidates narrowed by the indexed condition');
    }

    public function test_resolve_ids_returns_null_when_no_and_condition_is_indexed(): void
    {
        $meta = $this->metaNoChunk();

        $wheres = [
            ['type' => 'basic', 'column' => 'name', 'operator' => '=', 'value' => 'Alice', 'boolean' => 'and'],
            ['type' => 'basic', 'column' => 'email', 'operator' => '=', 'value' => 'a@example.com', 'boolean' => 'and'],
        ];

        // name and email are both non-indexed → no candidates to narrow → full scan
        $result = $this->resolver->resolveIds($wheres, $meta);

        $this->assertNull($result, 'Should return null when no AND condition can use an index');
    }

    public function test_resolve_ids_returns_null_when_or_condition_is_not_indexed(): void
    {
        $meta = $this->metaNoChunk();

        $wheres = [
            ['type' => 'basic', 'column' => 'country', 'operator' => '=', 'value' => 'JP', 'boolean' => 'and'],
            ['type' => 'basic', 'column' => 'name', 'operator' => '=', 'value' => 'Alice', 'boolean' => 'or'],
        ];

        // name is OR and not indexed → records matching only name=Alice would be missed
        $result = $this->resolver->resolveIds($wheres, $meta);

        $this->assertNull($result, 'Should return null when an OR branch cannot be index-resolved');
    }

    // =========================================================================
    // Chunked index
    // =========================================================================

    public function test_resolve_equal_from_chunked_index(): void
    {
        // Set up chunked index for 'price'
        $this->store->putIndex('products', 'v1', 'price', [
            [100, [3, 7]],
            [200, [1, 12]],
        ], 3600, chunk: 0);

        $this->store->putIndex('products', 'v1', 'price', [
            [500, [6, 9, 15]],
            [700, [8, 14]],
            [1000, [4, 11]],
        ], 3600, chunk: 1);

        $metaChunked = [
            'columns' => ['id' => 'int', 'price' => 'int'],
            'indexes' => [
                'price' => [
                    ['min' => 100, 'max' => 200],
                    ['min' => 500, 'max' => 1000],
                ],
            ],
        ];

        $where = ['type' => 'basic', 'column' => 'price', 'operator' => '=', 'value' => 700, 'boolean' => 'and'];
        $result = $this->resolver->resolveForWhere($where, $metaChunked);

        $this->assertNotNull($result, 'Chunked index should resolve for equal condition');
        $this->assertSame([8, 14], $result, 'Should find IDs from the correct chunk');
    }

    public function test_resolve_between_spanning_multiple_chunks(): void
    {
        $this->store->putIndex('products', 'v1', 'price', [
            [100, [3, 7]],
            [200, [1, 12]],
        ], 3600, chunk: 0);

        $this->store->putIndex('products', 'v1', 'price', [
            [500, [6, 9, 15]],
            [700, [8, 14]],
            [1000, [4, 11]],
        ], 3600, chunk: 1);

        $metaChunked = [
            'columns' => ['id' => 'int', 'price' => 'int'],
            'indexes' => [
                'price' => [
                    ['min' => 100, 'max' => 200],
                    ['min' => 500, 'max' => 1000],
                ],
            ],
        ];

        // BETWEEN 200 AND 700 spans both chunks
        $where = ['type' => 'between', 'column' => 'price', 'values' => [200, 700], 'not' => false, 'boolean' => 'and'];
        $result = $this->resolver->resolveForWhere($where, $metaChunked);

        $this->assertNotNull($result, 'Chunked index should resolve between spanning multiple chunks');
        sort($result);
        $this->assertSame([1, 6, 8, 9, 12, 14, 15], $result, 'Between spanning chunks should collect IDs from all relevant chunks');
    }

    // =========================================================================
    // IN condition
    // =========================================================================

    public function test_resolve_in_condition(): void
    {
        $meta = $this->metaNoChunk();

        $where = [
            'type' => 'in',
            'column' => 'country',
            'values' => ['JP', 'DE'],
            'not' => false,
            'boolean' => 'and',
        ];

        $result = $this->resolver->resolveForWhere($where, $meta);

        $this->assertNotNull($result, 'Index should resolve for IN condition');
        sort($result);
        $this->assertSame([1, 3, 4, 6], $result, 'IN should return union of IDs for all specified values');
    }

    // =========================================================================
    // OR union
    // =========================================================================

    public function test_resolve_or_union(): void
    {
        // Given index for country and price
        $meta = $this->metaNoChunk();

        // When resolving where('country', 'DE')->orWhere('country', 'JP')
        $wheres = [
            ['type' => 'basic', 'column' => 'country', 'operator' => '=', 'value' => 'DE', 'boolean' => 'and'],
            ['type' => 'basic', 'column' => 'country', 'operator' => '=', 'value' => 'JP', 'boolean' => 'or'],
        ];

        // Then DE => [4], JP => [1, 3, 6], union => [1, 3, 4, 6]
        $result = $this->resolver->resolveIds($wheres, $meta);

        $this->assertNotNull($result, 'OR conditions should resolve via union when all are indexed');
        sort($result);
        $this->assertSame([1, 3, 4, 6], $result, 'OR should return union of index results');
    }

    public function test_resolve_and_then_or(): void
    {
        // Given index for country and price
        $meta = $this->metaNoChunk();

        // country=JP AND price>=500 OR country=DE
        // (JP ∩ >=500) ∪ DE = {6} ∪ {4} = {4, 6}
        $wheres = [
            ['type' => 'basic', 'column' => 'country', 'operator' => '=', 'value' => 'JP', 'boolean' => 'and'],
            ['type' => 'basic', 'column' => 'price', 'operator' => '>=', 'value' => 500, 'boolean' => 'and'],
            ['type' => 'basic', 'column' => 'country', 'operator' => '=', 'value' => 'DE', 'boolean' => 'or'],
        ];

        $result = $this->resolver->resolveIds($wheres, $meta);

        $this->assertNotNull($result, 'Mixed AND/OR should resolve when all conditions are indexed');
        sort($result);
        $this->assertSame([4, 6], $result, 'Should intersect AND conditions, then union OR condition');
    }

    public function test_resolve_or_falls_back_when_not_indexed(): void
    {
        $meta = $this->metaNoChunk();

        $wheres = [
            ['type' => 'basic', 'column' => 'country', 'operator' => '=', 'value' => 'JP', 'boolean' => 'and'],
            ['type' => 'basic', 'column' => 'name', 'operator' => '=', 'value' => 'Alice', 'boolean' => 'or'],
        ];

        $result = $this->resolver->resolveIds($wheres, $meta);

        $this->assertNull($result, 'Should return null when any OR condition is not index-resolvable');
    }

    // =========================================================================
    // Composite index
    // =========================================================================

    public function test_resolve_composite_index_for_and_equality(): void
    {
        // Given a composite index country|category
        $this->store->putCompositeIndex('products', 'v1', 'country|category', [
            'JP|A' => [1, 4],
            'JP|B' => [3],
            'US|B' => [2],
        ], 3600);

        $meta = [
            'columns' => ['id' => 'int', 'country' => 'string', 'category' => 'string'],
            'indexes' => ['country' => [], 'category' => []],
            'composites' => ['country|category'],
        ];

        // When resolving where('country', 'JP')->where('category', 'A')
        $wheres = [
            ['type' => 'basic', 'column' => 'country', 'operator' => '=', 'value' => 'JP', 'boolean' => 'and'],
            ['type' => 'basic', 'column' => 'category', 'operator' => '=', 'value' => 'A', 'boolean' => 'and'],
        ];

        $result = $this->resolver->resolveIds($wheres, $meta);

        // Then IDs [1, 4] should be returned via composite lookup
        $this->assertNotNull($result, 'Composite index should resolve AND equality conditions');
        sort($result);
        $this->assertSame([1, 4], $result, 'Composite index should return IDs for combined key');
    }

    public function test_resolve_composite_index_returns_empty_for_no_match(): void
    {
        // Given a composite index
        $this->store->putCompositeIndex('products', 'v1', 'country|category', [
            'JP|A' => [1],
        ], 3600);

        $meta = [
            'columns' => ['id' => 'int', 'country' => 'string', 'category' => 'string'],
            'indexes' => ['country' => [], 'category' => []],
            'composites' => ['country|category'],
        ];

        // When resolving a combination that doesn't exist
        $wheres = [
            ['type' => 'basic', 'column' => 'country', 'operator' => '=', 'value' => 'FR', 'boolean' => 'and'],
            ['type' => 'basic', 'column' => 'category', 'operator' => '=', 'value' => 'Z', 'boolean' => 'and'],
        ];

        $result = $this->resolver->resolveIds($wheres, $meta);

        $this->assertNotNull($result, 'Composite index should resolve even for non-matching key');
        $this->assertSame([], $result, 'Non-matching composite key should return empty array');
    }

    public function test_resolve_composite_skipped_for_non_equality(): void
    {
        // Given a composite index
        $this->store->putCompositeIndex('products', 'v1', 'country|category', [
            'JP|A' => [1],
        ], 3600);

        $meta = [
            'columns' => ['id' => 'int', 'country' => 'string', 'category' => 'string'],
            'indexes' => ['country' => [], 'category' => []],
            'composites' => ['country|category'],
        ];

        // When one condition uses '>' instead of '='
        $wheres = [
            ['type' => 'basic', 'column' => 'country', 'operator' => '=', 'value' => 'JP', 'boolean' => 'and'],
            ['type' => 'basic', 'column' => 'category', 'operator' => '>', 'value' => 'A', 'boolean' => 'and'],
        ];

        // Then falls back to per-column index resolution (not composite)
        $result = $this->resolver->resolveIds($wheres, $meta);

        // category>A is not resolvable (index not in store) → skipped
        // country=JP IS resolvable → partial resolution returns JP candidates
        $this->assertNotNull($result, 'Should use country index even though category index is missing from store');
        sort($result);
        $this->assertSame([1, 3, 6], $result, 'Should return candidates narrowed by the indexed condition');
    }

    public function test_resolve_composite_skipped_for_or_boolean(): void
    {
        $this->store->putCompositeIndex('products', 'v1', 'country|category', [
            'JP|A' => [1],
        ], 3600);

        $meta = [
            'columns' => ['id' => 'int', 'country' => 'string', 'category' => 'string'],
            'indexes' => ['country' => [], 'category' => []],
            'composites' => ['country|category'],
        ];

        // OR conditions should not use composite index
        $wheres = [
            ['type' => 'basic', 'column' => 'country', 'operator' => '=', 'value' => 'JP', 'boolean' => 'and'],
            ['type' => 'basic', 'column' => 'category', 'operator' => '=', 'value' => 'A', 'boolean' => 'or'],
        ];

        // Falls back to per-column, which uses store → null for missing store data
        $result = $this->resolver->resolveIds($wheres, $meta);

        // Country index exists in store from setUp, category does not
        $this->assertNull($result, 'OR conditions should not use composite index');
    }

    public function test_resolve_composite_skipped_when_columns_dont_match(): void
    {
        $this->store->putCompositeIndex('products', 'v1', 'country|category', [
            'JP|A' => [1],
        ], 3600);

        $meta = [
            'columns' => ['id' => 'int', 'country' => 'string', 'price' => 'int'],
            'indexes' => ['country' => [], 'price' => []],
            'composites' => ['country|category'],
        ];

        // Conditions on country + price, but composite is country|category
        $wheres = [
            ['type' => 'basic', 'column' => 'country', 'operator' => '=', 'value' => 'JP', 'boolean' => 'and'],
            ['type' => 'basic', 'column' => 'price', 'operator' => '=', 'value' => 100, 'boolean' => 'and'],
        ];

        $result = $this->resolver->resolveIds($wheres, $meta);

        // Falls back to per-column intersection
        $this->assertNotNull($result, 'Should fall back to per-column when composite columns do not match');
    }

    public function test_resolve_not_in_returns_null(): void
    {
        $meta = $this->metaNoChunk();

        $where = [
            'type' => 'in',
            'column' => 'country',
            'values' => ['JP'],
            'not' => true,
            'boolean' => 'and',
        ];

        $result = $this->resolver->resolveForWhere($where, $meta);

        $this->assertNull($result, 'NOT IN should return null — not efficiently index-resolvable');
    }
}
