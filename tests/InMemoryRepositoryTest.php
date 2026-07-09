<?php

declare(strict_types=1);

namespace Milpa\Data\Tests;

use Milpa\Data\InMemoryRepository;
use Milpa\Data\RepositoryInterface;
use Milpa\Data\Tests\Fixtures\TestEntity;

final class InMemoryRepositoryTest extends RepositoryContractTestCase
{
    protected function createRepository(): RepositoryInterface
    {
        return new InMemoryRepository(TestEntity::class);
    }
}
