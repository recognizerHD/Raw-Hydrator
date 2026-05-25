<?php

namespace MinionFactory\RawHydrator\Concerns;

use MinionFactory\RawHydrator\HydratedQuery;

/**
 * Sugar trait for Eloquent models.
 *
 * Adds `Model::fromRawQuery("SELECT ...")` so callers don't have to mention
 * `HydratedQuery::for(...)` every time.
 *
 *   class Book extends Model
 *   {
 *       use HydratesFromRawQuery;
 *   }
 *
 *   Book::fromRawQuery("SELECT ... ", [$bind])
 *       ->with('author')
 *       ->get();
 */
trait HydratesFromRawQuery
{
    /**
     * @param  array<int|string, mixed>  $bindings
     */
    public static function fromRawQuery(string $sql, array $bindings = []): HydratedQuery
    {
        return HydratedQuery::for(static::class)->sql($sql, $bindings);
    }
}
