<?php

namespace Kura\Loader;

interface LoaderInterface
{
    /**
     * Yield all records as associative arrays.
     * Must use a generator to keep memory usage low.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function load(): \Generator;

    /**
     * Column name to type mapping.
     *
     * @return array<string, string> column => type ('int', 'string', 'float', 'bool')
     */
    public function columns(): array;

    /**
     * Index definitions.
     *
     * @return list<array{columns: list<string>, unique: bool}>
     *
     * Example:
     *   [
     *       ['columns' => ['country'], 'unique' => false],
     *       ['columns' => ['email'], 'unique' => true],
     *       ['columns' => ['country', 'category'], 'unique' => false],
     *   ]
     *
     * Composite index columns order:
     *   first = lower cardinality column
     *   Single-column indexes are auto-created for each column in a composite index.
     */
    public function indexes(): array;

    /**
     * Primary key column name.
     */
    public function primaryKey(): string;

    /**
     * Cache key version identifier.
     */
    public function version(): string|int|\Stringable;
}
