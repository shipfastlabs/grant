<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use ReflectionEnum;
use Shipfastlabs\Grant\Exceptions\ColumnStorageException;
use Shipfastlabs\Grant\Exceptions\InvalidConfigurationException;
use Shipfastlabs\Grant\Exceptions\UnsavedModelException;
use Shipfastlabs\Grant\Models\RoleAssignment;

final class Grant
{
    public function grant(Model $user, Role $role, ?Model $on = null): Model
    {
        if ($this->usesRoleColumn($on)) {
            return $this->writeColumn($user, $role->value);
        }

        $this->assignmentModel()::query()->firstOrCreate($this->assignmentAttributes($user, $role, $on));

        return $user;
    }

    public function revoke(Model $user, Role $role, ?Model $on = null): Model
    {
        if ($this->usesRoleColumn($on)) {
            if ($this->columnValue($user) === $role->value) {
                $this->writeColumn($user, null);
            }

            return $user;
        }

        $this->assignmentQuery($user, $on)->where('role', $role->value)->delete();

        return $user;
    }

    /** @param iterable<Role> $roles */
    public function syncRoles(Model $user, iterable $roles, ?Model $on = null): Model
    {
        $roles = collect([...$roles])->unique(static fn (Role $role): string => $role->value)->values();

        if ($this->usesRoleColumn($on)) {
            if ($roles->count() > 1) {
                throw ColumnStorageException::multipleRoles();
            }

            return $this->writeColumn($user, $roles->first()?->value);
        }

        $rows = $roles->map(fn (Role $role): array => $this->assignmentAttributes($user, $role, $on))->all();

        $user->getConnection()->transaction(function () use ($user, $rows, $on): void {
            $this->assignmentQuery($user, $on)->delete();
            $this->assignmentModel()::query()->insert($rows);
        });

        return $user;
    }

    public function hasRole(Model $user, Role $role, ?Model $on = null): bool
    {
        return $this->roles($user, $on)->containsStrict($role);
    }

    /** @return Collection<int, Role> */
    public function roles(Model $user, ?Model $on = null): Collection
    {
        $roleClass = $this->roleClass();

        $values = $this->usesRoleColumn($on)
            ? [$this->columnValue($user)]
            : $this->assignmentQuery($user, $on)->pluck('role')->all();

        return collect($values)
            ->map(static fn (mixed $value): ?Role => is_string($value) ? $roleClass::tryFrom($value) : null)
            ->filter()
            ->values();
    }

    /** @return Collection<int, Ability> */
    public function permissions(Model $user, ?Model $on = null): Collection
    {
        $roles = $this->roles($user);

        if ($on instanceof Model && ! $this->usesRoleColumn()) {
            $roles = $roles->merge($this->roles($user, $on));
        }

        return $roles
            ->flatMap(static fn (Role $role): array => $role->permissions())
            ->unique(static fn (Ability $permission): string => $permission->value)
            ->values();
    }

    /** @return class-string<Role> */
    public function roleClass(): string
    {
        return $this->enumClass('roles', Role::class);
    }

    /** @return class-string<Ability> */
    public function permissionClass(): string
    {
        return $this->enumClass('permissions', Ability::class);
    }

    /** @return class-string<RoleAssignment> */
    public function assignmentModel(): string
    {
        $model = config('grant.model');

        if (! is_string($model) || ! is_a($model, RoleAssignment::class, true)) {
            throw InvalidConfigurationException::model(RoleAssignment::class);
        }

        return $model;
    }

    /** @return Builder<RoleAssignment> */
    private function assignmentQuery(Model $user, ?Model $on): Builder
    {
        $this->assertPersisted($user, $on);
        $query = $this->assignmentModel()::query()->where('user_id', $user->getKey());

        return $on instanceof Model
            ? $query->where('scopeable_type', $on->getMorphClass())->where('scopeable_id', $on->getKey())
            : $query->whereNull('scopeable_type')->whereNull('scopeable_id');
    }

    /** @return array<string, mixed> */
    private function assignmentAttributes(Model $user, Role $role, ?Model $on): array
    {
        $this->assertPersisted($user, $on);

        return [
            'user_id' => $user->getKey(),
            'role' => $role->value,
            'scopeable_type' => $on?->getMorphClass(),
            'scopeable_id' => $on?->getKey(),
        ];
    }

    private function writeColumn(Model $user, ?string $value): Model
    {
        $user->setAttribute('role', $value);
        $user->save();

        return $user;
    }

    private function columnValue(Model $user): mixed
    {
        $value = $user->getAttribute('role');

        return $value instanceof Role ? $value->value : $value;
    }

    /**
     * @template TEnum of \BackedEnum
     *
     * @param  class-string<TEnum>  $interface
     * @return class-string<TEnum>
     */
    private function enumClass(string $key, string $interface): string
    {
        $enum = config("grant.{$key}");

        if (! is_string($enum) || ! is_subclass_of($enum, $interface)) {
            throw InvalidConfigurationException::enum($key, $interface);
        }

        if ((string) (new ReflectionEnum($enum))->getBackingType() !== 'string') {
            throw InvalidConfigurationException::backingType($key);
        }

        return $enum;
    }

    private function usesRoleColumn(?Model $on = null): bool
    {
        if (config('grant.storage') !== 'column') {
            return false;
        }

        if ($on instanceof Model) {
            throw ColumnStorageException::scoped();
        }

        return true;
    }

    private function assertPersisted(Model $user, ?Model $on): void
    {
        if (! $user->exists) {
            throw UnsavedModelException::user();
        }

        if ($on instanceof Model && ! $on->exists) {
            throw UnsavedModelException::scope();
        }
    }
}
