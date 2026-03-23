<?php

namespace Kura\Tests\Support;

use Kura\Loader\LoaderInterface;

/**
 * Simple in-memory LoaderInterface implementation for tests.
 */
final class InMemoryLoader implements LoaderInterface
{
    /**
     * @param  list<array<string, mixed>>  $records
     * @param  array<string, string>  $columns
     * @param  list<array{columns: list<string>, unique: bool}>  $indexes
     */
    public function __construct(
        private readonly array $records,
        private readonly array $columns = [],
        private readonly array $indexes = [],
        private readonly string $version = 'v1',
        private readonly string $primaryKeyColumn = 'id',
    ) {}

    public function load(): \Generator
    {
        yield from $this->records;
    }

    public function columns(): array
    {
        return $this->columns;
    }

    public function indexes(): array
    {
        return $this->indexes;
    }

    public function primaryKey(): string
    {
        return $this->primaryKeyColumn;
    }

    public function version(): string
    {
        return $this->version;
    }
}
