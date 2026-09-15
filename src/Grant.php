<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use ReflectionEnum;
use Shipfastlabs\Grant\Exceptions\InvalidConfigurationException;
use Shipfastlabs\Grant\Exceptions\UnsavedModelException;
use Shipfastlabs\Grant\Models\RoleAssignment;

final class Grant
{
    /**
     * @var array<string, Collection<int, RoleAssignment>>
     */
    private array $assignments = [];

    public function grant(Model $user, Role $role, ?Model $on = null): Model
    {
        $this->assignmentModel()::query()->firstOrCreate($this->assignmentAttributes($user, $role, $on));

        $this->flush($user);

        return $user;
    }

    public function revoke(Model $user, Role $role, ?Model $on = null): Model
    {
        $this->assignmentQuery($user, $on)->where('role', $role->value)->delete();

        $this->flush($user);

        return $user;
    }

    /** @param iterable<Role> $roles */
    public function syncRoles(Model $user, iterable $roles, ?Model $on = null): Model
    {
        $rows = collect([...$roles])
            ->unique(static fn (Role $role): string => $role->value)
            ->map(fn (Role $role): array => $this->assignmentAttributes($user, $role, $on))
            ->all();

        $user->getConnection()->transaction(function () use ($user, $rows, $on): void {
            $this->assignmentQuery($user, $on)->delete();
            $this->assignmentModel()::query()->insert($rows);
        });

        $this->flush($user);

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

        $values = $this->assignments($user, $on)
            ->where('scopeable_type', $on?->getMorphClass())
            ->where('scopeable_id', $on?->getKey())
            ->pluck('role')
            ->all();

        return collect($values)
            ->map(static fn (mixed $value): ?Role => is_string($value) ? $roleClass::tryFrom($value) : null)
            ->filter()
            ->values();
    }

    /** @return Collection<int, Ability> */
    public function permissions(Model $user, ?Model $on = null): Collection
    {
        $roles = $this->roles($user);

        if ($on instanceof Model) {
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

    public function flush(Model|int|string|null $user = null): void
    {
        if ($user === null) {
            $this->assignments = [];

            return;
        }

        unset($this->assignments[$this->memoKey($user)]);
    }

    private function memoKey(Model|int|string $user): string
    {
        $key = $user instanceof Model ? $user->getKey() : $user;

        return is_scalar($key) ? (string) $key : serialize($key);
    }

    /** @return Collection<int, RoleAssignment> */
    private function assignments(Model $user, ?Model $on): Collection
    {
        $this->assertPersisted($user, $on);

        return $this->assignments[$this->memoKey($user)] ??= $this->assignmentModel()::query()
            ->where('user_id', $user->getKey())
            ->get();
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
