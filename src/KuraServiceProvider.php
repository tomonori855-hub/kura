<?php

namespace Kura;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Kura\Console\RebuildCommand;
use Kura\Contracts\VersionResolverInterface;
use Kura\Http\Controllers\WarmController;
use Kura\Http\Middleware\KuraAuthMiddleware;
use Kura\Jobs\RebuildCacheJob;
use Kura\Loader\CsvVersionResolver;
use Kura\Store\ApcuStore;
use Kura\Store\StoreInterface;
use Kura\Version\CachedVersionResolver;
use Kura\Version\DatabaseVersionResolver;

class KuraServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/kura.php', 'kura');

        $this->app->singleton(StoreInterface::class, function ($app) {
            /** @var string $prefix */
            $prefix = $app['config']->get('kura.prefix', 'kura');

            return new ApcuStore($prefix);
        });

        $this->app->singleton(VersionResolverInterface::class, function ($app) {
            /** @var \Illuminate\Config\Repository $config */
            $config = $app->make('config');

            /** @var string $driver */
            $driver = $config->get('kura.version.driver', 'database');
            /** @var int $cacheTtl */
            $cacheTtl = $config->get('kura.version.cache_ttl', 300);

            $inner = match ($driver) {
                'database' => new DatabaseVersionResolver(
                    table: $config->get('kura.version.table', 'reference_versions'),
                    versionColumn: $config->get('kura.version.columns.version', 'version'),
                    startAtColumn: $config->get('kura.version.columns.activated_at', 'activated_at'),
                ),
                'csv' => new CsvVersionResolver(
                    versionsFilePath: $config->get('kura.version.csv_path', ''),
                ),
                default => throw new \InvalidArgumentException("Unknown version driver: {$driver}"),
            };

            if ($cacheTtl > 0) {
                return new CachedVersionResolver($inner, ttl: $cacheTtl);
            }

            return $inner;
        });

        $this->app->singleton(KuraManager::class, function ($app) {
            /** @var array{ids?: int, record?: int, meta?: int, index?: int, ids_jitter?: int} $ttl */
            $ttl = $app['config']->get('kura.ttl', []);
            /** @var int|null $chunkSize */
            $chunkSize = $app['config']->get('kura.chunk_size');
            /** @var int $lockTtl */
            $lockTtl = $app['config']->get('kura.lock_ttl', 60);
            /** @var array<string, array{ttl?: array{ids?: int, record?: int, meta?: int, index?: int, ids_jitter?: int}, chunk_size?: int|null}> $tableConfigs */
            $tableConfigs = $app['config']->get('kura.tables', []);

            return new KuraManager(
                store: $app->make(StoreInterface::class),
                defaultTtl: $ttl,
                defaultChunkSize: $chunkSize,
                lockTtl: $lockTtl,
                rebuildDispatcher: $this->buildRebuildDispatcher(),
                tableConfigs: $tableConfigs,
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/kura.php' => config_path('kura.php'),
            ], 'kura-config');

            $this->commands([RebuildCommand::class]);
        }

        $this->registerWarmRoute();
    }

    private function registerWarmRoute(): void
    {
        /** @var \Illuminate\Config\Repository $config */
        $config = $this->app->make('config');

        if (! $config->get('kura.warm.enabled', false)) {
            return;
        }

        /** @var string $path */
        $path = $config->get('kura.warm.path', 'kura/warm');

        Route::post($path, WarmController::class)
            ->middleware(KuraAuthMiddleware::class)
            ->name('kura.warm');
    }

    /**
     * Build the rebuild dispatcher Closure based on config strategy.
     *
     * sync:     null (CacheProcessor falls back to synchronous rebuild)
     * queue:    dispatches RebuildCacheJob
     * callback: user registers their own via config (not built here)
     *
     * @return (\Closure(CacheRepository): void)|null
     */
    private function buildRebuildDispatcher(): ?\Closure
    {
        /** @var \Illuminate\Config\Repository $config */
        $config = $this->app->make('config');

        /** @var string $strategy */
        $strategy = $config->get('kura.rebuild.strategy', 'sync');

        if ($strategy !== 'queue') {
            return null;
        }

        /** @var string|null $connection */
        $connection = $config->get('kura.rebuild.queue.connection');
        /** @var string|null $queue */
        $queue = $config->get('kura.rebuild.queue.queue');
        /** @var int $retry */
        $retry = $config->get('kura.rebuild.queue.retry', 3);

        return function (CacheRepository $repository) use ($connection, $queue, $retry): void {
            $job = new RebuildCacheJob($repository->table());
            $job->tries = $retry;

            if ($connection !== null) {
                $job->onConnection($connection);
            }
            if ($queue !== null) {
                $job->onQueue($queue);
            }

            dispatch($job);
        };
    }
}
