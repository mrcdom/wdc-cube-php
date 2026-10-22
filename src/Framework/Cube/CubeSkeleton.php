<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube;

/**
 * Lado servidor do "RPC" da view: recebe os eventos que o cliente dispara.
 */
interface CubeSkeleton
{
    /** Identificador estável da tela (12 hex), compartilhado com o cliente. */
    public function classId(): string;

    /**
     * @param array<string, mixed> $formData campos "p.xxx" são parâmetros do evento
     */
    public function submit(int $eventCode, int $eventQtde, array $formData): void;

    /**
     * Copia para o ViewState os campos que o cliente sincroniza (sem prefixo "p.").
     *
     * @param array<string, mixed> $formData
     */
    public function syncState(array $formData): void;
}
