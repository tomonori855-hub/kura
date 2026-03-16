<?php

namespace Kura;

use Kura\Loader\LoaderInterface;
use Kura\Store\StoreInterface;

/**
 * Central registry for Kura cache tables.
 *
 * Usage:
 *   $manager->register('products', loader: $loader, primaryKey: 'id');
 *   $manager->table('products')->where('country', 'JP')->get();
 */
class KuraManager
{
    /** @var array<string, array{loader: LoaderInterface, primaryKey: string}> */
    private array $tables = [];

    /** @var array<string, CacheRepository> */
    private array $repositories = [];

    /** @var array<string, CacheProcessor> */
    private array $processors = [];

    private ?string $versionOverride = null;

    /**
     * @param  array{ids?: int, record?: int, meta?: int, index?: int, ids_jitter?: int}  $defaultTtl
     * @param  array<string, array{ttl?: array{ids?: int, record?: int, meta?: int, index?: int, ids_jitter?: int}, chunk_size?: int|null}>  $tableConfigs
     */
    public function __construct(
        private readonly StoreInterface $store,
        private readonly array $defaultTtl = [],
        private readonly ?int $defaultChunkSize = null,
        private readonly int $lockTtl = 60,
        private readonly ?\Closure $rebuildDispatcher = null,
        private readonly array $tableConfigs = [],
    ) {}

    /**
     * Register a table with its loader.
     */
    public function register(string $table, LoaderInterface $loader, string $primaryKey = 'id'): void
    {
        $this->tables[$table] = [
            'loader' => $loader,
            'primaryKey' => $primaryKey,
        ];

        // Clear cached instances if re-registering
        unset($this->repositories[$table], $this->processors[$table]);
    }

    /**
     * Get a fresh query builder for a table.
     */
    public function table(string $table): ReferenceQueryBuilder
    {
        return new ReferenceQueryBuilder(
            table: $table,
            repository: $this->repository($table),
            processor: $this->processor($table),
        );
    }

    /**
     * Get or create a CacheRepository for a table.
     */
    public function repository(string $table): CacheRepository
    {
        if (! isset($this->repositories[$table])) {
            $config = $this->tables[$table] ?? null;

            if ($config === null) {
                throw new \InvalidArgumentException("Table '{$table}' is not registered with Kura.");
            }

            $this->repositories[$table] = new CacheRepository(
                table: $table,
                primaryKey: $config['primaryKey'],
                store: $this->store,
                loader: $config['loader'],
                versionOverride: $this->versionOverride,
            );
        }

        return $this->repositories[$table];
    }

    /**
     * Get or create a CacheProcessor for a table.
     */
    public function processor(string $table): CacheProcessor
    {
        if (! isset($this->processors[$table])) {
            $this->processors[$table] = new CacheProcessor(
                repository: $this->repository($table),
                store: $this->store,
                rebuildDispatcher: $this->rebuildDispatcher,
            );
        }

        return $this->processors[$table];
    }

    /**
     * Rebuild cache for a specific table.
     */
    public function rebuild(string $table): void
    {
        $tableConfig = $this->tableConfigs[$table] ?? [];

        /** @var array{ids?: int, record?: int, meta?: int, index?: int, ids_jitter?: int} $ttl */
        $ttl = array_merge($this->defaultTtl, $tableConfig['ttl'] ?? []);
        $chunkSize = $tableConfig['chunk_size'] ?? $this->defaultChunkSize;

        $this->repository($table)->rebuild(
            ttl: $ttl,
            chunkSize: $chunkSize,
            lockTtl: $this->lockTtl,
        );
    }

    /**
     * Rebuild cache for all registered tables.
     */
    public function rebuildAll(): void
    {
        foreach (array_keys($this->tables) as $table) {
            $this->rebuild($table);
        }
    }

    /**
     * Override the version used for all tables.
     *
     * When set, CacheRepository uses this version instead of Loader::version().
     * Clears cached repository/processor instances so they pick up the new version.
     */
    public function setVersionOverride(string $version): void
    {
        $this->versionOverride = $version;
        $this->repositories = [];
        $this->processors = [];
    }

    /**
     * Get all registered table names.
     *
     * @return list<string>
     */
    public function registeredTables(): array
    {
        return array_keys($this->tables);
    }
}
