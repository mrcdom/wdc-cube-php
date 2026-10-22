<?php

declare(strict_types=1);

namespace Wdc\Tests\Shopping\Persistence;

use PHPUnit\Framework\TestCase;
use Wdc\Shopping\Persistence\ShoppingRepositories;
use Wdc\Tests\Support\TestDatabase;

/**
 * Cada teste ganha um banco SQLite novo em memória, já com o seed, isolado dos demais.
 */
abstract class RepositoryTestCase extends TestCase
{
    protected ShoppingRepositories $repos;

    protected function setUp(): void
    {
        $this->repos = TestDatabase::repositories();
    }
}
