<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Requires
{
    public function __construct(public Ability $ability) {}
}
