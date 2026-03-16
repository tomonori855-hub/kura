<?php

namespace Kura\Index;

/**
 * Binary search operations on sorted index entries.
 *
 * Index entries follow the format: [[value, [ids]], ...] sorted by value ascending.
 * All methods return a flat list of IDs matching the condition.
 */
final class BinarySearch
{
    /**
     * Find IDs for an exact value match.
     *
     * @param  list<array{mixed, list<int|string>}>  $entries
     * @return list<int|string>
     */
    public static function equal(array $entries, mixed $value): array
    {
        $index = self::findIndex($entries, $value);

        if ($index === null) {
            return [];
        }

        return $entries[$index][1];
    }

    /**
     * Find IDs for values strictly greater than the given value.
     *
     * @param  list<array{mixed, list<int|string>}>  $entries
     * @return list<int|string>
     */
    public static function greaterThan(array $entries, mixed $value): array
    {
        $pos = self::upperBound($entries, $value);

        return self::collectFrom($entries, $pos);
    }

    /**
     * Find IDs for values greater than or equal to the given value.
     *
     * @param  list<array{mixed, list<int|string>}>  $entries
     * @return list<int|string>
     */
    public static function greaterThanOrEqual(array $entries, mixed $value): array
    {
        $pos = self::lowerBound($entries, $value);

        return self::collectFrom($entries, $pos);
    }

    /**
     * Find IDs for values strictly less than the given value.
     *
     * @param  list<array{mixed, list<int|string>}>  $entries
     * @return list<int|string>
     */
    public static function lessThan(array $entries, mixed $value): array
    {
        $pos = self::lowerBound($entries, $value);

        return self::collectUntil($entries, $pos);
    }

    /**
     * Find IDs for values less than or equal to the given value.
     *
     * @param  list<array{mixed, list<int|string>}>  $entries
     * @return list<int|string>
     */
    public static function lessThanOrEqual(array $entries, mixed $value): array
    {
        $pos = self::upperBound($entries, $value);

        return self::collectUntil($entries, $pos);
    }

    /**
     * Find IDs for values within an inclusive range [min, max].
     *
     * @param  list<array{mixed, list<int|string>}>  $entries
     * @return list<int|string>
     */
    public static function between(array $entries, mixed $min, mixed $max): array
    {
        $from = self::lowerBound($entries, $min);
        $to = self::upperBound($entries, $max);

        $ids = [];
        for ($i = $from; $i < $to; $i++) {
            foreach ($entries[$i][1] as $id) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    // -------------------------------------------------------------------------
    // Binary search primitives
    // -------------------------------------------------------------------------

    /**
     * Find the exact index of a value, or null if not found.
     *
     * @param  list<array{mixed, list<int|string>}>  $entries
     */
    private static function findIndex(array $entries, mixed $value): ?int
    {
        $lo = 0;
        $hi = count($entries) - 1;

        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            $cmp = $entries[$mid][0] <=> $value;

            if ($cmp === 0) {
                return $mid;
            }

            if ($cmp < 0) {
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }

        return null;
    }

    /**
     * Find the first position where entries[pos][0] >= value.
     *
     * @param  list<array{mixed, list<int|string>}>  $entries
     */
    private static function lowerBound(array $entries, mixed $value): int
    {
        $lo = 0;
        $hi = count($entries);

        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);

            if ($entries[$mid][0] < $value) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }

        return $lo;
    }

    /**
     * Find the first position where entries[pos][0] > value.
     *
     * @param  list<array{mixed, list<int|string>}>  $entries
     */
    private static function upperBound(array $entries, mixed $value): int
    {
        $lo = 0;
        $hi = count($entries);

        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);

            if ($entries[$mid][0] <= $value) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }

        return $lo;
    }

    /**
     * Collect all IDs from entries[pos] to end.
     *
     * @param  list<array{mixed, list<int|string>}>  $entries
     * @return list<int|string>
     */
    private static function collectFrom(array $entries, int $pos): array
    {
        $ids = [];
        $count = count($entries);

        for ($i = $pos; $i < $count; $i++) {
            foreach ($entries[$i][1] as $id) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Collect all IDs from entries[0] to entries[pos-1].
     *
     * @param  list<array{mixed, list<int|string>}>  $entries
     * @return list<int|string>
     */
    private static function collectUntil(array $entries, int $pos): array
    {
        $ids = [];

        for ($i = 0; $i < $pos; $i++) {
            foreach ($entries[$i][1] as $id) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
