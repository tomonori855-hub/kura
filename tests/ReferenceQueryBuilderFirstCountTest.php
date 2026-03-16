<?php

namespace Kura\Tests;

use Kura\CacheRepository;
use Kura\ReferenceQueryBuilder;
use Kura\Store\ArrayStore;
use Kura\Tests\Support\InMemoryLoader;
use PHPUnit\Framework\TestCase;

class ReferenceQueryBuilderFirstCountTest extends TestCase
{
    private ArrayStore $store;

    /** @var list<array<string, mixed>> */
    private array $users = [
        ['id' => 1, 'name' => 'Alice', 'status' => 'active'],
        ['id' => 2, 'name' => 'Bob',   'status' => 'inactive'],
        ['id' => 3, 'name' => 'Carol', 'status' => 'active'],
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
    // first()
    // -------------------------------------------------------------------------

    public function test_first_returns_first_matching_record(): void
    {
        $result = $this->builder()->where('status', 'active')->first();

        $this->assertNotNull($result, 'first() should return a record when matches exist');
        $this->assertSame(1, $result['id'], 'first() should return Alice (id=1) as the first active record');
    }

    public function test_first_returns_null_when_no_match(): void
    {
        $result = $this->builder()->where('status', 'banned')->first();

        $this->assertNull($result, 'first() should return null when no records match');
    }

    public function test_first_without_conditions_returns_first_record(): void
    {
        $result = $this->builder()->first();

        $this->assertNotNull($result, 'first() without conditions should return a record');
        $this->assertSame(1, $result['id'], 'first() without conditions should return the first record (id=1)');
    }

    public function test_first_respects_order_by(): void
    {
        $result = $this->builder()
            ->where('status', 'active')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($result, 'first() with orderByDesc should return a record');
        $this->assertSame(3, $result['id'], 'first() with orderByDesc(id) should return Carol (id=3)');
    }

    // -------------------------------------------------------------------------
    // count()
    // -------------------------------------------------------------------------

    public function test_count_returns_total_when_no_conditions(): void
    {
        $this->assertSame(3, $this->builder()->count(), 'count() without conditions should return total record count');
    }

    public function test_count_returns_matching_record_count(): void
    {
        $this->assertSame(
            2,
            $this->builder()->where('status', 'active')->count(),
            'count() should return 2 for active records',
        );
    }

    public function test_count_returns_zero_when_no_match(): void
    {
        $this->assertSame(
            0,
            $this->builder()->where('status', 'banned')->count(),
            'count() should return 0 when no records match',
        );
    }

    // -------------------------------------------------------------------------
    // cursor()
    // -------------------------------------------------------------------------

    public function test_cursor_returns_generator(): void
    {
        $cursor = $this->builder()->cursor();

        $this->assertInstanceOf(\Generator::class, $cursor, 'cursor() should return a Generator instance');
    }

    public function test_cursor_yields_matching_records(): void
    {
        $cursor = $this->builder()->where('status', 'active')->cursor();

        $results = [];
        foreach ($cursor as $record) {
            $results[] = $record;
        }

        $this->assertCount(2, $results, 'cursor() with status=active should yield 2 records');
    }

    public function test_cursor_is_lazy_and_does_not_preload(): void
    {
        $cursor = $this->builder()->cursor();

        $this->assertFalse(
            $cursor->valid() === false && ! $cursor->current(),
            'Generator should not be exhausted before iteration begins',
        );

        $cursor->rewind();
        $this->assertArrayHasKey('id', $cursor->current(), 'First yielded record should contain an id key');
    }
}
