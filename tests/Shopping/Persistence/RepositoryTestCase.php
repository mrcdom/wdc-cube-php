<?php

declare(strict_types=1);

namespace Wdc\Tests\Shopping\Persistence;

use PHPUnit\Framework\TestCase;
use Wdc\Framework\Persistence\Database;
use Wdc\Tests\Support\TestDatabase;

/**
 * Each test starts with the test database at the seed state (see TestDatabase).
 */
abstract class RepositoryTestCase extends TestCase
{
    protected Database $db;

    protected function setUp(): void
    {
        $this->db = TestDatabase::open();
    }
}
