<?php

namespace Kura\Tests\Version;

use Kura\Contracts\VersionResolverInterface;
use Kura\Version\CachedVersionResolver;
use PHPUnit\Framework\TestCase;

class CachedVersionResolverTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeCountingResolver(?string $version): VersionResolverInterface&CountableResolver
    {
        return new CountableResolver($version);
    }

    // -------------------------------------------------------------------------
    // Basic caching
    // -------------------------------------------------------------------------

    public function test_resolve_delegates_to_inner_on_first_call(): void
    {
        // Arrange
        $inner = $this->makeCountingResolver('v1.0.0');
        $cached = new CachedVersionResolver($inner, ttl: 300, useApcu: false);

        // Act
        $version = $cached->resolve();

        // Assert
        $this->assertSame('v1.0.0', $version, 'Should return the inner resolver version');
        $this->assertSame(1, $inner->callCount, 'Inner resolver should be called once');
    }

    public function test_resolve_returns_cached_value_on_subsequent_calls(): void
    {
        // Arrange
        $inner = $this->makeCountingResolver('v1.0.0');
        $cached = new CachedVersionResolver($inner, ttl: 300, useApcu: false);

        // Act
        $cached->resolve();
        $cached->resolve();
        $cached->resolve();

        // Assert
        $this->assertSame(1, $inner->callCount, 'Inner resolver should be called only once within TTL');
    }

    public function test_resolve_returns_null_when_inner_returns_null(): void
    {
        // Arrange
        $inner = $this->makeCountingResolver(null);
        $cached = new CachedVersionResolver($inner, ttl: 300, useApcu: false);

        // Act
        $version = $cached->resolve();

        // Assert
        $this->assertNull($version, 'Should return null when inner returns null');
    }

    public function test_null_result_is_not_cached(): void
    {
        // Arrange
        $inner = $this->makeCountingResolver(null);
        $cached = new CachedVersionResolver($inner, ttl: 300, useApcu: false);

        // Act
        $cached->resolve();
        $cached->resolve();

        // Assert
        $this->assertSame(2, $inner->callCount, 'null result should not be cached — inner called each time');
    }

    // -------------------------------------------------------------------------
    // clearCache
    // -------------------------------------------------------------------------

    public function test_clear_cache_forces_re_resolve(): void
    {
        // Arrange
        $inner = $this->makeCountingResolver('v1.0.0');
        $cached = new CachedVersionResolver($inner, ttl: 300, useApcu: false);
        $cached->resolve(); // populates cache

        // Act
        $cached->clearCache();
        $cached->resolve();

        // Assert
        $this->assertSame(2, $inner->callCount, 'clearCache should force inner to be called again');
    }
}

/**
 * @internal Test helper: VersionResolver that counts resolve() calls.
 */
class CountableResolver implements VersionResolverInterface
{
    public int $callCount = 0;

    public function __construct(
        private readonly ?string $version,
    ) {}

    public function resolve(): ?string
    {
        $this->callCount++;

        return $this->version;
    }
}
