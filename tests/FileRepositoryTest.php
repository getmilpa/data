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
        @unlink($this->path . '.worker.php');
        @unlink($this->path . '.go');
        @unlink($this->path . '.go.a.ready');
        @unlink($this->path . '.go.b.ready');
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

    public function testConcurrentWritersFromSeparateProcessesLoseNoRows(): void
    {
        $perWorker = 50;
        $workerScript = $this->path . '.worker.php';
        $goFile = $this->path . '.go';
        $autoload = __DIR__ . '/../vendor/autoload.php';

        // Each worker signals readiness, then blocks until the go-file drops — so both save loops
        // start together (already booted), and genuinely interleave instead of running back to back.
        file_put_contents($workerScript, <<<'PHP'
            <?php

            declare(strict_types=1);

            require $argv[1];

            use Milpa\Data\FileRepository;
            use Milpa\Data\Tests\Fixtures\TestEntity;

            [, , $file, $goFile, $label, $count] = $argv;

            touch("{$goFile}.{$label}.ready");
            while (!is_file($goFile)) {
                usleep(100);
            }

            $repo = new FileRepository($file, TestEntity::class);
            for ($i = 0; $i < (int) $count; ++$i) {
                $repo->save(new TestEntity(null, $label . '-' . $i, 'draft'));
            }
            PHP);

        $processes = [];
        foreach (['a', 'b'] as $label) {
            $pipes = [];
            $process = proc_open(
                [PHP_BINARY, $workerScript, $autoload, $this->path, $goFile, $label, (string) $perWorker],
                [2 => ['pipe', 'w']],
                $pipes,
            );
            $this->assertIsResource($process, "worker {$label} failed to start");
            $processes[$label] = [$process, $pipes[2]];
        }

        $deadline = microtime(true) + 10.0;
        while (!is_file("{$goFile}.a.ready") || !is_file("{$goFile}.b.ready")) {
            if (microtime(true) > $deadline) {
                $this->fail('workers never became ready');
            }
            usleep(100);
        }
        touch($goFile); // the barrier drops: both workers start writing at once

        foreach ($processes as $label => [$process, $errPipe]) {
            $errors = (string) stream_get_contents($errPipe);
            fclose($errPipe);
            $this->assertSame(0, proc_close($process), "worker {$label} failed: {$errors}");
        }

        $all = (new FileRepository($this->path, TestEntity::class))->all();

        $this->assertCount(2 * $perWorker, $all, 'a concurrent writer lost rows the other one wrote');

        $ids = array_map(static fn (TestEntity $e): int|string|null => $e->id(), $all);
        $this->assertSame(range(1, 2 * $perWorker), $ids, 'ids must be assigned without collision across processes');
    }

    public function testAFailingJsonEncodeLeavesTheExistingCollectionByteIntact(): void
    {
        $repo = new FileRepository($this->path, TestEntity::class);
        $repo->save(new TestEntity(null, 'A', 'draft'));
        $repo->save(new TestEntity(null, 'B', 'published'));

        $before = (string) file_get_contents($this->path);

        try {
            $repo->save(new TestEntity(null, "\xB1\x31", 'draft')); // malformed UTF-8: json_encode throws
            $this->fail('saving an entity whose toArray() holds malformed UTF-8 must throw a JsonException');
        } catch (\JsonException) {
            // expected — encoding must fail before a single byte of the file is touched
        }

        $this->assertSame(
            $before,
            (string) file_get_contents($this->path),
            'an encode failure must leave the collection file byte-untouched',
        );

        $all = (new FileRepository($this->path, TestEntity::class))->all();
        $this->assertCount(2, $all, 'both pre-existing rows must survive the failed save');
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
