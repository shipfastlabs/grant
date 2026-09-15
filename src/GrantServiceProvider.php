<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use ReflectionMethod;
use Shipfastlabs\Grant\Console\Commands\InstallCommand;
use Shipfastlabs\Grant\Console\Commands\ListCommand;
use Shipfastlabs\Grant\Console\Commands\ShowCommand;
use Shipfastlabs\Grant\Console\Commands\SyncCommand;

final class GrantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/grant.php', 'grant');

        $this->app->scoped(Grant::class);
    }

    public function boot(): void
    {
        $permissions = config('grant.permissions');

        if (is_string($permissions) && is_subclass_of($permissions, Ability::class)) {
            $this->registerGate();
        }

        if ($this->app->runningInConsole()) {
            $this->registerCommands();
            $this->registerPublishing();
        }
    }

    private function registerGate(): void
    {
        /** @var Gate $gate */
        $gate = $this->app->make(Gate::class);
        $grant = $this->app->make(Grant::class);

        $this->flushOnWrite($grant);
        $this->defineAbilities($gate, $grant);
        $this->allowSuperAdmin($gate, $grant);
        $this->enforcePolicyAttributes($gate);
    }

    private function flushOnWrite(Grant $grant): void
    {
        $flush = static function (Model $row) use ($grant): void {
            $id = $row->getAttribute('user_id');

            $grant->flush(is_int($id) || is_string($id) ? $id : null);
        };

        $grant->assignmentModel()::saved($flush);
        $grant->assignmentModel()::deleted($flush);
    }

    private function defineAbilities(Gate $gate, Grant $grant): void
    {
        foreach ($grant->permissionClass()::cases() as $permission) {
            $gate->define($permission, static function (object $user, mixed ...$arguments) use ($grant, $permission): Response {
                $scope = $arguments[0] ?? null;
                $scope = $scope instanceof Model && $scope->exists ? $scope : null;

                $allowed = $user instanceof Model
                    && $user->exists
                    && $grant->permissions($user, $scope)->containsStrict($permission);

                return $allowed ? Response::allow() : Response::deny($permission->deniedMessage());
            });
        }
    }

    private function allowSuperAdmin(Gate $gate, Grant $grant): void
    {
        $gate->before(static function (object $user) use ($grant): ?bool {
            $superAdmin = config('grant.super_admin');

            $isSuperAdmin = $superAdmin instanceof Role
                && $user instanceof Model
                && $user->exists
                && $grant->hasRole($user, $superAdmin);

            return $isSuperAdmin ? true : null;
        });
    }

    private function enforcePolicyAttributes(Gate $gate): void
    {
        $gate->before(static function (?object $user, string $ability, array $arguments) use ($gate): ?bool {
            $subject = $arguments[0] ?? null;

            if (! is_object($subject) && ! is_string($subject)) {
                return null;
            }

            $policy = $gate->getPolicyFor(is_object($subject) ? $subject::class : $subject);
            $method = str_contains($ability, '-') ? Str::camel($ability) : $ability;

            if (! is_object($policy) || ! method_exists($policy, $method)) {
                return null;
            }

            $requires = (new ReflectionMethod($policy, $method))->getAttributes(Requires::class)[0] ?? null;
            $required = $requires?->newInstance()->ability;

            if ($required === null || $required->value === $ability) {
                return null;
            }

            return $gate->forUser($user)->allows($required, $arguments) ? null : false;
        });
    }

    private function registerCommands(): void
    {
        $this->commands([
            InstallCommand::class,
            ListCommand::class,
            ShowCommand::class,
            SyncCommand::class,
        ]);
    }

    private function registerPublishing(): void
    {
        $this->publishes([
            __DIR__.'/../config/grant.php' => config_path('grant.php'),
        ], ['grant', 'grant-config']);

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], ['grant', 'grant-migrations']);
    }
}
