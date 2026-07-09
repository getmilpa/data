<?php

declare(strict_types=1);

namespace Milpa\Data;

/**
 * File-JSON {@see RepositoryInterface}: the whole collection lives in one JSON file, keyed by id,
 * read-modify-write on every mutation — the pattern the `example-agent-ready-blog`'s
 * `JsonPostStorage` proved for a single entity, generalized here to any {@see EntityInterface}. Zero
 * DB, zero dependency: a flat file is the entire durability story.
 *
 * A fresh `FileRepository` pointed at the same path — a different process, a different request —
 * reads back exactly what the last write left, because every read and write goes through the file
 * itself rather than an in-memory cache.
 *
 * @template T of EntityInterface
 *
 * @implements RepositoryInterface<T>
 */
final class FileRepository implements RepositoryInterface
{
    /**
     * @param string          $file        path to the JSON collection file; its directory is created on first save if missing
     * @param class-string<T> $entityClass the entity class rows are rehydrated into via `fromArray()`
     */
    public function __construct(
        private readonly string $file,
        private readonly string $entityClass,
    ) {
    }

    /**
     * The entity stored under `$id`, or `null` when no entity is stored under it.
     *
     * @return T|null
     */
    public function find(int|string $id): ?EntityInterface
    {
        $row = $this->load()[$id] ?? null;

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Persists `$entity` to the file. When `$entity->id()` is `null`, a fresh id is assigned via
     * {@see self::nextId()}. The written row always carries the id actually used under the key
     * `'id'`, regardless of what `$entity->toArray()` returned for it.
     *
     * @param T $entity
     */
    public function save(EntityInterface $entity): int|string
    {
        $rows = $this->load();
        $id = $entity->id() ?? $this->nextIdFrom($rows);

        $row = $entity->toArray();
        $row['id'] = $id;
        $rows[$id] = $row;

        $this->write($rows);

        return $id;
    }

    /**
     * Removes the entity stored under `$id`. A no-op when no entity is stored under it.
     */
    public function delete(int|string $id): void
    {
        $rows = $this->load();
        unset($rows[$id]);
        $this->write($rows);
    }

    /**
     * Every stored entity, in file order.
     *
     * @return list<T>
     */
    public function all(): array
    {
        return array_values(array_map($this->hydrate(...), $this->load()));
    }

    /**
     * The id to assign to the next entity saved without one of its own — one past the highest
     * integer id currently in the file, or `1` when the file holds no integer id.
     */
    public function nextId(): int
    {
        return $this->nextIdFrom($this->load());
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
            $this->load(),
            fn (array $row): bool => $this->matches($row, $criteria),
        );

        return array_values(array_map($this->hydrate(...), $matches));
    }

    /**
     * @param array<int|string, array<string,mixed>> $rows
     */
    private function nextIdFrom(array $rows): int
    {
        $intIds = array_filter(array_keys($rows), static fn (int|string $id): bool => is_int($id));

        return $intIds === [] ? 1 : max($intIds) + 1;
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

    /**
     * @return array<int|string, array<string,mixed>>
     */
    private function load(): array
    {
        if (!is_file($this->file)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($this->file), true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<int|string, array<string,mixed>> $rows
     */
    private function write(array $rows): void
    {
        $dir = \dirname($this->file);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Unable to create data directory: {$dir}");
        }

        file_put_contents(
            $this->file,
            json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
    }
}
