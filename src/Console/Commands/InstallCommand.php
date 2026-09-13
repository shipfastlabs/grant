<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Console\Commands;

use Illuminate\Console\Command;

final class InstallCommand extends Command
{
    protected $signature = 'grant:install {--force : Overwrite published config and migration}';

    protected $description = 'Install Grant configuration, migration, and enums.';

    public function handle(): int
    {
        $this->call('vendor:publish', ['--tag' => 'grant', '--force' => $this->option('force')]);

        $permissions = config('grant.permissions');
        $roles = config('grant.roles');

        if (is_string($permissions) && ! enum_exists($permissions)) {
            $this->call('make:permission', ['name' => 'Example']);
        }

        if (is_string($roles) && ! enum_exists($roles)) {
            $this->call('make:role', ['name' => 'Admin']);
            $this->call('make:role', ['name' => 'Member']);
        }

        $this->components->info('Grant installed successfully. Add `Shipfastlabs\Grant\HasRoles` to your user model.');

        return self::SUCCESS;
    }
}
