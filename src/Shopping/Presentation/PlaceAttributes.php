<?php

declare(strict_types=1);

namespace Wdc\Shopping\Presentation;

/** Chaves dos atributos de intent (transientes). Devem ser únicos no sistema todo. */
final class PlaceAttributes
{
    /** Slot onde o presenter-filho encaixa a sua view. */
    public const string SLOT_OWNER = '8e4ee9977028';

    /** "A compra acabou de ser feita" — o recibo mostra o aviso de sucesso. */
    public const string ATTR_PURCHASE_MADE = '207136b2b81c';
}
