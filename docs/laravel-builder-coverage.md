> Japanese version: [laravel-builder-coverage-ja.md](laravel-builder-coverage-ja.md)

# Laravel QueryBuilder vs ReferenceQueryBuilder — Coverage Table

Legend:
- ✅ Implemented
- ❌ Not applicable (requires SQL/JOIN/DB connection, write ops, etc.)

---

## WHERE conditions

| Laravel method | Status | Notes |
|---|---|---|
| `where($col, $op, $val)` | ✅ | Operators: `=` `!=` `<>` `>` `>=` `<` `<=` `like` `not like` `&` `\|` `^` `<<` `>>` `&~` `!&` |
| `where(Closure)` | ✅ | Nested AND sub-group |
| `orWhere($col, $op, $val)` | ✅ | |
| `orWhere(Closure)` | ✅ | Nested OR sub-group |
| `whereNot($col, $op, $val)` | ✅ | Negates via `negate: true` flag |
| `whereNot(Closure)` | ✅ | |
| `orWhereNot(...)` | ✅ | |
| `whereColumn($first, $op, $second)` | ✅ | |
| `orWhereColumn(...)` | ✅ | |
| `whereNested(Closure, $boolean)` | ✅ | |
| `whereNull($column)` | ✅ | |
| `orWhereNull($column)` | ✅ | |
| `whereNotNull($column)` | ✅ | |
| `orWhereNotNull($column)` | ✅ | |
| `whereIn($column, $values)` | ✅ | Also accepts `Closure` (lazy subquery). O(1) hash-map lookup |
| `orWhereIn(...)` | ✅ | |
| `whereNotIn(...)` | ✅ | |
| `orWhereNotIn(...)` | ✅ | |
| `whereBetween($column, $values)` | ✅ | |
| `orWhereBetween(...)` | ✅ | |
| `whereNotBetween(...)` | ✅ | |
| `orWhereNotBetween(...)` | ✅ | |
| `whereBetweenColumns($col, [$min_col, $max_col])` | ✅ | col BETWEEN min_col AND max_col (all from same record) |
| `orWhereBetweenColumns(...)` | ✅ | |
| `whereNotBetweenColumns(...)` | ✅ | |
| `orWhereNotBetweenColumns(...)` | ✅ | |
| `whereValueBetween($scalar, [$min_col, $max_col])` | ✅ | scalar BETWEEN two column values |
| `orWhereValueBetween(...)` | ✅ | |
| `whereValueNotBetween(...)` | ✅ | |
| `orWhereValueNotBetween(...)` | ✅ | |
| `whereLike($col, $val, $caseSensitive)` | ✅ | |
| `orWhereLike(...)` | ✅ | |
| `whereNotLike(...)` | ✅ | |
| `orWhereNotLike(...)` | ✅ | |
| `whereNullSafeEquals($col, $val)` | ✅ | |
| `orWhereNullSafeEquals($col, $val)` | ✅ | |
| `whereAll($columns, $op, $val)` | ✅ | |
| `orWhereAll(...)` | ✅ | |
| `whereAny($columns, $op, $val)` | ✅ | |
| `orWhereAny(...)` | ✅ | |
| `whereNone($columns, $op, $val)` | ✅ | |
| `orWhereNone(...)` | ✅ | |
| `whereExists(Closure)` | ✅ | Closure receives record array, returns bool (≠ SQL EXISTS subquery) |
| `orWhereExists(Closure)` | ✅ | |
| `whereNotExists(Closure)` | ✅ | |
| `orWhereNotExists(Closure)` | ✅ | |
| `whereFilter(Closure)` *(extension)* | ✅ | Raw PHP predicate; basis for `whereExists` |
| `orWhereFilter(Closure)` *(extension)* | ✅ | |
| `whereIntegerInRaw(...)` | ❌ | Raw SQL (binding bypass) |
| `whereRaw($sql, $bindings)` | ❌ | SQL-only |
| `whereDate / whereTime / whereDay / whereMonth / whereYear` | ❌ | SQL date extraction; use `whereFilter` instead |
| `whereRowValues(...)` | ❌ | SQL row-value comparison (see `whereRowValuesIn` below) |
| `whereRowValuesIn(...)` *(extension)* | ✅ | `(col1, col2) IN ((v1, v2), ...)` — Kura extension |
| `whereRowValuesNotIn(...)` *(extension)* | ✅ | NOT IN variant |
| `orWhereRowValuesIn(...)` *(extension)* | ✅ | OR variant |
| `orWhereRowValuesNotIn(...)` *(extension)* | ✅ | OR NOT IN variant |
| `whereJson*(...)` | ❌ | SQL JSON operators |
| `whereFullText(...)` | ❌ | Full-text SQL index |
| `whereVector*(...)` | ❌ | Vector DB |
| `dynamicWhere(...)` | ❌ | Laravel `__call` magic routing |
| `mergeWheres / forNestedWhere / addNestedWhereQuery / addWhereExistsQuery` | ❌ | Internal Laravel plumbing |

---

## ORDER BY

| Laravel method | Status | Notes |
|---|---|---|
| `orderBy($column, $direction)` | ✅ | |
| `orderByDesc($column)` | ✅ | |
| `latest($column)` | ✅ | Alias: `orderByDesc($column ?? 'created_at')` |
| `oldest($column)` | ✅ | Alias: `orderBy($column ?? 'created_at')` |
| `inRandomOrder($seed)` | ✅ | `shuffle()` after collect; seed ignored |
| `reorder($column, $direction)` | ✅ | Clears all orders then optionally adds new one |
| `reorderDesc($column)` | ✅ | |
| `orderByRaw($sql, $bindings)` | ❌ | SQL expression |
| `orderByVectorDistance(...)` | ❌ | Vector DB |

---

## LIMIT / OFFSET / PAGINATION

| Laravel method | Status | Notes |
|---|---|---|
| `limit($value)` | ✅ | |
| `take($value)` | ✅ | Alias |
| `offset($value)` | ✅ | |
| `skip($value)` | ✅ | Alias |
| `forPage($page, $perPage)` | ✅ | `offset(($page-1)*$perPage)->limit($perPage)` |
| `forPageBeforeId($perPage, $lastId, $col)` | ✅ | Cursor-style, descending |
| `forPageAfterId($perPage, $lastId, $col)` | ✅ | Cursor-style, ascending |
| `getLimit()` | ✅ | |
| `getOffset()` | ✅ | |
| `paginate($perPage, $pageName, $page)` | ✅ | Returns `LengthAwarePaginator` (includes total count) |
| `simplePaginate($perPage, $pageName, $page)` | ✅ | Returns `Paginator` (no total count) |
| `groupLimit($value, $column)` | ❌ | SQL window function |
| `cursorPaginate(...)` | ❌ | Requires DB cursor logic |

---

## EXECUTION / RETRIEVAL

| Laravel method | Status | Notes |
|---|---|---|
| `get()` | ✅ | Column projection not supported (APCu stores full records) |
| `cursor()` | ✅ | Returns `\Generator` |
| `first()` | ✅ | |
| `sole()` | ✅ | Throws `RecordsNotFoundException` / `MultipleRecordsFoundException` |
| `soleValue($column)` | ✅ | |
| `find($id)` | ✅ | Uses `$primaryKey` from `CacheRepository` |
| `findOr($id, Closure)` | ✅ | |
| `value($column)` | ✅ | |
| `implode($column, $glue)` | ✅ | |
| `count()` | ✅ | |
| `pluck($column, $key)` | ✅ | `$key` parameter fully supported |
| `exists()` | ✅ | |
| `doesntExist()` | ✅ | |
| `existsOr(Closure)` | ✅ | |
| `doesntExistOr(Closure)` | ✅ | |
| `rawValue(...)` | ❌ | SQL expression |

---

## AGGREGATES

| Laravel method | Status | Notes |
|---|---|---|
| `min($column)` | ✅ | Returns null for empty result set |
| `max($column)` | ✅ | |
| `sum($column)` | ✅ | Returns 0 for empty result set |
| `avg($column)` | ✅ | Returns null for empty result set |
| `average($column)` | ✅ | Alias for `avg()` |
| `aggregate($function, $columns)` | ❌ | SQL aggregate dispatch |

---

## CLONING / UTILITY

| Laravel method | Status | Notes |
|---|---|---|
| `clone()` | ✅ | |
| `cloneWithout(array $properties)` | ✅ | Accepts: `wheres` `orders` `limit` `offset` `randomOrder` |
| `newQuery()` | ✅ | Fresh builder for the same table/repository |
| `getLimit()` | ✅ | |
| `getOffset()` | ✅ | |
| `dump()` | ✅ | Dumps query state, returns `$this` for chaining |
| `dd()` | ✅ | |
| `cloneWithoutBindings(...)` | ❌ | SQL bindings concept |
| `toSql() / toRawSql()` | ❌ | SQL string generation |
| `raw($value)` | ❌ | SQL raw expression |
| `getColumns / getBindings / getConnection / getGrammar / ...` | ❌ | SQL/DB internals |
| `__call($method, $params)` | ❌ | Laravel macro/mixin system |

---

## SELECT / FROM

| Laravel method | Status | Notes |
|---|---|---|
| `select / addSelect / selectSub / selectRaw / distinct` | ❌ | Column projection not supported; APCu stores full records |
| `from / fromSub / fromRaw` | ❌ | Table is fixed at construction time |

---

## GROUP BY / HAVING / JOINS / UNION / WRITE OPS / LOCKING

| Category | Status | Notes |
|---|---|---|
| GROUP BY / HAVING | ❌ | SQL aggregation — use `min/max/sum/avg` + `whereFilter` |
| JOINs | ❌ | Use `whereIn('col', fn() => $other->pluck('id'))` for cross-table filtering |
| UNION | ❌ | SQL UNION |
| Write ops (insert/update/delete/truncate) | ❌ | Data loaded via `Loader`; use `CacheRepository::reload()` to refresh |
| Locking / timeout | ❌ | DB concurrency concepts |
| Query lifecycle callbacks (beforeQuery/afterQuery) | ❌ | Laravel DB hooks |

---

## Summary

| Category | ✅ Implemented | ❌ Not applicable |
|---|---|---|
| WHERE | 54 | 30+ |
| ORDER BY | 7 | 2 |
| LIMIT / PAGINATION | 11 | 2 |
| EXECUTION / RETRIEVAL | 15 | 1 |
| AGGREGATES | 5 | 1 |
| CLONING / UTILITY | 7 | 10+ |
| SELECT / FROM / GROUP BY / HAVING / JOIN / UNION / WRITE / LOCK | 0 | 80+ |
| **Total** | **~99** | **~125** |

### Key design notes

1. **WHERE coverage is complete** for all non-SQL-specific patterns.
   Cross-table filtering is handled idiomatically via lazy subqueries:
   `whereIn('country', fn() => $countries->where('active', true)->pluck('code'))`

2. **Aggregates (min/max/sum/avg)** operate on the in-memory record set — no SQL required.

3. **Pagination** returns standard Laravel `LengthAwarePaginator` / `Paginator` objects,
   fully compatible with Blade `{{ $results->links() }}`.

4. **`whereExists(Closure)`** differs from SQL EXISTS: the Closure receives a record array
   and returns bool. For SQL-style EXISTS (does another table have a row?), use
   `whereIn('id', fn() => $other->pluck('foreign_key'))`.

5. **SQL-specific features** (JSON, full-text, vector, raw expressions, joins, write ops)
   are correctly excluded — this is a read-only, in-memory cache layer.

### Architecture

```
src/
  Contracts/
    ReferenceQueryBuilderInterface.php  ← mock this in tests
  Concerns/
    BuildsWhereConditions.php           ← all where* methods (+ whereRowValuesIn extension)
    BuildsOrderAndPagination.php        ← order/limit/paginate methods
    ExecutesQueries.php                 ← get/find/aggregate/paginate execution
  ReferenceQueryBuilder.php             ← thin class: uses traits, implements interface
  CacheProcessor.php                    ← Processor pattern: resolveIds, compilePredicate
  CacheRepository.php                   ← cache load/heal per table
  Index/
    IndexResolver.php                   ← index → candidate IDs (composite index acceleration)
    BinarySearch.php                    ← sorted index binary search
  Support/
    RecordCursor.php                    ← generator-based evaluation engine
```
