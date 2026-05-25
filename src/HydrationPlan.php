<?php

namespace MinionFactory\RawHydrator;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;

/**
 * Describes how to pull one related model's columns out of each result row,
 * and how to attach the hydrated result to the base model.
 *
 * A plan resolves itself against an instance of the base model: it calls the
 * relation method (e.g. `$book->author()`) and reads the related model class
 * and cardinality from the returned Relation. The caller never has to repeat
 * any of that — the existing Eloquent relation is the source of truth.
 */
class HydrationPlan
{
    protected string $name;

    /** Column-name prefix, e.g. "author__". Defaulted from the relation name. */
    protected ?string $prefix;

    /**
     * Explicit map: "<result column> => <model attribute>".
     *
     * @var array<string, string>|null
     */
    protected ?array $columns;

    /** @var class-string<Model>|null */
    protected ?string $relatedClass = null;

    /** 'one' or 'many'. */
    protected string $cardinality = 'one';

    protected bool $resolved = false;

    /**
     * @param  array<string, string>|null  $columns
     */
    public function __construct(string $name, ?string $prefix = null, ?array $columns = null)
    {
        $this->name = $name;
        $this->prefix = $prefix;
        $this->columns = $columns;
    }

    /**
     * Inspect the relation method on the base model to learn the related
     * class and whether we're producing one instance or a Collection.
     */
    public function resolveAgainst(Model $baseModel): void
    {
        if ($this->resolved) {
            return;
        }

        if (! method_exists($baseModel, $this->name)) {
            throw new InvalidArgumentException(
                "Relation [{$this->name}] is not defined on " . $baseModel::class
            );
        }

        $relation = $baseModel->{$this->name}();

        if (! $relation instanceof Relation) {
            throw new InvalidArgumentException(
                "Method [{$this->name}] on " . $baseModel::class . " did not return an Eloquent relation."
            );
        }

        if ($relation instanceof MorphTo) {
            throw new InvalidArgumentException(
                "MorphTo relations are not supported by raw-hydrator (the morphed type isn't known until row data is read). "
                . "Use the `columns:` override and hydrate manually if you need this."
            );
        }

        $this->relatedClass = $relation->getRelated()::class;
        $this->cardinality = $this->cardinalityFor($relation);

        // Convention: "<relationName>__" prefix unless an override was supplied.
        if ($this->prefix === null && $this->columns === null) {
            $this->prefix = $this->name . '__';
        }

        $this->resolved = true;
    }

    protected function cardinalityFor(Relation $relation): string
    {
        return match (true) {
            $relation instanceof BelongsTo,
            $relation instanceof HasOne,
            $relation instanceof MorphOne => 'one',

            $relation instanceof HasMany,
            $relation instanceof MorphMany,
            $relation instanceof HasManyThrough,
            // BelongsToMany: we hydrate the far side as 'many'. The pivot row
            // isn't filled — caller can attach it manually if needed.
            $relation instanceof BelongsToMany => 'many',

            default => 'many',
        };
    }

    /**
     * Does a given result-set column belong to this relation?
     */
    public function owns(string $column): bool
    {
        if ($this->columns !== null) {
            return array_key_exists($column, $this->columns);
        }

        return $this->prefix !== null && str_starts_with($column, $this->prefix);
    }

    /**
     * Build the related model(s) from the rows in a single base-PK bucket.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function hydrateFrom(array $rows): Model|EloquentCollection|null
    {
        if ($this->cardinality === 'one') {
            $attrs = $this->extractAttributes($rows[0] ?? []);

            return $this->isAllNull($attrs)
                ? null
                : $this->relatedClass::hydrate([$attrs])->first();
        }

        $relatedKey = (new $this->relatedClass)->getKeyName();
        $seen = [];
        $instances = [];

        foreach ($rows as $row) {
            $attrs = $this->extractAttributes($row);
            if ($this->isAllNull($attrs)) {
                continue;
            }

            // Dedup by the related model's PK so a join with several siblings
            // doesn't produce duplicate children when their child rows repeat.
            $key = $attrs[$relatedKey] ?? null;
            if ($key !== null) {
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
            }

            $instances[] = $attrs;
        }

        return $this->relatedClass::hydrate($instances);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function extractAttributes(array $row): array
    {
        if ($this->columns !== null) {
            $out = [];
            foreach ($this->columns as $source => $target) {
                if (array_key_exists($source, $row)) {
                    $out[$target] = $row[$source];
                }
            }

            return $out;
        }

        $prefixLen = strlen($this->prefix);
        $out = [];
        foreach ($row as $key => $value) {
            if (str_starts_with($key, $this->prefix)) {
                $out[substr($key, $prefixLen)] = $value;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    protected function isAllNull(array $attrs): bool
    {
        if (empty($attrs)) {
            return true;
        }

        foreach ($attrs as $value) {
            if ($value !== null) {
                return false;
            }
        }

        return true;
    }
}
