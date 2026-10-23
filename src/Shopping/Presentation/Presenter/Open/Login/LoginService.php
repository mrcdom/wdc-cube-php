<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter\Open\Login;

use Wdc\Framework\Commons\Beans;
use Wdc\Framework\Domain\Projection\ProjectionValues as PV;
use Wdc\Framework\Domain\Security\AuthenticationService;
use Wdc\Framework\Domain\Security\PasswordUtil;
use Wdc\Shopping\Domain\User\UserCriteria;
use Wdc\Shopping\Domain\User\UserRepository;

final class LoginService
{
    private readonly UserRepository $users;

    /** null ⇒ no authentication configured: checks the password directly against the repository */
    private readonly ?AuthenticationService $auth;

    public function __construct()
    {
        $this->users = Beans::of(UserRepository::class);
        $this->auth = Beans::find(AuthenticationService::class);
    }

    /** null = invalid credentials. */
    public function fetchSubject(?string $userName, ?string $password): ?LoginResult
    {
        if ($userName === null || $password === null) {
            return null;
        }

        return $this->auth !== null
            ? $this->authenticateViaAuthService($this->auth, $userName, $password)
            : $this->authenticateViaRepository($userName, $password);
    }

    /**
     * The same HMAC challenge a remote client would perform, only all of it here on the server:
     * the password already arrived encrypted over the session channel (AES-GCM).
     */
    private function authenticateViaAuthService(AuthenticationService $auth, string $userName, string $password): ?LoginResult
    {
        $passwordHash = PasswordUtil::hashPassword($password);                     // 1. hash as in the database
        $challenge = $auth->challenge();                                           // 2. single-use nonce
        $digest = PasswordUtil::computeHmac($passwordHash, $userName . $challenge->nonce);   // 3. HMAC
        $result = $auth->login($userName, $digest, $challenge->nonce);            // 4. authenticate
        if ($result === null) {
            return null;
        }

        $persistentToken = $auth->createPersistentToken($result->userId, $userName);   // 5. "remember me"
        $subject = Subject::create($this->users->fetch(new UserCriteria()          // 6. display name
            ->withUserId($result->userId)
            ->withProjection(Subject::projection()), 0, 1)[0] ?? null);

        return $subject === null ? null : new LoginResult($subject, $persistentToken);
    }

    /**
     * Without an AuthenticationService (environments with no security configured): looks up by
     * login and compares the hash here — the password is never a search criterion.
     */
    private function authenticateViaRepository(string $userName, string $password): ?LoginResult
    {
        $projection = Subject::projection()->withPassword(PV::STR);
        $user = $this->users->fetch(new UserCriteria()->withUserName($userName)->withProjection($projection), 0, 1)[0] ?? null;
        $stored = $user?->password() === null ? null : trim((string) $user->password());
        if ($stored === null || !hash_equals($stored, PasswordUtil::hashPassword($password))) {
            return null;
        }
        $subject = Subject::create($user);

        return $subject === null ? null : new LoginResult($subject, null);
    }
}
