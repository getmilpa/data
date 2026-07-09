<?php

declare(strict_types=1);

namespace Milpa\Data\Tests;

use Milpa\Data\FileRepository;
use Milpa\Data\RepositoryInterface;
use Milpa\Data\Tests\Fixtures\TestEntity;

final class FileRepositoryTest extends RepositoryContractTestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/milpa-data-repo-' . uniqid('', true) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    protected function createRepository(): RepositoryInterface
    {
        return new FileRepository($this->path, TestEntity::class);
    }

    public function testAFreshFileRepositoryOverTheSameFileRereadsIdentically(): void
    {
        $repo = new FileRepository($this->path, TestEntity::class);
        $id = $repo->save(new TestEntity(null, 'A', 'draft'));

        $fresh = new FileRepository($this->path, TestEntity::class);

        $this->assertEquals($repo->find($id), $fresh->find($id));
        $this->assertEquals($repo->all(), $fresh->all());
        $this->assertSame(2, $fresh->nextId(), 'nextId must continue from what is persisted, not reset');
    }

    public function testAMissingFileStartsEmpty(): void
    {
        $this->assertFalse(is_file($this->path));

        $repo = new FileRepository($this->path, TestEntity::class);

        $this->assertSame([], $repo->all());
        $this->assertSame(1, $repo->nextId());
    }

    public function testAnEmptyFileStartsEmpty(): void
    {
        file_put_contents($this->path, '');

        $repo = new FileRepository($this->path, TestEntity::class);

        $this->assertSame([], $repo->all());
    }

    public function testSaveCreatesTheParentDirectoryWhenMissing(): void
    {
        $nestedPath = sys_get_temp_dir() . '/milpa-data-repo-nested-' . uniqid('', true) . '/entities.json';
        $repo = new FileRepository($nestedPath, TestEntity::class);

        $repo->save(new TestEntity(null, 'A', 'draft'));

        $this->assertFileExists($nestedPath);

        @unlink($nestedPath);
        @rmdir(\dirname($nestedPath));
    }

    public function testFileContentIsPrettyPrintedJsonKeyedById(): void
    {
        $repo = new FileRepository($this->path, TestEntity::class);
        $id = $repo->save(new TestEntity(null, 'A', 'draft'));

        $raw = (string) file_get_contents($this->path);
        $decoded = json_decode($raw, true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey($id, $decoded);
        $this->assertStringContainsString("\n", $raw, 'expected pretty-printed (multi-line) JSON');
    }
}
