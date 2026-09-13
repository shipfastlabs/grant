<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Facades;

use Illuminate\Support\Facades\Facade;
use Shipfastlabs\Grant\Grant as GrantManager;

/** @see GrantManager */
final class Grant extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return GrantManager::class;
    }
}
