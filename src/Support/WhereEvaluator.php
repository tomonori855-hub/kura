<?php

namespace Kura\Support;

/**
 * Stateless evaluator for where-condition trees.
 *
 * Extracted from RecordCursor so that CacheProcessor (and any other caller)
 * can filter records without constructing a full cursor pipeline.
 *
 * All methods are static — no instance state required.
 *
 * @see RecordCursor  Uses this class internally for predicate evaluation.
 */
final class WhereEvaluator
{
    /**
     * Evaluate whether a record matches the given where conditions.
     *
     * Conditions are connected left-to-right by their 'boolean' field ('and'|'or').
     * An optional 'negate' flag on any item flips that item's result before it is
     * combined — used internally by whereNot() and whereNone().
     *
     * PHP's native && / || short-circuit evaluation is preserved:
     *   AND: evalWhere() is NOT called when $result is already false.
     *   OR:  evalWhere() is NOT called when $result is already true.
     *
     * An empty list matches every record (returns true).
     *
     * @param  array<string, mixed>  $record
     * @param  list<array<string, mixed>>  $wheres
     */
    public static function evaluate(array $record, array $wheres): bool
    {
        if ($wheres === []) {
            return true;
        }

        // First condition — always evaluated.
        $first = $wheres[0];
        $result = self::evalWhere($record, $first);
        if ($first['negate'] ?? false) {
            $result = ! $result;
        }

        $count = count($wheres);
        for ($i = 1; $i < $count; $i++) {
            $where = $wheres[$i];
            $negate = $where['negate'] ?? false;

            if ($where['boolean'] === 'and') {
                // Short-circuit: evalWhere NOT called when $result is false.
                $result = $result && ($negate
                    ? ! self::evalWhere($record, $where)
                    : self::evalWhere($record, $where));
            } else {
                // Short-circuit: evalWhere NOT called when $result is true.
                $result = $result || ($negate
                    ? ! self::evalWhere($record, $where)
                    : self::evalWhere($record, $where));
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $where
     */
    private static function evalWhere(array $record, array $where): bool
    {
        return match ($where['type']) {
            'basic' => self::evalBasic($record, $where),
            'in' => self::evalIn($record, $where),
            'null' => self::evalNull($record, $where),
            'between' => self::evalBetween($record, $where),
            'betweenColumns' => self::evalBetweenColumns($record, $where),
            'valueBetween' => self::evalValueBetween($record, $where),
            'like' => self::evalLike($record, $where),
            'column' => self::evalColumn($record, $where),
            'rowValuesIn' => self::evalRowValuesIn($record, $where),
            'nullsafe' => ($record[$where['column']] ?? null) === $where['value'],
            'filter' => ($where['callback'])($record),
            'nested' => self::evaluate($record, $where['wheres']),
            default => throw new \InvalidArgumentException("Unknown where type: {$where['type']}"),
        };
    }

    // -------------------------------------------------------------------------
    // Per-type evaluators
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $where
     */
    private static function evalBasic(array $record, array $where): bool
    {
        $actual = $record[$where['column']] ?? null;
        $value = $where['value'];

        // DB semantics: any comparison involving NULL returns NULL (falsy).
        // Exception: strict equality/inequality where we compare with a non-null literal.
        if ($actual === null || $value === null) {
            return match ($where['operator']) {
                '=' => $actual === $value,
                '!=' => $actual !== $value,
                '<>' => $actual !== $value,
                default => false,
            };
        }

        return match ($where['operator']) {
            // Comparison
            '=' => $actual === $value,
            '!=' => $actual !== $value,
            '<>' => $actual !== $value,
            '>' => $actual > $value,
            '>=' => $actual >= $value,
            '<' => $actual < $value,
            '<=' => $actual <= $value,
            'like' => self::matchesLike((string) $actual, (string) $value, false),
            'not like' => ! self::matchesLike((string) $actual, (string) $value, false),
            // Bitwise — mirrors Laravel's bitwiseOperators list.
            '&' => ((int) $actual & (int) $value) !== 0,
            '|' => ((int) $actual | (int) $value) !== 0,
            '^' => ((int) $actual ^ (int) $value) !== 0,
            '<<' => ((int) $actual << (int) $value) !== 0,
            '>>' => ((int) $actual >> (int) $value) !== 0,
            '&~' => ((int) $actual & ~(int) $value) !== 0,
            // !& extension: (actual & value) === 0  (no bits in mask are set)
            '!&' => ((int) $actual & (int) $value) === 0,
            default => throw new \InvalidArgumentException("Unsupported operator: {$where['operator']}"),
        };
    }

    /**
     * ROW constructor IN: (col1, col2) IN ((v1a, v2a), (v1b, v2b)).
     *
     * Uses the pre-built 'tupleSet' hash-map for O(1) lookup.
     * DB semantics: if any column value is NULL, the result is NULL (false).
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $where
     */
    private static function evalRowValuesIn(array $record, array $where): bool
    {
        /** @var list<string> $columns */
        $columns = $where['columns'];

        $parts = [];
        foreach ($columns as $col) {
            $value = $record[$col] ?? null;
            // DB semantics: NULL in any column -> NULL (false)
            if ($value === null) {
                return false;
            }
            $parts[] = (string) $value;
        }

        $key = implode('|', $parts);
        $in = isset($where['tupleSet'][$key]);

        return $where['not'] ? ! $in : $in;
    }

    /**
     * Uses the pre-built 'valueSet' hash-map (array<mixed, true>) for O(1) lookup.
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $where
     */
    private static function evalIn(array $record, array $where): bool
    {
        if ($where['values'] === []) {
            // Empty IN -> false;  empty NOT IN -> true.
            return $where['not'];
        }

        $actual = $record[$where['column']] ?? null;

        // DB semantics: NULL IN (...) -> NULL (false), NULL NOT IN (...) -> NULL (false)
        if ($actual === null) {
            return false;
        }

        $in = isset($where['valueSet'][$actual]);

        return $where['not'] ? ! $in : $in;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $where
     */
    private static function evalNull(array $record, array $where): bool
    {
        $isNull = ($record[$where['column']] ?? null) === null;

        return $where['not'] ? ! $isNull : $isNull;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $where
     */
    private static function evalBetween(array $record, array $where): bool
    {
        $actual = $record[$where['column']] ?? null;

        // DB semantics: NULL BETWEEN x AND y -> NULL (false)
        if ($actual === null) {
            return (bool) $where['not'];
        }

        $between = $actual >= $where['values'][0] && $actual <= $where['values'][1];

        return $where['not'] ? ! $between : $between;
    }

    /**
     * col BETWEEN min_col AND max_col — all three values come from the same record.
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $where
     */
    private static function evalBetweenColumns(array $record, array $where): bool
    {
        $actual = $record[$where['column']] ?? null;
        $min = $record[$where['values'][0]] ?? null;
        $max = $record[$where['values'][1]] ?? null;

        // DB semantics: any NULL operand -> NULL (false)
        if ($actual === null || $min === null || $max === null) {
            return (bool) $where['not'];
        }

        $between = $actual >= $min && $actual <= $max;

        return $where['not'] ? ! $between : $between;
    }

    /**
     * scalar_value BETWEEN min_col AND max_col.
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $where
     */
    private static function evalValueBetween(array $record, array $where): bool
    {
        $value = $where['value'];
        $min = $record[$where['columns'][0]] ?? null;
        $max = $record[$where['columns'][1]] ?? null;

        // DB semantics: any NULL operand -> NULL (false)
        if ($value === null || $min === null || $max === null) {
            return (bool) $where['not'];
        }

        $between = $value >= $min && $value <= $max;

        return $where['not'] ? ! $between : $between;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $where
     */
    private static function evalLike(array $record, array $where): bool
    {
        $actual = $record[$where['column']] ?? null;

        // DB semantics: NULL LIKE pattern -> NULL (false)
        if ($actual === null) {
            return false;
        }

        $matches = self::matchesLike((string) $actual, $where['value'], $where['caseSensitive']);

        return $where['not'] ? ! $matches : $matches;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $where
     */
    private static function evalColumn(array $record, array $where): bool
    {
        $left = $record[$where['first']] ?? null;
        $right = $record[$where['second']] ?? null;

        // DB semantics: any comparison involving NULL returns NULL (falsy).
        if ($left === null || $right === null) {
            return match ($where['operator']) {
                '=' => $left === $right,
                '!=' => $left !== $right,
                '<>' => $left !== $right,
                default => false,
            };
        }

        return match ($where['operator']) {
            '=' => $left === $right,
            '!=' => $left !== $right,
            '<>' => $left !== $right,
            '>' => $left > $right,
            '>=' => $left >= $right,
            '<' => $left < $right,
            '<=' => $left <= $right,
            default => throw new \InvalidArgumentException("Unsupported operator for whereColumn: {$where['operator']}"),
        };
    }

    // -------------------------------------------------------------------------
    // Shared utilities
    // -------------------------------------------------------------------------

    private static function matchesLike(string $actual, string $pattern, bool $caseSensitive): bool
    {
        $regex = '/^'.str_replace(['%', '_'], ['.*', '.'], preg_quote($pattern, '/')).'$/'
            .($caseSensitive ? '' : 'i');

        return (bool) preg_match($regex, $actual);
    }
}
