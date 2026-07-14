<?php

declare(strict_types=1);

namespace Milpa\Data\Tests;

use Milpa\Data\RepositoryInterface;
use Milpa\Data\Tests\Fixtures\TestEntity;
use PHPUnit\Framework\TestCase;

/**
 * Behavior every {@see RepositoryInterface} implementation must satisfy, regardless of storage
 * medium. Concrete test cases supply {@see self::createRepository()}; implementation-specific
 * behavior (e.g. file durability) lives in the concrete test case, not here.
 */
abstract class RepositoryContractTestCase extends TestCase
{
    /** @return RepositoryInterface<TestEntity> */
    abstract protected function createRepository(): RepositoryInterface;

    public function testSaveAssignsAnIdWhenTheEntityHasNoneAndPersists(): void
    {
        $repo = $this->createRepository();

        $id = $repo->save(new TestEntity(null, 'First', 'draft'));

        $this->assertNotNull($id);
        $found = $repo->find($id);
        $this->assertNotNull($found);
        $this->assertSame($id, $found->id());
        $this->assertSame('First', $found->name);
        $this->assertSame('draft', $found->status);
    }

    public function testSaveWithAPresetIdKeepsIt(): void
    {
        $repo = $this->createRepository();

        $id = $repo->save(new TestEntity('custom-id', 'Fixed', 'draft'));

        $this->assertSame('custom-id', $id);
        $this->assertSame('custom-id', $repo->find('custom-id')?->id());
    }

    public function testFindReturnsNullForAnUnknownId(): void
    {
        $repo = $this->createRepository();

        $this->assertNull($repo->find(999));
    }

    public function testAllReturnsEveryStoredEntity(): void
    {
        $repo = $this->createRepository();
        $repo->save(new TestEntity(null, 'A', 'draft'));
        $repo->save(new TestEntity(null, 'B', 'published'));

        $all = $repo->all();

        $this->assertCount(2, $all);
        $this->assertSame(['A', 'B'], array_map(static fn (TestEntity $e): string => $e->name, $all));
    }

    public function testAllReturnsEntitiesInInsertionOrderNotIdOrder(): void
    {
        $repo = $this->createRepository();

        $repo->save(new TestEntity('z', 'First', 'draft'));
        $repo->save(new TestEntity('a', 'Second', 'draft'));
        $repo->save(new TestEntity(null, 'Third', 'draft'));

        $this->assertSame(
            ['First', 'Second', 'Third'],
            array_map(static fn (TestEntity $e): string => $e->name, $repo->all()),
            'all() must return entities in insertion order — the order they were first saved — not id order',
        );
    }

    public function testResavingAnExistingIdUpdatesInPlaceWithoutMovingItsPosition(): void
    {
        $repo = $this->createRepository();

        $repo->save(new TestEntity('a', 'First', 'draft'));
        $repo->save(new TestEntity('b', 'Second', 'draft'));
        $repo->save(new TestEntity('c', 'Third', 'draft'));

        $repo->save(new TestEntity('a', 'First, revised', 'published'));

        $all = $repo->all();
        $this->assertCount(3, $all, 're-saving an existing id must update the stored entity, not append a new one');
        $this->assertSame(
            ['First, revised', 'Second', 'Third'],
            array_map(static fn (TestEntity $e): string => $e->name, $all),
            'a re-saved entity keeps the position of its first save in all()',
        );
        $this->assertSame('published', $repo->find('a')?->status);
    }

    public function testAllIsEmptyForAnEmptyRepository(): void
    {
        $repo = $this->createRepository();

        $this->assertSame([], $repo->all());
    }

    public function testQueryMatchesEntitiesByEqualityCriteria(): void
    {
        $repo = $this->createRepository();
        $repo->save(new TestEntity(null, 'A', 'draft'));
        $repo->save(new TestEntity(null, 'B', 'published'));
        $repo->save(new TestEntity(null, 'C', 'published'));

        $published = $repo->query(['status' => 'published']);

        $this->assertCount(2, $published);
        $this->assertSame(['B', 'C'], array_map(static fn (TestEntity $e): string => $e->name, $published));
    }

    public function testQueryWithEmptyCriteriaMatchesEveryEntity(): void
    {
        $repo = $this->createRepository();
        $repo->save(new TestEntity(null, 'A', 'draft'));
        $repo->save(new TestEntity(null, 'B', 'published'));

        $this->assertCount(2, $repo->query([]));
    }

    public function testQueryWithNoMatchesReturnsAnEmptyList(): void
    {
        $repo = $this->createRepository();
        $repo->save(new TestEntity(null, 'A', 'draft'));

        $this->assertSame([], $repo->query(['status' => 'archived']));
    }

    public function testDeleteRemovesTheEntity(): void
    {
        $repo = $this->createRepository();
        $id = $repo->save(new TestEntity(null, 'A', 'draft'));

        $repo->delete($id);

        $this->assertNull($repo->find($id));
        $this->assertSame([], $repo->all());
    }

    public function testDeleteOfAnUnknownIdIsANoOp(): void
    {
        $repo = $this->createRepository();
        $repo->save(new TestEntity(null, 'A', 'draft'));

        $repo->delete('does-not-exist');

        $this->assertCount(1, $repo->all());
    }

    public function testNextIdIsOneForAnEmptyRepository(): void
    {
        $repo = $this->createRepository();

        $this->assertSame(1, $repo->nextId());
    }

    public function testNextIdIsMonotonic(): void
    {
        $repo = $this->createRepository();

        $first = $repo->save(new TestEntity(null, 'A', 'draft'));
        $second = $repo->save(new TestEntity(null, 'B', 'draft'));
        $third = $repo->save(new TestEntity(null, 'C', 'draft'));

        $this->assertSame([1, 2, 3], [$first, $second, $third]);
        $this->assertSame(4, $repo->nextId());
    }

    public function testTwoEntitiesDoNotBleedIntoEachOther(): void
    {
        $repo = $this->createRepository();

        $idA = $repo->save(new TestEntity(null, 'A', 'draft'));
        $idB = $repo->save(new TestEntity(null, 'B', 'published'));

        $foundA = $repo->find($idA);
        $foundB = $repo->find($idB);

        $this->assertNotNull($foundA);
        $this->assertNotNull($foundB);
        $this->assertSame('A', $foundA->name);
        $this->assertSame('draft', $foundA->status);
        $this->assertSame('B', $foundB->name);
        $this->assertSame('published', $foundB->status);
    }
}
