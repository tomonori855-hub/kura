<?php

namespace Kura\Tests;

use Illuminate\Database\MultipleRecordsFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Kura\CacheRepository;
use Kura\ReferenceQueryBuilder;
use Kura\Store\ArrayStore;
use Kura\Tests\Support\InMemoryLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests (AAA format) for ReferenceQueryBuilder execution methods.
 *
 * Covers: min, max, sum, avg, value, implode, pluck(key), exists, doesntExist,
 *         existsOr, doesntExistOr, find, findOr, sole, soleValue,
 *         clone, cloneWithout, newQuery, latest, oldest, inRandomOrder,
 *         reorder, forPage, forPageBeforeId, forPageAfterId.
 */
class ReferenceQueryBuilderExecutionTest extends TestCase
{
    private ArrayStore $store;

    /** @var list<array<string, mixed>> */
    private array $users = [
        ['id' => 1, 'name' => 'Alice', 'age' => 30, 'score' => 85, 'country' => 'JP'],
        ['id' => 2, 'name' => 'Bob',   'age' => 25, 'score' => 72, 'country' => 'US'],
        ['id' => 3, 'name' => 'Carol', 'age' => 35, 'score' => 91, 'country' => 'JP'],
        ['id' => 4, 'name' => 'Dave',  'age' => 20, 'score' => 60, 'country' => 'US'],
        ['id' => 5, 'name' => 'Eve',   'age' => 28, 'score' => 78, 'country' => 'UK'],
    ];

    protected function setUp(): void
    {
        $this->store = new ArrayStore;
    }

    private function builder(): ReferenceQueryBuilder
    {
        $repository = new CacheRepository(
            table: 'users',
            primaryKey: 'id',
            loader: new InMemoryLoader($this->users),
            store: $this->store,
        );

        return new ReferenceQueryBuilder(
            table: 'users',
            repository: $repository,
        );
    }

    // =========================================================================
    // Aggregates — data provider covers all four methods in one pass
    // =========================================================================

    /** @return array<string, array{string, string, mixed}> */
    public static function aggregateProvider(): array
    {
        return [
            'min age' => ['age',   'min', 20],
            'max age' => ['age',   'max', 35],
            'sum score' => ['score', 'sum', 386],  // 85+72+91+60+78
            'avg age' => ['age',   'avg', 27.6], // (30+25+35+20+28)/5
        ];
    }

    #[DataProvider('aggregateProvider')]
    public function test_aggregate_over_all_records(string $column, string $method, mixed $expected): void
    {
        // Arrange
        $builder = $this->builder();

        // Act
        $result = $builder->$method($column);

        // Assert
        $this->assertEquals($expected, $result);
    }

    public function test_min_returns_null_on_empty_result(): void
    {
        // Arrange
        $builder = $this->builder()->where('country', 'AU');

        // Act / Assert
        $this->assertNull($builder->min('age'));
    }

    public function test_max_returns_null_on_empty_result(): void
    {
        $this->assertNull($this->builder()->where('country', 'AU')->max('age'));
    }

    public function test_sum_returns_zero_on_empty_result(): void
    {
        $this->assertSame(0, $this->builder()->where('country', 'AU')->sum('score'));
    }

    public function test_avg_returns_null_on_empty_result(): void
    {
        $this->assertNull($this->builder()->where('country', 'AU')->avg('age'));
    }

    public function test_average_is_alias_for_avg(): void
    {
        $this->assertSame(
            $this->builder()->avg('score'),
            $this->builder()->average('score'),
        );
    }

    public function test_aggregate_respects_where_filter(): void
    {
        // Arrange — JP users: Alice(30), Carol(35)
        $builder = $this->builder()->where('country', 'JP');

        // Act / Assert
        $this->assertSame(30, $builder->min('age'));
        $this->assertSame(35, $this->builder()->where('country', 'JP')->max('age'));
    }

    // =========================================================================
    // Scalar extraction — value / implode / pluck with key
    // =========================================================================

    public function test_value_returns_column_from_first_matching_record(): void
    {
        // Arrange
        $builder = $this->builder()->orderBy('id');

        // Act
        $result = $builder->value('name');

        // Assert
        $this->assertSame('Alice', $result);
    }

    public function test_value_returns_null_when_no_match(): void
    {
        $this->assertNull($this->builder()->where('country', 'AU')->value('name'));
    }

    public function test_implode_joins_values_with_glue(): void
    {
        // Arrange
        $builder = $this->builder()->orderBy('name');

        // Act
        $result = $builder->implode('name', ', ');

        // Assert
        $this->assertSame('Alice, Bob, Carol, Dave, Eve', $result);
    }

    public function test_implode_defaults_to_empty_glue(): void
    {
        $this->assertSame('Eve', $this->builder()->where('country', 'UK')->implode('name'));
    }

    public function test_pluck_with_key_returns_map_keyed_by_column(): void
    {
        // Arrange
        $builder = $this->builder()->orderBy('id');

        // Act
        $result = $builder->pluck('name', 'id');

        // Assert
        $this->assertSame([1 => 'Alice', 2 => 'Bob', 3 => 'Carol', 4 => 'Dave', 5 => 'Eve'], $result);
    }

    public function test_pluck_with_key_respects_where(): void
    {
        // Arrange
        $builder = $this->builder()->where('country', 'JP')->orderBy('id');

        // Act
        $result = $builder->pluck('name', 'id');

        // Assert
        $this->assertSame([1 => 'Alice', 3 => 'Carol'], $result);
    }

    // =========================================================================
    // Existence — exists / doesntExist / existsOr / doesntExistOr
    // =========================================================================

    public function test_exists_returns_true_when_records_match(): void
    {
        $this->assertTrue($this->builder()->where('country', 'JP')->exists());
    }

    public function test_exists_returns_false_when_nothing_matches(): void
    {
        $this->assertFalse($this->builder()->where('country', 'AU')->exists());
    }

    public function test_doesnt_exist_is_inverse_of_exists(): void
    {
        $this->assertFalse($this->builder()->where('country', 'JP')->doesntExist());
        $this->assertTrue($this->builder()->where('country', 'AU')->doesntExist());
    }

    public function test_exists_or_returns_true_without_calling_callback(): void
    {
        // Arrange
        $builder = $this->builder()->where('country', 'JP');
        $called = false;

        // Act
        $result = $builder->existsOr(function () use (&$called) {
            $called = true;

            return 'fallback';
        });

        // Assert
        $this->assertTrue($result);
        $this->assertFalse($called, 'callback must not fire when records exist');
    }

    public function test_exists_or_calls_callback_when_nothing_matches(): void
    {
        $result = $this->builder()->where('country', 'AU')->existsOr(fn () => 'default');

        $this->assertSame('default', $result);
    }

    public function test_doesnt_exist_or_returns_true_without_calling_callback(): void
    {
        // Arrange
        $builder = $this->builder()->where('country', 'AU');
        $called = false;

        // Act
        $result = $builder->doesntExistOr(function () use (&$called) {
            $called = true;

            return 'fallback';
        });

        // Assert
        $this->assertTrue($result);
        $this->assertFalse($called, 'callback must not fire when no records exist');
    }

    public function test_doesnt_exist_or_calls_callback_when_records_found(): void
    {
        $result = $this->builder()->where('country', 'JP')->doesntExistOr(fn () => 'fallback');

        $this->assertSame('fallback', $result);
    }

    // =========================================================================
    // Find — find / findOr / sole / soleValue
    // =========================================================================

    public function test_find_returns_record_by_primary_key(): void
    {
        // Arrange
        $builder = $this->builder();

        // Act
        $result = $builder->find(1);

        // Assert
        $this->assertNotNull($result);
        $this->assertSame('Alice', $result['name']);
    }

    public function test_find_returns_null_for_nonexistent_id(): void
    {
        $this->assertNull($this->builder()->find(999));
    }

    public function test_find_or_returns_record_when_found(): void
    {
        // Arrange
        $builder = $this->builder();

        // Act
        $result = $builder->findOr(1, fn () => 'not found');

        // Assert
        $this->assertIsArray($result);
        $this->assertSame('Alice', $result['name']);
    }

    public function test_find_or_calls_callback_when_not_found(): void
    {
        $result = $this->builder()->findOr(999, fn () => 'not found');

        $this->assertSame('not found', $result);
    }

    public function test_sole_returns_the_single_matching_record(): void
    {
        // Arrange
        $builder = $this->builder()->where('country', 'UK');

        // Act
        $result = $builder->sole();

        // Assert
        $this->assertSame('Eve', $result['name']);
    }

    public function test_sole_throws_records_not_found_exception(): void
    {
        $this->expectException(RecordsNotFoundException::class);

        $this->builder()->where('country', 'AU')->sole();
    }

    public function test_sole_throws_multiple_records_found_exception(): void
    {
        $this->expectException(MultipleRecordsFoundException::class);

        $this->builder()->where('country', 'JP')->sole();
    }

    public function test_sole_value_extracts_column_from_sole_record(): void
    {
        $result = $this->builder()->where('country', 'UK')->soleValue('name');

        $this->assertSame('Eve', $result);
    }

    // =========================================================================
    // Clone — clone / cloneWithout / newQuery
    // =========================================================================

    public function test_clone_produces_independent_copy(): void
    {
        // Arrange
        $original = $this->builder()->where('country', 'JP');

        // Act — mutate clone, original must be unaffected
        $clone = $original->clone();
        $clone->where('age', '>', 32);

        // Assert
        $this->assertCount(2, $original->get()); // Alice + Carol
        $this->assertCount(1, $clone->get());     // Carol only
    }

    public function test_clone_without_limit_removes_row_cap(): void
    {
        // Arrange — limited to 1 result
        $builder = $this->builder()->limit(1);

        // Act — clone without limit, all 5 users returned
        $clone = $builder->cloneWithout(['limit']);

        // Assert
        $this->assertNull($clone->getLimit());
        $this->assertCount(5, $clone->get());
    }

    public function test_clone_without_offset_removes_skip(): void
    {
        // Arrange — skip first 3 records
        $builder = $this->builder()->orderBy('id')->offset(3);

        // Act — clone without offset, starts from the beginning
        $clone = $builder->cloneWithout(['offset']);

        // Assert
        $this->assertNull($clone->getOffset());
        $this->assertCount(5, $clone->get());
    }

    public function test_clone_without_wheres_removes_filter(): void
    {
        // Arrange — filtered to JP only (2 users)
        $builder = $this->builder()->where('country', 'JP');

        // Act — clone without wheres, all 5 users returned
        $clone = $builder->cloneWithout(['wheres']);

        // Assert
        $this->assertCount(5, $clone->get());
    }

    public function test_new_query_returns_fresh_builder_without_conditions(): void
    {
        // Arrange
        $builder = $this->builder()->where('country', 'JP')->limit(1);

        // Act
        $fresh = $builder->newQuery();

        // Assert
        $this->assertCount(5, $fresh->get());
    }

    // =========================================================================
    // Ordering — latest / oldest / inRandomOrder / reorder
    // =========================================================================

    public function test_latest_orders_column_desc(): void
    {
        // Arrange
        $builder = $this->builder();

        // Act
        $ages = array_column($builder->latest('age')->get(), 'age');

        // Assert
        $this->assertSame([35, 30, 28, 25, 20], $ages);
    }

    public function test_oldest_orders_column_asc(): void
    {
        $ages = array_column($this->builder()->oldest('age')->get(), 'age');

        $this->assertSame([20, 25, 28, 30, 35], $ages);
    }

    public function test_in_random_order_returns_all_records_in_any_order(): void
    {
        // Arrange
        $builder = $this->builder();

        // Act
        $ids = array_column($builder->inRandomOrder()->get(), 'id');
        sort($ids);

        // Assert — all 5 IDs present regardless of order
        $this->assertSame([1, 2, 3, 4, 5], $ids);
    }

    public function test_reorder_with_no_args_clears_all_orders(): void
    {
        // Arrange — initially sorted by name
        $builder = $this->builder()->orderBy('name');

        // Act — clear sorting, records come in insertion order
        $ids = array_column($builder->reorder()->get(), 'id');

        // Assert — insertion order preserved
        $this->assertSame([1, 2, 3, 4, 5], $ids);
    }

    public function test_reorder_can_replace_order_with_new_column(): void
    {
        // Arrange
        $builder = $this->builder()->orderBy('name');

        // Act
        $ages = array_column($builder->reorder('age', 'desc')->get(), 'age');

        // Assert
        $this->assertSame([35, 30, 28, 25, 20], $ages);
    }

    // =========================================================================
    // Cursor pagination — forPage / forPageBeforeId / forPageAfterId
    // =========================================================================

    public function test_for_page_returns_correct_slice(): void
    {
        // Arrange — 5 records ordered by id; page 2 with 2 per page → ids 3,4
        $builder = $this->builder()->orderBy('id');

        // Act
        $ids = array_column($builder->forPage(2, 2)->get(), 'id');

        // Assert
        $this->assertSame([3, 4], $ids);
    }

    public function test_for_page_before_id_returns_records_before_cursor(): void
    {
        // Arrange — 2 records with id < 4, ordered desc → ids 3,2
        $builder = $this->builder();

        // Act
        $ids = array_column($builder->forPageBeforeId(2, 4)->get(), 'id');

        // Assert
        $this->assertSame([3, 2], $ids);
    }

    public function test_for_page_after_id_returns_records_after_cursor(): void
    {
        // Arrange — 2 records with id > 2, ordered asc → ids 3,4
        $builder = $this->builder();

        // Act
        $ids = array_column($builder->forPageAfterId(2, 2)->get(), 'id');

        // Assert
        $this->assertSame([3, 4], $ids);
    }

    public function test_for_page_before_id_with_zero_last_id_applies_no_constraint(): void
    {
        // Arrange — no constraint, desc order, limit 3 → ids 5,4,3
        $builder = $this->builder();

        // Act
        $ids = array_column($builder->forPageBeforeId(3, 0)->get(), 'id');

        // Assert
        $this->assertSame([5, 4, 3], $ids);
    }
}
