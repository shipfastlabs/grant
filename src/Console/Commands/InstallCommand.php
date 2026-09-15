<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

final class InstallCommand extends Command
{
    protected $signature = 'grant:install {--force : Overwrite published config and migration}';

    protected $description = 'Install Grant configuration, migration, and enums.';

    public function handle(Filesystem $files): int
    {
        $this->call('vendor:publish', ['--tag' => 'grant', '--force' => $this->option('force')]);

        foreach (['permissions' => 'Permission', 'roles' => 'Role'] as $key => $name) {
            $enum = config("grant.{$key}");

            if (! is_string($enum) || enum_exists($enum)) {
                continue;
            }

            $path = app_path("Enums/{$name}.php");
            $files->ensureDirectoryExists(dirname($path));
            $files->copy(__DIR__."/../../../stubs/{$name}.php.stub", $path);
            $this->components->info("Enum [{$path}] created successfully.");
        }

        $this->components->info('Grant installed successfully. Add `Shipfastlabs\Grant\HasRoles` to your user model.');

        return self::SUCCESS;
    }
}
