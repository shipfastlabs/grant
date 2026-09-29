<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
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

        $rows = [['Roles', $this->format($grant->roles($user))]];

        if ($scope instanceof Model) {
            $rows[] = ['Scoped roles', $this->format($grant->roles($user, $scope))];
        }

        $rows[] = ['Permissions', $this->format($grant->permissions($user, $scope))];

        $this->table([], $rows);

        return self::SUCCESS;
    }

    /**
     * @template TValue of Role|Ability
     *
     * @param  Collection<int, TValue>  $values
     */
    private function format(Collection $values): string
    {
        return $values->map(static fn (Role|Ability $value): string => $value->value)->join(', ') ?: 'None';
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
