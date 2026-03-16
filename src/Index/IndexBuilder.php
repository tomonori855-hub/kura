<?php

namespace Kura\Index;

/**
 * Builds index entries from records.
 *
 * Produces sorted [[value, [ids]], ...] format for storage.
 * Supports optional chunk splitting by unique value count.
 */
final class IndexBuilder
{
    public function __construct(
        private readonly string $primaryKey,
    ) {}

    /**
     * Build index entries for a single column (no chunking).
     *
     * @param  list<array<string, mixed>>  $records
     * @return list<array{mixed, list<int|string>}>
     */
    public function buildColumn(array $records, string $column): array
    {
        $map = $this->groupByColumn($records, $column);

        ksort($map, SORT_NATURAL);

        $entries = [];
        foreach ($map as $value => $ids) {
            $entries[] = [$this->restoreType($value), $ids];
        }

        return $entries;
    }

    /**
     * Build chunked index entries for a single column.
     *
     * Returns an array of chunks, each with 'entries', 'min', and 'max'.
     *
     * @param  list<array<string, mixed>>  $records
     * @param  int<1, max>  $chunkSize
     * @return list<array{entries: list<array{mixed, list<int|string>}>, min: mixed, max: mixed}>
     */
    public function buildColumnChunked(array $records, string $column, int $chunkSize): array
    {
        $allEntries = $this->buildColumn($records, $column);

        if ($allEntries === []) {
            return [];
        }

        $chunks = [];
        foreach (array_chunk($allEntries, $chunkSize) as $chunkEntries) {
            $chunks[] = [
                'entries' => $chunkEntries,
                'min' => $chunkEntries[0][0],
                'max' => $chunkEntries[count($chunkEntries) - 1][0],
            ];
        }

        return $chunks;
    }

    /**
     * Build all indexes from index definitions.
     *
     * For composite indexes, single-column indexes are auto-created for each column.
     *
     * @param  list<array<string, mixed>>  $records
     * @param  list<array{columns: list<string>, unique: bool}>  $definitions
     * @return array<string, list<array{mixed, list<int|string>}>> column => entries
     */
    public function buildAll(array $records, array $definitions, ?int $chunkSize = null): array
    {
        $result = [];

        foreach ($definitions as $def) {
            $columns = $def['columns'];

            // Single column index
            if (count($columns) === 1) {
                $col = $columns[0];
                if (! isset($result[$col])) {
                    $result[$col] = $this->buildColumn($records, $col);
                }

                continue;
            }

            // Composite index: auto-create single-column indexes for each column
            foreach ($columns as $col) {
                if (! isset($result[$col])) {
                    $result[$col] = $this->buildColumn($records, $col);
                }
            }

        }

        return $result;
    }

    /**
     * Build composite index hashmaps from records and definitions.
     *
     * Returns name (col1|col2) => [combined_key => [ids]].
     *
     * @param  list<array<string, mixed>>  $records
     * @param  list<array{columns: list<string>, unique: bool}>  $definitions
     * @return array<string, array<string, list<int|string>>>
     */
    public function buildCompositeIndexes(array $records, array $definitions): array
    {
        $compositeDefs = [];
        foreach ($definitions as $def) {
            if (count($def['columns']) >= 2) {
                $name = implode('|', $def['columns']);
                $compositeDefs[$name] = $def['columns'];
            }
        }

        if ($compositeDefs === []) {
            return [];
        }

        /** @var array<string, array<string, list<int|string>>> $result */
        $result = [];

        foreach ($records as $record) {
            $id = $record[$this->primaryKey];

            foreach ($compositeDefs as $name => $cols) {
                $parts = [];
                $skip = false;
                foreach ($cols as $col) {
                    $value = $record[$col] ?? null;
                    if ($value === null) {
                        $skip = true;
                        break;
                    }
                    $parts[] = (string) $value;
                }
                if ($skip) {
                    continue;
                }
                $combinedKey = implode('|', $parts);
                $result[$name][$combinedKey] ??= [];
                $result[$name][$combinedKey][] = $id;
            }
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Group record IDs by column value.
     *
     * @param  list<array<string, mixed>>  $records
     * @return array<string|int, list<int|string>>
     */
    private function groupByColumn(array $records, string $column): array
    {
        $map = [];
        foreach ($records as $record) {
            $value = $record[$column] ?? null;

            // Skip null values — not indexable
            if ($value === null) {
                continue;
            }

            $id = $record[$this->primaryKey];
            $key = (string) $value;

            $map[$key] ??= [];
            $map[$key][] = $id;
        }

        return $map;
    }

    /**
     * Restore original type from string key.
     *
     * PHP array keys are always string|int. We need to preserve
     * the original type for binary search comparisons.
     */
    public function restoreType(string|int $key): string|int|float
    {
        if (is_int($key)) {
            return $key;
        }

        // If the string is a numeric integer, convert back
        if (ctype_digit($key) || (str_starts_with($key, '-') && ctype_digit(substr($key, 1)))) {
            return (int) $key;
        }

        // If the string is a numeric float, convert back
        if (is_numeric($key) && str_contains($key, '.')) {
            return (float) $key;
        }

        return $key;
    }
}
