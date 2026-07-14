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
 * File-JSON {@see RepositoryInterface}: the whole collection lives in one JSON file, keyed by id,
 * read-modify-write on every mutation — the pattern the `example-agent-ready-blog`'s
 * `JsonPostStorage` proved for a single entity, generalized here to any {@see EntityInterface}. Zero
 * DB, zero dependency: a flat file is the entire durability story.
 *
 * A fresh `FileRepository` pointed at the same path — a different process, a different request —
 * reads back exactly what the last write left, because every read and write goes through the file
 * itself rather than an in-memory cache.
 *
 * Every mutation runs under an exclusive `flock` held across the whole read-modify-write cycle,
 * and every read takes a shared lock — so concurrent processes over the same file can neither
 * observe a torn write nor lose each other's rows.
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
     * Persists `$entity` to the file, under one exclusive lock held across the whole
     * read-modify-write cycle — so a concurrent writer to the same file can neither steal the
     * assigned id nor be overwritten. When `$entity->id()` is `null`, a fresh id is assigned via
     * {@see self::nextId()}. The written row always carries the id actually used under the key
     * `'id'`, regardless of what `$entity->toArray()` returned for it.
     *
     * @param T $entity
     */
    public function save(EntityInterface $entity): int|string
    {
        /** @var int|string */
        return $this->mutate(function (array $rows) use ($entity): array {
            $id = $entity->id() ?? $this->nextIdFrom($rows);

            $row = $entity->toArray();
            $row['id'] = $id;
            $rows[$id] = $row;

            return [$rows, $id];
        });
    }

    /**
     * Removes the entity stored under `$id`, under the same exclusive read-modify-write lock as
     * {@see self::save()}. A no-op when no entity is stored under it.
     */
    public function delete(int|string $id): void
    {
        $this->mutate(static function (array $rows) use ($id): array {
            unset($rows[$id]);

            return [$rows, null];
        });
    }

    /**
     * Every stored entity, in insertion order — rows live in the file in the order they were
     * first saved, regardless of their ids.
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
     * Reads the current rows under a shared lock, so a writer mid-write can never be observed.
     *
     * @return array<int|string, array<string,mixed>>
     */
    private function load(): array
    {
        if (!is_file($this->file)) {
            return [];
        }

        $handle = fopen($this->file, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open data file: {$this->file}");
        }

        try {
            if (!flock($handle, LOCK_SH)) {
                throw new \RuntimeException("Unable to lock data file: {$this->file}");
            }

            return $this->decode((string) stream_get_contents($handle));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Runs `$mutation` over the freshly-read rows and persists the rows it returns, all under one
     * exclusive lock held for the whole read-modify-write cycle — the lock is what turns two
     * concurrent mutations into two sequential ones instead of a lost update.
     *
     * @template R
     *
     * @param callable(array<int|string, array<string,mixed>>): array{array<int|string, array<string,mixed>>, R} $mutation rows in, `[rows to persist, result]` out
     *
     * @return R the second element of the pair `$mutation` returned
     */
    private function mutate(callable $mutation): mixed
    {
        $dir = \dirname($this->file);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Unable to create data directory: {$dir}");
        }

        $handle = fopen($this->file, 'c+');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open data file: {$this->file}");
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException("Unable to lock data file: {$this->file}");
            }

            [$rows, $result] = $mutation($this->decode((string) stream_get_contents($handle)));

            // Encode BEFORE truncating: if encoding throws, the file stays byte-untouched.
            $json = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $json);
            fflush($handle);

            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return array<int|string, array<string,mixed>>
     */
    private function decode(string $json): array
    {
        $data = json_decode($json, true);

        return is_array($data) ? $data : [];
    }
}
