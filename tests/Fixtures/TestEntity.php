<?php

declare(strict_types=1);

namespace Milpa\Data\Tests\Fixtures;

use Milpa\Data\EntityInterface;

/**
 * Plain {@see EntityInterface} fixture used by the repository contract tests — mirrors the shape of
 * the `example-agent-ready-blog`'s `Post`: immutable, public properties, `toArray()`/`fromArray()`
 * round-trip.
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
