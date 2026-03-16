<?php

namespace Kura\Facades;

use Illuminate\Support\Facades\Facade;
use Kura\KuraManager;

/**
 * @method static void register(string $table, \Kura\Loader\LoaderInterface $loader, string $primaryKey = 'id')
 * @method static \Kura\ReferenceQueryBuilder table(string $table)
 * @method static \Kura\CacheRepository repository(string $table)
 * @method static void rebuild(string $table)
 * @method static void rebuildAll()
 * @method static list<string> registeredTables()
 *
 * @see KuraManager
 */
class Kura extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return KuraManager::class;
    }
}
