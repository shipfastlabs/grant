<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Console\Commands;

final class MakeRoleCommand extends MakeEnumCaseCommand
{
    protected $signature = 'make:role {name : The role case name}';

    protected $description = 'Add a case to the configured role enum, creating the enum if needed.';

    protected $type = 'Role';

    protected string $configKey = 'roles';
}
