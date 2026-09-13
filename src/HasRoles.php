<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Shipfastlabs\Grant\Models\RoleAssignment;

trait HasRoles
{
    /** @return HasMany<RoleAssignment, $this> */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(app(Grant::class)->assignmentModel(), 'user_id');
    }

    public function grant(Role $role, ?Model $on = null): static
    {
        app(Grant::class)->grant($this, $role, $on);

        return $this;
    }

    public function revoke(Role $role, ?Model $on = null): static
    {
        app(Grant::class)->revoke($this, $role, $on);

        return $this;
    }

    /** @param iterable<Role> $roles */
    public function syncRoles(iterable $roles, ?Model $on = null): static
    {
        app(Grant::class)->syncRoles($this, $roles, $on);

        return $this;
    }

    public function hasRole(Role $role, ?Model $on = null): bool
    {
        return app(Grant::class)->hasRole($this, $role, $on);
    }

    /** @return Collection<int, Role> */
    public function roles(?Model $on = null): Collection
    {
        return app(Grant::class)->roles($this, $on);
    }

    /** @return Collection<int, Ability> */
    public function permissions(?Model $on = null): Collection
    {
        return app(Grant::class)->permissions($this, $on);
    }
}
