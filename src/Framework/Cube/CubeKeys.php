<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube;

/**
 * Leitura tipada de um intent: o único lugar que conhece os NOMES dos parâmetros e atributos
 * de um place.
 *
 * Cada place que lê ou publica algo tem a sua classe de keys, que herda das keys de quem está
 * acima no caminho - assim um place mais fundo pode LER o que um ancestral publica. Cada
 * arquivo declara um prefixo de duas letras, único na aplicação, e os seus nomes numa tabela
 * local de constantes, todos começando pelo prefixo:
 *
 *     private const string PREFIX = 'pr';
 *     private const string P_ID = self::PREFIX . '-id';           // parâmetro: vai para o endereço
 *     private const string A_ROW = self::PREFIX . '-row';         // atributo: não vai
 *
 * A unicidade importa porque uma navegação passa o MESMO intent por todos os `applyParameters`,
 * da raiz à folha: dois donos do mesmo nome sobrescreveriam o valor um do outro no caminho, sem
 * erro. O teste `KeysNamesTest` garante a regra varrendo os arquivos `*Keys.php`.
 *
 * Os valores são expostos como propriedades com hooks (`get`/`set`), que validam na leitura -
 * o endereço é entrada de fora - e omitem o valor padrão na escrita.
 *
 * @template A of CubeApplication
 * @phpstan-consistent-constructor
 */
abstract class CubeKeys
{
    public readonly CubeIntent $intent;

    /**
     * @param A               $app
     * @param CubeIntent|null $intent o intent de uma navegação em curso; null = nasce do ESTADO
     *                                atual (cada presenter vivo publica o que mostra)
     */
    public function __construct(protected readonly CubeApplication $app, ?CubeIntent $intent = null)
    {
        if ($intent === null) {
            $intent = $app->newIntent();
            $intent->setPlace($this->place());
        }
        $this->intent = $intent;
    }

    /**
     * Keys de uma viagem que parte do estado atual: `ProductKeys::of($app)` e depois `->go()`.
     *
     * @param A $app
     */
    public static function of(CubeApplication $app): static
    {
        return new static($app);
    }

    /** O place destas keys (o destino de `go()`). */
    abstract public function place(): CubePlace;

    /** Navega para o place destas keys, com o intent que elas montaram. */
    abstract public function go(): bool;

    /** Inteiro não negativo (ids começam em 0), ou null quando o endereço não traz um utilizável. */
    protected function idParameter(string $name): ?int
    {
        $value = $this->intent->getParameterAsInt($name);

        return $value !== null && $value >= 0 ? $value : null;
    }
}
