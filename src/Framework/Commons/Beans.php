<?php

declare(strict_types=1);

namespace Wdc\Framework\Commons;

use LogicException;

/**
 * Os serviços compartilhados do processo (repositórios, transações, autenticação), achados pela
 * interface: `Beans::of(ProductRepository::class)`.
 *
 * São preenchidos uma vez, na subida do servidor (ou no `setUp` de um teste), e depois só lidos.
 * Por isso servem a todas as sessões ao mesmo tempo sem problema com as Fibers: o que muda por
 * requisição nunca fica aqui.
 *
 * A chave é a interface, e o PHPStan sabe o tipo do retorno: `of(ProductRepository::class)` é um
 * `ProductRepository`. (Uma interface PHP não pode ter propriedade estática, por isso o registro
 * fica numa classe à parte.)
 */
final class Beans
{
    /** @var array<class-string, object> */
    private static array $beans = [];

    private function __construct()
    {
    }

    /**
     * Registra (ou, com `null`, remove) a implementação de uma interface.
     *
     * @template T of object
     * @param class-string<T> $type
     * @param T|null          $bean
     */
    public static function set(string $type, ?object $bean): void
    {
        if ($bean === null) {
            unset(self::$beans[$type]);

            return;
        }
        if (!$bean instanceof $type) {
            throw new LogicException(get_class($bean) . " não implementa {$type}");
        }
        self::$beans[$type] = $bean;
    }

    /**
     * A implementação registrada; sem registro é erro de montagem.
     *
     * @template T of object
     * @param class-string<T> $type
     * @return T
     */
    public static function of(string $type): object
    {
        return self::find($type) ?? throw new LogicException("Nenhuma implementação registrada para {$type}");
    }

    /**
     * A implementação registrada, ou `null` para um serviço opcional (ex.: autenticação desligada).
     *
     * @template T of object
     * @param class-string<T> $type
     * @return T|null
     */
    public static function find(string $type): ?object
    {
        $bean = self::$beans[$type] ?? null;

        return $bean instanceof $type ? $bean : null;
    }

    /** Esvazia o registro (testes). */
    public static function clear(): void
    {
        self::$beans = [];
    }
}
