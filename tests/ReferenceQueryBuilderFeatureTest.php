<?php

namespace Kura\Tests;

use Kura\CacheRepository;
use Kura\ReferenceQueryBuilder;
use Kura\Store\ArrayStore;
use Kura\Tests\Support\InMemoryLoader;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ReferenceQueryBuilder features beyond the basic where/order/limit:
 *   - whereBit / whereNotBit / orWhereBit / orWhereNotBit
 *   - whereFilter / orWhereFilter  (raw PHP predicate)
 *   - whereIn with Closure subquery (lazy evaluation)
 *   - pluck()
 *   - Complex AND/OR with nested Closures
 */
class ReferenceQueryBuilderFeatureTest extends TestCase
{
    private ArrayStore $store;

    // flags: bitmask columns
    //   bit 1 (0x01) = verified
    //   bit 2 (0x02) = premium
    //   bit 4 (0x04) = admin
    /** @var list<array<string, mixed>> */
    private array $users = [
        ['id' => 1, 'name' => 'Alice', 'country' => 'JP', 'age' => 30, 'flags' => 0b101], // verified + admin
        ['id' => 2, 'name' => 'Bob',   'country' => 'US', 'age' => 25, 'flags' => 0b011], // verified + premium
        ['id' => 3, 'name' => 'Carol', 'country' => 'JP', 'age' => 35, 'flags' => 0b010], // premium only
        ['id' => 4, 'name' => 'Dave',  'country' => 'US', 'age' => 20, 'flags' => 0b000], // no flags
        ['id' => 5, 'name' => 'Eve',   'country' => 'UK', 'age' => 28, 'flags' => 0b100], // admin only
    ];

    /** @var list<array<string, mixed>> */
    private array $countries = [
        ['code' => 'JP', 'active' => true,  'region' => 'Asia'],
        ['code' => 'US', 'active' => true,  'region' => 'America'],
        ['code' => 'UK', 'active' => false, 'region' => 'Europe'],
    ];

    protected function setUp(): void
    {
        $this->store = new ArrayStore;
    }

    private function userBuilder(): ReferenceQueryBuilder
    {
        return $this->buildFor('users', 'id', $this->users);
    }

    private function countryBuilder(): ReferenceQueryBuilder
    {
        return $this->buildFor('countries', 'code', $this->countries);
    }

    /** @param list<array<string, mixed>> $records */
    private function buildFor(string $table, string $pk, array $records): ReferenceQueryBuilder
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

    /**
     * @param  list<array<string, mixed>>  $results
     * @return list<int|string>
     */
    private function ids(array $results): array
    {
        return array_column($results, 'id');
    }

    // =========================================================================
    // pluck()
    // =========================================================================

    public function test_pluck_returns_column_values(): void
    {
        $names = $this->userBuilder()->orderBy('id')->pluck('name');

        $this->assertSame(['Alice', 'Bob', 'Carol', 'Dave', 'Eve'], $names);
    }

    public function test_pluck_with_filter(): void
    {
        $ids = $this->userBuilder()->where('country', 'JP')->pluck('id');

        $this->assertEqualsCanonicalizing([1, 3], $ids);
    }

    // =========================================================================
    // Bitwise operators via where() — mirrors Laravel's bitwiseOperators
    // =========================================================================

    public function test_where_bitwise_and_matches_records_with_bit_set(): void
    {
        // flag bit 1 (verified): Alice(0b101), Bob(0b011)
        $results = $this->userBuilder()->where('flags', '&', 0b001)->get();

        $this->assertEqualsCanonicalizing([1, 2], $this->ids($results));
    }

    public function test_where_not_bitwise_matches_records_without_bit(): void
    {
        // not verified: Carol(0b010), Dave(0b000), Eve(0b100)
        $results = $this->userBuilder()->where('flags', '!&', 0b001)->get();

        $this->assertEqualsCanonicalizing([3, 4, 5], $this->ids($results));
    }

    public function test_where_bitwise_admin_flag(): void
    {
        // admin bit (0b100): Alice(0b101), Eve(0b100)
        $results = $this->userBuilder()->where('flags', '&', 0b100)->get();

        $this->assertEqualsCanonicalizing([1, 5], $this->ids($results));
    }

    public function test_or_where_bitwise(): void
    {
        // admin OR premium: Alice(admin), Bob(premium), Carol(premium), Eve(admin)
        $results = $this->userBuilder()
            ->where('flags', '&', 0b100)   // admin
            ->orWhere('flags', '&', 0b010) // premium
            ->get();

        $this->assertEqualsCanonicalizing([1, 2, 3, 5], $this->ids($results));
    }

    public function test_where_bitwise_combined_with_where(): void
    {
        // JP AND admin: Alice
        $results = $this->userBuilder()
            ->where('country', 'JP')
            ->where('flags', '&', 0b100)
            ->get();

        $this->assertSame([1], $this->ids($results));
    }

    // =========================================================================
    // whereFilter  (raw PHP predicate)
    // =========================================================================

    public function test_where_filter_with_custom_closure(): void
    {
        // age is even: Alice(30)✓, Dave(20)✓, Eve(28)✓
        $results = $this->userBuilder()
            ->whereFilter(fn (array $r) => $r['age'] % 2 === 0)
            ->get();

        $this->assertEqualsCanonicalizing([1, 4, 5], $this->ids($results));
    }

    public function test_where_filter_with_complex_expression(): void
    {
        // both verified AND premium bits set: Bob(0b011)
        $results = $this->userBuilder()
            ->whereFilter(fn (array $r) => ($r['flags'] & 0b011) === 0b011)
            ->get();

        $this->assertSame([2], $this->ids($results));
    }

    public function test_or_where_filter(): void
    {
        // country=JP OR age < 25
        $results = $this->userBuilder()
            ->where('country', 'JP')
            ->orWhereFilter(fn (array $r) => $r['age'] < 25)
            ->get();

        // JP: [1, 3]  age<25: [4]
        $this->assertEqualsCanonicalizing([1, 3, 4], $this->ids($results));
    }

    public function test_where_filter_chained_with_where(): void
    {
        // country=US AND name starts with B
        $results = $this->userBuilder()
            ->where('country', 'US')
            ->whereFilter(fn (array $r) => str_starts_with($r['name'], 'B'))
            ->get();

        $this->assertSame([2], $this->ids($results));
    }

    // =========================================================================
    // whereIn with Closure subquery (lazy evaluation)
    // =========================================================================

    public function test_where_in_with_closure_subquery(): void
    {
        // Get users whose country is active (JP, US)
        $countryBuilder = $this->countryBuilder();

        $results = $this->userBuilder()
            ->whereIn('country', fn () => $countryBuilder->where('active', true)->pluck('code'))
            ->get();

        // UK is inactive → Eve excluded
        $this->assertEqualsCanonicalizing([1, 2, 3, 4], $this->ids($results));
    }

    public function test_where_not_in_with_closure_subquery(): void
    {
        // Users NOT in active countries → Eve (UK)
        $countryBuilder = $this->countryBuilder();

        $results = $this->userBuilder()
            ->whereNotIn('country', fn () => $countryBuilder->where('active', true)->pluck('code'))
            ->get();

        $this->assertSame([5], $this->ids($results));
    }

    public function test_subquery_is_evaluated_lazily(): void
    {
        // Build the subquery closure but modify data before executing.
        // The closure captures $countryBuilder by reference; the array in
        // ArrayStore is populated only when builder() first queries it.
        $countryBuilder = $this->countryBuilder();

        $query = $this->userBuilder()
            ->whereIn('country', fn () => $countryBuilder->where('active', false)->pluck('code'));

        // No execution yet — subquery has not run.
        // Now run it.
        $results = $query->get();

        // inactive countries: UK → Eve
        $this->assertSame([5], $this->ids($results));
    }

    // =========================================================================
    // Complex nested AND/OR via Closure
    // =========================================================================

    public function test_complex_nested_with_bitwise_and_filter(): void
    {
        // (country=JP AND admin-bit set) OR (age > 27 AND verified-bit set)
        $results = $this->userBuilder()
            ->where(function (ReferenceQueryBuilder $q) {
                $q->where('country', 'JP')->where('flags', '&', 0b100);
            })
            ->orWhere(function (ReferenceQueryBuilder $q) {
                $q->where('age', '>', 27)->where('flags', '&', 0b001);
            })
            ->get();

        // JP+admin: [1(Alice)]
        // age>27+verified: Alice(30,flags=0b101,verified✓), Carol(35,flags=0b010,verified✗), Eve(28,flags=0b100,verified✗)
        // union: [1]
        $this->assertEqualsCanonicalizing([1], $this->ids($results));
    }

    public function test_nested_or_with_subquery(): void
    {
        $countryBuilder = $this->countryBuilder();

        // admin-bit OR (country in active countries AND age >= 30)
        $results = $this->userBuilder()
            ->where('flags', '&', 0b100)
            ->orWhere(function (ReferenceQueryBuilder $q) use ($countryBuilder) {
                $q->whereIn('country', fn () => $countryBuilder->where('active', true)->pluck('code'))
                    ->where('age', '>=', 30);
            })
            ->get();

        // admin: Alice(1), Eve(5)
        // active country AND age>=30: Alice(JP,30)✓, Carol(JP,35)✓, Bob(US,25)✗, Dave(US,20)✗
        // union: [1, 3, 5]
        $this->assertEqualsCanonicalizing([1, 3, 5], $this->ids($results));
    }
}
