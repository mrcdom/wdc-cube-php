<?php

declare(strict_types=1);

namespace Wdc\Tests\Interop;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wdc\Framework\Commons\Security\Rsa;
use Wdc\Framework\Cube\Remote\RemoteAppSecurity;
use Wdc\Framework\Cube\Remote\RemoteDataSecurity;

/**
 * O handshake e a cifra do canal conversam com o cliente de verdade: um script Node repete os
 * passos do DataSecurity.ts do shell React com WebCrypto (a mesma API do browser).
 */
final class DataSecurityInteropTest extends TestCase
{
    private static ?RemoteAppSecurity $security = null;

    private static function security(): RemoteAppSecurity
    {
        if (self::$security === null) {
            $keys = RemoteAppSecurity::generateDevKeys();
            self::$security = new RemoteAppSecurity(
                Rsa::fromBase36($keys['rsaPublicKey'], $keys['rsaPrivateKey']),
                $keys['signPublicKey'],
                $keys['signPrivateKey'],
            );
        }

        return self::$security;
    }

    /**
     * @param list<string> $args
     * @return array{secret: string, pwd: string, ciphered: string, deciphered?: string}
     */
    private static function client(array $args): array
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            self::markTestSkipped('node não encontrado');
        }
        $command = escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/datasecurity-client.mjs')
            . ' ' . implode(' ', array_map(escapeshellarg(...), $args));
        $output = shell_exec($command);
        self::assertIsString($output, 'o script Node falhou');
        /** @var array{secret: string, pwd: string, ciphered: string, deciphered?: string} */
        return json_decode($output, true, 4, JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function serverOpensTheBrowserSecret_andBothSidesTalk(): void
    {
        $security = self::security();
        $client = self::client([$security->webKey(), 'senha do usuário: café ☕']);

        $session = new RemoteDataSecurity($security);
        $session->updateSecret($client['secret']);
        self::assertTrue($session->isReady(), 'o secret gerado pelo "browser" foi aceito');

        // cliente → servidor (ex.: p.password do login)
        self::assertSame('senha do usuário: café ☕', $session->b64Decipher($client['ciphered']));

        // servidor → cliente (ex.: valores do client storage)
        $fromServer = $session->b64Cipher('token-persistente ✓');
        $back = self::client([$security->webKey(), 'x', $fromServer, $client['secret'], $client['pwd']]);
        self::assertSame('token-persistente ✓', $back['deciphered'] ?? null);
    }

    #[Test]
    public function tamperedData_isRejected(): void
    {
        $security = self::security();
        $client = self::client([$security->webKey(), 'conteúdo']);
        $session = new RemoteDataSecurity($security);
        $session->updateSecret($client['secret']);

        $bytes = (string) base64_decode($client['ciphered'], true);
        $bytes[0] = chr(ord($bytes[0]) ^ 1);

        $this->expectExceptionMessage('adulterado');
        $session->b64Decipher(base64_encode($bytes));
    }
}
