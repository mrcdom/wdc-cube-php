<?php

declare(strict_types=1);

namespace Wdc\Tests\Shopping\Presentation;

use PHPUnit\Framework\TestCase;
use Wdc\Framework\Commons\Beans;
use Wdc\Framework\Commons\Concurrent\ManualScheduler;
use Wdc\Framework\Domain\Security\AuthenticationService;
use Wdc\Shopping\Domain\User\UserRepository;
use Wdc\Shopping\Persistence\Security\AuthenticationServiceImpl;
use Wdc\Framework\Persistence\Database;
use Wdc\Shopping\Presentation\Routes;
use Wdc\Shopping\Presentation\ShoppingContext;
use Wdc\Tests\Shopping\Presentation\Mock\HomeViewMock;
use Wdc\Tests\Shopping\Presentation\Mock\LoginViewMock;
use Wdc\Tests\Shopping\Presentation\Mock\ShoppingApplicationMock;
use Wdc\Tests\Support\TestDatabase;

/**
 * Base dos testes de apresentação: banco novo com o seed a cada
 * teste, autenticação real (desafio HMAC + JWT) e agendador de relógio manual.
 *
 * Rodam com AuthenticationService, como o servidor; o caminho sem ele (login direto pelo
 * repositório) tem teste próprio.
 */
abstract class PresentationTestCase extends TestCase
{
    protected ManualScheduler $scheduler;

    protected AuthenticationServiceImpl $auth;

    protected ShoppingContext $context;

    protected ShoppingApplicationMock $app;

    protected function setUp(): void
    {
        TestDatabase::open();
        $this->scheduler = new ManualScheduler();
        $this->auth = new AuthenticationServiceImpl('test-jwt-secret');
        Beans::set(AuthenticationService::class, $this->auth);
        $this->context = new ShoppingContext($this->scheduler);
        $this->app = new ShoppingApplicationMock($this->context);
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
