<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant;

use BackedEnum;

/** @property-read string $value */
interface Role extends BackedEnum
{
    /** @return list<Ability> */
    public function permissions(): array;
}
