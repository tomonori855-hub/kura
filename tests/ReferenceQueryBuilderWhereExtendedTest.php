<?php

namespace Kura\Tests;

use Kura\CacheRepository;
use Kura\ReferenceQueryBuilder;
use Kura\Store\ArrayStore;
use Kura\Tests\Support\InMemoryLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests (AAA + data providers) for extended WHERE conditions.
 *
 * Covers: whereBetween, whereNotBetween, orWhereBetween, orWhereNotBetween,
 *         whereBetweenColumns, whereNotBetweenColumns,
 *         whereValueBetween, whereValueNotBetween,
 *         whereLike, whereNotLike, orWhereLike, orWhereNotLike,
 *         whereColumn, orWhereColumn,
 *         whereNullSafeEquals, orWhereNullSafeEquals,
 *         whereNot, orWhereNot,
 *         whereAll, whereAny, whereNone,
 *         whereExists, whereNotExists.
 */
class ReferenceQueryBuilderWhereExtendedTest extends TestCase
{
    private ArrayStore $store;

    /**
     * Primary dataset: users with age, name, country, email.
     *
     * @var list<array<string, mixed>>
     */
    private array $users = [
        ['id' => 1, 'name' => 'Alice', 'age' => 30, 'country' => 'JP', 'email' => 'alice@example.com', 'deleted_at' => null],
        ['id' => 2, 'name' => 'Bob',   'age' => 25, 'country' => 'US', 'email' => 'bob@example.com',   'deleted_at' => '2024-01-01'],
        ['id' => 3, 'name' => 'Carol', 'age' => 35, 'country' => 'JP', 'email' => 'carol@example.com', 'deleted_at' => null],
        ['id' => 4, 'name' => 'Dave',  'age' => 20, 'country' => 'US', 'email' => 'dave@example.com',  'deleted_at' => null],
        ['id' => 5, 'name' => 'Eve',   'age' => 28, 'country' => 'UK', 'email' => 'eve@example.com',   'deleted_at' => null],
    ];

    /**
     * Range dataset: each record has its own [min, max] range and a `value`.
     *
     * Used for whereBetweenColumns and whereValueBetween tests.
     *
     * whereBetweenColumns('value', ['min','max']) — value within own range:
     *   id=1: 40<=50<=60 → YES
     *   id=2: 60<=70<=80 → YES
     *   id=3: 20<=30<=45 → YES
     *   id=4: 40<=55<=50 → NO  (55 > max)
     *   id=5: 50<=45<=70 → NO  (45 < min)
     *
     * whereValueBetween(50, ['min','max']) — scalar 50 within record's range:
     *   id=1: 40<=50<=60 → YES
     *   id=2: 60<=50<=80 → NO  (50 < min)
     *   id=3: 20<=50<=45 → NO  (50 > max)
     *   id=4: 40<=50<=50 → YES (50=max boundary)
     *   id=5: 50<=50<=70 → YES (50=min boundary)
     *
     * @var list<array<string, mixed>>
     */
    private array $ranges = [
        ['id' => 1, 'value' => 50, 'min' => 40, 'max' => 60],
        ['id' => 2, 'value' => 70, 'min' => 60, 'max' => 80],
        ['id' => 3, 'value' => 30, 'min' => 20, 'max' => 45],
        ['id' => 4, 'value' => 55, 'min' => 40, 'max' => 50],
        ['id' => 5, 'value' => 45, 'min' => 50, 'max' => 70],
    ];

    protected function setUp(): void
    {
        $this->store = new ArrayStore;
    }

    /** @param list<array<string, mixed>> $records */
    private function builderFor(string $table, string $pk, array $records): ReferenceQueryBuilder
    {
        $repository = new CacheRepository(
            table: $table,
            primaryKey: $pk,
            loader: new InMemoryLoader($records),
            store: $this->store,
        );

        return new ReferenceQueryBuilder(
            table: $table,
            repository: $repository,
        );
    }

    private function users(): ReferenceQueryBuilder
    {
        return $this->builderFor('users', 'id', $this->users);
    }

    private function ranges(): ReferenceQueryBuilder
    {
        return $this->builderFor('ranges', 'id', $this->ranges);
    }

    /** @return array<int, mixed> */
    private function ids(ReferenceQueryBuilder $builder): array
    {
        return $builder->orderBy('id')->pluck('id');
    }

    // =========================================================================
    // whereBetween / whereNotBetween / orWhereBetween / orWhereNotBetween
    // =========================================================================

    /** @return array<string, array{int, int, list<int>}> */
    public static function betweenProvider(): array
    {
        return [
            'range 25-30 (Alice, Bob, Eve)' => [25, 30, [1, 2, 5]],
            'range 20-20 (Dave only)' => [20, 20, [4]],
            'range 36-99 (none)' => [36, 99, []],
        ];
    }

    /**
     * @param  list<int>  $expectedIds
     */
    #[DataProvider('betweenProvider')]
    public function test_where_between_includes_boundary_values(int $min, int $max, array $expectedIds): void
    {
        // Arrange
        $builder = $this->users();

        // Act
        $result = $this->ids($builder->whereBetween('age', [$min, $max]));

        // Assert
        $this->assertSame($expectedIds, $result);
    }

    public function test_where_not_between_excludes_range(): void
    {
        // Arrange — exclude 25-30: Carol(35), Dave(20)
        $builder = $this->users();

        // Act / Assert
        $this->assertEqualsCanonicalizing([3, 4], $this->ids($builder->whereNotBetween('age', [25, 30])));
    }

    public function test_or_where_between_unions_results(): void
    {
        // Arrange — age=20 OR age BETWEEN 34-36 → Dave + Carol
        $builder = $this->users()->where('age', 20)->orWhereBetween('age', [34, 36]);

        // Act / Assert
        $this->assertEqualsCanonicalizing([3, 4], $this->ids($builder));
    }

    public function test_or_where_not_between_unions_with_negation(): void
    {
        // Arrange — age>= 35 OR age NOT BETWEEN 25-35 → Carol + Dave
        $builder = $this->users()
            ->where('age', '>=', 35)
            ->orWhereNotBetween('age', [25, 35]);

        // Carol(35) + Dave(20-NOT in [25..35])
        $this->assertEqualsCanonicalizing([3, 4], $this->ids($builder));
    }

    // =========================================================================
    // whereBetweenColumns — col BETWEEN min_col AND max_col (same record)
    // =========================================================================

    public function test_where_between_columns_matches_records_inside_range(): void
    {
        // Arrange — records 1,2,3 have value within their own [min, max]
        $builder = $this->ranges();

        // Act
        $ids = $this->ids($builder->whereBetweenColumns('value', ['min', 'max']));

        // Assert
        $this->assertSame([1, 2, 3], $ids);
    }

    public function test_where_not_between_columns_matches_records_outside_range(): void
    {
        // Arrange — records 4,5 are outside their own [min, max]
        $ids = $this->ids($this->ranges()->whereNotBetweenColumns('value', ['min', 'max']));

        $this->assertSame([4, 5], $ids);
    }

    // =========================================================================
    // whereValueBetween — scalar BETWEEN min_col AND max_col
    // =========================================================================

    public function test_where_value_between_matches_records_whose_range_contains_scalar(): void
    {
        // scalar=50; records whose [min, max] contain 50 → ids 1,4,5
        $ids = $this->ids($this->ranges()->whereValueBetween(50, ['min', 'max']));

        $this->assertSame([1, 4, 5], $ids);
    }

    public function test_where_value_not_between_excludes_records_containing_scalar(): void
    {
        // records whose [min, max] do NOT contain 50 → ids 2,3
        $ids = $this->ids($this->ranges()->whereValueNotBetween(50, ['min', 'max']));

        $this->assertSame([2, 3], $ids);
    }

    public function test_where_value_between_respects_inclusive_boundary(): void
    {
        // scalar=45 exactly; record 3 has [20,45] (max=45=scalar), record 5 has [50,70] (50>45 → NO)
        // id=1:[40,60]→YES; id=2:[60,80]→NO; id=3:[20,45]→YES(45=max); id=4:[40,50]→YES; id=5:[50,70]→NO
        $ids = $this->ids($this->ranges()->whereValueBetween(45, ['min', 'max']));

        $this->assertSame([1, 3, 4], $ids);
    }

    // =========================================================================
    // whereLike / whereNotLike / orWhereLike / orWhereNotLike
    // =========================================================================

    /** @return array<string, array{string, string, list<int>}> */
    public static function likeProvider(): array
    {
        return [
            'prefix % matches suffix' => ['name', '%ol',   [3]],       // Carol
            'suffix % matches prefix' => ['name', 'A%',    [1]],       // Alice
            'both wildcards' => ['name', '%li%',  [1]],       // Alice
            'underscore single char' => ['name', 'Ali_e', [1]],       // Alice
            'exact match' => ['name', 'Bob',   [2]],
            'no match returns empty' => ['name', 'Zara',  []],
        ];
    }

    /**
     * @param  list<int>  $expectedIds
     */
    #[DataProvider('likeProvider')]
    public function test_where_like_matches_pattern(string $column, string $pattern, array $expectedIds): void
    {
        // Arrange
        $builder = $this->users();

        // Act
        $ids = $this->ids($builder->whereLike($column, $pattern));

        // Assert
        $this->assertSame($expectedIds, $ids);
    }

    public function test_where_not_like_excludes_pattern(): void
    {
        // Arrange — exclude names starting with A → Bob, Carol, Dave, Eve
        $ids = $this->ids($this->users()->whereNotLike('name', 'A%'));

        $this->assertSame([2, 3, 4, 5], $ids);
    }

    public function test_where_like_is_case_insensitive_by_default(): void
    {
        // 'alice' should match 'Alice'
        $ids = $this->ids($this->users()->whereLike('name', 'alice'));

        $this->assertSame([1], $ids);
    }

    public function test_where_like_case_sensitive_does_not_match_wrong_case(): void
    {
        // case-sensitive 'alice' must NOT match 'Alice'
        $ids = $this->ids($this->users()->whereLike('name', 'alice', caseSensitive: true));

        $this->assertSame([], $ids);
    }

    public function test_or_where_like_unions_matches(): void
    {
        // Alice OR Eve
        $ids = $this->ids($this->users()->whereLike('name', 'A%')->orWhereLike('name', 'E%'));

        $this->assertSame([1, 5], $ids);
    }

    // =========================================================================
    // whereColumn / orWhereColumn — compare two columns of the same record
    // =========================================================================

    public function test_where_column_equality_operator(): void
    {
        // Arrange: records where email starts with name (all emails match name prefix)
        // Use age == age as a trivially-true column equality check
        $ids = $this->ids($this->users()->whereColumn('age', 'age'));

        $this->assertSame([1, 2, 3, 4, 5], $ids);
    }

    public function test_where_column_less_than_operator_on_range_data(): void
    {
        // record where value < min: id=5 (value=45, min=50)
        $ids = $this->ids($this->ranges()->whereColumn('value', '<', 'min'));

        $this->assertSame([5], $ids);
    }

    public function test_where_column_greater_than_operator_on_range_data(): void
    {
        // record where value > max: id=4 (value=55, max=50)
        $ids = $this->ids($this->ranges()->whereColumn('value', '>', 'max'));

        $this->assertSame([4], $ids);
    }

    public function test_or_where_column_unions_out_of_range_records(): void
    {
        // value < min (id=5) OR value > max (id=4)
        $ids = $this->ids(
            $this->ranges()->whereColumn('value', '<', 'min')->orWhereColumn('value', '>', 'max')
        );

        $this->assertSame([4, 5], $ids);
    }

    // =========================================================================
    // whereNullSafeEquals / orWhereNullSafeEquals
    // =========================================================================

    public function test_where_null_safe_equals_matches_non_null_value(): void
    {
        $ids = $this->ids($this->users()->whereNullSafeEquals('country', 'JP'));

        $this->assertSame([1, 3], $ids);
    }

    public function test_where_null_safe_equals_matches_null_value(): void
    {
        // Arrange — only Alice and Carol have deleted_at = null
        $ids = $this->ids($this->users()->whereNullSafeEquals('deleted_at', null));

        $this->assertSame([1, 3, 4, 5], $ids);
    }

    public function test_or_where_null_safe_equals_unions_results(): void
    {
        // country=UK OR deleted_at<=>null → Eve + Alice,Carol,Dave
        $ids = $this->ids(
            $this->users()->whereNullSafeEquals('country', 'UK')->orWhereNullSafeEquals('deleted_at', null)
        );

        $this->assertSame([1, 3, 4, 5], $ids);
    }

    // =========================================================================
    // whereNot / orWhereNot
    // =========================================================================

    public function test_where_not_negates_condition(): void
    {
        // NOT country=JP → Bob, Dave, Eve
        $ids = $this->ids($this->users()->whereNot('country', 'JP'));

        $this->assertSame([2, 4, 5], $ids);
    }

    public function test_where_not_with_closure_negates_nested_group(): void
    {
        // NOT (country=JP AND age>25) → excludes Alice(JP,30) and Carol(JP,35)
        $ids = $this->ids(
            $this->users()->whereNot(function (ReferenceQueryBuilder $q) {
                $q->where('country', 'JP')->where('age', '>', 25);
            })
        );

        $this->assertSame([2, 4, 5], $ids);
    }

    public function test_or_where_not_ors_a_negated_condition(): void
    {
        // country=UK OR NOT country=JP → Eve + Bob,Dave,Eve → unique: Bob,Dave,Eve
        $ids = $this->ids(
            $this->users()->where('country', 'UK')->orWhereNot('country', 'JP')
        );

        $this->assertSame([2, 4, 5], $ids);
    }

    // =========================================================================
    // whereAll / whereAny / whereNone
    // =========================================================================

    public function test_where_all_requires_all_columns_to_match(): void
    {
        // Both name and country must equal 'JP' — no record has name='JP'
        $ids = $this->ids($this->users()->whereAll(['name', 'country'], 'JP'));

        $this->assertSame([], $ids);
    }

    public function test_where_any_matches_if_at_least_one_column_satisfies(): void
    {
        // name='Bob' OR email='alice@example.com' → Alice + Bob
        $ids = $this->ids(
            $this->users()->whereAny(['name', 'email'], '=', 'alice@example.com')
        );

        $this->assertSame([1], $ids);
    }

    public function test_where_any_with_like_operator(): void
    {
        // name LIKE '%ol%' OR email LIKE '%ol%' → Carol
        $ids = $this->ids($this->users()->whereAny(['name', 'email'], 'like', '%ol%'));

        $this->assertSame([3], $ids);
    }

    public function test_where_none_excludes_records_where_any_column_matches(): void
    {
        // NONE of name or email equal 'alice@example.com' → excludes Alice (her email matches)
        $ids = $this->ids($this->users()->whereNone(['name', 'email'], '=', 'alice@example.com'));

        $this->assertSame([2, 3, 4, 5], $ids);
    }

    // =========================================================================
    // whereExists / whereNotExists — record-level predicate
    // =========================================================================

    public function test_where_exists_filters_by_record_predicate(): void
    {
        // age divisible by 5: Alice(30), Bob(25), Carol(35), Dave(20) → ids 1,2,3,4
        $ids = $this->ids($this->users()->whereExists(fn (array $r) => $r['age'] % 5 === 0));

        $this->assertSame([1, 2, 3, 4], $ids);
    }

    public function test_where_not_exists_negates_predicate(): void
    {
        // NOT (age % 5 === 0): only Eve(28) → id 5
        $ids = $this->ids($this->users()->whereNotExists(fn (array $r) => $r['age'] % 5 === 0));

        $this->assertSame([5], $ids);
    }
}
