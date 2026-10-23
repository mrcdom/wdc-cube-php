<?php

declare(strict_types=1);

namespace Wdc\Tests\Store\Presentation;

use PHPUnit\Framework\TestCase;
use Wdc\Framework\Commons\Beans;
use Wdc\Framework\Commons\Concurrent\ManualScheduler;
use Wdc\Framework\Domain\Security\AuthenticationService;
use Wdc\Store\Domain\User\UserRepository;
use Wdc\Store\Persistence\Security\AuthenticationServiceImpl;
use Wdc\Framework\Persistence\Database;
use Wdc\Store\Presentation\Routes;
use Wdc\Store\Presentation\StoreContext;
use Wdc\Tests\Store\Presentation\Mock\HomeViewMock;
use Wdc\Tests\Store\Presentation\Mock\LoginViewMock;
use Wdc\Tests\Store\Presentation\Mock\StoreApplicationMock;
use Wdc\Tests\Support\TestDatabase;

/**
 * Base for the presentation tests: a fresh seeded database for every
 * test, real authentication (HMAC challenge + JWT) and a manual-clock scheduler.
 *
 * They run with AuthenticationService, like the server; the path without it (login straight through
 * the repository) has its own test.
 */
abstract class PresentationTestCase extends TestCase
{
    protected ManualScheduler $scheduler;

    protected AuthenticationServiceImpl $auth;

    protected StoreContext $context;

    protected StoreApplicationMock $app;

    protected function setUp(): void
    {
        TestDatabase::open();
        $this->scheduler = new ManualScheduler();
        $this->auth = new AuthenticationServiceImpl('test-jwt-secret');
        Beans::set(AuthenticationService::class, $this->auth);
        $this->context = new StoreContext($this->scheduler);
        $this->app = new StoreApplicationMock($this->context);
    }

    protected function tearDown(): void
    {
        $this->app->release();
        Beans::clear();
    }

    protected function login(string $user = 'admin', string $password = 'admin'): HomeViewMock
    {
        Routes::login($this->app);
        LoginViewMock::cast($this->app->rootView()->state->contentView)->presenter->onEnter($user, $password);

        return HomeViewMock::cast($this->app->rootView()->state->contentView);
    }
}
