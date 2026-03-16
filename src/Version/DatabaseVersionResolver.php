<?php

namespace Kura\Version;

use Illuminate\Support\Facades\DB;
use Kura\Contracts\VersionResolverInterface;

/**
 * Resolves the active version from a database table.
 *
 * Table structure (example: reference_versions):
 *   id          INT PRIMARY KEY
 *   version     VARCHAR       — e.g. "v2.1.0"
 *   activated_at    DATETIME      — when this version becomes active
 *
 * Resolution rule:
 *   SELECT version FROM {table} WHERE activated_at <= NOW()
 *   ORDER BY activated_at DESC LIMIT 1
 */
final class DatabaseVersionResolver implements VersionResolverInterface
{
    public function __construct(
        private readonly string $table = 'reference_versions',
        private readonly string $versionColumn = 'version',
        private readonly string $startAtColumn = 'activated_at',
    ) {}

    public function resolve(): ?string
    {
        /** @var object{version: string}|null $row */
        $row = DB::table($this->table)
            ->where($this->startAtColumn, '<=', now())
            ->orderByDesc($this->startAtColumn)
            ->first([$this->versionColumn]);

        return $row?->{$this->versionColumn};
    }
}
