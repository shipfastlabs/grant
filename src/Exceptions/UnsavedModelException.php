<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Exceptions;

final class UnsavedModelException extends GrantException
{
    public static function user(): self
    {
        return new self('Roles can only be assigned to persisted users.');
    }

    public static function scope(): self
    {
        return new self('Scoped roles require a persisted Eloquent model as the scope.');
    }
}
