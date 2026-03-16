<?php

namespace Kura;

use Kura\Index\IndexBuilder;
use Kura\Loader\LoaderInterface;
use Kura\Store\StoreInterface;

/**
 * Thin data layer over StoreInterface for a single table.
 *
 * Provides ids(), find(), meta(), isLocked(), rebuild().
 * No auto-reload on cache miss — the caller (CacheProcessor or query layer)
 * decides when to trigger a rebuild.
 */
class CacheRepository
{
    /** @var array<string, mixed>|false|null null = not yet fetched */
    private array|false|null $metaCache = null;

    private readonly string $version;

    public function __construct(
        private readonly string $table,
        private readonly string $primaryKey,
        private readonly StoreInterface $store,
        private readonly LoaderInterface $loader,
        ?string $versionOverride = null,
    ) {
        $this->version = $versionOverride ?? (string) $this->loader->version();
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    public function table(): string
    {
        return $this->table;
    }

    public function primaryKey(): string
    {
        return $this->primaryKey;
    }

    public function store(): StoreInterface
    {
        return $this->store;
    }

    public function loader(): LoaderInterface
    {
        return $this->loader;
    }

    public function version(): string
    {
        return $this->version;
    }

    /**
     * Return the IDs list from the store, or false if not cached.
     *
     * @return list<int|string>|false
     */
    public function ids(): array|false
    {
        return $this->store->getIds($this->table, $this->version());
    }

    /**
     * Fetch a single record from the store, or null if not found.
     *
     * @return array<string, mixed>|null
     */
    public function find(int|string $id): ?array
    {
        $record = $this->store->getRecord($this->table, $this->version(), $id);

        return $record !== false ? $record : null;
    }

    /**
     * Return meta from the store, or false if not cached.
     *
     * Uses PHP var cache to avoid repeated APCu fetches within a single request.
     *
     * @return array<string, mixed>|false
     */
    public function meta(): array|false
    {
        if ($this->metaCache === null) {
            $this->metaCache = $this->store->getMeta($this->table, $this->version());
        }

        return $this->metaCache;
    }

    public function isLocked(): bool
    {
        return $this->store->isLocked($this->table);
    }

    /**
     * Full rebuild: flush and re-import all records from the loader.
     *
     * Phase 1 (locked): load records into store, build ids hashmap.
     * Phase 2 (after records): build meta.
     *
     * @param  array{ids?: int, record?: int, meta?: int, index?: int, ids_jitter?: int}  $ttl
     */
    public function rebuild(array $ttl = [], ?int $chunkSize = null, int $lockTtl = 60): void
    {
        if (! $this->store->acquireLock($this->table, $lockTtl)) {
            return; // Another process is already rebuilding
        }

        $version = $this->version();
        $idsJitter = $ttl['ids_jitter'] ?? 0;
        $idsTtl = ($ttl['ids'] ?? 3600) + ($idsJitter > 0 ? random_int(0, $idsJitter) : 0);
        $recordTtl = $ttl['record'] ?? 4800;
        $metaTtl = $ttl['meta'] ?? 4800;
        $indexTtl = $ttl['index'] ?? 4800;

        try {
            $this->store->flush($this->table, $version);

            // Phase 1 (locked): load records + build ids + collect index data
            /** @var list<int|string> $idsList */
            $idsList = [];
            /** @var array<string, array<string|int, list<int|string>>> $indexData col → [value → [ids]] */
            $indexData = [];
            /** @var array<string, array<string, list<int|string>>> $compositeData name → [combined_key → [ids]] */
            $compositeData = [];
            $indexDefinitions = $this->loader->indexes();
            $indexedColumns = $this->extractIndexedColumns($indexDefinitions);
            $compositeDefs = $this->extractCompositeDefs($indexDefinitions);

            foreach ($this->loader->load() as $record) {
                $id = $record[$this->primaryKey];
                $idsList[] = $id;
                $this->store->putRecord($this->table, $version, $id, $record, $recordTtl);

                // Collect single-column index data
                foreach ($indexedColumns as $col) {
                    $value = $record[$col] ?? null;
                    if ($value !== null) {
                        $indexData[$col][(string) $value] ??= [];
                        $indexData[$col][(string) $value][] = $id;
                    }
                }

                // Collect composite index data
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
                    $compositeData[$name][$combinedKey] ??= [];
                    $compositeData[$name][$combinedKey][] = $id;
                }
            }

            $this->store->putIds($this->table, $version, $idsList, $idsTtl);
        } finally {
            $this->store->releaseLock($this->table);
        }

        // Phase 2 (unlocked): build indexes + meta
        // Queries can now run in full-scan mode (ids exist, meta not yet)
        $columns = $this->loader->columns();
        $indexBuilder = new IndexBuilder($this->primaryKey);

        /** @var array<string, list<array{min: mixed, max: mixed}>|array{}> $indexMeta */
        $indexMeta = [];

        foreach ($indexData as $column => $valueMap) {
            // Sort by value and build entries
            ksort($valueMap, SORT_NATURAL);
            /** @var list<array{mixed, list<int|string>}> $entries */
            $entries = [];
            foreach ($valueMap as $value => $ids) {
                $entries[] = [$indexBuilder->restoreType($value), $ids];
            }

            if ($chunkSize !== null && $chunkSize > 0) {
                /** @var int<1, max> $chunkSize */
                $chunkedEntries = array_chunk($entries, $chunkSize);
                $chunkMeta = [];
                foreach ($chunkedEntries as $chunkIndex => $chunkEntries) {
                    $this->store->putIndex($this->table, $version, $column, $chunkEntries, $indexTtl, $chunkIndex);
                    $chunkMeta[] = [
                        'min' => $chunkEntries[0][0],
                        'max' => $chunkEntries[count($chunkEntries) - 1][0],
                    ];
                }
                $indexMeta[$column] = $chunkMeta;
            } else {
                $this->store->putIndex($this->table, $version, $column, $entries, $indexTtl);
                $indexMeta[$column] = [];
            }
        }

        // Columns with index definitions but no data
        foreach ($indexedColumns as $col) {
            if (! isset($indexMeta[$col])) {
                $indexMeta[$col] = [];
            }
        }

        // Store composite indexes
        /** @var list<string> $compositeNames */
        $compositeNames = [];
        foreach ($compositeData as $name => $map) {
            $this->store->putCompositeIndex($this->table, $version, $name, $map, $indexTtl);
            $compositeNames[] = $name;
        }

        // Include composite names for definitions with no data
        foreach ($compositeDefs as $name => $cols) {
            if (! in_array($name, $compositeNames, true)) {
                $compositeNames[] = $name;
            }
        }

        $meta = [
            'columns' => $columns,
            'indexes' => $indexMeta,
            'composites' => $compositeNames,
        ];
        $this->store->putMeta($this->table, $version, $meta, $metaTtl);
        $this->metaCache = null; // Invalidate PHP var cache
    }

    /**
     * Extract all columns that need indexing from index definitions.
     *
     * Composite indexes auto-expand to include each column.
     *
     * @param  list<array{columns: list<string>, unique: bool}>  $definitions
     * @return list<string>
     */
    private function extractIndexedColumns(array $definitions): array
    {
        $columns = [];
        foreach ($definitions as $def) {
            foreach ($def['columns'] as $col) {
                $columns[$col] = true;
            }
        }

        return array_keys($columns);
    }

    /**
     * Extract composite (multi-column) index definitions.
     *
     * Returns name (col1|col2) → list of columns for definitions with 2+ columns.
     *
     * @param  list<array{columns: list<string>, unique: bool}>  $definitions
     * @return array<string, list<string>>
     */
    private function extractCompositeDefs(array $definitions): array
    {
        $composites = [];
        foreach ($definitions as $def) {
            if (count($def['columns']) >= 2) {
                $name = implode('|', $def['columns']);
                $composites[$name] = $def['columns'];
            }
        }

        return $composites;
    }

    /**
     * Simple reload for backward compatibility.
     * Delegates to rebuild() with default TTLs.
     */
    public function reload(): void
    {
        $this->rebuild();
    }
}
