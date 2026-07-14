<p align="center">
  <a href="https://github.com/getmilpa">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-dark.svg">
      <img src="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-light.svg" alt="Milpa" width="300">
    </picture>
  </a>
</p>

# Milpa Data

> Runtime-native **persistence** for Milpa: plain entities (no ORM base class, no attributes) behind a small repository contract, with four interchangeable backends — **file** (JSON), **SQLite** and **MySQL** (document-store) and **in-memory**. Zero Doctrine, zero migrations, zero infrastructure. The persistence primitive an agent-scaffolded entity targets.

[![CI](https://github.com/getmilpa/data/actions/workflows/ci.yml/badge.svg)](https://github.com/getmilpa/data/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/milpa/data.svg)](https://packagist.org/packages/milpa/data)
[![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.3-777bb4.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)
[![Docs](https://img.shields.io/badge/docs-API%20reference-blue.svg)](https://getmilpa.github.io/data/)

`milpa/data` is the smallest possible seam onto persistence: an entity is any final class that
implements `EntityInterface` — no base class to extend, no attributes to annotate — and a
repository's only jobs are "find by id", "save", "delete", "list", and "query by equality".
**No ORM, no query language, no schema migrations** — construct a repository with a path (or
nothing at all) and call `save()`.

## Install

```bash
composer require milpa/data
```

## Quick example

```php
use Milpa\Data\EntityInterface;
use Milpa\Data\FileRepository;

final readonly class Article implements EntityInterface
{
    public function __construct(
        public int|string|null $id,
        public string $title,
        public string $status,
    ) {
    }

    public function id(): int|string|null
    {
        return $this->id;
    }

    public function toArray(): array
    {
        return ['id' => $this->id, 'title' => $this->title, 'status' => $this->status];
    }

    public static function fromArray(array $row): static
    {
        return new self($row['id'] ?? null, $row['title'], $row['status']);
    }
}

$repo = new FileRepository('/var/data/articles.json', Article::class);

// Save: no id yet, so the repository assigns one and hands it back.
$id = $repo->save(new Article(null, 'Hello Milpa', 'draft'));

// A fresh instance, pointed at the same file, reads back exactly what was written —
// no shared state, no cache, just the file itself.
$reread = new FileRepository('/var/data/articles.json', Article::class);
$found = $reread->find($id);
printf("%s (%s)\n", $found->title, $found->status);
// Hello Milpa (draft)

$reread->query(['status' => 'draft']); // [Article{id: 1, title: 'Hello Milpa', status: 'draft'}]
```

## Four backends, one interface

| | `FileRepository` | `SqliteRepository` | `MysqlRepository` | `InMemoryRepository` |
|---|------------------|--------------------|-------------------|----------------------|
| **Durability** | Read-modify-write on every mutation: the whole collection lives in one JSON file, keyed by id. A fresh instance pointed at the same path — a different process, a different request — reads back exactly what the last write left, because every read and write goes through the file itself rather than an in-memory cache. | A real SQLite database file: one table per entity class (`id` + `doc`, the entity's `toArray()` as JSON), created on first use — zero migrations, because `toArray()`/`fromArray()` is the schema. Survives processes and restarts; open it with any SQLite tool. | A real MySQL server — the production root: one InnoDB table per entity class (`id VARCHAR` + native `doc JSON`), created on first use, utf8mb4 end to end — still zero migrations, because `toArray()`/`fromArray()` is the schema. Survives processes, restarts and hosts; when the server is unreachable, the error teaches the fix instead of dying raw. | An in-process array. Nothing is written to disk; nothing survives past the instance's lifetime. |
| **Concurrency** | Every mutation runs under an exclusive `flock` held across the whole read-modify-write cycle, and every read takes a shared lock — concurrent processes over the same file can neither observe a torn write nor lose each other's rows. | SQLite's own locking; every save runs inside an immediate transaction held across id assignment and the write, so concurrent savers can neither mint the same fresh id nor lose each other's rows. | InnoDB row locking: fresh-id assignment runs a locking read (`SELECT … FOR UPDATE`) inside a transaction, and re-saves ride the upsert's own row lock — concurrent savers can neither mint the same fresh id nor lose each other's rows. | Single-process by construction: the array is never shared beyond the instance. |
| **Use it for** | Real persistence — no database, no ORM, no infrastructure to stand up. | Real persistence with a real database file — still zero migrations and zero infrastructure; needs only `ext-pdo_sqlite`. | Production persistence on a shared database server — still zero migrations; needs `ext-pdo_mysql` and a MySQL to talk to. | Tests, and zero-file consumers that don't need durability. |

All four implement the same six-method `RepositoryInterface` (`find()`, `save()`, `delete()`,
`all()`, `nextId()`, `query()`), verified by one shared contract test suite
(`RepositoryContractTestCase`) so behavior — id assignment, equality querying, insertion order
of `all()`, isolation between entities — never drifts between backends.

## Choosing a backend

Because all four backends honor the same contract, **the backend is one config line**.
`RepositoryFactory::fromConfig()` takes a `storage` config array — `driver` picks the backend,
the remaining keys are that backend's constructor arguments — and hands back the right
repository:

```php
use Milpa\Data\RepositoryFactory;

$repo = RepositoryFactory::fromConfig([
    'driver' => 'file',
    'path'   => '/var/data/articles.json',
], Article::class);
```

Change `'file'` to `'sqlite'` and the same entities land in a real database file. Change it to
`'mysql'` and they land on a server. **No other line of code moves** — `find()`, `save()`,
`query()` and everything else behave identically, because the factory adds zero semantics: each
driver delegates straight to the backend's own constructor.

| `driver` | Its keys | Example |
|----------|----------|---------|
| `file` | `path` — the JSON collection file | `['driver' => 'file', 'path' => '/var/data/articles.json']` |
| `sqlite` | `path` — the SQLite database file | `['driver' => 'sqlite', 'path' => '/var/data/app.db']` |
| `mysql` | `dsn`, plus `user` / `password` when the DSN doesn't carry them | `['driver' => 'mysql', 'dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=app', 'user' => 'app', 'password' => '…']` |
| `memory` | — | `['driver' => 'memory']` |

In a Milpa app, this array is the `storage` block of `config/app.php`, and a plugin builds its
repository from it in `boot()` (the entities scaffolded by `milpa/devtools`' `make:entity` wire
exactly this):

```php
// config/app.php
return [
    'storage' => [
        'driver' => 'sqlite',                        // ← the one line
        'path'   => __DIR__ . '/../var/data/app.db',
    ],
];

// in a plugin's boot()
$storage = $this->container->get(Config::class)->get('storage', [
    'driver' => 'file',
    'path'   => $root . '/var/articles.json',       // zero-config default
]);
$this->container->registerService(
    Article::class . 'Repository',
    RepositoryFactory::fromConfig($storage, Article::class),
);
```

Misconfiguration teaches instead of failing raw: a missing or unknown `driver` throws an error
naming the four valid values, and a driver missing its key (`file` without `path`, `mysql`
without `dsn`) names the exact key with a copy-pasteable example.

## Requirements

- PHP **≥ 8.3**
- `ext-pdo_sqlite` **only** if you use `SqliteRepository` (suggested, never required)
- `ext-pdo_mysql` — and a MySQL server — **only** if you use `MysqlRepository` (suggested, never required)
- Nothing else — `milpa/data` has no package dependencies, Milpa or otherwise

## Documentation

**Full API reference: [getmilpa.github.io/data](https://getmilpa.github.io/data/)** —
generated straight from the source DocBlocks and dressed with the Milpa design system.

## Contributing

Contributions are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). Please report security
issues via [SECURITY.md](SECURITY.md), and note that this project follows a
[Code of Conduct](CODE_OF_CONDUCT.md).

## License

[Apache-2.0](LICENSE) © Rodrigo Vicente - TeamX Agency.

---

Milpa is designed, built, and maintained by **[Rodrigo Vicente - TeamX Agency](https://teamx.agency/?utm_source=github&utm_medium=readme&utm_campaign=milpa&utm_content=data)**.
