<?php

namespace VragenAI\Search;

/**
 * Builds the flat, indexed filter rows the vragen.ai search API expects.
 *
 * The API serialises filters as numbered rows under the `filter` query key —
 * group rows (`filter[N][id|parent|operator]`) and leaf rows
 * (`filter[N][parent|path|operator|value]`), all hanging off a single root
 * group. Passing the result of {@see toArray()} as the `filter` element of the
 * query args reproduces that structure via http_build_query().
 *
 * Filter `path` values address synced metadata (e.g. `languages`, `post_type`);
 * keys are matched in snake_case on the platform side.
 */
final class FilterBuilder
{
    private const ROOT = 'root';

    /** @var list<array<string, mixed>> */
    private array $rows = [];

    private int $groupCounter = 0;

    private string $rootId;

    /** Number of leaf conditions added, so we can tell an empty filter apart. */
    private int $leaves = 0;

    public function __construct(string $conjunction = 'AND')
    {
        $this->rootId = $this->addGroup($conjunction, self::ROOT);
    }

    /**
     * Add a single `path operator value` condition to the root group.
     */
    public function where(string $path, string $value, string $operator = '='): self
    {
        $this->addLeaf($this->rootId, $path, $operator, $value);

        return $this;
    }

    /**
     * Match any of the given values for a path. A single value collapses to a
     * plain equality; multiple values become an OR subgroup of equalities.
     * An empty list is a no-op.
     *
     * @param  list<string>  $values
     */
    public function whereIn(string $path, array $values): self
    {
        $values = array_values(array_unique(array_filter($values, static fn (string $v): bool => $v !== '')));

        if ($values === []) {
            return $this;
        }

        if (count($values) === 1) {
            return $this->where($path, $values[0]);
        }

        $groupId = $this->addGroup('OR', $this->rootId);
        foreach ($values as $value) {
            $this->addLeaf($groupId, $path, '=', $value);
        }

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->leaves === 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return $this->isEmpty() ? [] : $this->rows;
    }

    private function addGroup(string $operator, string $parent): string
    {
        $id = (string) ++$this->groupCounter;

        $this->rows[] = [
            'id' => $id,
            'parent' => $parent,
            'operator' => strtoupper($operator) === 'OR' ? 'OR' : 'AND',
        ];

        return $id;
    }

    private function addLeaf(string $parent, string $path, string $operator, string $value): void
    {
        $this->rows[] = [
            'parent' => $parent,
            'path' => $path,
            'operator' => $operator,
            'value' => $value,
        ];

        $this->leaves++;
    }
}
