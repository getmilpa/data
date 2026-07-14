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
 * SQLite document-store {@see RepositoryInterface}: the same six-method contract as
 * {@see FileRepository}, over a real database file — and still zero migrations, because the table
 * is not a schema. Each entity class gets one table of two payload columns — `id TEXT` plus `doc
 * TEXT` holding the entity's `toArray()` as JSON — created lazily with `CREATE TABLE IF NOT
 * EXISTS` on first use. The `toArray()`/`fromArray()` pair IS the schema: adding a field to an
 * entity never touches the database.
 *
 * The table name derives from the entity class: snake_case of the short class name plus a crc32
 * suffix of the fully-qualified name — `App\Blog\Post` becomes `post_2f1f1893`. The suffix is what
 * lets two classes with the same short name share one database file without silently sharing a
 * table. Ids live in the `id TEXT` column through their string form, so — as with the PHP array
 * keys backing {@see FileRepository} — `1` and `'1'` name the same row; the typed id survives
 * inside the JSON document.
 *
 * Insertion order of {@see self::all()} rides an internal `seq INTEGER PRIMARY KEY AUTOINCREMENT`
 * column; re-saving an existing id updates its row in place, so `seq` — and with it the entity's
 * position — never moves. Concurrency is SQLite's own locking, the way `flock` is
 * {@see FileRepository}'s: every save runs inside an immediate transaction held across id
 * assignment and the write, so concurrent processes can neither mint the same fresh id nor lose
 * each other's rows.
 *
 * Needs `ext-pdo_sqlite` (suggested, not required, by the package — the other backends need no
 * extension). A missing extension, or a database path that cannot be created or opened, fails
 * with an error that says what broke, why it matters, and how to fix it.
 *
 * @template T of EntityInterface
 *
 * @implements RepositoryInterface<T>
 */
final class SqliteRepository implements RepositoryInterface
{
    private ?\PDO $pdo = null;

    /** The table rows of `$entityClass` live in — see {@see self::tableFor()} for the derivation. */
    private readonly string $table;

    /**
     * @param string          $dbPath      path to the SQLite database file; the file — and its directory — are created on first use if missing
     * @param class-string<T> $entityClass the entity class rows are rehydrated into via `fromArray()`
     */
    public function __construct(
        private readonly string $dbPath,
        private readonly string $entityClass,
    ) {
        $this->table = self::tableFor($entityClass);
    }

    /**
     * The entity stored under `$id`, or `null` when no entity is stored under it.
     *
     * @return T|null
     */
    public function find(int|string $id): ?EntityInterface
    {
        $doc = $this->run(
            "SELECT doc FROM \"{$this->table}\" WHERE id = :id",
            ['id' => (string) $id],
        )->fetchColumn();

        return \is_string($doc) ? $this->hydrate($this->decode($doc)) : null;
    }

    /**
     * Persists `$entity` as one JSON document row, inside an immediate transaction held across id
     * assignment and the write — so a concurrent saver against the same file can neither mint the
     * same fresh id nor be overwritten. When `$entity->id()` is `null`, a fresh id is assigned via
     * {@see self::nextId()}. Re-saving an existing id updates its row in place, keeping the
     * entity's insertion position in {@see self::all()}. The stored document always carries the id
     * actually used under the key `'id'`, regardless of what `$entity->toArray()` returned for it.
     *
     * @param T $entity
     */
    public function save(EntityInterface $entity): int|string
    {
        $pdo = $this->pdo();

        $pdo->exec('BEGIN IMMEDIATE');

        try {
            $id = $entity->id() ?? $this->nextIdFrom($this->storedIds());

            $row = $entity->toArray();
            $row['id'] = $id;

            // Encode BEFORE writing: if encoding throws, the transaction rolls back with the
            // stored rows untouched — the same discipline as FileRepository's encode-before-truncate.
            $doc = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            $this->run(
                "INSERT INTO \"{$this->table}\" (id, doc) VALUES (:id, :doc)"
                . ' ON CONFLICT(id) DO UPDATE SET doc = excluded.doc',
                ['id' => (string) $id, 'doc' => $doc],
            );

            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $pdo->exec('ROLLBACK');

            throw $e;
        }

        return $id;
    }

    /**
     * Removes the row stored under `$id`. A no-op when no entity is stored under it.
     */
    public function delete(int|string $id): void
    {
        $this->run("DELETE FROM \"{$this->table}\" WHERE id = :id", ['id' => (string) $id]);
    }

    /**
     * Every stored entity, in insertion order — rows come back ordered by the internal
     * autoincrement `seq` column, which records the order ids were first saved regardless of the
     * ids themselves.
     *
     * @return list<T>
     */
    public function all(): array
    {
        return array_map(
            fn (string $doc): EntityInterface => $this->hydrate($this->decode($doc)),
            $this->docs(),
        );
    }

    /**
     * The id to assign to the next entity saved without one of its own — one past the highest
     * integer id currently stored, or `1` when the store holds no integer id. Ids live in a TEXT
     * column, so the maximum is computed in PHP over the fetched ids, exactly like
     * `FileRepository` does over its array keys — a stored `'42'` counts as the integer it names,
     * `'custom-id'` does not.
     */
    public function nextId(): int
    {
        return $this->nextIdFrom($this->storedIds());
    }

    /**
     * Stored entities matching every `$criteria` pair by strict equality. Deliberately filtered in
     * PHP after fetching every row — not pushed into SQL — so equality semantics stay exactly
     * {@see FileRepository}'s (`===` over the decoded `toArray()` values, JSON types intact), at
     * the cost of reading the whole table. For the collection sizes this backend targets that is
     * the right trade; a SQL-side filter could come later without touching the contract.
     *
     * @param array<string,mixed> $criteria
     *
     * @return list<T>
     */
    public function query(array $criteria): array
    {
        $matches = array_filter(
            array_map($this->decode(...), $this->docs()),
            fn (array $row): bool => $this->matches($row, $criteria),
        );

        return array_values(array_map($this->hydrate(...), $matches));
    }

    /**
     * The table name for `$entityClass`: snake_case of the short class name (an underscore before
     * every uppercase letter after the first, then lowercased) plus `_` and the crc32 of the
     * fully-qualified name — so same-named classes from different namespaces never collide within
     * one database file.
     */
    private static function tableFor(string $entityClass): string
    {
        $short = substr((string) strrchr('\\' . $entityClass, '\\'), 1);

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $short)) . '_' . hash('crc32b', $entityClass);
    }

    /**
     * @param list<string> $ids ids as stored — their TEXT form
     */
    private function nextIdFrom(array $ids): int
    {
        $intIds = [];
        foreach ($ids as $id) {
            if ((string) (int) $id === $id) {
                $intIds[] = (int) $id;
            }
        }

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
     * The stored document decoded back to the row `toArray()` produced. A non-object document can
     * only mean the table was tampered with outside this class, so it throws instead of guessing.
     *
     * @return array<string,mixed>
     */
    private function decode(string $doc): array
    {
        $row = json_decode($doc, true);

        if (!\is_array($row)) {
            throw new \RuntimeException(
                "Corrupt document in SQLite table '{$this->table}' ({$this->dbPath}): expected a JSON object, got: {$doc}",
            );
        }

        return $row;
    }

    /**
     * Every stored document, in insertion order (`ORDER BY seq`).
     *
     * @return list<string>
     */
    private function docs(): array
    {
        /** @var list<string> */
        return $this->run("SELECT doc FROM \"{$this->table}\" ORDER BY seq")->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Every stored id, in its TEXT form.
     *
     * @return list<string>
     */
    private function storedIds(): array
    {
        /** @var list<string> */
        return $this->run("SELECT id FROM \"{$this->table}\"")->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Prepares and executes `$sql` with `$params` bound — every value that touches SQL goes
     * through here as a bound parameter, never interpolated.
     *
     * @param array<string,string> $params
     */
    private function run(string $sql, array $params = []): \PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);
        if ($statement === false) {
            throw new \RuntimeException("Unable to prepare SQLite statement over: {$this->dbPath}");
        }

        $statement->execute($params);

        return $statement;
    }

    /**
     * The lazily-opened connection. The first call does all the setup there is: verifies
     * `ext-pdo_sqlite` is loaded, creates the database directory if missing (mirroring
     * {@see FileRepository}'s first-save behavior), opens the file in exception mode, and creates
     * the entity's table via `CREATE TABLE IF NOT EXISTS`.
     */
    private function pdo(): \PDO
    {
        if ($this->pdo instanceof \PDO) {
            return $this->pdo;
        }

        if (!\extension_loaded('pdo_sqlite')) {
            throw new \RuntimeException(
                "SqliteRepository needs the PHP extension 'pdo_sqlite', which is not loaded." . PHP_EOL
                . 'Why it matters: this backend stores entities in a SQLite database file, and PHP talks to SQLite only through ext-pdo_sqlite.' . PHP_EOL
                . 'Fixes:' . PHP_EOL
                . '  1. Install/enable the extension (Debian/Ubuntu: apt install php-sqlite3; Fedora: dnf install php-pdo; then restart PHP).' . PHP_EOL
                . '  2. Or switch to FileRepository — the same six-method contract over a JSON file, no extension needed.',
            );
        }

        $dir = \dirname($this->dbPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(
                "Unable to create database directory: {$dir}" . PHP_EOL
                . 'Why it matters: SQLite keeps the whole database in one file, and that file cannot exist until its directory does.' . PHP_EOL
                . "Fixes: create the directory yourself (mkdir -p {$dir}), fix the permissions that stopped PHP from creating it, or point SqliteRepository at a writable path.",
            );
        }

        try {
            $pdo = new \PDO('sqlite:' . $this->dbPath, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (\PDOException $e) {
            throw new \RuntimeException(
                "Unable to open SQLite database: {$this->dbPath} ({$e->getMessage()})" . PHP_EOL
                . 'Why it matters: every read and write goes through this one file; until it opens, nothing can be stored or found.' . PHP_EOL
                . 'Fixes: make the file and its directory readable and writable by this process, or point SqliteRepository at a path it may create.',
                0,
                $e,
            );
        }

        // Wait for a concurrent writer's lock instead of failing instantly with SQLITE_BUSY.
        $pdo->exec('PRAGMA busy_timeout = 5000');

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS \"{$this->table}\" ("
            . 'seq INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'id TEXT NOT NULL UNIQUE, '
            . 'doc TEXT NOT NULL)',
        );

        return $this->pdo = $pdo;
    }
}
