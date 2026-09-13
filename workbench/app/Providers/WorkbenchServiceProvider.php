<?php

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;
use Workbench\App\Enums\Permission;
use Workbench\App\Enums\Role;

class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        config()->set('grant.permissions', Permission::class);
        config()->set('grant.roles', Role::class);
    }
}
