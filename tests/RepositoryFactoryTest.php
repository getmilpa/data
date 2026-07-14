<?php

declare(strict_types=1);

namespace Milpa\Data\Tests;

use Milpa\Data\FileRepository;
use Milpa\Data\InMemoryRepository;
use Milpa\Data\MysqlRepository;
use Milpa\Data\RepositoryFactory;
use Milpa\Data\SqliteRepository;
use Milpa\Data\Tests\Fixtures\TestEntity;
use PHPUnit\Framework\TestCase;

/**
 * Covers {@see RepositoryFactory}: each driver constructs the right backend (and the constructed
 * repository is genuinely bound to its location and entity class — proven by persisting through
 * it, not by class name alone), and every misconfiguration teaches — the four valid drivers when
 * the driver is missing or unknown, the exact missing key plus an example when a driver-specific
 * key is absent. The MySQL construction test needs no server: {@see MysqlRepository} opens its
 * connection lazily on first use, so constructing against a dead DSN proves the factory wiring;
 * the full round trip runs when `MILPA_DATA_MYSQL_DSN` is set, and skips visibly otherwise.
 */
final class RepositoryFactoryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/milpa-data-factory-' . uniqid();
        mkdir($this->dir, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }

        $dsn = $this->env('MILPA_DATA_MYSQL_DSN');
        if ($dsn !== null) {
            $table = 'test_entity_' . hash('crc32b', TestEntity::class);
            (new \PDO($dsn, $this->env('MILPA_DATA_MYSQL_USER'), $this->env('MILPA_DATA_MYSQL_PASSWORD')))
                ->exec("DROP TABLE IF EXISTS `{$table}`");
        }
    }

    public function testMemoryDriverConstructsAnInMemoryRepository(): void
    {
        $repo = RepositoryFactory::fromConfig(['driver' => 'memory'], TestEntity::class);

        $this->assertInstanceOf(InMemoryRepository::class, $repo);

        // The entity class reached the constructor: a saved row rehydrates as a TestEntity.
        $id = $repo->save(new TestEntity(null, 'A', 'draft'));
        $this->assertInstanceOf(TestEntity::class, $repo->find($id));
    }

    public function testFileDriverConstructsAFileRepositoryBoundToThePathAndEntityClass(): void
    {
        $path = $this->dir . '/articles.json';
        $repo = RepositoryFactory::fromConfig(['driver' => 'file', 'path' => $path], TestEntity::class);

        $this->assertInstanceOf(FileRepository::class, $repo);

        // Both constructor arguments demonstrably arrived: a save lands at the configured path,
        // and a repository built DIRECTLY over that path rereads it as the configured entity.
        $id = $repo->save(new TestEntity(null, 'Persisted', 'draft'));
        $this->assertFileExists($path);
        $reread = (new FileRepository($path, TestEntity::class))->find($id);
        $this->assertInstanceOf(TestEntity::class, $reread);
        $this->assertSame('Persisted', $reread->name);
    }

    public function testSqliteDriverConstructsASqliteRepositoryBoundToThePathAndEntityClass(): void
    {
        $path = $this->dir . '/app.db';
        $repo = RepositoryFactory::fromConfig(['driver' => 'sqlite', 'path' => $path], TestEntity::class);

        $this->assertInstanceOf(SqliteRepository::class, $repo);

        if (!\extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('construction verified; the persistence round trip needs ext-pdo_sqlite');
        }

        $id = $repo->save(new TestEntity(null, 'Persisted', 'draft'));
        $this->assertFileExists($path);
        $reread = (new SqliteRepository($path, TestEntity::class))->find($id);
        $this->assertInstanceOf(TestEntity::class, $reread);
        $this->assertSame('Persisted', $reread->name);
    }

    /**
     * Needs no server: {@see MysqlRepository}'s constructor never touches the network (the
     * connection opens lazily on first use), so constructing against a dead DSN is a real proof
     * of the factory's `mysql` arm. The live round trip is the test below.
     */
    public function testMysqlDriverConstructsAMysqlRepositoryWithoutTouchingTheServer(): void
    {
        $repo = RepositoryFactory::fromConfig(
            ['driver' => 'mysql', 'dsn' => 'mysql:host=127.0.0.1;port=1;dbname=nowhere'],
            TestEntity::class,
        );

        $this->assertInstanceOf(MysqlRepository::class, $repo);
    }

    public function testMysqlDriverBuiltFromConfigPersistsAgainstARealServer(): void
    {
        $dsn = $this->env('MILPA_DATA_MYSQL_DSN');
        if ($dsn === null) {
            $this->markTestSkipped(
                'The factory-built MySQL round trip needs a real server — set MILPA_DATA_MYSQL_DSN '
                . '(see MysqlRepositoryTest for the full how-to); the construction-only test above always runs.',
            );
        }

        $config = [
            'driver' => 'mysql',
            'dsn' => $dsn,
            'user' => $this->env('MILPA_DATA_MYSQL_USER'),
            'password' => $this->env('MILPA_DATA_MYSQL_PASSWORD'),
        ];

        $id = RepositoryFactory::fromConfig($config, TestEntity::class)
            ->save(new TestEntity(null, 'Persisted', 'draft'));

        // A SECOND factory-built repository over the same config rereads it: dsn, user and
        // password all demonstrably reached MysqlRepository's constructor.
        $reread = RepositoryFactory::fromConfig($config, TestEntity::class)->find($id);
        $this->assertInstanceOf(TestEntity::class, $reread);
        $this->assertSame('Persisted', $reread->name);
    }

    public function testAMissingDriverTeachesTheFourValidDrivers(): void
    {
        try {
            RepositoryFactory::fromConfig([], TestEntity::class);
            $this->fail('a config without a driver must throw the teaching InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString('storage.driver', $message, 'the error must name the exact config key to set');
            $this->assertStringContainsString('Why it matters', $message, 'the error must say why the failure blocks everything');
            foreach (['file', 'sqlite', 'mysql', 'memory'] as $driver) {
                $this->assertStringContainsString("'{$driver}'", $message, "the four valid drivers must be taught — '{$driver}' is missing");
            }
        }
    }

    public function testAnUnknownDriverTeachesTheFourValidDrivers(): void
    {
        try {
            RepositoryFactory::fromConfig(['driver' => 'redis'], TestEntity::class);
            $this->fail('an unknown driver must throw the teaching InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString("'redis'", $message, 'the error must echo the driver it did not recognize');
            foreach (['file', 'sqlite', 'mysql', 'memory'] as $driver) {
                $this->assertStringContainsString("'{$driver}'", $message, "the four valid drivers must be taught — '{$driver}' is missing");
            }
        }
    }

    public function testANonStringDriverIsTaughtAsUnknown(): void
    {
        try {
            RepositoryFactory::fromConfig(['driver' => 42], TestEntity::class);
            $this->fail('a non-string driver must throw the teaching InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('int', $e->getMessage(), 'the error must say what type it got instead of a driver name');
            $this->assertStringContainsString("'file'", $e->getMessage());
        }
    }

    public function testFileWithoutAPathTeachesTheExactKeyAndAnExample(): void
    {
        try {
            RepositoryFactory::fromConfig(['driver' => 'file'], TestEntity::class);
            $this->fail("the 'file' driver without a path must throw the teaching InvalidArgumentException");
        } catch (\InvalidArgumentException $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString('storage.path', $message, 'the error must name the exact missing key');
            $this->assertStringContainsString("'file'", $message, 'the error must name the driver that needs the key');
            $this->assertStringContainsString('/var/data/articles.json', $message, 'the error must carry a copy-pasteable example');
        }
    }

    public function testSqliteWithoutAPathTeachesTheExactKeyAndAnExample(): void
    {
        try {
            RepositoryFactory::fromConfig(['driver' => 'sqlite'], TestEntity::class);
            $this->fail("the 'sqlite' driver without a path must throw the teaching InvalidArgumentException");
        } catch (\InvalidArgumentException $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString('storage.path', $message, 'the error must name the exact missing key');
            $this->assertStringContainsString("'sqlite'", $message, 'the error must name the driver that needs the key');
            $this->assertStringContainsString('/var/data/app.db', $message, 'the error must carry a copy-pasteable example');
        }
    }

    public function testMysqlWithoutADsnTeachesTheExactKeyAndAnExample(): void
    {
        try {
            RepositoryFactory::fromConfig(['driver' => 'mysql'], TestEntity::class);
            $this->fail("the 'mysql' driver without a dsn must throw the teaching InvalidArgumentException");
        } catch (\InvalidArgumentException $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString('storage.dsn', $message, 'the error must name the exact missing key');
            $this->assertStringContainsString("'mysql'", $message, 'the error must name the driver that needs the key');
            $this->assertStringContainsString('mysql:host=', $message, 'the error must carry a copy-pasteable DSN example');
            $this->assertStringContainsString('storage.user', $message, 'the credential keys must be taught alongside the DSN');
        }
    }

    /** An empty-string value is as absent as a missing key — '' opens nothing. */
    public function testAnEmptyStringPathIsAsMissingAsNoPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('storage.path');

        RepositoryFactory::fromConfig(['driver' => 'file', 'path' => ''], TestEntity::class);
    }

    private function env(string $name): ?string
    {
        $value = getenv($name);

        return \is_string($value) && $value !== '' ? $value : null;
    }
}
