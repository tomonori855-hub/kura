<?php

namespace Kura\Tests;

use Kura\CacheRepository;
use Kura\ReferenceQueryBuilder;
use Kura\Store\ArrayStore;
use Kura\Tests\Support\InMemoryLoader;
use PHPUnit\Framework\TestCase;

/**
 * Index-based query optimization tests.
 *
 * In Phase 2-3, index optimization is disabled (always full scan).
 * These tests verify that queries still return correct results via full scan.
 * Index optimization will be re-enabled in Phase 4.
 */
class ReferenceQueryBuilderIndexTest extends TestCase
{
    private ArrayStore $store;

    /** @var list<array<string, mixed>> */
    private array $users = [
        ['id' => 1, 'email' => 'alice@example.com', 'status' => 'active',   'country' => 'JP'],
        ['id' => 2, 'email' => 'bob@example.com',   'status' => 'inactive', 'country' => 'US'],
        ['id' => 3, 'email' => 'carol@example.com', 'status' => 'active',   'country' => 'JP'],
        ['id' => 4, 'email' => 'dave@example.com',  'status' => 'active',   'country' => 'US'],
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
    // Exact match queries (previously used unique index)
    // -------------------------------------------------------------------------

    public function test_where_exact_match_on_email(): void
    {
        $results = $this->builder()
            ->where('email', 'alice@example.com')
            ->get();

        $this->assertCount(
            1,
            $results,
            'Exact match on email should return exactly one record',
        );
        $this->assertSame(
            1,
            $results[0]['id'],
            'Exact match on alice@example.com should return id=1',
        );
    }

    public function test_exact_match_returns_empty_for_unknown_value(): void
    {
        $results = $this->builder()
            ->where('email', 'nobody@example.com')
            ->get();

        $this->assertSame(
            [],
            $results,
            'Exact match on non-existent email should return empty array',
        );
    }

    // -------------------------------------------------------------------------
    // Non-unique filter queries (previously used non-unique index)
    // -------------------------------------------------------------------------

    public function test_where_filters_by_status(): void
    {
        $results = $this->builder()
            ->where('status', 'active')
            ->get();

        $this->assertCount(
            3,
            $results,
            'Filtering by status=active should return 3 records',
        );
        foreach ($results as $row) {
            $this->assertSame(
                'active',
                $row['status'],
                'All returned records should have status=active',
            );
        }
    }

    // -------------------------------------------------------------------------
    // Multi-column filter queries (previously used composite index)
    // -------------------------------------------------------------------------

    public function test_where_with_multiple_columns(): void
    {
        $results = $this->builder()
            ->where('country', 'JP')
            ->where('status', 'active')
            ->get();

        $this->assertCount(
            2,
            $results,
            'Filtering by country=JP AND status=active should return 2 records',
        );
        foreach ($results as $row) {
            $this->assertSame('JP', $row['country'], 'All records should have country=JP');
            $this->assertSame('active', $row['status'], 'All records should have status=active');
        }
    }

    public function test_where_with_single_column_filter(): void
    {
        $results = $this->builder()
            ->where('country', 'US')
            ->get();

        $this->assertCount(
            2,
            $results,
            'Filtering by country=US should return 2 records',
        );
        foreach ($results as $row) {
            $this->assertSame('US', $row['country'], 'All records should have country=US');
        }
    }

    // -------------------------------------------------------------------------
    // Full scan (no index optimization)
    // -------------------------------------------------------------------------

    public function test_full_scan_returns_correct_results(): void
    {
        $results = $this->builder()
            ->where('country', 'JP')
            ->get();

        $this->assertCount(
            2,
            $results,
            'Full scan for country=JP should return 2 records',
        );
        foreach ($results as $row) {
            $this->assertSame('JP', $row['country'], 'All records should have country=JP');
        }
    }
}
