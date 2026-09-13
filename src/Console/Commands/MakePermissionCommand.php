<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Console\Commands;

final class MakePermissionCommand extends MakeEnumCaseCommand
{
    protected $signature = 'make:permission {name : The permission case name}';

    protected $description = 'Add a case to the configured permission enum, creating the enum if needed.';

    protected $type = 'Permission';

    protected string $configKey = 'permissions';
}
