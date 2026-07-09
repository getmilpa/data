<?php

declare(strict_types=1);

namespace Milpa\Data;

/**
 * A persistence boundary for one entity type: plain data in, plain data out, equality-only querying —
 * no query language, no relations, no transactions. One repository instance is bound to exactly one
 * entity class (the concrete `T`); a consumer with several persistent entity types wires one
 * repository per type. {@see FileRepository} and {@see InMemoryRepository} are the two backends
 * behind this contract.
 *
 * @template T of EntityInterface
 */
interface RepositoryInterface
{
    /**
     * The entity stored under `$id`, or `null` when no entity is stored under it.
     *
     * @return T|null
     */
    public function find(int|string $id): ?EntityInterface;

    /**
     * Persists `$entity`. When `$entity->id()` is `null`, a fresh id is assigned via
     * {@see self::nextId()} before writing; the id actually used — freshly assigned, or the
     * entity's own — is what gets returned.
     *
     * @param T $entity
     */
    public function save(EntityInterface $entity): int|string;

    /**
     * Removes the entity stored under `$id`. A no-op when no entity is stored under it.
     */
    public function delete(int|string $id): void;

    /**
     * Every stored entity, in insertion order.
     *
     * @return list<T>
     */
    public function all(): array;

    /**
     * The id to assign to the next entity saved without one of its own — one past the highest
     * integer id currently stored, or `1` when the store holds no integer id.
     */
    public function nextId(): int|string;

    /**
     * Stored entities matching every `$criteria` pair by strict equality — `['status' =>
     * 'published']` matches entities whose `toArray()['status'] === 'published'`. An empty
     * `$criteria` matches every entity, same as {@see self::all()}.
     *
     * @param array<string,mixed> $criteria
     *
     * @return list<T>
     */
    public function query(array $criteria): array;
}
