<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant;

use BackedEnum;

/** @property-read string $value */
interface Ability extends BackedEnum
{
    public function deniedMessage(): string;
}
