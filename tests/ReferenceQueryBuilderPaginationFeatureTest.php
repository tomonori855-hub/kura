<?php

namespace Kura\Tests;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Kura\CacheRepository;
use Kura\ReferenceQueryBuilder;
use Kura\Store\ArrayStore;
use Kura\Tests\Support\InMemoryLoader;
use PHPUnit\Framework\TestCase;

/**
 * Feature tests (BDD / Gherkin-style) for pagination.
 *
 * Covers: paginate(), simplePaginate().
 *
 * Each test describes a concrete scenario using Given/When/Then phrasing so
 * that the intent is clear without reading the assertion code.
 */
class ReferenceQueryBuilderPaginationFeatureTest extends TestCase
{
    private ArrayStore $store;

    /** @var list<array<string, mixed>> */
    private array $users = [
        ['id' => 1,  'name' => 'Alice',   'country' => 'JP'],
        ['id' => 2,  'name' => 'Bob',     'country' => 'US'],
        ['id' => 3,  'name' => 'Carol',   'country' => 'JP'],
        ['id' => 4,  'name' => 'Dave',    'country' => 'US'],
        ['id' => 5,  'name' => 'Eve',     'country' => 'UK'],
        ['id' => 6,  'name' => 'Frank',   'country' => 'JP'],
        ['id' => 7,  'name' => 'Grace',   'country' => 'US'],
        ['id' => 8,  'name' => 'Heidi',   'country' => 'JP'],
        ['id' => 9,  'name' => 'Ivan',    'country' => 'UK'],
        ['id' => 10, 'name' => 'Judy',    'country' => 'US'],
    ];

    protected function setUp(): void
    {
        $this->store = new ArrayStore;

        // Provide stable defaults so tests run outside a full Laravel context.
        Paginator::currentPageResolver(fn () => 1);
        Paginator::currentPathResolver(fn () => '/');
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
    // paginate() — LengthAwarePaginator (total count known)
    // =========================================================================

    /**
     * Scenario: Paginating all records on the first page.
     *
     * Given 10 users
     * When  I call paginate(3, page: 1)
     * Then  I receive the first 3 users, total is 10, and there are more pages.
     */
    public function test_paginate_returns_first_page_with_correct_meta(): void
    {
        $paginator = $this->builder()->orderBy('id')->paginate(3, page: 1);

        $this->assertInstanceOf(LengthAwarePaginator::class, $paginator);
        $this->assertSame(10, $paginator->total());
        $this->assertSame(3, $paginator->perPage());
        $this->assertSame(1, $paginator->currentPage());
        $this->assertTrue($paginator->hasMorePages());
        $this->assertCount(3, $paginator->items());
        $this->assertSame(1, $paginator->items()[0]['id']);
    }

    /**
     * Scenario: Requesting the last page when total does not divide evenly.
     *
     * Given 10 users
     * When  I call paginate(3, page: 4) — 4th page of 3 has 1 record
     * Then  I receive 1 user and there are no more pages.
     */
    public function test_paginate_last_page_has_remaining_records(): void
    {
        $paginator = $this->builder()->orderBy('id')->paginate(3, page: 4);

        $this->assertSame(4, $paginator->currentPage());
        $this->assertCount(1, $paginator->items());
        $this->assertSame(10, $paginator->items()[0]['id']);
        $this->assertFalse($paginator->hasMorePages());
    }

    /**
     * Scenario: Paginating a filtered result set.
     *
     * Given 4 JP users (Alice, Carol, Frank, Heidi)
     * When  I paginate with 2 per page, page 2
     * Then  I get the 3rd and 4th JP user, total is 4.
     */
    public function test_paginate_respects_where_conditions(): void
    {
        $paginator = $this->builder()
            ->where('country', 'JP')
            ->orderBy('id')
            ->paginate(2, page: 2);

        $this->assertSame(4, $paginator->total());
        $this->assertCount(2, $paginator->items());
        $this->assertSame(6, $paginator->items()[0]['id']); // Frank
        $this->assertSame(8, $paginator->items()[1]['id']); // Heidi
    }

    /**
     * Scenario: Paginating an empty result set.
     *
     * Given 0 users matching country=AU
     * When  I paginate
     * Then  total is 0, items are empty, no more pages.
     */
    public function test_paginate_on_empty_result_set(): void
    {
        $paginator = $this->builder()->where('country', 'AU')->paginate(5, page: 1);

        $this->assertSame(0, $paginator->total());
        $this->assertCount(0, $paginator->items());
        $this->assertFalse($paginator->hasMorePages());
    }

    /**
     * Scenario: Custom page name for query-string key.
     *
     * Given 10 users
     * When  I paginate using pageName='p' and explicit page 2
     * Then  the paginator url contains 'p=' parameter.
     */
    public function test_paginate_uses_custom_page_name(): void
    {
        $paginator = $this->builder()->orderBy('id')->paginate(3, pageName: 'p', page: 2);

        $this->assertStringContainsString('p=', $paginator->url(2));
    }

    // =========================================================================
    // simplePaginate() — Paginator (no total count)
    // =========================================================================

    /**
     * Scenario: Simple pagination on the first page.
     *
     * Given 10 users
     * When  I call simplePaginate(3, page: 1)
     * Then  I receive 3 items and there are more pages.
     */
    public function test_simple_paginate_returns_items_with_has_more_pages(): void
    {
        $paginator = $this->builder()->orderBy('id')->simplePaginate(3, page: 1);

        $this->assertInstanceOf(Paginator::class, $paginator);
        $this->assertSame(3, $paginator->perPage());
        $this->assertCount(3, $paginator->items());
        $this->assertTrue($paginator->hasMorePages());
        $this->assertSame(1, $paginator->items()[0]['id']);
    }

    /**
     * Scenario: Simple pagination on the last page.
     *
     * Given 10 users
     * When  I call simplePaginate(3, page: 4)
     * Then  I receive 1 item and there are no more pages.
     */
    public function test_simple_paginate_on_last_page_has_no_more_pages(): void
    {
        $paginator = $this->builder()->orderBy('id')->simplePaginate(3, page: 4);

        $this->assertCount(1, $paginator->items());
        $this->assertFalse($paginator->hasMorePages());
        $this->assertSame(10, $paginator->items()[0]['id']);
    }

    /**
     * Scenario: Simple pagination with an exact multiple of perPage.
     *
     * Given 10 users
     * When  I call simplePaginate(5, page: 2)
     * Then  I get 5 items and no more pages (exactly fills the page).
     */
    public function test_simple_paginate_exact_page_boundary(): void
    {
        $paginator = $this->builder()->orderBy('id')->simplePaginate(5, page: 2);

        $this->assertCount(5, $paginator->items());
        $this->assertFalse($paginator->hasMorePages());
        $this->assertSame(6, $paginator->items()[0]['id']);
    }

    /**
     * Scenario: Simple pagination on an empty result set.
     *
     * Given 0 users matching country=AU
     * When  I call simplePaginate
     * Then  items are empty and there are no more pages.
     */
    public function test_simple_paginate_on_empty_result_set(): void
    {
        $paginator = $this->builder()->where('country', 'AU')->simplePaginate(5, page: 1);

        $this->assertCount(0, $paginator->items());
        $this->assertFalse($paginator->hasMorePages());
    }
}
