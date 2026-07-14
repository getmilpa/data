<?php

declare(strict_types=1);

namespace Milpa\Data\Tests;

use Milpa\Data\MysqlRepository;
use Milpa\Data\MysqlRepositoryException;
use Milpa\Data\RepositoryInterface;
use Milpa\Data\Tests\Fixtures\Alt\TestEntity as AltTestEntity;
use Milpa\Data\Tests\Fixtures\TestEntity;

/**
 * Runs the full repository contract against a real MySQL server when `MILPA_DATA_MYSQL_DSN` is
 * set (credentials via `MILPA_DATA_MYSQL_USER` / `MILPA_DATA_MYSQL_PASSWORD`); every test that
 * needs the server skips VISIBLY otherwise, with instructions for enabling it. The
 * teaching-error test needs no server at all, so it always runs.
 */
final class MysqlRepositoryTest extends RepositoryContractTestCase
{
    private const HOW_TO_ENABLE = 'MysqlRepository integration tests need a real MySQL server and were SKIPPED.' . PHP_EOL
        . 'To enable them:' . PHP_EOL
        . '  1. Have MySQL running and create a dedicated throwaway database (NEVER a real one):' . PHP_EOL
        . "     mysql -uroot -p -e 'CREATE DATABASE IF NOT EXISTS milpa_data_test'" . PHP_EOL
        . "  2. export MILPA_DATA_MYSQL_DSN='mysql:host=127.0.0.1;port=3306;dbname=milpa_data_test'" . PHP_EOL
        . '     (credentials: export MILPA_DATA_MYSQL_USER and MILPA_DATA_MYSQL_PASSWORD as needed)' . PHP_EOL
        . '  3. Re-run the suite — the tests create and drop their own tables inside that database.';

    protected function setUp(): void
    {
        // No skip here: the teaching-error test must run without any server. Tests that DO need
        // one go through repository(), which skips with self::HOW_TO_ENABLE when no DSN is set.
        if ($this->dsn() !== null) {
            $this->dropTestTables();
        }
    }

    protected function tearDown(): void
    {
        if ($this->dsn() !== null) {
            $this->dropTestTables();
        }
    }

    protected function createRepository(): RepositoryInterface
    {
        return $this->repository(TestEntity::class);
    }

    public function testAFreshMysqlRepositoryOverTheSameDatabaseRereadsIdentically(): void
    {
        $repo = $this->repository(TestEntity::class);
        $id = $repo->save(new TestEntity(null, 'A', 'draft'));

        $fresh = $this->repository(TestEntity::class);

        $this->assertEquals($repo->find($id), $fresh->find($id));
        $this->assertEquals($repo->all(), $fresh->all());
        $this->assertSame(2, $fresh->nextId(), 'nextId must continue from what is persisted, not reset');
    }

    public function testTheTableIsAutoCreatedWithHonestMysqlTypesUnderTheSharedDerivationRule(): void
    {
        $this->repository(TestEntity::class)->save(new TestEntity(null, 'A', 'draft'));

        $expected = 'test_entity_' . hash('crc32b', TestEntity::class);
        $this->assertSame([$expected], $this->tableNames(), 'expected the same snake_case + crc32-of-FQCN rule as SqliteRepository');

        $table = $this->pdo()->query(
            'SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES'
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$expected}'",
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($table);
        $this->assertSame('InnoDB', $table['ENGINE'], 'the table must be InnoDB — row locking is the concurrency story');
        $this->assertSame('utf8mb4_bin', $table['TABLE_COLLATION'], 'binary collation: id matching must be exact, never case-folded');

        $columns = $this->pdo()->query(
            'SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS'
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$expected}' ORDER BY ORDINAL_POSITION",
        )->fetchAll(\PDO::FETCH_KEY_PAIR);
        $this->assertSame(
            ['seq' => 'bigint', 'id' => 'varchar', 'doc' => 'json'],
            $columns,
            'honest MySQL 8 types: BIGINT auto-increment seq, VARCHAR id, native JSON doc',
        );
    }

    public function testTwoEntityClassesWithTheSameShortNameShareOneDatabaseWithoutContamination(): void
    {
        $repo = $this->repository(TestEntity::class);
        $altRepo = $this->repository(AltTestEntity::class);

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

    public function testStringIdsRoundTripThroughVarcharStorage(): void
    {
        $repo = $this->repository(TestEntity::class);
        $repo->save(new TestEntity('custom-id', 'Fixed', 'draft'));
        $autoId = $repo->save(new TestEntity(null, 'Auto', 'draft'));

        $fresh = $this->repository(TestEntity::class);

        $this->assertSame('custom-id', $fresh->find('custom-id')?->id(), 'a string id must come back as the same string');
        $this->assertSame(1, $fresh->find($autoId)?->id(), 'an integer id must come back as an int, not the VARCHAR the column stores');
        $this->assertSame(2, $fresh->nextId(), 'nextId must see the integer id through its VARCHAR storage');
    }

    public function testAFailingJsonEncodeOnInsertLeavesExistingRowsIntact(): void
    {
        $repo = $this->repository(TestEntity::class);
        $repo->save(new TestEntity(null, 'A', 'draft'));
        $repo->save(new TestEntity(null, 'B', 'published'));

        try {
            $repo->save(new TestEntity(null, "\xB1\x31", 'draft')); // malformed UTF-8: json_encode throws
            $this->fail('saving an entity whose toArray() holds malformed UTF-8 must throw a JsonException');
        } catch (\JsonException) {
            // expected — encoding must fail before any row is written
        }

        $all = $this->repository(TestEntity::class)->all();
        $this->assertCount(2, $all, 'both pre-existing rows must survive the failed save');
        $this->assertSame(['A', 'B'], array_map(static fn (TestEntity $e): string => $e->name, $all));
        $this->assertSame(3, $this->repository(TestEntity::class)->nextId(), 'the failed save must not have consumed an id');
    }

    public function testAFailingJsonEncodeOnUpdateLeavesTheStoredRowIntact(): void
    {
        $repo = $this->repository(TestEntity::class);
        $repo->save(new TestEntity('a', 'Original', 'draft'));

        try {
            $repo->save(new TestEntity('a', "\xB1\x31", 'published')); // update path: same id, malformed UTF-8
            $this->fail('re-saving an existing id with malformed UTF-8 must throw a JsonException');
        } catch (\JsonException) {
            // expected — encoding must fail before the stored row is touched
        }

        $found = $this->repository(TestEntity::class)->find('a');
        $this->assertNotNull($found, 'the stored row must survive the failed update');
        $this->assertSame('Original', $found->name, 'the stored document must be exactly what the last successful save wrote');
        $this->assertSame('draft', $found->status);
    }

    public function testAnUnreachableMysqlServerTeachesInsteadOfDyingRaw(): void
    {
        // Port 1 on loopback: connection refused instantly, no server needed. The password rides
        // the DSN on purpose — the error message must redact it.
        $repo = new MysqlRepository(
            'mysql:host=127.0.0.1;port=1;dbname=milpa_data_test;password=deliberately-leaked',
            TestEntity::class,
        );

        try {
            $repo->all();
            $this->fail('an unreachable MySQL server must throw the teaching MysqlRepositoryException');
        } catch (MysqlRepositoryException $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString('127.0.0.1:1', $message, 'the error must say WHERE it tried to connect');
            $this->assertStringContainsString('Why it matters', $message, 'the error must say why the failure blocks everything');
            $this->assertStringContainsString('Fixes:', $message, 'the error must offer the ways out, not just the diagnosis');
            $this->assertStringContainsString('storage.driver=file', $message, 'the zero-service escape hatch must be taught');
            $this->assertStringContainsString('FileRepository', $message);
            $this->assertStringContainsString('MYSQL_HOST', $message, 'the env vars behind a Milpa DSN must be named');
            $this->assertStringNotContainsString('deliberately-leaked', $message, 'a DSN password must never leak into the error');
            $this->assertInstanceOf(\PDOException::class, $e->getPrevious(), 'the original driver error must survive as previous');
        }
    }

    /**
     * A repository over the env-provided server, or a VISIBLE skip explaining how to enable it.
     *
     * @template T of \Milpa\Data\EntityInterface
     *
     * @param class-string<T> $entityClass
     *
     * @return MysqlRepository<T>
     */
    private function repository(string $entityClass): MysqlRepository
    {
        $dsn = $this->dsn();
        if ($dsn === null) {
            $this->markTestSkipped(self::HOW_TO_ENABLE);
        }

        return new MysqlRepository($dsn, $entityClass, $this->env('MILPA_DATA_MYSQL_USER'), $this->env('MILPA_DATA_MYSQL_PASSWORD'));
    }

    private function dsn(): ?string
    {
        return $this->env('MILPA_DATA_MYSQL_DSN');
    }

    private function env(string $name): ?string
    {
        $value = getenv($name);

        return \is_string($value) && $value !== '' ? $value : null;
    }

    /** Drops the tables this test file can create, so every test starts from an empty database. */
    private function dropTestTables(): void
    {
        $pdo = $this->pdo();
        foreach ([TestEntity::class, AltTestEntity::class] as $class) {
            $short = substr((string) strrchr('\\' . $class, '\\'), 1);
            $table = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $short)) . '_' . hash('crc32b', $class);
            $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
        }
    }

    /**
     * The user tables currently in the test database, by name.
     *
     * @return list<string>
     */
    private function tableNames(): array
    {
        $statement = $this->pdo()->query(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME',
        );
        $this->assertNotFalse($statement);

        /** @var list<string> */
        return $statement->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** A direct connection to the env-provided server, for assertions that look behind the repository. */
    private function pdo(): \PDO
    {
        return new \PDO(
            (string) $this->dsn(),
            $this->env('MILPA_DATA_MYSQL_USER'),
            $this->env('MILPA_DATA_MYSQL_PASSWORD'),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
    }
}
