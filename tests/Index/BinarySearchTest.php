<?php

namespace Kura\Tests\Index;

use Kura\Index\BinarySearch;
use PHPUnit\Framework\TestCase;

/**
 * Feature: Binary search on sorted index entries [[value, [ids]], ...]
 *
 * Given a sorted list of [value, [ids]] pairs,
 * When searching with various operators (=, >, >=, <, <=, BETWEEN),
 * Then the correct IDs should be returned.
 */
class BinarySearchTest extends TestCase
{
    /**
     * Sorted index entries for testing.
     * Structure: [[value, [ids]], ...] sorted by value ascending.
     *
     * @return list<array{int, list<int>}>
     */
    private static function entries(): array
    {
        return [
            [100, [3, 7]],
            [200, [1, 12]],
            [300, [5]],
            [500, [6, 9, 15]],
            [700, [8, 14]],
            [1000, [4, 11]],
        ];
    }

    // =========================================================================
    // Equal (=)
    // =========================================================================

    public function test_equal_finds_exact_match(): void
    {
        // Given a sorted index with value 500 mapping to [6, 9, 15]
        $entries = self::entries();

        // When searching for value = 500
        $result = BinarySearch::equal($entries, 500);

        // Then IDs [6, 9, 15] should be returned
        $this->assertSame([6, 9, 15], $result, 'Equal search should return IDs for exact value match');
    }

    public function test_equal_returns_empty_for_missing_value(): void
    {
        $entries = self::entries();

        $result = BinarySearch::equal($entries, 400);

        $this->assertSame([], $result, 'Equal search should return empty array when value not found');
    }

    public function test_equal_on_empty_entries(): void
    {
        $result = BinarySearch::equal([], 100);

        $this->assertSame([], $result, 'Equal search on empty entries should return empty array');
    }

    public function test_equal_finds_first_entry(): void
    {
        $entries = self::entries();

        $result = BinarySearch::equal($entries, 100);

        $this->assertSame([3, 7], $result, 'Equal search should find the first entry');
    }

    public function test_equal_finds_last_entry(): void
    {
        $entries = self::entries();

        $result = BinarySearch::equal($entries, 1000);

        $this->assertSame([4, 11], $result, 'Equal search should find the last entry');
    }

    // =========================================================================
    // Greater than (>)
    // =========================================================================

    public function test_greater_than_returns_ids_after_value(): void
    {
        $entries = self::entries();

        // > 300 should return entries for 500, 700, 1000
        $result = BinarySearch::greaterThan($entries, 300);

        sort($result);
        $this->assertSame([4, 6, 8, 9, 11, 14, 15], $result, 'Greater than should return IDs for values strictly above threshold');
    }

    public function test_greater_than_excludes_equal_value(): void
    {
        $entries = self::entries();

        // > 500 should NOT include 500's IDs
        $result = BinarySearch::greaterThan($entries, 500);

        sort($result);
        $this->assertSame([4, 8, 11, 14], $result, 'Greater than should exclude the exact value');
    }

    public function test_greater_than_above_max_returns_empty(): void
    {
        $entries = self::entries();

        $result = BinarySearch::greaterThan($entries, 1000);

        $this->assertSame([], $result, 'Greater than max value should return empty');
    }

    // =========================================================================
    // Greater than or equal (>=)
    // =========================================================================

    public function test_greater_than_or_equal_includes_equal_value(): void
    {
        $entries = self::entries();

        // >= 500 should include 500's IDs
        $result = BinarySearch::greaterThanOrEqual($entries, 500);

        sort($result);
        $this->assertSame([4, 6, 8, 9, 11, 14, 15], $result, 'Greater than or equal should include the exact value');
    }

    // =========================================================================
    // Less than (<)
    // =========================================================================

    public function test_less_than_returns_ids_before_value(): void
    {
        $entries = self::entries();

        // < 500 should return entries for 100, 200, 300
        $result = BinarySearch::lessThan($entries, 500);

        sort($result);
        $this->assertSame([1, 3, 5, 7, 12], $result, 'Less than should return IDs for values strictly below threshold');
    }

    public function test_less_than_excludes_equal_value(): void
    {
        $entries = self::entries();

        // < 300 should NOT include 300's IDs
        $result = BinarySearch::lessThan($entries, 300);

        sort($result);
        $this->assertSame([1, 3, 7, 12], $result, 'Less than should exclude the exact value');
    }

    public function test_less_than_below_min_returns_empty(): void
    {
        $entries = self::entries();

        $result = BinarySearch::lessThan($entries, 100);

        $this->assertSame([], $result, 'Less than min value should return empty');
    }

    // =========================================================================
    // Less than or equal (<=)
    // =========================================================================

    public function test_less_than_or_equal_includes_equal_value(): void
    {
        $entries = self::entries();

        // <= 300 should include 300's IDs
        $result = BinarySearch::lessThanOrEqual($entries, 300);

        sort($result);
        $this->assertSame([1, 3, 5, 7, 12], $result, 'Less than or equal should include the exact value');
    }

    // =========================================================================
    // Between (BETWEEN min AND max, inclusive)
    // =========================================================================

    public function test_between_returns_ids_in_range(): void
    {
        $entries = self::entries();

        // BETWEEN 200 AND 700 should include 200, 300, 500, 700
        $result = BinarySearch::between($entries, 200, 700);

        sort($result);
        $this->assertSame([1, 5, 6, 8, 9, 12, 14, 15], $result, 'Between should return IDs for values within inclusive range');
    }

    public function test_between_with_no_match(): void
    {
        $entries = self::entries();

        // BETWEEN 350 AND 450 — no values in this range
        $result = BinarySearch::between($entries, 350, 450);

        $this->assertSame([], $result, 'Between should return empty when no values in range');
    }

    public function test_between_single_value_range(): void
    {
        $entries = self::entries();

        // BETWEEN 500 AND 500 — only value 500
        $result = BinarySearch::between($entries, 500, 500);

        $this->assertSame([6, 9, 15], $result, 'Between with equal bounds should return IDs for that single value');
    }

    // =========================================================================
    // String values
    // =========================================================================

    public function test_equal_with_string_values(): void
    {
        /** @var list<array{string, list<int>}> $entries */
        $entries = [
            ['DE', [5, 7]],
            ['JP', [1, 3, 6]],
            ['US', [2, 4, 8]],
        ];

        $result = BinarySearch::equal($entries, 'JP');

        $this->assertSame([1, 3, 6], $result, 'Equal search should work with string values');
    }

    public function test_greater_than_with_string_values(): void
    {
        /** @var list<array{string, list<int>}> $entries */
        $entries = [
            ['DE', [5, 7]],
            ['JP', [1, 3, 6]],
            ['US', [2, 4, 8]],
        ];

        $result = BinarySearch::greaterThan($entries, 'JP');

        sort($result);
        $this->assertSame([2, 4, 8], $result, 'Greater than should work with string comparisons');
    }

    // =========================================================================
    // Single entry edge case
    // =========================================================================

    public function test_operations_on_single_entry(): void
    {
        /** @var list<array{int, list<int>}> $entries */
        $entries = [[500, [1, 2]]];

        $this->assertSame([1, 2], BinarySearch::equal($entries, 500), 'Equal should find single entry');
        $this->assertSame([], BinarySearch::greaterThan($entries, 500), 'Greater than single entry max should be empty');
        $this->assertSame([1, 2], BinarySearch::greaterThanOrEqual($entries, 500), 'GTE on single entry should match');
        $this->assertSame([], BinarySearch::lessThan($entries, 500), 'Less than single entry min should be empty');
        $this->assertSame([1, 2], BinarySearch::lessThanOrEqual($entries, 500), 'LTE on single entry should match');
    }
}
