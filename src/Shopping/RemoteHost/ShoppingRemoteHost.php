<?php

declare(strict_types=1);

namespace Wdc\Shopping\RemoteHost;

use Closure;
use RuntimeException;
use Throwable;
use Wdc\Framework\Commons\Security\Rsa;
use Wdc\Framework\Cube\Remote\DirectKeyDerivation;
use Wdc\Framework\Cube\Remote\KeyDerivation;
use Wdc\Framework\Cube\Remote\RemoteAppSecurity;
use Wdc\Framework\Cube\Remote\RemoteApplicationRegistry;
use Wdc\Framework\Cube\Remote\RemoteBrowserPresenter;
use Wdc\Shopping\Domain\ShoppingConfig;
use Wdc\Shopping\Presentation\ShoppingContext;

/**
 * Monta o host remoto do Shopping: chaves, registry de sessões
 * e a fábrica que cria uma sessão a partir da 1ª mensagem do cliente.
 */
final class ShoppingRemoteHost
{
    public readonly RemoteApplicationRegistry $registry;

    /**
     * @param (Closure(): float)|null $clock relógio em ms (testes)
     */
    public function __construct(
        private readonly ShoppingContext $context,
        public readonly RemoteAppSecurity $security,
        int $maxSessions = -1,
        int $sessionTtlSeconds = RemoteApplicationRegistry::DEFAULT_SESSION_TTL_SECONDS,
        ?Closure $clock = null,
        KeyDerivation $keyDerivation = new DirectKeyDerivation(),
    ) {
        $this->registry = new RemoteApplicationRegistry(
            $this->createApp(...),
            $maxSessions,
            $sessionTtlSeconds,
            $clock,
            $context->logger,
            $keyDerivation,
        );
    }

    /**
     * Lê `[remote]` e `[server]` da configuração. Sem chaves, gera chaves de desenvolvimento e
     * as grava em `application.local.toml` — troque-as em produção.
     */
    public static function fromConfig(
        ShoppingConfig $config,
        ShoppingContext $context,
        KeyDerivation $keyDerivation = new DirectKeyDerivation(),
    ): self
    {
        $keys = [
            'rsaPublicKey' => $config->config->get('remote.rsaPublicKey'),
            'rsaPrivateKey' => $config->config->get('remote.rsaPrivateKey'),
            'signPublicKey' => $config->config->get('remote.signPublicKey'),
            'signPrivateKey' => $config->config->get('remote.signPrivateKey'),
        ];
        if (in_array(null, $keys, true)) {
            $keys = RemoteAppSecurity::generateDevKeys();
            self::appendDevKeys($config, $keys);
        }
        /** @var array{rsaPublicKey: string, rsaPrivateKey: string, signPublicKey: string, signPrivateKey: string} $keys */
        $security = new RemoteAppSecurity(
            Rsa::fromBase36($keys['rsaPublicKey'], $keys['rsaPrivateKey']),
            $keys['signPublicKey'],
            $keys['signPrivateKey'],
        );

        $ttl = $config->config->getInt('server.sessionTtlSeconds', -1);

        return new self(
            $context,
            $security,
            $config->config->getInt('server.maxSessions', -1),
            $ttl < 0 ? RemoteApplicationRegistry::DEFAULT_SESSION_TTL_SECONDS : $ttl,
            keyDerivation: $keyDerivation,
        );
    }

    /**
     * Cria a sessão a partir da 1ª mensagem: aplica o `secret` (antes do auto-login, que precisa
     * decifrar o storage), carrega o client storage e navega para o `p.path` do evento de início.
     *
     * @param array<string, mixed> $request
     */
    public function createApp(string $appId, array $request): ShoppingRemoteApplication
    {
        $app = new ShoppingRemoteApplication($this->context, $appId, $this->security, $this->registry);
        try {
            $support = $app->remote();
            $support->setTimeSpan($this->registry->sessionTtlSeconds());
            $secret = $request['secret'] ?? null;
            if (is_string($secret) && trim($secret) !== '') {
                $support->dataSecurity->updateSecret($secret);
            }
            $support->clientStorage->loadFromBootstrap($request['storage'] ?? null);

            $path = $app->fragment();
            $browserForm = $request[RemoteBrowserPresenter::CLASS_ID . ':0'] ?? null;
            if (is_array($browserForm) && is_string($browserForm['p.path'] ?? null) && trim($browserForm['p.path']) !== '') {
                $path = $browserForm['p.path'];
            }
            $support->safeGo($path);
        } catch (Throwable $e) {
            $app->release();
            throw $e;
        }

        return $app;
    }

    /** @param array{rsaPublicKey: string, rsaPrivateKey: string, signPublicKey: string, signPrivateKey: string} $keys */
    private static function appendDevKeys(ShoppingConfig $config, array $keys): void
    {
        $file = $config->configDir . '/application.local.toml';
        $block = "\n[remote]\n"
            . "# Chaves geradas automaticamente para DESENVOLVIMENTO — troque em produção\n"
            . "# handshake do canal: RSA em base 36 (expoente:módulo / privada)\n"
            . "rsaPublicKey = \"{$keys['rsaPublicKey']}\"\n"
            . "rsaPrivateKey = \"{$keys['rsaPrivateKey']}\"\n"
            . "# assinatura de appId e URLs: DER em Base64 URL-safe (X.509 / PKCS#8)\n"
            . "signPublicKey = \"{$keys['signPublicKey']}\"\n"
            . "signPrivateKey = \"{$keys['signPrivateKey']}\"\n";
        if (file_put_contents($file, $block, FILE_APPEND) === false) {
            throw new RuntimeException("Não foi possível gravar as chaves em {$file}");
        }
    }
}
