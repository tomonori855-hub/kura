<?php

namespace Kura\Tests;

use Kura\CacheRepository;
use Kura\ReferenceQueryBuilder;
use Kura\Store\ArrayStore;
use Kura\Tests\Support\InMemoryLoader;
use PHPUnit\Framework\TestCase;

class ReferenceQueryBuilderWhereTest extends TestCase
{
    private ArrayStore $store;

    /** @var list<array<string, mixed>> */
    private array $users = [
        ['id' => 1, 'name' => 'Alice', 'status' => 'active',   'age' => 30, 'role' => 'admin'],
        ['id' => 2, 'name' => 'Bob',   'status' => 'inactive', 'age' => 25, 'role' => 'user'],
        ['id' => 3, 'name' => 'Carol', 'status' => 'active',   'age' => 35, 'role' => 'user'],
        ['id' => 4, 'name' => 'Dave',  'status' => 'active',   'age' => 25, 'role' => 'admin'],
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
    // get() without conditions
    // -------------------------------------------------------------------------

    public function test_get_returns_all_records_without_conditions(): void
    {
        $results = $this->builder()->get();

        $this->assertCount(4, $results, 'get() without conditions should return all 4 records');
    }

    // -------------------------------------------------------------------------
    // where with = (shorthand)
    // -------------------------------------------------------------------------

    public function test_where_filters_by_equality(): void
    {
        $results = $this->builder()->where('status', 'active')->get();

        $this->assertCount(3, $results, 'where status=active should return 3 records');
        foreach ($results as $row) {
            $this->assertSame('active', $row['status'], 'Each returned record should have status=active');
        }
    }

    public function test_where_with_explicit_equals_operator(): void
    {
        $results = $this->builder()->where('status', '=', 'active')->get();

        $this->assertCount(3, $results, 'where status = active with explicit operator should return 3 records');
    }

    public function test_where_returns_empty_when_no_match(): void
    {
        $results = $this->builder()->where('status', 'banned')->get();

        $this->assertSame([], $results, 'where with non-existent value should return empty array');
    }

    // -------------------------------------------------------------------------
    // Multiple where (AND chaining)
    // -------------------------------------------------------------------------

    public function test_chained_where_acts_as_and(): void
    {
        $results = $this->builder()
            ->where('status', 'active')
            ->where('role', 'admin')
            ->get();

        $this->assertCount(2, $results, 'AND chain of status=active AND role=admin should return 2 records');
        foreach ($results as $row) {
            $this->assertSame('active', $row['status'], 'Each record should have status=active');
            $this->assertSame('admin', $row['role'], 'Each record should have role=admin');
        }
    }

    // -------------------------------------------------------------------------
    // Comparison operators
    // -------------------------------------------------------------------------

    public function test_where_with_greater_than(): void
    {
        $results = $this->builder()->where('age', '>', 25)->get();

        $this->assertCount(2, $results, 'age > 25 should return 2 records (Alice=30, Carol=35)');
        foreach ($results as $row) {
            $this->assertGreaterThan(25, $row['age'], 'Each returned record age should be > 25');
        }
    }

    public function test_where_with_greater_than_or_equal(): void
    {
        $results = $this->builder()->where('age', '>=', 30)->get();

        $this->assertCount(2, $results, 'age >= 30 should return 2 records (Alice=30, Carol=35)');
    }

    public function test_where_with_less_than(): void
    {
        $results = $this->builder()->where('age', '<', 30)->get();

        $this->assertCount(2, $results, 'age < 30 should return 2 records (Bob=25, Dave=25)');
        foreach ($results as $row) {
            $this->assertLessThan(30, $row['age'], 'Each returned record age should be < 30');
        }
    }

    public function test_where_with_less_than_or_equal(): void
    {
        $results = $this->builder()->where('age', '<=', 25)->get();

        $this->assertCount(2, $results, 'age <= 25 should return 2 records (Bob=25, Dave=25)');
    }

    public function test_where_with_not_equal(): void
    {
        $results = $this->builder()->where('status', '!=', 'active')->get();

        $this->assertCount(1, $results, 'status != active should return 1 record (Bob)');
        $this->assertSame('Bob', $results[0]['name'], 'The non-active record should be Bob');
    }

    public function test_where_with_not_equal_diamond_operator(): void
    {
        $results = $this->builder()->where('status', '<>', 'active')->get();

        $this->assertCount(1, $results, 'status <> active should return 1 record');
    }

    // -------------------------------------------------------------------------
    // LIKE operator
    // -------------------------------------------------------------------------

    public function test_where_like_with_prefix_wildcard(): void
    {
        $results = $this->builder()->where('name', 'like', '%ol')->get();

        $this->assertCount(1, $results, 'LIKE %ol should match only Carol');
        $this->assertSame('Carol', $results[0]['name'], 'LIKE %ol should match Carol');
    }

    public function test_where_like_with_suffix_wildcard(): void
    {
        $results = $this->builder()->where('name', 'like', 'A%')->get();

        $this->assertCount(1, $results, 'LIKE A% should match only Alice');
        $this->assertSame('Alice', $results[0]['name'], 'LIKE A% should match Alice');
    }

    public function test_where_like_with_both_wildcards(): void
    {
        $results = $this->builder()->where('name', 'like', '%li%')->get();

        $this->assertCount(1, $results, 'LIKE %li% should match only Alice');
        $this->assertSame('Alice', $results[0]['name'], 'LIKE %li% should match Alice');
    }

    public function test_where_not_like(): void
    {
        $results = $this->builder()->where('name', 'not like', 'A%')->get();

        $this->assertCount(3, $results, 'NOT LIKE A% should exclude Alice, returning 3 records');
        foreach ($results as $row) {
            $this->assertStringStartsNotWith('A', $row['name'], 'No returned record name should start with A');
        }
    }

    // -------------------------------------------------------------------------
    // Unsupported operator
    // -------------------------------------------------------------------------

    public function test_unsupported_operator_throws_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->builder()->where('age', 'between', [20, 30])->get();
    }
}
