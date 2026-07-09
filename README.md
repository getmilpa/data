<p align="center">
  <a href="https://github.com/getmilpa">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-dark.svg">
      <img src="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-light.svg" alt="Milpa" width="300">
    </picture>
  </a>
</p>

# Milpa Data

> Runtime-native **persistence** for Milpa: plain entities (no ORM base class, no attributes) behind a small repository contract, with two interchangeable backends — **file** (JSON) and **in-memory**. Zero Doctrine, zero database, zero infrastructure. The persistence primitive an agent-scaffolded entity targets.

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

## Two backends, one interface

| Repository | Durability | Use it for |
|-------|-----------|------------|
| `FileRepository` | Read-modify-write on every mutation: the whole collection lives in one JSON file, keyed by id. A fresh instance pointed at the same path — a different process, a different request — reads back exactly what the last write left, because every read and write goes through the file itself rather than an in-memory cache. | Real persistence — no database, no ORM, no infrastructure to stand up. |
| `InMemoryRepository` | An in-process array. Nothing is written to disk; nothing survives past the instance's lifetime. | Tests, and zero-file consumers that don't need durability. |

Both implement the same five-method `RepositoryInterface` (`find()`, `save()`, `delete()`,
`all()`, `query()`, plus `nextId()`), verified by one shared contract test suite
(`RepositoryContractTestCase`) so behavior — id assignment, equality querying, isolation between
entities — never drifts between the two.

## Requirements

- PHP **≥ 8.3**
- Nothing else — `milpa/data` has no package dependencies, Milpa or otherwise

## Documentation

**Full API reference: [getmilpa.github.io/data](https://getmilpa.github.io/data/)** —
generated straight from the source DocBlocks and dressed with the Milpa design system.

## Contributing

Contributions are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). Please report security
issues via [SECURITY.md](SECURITY.md), and note that this project follows a
[Code of Conduct](CODE_OF_CONDUCT.md).

## License

[Apache-2.0](LICENSE) © TeamX Agency.

---

Milpa is designed, built, and maintained by **[TeamX Agency](https://teamx.agency/?utm_source=github&utm_medium=readme&utm_campaign=milpa&utm_content=data)**.
