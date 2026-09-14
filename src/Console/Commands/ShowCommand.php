<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Shipfastlabs\Grant\Ability;
use Shipfastlabs\Grant\Grant;
use Shipfastlabs\Grant\Role;

use function Laravel\Prompts\text;

final class ShowCommand extends Command
{
    protected $signature = 'grant:show {user? : The user ID} {--on= : Scope as ModelClass:id}';

    protected $description = 'Show a user’s roles and resolved permissions.';

    public function handle(Grant $grant): int
    {
        $userClass = config('auth.providers.users.model');

        if (! is_string($userClass) || ! is_subclass_of($userClass, Model::class)) {
            $this->components->error('The auth user model could not be resolved.');

            return self::FAILURE;
        }

        $userId = $this->argument('user');
        $user = $userClass::query()->findOrFail(
            is_string($userId) ? $userId : text('Which user should be shown?', placeholder: 'E.g. 1', required: true),
        );
        $scope = $this->resolveScope($this->option('on'));

        $this->table([], [
            ['Roles', $grant->roles($user, $scope)->map(static fn (Role $role): string => $role->value)->join(', ') ?: 'None'],
            ['Permissions', $grant->permissions($user, $scope)->map(static fn (Ability $ability): string => $ability->value)->join(', ') ?: 'None'],
        ]);

        return self::SUCCESS;
    }

    private function resolveScope(mixed $scope): ?Model
    {
        if ($scope === null || $scope === '') {
            return null;
        }

        if (! is_string($scope)) {
            $this->fail('The --on option must use ModelClass:id.');
        }

        [$class, $id] = array_pad(explode(':', $scope, 2), 2, '');

        if ($id === '' || ! is_subclass_of($class, Model::class)) {
            $this->fail('The --on option must use ModelClass:id.');
        }

        return $class::query()->findOrFail($id);
    }
}
