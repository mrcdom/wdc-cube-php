<?php

declare(strict_types=1);

namespace Wdc\Framework\Cube\Remote;

use Attribute;

/** A public ViewState property that is NOT sent to the client. PHP has no modifier to exclude properties from serialization; this attribute plays that role. */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Transient
{
}
