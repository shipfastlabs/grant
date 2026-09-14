<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Testing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Assert;
use Shipfastlabs\Grant\Ability;
use Shipfastlabs\Grant\Grant;
use Shipfastlabs\Grant\Role;

trait InteractsWithGrant
{
    public function assertCan(Ability $ability, mixed ...$arguments): static
    {
        Assert::assertTrue(Gate::forUser(Auth::user())->allows($ability, $arguments));

        return $this;
    }

    public function assertCannot(Ability $ability, mixed ...$arguments): static
    {
        Assert::assertFalse(Gate::forUser(Auth::user())->allows($ability, $arguments));

        return $this;
    }

    public function withRole(Role $role, ?Model $on = null): static
    {
        $user = Auth::user();

        if (! $user instanceof Model) {
            Assert::fail('The authenticated user must be an Eloquent model.');
        }

        app(Grant::class)->grant($user, $role, $on);

        return $this;
    }
}
