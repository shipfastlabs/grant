<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Shipfastlabs\Grant\GrantServiceProvider;
use Shipfastlabs\Grant\Testing\InteractsWithGrant;
use Shipfastlabs\Grant\Tests\Fixtures\Permission;
use Shipfastlabs\Grant\Tests\Fixtures\Role;
use Shipfastlabs\Grant\Tests\Fixtures\User;

abstract class TestCase extends Orchestra
{
    use InteractsWithGrant;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('grant.permissions', Permission::class);
        $app['config']->set('grant.roles', Role::class);
        $app['config']->set('auth.providers.users.model', User::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('role')->nullable();
        });

        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function getPackageProviders($app): array
    {
        return [
            GrantServiceProvider::class,
        ];
    }
}
