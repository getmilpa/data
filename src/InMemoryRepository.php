<?php

/**
 * This file is part of Milpa Data — the runtime-native persistence primitive of the Milpa PHP framework.
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
 * Array-backed {@see RepositoryInterface}: same contract as {@see FileRepository}, kept entirely in
 * process memory — for tests and zero-file consumers. Nothing is written to disk and nothing
 * survives past the instance's lifetime.
 *
 * @template T of EntityInterface
 *
 * @implements RepositoryInterface<T>
 */
final class InMemoryRepository implements RepositoryInterface
{
    /** @var array<int|string, array<string,mixed>> */
    private array $rows = [];

    /**
     * @param class-string<T> $entityClass the entity class rows are rehydrated into via `fromArray()`
     */
    public function __construct(private readonly string $entityClass)
    {
    }

    /**
     * The entity stored under `$id`, or `null` when no entity is stored under it.
     *
     * @return T|null
     */
    public function find(int|string $id): ?EntityInterface
    {
        return isset($this->rows[$id]) ? $this->hydrate($this->rows[$id]) : null;
    }

    /**
     * Persists `$entity` in memory. When `$entity->id()` is `null`, a fresh id is assigned via
     * {@see self::nextId()}. The stored row always carries the id actually used under the key
     * `'id'`, regardless of what `$entity->toArray()` returned for it.
     *
     * @param T $entity
     */
    public function save(EntityInterface $entity): int|string
    {
        $id = $entity->id() ?? $this->nextId();

        $row = $entity->toArray();
        $row['id'] = $id;
        $this->rows[$id] = $row;

        return $id;
    }

    /**
     * Removes the entity stored under `$id`. A no-op when no entity is stored under it.
     */
    public function delete(int|string $id): void
    {
        unset($this->rows[$id]);
    }

    /**
     * Every stored entity, in insertion order.
     *
     * @return list<T>
     */
    public function all(): array
    {
        return array_values(array_map($this->hydrate(...), $this->rows));
    }

    /**
     * The id to assign to the next entity saved without one of its own — one past the highest
     * integer id currently stored, or `1` when the store holds no integer id.
     */
    public function nextId(): int
    {
        $intIds = array_filter(array_keys($this->rows), static fn (int|string $id): bool => is_int($id));

        return $intIds === [] ? 1 : max($intIds) + 1;
    }

    /**
     * Stored entities matching every `$criteria` pair by strict equality.
     *
     * @param array<string,mixed> $criteria
     *
     * @return list<T>
     */
    public function query(array $criteria): array
    {
        $matches = array_filter(
            $this->rows,
            fn (array $row): bool => $this->matches($row, $criteria),
        );

        return array_values(array_map($this->hydrate(...), $matches));
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $criteria
     */
    private function matches(array $row, array $criteria): bool
    {
        foreach ($criteria as $key => $value) {
            if (!array_key_exists($key, $row) || $row[$key] !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return T
     */
    private function hydrate(array $row): EntityInterface
    {
        $entityClass = $this->entityClass;

        return $entityClass::fromArray($row);
    }
}
