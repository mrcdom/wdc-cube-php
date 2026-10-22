<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube;

/** Presenter navegável: ocupa um place na cadeia ativa. */
interface CubePresenter extends PresenterBase
{
    /**
     * @param bool $initialization true quando o presenter acabou de ser criado
     * @param bool $deepest        true só para o place alvo (a folha da cadeia)
     * @return bool false interrompe a navegação (guarda, redirecionamento)
     */
    public function applyParameters(CubeIntent $intent, bool $initialization, bool $deepest): bool;

    /** Escreve no intent os parâmetros que representam o estado atual (viram a URL). */
    public function publishParameters(CubeIntent $intent): void;
}
