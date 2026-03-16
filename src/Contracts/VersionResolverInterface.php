<?php

namespace Kura\Contracts;

/**
 * Resolves the active reference data version.
 *
 * Implementations may resolve from DB, CSV, config, etc.
 * The resolved version is used as the APCu key segment and
 * passed to Loaders to filter records.
 */
interface VersionResolverInterface
{
    /**
     * Resolve the currently active version string.
     *
     * @return string|null null if no version is available
     */
    public function resolve(): ?string;
}
