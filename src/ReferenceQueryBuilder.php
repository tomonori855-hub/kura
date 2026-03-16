<?php

namespace Kura;

use Kura\Concerns\BuildsOrderAndPagination;
use Kura\Concerns\BuildsWhereConditions;
use Kura\Concerns\ExecutesQueries;
use Kura\Contracts\ReferenceQueryBuilderInterface;

/**
 * Fluent query builder over APCu-cached reference data.
 *
 * @phpstan-consistent-constructor
 *
 * Method signatures follow Illuminate\Database\Query\Builder conventions.
 * Unsupported DB-specific features (raw SQL, JSON, full-text, vector, writes)
 * are intentionally omitted — see docs/laravel-builder-coverage.md.
 *
 * Where condition tree
 * --------------------
 * Each element in $wheres carries:
 *   boolean : 'and' | 'or'
 *   negate  : bool  (optional, defaults false — set by whereNot/whereNone)
 *   type    : one of the types below
 *
 * Types:
 *   basic          {column, operator, value}
 *                  Operators: =  !=  <>  >  >=  <  <=  like  not like
 *                  Bitwise  : &  |  ^  <<  >>  &~  → (actual OP value) !== 0
 *                             !& (extension)       → (actual  &  value) === 0
 *   in             {column, values: array, not: bool, valueSet: array}
 *   null           {column, not: bool}
 *   between        {column, values: [min, max], not: bool}
 *   betweenColumns {column, values: [min_col, max_col], not: bool}
 *   valueBetween   {value: mixed, columns: [min_col, max_col], not: bool}
 *   like           {column, value: string, caseSensitive: bool, not: bool}
 *   column         {first, operator, second}  — compare two columns
 *   nullsafe       {column, value}            — null === null is true
 *   filter         {callback: Closure(array): bool}  — raw PHP predicate
 *   nested         {wheres: array, [negate: bool]}
 */
class ReferenceQueryBuilder implements ReferenceQueryBuilderInterface
{
    use BuildsOrderAndPagination;
    use BuildsWhereConditions;
    use ExecutesQueries;

    /** @var list<array<string, mixed>> */
    protected array $wheres = [];

    /** @var list<array{column: string, direction: string}> */
    protected array $orders = [];

    protected ?int $limit = null;

    protected ?int $offset = null;

    protected bool $randomOrder = false;

    protected string $primaryKey;

    protected CacheProcessor $processor;

    public function __construct(
        protected readonly string $table,
        protected readonly CacheRepository $repository,
        ?CacheProcessor $processor = null,
    ) {
        $this->primaryKey = $repository->primaryKey();
        $this->processor = $processor ?? new CacheProcessor($repository, $repository->store());
    }

    // =========================================================================
    // Cloning / utility
    // =========================================================================

    public function clone(): static
    {
        return clone $this;
    }

    /**
     * Clone this builder with the specified properties reset to their defaults.
     *
     * Recognised property names: 'wheres', 'orders', 'limit', 'offset', 'randomOrder'.
     */
    /** @param list<string> $properties */
    public function cloneWithout(array $properties): static
    {
        $clone = clone $this;

        foreach ($properties as $property) {
            $clone->$property = match ($property) {
                'wheres' => [],
                'orders' => [],
                'limit', 'offset' => null,
                'randomOrder' => false,
                default => throw new \InvalidArgumentException("Cannot reset unknown property: {$property}"),
            };
        }

        return $clone;
    }

    /** Return a fresh builder for the same table / repository. */
    public function newQuery(): static
    {
        return new static($this->table, $this->repository, $this->processor);
    }

    // =========================================================================
    // Subquery resolution (internal)
    // =========================================================================

    /**
     * Resolve Closure subqueries and pre-build valueSet hash maps for 'in' conditions.
     *
     * @param  list<array<string, mixed>>  $wheres
     * @return list<array<string, mixed>>
     */
    protected function resolveSubqueries(array $wheres): array
    {
        return array_map(function (array $where) {
            if ($where['type'] === 'nested') {
                $where['wheres'] = $this->resolveSubqueries($where['wheres']);
            } elseif ($where['type'] === 'in') {
                if (! is_array($where['values'])) {
                    $result = ($where['values'])();
                    $where['values'] = is_array($result)
                        ? $result
                        : iterator_to_array($result, preserve_keys: false);
                }

                $where['valueSet'] = array_fill_keys($where['values'], true);
            } elseif ($where['type'] === 'rowValuesIn') {
                // Build tupleSet hashmap: "v1|v2" => true for O(1) lookup
                $tupleSet = [];
                foreach ($where['tuples'] as $tuple) {
                    $key = implode('|', array_map('strval', $tuple));
                    $tupleSet[$key] = true;
                }
                $where['tupleSet'] = $tupleSet;
            }

            return $where;
        }, $wheres);
    }
}
