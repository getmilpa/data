<?php

declare(strict_types=1);

namespace Milpa\Data\Tests;

use Milpa\Data\RepositoryInterface;
use Milpa\Data\SqliteRepository;
use Milpa\Data\Tests\Fixtures\Alt\TestEntity as AltTestEntity;
use Milpa\Data\Tests\Fixtures\TestEntity;

final class SqliteRepositoryTest extends RepositoryContractTestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/milpa-data-sqlite-' . uniqid('', true) . '.db';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    protected function createRepository(): RepositoryInterface
    {
        return new SqliteRepository($this->path, TestEntity::class);
    }

    public function testAFreshSqliteRepositoryOverTheSameDatabaseRereadsIdentically(): void
    {
        $repo = new SqliteRepository($this->path, TestEntity::class);
        $id = $repo->save(new TestEntity(null, 'A', 'draft'));

        $fresh = new SqliteRepository($this->path, TestEntity::class);

        $this->assertEquals($repo->find($id), $fresh->find($id));
        $this->assertEquals($repo->all(), $fresh->all());
        $this->assertSame(2, $fresh->nextId(), 'nextId must continue from what is persisted, not reset');
    }

    public function testAMissingDatabaseFileStartsEmpty(): void
    {
        $this->assertFalse(is_file($this->path));

        $repo = new SqliteRepository($this->path, TestEntity::class);

        $this->assertSame([], $repo->all());
        $this->assertSame(1, $repo->nextId());
    }

    public function testSaveCreatesTheParentDirectoryWhenMissing(): void
    {
        $nestedPath = sys_get_temp_dir() . '/milpa-data-sqlite-nested-' . uniqid('', true) . '/entities.db';
        $repo = new SqliteRepository($nestedPath, TestEntity::class);

        $repo->save(new TestEntity(null, 'A', 'draft'));

        $this->assertFileExists($nestedPath);

        @unlink($nestedPath);
        @rmdir(\dirname($nestedPath));
    }

    public function testTheTableIsAutoCreatedUnderTheDocumentedDerivationRule(): void
    {
        (new SqliteRepository($this->path, TestEntity::class))->save(new TestEntity(null, 'A', 'draft'));

        $expected = 'test_entity_' . hash('crc32b', TestEntity::class);

        $this->assertSame([$expected], $this->tableNames(), 'expected snake_case short name + crc32-of-FQCN suffix');
    }

    public function testTwoEntityClassesWithTheSameShortNameShareOneDatabaseWithoutContamination(): void
    {
        $repo = new SqliteRepository($this->path, TestEntity::class);
        $altRepo = new SqliteRepository($this->path, AltTestEntity::class);

        $repo->save(new TestEntity(null, 'Original', 'draft'));
        $altRepo->save(new AltTestEntity(null, 'Alt one', 'published'));
        $altRepo->save(new AltTestEntity(null, 'Alt two', 'published'));

        $this->assertCount(1, $repo->all(), 'rows saved through the other entity class must not appear here');
        $this->assertCount(2, $altRepo->all());
        $this->assertSame('Original', $repo->find(1)?->name);
        $this->assertNull($repo->find(2), 'the alt class saved id 2 — it must be invisible to this repository');
        $this->assertCount(
            2,
            $this->tableNames(),
            'same short class name, different namespaces: the crc32 suffix must yield two distinct tables',
        );
    }

    public function testStringIdsRoundTripThroughTextStorage(): void
    {
        $repo = new SqliteRepository($this->path, TestEntity::class);
        $repo->save(new TestEntity('custom-id', 'Fixed', 'draft'));
        $autoId = $repo->save(new TestEntity(null, 'Auto', 'draft'));

        $fresh = new SqliteRepository($this->path, TestEntity::class);

        $this->assertSame('custom-id', $fresh->find('custom-id')?->id(), 'a string id must come back as the same string');
        $this->assertSame(1, $fresh->find($autoId)?->id(), 'an integer id must come back as an int, not the TEXT the column stores');
        $this->assertSame(2, $fresh->nextId(), 'nextId must see the integer id through its TEXT storage');
    }

    public function testAFailingJsonEncodeLeavesExistingRowsIntact(): void
    {
        $repo = new SqliteRepository($this->path, TestEntity::class);
        $repo->save(new TestEntity(null, 'A', 'draft'));
        $repo->save(new TestEntity(null, 'B', 'published'));

        try {
            $repo->save(new TestEntity(null, "\xB1\x31", 'draft')); // malformed UTF-8: json_encode throws
            $this->fail('saving an entity whose toArray() holds malformed UTF-8 must throw a JsonException');
        } catch (\JsonException) {
            // expected — encoding must fail before any row is written
        }

        $all = (new SqliteRepository($this->path, TestEntity::class))->all();
        $this->assertCount(2, $all, 'both pre-existing rows must survive the failed save');
        $this->assertSame(['A', 'B'], array_map(static fn (TestEntity $e): string => $e->name, $all));
        $this->assertSame(3, (new SqliteRepository($this->path, TestEntity::class))->nextId(), 'the failed save must not have consumed an id');
    }

    /**
     * The user tables currently in the database file, by name.
     *
     * @return list<string>
     */
    private function tableNames(): array
    {
        $pdo = new \PDO('sqlite:' . $this->path);
        $statement = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
        $this->assertNotFalse($statement);

        /** @var list<string> */
        return $statement->fetchAll(\PDO::FETCH_COLUMN);
    }

    // ---- what a broken file or a corrupt row does ------------------------------

    public function testARowThatIsNotAJsonObjectIsReportedWithTheTableAndThePath(): void
    {
        // Someone else's process, a partial write, a hand-edited row: the
        // repository has to say which table and which file, or the reader is
        // left guessing across every database on the machine.
        $repository = new SqliteRepository($this->path, TestEntity::class);
        $repository->save(new TestEntity(1, 'uno', 'activo'));

        $pdo = new \PDO('sqlite:' . $this->path);
        $pdo->exec("UPDATE " . $this->tableNames()[0] . " SET doc = '\"no soy un objeto\"'");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Corrupt document in SQLite table');

        $repository->find(1);
    }

    public function testADatabaseDirectoryThatCannotBeCreatedIsNamed(): void
    {
        // The parent of the requested directory is a FILE, so no mkdir can
        // succeed. Naming the directory is what turns this into a fixable
        // message instead of a permissions mystery.
        $archivo = sys_get_temp_dir() . '/milpa-data-no-es-dir-' . uniqid('', true);
        file_put_contents($archivo, 'soy un archivo, no un directorio');

        try {
            $repository = new SqliteRepository($archivo . '/sub/base.db', TestEntity::class);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Unable to create database directory');

            $repository->find(1);
        } finally {
            @unlink($archivo);
        }
    }

    public function testADatabaseFileThatCannotBeOpenedCarriesTheDriversReason(): void
    {
        // The path is a directory: SQLite cannot open it, and the message has
        // to carry the driver's own words or there is nothing to act on.
        $dir = sys_get_temp_dir() . '/milpa-data-dir-' . uniqid('', true);
        mkdir($dir, 0o775, true);

        try {
            $repository = new SqliteRepository($dir, TestEntity::class);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Unable to open SQLite database');

            $repository->find(1);
        } finally {
            @rmdir($dir);
        }
    }
}
