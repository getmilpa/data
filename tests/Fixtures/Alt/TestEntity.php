<?php

declare(strict_types=1);

namespace Milpa\Data\Tests\Fixtures\Alt;

use Milpa\Data\EntityInterface;

/**
 * Same short class name as {@see \Milpa\Data\Tests\Fixtures\TestEntity}, different namespace — the
 * exact collision `SqliteRepository`'s table-name derivation (snake_case short name + crc32 of the
 * FQCN) must keep apart when both classes share one database file.
 */
final readonly class TestEntity implements EntityInterface
{
    public function __construct(
        public int|string|null $id,
        public string $name,
        public string $status,
    ) {
    }

    /** True identity accessor — mirrors the constructor-promoted `$id` property. */
    public function id(): int|string|null
    {
        return $this->id;
    }

    /** @return array{id: int|string|null, name: string, status: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status,
        ];
    }

    /** @param array{id?: int|string|null, name: string, status: string} $row */
    public static function fromArray(array $row): static
    {
        return new self($row['id'] ?? null, $row['name'], $row['status']);
    }
}
