<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation\Presenter\Open\Login;

use Wdc\Framework\Domain\Projection\ProjectionValues as PV;
use Wdc\Framework\Domain\Security\AuthenticationService;
use Wdc\Framework\Domain\Security\PasswordUtil;
use Wdc\Shopping\Domain\User\UserCriteria;
use Wdc\Shopping\Domain\User\UserRepository;

final class LoginService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly ?AuthenticationService $auth = null,
    ) {
    }

    /** null = credenciais inválidas. */
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
     * O mesmo desafio HMAC que um cliente remoto faria, só que todo aqui no servidor: a senha
     * já chegou cifrada pelo canal da sessão (AES-GCM).
     */
    private function authenticateViaAuthService(AuthenticationService $auth, string $userName, string $password): ?LoginResult
    {
        $passwordHash = PasswordUtil::hashPassword($password);                     // 1. hash como no banco
        $challenge = $auth->challenge();                                           // 2. nonce de uso único
        $digest = PasswordUtil::computeHmac($passwordHash, $userName . $challenge->nonce);   // 3. HMAC
        $result = $auth->login($userName, $digest, $challenge->nonce);            // 4. autentica
        if ($result === null) {
            return null;
        }

        $securityContext = $auth->resolveToken($result->accessToken);              // 5. contexto da sessão
        $persistentToken = $auth->createPersistentToken($result->userId, $userName);   // 6. "lembrar-me"
        $subject = Subject::create($this->users->fetch(new UserCriteria()          // 7. nome de exibição
            ->withUserId($result->userId)
            ->withProjection(Subject::projection()), 0, 1)[0] ?? null);

        return $subject === null ? null : new LoginResult($subject, $securityContext, $persistentToken);
    }

    /**
     * Sem AuthenticationService (ambientes sem segurança configurada): busca pelo login e
     * compara o hash aqui — a senha nunca é critério de busca.
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

        return $subject === null ? null : new LoginResult($subject, null, null);
    }
}
