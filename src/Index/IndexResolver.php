<?php

namespace Kura\Index;

use Kura\Store\StoreInterface;

/**
 * Resolves candidate IDs from stored indexes using where conditions.
 *
 * Returns null when the condition cannot be resolved via index (full scan needed).
 * Returns list<int|string> when IDs were successfully resolved.
 */
final class IndexResolver
{
    public function __construct(
        private readonly StoreInterface $store,
        private readonly string $table,
        private readonly string $version,
    ) {}

    /**
     * Resolve candidate IDs for a single where condition.
     *
     * @param  array<string, mixed>  $where
     * @param  array<string, mixed>  $meta
     * @return list<int|string>|null null = not index-resolvable
     */
    public function resolveForWhere(array $where, array $meta): ?array
    {
        return match ($where['type']) {
            'basic' => $this->resolveBasic($where, $meta),
            'between' => $this->resolveBetween($where, $meta),
            'in' => $this->resolveIn($where, $meta),
            'rowValuesIn' => $this->resolveRowValuesIn($where, $meta),
            default => null,
        };
    }

    /**
     * Resolve candidate IDs for multiple where conditions.
     *
     * 1. Try composite index first (multiple AND equality conditions on a composite key).
     * 2. Fall back to per-condition resolution with AND/OR logic.
     *
     * Partial AND resolution: if an AND condition cannot be index-resolved, it is
     * skipped here and left to WhereEvaluator. This narrows candidates using whatever
     * indexes are available without abandoning the entire index resolution.
     *
     * OR conditions are all-or-nothing: if any OR branch is not index-resolvable,
     * we cannot safely narrow (records matching only that branch would be missed).
     *
     * @param  list<array<string, mixed>>  $wheres
     * @param  array<string, mixed>  $meta
     * @return list<int|string>|null null = cannot narrow (full scan needed)
     */
    public function resolveIds(array $wheres, array $meta): ?array
    {
        if ($wheres === []) {
            return null;
        }

        // Try composite index lookup for AND equality conditions
        $compositeResult = $this->tryCompositeIndex($wheres, $meta);
        if ($compositeResult !== null) {
            return $compositeResult;
        }

        /** @var array<int|string, true>|null $result null = not yet established */
        $result = null;

        // Track whether any AND conditions were skipped before the first resolved result.
        // If true and we later encounter an OR, we cannot safely narrow.
        $skippedAndBeforeResult = false;

        foreach ($wheres as $where) {
            $boolean = $where['boolean'] ?? 'and';
            $ids = $this->resolveForWhere($where, $meta);

            if ($ids === null) {
                if ($boolean === 'or') {
                    // Non-resolvable OR branch: records matching only this branch
                    // would be missed → must full scan.
                    return null;
                }

                // Non-resolvable AND: skip. WhereEvaluator will evaluate it.
                if ($result === null) {
                    $skippedAndBeforeResult = true;
                }

                continue;
            }

            $set = array_fill_keys($ids, true);

            if ($result === null) {
                if ($skippedAndBeforeResult && $boolean === 'or') {
                    // Skipped AND conditions precede this OR — the OR's "other side"
                    // is unknown, so we cannot safely narrow.
                    return null;
                }

                $result = $set;
            } elseif ($boolean === 'and') {
                $result = array_intersect_key($result, $set);
            } else {
                // OR: union. Previously skipped AND conditions only narrow the AND
                // group, so $result is already a superset — safe to union.
                $result += $set;
            }
        }

        return $result !== null ? array_keys($result) : null;
    }

    // -------------------------------------------------------------------------
    // Composite index
    // -------------------------------------------------------------------------

    /**
     * Try to resolve IDs using a composite index.
     *
     * Matches when all conditions are AND + basic '=' and their columns
     * exactly match a registered composite index.
     *
     * @param  list<array<string, mixed>>  $wheres
     * @param  array<string, mixed>  $meta
     * @return list<int|string>|null
     */
    private function tryCompositeIndex(array $wheres, array $meta): ?array
    {
        /** @var list<string> $compositeNames */
        $compositeNames = $meta['composites'] ?? [];
        if ($compositeNames === []) {
            return null;
        }

        // Collect AND equality conditions: column → value
        /** @var array<string, mixed> $eqConditions */
        $eqConditions = [];
        foreach ($wheres as $where) {
            $boolean = $where['boolean'] ?? 'and';
            if ($boolean !== 'and') {
                return null; // OR mixed in — can't use composite
            }
            if (($where['type'] ?? '') !== 'basic' || ($where['operator'] ?? '') !== '=') {
                return null; // Non-equality — can't use composite
            }
            /** @var string $column */
            $column = $where['column'];
            $eqConditions[$column] = $where['value'];
        }

        // Find a composite index that matches the condition columns
        foreach ($compositeNames as $name) {
            $cols = explode('|', $name);
            if (count($cols) !== count($eqConditions)) {
                continue;
            }

            // All composite columns must be present in conditions
            $allMatch = true;
            $parts = [];
            foreach ($cols as $col) {
                if (! array_key_exists($col, $eqConditions)) {
                    $allMatch = false;
                    break;
                }
                $parts[] = (string) $eqConditions[$col];
            }

            if (! $allMatch) {
                continue;
            }

            // Fetch the composite index from store
            $map = $this->store->getCompositeIndex($this->table, $this->version, $name);
            if ($map === false) {
                return null; // Index missing — fall back
            }

            $combinedKey = implode('|', $parts);

            return $map[$combinedKey] ?? [];
        }

        return null; // No matching composite index
    }

    // -------------------------------------------------------------------------
    // ROW constructor IN — composite index acceleration
    // -------------------------------------------------------------------------

    /**
     * Resolve IDs for (col1, col2) IN ((v1a, v2a), (v1b, v2b)) via composite index.
     *
     * When a composite index matches the columns, each tuple is looked up in O(1)
     * from the hashmap, and the results are unioned. Falls back to null (full scan)
     * when no matching composite index exists or NOT IN is used.
     *
     * @param  array<string, mixed>  $where
     * @param  array<string, mixed>  $meta
     * @return list<int|string>|null
     */
    private function resolveRowValuesIn(array $where, array $meta): ?array
    {
        if ($where['not']) {
            return null; // NOT IN cannot be accelerated via index
        }

        /** @var list<string> $columns */
        $columns = $where['columns'];
        $compositeName = implode('|', $columns);

        /** @var list<string> $compositeNames */
        $compositeNames = $meta['composites'] ?? [];

        if (! in_array($compositeName, $compositeNames, true)) {
            return null; // No matching composite index
        }

        $map = $this->store->getCompositeIndex($this->table, $this->version, $compositeName);
        if ($map === false) {
            return null;
        }

        /** @var array<int|string, true> $idSet */
        $idSet = [];
        foreach ($where['tuples'] as $tuple) {
            $key = implode('|', array_map('strval', $tuple));
            foreach ($map[$key] ?? [] as $id) {
                $idSet[$id] = true;
            }
        }

        return array_keys($idSet);
    }

    // -------------------------------------------------------------------------
    // Per-type resolvers
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $where
     * @param  array<string, mixed>  $meta
     * @return list<int|string>|null
     */
    private function resolveBasic(array $where, array $meta): ?array
    {
        $column = $where['column'];
        $operator = $where['operator'];
        $value = $where['value'];

        if (! $this->isIndexed($column, $meta)) {
            return null;
        }

        // Only these operators are index-resolvable
        if (! in_array($operator, ['=', '>', '>=', '<', '<='], true)) {
            return null;
        }

        $indexMeta = $meta['indexes'][$column];

        return $this->searchIndex($column, $indexMeta, $operator, $value);
    }

    /**
     * @param  array<string, mixed>  $where
     * @param  array<string, mixed>  $meta
     * @return list<int|string>|null
     */
    private function resolveBetween(array $where, array $meta): ?array
    {
        if ($where['not']) {
            return null;
        }

        $column = $where['column'];

        if (! $this->isIndexed($column, $meta)) {
            return null;
        }

        $indexMeta = $meta['indexes'][$column];

        return $this->searchIndexBetween($column, $indexMeta, $where['values'][0], $where['values'][1]);
    }

    /**
     * @param  array<string, mixed>  $where
     * @param  array<string, mixed>  $meta
     * @return list<int|string>|null
     */
    private function resolveIn(array $where, array $meta): ?array
    {
        if ($where['not']) {
            return null;
        }

        $column = $where['column'];

        if (! $this->isIndexed($column, $meta)) {
            return null;
        }

        $indexMeta = $meta['indexes'][$column];

        // IN → union of equal searches for each value
        $allIds = [];
        foreach ($where['values'] as $value) {
            $ids = $this->searchIndex($column, $indexMeta, '=', $value);
            if ($ids !== null) {
                foreach ($ids as $id) {
                    $allIds[] = $id;
                }
            }
        }

        return array_values(array_unique($allIds));
    }

    // -------------------------------------------------------------------------
    // Index access
    // -------------------------------------------------------------------------

    /**
     * @param  array<int, array{min: mixed, max: mixed}>|array{}  $indexMeta
     * @return list<int|string>|null
     */
    private function searchIndex(string $column, array $indexMeta, string $operator, mixed $value): ?array
    {
        if ($indexMeta === []) {
            // No chunks — single index key
            return $this->searchEntries($column, null, $operator, $value);
        }

        // Chunked — find relevant chunks by min/max
        $allIds = [];
        foreach ($indexMeta as $chunkIndex => $chunk) {
            if (! $this->chunkOverlaps($chunk, $operator, $value)) {
                continue;
            }

            $ids = $this->searchEntries($column, $chunkIndex, $operator, $value);
            if ($ids === null) {
                return null;
            }

            foreach ($ids as $id) {
                $allIds[] = $id;
            }
        }

        return $allIds;
    }

    /**
     * @param  array<int, array{min: mixed, max: mixed}>|array{}  $indexMeta
     * @return list<int|string>|null
     */
    private function searchIndexBetween(string $column, array $indexMeta, mixed $min, mixed $max): ?array
    {
        if ($indexMeta === []) {
            $entries = $this->store->getIndex($this->table, $this->version, $column);

            if ($entries === false) {
                return null;
            }

            return BinarySearch::between($entries, $min, $max);
        }

        // Chunked — find relevant chunks
        $allIds = [];
        foreach ($indexMeta as $chunkIndex => $chunk) {
            // Chunk overlaps with [min, max] if chunk.max >= min && chunk.min <= max
            if ($chunk['max'] < $min || $chunk['min'] > $max) {
                continue;
            }

            $entries = $this->store->getIndex($this->table, $this->version, $column, $chunkIndex);
            if ($entries === false) {
                return null;
            }

            $ids = BinarySearch::between($entries, $min, $max);
            foreach ($ids as $id) {
                $allIds[] = $id;
            }
        }

        return $allIds;
    }

    /**
     * @return list<int|string>|null
     */
    private function searchEntries(string $column, ?int $chunk, string $operator, mixed $value): ?array
    {
        $entries = $this->store->getIndex($this->table, $this->version, $column, $chunk);

        if ($entries === false) {
            return null;
        }

        return match ($operator) {
            '=' => BinarySearch::equal($entries, $value),
            '>' => BinarySearch::greaterThan($entries, $value),
            '>=' => BinarySearch::greaterThanOrEqual($entries, $value),
            '<' => BinarySearch::lessThan($entries, $value),
            '<=' => BinarySearch::lessThanOrEqual($entries, $value),
            default => null,
        };
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $meta
     */
    private function isIndexed(string $column, array $meta): bool
    {
        return isset($meta['indexes'][$column]);
    }

    /**
     * Check if a chunk's [min, max] range could contain results for the given operator and value.
     *
     * @param  array{min: mixed, max: mixed}  $chunk
     */
    private function chunkOverlaps(array $chunk, string $operator, mixed $value): bool
    {
        return match ($operator) {
            '=' => $value >= $chunk['min'] && $value <= $chunk['max'],
            '>' => $chunk['max'] > $value,
            '>=' => $chunk['max'] >= $value,
            '<' => $chunk['min'] < $value,
            '<=' => $chunk['min'] <= $value,
            default => true,
        };
    }
}
