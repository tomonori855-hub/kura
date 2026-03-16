<?php

namespace Kura\Tests;

use Kura\CacheRepository;
use Kura\ReferenceQueryBuilder;
use Kura\Store\ArrayStore;
use Kura\Tests\Support\InMemoryLoader;
use PHPUnit\Framework\TestCase;

class ReferenceQueryBuilderAdvancedWhereTest extends TestCase
{
    private ArrayStore $store;

    /** @var list<array<string, mixed>> */
    private array $users = [
        ['id' => 1, 'name' => 'Alice', 'status' => 'active',   'country' => 'JP', 'role' => 'admin', 'deleted_at' => null],
        ['id' => 2, 'name' => 'Bob',   'status' => 'inactive', 'country' => 'US', 'role' => 'user',  'deleted_at' => '2024-01-01'],
        ['id' => 3, 'name' => 'Carol', 'status' => 'active',   'country' => 'JP', 'role' => 'user',  'deleted_at' => null],
        ['id' => 4, 'name' => 'Dave',  'status' => 'banned',   'country' => 'US', 'role' => 'admin', 'deleted_at' => null],
        ['id' => 5, 'name' => 'Eve',   'status' => 'active',   'country' => 'UK', 'role' => 'user',  'deleted_at' => '2024-06-01'],
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

    /**
     * @param  list<array<string, mixed>>  $results
     * @return list<int|string>
     */
    private function ids(array $results): array
    {
        return array_column($results, 'id');
    }

    // -------------------------------------------------------------------------
    // whereIn / whereNotIn
    // -------------------------------------------------------------------------

    public function test_where_in(): void
    {
        $results = $this->builder()->whereIn('status', ['active', 'inactive'])->get();

        $this->assertEqualsCanonicalizing(
            [1, 2, 3, 5],
            $this->ids($results),
            'whereIn should return records with status in [active, inactive]',
        );
    }

    public function test_where_not_in(): void
    {
        $results = $this->builder()->whereNotIn('status', ['active', 'inactive'])->get();

        $this->assertSame(
            [4],
            $this->ids($results),
            'whereNotIn should return only the banned record (Dave)',
        );
    }

    public function test_where_in_with_empty_values_returns_nothing(): void
    {
        $results = $this->builder()->whereIn('status', [])->get();

        $this->assertSame(
            [],
            $results,
            'whereIn with empty values should return no records',
        );
    }

    public function test_where_in_chained_with_where(): void
    {
        $results = $this->builder()
            ->where('country', 'JP')
            ->whereIn('status', ['active', 'banned'])
            ->get();

        $this->assertSame(
            [1, 3],
            $this->ids($results),
            'country=JP AND status IN [active, banned] should return Alice and Carol',
        );
    }

    // -------------------------------------------------------------------------
    // whereNull / whereNotNull
    // -------------------------------------------------------------------------

    public function test_where_null(): void
    {
        $results = $this->builder()->whereNull('deleted_at')->get();

        $this->assertEqualsCanonicalizing(
            [1, 3, 4],
            $this->ids($results),
            'whereNull should return records where deleted_at is null',
        );
    }

    public function test_where_not_null(): void
    {
        $results = $this->builder()->whereNotNull('deleted_at')->get();

        $this->assertEqualsCanonicalizing(
            [2, 5],
            $this->ids($results),
            'whereNotNull should return records where deleted_at is not null',
        );
    }

    public function test_where_null_chained_with_where(): void
    {
        $results = $this->builder()
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->get();

        $this->assertEqualsCanonicalizing(
            [1, 3],
            $this->ids($results),
            'status=active AND deleted_at IS NULL should return Alice and Carol',
        );
    }

    // -------------------------------------------------------------------------
    // orWhere
    // -------------------------------------------------------------------------

    public function test_or_where(): void
    {
        $results = $this->builder()
            ->where('status', 'inactive')
            ->orWhere('status', 'banned')
            ->get();

        $this->assertEqualsCanonicalizing(
            [2, 4],
            $this->ids($results),
            'status=inactive OR status=banned should return Bob and Dave',
        );
    }

    public function test_or_where_in(): void
    {
        $results = $this->builder()
            ->where('country', 'JP')
            ->orWhereIn('country', ['UK'])
            ->get();

        $this->assertEqualsCanonicalizing(
            [1, 3, 5],
            $this->ids($results),
            'country=JP OR country IN [UK] should return Alice, Carol, Eve',
        );
    }

    public function test_or_where_null(): void
    {
        $results = $this->builder()
            ->where('status', 'banned')
            ->orWhereNull('deleted_at')
            ->get();

        $this->assertEqualsCanonicalizing(
            [1, 3, 4],
            $this->ids($results),
            'status=banned OR deleted_at IS NULL should return Alice, Carol, Dave',
        );
    }

    public function test_or_where_not_null(): void
    {
        $results = $this->builder()
            ->where('status', 'banned')
            ->orWhereNotNull('deleted_at')
            ->get();

        $this->assertEqualsCanonicalizing(
            [2, 4, 5],
            $this->ids($results),
            'status=banned OR deleted_at IS NOT NULL should return Bob, Dave, Eve',
        );
    }

    // -------------------------------------------------------------------------
    // Closure grouping
    // -------------------------------------------------------------------------

    public function test_where_closure_groups_conditions_with_and(): void
    {
        $results = $this->builder()
            ->where(function (ReferenceQueryBuilder $q) {
                $q->where('country', 'JP')->where('status', 'active');
            })
            ->get();

        $this->assertEqualsCanonicalizing(
            [1, 3],
            $this->ids($results),
            'Nested (country=JP AND status=active) should return Alice and Carol',
        );
    }

    public function test_or_where_closure(): void
    {
        $results = $this->builder()
            ->where('role', 'admin')
            ->orWhere(function (ReferenceQueryBuilder $q) {
                $q->where('country', 'JP')->where('status', 'active');
            })
            ->get();

        $this->assertEqualsCanonicalizing(
            [1, 3, 4],
            $this->ids($results),
            'role=admin OR (country=JP AND status=active) should return Alice, Carol, Dave',
        );
    }

    public function test_where_closure_with_or_inside(): void
    {
        $results = $this->builder()
            ->where('country', 'UK')
            ->orWhere(function (ReferenceQueryBuilder $q) {
                $q->where('status', 'inactive')->orWhere('role', 'admin');
            })
            ->get();

        $this->assertEqualsCanonicalizing(
            [1, 2, 4, 5],
            $this->ids($results),
            'country=UK OR (status=inactive OR role=admin) should return Alice, Bob, Dave, Eve',
        );
    }

    public function test_complex_nested_conditions(): void
    {
        $results = $this->builder()
            ->where(function (ReferenceQueryBuilder $q) {
                $q->where('country', 'JP')->where('status', 'active');
            })
            ->orWhere(function (ReferenceQueryBuilder $q) {
                $q->where('role', 'admin')->whereNull('deleted_at');
            })
            ->get();

        $this->assertEqualsCanonicalizing(
            [1, 3, 4],
            $this->ids($results),
            '(JP AND active) OR (admin AND deleted_at IS NULL) should return Alice, Carol, Dave',
        );
    }
}
