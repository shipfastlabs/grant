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

        $this->flushOnWrite($grant->assignmentModel());
        $this->defineAbilities($gate, $grant->permissionClass());
        $this->allowSuperAdmin($gate);
        $this->enforcePolicyAttributes($gate);
    }

    /** @param class-string<Model> $model */
    private function flushOnWrite(string $model): void
    {
        $flush = static function (Model $row): void {
            $grant = app(Grant::class);

            foreach ([$row->getAttribute('user_id'), $row->getOriginal('user_id')] as $id) {
                if (is_int($id) || is_string($id)) {
                    $grant->flush($id);
                }
            }
        };

        $model::saved($flush);
        $model::deleted($flush);
    }

    /** @param class-string<Ability> $permissions */
    private function defineAbilities(Gate $gate, string $permissions): void
    {
        foreach ($permissions::cases() as $permission) {
            $gate->define($permission, static function (object $user, mixed ...$arguments) use ($permission): Response {
                $scope = $arguments[0] ?? null;
                $scope = $scope instanceof Model && $scope->exists ? $scope : null;

                $allowed = $user instanceof Model
                    && $user->exists
                    && app(Grant::class)->permissions($user, $scope)->containsStrict($permission);

                return $allowed ? Response::allow() : Response::deny($permission->deniedMessage());
            });
        }
    }

    private function allowSuperAdmin(Gate $gate): void
    {
        $gate->before(static function (object $user): ?bool {
            $grant = app(Grant::class);
            $superAdmin = $grant->superAdmin();

            $isSuperAdmin = $superAdmin instanceof Role
                && $user instanceof Model
                && $user->exists
                && $grant->hasRole($user, $superAdmin);

            return $isSuperAdmin ? true : null;
        });
    }

    private function enforcePolicyAttributes(Gate $gate): void
    {
        $gate->before(static function (?object $user, string $ability, array $arguments) use ($gate): ?Response {
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

            $response = $gate->forUser($user)->inspect($required, $arguments);

            return $response->allowed() ? null : $response;
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
