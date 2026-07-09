<?php

declare(strict_types=1);

namespace Milpa\Data;

/**
 * A plain domain object that round-trips through persistence via {@see self::toArray()} /
 * {@see self::fromArray()} — no base class, no attributes, no ORM coupling; any final class can
 * implement it, the way the `example-agent-ready-blog`'s `Post` does. Conventionally the array
 * returned by `toArray()` carries the identity under the key `'id'`, mirroring {@see self::id()} —
 * {@see FileRepository} and {@see InMemoryRepository} rely on that key when assigning a fresh id to
 * an entity saved without one.
 */
interface EntityInterface
{
    /**
     * This entity's identity, or `null` when it has never been saved — an id is assigned the first
     * time it is passed to {@see RepositoryInterface::save()}.
     */
    public function id(): int|string|null;

    /**
     * Projects this entity into a plain array suitable for JSON encoding or any other serialization.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array;

    /**
     * Reconstructs an entity from the array shape produced by {@see self::toArray()}.
     *
     * @param array<string,mixed> $row
     */
    public static function fromArray(array $row): static;
}
