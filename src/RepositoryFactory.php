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
 * Constructs the right {@see RepositoryInterface} backend from one config array — the seam that
 * makes the backend ONE CONFIG LINE. The array is the canonical nested `storage` block of a Milpa
 * app's `config/app.php` (read in a plugin's `boot()` as `$config->get('storage', [...])`):
 * `driver` picks the backend — `'file'`, `'sqlite'`, `'mysql'` or `'memory'` — and the remaining
 * keys are that backend's constructor arguments (`path` for file and sqlite, `dsn` plus optional
 * `user`/`password` for mysql, nothing for memory). Switching a store from a JSON file to SQLite
 * or to a MySQL server is editing `driver` and its location key: no code changes anywhere.
 *
 * The factory adds ZERO semantics: each arm delegates to the backend's own constructor, so
 * everything those constructors promise — lazy connections, lock disciplines, table derivation —
 * holds unchanged for a factory-built repository. What it adds is teaching at the config seam: a
 * missing or unknown driver names the four valid values, and a driver-specific key that is absent
 * names the exact key with a copy-pasteable example, instead of failing somewhere deeper with a
 * bare type error.
 */
final class RepositoryFactory
{
    /** The `Fix:` line every driver error ends with — the four valid drivers, each with its one-line story. */
    private const VALID_DRIVERS = 'Fix: set storage.driver to one of: '
        . "'file' (FileRepository — the whole collection in one JSON file), "
        . "'sqlite' (SqliteRepository — a real database in one file), "
        . "'mysql' (MysqlRepository — a MySQL server, the production root), "
        . "'memory' (InMemoryRepository — nothing outlives the process; tests).";

    /** Static-only: the factory carries no state — everything happens in {@see self::fromConfig()}. */
    private function __construct()
    {
    }

    /**
     * The repository the `storage` config describes, bound to `$entityClass`. Config shape:
     * `['driver' => 'file'|'sqlite'|'mysql'|'memory']` plus the driver's own keys — `path` (file:
     * the JSON collection file; sqlite: the database file), or `dsn` with optional `user` and
     * `password` (mysql). Every misconfiguration throws an `\InvalidArgumentException` that
     * teaches the fix: the four valid drivers, or the exact missing key with an example.
     *
     * @template T of EntityInterface
     *
     * @param array<string, mixed> $storage     the app's nested `storage` config block
     * @param class-string<T>      $entityClass the entity class the repository is bound to
     *
     * @return RepositoryInterface<T>
     *
     * @throws \InvalidArgumentException when the driver is missing or unknown, or a driver-specific required key is absent
     */
    public static function fromConfig(array $storage, string $entityClass): RepositoryInterface
    {
        $driver = $storage['driver'] ?? null;

        if ($driver === null || $driver === '') {
            throw new \InvalidArgumentException(
                'RepositoryFactory needs a storage driver, and the config names none.' . PHP_EOL
                . 'Why it matters: storage.driver is the one config line that picks the persistence backend — '
                . 'without it the factory cannot know where your entities should live.' . PHP_EOL
                . self::VALID_DRIVERS,
            );
        }

        return match ($driver) {
            'file' => new FileRepository(
                self::requiredString($storage, 'path', 'file', "'path' => '/var/data/articles.json' — the JSON collection file"),
                $entityClass,
            ),
            'sqlite' => new SqliteRepository(
                self::requiredString($storage, 'path', 'sqlite', "'path' => '/var/data/app.db' — the SQLite database file"),
                $entityClass,
            ),
            'mysql' => new MysqlRepository(
                self::requiredString(
                    $storage,
                    'dsn',
                    'mysql',
                    "'dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=app' — credentials ride storage.user / "
                    . 'storage.password when the DSN does not carry them',
                ),
                $entityClass,
                self::optionalString($storage, 'user'),
                self::optionalString($storage, 'password'),
            ),
            'memory' => new InMemoryRepository($entityClass),
            default => throw new \InvalidArgumentException(
                'RepositoryFactory does not know the storage driver '
                . (\is_string($driver) ? "'{$driver}'" : 'of type ' . get_debug_type($driver)) . '.' . PHP_EOL
                . 'Why it matters: storage.driver is the one config line that picks the persistence backend — '
                . 'an unrecognized value means no repository can be constructed at all.' . PHP_EOL
                . self::VALID_DRIVERS,
            ),
        };
    }

    /**
     * The non-empty string stored under `$key`, or the teaching error naming the exact key —
     * `storage.{$key}` — and a copy-pasteable example. An empty string is as absent as a missing
     * key: `''` opens nothing.
     *
     * @param array<string, mixed> $storage
     */
    private static function requiredString(array $storage, string $key, string $driver, string $example): string
    {
        $value = $storage[$key] ?? null;

        if (\is_string($value) && $value !== '') {
            return $value;
        }

        throw new \InvalidArgumentException(
            "The '{$driver}' storage driver needs storage.{$key}, and the config carries none." . PHP_EOL
            . "Why it matters: storage.{$key} is where the '{$driver}' backend keeps (or reaches) your "
            . 'entities — without it there is nothing to open.' . PHP_EOL
            . "Fix: set it next to storage.driver, e.g. {$example}.",
        );
    }

    /**
     * The string stored under `$key`, or `null` when the key is absent, empty, or not a string —
     * the optional-credential semantics of {@see MysqlRepository}'s `user`/`password` parameters.
     *
     * @param array<string, mixed> $storage
     */
    private static function optionalString(array $storage, string $key): ?string
    {
        $value = $storage[$key] ?? null;

        return \is_string($value) && $value !== '' ? $value : null;
    }
}
