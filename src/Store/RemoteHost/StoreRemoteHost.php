<?php

declare(strict_types=1);

namespace Wdc\Store\RemoteHost;

use Closure;
use RuntimeException;
use Throwable;
use Wdc\Framework\Commons\Security\Rsa;
use Wdc\Framework\Cube\Remote\DirectKeyDerivation;
use Wdc\Framework\Cube\Remote\KeyDerivation;
use Wdc\Framework\Cube\Remote\RemoteAppSecurity;
use Wdc\Framework\Cube\Remote\RemoteApplicationRegistry;
use Wdc\Framework\Cube\Remote\RemoteBrowserPresenter;
use Wdc\Store\Domain\StoreConfig;
use Wdc\Store\Presentation\StoreContext;

/**
 * Builds the Store remote host: keys, session registry
 * and the factory that creates a session from the client's first message.
 */
final class StoreRemoteHost
{
    public readonly RemoteApplicationRegistry $registry;

    /**
     * @param (Closure(): float)|null $clock clock in ms (tests)
     */
    public function __construct(
        private readonly StoreContext $context,
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
     * Reads `[remote]` and `[server]` from the configuration. Without keys, generates development keys and
     * writes them to `application.local.toml` — replace them in production.
     */
    public static function fromConfig(
        StoreConfig $config,
        StoreContext $context,
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
     * Creates the session from the first message: applies the `secret` (before auto-login, which needs
     * to decipher the storage), loads the client storage and navigates to the start event's `p.path`.
     *
     * @param array<string, mixed> $request
     */
    public function createApp(string $appId, array $request): StoreRemoteApplication
    {
        $app = new StoreRemoteApplication($this->context, $appId, $this->security, $this->registry);
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
    private static function appendDevKeys(StoreConfig $config, array $keys): void
    {
        $file = $config->configDir . '/application.local.toml';
        $block = "\n[remote]\n"
            . "# Keys generated automatically for DEVELOPMENT — replace them in production\n"
            . "# channel handshake: RSA in base 36 (exponent:modulus / private)\n"
            . "rsaPublicKey = \"{$keys['rsaPublicKey']}\"\n"
            . "rsaPrivateKey = \"{$keys['rsaPrivateKey']}\"\n"
            . "# appId and URL signing: DER in URL-safe Base64 (X.509 / PKCS#8)\n"
            . "signPublicKey = \"{$keys['signPublicKey']}\"\n"
            . "signPrivateKey = \"{$keys['signPrivateKey']}\"\n";
        if (file_put_contents($file, $block, FILE_APPEND) === false) {
            throw new RuntimeException("Could not write the keys to {$file}");
        }
    }
}
