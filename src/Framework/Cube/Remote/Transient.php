<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote;

use Attribute;

/** Propriedade pública de ViewState que NÃO vai para o cliente. PHP não tem modificador para excluir propriedades da serialização; este atributo faz esse papel. */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Transient
{
}
