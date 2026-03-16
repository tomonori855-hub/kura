<?php

namespace Kura\Tests\Jobs;

use Kura\Jobs\RebuildCacheJob;
use Kura\KuraManager;
use Kura\Store\ArrayStore;
use Kura\Tests\Support\InMemoryLoader;
use PHPUnit\Framework\TestCase;

class RebuildCacheJobTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function test_constructor_sets_table_name(): void
    {
        // Arrange & Act
        $job = new RebuildCacheJob('products');

        // Assert
        $this->assertSame('products', $job->table, 'table should be set from constructor');
    }

    public function test_default_tries_is_three(): void
    {
        // Arrange & Act
        $job = new RebuildCacheJob('products');

        // Assert
        $this->assertSame(3, $job->tries, 'Default tries should be 3');
    }

    // -------------------------------------------------------------------------
    // handle() delegates to KuraManager::rebuild()
    // -------------------------------------------------------------------------

    public function test_handle_calls_manager_rebuild(): void
    {
        // Arrange
        $store = new ArrayStore;
        $manager = new KuraManager(store: $store);
        $manager->register('products', new InMemoryLoader([
            ['id' => 1, 'name' => 'Widget'],
            ['id' => 2, 'name' => 'Gadget'],
        ]));

        $job = new RebuildCacheJob('products');

        // Act
        $job->handle($manager);

        // Assert
        $ids = $store->getIds('products', 'v1');
        $this->assertIsArray($ids, 'handle() should trigger rebuild and populate cache');
        $this->assertSame(
            [1, 2],
            $ids,
            'All records should be cached after job execution',
        );
    }

    public function test_handle_rebuilds_correct_table_only(): void
    {
        // Arrange
        $store = new ArrayStore;
        $manager = new KuraManager(store: $store);
        $manager->register('products', new InMemoryLoader([
            ['id' => 1, 'name' => 'Widget'],
        ]));
        $manager->register('users', new InMemoryLoader([
            ['id' => 1, 'name' => 'Alice'],
        ]));

        $job = new RebuildCacheJob('products');

        // Act
        $job->handle($manager);

        // Assert
        $this->assertIsArray(
            $store->getIds('products', 'v1'),
            'products should be rebuilt',
        );
        $this->assertFalse(
            $store->getIds('users', 'v1'),
            'users should NOT be rebuilt by a products job',
        );
    }

    // -------------------------------------------------------------------------
    // Queue configuration
    // -------------------------------------------------------------------------

    public function test_tries_can_be_overridden(): void
    {
        // Arrange
        $job = new RebuildCacheJob('products');

        // Act
        $job->tries = 5;

        // Assert
        $this->assertSame(5, $job->tries, 'tries should be overridable');
    }
}
