<?php

namespace Kura\Concerns;

/**
 * All WHERE-condition builder methods.
 *
 * Expects the using class to expose these protected members:
 *   array  $wheres
 *   string $table
 *   CacheRepository $repository
 *   StoreInterface  $store
 */
trait BuildsWhereConditions
{
    // =========================================================================
    // where / orWhere / whereNot / orWhereNot / whereNested
    // =========================================================================

    /**
     * AND condition.
     *
     * Closure: ->where(fn($q) => $q->where(...)->orWhere(...))
     *   Creates a nested AND sub-group.
     *
     * String: ->where('col', 'val')  or  ->where('col', '>=', 1)
     *         ->where('flags', '&', 0b0100)   ← bitwise AND truthy check
     */
    public function where(string|\Closure $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->addWhere('and', false, $column, $operator, $value);
    }

    /** OR condition — same overloads as where(). */
    public function orWhere(string|\Closure $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->addWhere('or', false, $column, $operator, $value);
    }

    /**
     * AND WHERE NOT condition.
     *
     * Closure: negates the entire nested sub-group.
     * String:  negates the individual condition.
     */
    public function whereNot(string|\Closure $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->addWhere('and', true, $column, $operator, $value);
    }

    public function orWhereNot(string|\Closure $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->addWhere('or', true, $column, $operator, $value);
    }

    /**
     * Explicit nested group — identical to where(Closure) but matches Laravel's
     * whereNested() naming for callers that prefer it.
     */
    public function whereNested(\Closure $callback, string $boolean = 'and'): static
    {
        $nested = new static($this->table, $this->repository);
        $callback($nested);
        $this->wheres[] = [
            'type' => 'nested',
            'boolean' => $boolean,
            'wheres' => $nested->wheres,
        ];

        return $this;
    }

    // =========================================================================
    // whereIn / whereNotIn  (+ or variants)
    // =========================================================================

    /**
     * AND WHERE column IN (values).
     *
     * $values may be:
     *   - array    : used directly
     *   - Closure  : called at query-execution time (lazy subquery)
     *                must return array|iterable
     *                e.g. fn() => $otherRepo->where('active', true)->pluck('id')
     */
    /** @param array<mixed>|\Closure $values */
    public function whereIn(string $column, array|\Closure $values): static
    {
        return $this->addWhereIn('and', false, $column, $values);
    }

    /**
     * AND WHERE column NOT IN (values). Same $values overloads as whereIn().
     *
     * @param  array<mixed>|\Closure  $values
     */
    public function whereNotIn(string $column, array|\Closure $values): static
    {
        return $this->addWhereIn('and', true, $column, $values);
    }

    /**
     * OR WHERE column IN (values).
     *
     * @param  array<mixed>|\Closure  $values
     */
    public function orWhereIn(string $column, array|\Closure $values): static
    {
        return $this->addWhereIn('or', false, $column, $values);
    }

    /**
     * OR WHERE column NOT IN (values).
     *
     * @param  array<mixed>|\Closure  $values
     */
    public function orWhereNotIn(string $column, array|\Closure $values): static
    {
        return $this->addWhereIn('or', true, $column, $values);
    }

    // =========================================================================
    // whereRowValuesIn / whereRowValuesNotIn  (+ or variants)
    // =========================================================================
    // Kura extension — not available in Laravel's Query\Builder.
    //
    // Equivalent to MySQL's ROW constructor syntax:
    //   SELECT * FROM t WHERE (col1, col2) IN ((v1a, v2a), (v1b, v2b))
    //
    // When a composite index exists on the specified columns, Kura resolves
    // matching IDs via O(n) hashmap lookups (n = number of tuples) instead of
    // a full table scan.

    /**
     * AND WHERE (columns...) IN (tuples...).
     *
     * @param  list<string>  $columns  e.g. ['user_id', 'item_id']
     * @param  list<list<mixed>>  $tuples  e.g. [[1, 10], [2, 20]]
     */
    public function whereRowValuesIn(array $columns, array $tuples): static
    {
        return $this->addWhereRowValuesIn('and', false, $columns, $tuples);
    }

    /**
     * AND WHERE (columns...) NOT IN (tuples...).
     *
     * @param  list<string>  $columns
     * @param  list<list<mixed>>  $tuples
     */
    public function whereRowValuesNotIn(array $columns, array $tuples): static
    {
        return $this->addWhereRowValuesIn('and', true, $columns, $tuples);
    }

    /**
     * OR WHERE (columns...) IN (tuples...).
     *
     * @param  list<string>  $columns
     * @param  list<list<mixed>>  $tuples
     */
    public function orWhereRowValuesIn(array $columns, array $tuples): static
    {
        return $this->addWhereRowValuesIn('or', false, $columns, $tuples);
    }

    /**
     * OR WHERE (columns...) NOT IN (tuples...).
     *
     * @param  list<string>  $columns
     * @param  list<list<mixed>>  $tuples
     */
    public function orWhereRowValuesNotIn(array $columns, array $tuples): static
    {
        return $this->addWhereRowValuesIn('or', true, $columns, $tuples);
    }

    // =========================================================================
    // whereNull / whereNotNull  (+ or variants)
    // =========================================================================

    public function whereNull(string $column): static
    {
        return $this->addWhereNull('and', false, $column);
    }

    public function whereNotNull(string $column): static
    {
        return $this->addWhereNull('and', true, $column);
    }

    public function orWhereNull(string $column): static
    {
        return $this->addWhereNull('or', false, $column);
    }

    public function orWhereNotNull(string $column): static
    {
        return $this->addWhereNull('or', true, $column);
    }

    // =========================================================================
    // whereBetween / whereNotBetween  (+ or variants)
    // =========================================================================

    /**
     * AND WHERE column BETWEEN min AND max (inclusive).
     *
     * @param  iterable<mixed>  $values  [min, max]
     */
    public function whereBetween(string $column, iterable $values, bool $not = false): static
    {
        return $this->addWhereBetween('and', $not, $column, $values);
    }

    /**
     * AND WHERE column NOT BETWEEN min AND max.
     *
     * @param  iterable<mixed>  $values
     */
    public function whereNotBetween(string $column, iterable $values): static
    {
        return $this->addWhereBetween('and', true, $column, $values);
    }

    /**
     * OR WHERE column BETWEEN min AND max.
     *
     * @param  iterable<mixed>  $values
     */
    public function orWhereBetween(string $column, iterable $values): static
    {
        return $this->addWhereBetween('or', false, $column, $values);
    }

    /**
     * OR WHERE column NOT BETWEEN min AND max.
     *
     * @param  iterable<mixed>  $values
     */
    public function orWhereNotBetween(string $column, iterable $values): static
    {
        return $this->addWhereBetween('or', true, $column, $values);
    }

    // =========================================================================
    // whereBetweenColumns — col BETWEEN min_col AND max_col
    // =========================================================================

    /**
     * AND WHERE column BETWEEN min_column AND max_column.
     *
     * All three column values come from the same record.
     * e.g. ->whereBetweenColumns('price', ['min_price', 'max_price'])
     *
     * @param  array{string, string}  $values  [min_column, max_column]
     */
    public function whereBetweenColumns(string $column, array $values, bool $not = false): static
    {
        return $this->addWhereBetweenColumns('and', $not, $column, $values);
    }

    /**
     * AND WHERE column NOT BETWEEN min_column AND max_column.
     *
     * @param  array{string, string}  $values
     */
    public function whereNotBetweenColumns(string $column, array $values): static
    {
        return $this->addWhereBetweenColumns('and', true, $column, $values);
    }

    /**
     * OR WHERE column BETWEEN min_column AND max_column.
     *
     * @param  array{string, string}  $values
     */
    public function orWhereBetweenColumns(string $column, array $values): static
    {
        return $this->addWhereBetweenColumns('or', false, $column, $values);
    }

    /**
     * OR WHERE column NOT BETWEEN min_column AND max_column.
     *
     * @param  array{string, string}  $values
     */
    public function orWhereNotBetweenColumns(string $column, array $values): static
    {
        return $this->addWhereBetweenColumns('or', true, $column, $values);
    }

    // =========================================================================
    // whereValueBetween — scalar BETWEEN min_col AND max_col
    // =========================================================================

    /**
     * AND WHERE scalar_value BETWEEN min_column AND max_column.
     *
     * The scalar is tested against two record columns (the "range" is in the record).
     * e.g. ->whereValueBetween(50, ['min_age', 'max_age'])
     *       -> keep records where min_age <= 50 <= max_age
     *
     * @param  array{string, string}  $columns  [min_column, max_column]
     */
    public function whereValueBetween(mixed $value, array $columns, bool $not = false): static
    {
        return $this->addWhereValueBetween('and', $not, $value, $columns);
    }

    /**
     * AND WHERE scalar_value NOT BETWEEN min_column AND max_column.
     *
     * @param  array{string, string}  $columns
     */
    public function whereValueNotBetween(mixed $value, array $columns): static
    {
        return $this->addWhereValueBetween('and', true, $value, $columns);
    }

    /**
     * OR WHERE scalar_value BETWEEN min_column AND max_column.
     *
     * @param  array{string, string}  $columns
     */
    public function orWhereValueBetween(mixed $value, array $columns): static
    {
        return $this->addWhereValueBetween('or', false, $value, $columns);
    }

    /**
     * OR WHERE scalar_value NOT BETWEEN min_column AND max_column.
     *
     * @param  array{string, string}  $columns
     */
    public function orWhereValueNotBetween(mixed $value, array $columns): static
    {
        return $this->addWhereValueBetween('or', true, $value, $columns);
    }

    // =========================================================================
    // whereLike / whereNotLike  (+ or variants)
    // =========================================================================

    /**
     * AND WHERE column LIKE value.
     *
     * @param  bool  $caseSensitive  When false (default) the match is case-insensitive.
     */
    public function whereLike(string $column, string $value, bool $caseSensitive = false): static
    {
        return $this->addWhereLike('and', false, $column, $value, $caseSensitive);
    }

    public function whereNotLike(string $column, string $value, bool $caseSensitive = false): static
    {
        return $this->addWhereLike('and', true, $column, $value, $caseSensitive);
    }

    public function orWhereLike(string $column, string $value, bool $caseSensitive = false): static
    {
        return $this->addWhereLike('or', false, $column, $value, $caseSensitive);
    }

    public function orWhereNotLike(string $column, string $value, bool $caseSensitive = false): static
    {
        return $this->addWhereLike('or', true, $column, $value, $caseSensitive);
    }

    // =========================================================================
    // whereColumn / orWhereColumn  — compare two columns of the same record
    // =========================================================================

    /**
     * AND WHERE first_column OP second_column.
     *
     *   ->whereColumn('updated_at', '>', 'created_at')
     *   ->whereColumn('first_name', 'last_name')   <- shorthand for =
     */
    public function whereColumn(string $first, string $operator = '=', ?string $second = null): static
    {
        return $this->addWhereColumn('and', $first, $operator, $second);
    }

    public function orWhereColumn(string $first, string $operator = '=', ?string $second = null): static
    {
        return $this->addWhereColumn('or', $first, $operator, $second);
    }

    // =========================================================================
    // whereAll / whereAny / whereNone  (+ or variants)
    // =========================================================================

    /**
     * AND WHERE all columns match the condition (AND between columns).
     *
     *   ->whereAll(['first_name', 'last_name'], 'LIKE', '%Smith%')
     *
     * @param  list<string>  $columns
     */
    public function whereAll(array $columns, mixed $operator = '=', mixed $value = null): static
    {
        [$value, $operator] = $this->normaliseOperatorValue($operator, $value);

        return $this->where(function ($q) use ($columns, $operator, $value) {
            foreach ($columns as $column) {
                $q->where($column, $operator, $value);
            }
        });
    }

    /** @param list<string> $columns */
    public function orWhereAll(array $columns, mixed $operator = '=', mixed $value = null): static
    {
        [$value, $operator] = $this->normaliseOperatorValue($operator, $value);

        return $this->orWhere(function ($q) use ($columns, $operator, $value) {
            foreach ($columns as $column) {
                $q->where($column, $operator, $value);
            }
        });
    }

    /**
     * AND WHERE any column matches the condition (OR between columns).
     *
     *   ->whereAny(['email', 'username'], '=', 'alice')
     *
     * @param  list<string>  $columns
     */
    public function whereAny(array $columns, mixed $operator = '=', mixed $value = null): static
    {
        [$value, $operator] = $this->normaliseOperatorValue($operator, $value);

        return $this->where(function ($q) use ($columns, $operator, $value) {
            foreach ($columns as $column) {
                $q->orWhere($column, $operator, $value);
            }
        });
    }

    /** @param list<string> $columns */
    public function orWhereAny(array $columns, mixed $operator = '=', mixed $value = null): static
    {
        [$value, $operator] = $this->normaliseOperatorValue($operator, $value);

        return $this->orWhere(function ($q) use ($columns, $operator, $value) {
            foreach ($columns as $column) {
                $q->orWhere($column, $operator, $value);
            }
        });
    }

    /**
     * AND WHERE none of the columns match the condition.
     * Equivalent to NOT (col1 = val OR col2 = val OR ...).
     *
     * @param  list<string>  $columns
     */
    public function whereNone(array $columns, mixed $operator = '=', mixed $value = null): static
    {
        [$value, $operator] = $this->normaliseOperatorValue($operator, $value);

        $nested = new static($this->table, $this->repository);
        foreach ($columns as $column) {
            $nested->orWhere($column, $operator, $value);
        }

        $this->wheres[] = [
            'type' => 'nested',
            'boolean' => 'and',
            'wheres' => $nested->wheres,
            'negate' => true,
        ];

        return $this;
    }

    /** @param list<string> $columns */
    public function orWhereNone(array $columns, mixed $operator = '=', mixed $value = null): static
    {
        [$value, $operator] = $this->normaliseOperatorValue($operator, $value);

        $nested = new static($this->table, $this->repository);
        foreach ($columns as $column) {
            $nested->orWhere($column, $operator, $value);
        }

        $this->wheres[] = [
            'type' => 'nested',
            'boolean' => 'or',
            'wheres' => $nested->wheres,
            'negate' => true,
        ];

        return $this;
    }

    // =========================================================================
    // whereNullSafeEquals / orWhereNullSafeEquals
    // =========================================================================

    /**
     * AND WHERE column <=> value  (null-safe equality).
     *
     * In PHP, null === null is already true, so this behaves the same as
     * where(column, '=', value) for non-null values, but correctly handles
     * null <=> null comparisons that SQL's = operator would miss.
     */
    public function whereNullSafeEquals(string $column, mixed $value): static
    {
        return $this->addWhereNullSafe('and', $column, $value);
    }

    public function orWhereNullSafeEquals(string $column, mixed $value): static
    {
        return $this->addWhereNullSafe('or', $column, $value);
    }

    // =========================================================================
    // whereExists — predicate-based existence check
    // =========================================================================

    /**
     * AND condition using a record predicate.
     *
     * Note: unlike SQL EXISTS (which runs a subquery), this operates on the
     * individual record. The Closure receives the full record array and must
     * return bool — semantically equivalent to whereFilter().
     *
     *   ->whereExists(fn($r) => $r['stock'] > 0)
     */
    public function whereExists(\Closure $callback): static
    {
        return $this->whereFilter($callback);
    }

    public function orWhereExists(\Closure $callback): static
    {
        return $this->orWhereFilter($callback);
    }

    /** AND condition where the predicate must NOT match. */
    public function whereNotExists(\Closure $callback): static
    {
        return $this->whereFilter(fn ($r) => ! $callback($r));
    }

    public function orWhereNotExists(\Closure $callback): static
    {
        return $this->orWhereFilter(fn ($r) => ! $callback($r));
    }

    // =========================================================================
    // whereFilter / orWhereFilter  (our extension — raw PHP predicate)
    // =========================================================================

    /**
     * AND condition using an arbitrary PHP closure.
     * The closure receives the full record array and must return bool.
     *
     *   ->whereFilter(fn($r) => ($r['flags'] & 0b110) === 0b110)
     *   ->whereFilter(fn($r) => strlen($r['code']) === 3)
     */
    public function whereFilter(\Closure $callback): static
    {
        $this->wheres[] = ['type' => 'filter', 'boolean' => 'and', 'callback' => $callback];

        return $this;
    }

    public function orWhereFilter(\Closure $callback): static
    {
        $this->wheres[] = ['type' => 'filter', 'boolean' => 'or', 'callback' => $callback];

        return $this;
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function addWhere(
        string $boolean,
        bool $negate,
        string|\Closure $column,
        mixed $operator,
        mixed $value,
    ): static {
        if (! is_string($column)) {
            $nested = new static($this->table, $this->repository);
            $column($nested);
            $item = ['type' => 'nested', 'boolean' => $boolean, 'wheres' => $nested->wheres];
            if ($negate) {
                $item['negate'] = true;
            }
            $this->wheres[] = $item;

            return $this;
        }

        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $item = ['type' => 'basic', 'boolean' => $boolean, 'column' => $column, 'operator' => $operator, 'value' => $value];
        if ($negate) {
            $item['negate'] = true;
        }
        $this->wheres[] = $item;

        return $this;
    }

    /** @param array<mixed>|\Closure $values */
    private function addWhereIn(string $boolean, bool $not, string $column, array|\Closure $values): static
    {
        $this->wheres[] = [
            'type' => 'in',
            'boolean' => $boolean,
            'column' => $column,
            'values' => $values,
            'not' => $not,
        ];

        return $this;
    }

    /**
     * @param  list<string>  $columns
     * @param  list<list<mixed>>  $tuples
     */
    private function addWhereRowValuesIn(string $boolean, bool $not, array $columns, array $tuples): static
    {
        $this->wheres[] = [
            'type' => 'rowValuesIn',
            'boolean' => $boolean,
            'columns' => $columns,
            'tuples' => $tuples,
            'not' => $not,
        ];

        return $this;
    }

    private function addWhereNull(string $boolean, bool $not, string $column): static
    {
        $this->wheres[] = ['type' => 'null', 'boolean' => $boolean, 'column' => $column, 'not' => $not];

        return $this;
    }

    /** @param iterable<mixed> $values */
    private function addWhereBetween(string $boolean, bool $not, string $column, iterable $values): static
    {
        $this->wheres[] = [
            'type' => 'between',
            'boolean' => $boolean,
            'column' => $column,
            'values' => $this->twoValues($values),
            'not' => $not,
        ];

        return $this;
    }

    /** @param array{string, string} $values */
    private function addWhereBetweenColumns(string $boolean, bool $not, string $column, array $values): static
    {
        $this->wheres[] = [
            'type' => 'betweenColumns',
            'boolean' => $boolean,
            'column' => $column,
            'values' => [$values[0], $values[1]],
            'not' => $not,
        ];

        return $this;
    }

    /** @param array{string, string} $columns */
    private function addWhereValueBetween(string $boolean, bool $not, mixed $value, array $columns): static
    {
        $this->wheres[] = [
            'type' => 'valueBetween',
            'boolean' => $boolean,
            'value' => $value,
            'columns' => [$columns[0], $columns[1]],
            'not' => $not,
        ];

        return $this;
    }

    private function addWhereColumn(string $boolean, string $first, string $operator, ?string $second): static
    {
        if ($second === null) {
            [$second, $operator] = [$operator, '='];
        }

        $this->wheres[] = [
            'type' => 'column',
            'boolean' => $boolean,
            'first' => $first,
            'operator' => $operator,
            'second' => $second,
        ];

        return $this;
    }

    private function addWhereNullSafe(string $boolean, string $column, mixed $value): static
    {
        $this->wheres[] = [
            'type' => 'nullsafe',
            'boolean' => $boolean,
            'column' => $column,
            'value' => $value,
        ];

        return $this;
    }

    private function addWhereLike(string $boolean, bool $not, string $column, string $value, bool $caseSensitive): static
    {
        $this->wheres[] = [
            'type' => 'like',
            'boolean' => $boolean,
            'column' => $column,
            'value' => $value,
            'caseSensitive' => $caseSensitive,
            'not' => $not,
        ];

        return $this;
    }

    /**
     * Normalise the two-argument shorthand: when $value is null,
     * treat $operator as the value and default the operator to '='.
     *
     * @return array{mixed, string} [$value, $operator]
     */
    private function normaliseOperatorValue(mixed $operator, mixed $value): array
    {
        if ($value === null) {
            return [$operator, '='];
        }

        return [$value, $operator];
    }

    /**
     * Extract the first two values from any iterable for whereBetween.
     *
     * @param  iterable<mixed>  $values
     * @return array{mixed, mixed}
     */
    private function twoValues(iterable $values): array
    {
        $arr = is_array($values)
            ? array_values($values)
            : iterator_to_array($values, preserve_keys: false);

        return [$arr[0], $arr[1]];
    }
}
