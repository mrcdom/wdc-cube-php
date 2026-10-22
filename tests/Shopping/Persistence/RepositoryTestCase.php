<?php

declare(strict_types=1);

namespace Wdc\Tests\Shopping\Persistence;

use PHPUnit\Framework\TestCase;
use Wdc\Shopping\Persistence\ShoppingRepositories;
use Wdc\Tests\Support\TestDatabase;

/**
 * Cada teste começa com o banco de testes no seed (ver TestDatabase).
 */
abstract class RepositoryTestCase extends TestCase
{
    protected ShoppingRepositories $repos;

    protected function setUp(): void
    {
        $this->repos = TestDatabase::repositories();
    }
}
