<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Exceptions;

final class InvalidConfigurationException extends GrantException
{
    public static function enum(string $key, string $interface): self
    {
        return new self(sprintf('The [grant.%s] config value must be a string-backed enum implementing [%s].', $key, $interface));
    }

    public static function backingType(string $key): self
    {
        return new self(sprintf('The [grant.%s] enum must be string-backed.', $key));
    }

    public static function model(string $expected): self
    {
        return new self(sprintf('The [grant.model] config value must be [%s] or a subclass.', $expected));
    }
}
