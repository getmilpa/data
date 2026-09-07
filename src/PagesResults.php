<?php

/**
 * This file is part of Milpa Data — the small persistence contract of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/data
 */

declare(strict_types=1);

namespace Milpa\Data;

/**
 * A {@see RepositoryInterface} that can answer a BOUNDED slice instead of everything it holds.
 *
 * Until this existed the contract had exactly one supported access path — `find()` by id. `all()` and
 * `query()` read the whole table and filtered in PHP, so a collection had no way to be paged and a
 * thousand rows cost a thousand rows no matter how many the caller wanted (greenhouse decisions/0215).
 *
 * Kept as a SIBLING interface rather than new parameters on `RepositoryInterface::query()`, because
 * adding even an optional parameter to an interface method breaks every implementation of it — including
 * ones outside this family, which is published and cannot see them. This is the shape
 * {@see \Milpa\Live\Contracts\Component\ListsComponents} already set for the same reason: existing
 * implementations stay valid, and the four backends here all implement it, so a consumer that asks
 * `instanceof` finds it in practice.
 *
 * @template T of EntityInterface
 */
interface PagesResults
{
    /**
     * At most `$limit` stored entities matching `$criteria`, skipping the first `$offset` of them.
     *
     * The matching and the ordering are exactly {@see RepositoryInterface::query()}'s: strict equality
     * on `toArray()` values, insertion order. This adds bounds and nothing else — an empty `$criteria`
     * with no offset is the first page of {@see RepositoryInterface::all()}.
     *
     * ── WHAT IS PUSHED TO THE ENGINE, AND WHAT IS NOT ──────────────────────────────────────────────
     *
     * With an EMPTY `$criteria` the SQL backends push the bounds into the query (`LIMIT`/`OFFSET`), so
     * the rows beyond the page are never fetched. **With criteria they do not**, and that is a
     * correctness requirement rather than an omission: criteria are matched in PHP by strict equality
     * over the decoded document, and SQL's own comparison is looser — `LIMIT` applied before a filter
     * that then removes rows would return short or wrong pages. Filtering in the engine needs the
     * criteria to be pushed too, with identical semantics; that is named as the next slice, not
     * half-built here.
     *
     * So: paging a collection is cheap, and paging a FILTERED collection is correct but not yet cheap.
     * Saying which is which is the point — a page that is quietly wrong is worse than one that is slow.
     *
     * @param array<string,mixed> $criteria
     * @param int                 $limit    how many at most; `0` answers an empty page
     * @param int                 $offset   how many to skip first
     *
     * @throws \InvalidArgumentException when `$limit` or `$offset` is negative — a negative bound has no
     *                                   meaning and silently treating it as zero would hide the caller's bug
     *
     * @return list<T>
     */
    public function page(array $criteria, int $limit, int $offset = 0): array;
}
