<?php

namespace Kura\Tests\Version;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Kura\KuraServiceProvider;
use Kura\Version\DatabaseVersionResolver;
use Orchestra\Testbench\TestCase;

/**
 * Feature: Resolve active version from reference_versions table.
 *
 * Given a reference_versions table with id, version, activated_at,
 * When resolving the active version,
 * Then the latest version whose activated_at <= now() is returned.
 */
class DatabaseVersionResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [KuraServiceProvider::class];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/migrations');
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    public function test_resolves_latest_active_version(): void
    {
        // Arrange
        DB::table('reference_versions')->insert([
            ['version' => 'v1.0.0', 'activated_at' => '2024-01-01 00:00:00'],
            ['version' => 'v2.0.0', 'activated_at' => '2024-06-01 00:00:00'],
            ['version' => 'v3.0.0', 'activated_at' => '2099-01-01 00:00:00'],
        ]);

        $resolver = new DatabaseVersionResolver;

        // Act
        $version = $resolver->resolve();

        // Assert — v3.0.0 is future, v2.0.0 is the latest active
        $this->assertSame('v2.0.0', $version, 'Should return latest version with activated_at <= now()');
    }

    public function test_returns_null_when_table_is_empty(): void
    {
        // Arrange — empty table
        $resolver = new DatabaseVersionResolver;

        // Act
        $version = $resolver->resolve();

        // Assert
        $this->assertNull($version, 'Should return null when no versions exist');
    }

    public function test_returns_null_when_all_versions_are_future(): void
    {
        // Arrange
        DB::table('reference_versions')->insert([
            ['version' => 'v1.0.0', 'activated_at' => '2099-01-01 00:00:00'],
        ]);

        $resolver = new DatabaseVersionResolver;

        // Act
        $version = $resolver->resolve();

        // Assert
        $this->assertNull($version, 'Should return null when all versions are in the future');
    }

    public function test_returns_single_active_version(): void
    {
        // Arrange
        DB::table('reference_versions')->insert([
            ['version' => 'v1.0.0', 'activated_at' => '2020-01-01 00:00:00'],
        ]);

        $resolver = new DatabaseVersionResolver;

        // Act
        $version = $resolver->resolve();

        // Assert
        $this->assertSame('v1.0.0', $version, 'Should return the only active version');
    }

    public function test_uses_custom_column_names(): void
    {
        // Arrange — custom table/column names via constructor
        // Using the default table but verifying the resolver accepts custom names
        DB::table('reference_versions')->insert([
            ['version' => 'v5.0.0', 'activated_at' => '2024-01-01 00:00:00'],
        ]);

        $resolver = new DatabaseVersionResolver(
            table: 'reference_versions',
            versionColumn: 'version',
            startAtColumn: 'activated_at',
        );

        // Act
        $version = $resolver->resolve();

        // Assert
        $this->assertSame('v5.0.0', $version, 'Should work with explicitly specified column names');
    }
}
