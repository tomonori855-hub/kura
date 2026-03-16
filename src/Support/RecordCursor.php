<?php

namespace Kura\Support;

use Kura\CacheRepository;

/**
 * Generator-based cursor that fetches and filters records from CacheRepository.
 *
 * Supported where types
 * ---------------------
 *   basic          — comparison:  =  !=  <>  >  >=  <  <=  like  not like
 *                    bitwise   :  &  |  ^  <<  >>  &~  → (actual OP value) !== 0
 *                               !& (extension)       → (actual  &  value) === 0
 *   in             — IN / NOT IN  (valueSet is a pre-built hash map)
 *   null           — IS NULL / IS NOT NULL
 *   between        — BETWEEN min AND max  (inclusive)
 *   betweenColumns — col BETWEEN min_col AND max_col  (all values from same record)
 *   valueBetween   — scalar value BETWEEN min_col AND max_col
 *   like           — LIKE / NOT LIKE with optional case-sensitive flag
 *   column         — compare two columns of the same record
 *   nullsafe       — null-safe equality  (null === null is true)
 *   filter         — raw PHP Closure predicate  (our extension)
 *   nested         — recursive sub-group  (supports optional negate flag)
 *
 * Performance notes
 * -----------------
 * • matchesWheres() uses PHP's native short-circuit operators (&& / ||).
 *   Expensive evaluators (filter closures, nested groups) placed at the END
 *   of an AND chain are skipped when any earlier condition fails; OR conditions
 *   are skipped once the result is already true.
 * • 'in' conditions use a pre-built hash map (O(1) isset).
 * • All equality comparisons use === (strict) — no implicit type coercion.
 */
class RecordCursor
{
    /**
     * @param  list<int|string>  $ids
     * @param  list<array<string, mixed>>  $wheres  already resolved (no Closures in 'in')
     * @param  list<array{column: string, direction: string}>  $orders
     */
    public function __construct(
        private readonly array $ids,
        private readonly CacheRepository $repository,
        private readonly array $wheres,
        private readonly array $orders,
        private readonly ?int $limit,
        private readonly ?int $offset,
        private readonly bool $randomOrder = false,
    ) {}

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    public function generate(): \Generator
    {
        if ($this->randomOrder) {
            yield from $this->generateRandom();

            return;
        }

        if ($this->orders !== []) {
            yield from $this->generateSorted();

            return;
        }

        $skipped = 0;
        $yielded = 0;

        foreach ($this->ids as $id) {
            if ($this->limit !== null && $yielded >= $this->limit) {
                return;
            }

            $record = $this->repository->find($id);
            if ($record === null || ! $this->matchesWheres($record, $this->wheres)) {
                continue;
            }

            if ($this->offset !== null && $skipped < $this->offset) {
                $skipped++;

                continue;
            }

            yield $record;
            $yielded++;
        }
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private function generateSorted(): \Generator
    {
        $records = [];

        foreach ($this->ids as $id) {
            $record = $this->repository->find($id);
            if ($record === null || ! $this->matchesWheres($record, $this->wheres)) {
                continue;
            }
            $records[] = $record;
        }

        usort($records, $this->buildSorter());

        foreach (array_slice($records, $this->offset ?? 0, $this->limit) as $record) {
            yield $record;
        }
    }

    /**
     * Random order with reservoir sampling when limit is set.
     *
     * When limit+offset is known, uses reservoir sampling to keep at most
     * (limit+offset) records in memory instead of collecting all matches.
     * Without limit, falls back to collect-all + shuffle.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function generateRandom(): \Generator
    {
        $reservoirSize = $this->limit !== null
            ? $this->limit + ($this->offset ?? 0)
            : null;

        if ($reservoirSize === null) {
            // No limit — must collect all, shuffle, then yield
            $records = [];
            foreach ($this->ids as $id) {
                $record = $this->repository->find($id);
                if ($record === null || ! $this->matchesWheres($record, $this->wheres)) {
                    continue;
                }
                $records[] = $record;
            }

            shuffle($records);

            foreach (array_slice($records, $this->offset ?? 0) as $record) {
                yield $record;
            }

            return;
        }

        // Reservoir sampling: keep at most $reservoirSize items
        /** @var list<array<string, mixed>> $reservoir */
        $reservoir = [];
        $seen = 0;

        foreach ($this->ids as $id) {
            $record = $this->repository->find($id);
            if ($record === null || ! $this->matchesWheres($record, $this->wheres)) {
                continue;
            }

            $seen++;

            if ($seen <= $reservoirSize) {
                $reservoir[] = $record;
            } else {
                // Replace a random element with probability reservoirSize/seen
                $j = random_int(1, $seen);
                if ($j <= $reservoirSize) {
                    $reservoir[$j - 1] = $record;
                }
            }
        }

        shuffle($reservoir);

        foreach (array_slice($reservoir, $this->offset ?? 0, $this->limit) as $record) {
            yield $record;
        }
    }

    /**
     * Evaluate a list of where conditions against a record.
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
     * @param  list<array<string, mixed>>  $wheres
     */
    /**
     * Evaluate whether a record matches the given where conditions.
     *
     * Public to allow CacheProcessor to filter records inline without
     * going through the full generate() pipeline.
     *
     * @param  array<string, mixed>  $record
     * @param  list<array<string, mixed>>  $wheres
     */
    public function matchesWheres(array $record, array $wheres): bool
    {
        if ($wheres === []) {
            return true;
        }

        // First condition — always evaluated.
        $first = $wheres[0];
        $result = $this->evalWhere($record, $first);
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
                    ? ! $this->evalWhere($record, $where)
                    : $this->evalWhere($record, $where));
            } else {
                // Short-circuit: evalWhere NOT called when $result is true.
                $result = $result || ($negate
                    ? ! $this->evalWhere($record, $where)
                    : $this->evalWhere($record, $where));
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $where
     */
    private function evalWhere(array $record, array $where): bool
    {
        return match ($where['type']) {
            'basic' => $this->evalBasic($record, $where),
            'in' => $this->evalIn($record, $where),
            'null' => $this->evalNull($record, $where),
            'between' => $this->evalBetween($record, $where),
            'betweenColumns' => $this->evalBetweenColumns($record, $where),
            'valueBetween' => $this->evalValueBetween($record, $where),
            'like' => $this->evalLike($record, $where),
            'column' => $this->evalColumn($record, $where),
            'rowValuesIn' => $this->evalRowValuesIn($record, $where),
            'nullsafe' => ($record[$where['column']] ?? null) === $where['value'],
            'filter' => ($where['callback'])($record),
            'nested' => $this->matchesWheres($record, $where['wheres']),
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
    private function evalBasic(array $record, array $where): bool
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
            'like' => $this->matchesLike((string) $actual, (string) $value, false),
            'not like' => ! $this->matchesLike((string) $actual, (string) $value, false),
            // Bitwise — mirrors Laravel's bitwiseOperators list.
            // All truthy-check operators: (actual OP value) !== 0
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
    private function evalRowValuesIn(array $record, array $where): bool
    {
        /** @var list<string> $columns */
        $columns = $where['columns'];

        $parts = [];
        foreach ($columns as $col) {
            $value = $record[$col] ?? null;
            // DB semantics: NULL in any column → NULL (false)
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
     * valueSet is always built by ReferenceQueryBuilder::resolveSubqueries().
     */
    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $where
     */
    private function evalIn(array $record, array $where): bool
    {
        if ($where['values'] === []) {
            // Empty IN → false;  empty NOT IN → true.
            return $where['not'];
        }

        $actual = $record[$where['column']] ?? null;

        // DB semantics: NULL IN (...) → NULL (false), NULL NOT IN (...) → NULL (false)
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
    private function evalNull(array $record, array $where): bool
    {
        $isNull = ($record[$where['column']] ?? null) === null;

        return $where['not'] ? ! $isNull : $isNull;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $where
     */
    private function evalBetween(array $record, array $where): bool
    {
        $actual = $record[$where['column']] ?? null;

        // DB semantics: NULL BETWEEN x AND y → NULL (false)
        if ($actual === null) {
            return (bool) $where['not'];
        }

        $between = $actual >= $where['values'][0] && $actual <= $where['values'][1];

        return $where['not'] ? ! $between : $between;
    }

    /**
     * col BETWEEN min_col AND max_col — all three values come from the same record.
     */
    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $where
     */
    private function evalBetweenColumns(array $record, array $where): bool
    {
        $actual = $record[$where['column']] ?? null;
        $min = $record[$where['values'][0]] ?? null;
        $max = $record[$where['values'][1]] ?? null;

        // DB semantics: any NULL operand → NULL (false)
        if ($actual === null || $min === null || $max === null) {
            return (bool) $where['not'];
        }

        $between = $actual >= $min && $actual <= $max;

        return $where['not'] ? ! $between : $between;
    }

    /**
     * scalar_value BETWEEN min_col AND max_col.
     */
    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $where
     */
    private function evalValueBetween(array $record, array $where): bool
    {
        $value = $where['value'];
        $min = $record[$where['columns'][0]] ?? null;
        $max = $record[$where['columns'][1]] ?? null;

        // DB semantics: any NULL operand → NULL (false)
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
    private function evalLike(array $record, array $where): bool
    {
        $actual = $record[$where['column']] ?? null;

        // DB semantics: NULL LIKE pattern → NULL (false)
        if ($actual === null) {
            return false;
        }

        $matches = $this->matchesLike((string) $actual, $where['value'], $where['caseSensitive']);

        return $where['not'] ? ! $matches : $matches;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $where
     */
    private function evalColumn(array $record, array $where): bool
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

    private function matchesLike(string $actual, string $pattern, bool $caseSensitive): bool
    {
        $regex = '/^'.str_replace(['%', '_'], ['.*', '.'], preg_quote($pattern, '/')).'$/'
            .($caseSensitive ? '' : 'i');

        return (bool) preg_match($regex, $actual);
    }

    private function buildSorter(): \Closure
    {
        $orders = $this->orders;

        return function (array $a, array $b) use ($orders): int {
            foreach ($orders as ['column' => $col, 'direction' => $dir]) {
                $va = $a[$col] ?? null;
                $vb = $b[$col] ?? null;

                // MySQL semantics: NULL is smallest (first in ASC, last in DESC)
                if ($va === null && $vb === null) {
                    continue;
                }
                if ($va === null) {
                    return $dir === 'desc' ? 1 : -1;
                }
                if ($vb === null) {
                    return $dir === 'desc' ? -1 : 1;
                }

                $cmp = $va <=> $vb;
                if ($cmp !== 0) {
                    return $dir === 'desc' ? -$cmp : $cmp;
                }
            }

            return 0;
        };
    }
}
