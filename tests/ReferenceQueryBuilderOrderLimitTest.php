<?php

namespace Kura\Tests;

use Kura\CacheRepository;
use Kura\ReferenceQueryBuilder;
use Kura\Store\ArrayStore;
use Kura\Tests\Support\InMemoryLoader;
use PHPUnit\Framework\TestCase;

class ReferenceQueryBuilderOrderLimitTest extends TestCase
{
    private ArrayStore $store;

    /** @var list<array<string, mixed>> */
    private array $users = [
        ['id' => 1, 'name' => 'Alice', 'age' => 30, 'status' => 'active'],
        ['id' => 2, 'name' => 'Bob',   'age' => 25, 'status' => 'active'],
        ['id' => 3, 'name' => 'Carol', 'age' => 35, 'status' => 'active'],
        ['id' => 4, 'name' => 'Dave',  'age' => 25, 'status' => 'inactive'],
        ['id' => 5, 'name' => 'Eve',   'age' => 28, 'status' => 'active'],
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

    // -------------------------------------------------------------------------
    // orderBy
    // -------------------------------------------------------------------------

    public function test_order_by_asc(): void
    {
        $results = $this->builder()->orderBy('age')->get();

        $ages = array_column($results, 'age');
        $this->assertSame([25, 25, 28, 30, 35], $ages, 'orderBy age asc should sort ages ascending');
    }

    public function test_order_by_desc(): void
    {
        $results = $this->builder()->orderBy('age', 'desc')->get();

        $ages = array_column($results, 'age');
        $this->assertSame([35, 30, 28, 25, 25], $ages, 'orderBy age desc should sort ages descending');
    }

    public function test_order_by_desc_shorthand(): void
    {
        $results = $this->builder()->orderByDesc('age')->get();

        $ages = array_column($results, 'age');
        $this->assertSame([35, 30, 28, 25, 25], $ages, 'orderByDesc should sort ages descending');
    }

    public function test_order_by_string_column(): void
    {
        $results = $this->builder()->orderBy('name')->get();

        $names = array_column($results, 'name');
        $this->assertSame(
            ['Alice', 'Bob', 'Carol', 'Dave', 'Eve'],
            $names,
            'orderBy name should sort names alphabetically',
        );
    }

    public function test_order_by_multiple_columns(): void
    {
        $results = $this->builder()
            ->orderBy('age')
            ->orderBy('name')
            ->get();

        $this->assertSame('Bob', $results[0]['name'], 'First age=25 record should be Bob (alphabetically)');
        $this->assertSame('Dave', $results[1]['name'], 'Second age=25 record should be Dave (alphabetically)');
    }

    // -------------------------------------------------------------------------
    // limit / take
    // -------------------------------------------------------------------------

    public function test_limit_restricts_result_count(): void
    {
        $results = $this->builder()->limit(2)->get();

        $this->assertCount(2, $results, 'limit(2) should return exactly 2 records');
    }

    public function test_take_is_alias_for_limit(): void
    {
        $results = $this->builder()->take(3)->get();

        $this->assertCount(3, $results, 'take(3) should return exactly 3 records');
    }

    // -------------------------------------------------------------------------
    // offset / skip
    // -------------------------------------------------------------------------

    public function test_offset_skips_records(): void
    {
        $all = $this->builder()->get();
        $offset = $this->builder()->offset(2)->get();

        $this->assertSame(
            array_slice($all, 2),
            $offset,
            'offset(2) should skip the first 2 records',
        );
    }

    public function test_skip_is_alias_for_offset(): void
    {
        $all = $this->builder()->get();
        $skipped = $this->builder()->skip(1)->get();

        $this->assertSame(
            array_slice($all, 1),
            $skipped,
            'skip(1) should skip the first record',
        );
    }

    // -------------------------------------------------------------------------
    // limit + offset combined
    // -------------------------------------------------------------------------

    public function test_limit_and_offset_combined(): void
    {
        $results = $this->builder()->offset(1)->limit(2)->get();

        $this->assertCount(2, $results, 'offset(1) + limit(2) should return 2 records');
        $this->assertSame(2, $results[0]['id'], 'First record should be id=2 (offset 1)');
        $this->assertSame(3, $results[1]['id'], 'Second record should be id=3');
    }

    // -------------------------------------------------------------------------
    // where + orderBy + limit combined
    // -------------------------------------------------------------------------

    public function test_where_with_order_and_limit(): void
    {
        $results = $this->builder()
            ->where('status', 'active')
            ->orderBy('age')
            ->limit(2)
            ->get();

        $this->assertCount(2, $results, 'where + orderBy + limit should return 2 records');
        $this->assertSame(25, $results[0]['age'], 'First active user by age should be Bob (age=25)');
        $this->assertSame(28, $results[1]['age'], 'Second active user by age should be Eve (age=28)');
    }
}
