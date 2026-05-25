<?php

namespace MinionFactory\RawHydrator;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Run a single raw SQL query and hydrate one Eloquent model per row,
 * including related models attached via Eloquent's normal relation methods.
 *
 * Usage:
 *
 *   HydratedQuery::for(Book::class)
 *       ->sql("
 *           SELECT
 *               b.id, b.title, b.author_id,
 *               a.id AS author__id, a.name AS author__name
 *           FROM books b
 *           JOIN authors a ON b.author_id = a.id
 *           WHERE b.published_year > ?
 *       ", [2000])
 *       ->with('author')
 *       ->get();
 */
class HydratedQuery
{
    /** @var class-string<Model> */
    protected string $baseClass;

    protected string $sql = '';

    /** @var array<int|string, mixed> */
    protected array $bindings = [];

    /** @var array<string, HydrationPlan> */
    protected array $plans = [];

    protected ?string $connection = null;

    /**
     * @param  class-string<Model>  $modelClass
     */
    public static function for(string $modelClass): static
    {
        $instance = new static();
        $instance->baseClass = $modelClass;

        return $instance;
    }

    /**
     * @param  array<int|string, mixed>  $bindings
     */
    public function sql(string $sql, array $bindings = []): static
    {
        $this->sql = $sql;
        $this->bindings = $bindings;

        return $this;
    }

    public function on(string $connection): static
    {
        $this->connection = $connection;

        return $this;
    }

    /**
     * Hydrate a relation that's already defined on the base model.
     *
     * Default: the relation's columns are pulled from the result using a
     * "<relation>__" prefix — e.g. `with('author')` consumes "author__id",
     * "author__name", etc.
     *
     * Overrides (the "exceptions"):
     *   - $prefix:  use a different column prefix
     *   - $columns: explicit "<result column> => <model attribute>" map
     *
     * Pass an array of relation names to register several at once (default
     * prefix mode only).
     *
     * @param  string|array<int, string>  $relation
     * @param  array<string, string>|null  $columns
     */
    public function with(string|array $relation, ?string $prefix = null, ?array $columns = null): static
    {
        if (is_array($relation)) {
            foreach ($relation as $name) {
                $this->plans[$name] = new HydrationPlan($name);
            }

            return $this;
        }

        $this->plans[$relation] = new HydrationPlan($relation, $prefix, $columns);

        return $this;
    }

    /**
     * @return Collection<int, Model>
     */
    public function get(): Collection
    {
        return $this->hydrateRows($this->runQuery());
    }

    public function first(): ?Model
    {
        return $this->get()->first();
    }

    /**
     * @return array<int, object>
     */
    protected function runQuery(): array
    {
        $connection = $this->connection
            ? DB::connection($this->connection)
            : DB::connection();

        return $connection->select($this->sql, $this->bindings);
    }

    /**
     * @param  array<int, object>  $rows
     * @return Collection<int, Model>
     */
    protected function hydrateRows(array $rows): Collection
    {
        if (empty($rows)) {
            return collect();
        }

        /** @var Model $blueprint */
        $blueprint = new $this->baseClass;
        $primaryKey = $blueprint->getKeyName();

        foreach ($this->plans as $plan) {
            $plan->resolveAgainst($blueprint);
        }

        // Bucket rows by the base model's primary key. hasMany joins duplicate
        // the parent row across each child; we want one parent instance per
        // unique PK with a Collection of children attached.
        $buckets = [];
        $order = [];
        foreach ($rows as $row) {
            $assoc = (array) $row;
            $pk = $assoc[$primaryKey] ?? null;
            $bucketKey = $pk ?? ('__row_' . count($order));

            if (! isset($buckets[$bucketKey])) {
                $buckets[$bucketKey] = [];
                $order[] = $bucketKey;
            }
            $buckets[$bucketKey][] = $assoc;
        }

        $results = collect();
        foreach ($order as $bucketKey) {
            $bucketRows = $buckets[$bucketKey];

            $baseAttrs = $this->extractBaseAttributes($bucketRows[0]);
            /** @var Model $instance */
            $instance = $this->baseClass::hydrate([$baseAttrs])->first();

            foreach ($this->plans as $name => $plan) {
                $instance->setRelation($name, $plan->hydrateFrom($bucketRows));
            }

            $results->push($instance);
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function extractBaseAttributes(array $row): array
    {
        $base = [];
        foreach ($row as $key => $value) {
            foreach ($this->plans as $plan) {
                if ($plan->owns($key)) {
                    continue 2;
                }
            }
            $base[$key] = $value;
        }

        return $base;
    }
}
