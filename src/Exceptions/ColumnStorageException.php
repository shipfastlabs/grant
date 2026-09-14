<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Exceptions;

final class ColumnStorageException extends GrantException
{
    public static function multipleRoles(): self
    {
        return new self('Column storage supports only one role.');
    }

    public static function scoped(): self
    {
        return new self('Column storage does not support scoped roles.');
    }
}
