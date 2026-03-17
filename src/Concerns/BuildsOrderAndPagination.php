<?php

namespace Kura\Concerns;

/**
 * ORDER BY, LIMIT/OFFSET, and pagination builder methods.
 *
 * Expects the using class to expose these protected members:
 *   array  $orders
 *   ?int   $limit
 *   ?int   $offset
 *   bool   $randomOrder
 */
trait BuildsOrderAndPagination
{
    // =========================================================================
    // ORDER BY
    // =========================================================================

    public function orderBy(string $column, string $direction = 'asc'): static
    {
        $this->orders[] = ['column' => $column, 'direction' => strtolower($direction)];

        return $this;
    }

    public function orderByDesc(string $column): static
    {
        return $this->orderBy($column, 'desc');
    }

    /** Order by $column descending (most recent first). */
    public function latest(string $column = 'created_at'): static
    {
        return $this->orderByDesc($column);
    }

    /** Order by $column ascending (oldest first). */
    public function oldest(string $column = 'created_at'): static
    {
        return $this->orderBy($column);
    }

    /**
     * Randomise the result order.
     * Clears any existing orderBy clauses; $seed is accepted for API parity
     * but ignored (PHP's shuffle() does not support a deterministic seed).
     */
    public function inRandomOrder(mixed $seed = ''): static
    {
        $this->orders = [];
        $this->randomOrder = true;

        return $this;
    }

    /**
     * Remove all existing ORDER BY clauses and optionally add a new one.
     *
     *   ->reorder()                  ← clear all orders
     *   ->reorder('name', 'asc')     ← clear and set new order
     */
    public function reorder(?string $column = null, string $direction = 'asc'): static
    {
        $this->orders = [];
        $this->randomOrder = false;

        if ($column !== null) {
            $this->orderBy($column, $direction);
        }

        return $this;
    }

    public function reorderDesc(?string $column = null): static
    {
        return $this->reorder($column, 'desc');
    }

    // =========================================================================
    // LIMIT / OFFSET
    // =========================================================================

    public function limit(int $value): static
    {
        $this->limit = $value;

        return $this;
    }

    public function offset(int $value): static
    {
        $this->offset = $value;

        return $this;
    }

    /** Alias for limit(). */
    public function take(int $value): static
    {
        return $this->limit($value);
    }

    /** Alias for offset(). */
    public function skip(int $value): static
    {
        return $this->offset($value);
    }

    // =========================================================================
    // PAGINATION
    // =========================================================================

    /**
     * Set limit and offset for the given page number.
     *
     *   ->forPage(2, 15)  ← page 2, 15 per page  → offset 15, limit 15
     */
    public function forPage(int $page, int $perPage = 15): static
    {
        return $this->offset(($page - 1) * $perPage)->limit($perPage);
    }

    /**
     * Cursor-style pagination — fetch records BEFORE $lastId (descending order).
     *
     * Removes any existing order on $column and re-adds it as DESC so the
     * page is contiguous.
     */
    public function forPageBeforeId(int $perPage = 15, int|string|null $lastId = null, string $column = 'id'): static
    {
        $this->orders = $this->removeExistingOrdersFor($column);

        if ($lastId !== null) {
            $this->where($column, '<', $lastId);
        }

        return $this->orderByDesc($column)->limit($perPage);
    }

    /**
     * Cursor-style pagination — fetch records AFTER $lastId (ascending order).
     */
    public function forPageAfterId(int $perPage = 15, int|string|null $lastId = null, string $column = 'id'): static
    {
        $this->orders = $this->removeExistingOrdersFor($column);

        if ($lastId !== null) {
            $this->where($column, '>', $lastId);
        }

        return $this->orderBy($column)->limit($perPage);
    }

    // =========================================================================
    // Introspection
    // =========================================================================

    public function getLimit(): ?int
    {
        return $this->limit;
    }

    public function getOffset(): ?int
    {
        return $this->offset;
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /** @return list<array{column: string, direction: string}> */
    private function removeExistingOrdersFor(string $column): array
    {
        return array_values(
            array_filter($this->orders, fn ($order) => $order['column'] !== $column)
        );
    }
}
