<?php

namespace Kura\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

/**
 * Contract for the in-memory reference-data query builder.
 *
 * All fluent builder methods return `static` for method chaining.
 * Execution methods return concrete result types.
 *
 * Implement this interface to create test doubles, decorators, or
 * alternative backends without touching call-sites.
 */
interface ReferenceQueryBuilderInterface
{
    // =========================================================================
    // WHERE conditions
    // =========================================================================

    public function where(string|\Closure $column, mixed $operator = null, mixed $value = null): static;

    public function orWhere(string|\Closure $column, mixed $operator = null, mixed $value = null): static;

    public function whereNot(string|\Closure $column, mixed $operator = null, mixed $value = null): static;

    public function orWhereNot(string|\Closure $column, mixed $operator = null, mixed $value = null): static;

    public function whereNested(\Closure $callback, string $boolean = 'and'): static;

    /** @param array<mixed>|\Closure $values */
    public function whereIn(string $column, array|\Closure $values): static;

    /** @param array<mixed>|\Closure $values */
    public function whereNotIn(string $column, array|\Closure $values): static;

    /** @param array<mixed>|\Closure $values */
    public function orWhereIn(string $column, array|\Closure $values): static;

    /** @param array<mixed>|\Closure $values */
    public function orWhereNotIn(string $column, array|\Closure $values): static;

    public function whereNull(string $column): static;

    public function whereNotNull(string $column): static;

    public function orWhereNull(string $column): static;

    public function orWhereNotNull(string $column): static;

    /** @param iterable<mixed> $values */
    public function whereBetween(string $column, iterable $values, bool $not = false): static;

    /** @param iterable<mixed> $values */
    public function whereNotBetween(string $column, iterable $values): static;

    /** @param iterable<mixed> $values */
    public function orWhereBetween(string $column, iterable $values): static;

    /** @param iterable<mixed> $values */
    public function orWhereNotBetween(string $column, iterable $values): static;

    /** @param array{string, string} $values */
    public function whereBetweenColumns(string $column, array $values, bool $not = false): static;

    /** @param array{string, string} $values */
    public function whereNotBetweenColumns(string $column, array $values): static;

    /** @param array{string, string} $values */
    public function orWhereBetweenColumns(string $column, array $values): static;

    /** @param array{string, string} $values */
    public function orWhereNotBetweenColumns(string $column, array $values): static;

    /** @param array{string, string} $columns */
    public function whereValueBetween(mixed $value, array $columns, bool $not = false): static;

    /** @param array{string, string} $columns */
    public function whereValueNotBetween(mixed $value, array $columns): static;

    /** @param array{string, string} $columns */
    public function orWhereValueBetween(mixed $value, array $columns): static;

    /** @param array{string, string} $columns */
    public function orWhereValueNotBetween(mixed $value, array $columns): static;

    public function whereLike(string $column, string $value, bool $caseSensitive = false): static;

    public function whereNotLike(string $column, string $value, bool $caseSensitive = false): static;

    public function orWhereLike(string $column, string $value, bool $caseSensitive = false): static;

    public function orWhereNotLike(string $column, string $value, bool $caseSensitive = false): static;

    public function whereColumn(string $first, string $operator = '=', ?string $second = null): static;

    public function orWhereColumn(string $first, string $operator = '=', ?string $second = null): static;

    /** @param list<string> $columns */
    public function whereAll(array $columns, mixed $operator = '=', mixed $value = null): static;

    /** @param list<string> $columns */
    public function orWhereAll(array $columns, mixed $operator = '=', mixed $value = null): static;

    /** @param list<string> $columns */
    public function whereAny(array $columns, mixed $operator = '=', mixed $value = null): static;

    /** @param list<string> $columns */
    public function orWhereAny(array $columns, mixed $operator = '=', mixed $value = null): static;

    /** @param list<string> $columns */
    public function whereNone(array $columns, mixed $operator = '=', mixed $value = null): static;

    /** @param list<string> $columns */
    public function orWhereNone(array $columns, mixed $operator = '=', mixed $value = null): static;

    public function whereNullSafeEquals(string $column, mixed $value): static;

    public function orWhereNullSafeEquals(string $column, mixed $value): static;

    public function whereExists(\Closure $callback): static;

    public function orWhereExists(\Closure $callback): static;

    public function whereNotExists(\Closure $callback): static;

    public function orWhereNotExists(\Closure $callback): static;

    public function whereFilter(\Closure $callback): static;

    // Kura extension — ROW constructor IN
    // Equivalent to: WHERE (col1, col2) IN ((v1a, v2a), (v1b, v2b))

    /**
     * @param  list<string>  $columns
     * @param  list<list<mixed>>  $tuples
     */
    public function whereRowValuesIn(array $columns, array $tuples): static;

    /**
     * @param  list<string>  $columns
     * @param  list<list<mixed>>  $tuples
     */
    public function whereRowValuesNotIn(array $columns, array $tuples): static;

    /**
     * @param  list<string>  $columns
     * @param  list<list<mixed>>  $tuples
     */
    public function orWhereRowValuesIn(array $columns, array $tuples): static;

    /**
     * @param  list<string>  $columns
     * @param  list<list<mixed>>  $tuples
     */
    public function orWhereRowValuesNotIn(array $columns, array $tuples): static;

    public function orWhereFilter(\Closure $callback): static;

    // =========================================================================
    // ORDER BY
    // =========================================================================

    public function orderBy(string $column, string $direction = 'asc'): static;

    public function orderByDesc(string $column): static;

    public function latest(string $column = 'created_at'): static;

    public function oldest(string $column = 'created_at'): static;

    public function inRandomOrder(mixed $seed = ''): static;

    public function reorder(?string $column = null, string $direction = 'asc'): static;

    public function reorderDesc(?string $column = null): static;

    // =========================================================================
    // LIMIT / OFFSET / PAGINATION
    // =========================================================================

    public function limit(int $value): static;

    public function offset(int $value): static;

    public function take(int $value): static;

    public function skip(int $value): static;

    public function forPage(int $page, int $perPage = 15): static;

    public function forPageBeforeId(int $perPage = 15, int|string|null $lastId = null, string $column = 'id'): static;

    public function forPageAfterId(int $perPage = 15, int|string|null $lastId = null, string $column = 'id'): static;

    public function getLimit(): ?int;

    public function getOffset(): ?int;

    // =========================================================================
    // Execution / retrieval
    // =========================================================================

    /** @return \Generator<int, array<string, mixed>> */
    public function cursor(): \Generator;

    /** @return list<array<string, mixed>> */
    public function get(): array;

    /** @return array<string, mixed>|null */
    public function first(): ?array;

    /** @return array<string, mixed> */
    public function sole(): array;

    public function soleValue(string $column): mixed;

    /** @return array<string, mixed>|null */
    public function find(int|string $id): ?array;

    public function findOr(int|string $id, \Closure $callback): mixed;

    public function value(string $column): mixed;

    public function implode(string $column, string $glue = ''): string;

    // =========================================================================
    // count / pluck
    // =========================================================================

    public function count(): int;

    /** @return array<array-key, mixed> */
    public function pluck(string $column, ?string $key = null): array;

    // =========================================================================
    // Existence
    // =========================================================================

    public function exists(): bool;

    public function doesntExist(): bool;

    public function existsOr(\Closure $callback): mixed;

    public function doesntExistOr(\Closure $callback): mixed;

    // =========================================================================
    // Aggregates
    // =========================================================================

    public function min(string $column): mixed;

    public function max(string $column): mixed;

    public function sum(string $column): int|float;

    public function avg(string $column): int|float|null;

    public function average(string $column): int|float|null;

    // =========================================================================
    // Pagination
    // =========================================================================

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function paginate(int $perPage = 15, string $pageName = 'page', ?int $page = null): LengthAwarePaginator;

    /** @return Paginator<int, array<string, mixed>> */
    public function simplePaginate(int $perPage = 15, string $pageName = 'page', ?int $page = null): Paginator;

    // =========================================================================
    // Cloning / utility
    // =========================================================================

    public function clone(): static;

    /** @param list<string> $properties */
    public function cloneWithout(array $properties): static;

    public function newQuery(): static;

    public function dump(): static;

    public function dd(): never;
}
