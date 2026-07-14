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
 * MySQL document-store {@see RepositoryInterface}: the production root — the same six-method
 * contract as {@see FileRepository} and {@see SqliteRepository}, over a MySQL server, and still
 * zero migrations. Each entity class gets one InnoDB table of two payload columns — `id
 * VARCHAR(191)` plus a native `doc JSON` column holding the entity's `toArray()` — created lazily
 * with `CREATE TABLE IF NOT EXISTS` on first use. The `toArray()`/`fromArray()` pair IS the
 * schema: adding a field to an entity never touches the database.
 *
 * The table name derives from the entity class by the exact rule {@see SqliteRepository} uses —
 * snake_case of the short class name plus a crc32 suffix of the fully-qualified name (`App\Blog\
 * Post` becomes `post_2f1f1893`) — so an entity keeps one table name across backends and two
 * classes with the same short name never silently share a table. Tables are utf8mb4 with the
 * binary collation, so id matching is exact — never case-folded or trailing-space-trimmed — and
 * insertion order of {@see self::all()} rides an internal `seq BIGINT UNSIGNED AUTO_INCREMENT`
 * column, exactly as in the SQLite backend.
 *
 * Concurrency is InnoDB row locking. Every fresh-id save runs a locking read (`SELECT … FOR
 * UPDATE`) inside a transaction — the InnoDB equivalent of SqliteRepository's `BEGIN IMMEDIATE` —
 * and re-saves ride the row lock of the single-statement upsert, so concurrent processes can
 * neither mint the same fresh id nor lose each other's rows; {@see self::save()} documents the
 * reasoning.
 *
 * Takes a DSN, not an injected PDO, because the package's construction idiom is a location plus
 * an entity class ({@see FileRepository} a path, {@see SqliteRepository} a database file): the
 * repository owns its connection and opens it lazily on first use, so construction never touches
 * the network — and when the server cannot be reached, the failure is
 * {@see MysqlRepositoryException}, an error that teaches what broke, why it matters, and the ways
 * out, instead of a bare driver error. Needs `ext-pdo_mysql` (suggested, not required, by the
 * package).
 *
 * @template T of EntityInterface
 *
 * @implements RepositoryInterface<T>
 */
final class MysqlRepository implements RepositoryInterface
{
    private ?\PDO $pdo = null;

    /** The table rows of `$entityClass` live in — see {@see self::tableFor()} for the derivation. */
    private readonly string $table;

    /**
     * @param string          $dsn         PDO DSN of the server and database (e.g. `mysql:host=127.0.0.1;port=3306;dbname=app`); when it names no `charset`, `utf8mb4` is appended so unicode documents survive byte-for-byte
     * @param class-string<T> $entityClass the entity class rows are rehydrated into via `fromArray()`
     * @param string|null     $user        the user to connect as, when not carried by the DSN
     * @param string|null     $password    the password to connect with, when not carried by the DSN
     */
    public function __construct(
        private readonly string $dsn,
        private readonly string $entityClass,
        private readonly ?string $user = null,
        private readonly ?string $password = null,
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
            "SELECT doc FROM `{$this->table}` WHERE id = :id",
            ['id' => (string) $id],
        )->fetchColumn();

        return \is_string($doc) ? $this->hydrate($this->decode($doc)) : null;
    }

    /**
     * Persists `$entity` as one JSON document row. When `$entity->id()` is `null`, the fresh id
     * is computed from a locking read (`SELECT … FOR UPDATE`) inside the transaction — the InnoDB
     * equivalent of SqliteRepository's `BEGIN IMMEDIATE`. Under InnoDB's default REPEATABLE READ
     * a plain SELECT reads a snapshot and takes no locks, so two concurrent savers would compute
     * the same fresh id and the second upsert would silently swallow the first row; `FOR UPDATE`
     * takes exclusive next-key locks over every id it scans plus the gap above them, so a
     * concurrent minting saver blocks until this transaction commits and then sees the row it
     * must not collide with. Re-saves with a preset id ride the upsert itself: one `INSERT … ON
     * DUPLICATE KEY UPDATE` statement is atomic under the unique `id` index's row lock, updating
     * the row in place so the entity's `seq` — its position in {@see self::all()} — never moves.
     * The stored document always carries the id actually used under the key `'id'`, regardless of
     * what `$entity->toArray()` returned for it.
     *
     * @param T $entity
     */
    public function save(EntityInterface $entity): int|string
    {
        $pdo = $this->pdo();

        $pdo->beginTransaction();

        try {
            $id = $entity->id() ?? $this->nextIdFrom($this->storedIdsLocked());

            $row = $entity->toArray();
            $row['id'] = $id;

            // Encode BEFORE writing: if encoding throws, the transaction rolls back with the
            // stored rows untouched — the same discipline as FileRepository's encode-before-truncate.
            $doc = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            $this->run(
                "INSERT INTO `{$this->table}` (id, doc) VALUES (:id, :doc)"
                . ' ON DUPLICATE KEY UPDATE doc = VALUES(doc)',
                ['id' => (string) $id, 'doc' => $doc],
            );

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        return $id;
    }

    /**
     * Removes the row stored under `$id`. A no-op when no entity is stored under it.
     */
    public function delete(int|string $id): void
    {
        $this->run("DELETE FROM `{$this->table}` WHERE id = :id", ['id' => (string) $id]);
    }

    /**
     * Every stored entity, in insertion order — rows come back ordered by the internal
     * auto-increment `seq` column, which records the order ids were first saved regardless of the
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
     * integer id currently stored, or `1` when the store holds no integer id. Ids live in a
     * VARCHAR column, so the maximum is computed in PHP over the fetched ids, exactly like
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
     * fully-qualified name. Deliberately byte-identical to {@see SqliteRepository}'s rule, so an
     * entity keeps one table name across backends and same-named classes from different
     * namespaces never collide within one database.
     */
    private static function tableFor(string $entityClass): string
    {
        $short = substr((string) strrchr('\\' . $entityClass, '\\'), 1);

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $short)) . '_' . hash('crc32b', $entityClass);
    }

    /**
     * @param list<string> $ids ids as stored — their VARCHAR form
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
     * The stored document decoded back to the row `toArray()` produced. The `doc` column is
     * native JSON — MySQL rejects invalid documents at write time — so a non-object here can only
     * mean the table was tampered with outside this class, and it throws instead of guessing.
     *
     * @return array<string,mixed>
     */
    private function decode(string $doc): array
    {
        $row = json_decode($doc, true);

        if (!\is_array($row)) {
            throw new \RuntimeException(
                "Corrupt document in MySQL table '{$this->table}': expected a JSON object, got: {$doc}",
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
        return $this->run("SELECT doc FROM `{$this->table}` ORDER BY seq")->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Every stored id, in its VARCHAR form — a plain snapshot read, for callers outside a
     * transaction ({@see self::nextId()} is advisory by contract).
     *
     * @return list<string>
     */
    private function storedIds(): array
    {
        /** @var list<string> */
        return $this->run("SELECT id FROM `{$this->table}`")->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Every stored id, read under `FOR UPDATE` — only meaningful inside {@see self::save()}'s
     * transaction, where its exclusive next-key locks serialize concurrent fresh-id minting.
     *
     * @return list<string>
     */
    private function storedIdsLocked(): array
    {
        /** @var list<string> */
        return $this->run("SELECT id FROM `{$this->table}` FOR UPDATE")->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Prepares and executes `$sql` with `$params` bound — every value that touches SQL goes
     * through here as a bound parameter, never interpolated, and prepares are native (never
     * emulated), so values travel to the server out-of-band of the SQL text.
     *
     * @param array<string,string> $params
     */
    private function run(string $sql, array $params = []): \PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);
        if ($statement === false) {
            throw new \RuntimeException("Unable to prepare MySQL statement over table '{$this->table}'");
        }

        $statement->execute($params);

        return $statement;
    }

    /**
     * The lazily-opened connection. The first call does all the setup there is: verifies
     * `ext-pdo_mysql` is loaded, connects with a bounded timeout — a teaching error must arrive
     * in seconds, not when the OS gives up — and creates the entity's table via `CREATE TABLE IF
     * NOT EXISTS` (InnoDB, utf8mb4 with binary collation for exact id matching). A connection
     * that cannot be established fails as {@see MysqlRepositoryException}: what broke, why it
     * matters, the fixes.
     */
    private function pdo(): \PDO
    {
        if ($this->pdo instanceof \PDO) {
            return $this->pdo;
        }

        if (!\extension_loaded('pdo_mysql')) {
            throw MysqlRepositoryException::extensionMissing();
        }

        $dsn = str_contains($this->dsn, 'charset=')
            ? $this->dsn
            : rtrim($this->dsn, ';') . ';charset=utf8mb4';

        try {
            $pdo = new \PDO($dsn, $this->user, $this->password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::ATTR_TIMEOUT => 5,
            ]);
        } catch (\PDOException $e) {
            throw MysqlRepositoryException::unreachable($this->dsn, $e);
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `{$this->table}` ("
            . 'seq BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, '
            . 'id VARCHAR(191) NOT NULL UNIQUE, '
            . 'doc JSON NOT NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin',
        );

        return $this->pdo = $pdo;
    }
}
