<?php

namespace Kura\Version;

use Kura\Contracts\VersionResolverInterface;

/**
 * Decorator that caches the resolved version in APCu.
 *
 * Wraps any VersionResolverInterface and avoids hitting DB/CSV on every request.
 * Default TTL is 300 seconds (5 minutes).
 */
final class CachedVersionResolver implements VersionResolverInterface
{
    private ?string $cachedVersion = null;

    private ?float $cachedAt = null;

    public function __construct(
        private readonly VersionResolverInterface $inner,
        private readonly int $ttl = 300,
        private readonly string $cacheKey = 'kura:reference_version',
        private readonly bool $useApcu = true,
    ) {}

    public function resolve(): ?string
    {
        // PHP var cache (within same request)
        if ($this->cachedVersion !== null && $this->cachedAt !== null) {
            if ((microtime(true) - $this->cachedAt) < $this->ttl) {
                return $this->cachedVersion;
            }
        }

        // APCu cache (across requests)
        if ($this->useApcu) {
            /** @var string|false $cached */
            $cached = apcu_fetch($this->cacheKey, $success);

            if ($success && is_string($cached)) {
                $this->cachedVersion = $cached;
                $this->cachedAt = microtime(true);

                return $cached;
            }
        }

        $version = $this->inner->resolve();

        if ($version !== null) {
            $this->cachedVersion = $version;
            $this->cachedAt = microtime(true);

            if ($this->useApcu) {
                apcu_store($this->cacheKey, $version, $this->ttl);
            }
        }

        return $version;
    }

    /**
     * Force clear the cached version (both PHP var and APCu).
     */
    public function clearCache(): void
    {
        $this->cachedVersion = null;
        $this->cachedAt = null;

        if ($this->useApcu) {
            apcu_delete($this->cacheKey);
        }
    }
}
