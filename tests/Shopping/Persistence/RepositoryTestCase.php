<?php

declare(strict_types=1);

namespace Wdc\Tests\Shopping\Persistence;

use PHPUnit\Framework\TestCase;
use Wdc\Framework\Persistence\Database;
use Wdc\Tests\Support\TestDatabase;

/**
 * Cada teste começa com o banco de testes no seed (ver TestDatabase).
 */
abstract class RepositoryTestCase extends TestCase
{
    protected Database $db;

    protected function setUp(): void
    {
        $this->db = TestDatabase::open();
    }
}
