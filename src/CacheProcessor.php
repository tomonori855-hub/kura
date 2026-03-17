<?php

namespace Kura;

use Kura\Exceptions\CacheInconsistencyException;
use Kura\Index\IndexResolver;
use Kura\Store\ArrayStore;
use Kura\Store\StoreInterface;
use Kura\Support\RecordCursor;
use Kura\Support\WhereEvaluator;

/**
 * Orchestrates query execution over cached data.
 *
 * Responsibilities:
 *   - Lock check → Loader fallback
 *   - ids/meta existence → Loader fallback + rebuild
 *   - Index resolution (via IndexResolver) to narrow candidate IDs
 *   - Record fetch with inconsistency detection
 *   - Self-Healing dispatch
 *
 * cursor() is a generator that throws CacheInconsistencyException on record miss.
 * select() wraps cursor() and catches the exception, falling back to Loader.
 */
class CacheProcessor
{
    /** @var (\Closure(CacheRepository): void)|null */
    private ?\Closure $rebuildDispatcher;

    /**
     * @param  (\Closure(CacheRepository): void)|null  $rebuildDispatcher
     *                                                                     Custom rebuild dispatcher. When null, rebuild() is called synchronously.
     *                                                                     For queue strategy: fn (CacheRepository $repo) => dispatch(new RebuildJob($repo))
     */
    public function __construct(
        private readonly CacheRepository $repository,
        private readonly StoreInterface $store,
        ?\Closure $rebuildDispatcher = null,
    ) {
        $this->rebuildDispatcher = $rebuildDispatcher;
    }

    /**
     * Execute a query as a generator.
     *
     * Throws CacheInconsistencyException if a record that should exist is missing.
     *
     * @param  list<array<string, mixed>>  $wheres
     * @param  list<array{column: string, direction: string}>  $orders
     * @return \Generator<int, array<string, mixed>>
     *
     * @throws CacheInconsistencyException
     */
    public function cursor(
        array $wheres,
        array $orders,
        ?int $limit,
        ?int $offset,
        bool $randomOrder,
    ): \Generator {
        $table = $this->repository->table();
        $version = $this->repository->version();

        // ロック中 → Loader 直撃
        if ($this->repository->isLocked()) {
            yield from $this->cursorFromLoader($wheres, $orders, $limit, $offset, $randomOrder);

            return;
        }

        $ids = $this->repository->ids();

        // ids なし → rebuild dispatch + Loader 直撃
        if ($ids === false) {
            $this->dispatchRebuild();
            yield from $this->cursorFromLoader($wheres, $orders, $limit, $offset, $randomOrder);

            return;
        }

        // 候補 IDs の解決
        $candidateIds = $ids;

        $meta = $this->repository->meta();

        // meta あり → index で絞り込み可能
        if ($meta !== false) {
            $resolver = new IndexResolver($this->store, $table, $version);
            $resolved = $resolver->resolveIds($wheres, $meta);

            if ($resolved !== null) {
                $candidateIds = $resolved;
            }
        }

        // Record 欠損チェック用に hashmap を作成（array_flip で O(1) lookup）
        /** @var array<int|string, true> $idsMap */
        $idsMap = array_fill_keys($ids, true);

        // RecordCursor でフィルタ + ソート + ページネーション
        // ただし record 欠損を検出するため、直接ループする
        yield from $this->cursorFromCache($candidateIds, $idsMap, $wheres, $orders, $limit, $offset, $randomOrder);
    }

    /**
     * Execute a query and return all matching records as an array.
     *
     * Catches CacheInconsistencyException and falls back to Loader.
     *
     * @param  list<array<string, mixed>>  $wheres
     * @param  list<array{column: string, direction: string}>  $orders
     * @return list<array<string, mixed>>
     */
    public function select(
        array $wheres,
        array $orders,
        ?int $limit,
        ?int $offset,
        bool $randomOrder,
    ): array {
        try {
            return iterator_to_array($this->cursor($wheres, $orders, $limit, $offset, $randomOrder), preserve_keys: false);
        } catch (CacheInconsistencyException) {
            $this->dispatchRebuild();

            return iterator_to_array($this->cursorFromLoader($wheres, $orders, $limit, $offset, $randomOrder), preserve_keys: false);
        }
    }

    // -------------------------------------------------------------------------
    // Cache cursor with inconsistency detection
    // -------------------------------------------------------------------------

    /**
     * @param  list<int|string>  $candidateIds
     * @param  array<int|string, true>  $idsMap
     * @param  list<array<string, mixed>>  $wheres
     * @param  list<array{column: string, direction: string}>  $orders
     * @return \Generator<int, array<string, mixed>>
     *
     * @throws CacheInconsistencyException
     */
    private function cursorFromCache(
        array $candidateIds,
        array $idsMap,
        array $wheres,
        array $orders,
        ?int $limit,
        ?int $offset,
        bool $randomOrder,
    ): \Generator {
        // Sorted/random queries need all records upfront — delegate to RecordCursor
        // with inconsistency check integrated into the ID validation pass.
        if ($orders !== [] || $randomOrder) {
            yield from $this->cursorFromCacheSorted($candidateIds, $idsMap, $wheres, $orders, $limit, $offset, $randomOrder);

            return;
        }

        // No sorting: stream records with early exit on limit.
        // Record inconsistency is checked inline — no pre-fetch needed.
        $skipped = 0;
        $yielded = 0;

        foreach ($candidateIds as $id) {
            if ($limit !== null && $yielded >= $limit) {
                return;
            }

            $record = $this->repository->find($id);

            if ($record === null) {
                if (isset($idsMap[$id])) {
                    throw new CacheInconsistencyException(
                        "Record {$id} missing from cache but present in ids for table {$this->repository->table()}",
                        table: $this->repository->table(),
                        recordId: $id,
                    );
                }

                continue;
            }

            if (! WhereEvaluator::evaluate($record, $wheres)) {
                continue;
            }

            if ($offset !== null && $skipped < $offset) {
                $skipped++;

                continue;
            }

            yield $record;
            $yielded++;
        }
    }

    /**
     * Cache cursor for sorted/random queries — must collect all matching records.
     *
     * @param  list<int|string>  $candidateIds
     * @param  array<int|string, true>  $idsMap
     * @param  list<array<string, mixed>>  $wheres
     * @param  list<array{column: string, direction: string}>  $orders
     * @return \Generator<int, array<string, mixed>>
     *
     * @throws CacheInconsistencyException
     */
    private function cursorFromCacheSorted(
        array $candidateIds,
        array $idsMap,
        array $wheres,
        array $orders,
        ?int $limit,
        ?int $offset,
        bool $randomOrder,
    ): \Generator {
        // Validate all candidate IDs and collect valid ones
        $validIds = [];
        foreach ($candidateIds as $id) {
            $record = $this->repository->find($id);

            if ($record === null && isset($idsMap[$id])) {
                throw new CacheInconsistencyException(
                    "Record {$id} missing from cache but present in ids for table {$this->repository->table()}",
                    table: $this->repository->table(),
                    recordId: $id,
                );
            }

            if ($record !== null) {
                $validIds[] = $id;
            }
        }

        yield from (new RecordCursor(
            ids: $validIds,
            repository: $this->repository,
            wheres: $wheres,
            orders: $orders,
            limit: $limit,
            offset: $offset,
            randomOrder: $randomOrder,
        ))->generate();
    }

    // -------------------------------------------------------------------------
    // Loader fallback
    // -------------------------------------------------------------------------

    /**
     * Execute query directly from Loader (bypass cache).
     *
     * @param  list<array<string, mixed>>  $wheres
     * @param  list<array{column: string, direction: string}>  $orders
     * @return \Generator<int, array<string, mixed>>
     */
    private function cursorFromLoader(
        array $wheres,
        array $orders,
        ?int $limit,
        ?int $offset,
        bool $randomOrder,
    ): \Generator {
        $table = $this->repository->table();
        $version = $this->repository->version();
        $primaryKey = $this->repository->primaryKey();

        // Use a temporary in-memory store to avoid polluting the shared APCu cache
        $tempStore = new ArrayStore;
        $tempIds = [];

        foreach ($this->repository->loader()->load() as $record) {
            $id = $record[$primaryKey];
            $tempIds[] = $id;
            $tempStore->putRecord($table, $version, $id, $record, 0);
        }

        $tempRepository = new CacheRepository(
            table: $table,
            primaryKey: $primaryKey,
            store: $tempStore,
            loader: $this->repository->loader(),
        );

        yield from (new RecordCursor(
            ids: $tempIds,
            repository: $tempRepository,
            wheres: $wheres,
            orders: $orders,
            limit: $limit,
            offset: $offset,
            randomOrder: $randomOrder,
        ))->generate();
    }

    // -------------------------------------------------------------------------
    // Rebuild dispatch
    // -------------------------------------------------------------------------

    /**
     * Dispatch a rebuild using the configured strategy.
     *
     * sync (default): calls rebuild() directly.
     * queue/callback: calls the injected dispatcher closure.
     */
    public function dispatchRebuild(): void
    {
        if ($this->rebuildDispatcher !== null) {
            ($this->rebuildDispatcher)($this->repository);

            return;
        }

        // Default: sync rebuild
        $this->repository->rebuild();
    }
}
