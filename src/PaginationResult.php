<?php

namespace Luany\Database;

/**
 * PaginationResult
 *
 * Immutable value object returned by QueryBuilder::paginate().
 * Carries the page data and all metadata needed to render pagination UI.
 *
 * Usage:
 *   $page = (new QueryBuilder($conn))
 *       ->table('users')
 *       ->where('active', '=', 1)
 *       ->orderBy('name', 'ASC')
 *       ->paginate(perPage: 15, page: 2);
 *
 *   $page->data        // array of rows for this page
 *   $page->total       // total matching rows across ALL pages
 *   $page->perPage     // rows requested per page
 *   $page->currentPage // current page number (1-based)
 *   $page->lastPage    // last page number
 *   $page->from        // first row number on this page (1-based), or null if empty
 *   $page->to          // last row number on this page (1-based), or null if empty
 *   $page->hasMore()   // true if there is a next page
 *   $page->toArray()   // all of the above as an array
 */
final class PaginationResult
{
    public readonly int  $lastPage;
    public readonly ?int $from;
    public readonly ?int $to;

    /**
     * @param array<int, array<string, mixed>> $data        Rows for the current page
     * @param int                               $total       Total matching rows
     * @param int                               $perPage     Rows per page
     * @param int                               $currentPage Current page number (1-based)
     */
    public function __construct(
        public readonly array $data,
        public readonly int   $total,
        public readonly int   $perPage,
        public readonly int   $currentPage,
    ) {
        $this->lastPage = $perPage > 0
            ? (int) max(1, ceil($total / $perPage))
            : 1;

        if ($total === 0 || empty($data)) {
            $this->from = null;
            $this->to   = null;
        } else {
            $this->from = ($currentPage - 1) * $perPage + 1;
            $this->to   = $this->from + count($data) - 1;
        }
    }

    /**
     * Whether there is a page after the current one.
     */
    public function hasMore(): bool
    {
        return $this->currentPage < $this->lastPage;
    }

    /**
     * Whether there is a page before the current one.
     */
    public function hasPrev(): bool
    {
        return $this->currentPage > 1;
    }

    /**
     * Serialize all pagination metadata to an associative array.
     * Useful for JSON API responses.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'data'         => $this->data,
            'total'        => $this->total,
            'per_page'     => $this->perPage,
            'current_page' => $this->currentPage,
            'last_page'    => $this->lastPage,
            'from'         => $this->from,
            'to'           => $this->to,
            'has_more'     => $this->hasMore(),
            'has_prev'     => $this->hasPrev(),
        ];
    }
}
