<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Shipfastlabs\Grant\Exceptions\ColumnStorageException;
use Shipfastlabs\Grant\Exceptions\InvalidConfigurationException;
use Shipfastlabs\Grant\Exceptions\UnsavedModelException;
use Shipfastlabs\Grant\Facades\Grant;
use Shipfastlabs\Grant\Models\RoleAssignment;
use Shipfastlabs\Grant\Tests\Fixtures\Permission;
use Shipfastlabs\Grant\Tests\Fixtures\Post;
use Shipfastlabs\Grant\Tests\Fixtures\Role;
use Shipfastlabs\Grant\Tests\Fixtures\Team;
use Shipfastlabs\Grant\Tests\Fixtures\User;

it('assigns, revokes, and synchronizes global roles', function (): void {
    $user = User::query()->create(['name' => 'Taylor']);

    $user->grant(Role::Editor)->grant(Role::Viewer);

    expect($user->hasRole(Role::Editor))->toBeTrue()
        ->and($user->roles()->all())->toEqualCanonicalizing([Role::Editor, Role::Viewer])
        ->and($user->permissions()->all())->toEqualCanonicalizing(Permission::cases());

    $user->revoke(Role::Viewer);
    $user->syncRoles([Role::Admin]);

    expect($user->roles()->all())->toBe([Role::Admin]);
});

it('exposes assignments as a relationship and removes them when the user is deleted', function (): void {
    $user = User::query()->create(['name' => 'Taylor']);
    $other = User::query()->create(['name' => 'Other']);
    $team = Team::query()->create(['name' => 'Laravel']);

    $user->grant(Role::Editor)->grant(Role::Viewer, on: $team);
    $other->grant(Role::Editor);

    expect($user->roleAssignments)->toHaveCount(2)
        ->each->toBeInstanceOf(RoleAssignment::class)
        ->and($user->roleAssignments->firstWhere('role', 'viewer')?->scopeable)->toBeInstanceOf(Team::class)
        ->and($user->roleAssignments->first()?->user)->toBeInstanceOf(User::class);

    $user->delete();

    expect(RoleAssignment::query()->count())->toBe(1)
        ->and($other->roles()->all())->toBe([Role::Editor]);
});

it('uses the configured assignment model and rejects other classes', function (): void {
    $model = new class extends RoleAssignment
    {
        protected $table = 'role_assignments';
    };
    config()->set('grant.model', $model::class);
    $user = User::query()->create(['name' => 'Taylor']);

    $user->grant(Role::Editor);

    expect($user->roleAssignments()->first())->toBeInstanceOf($model::class)
        ->and($user->roles()->all())->toBe([Role::Editor]);

    config()->set('grant.model', Team::class);

    expect(fn () => $user->roles())
        ->toThrow(InvalidConfigurationException::class, 'grant.model');
});

it('keeps scoped roles separate and falls back to global permissions', function (): void {
    $user = User::query()->create(['name' => 'Taylor']);
    $firstTeam = Team::query()->create(['name' => 'First']);
    $secondTeam = Team::query()->create(['name' => 'Second']);

    $user->grant(Role::Viewer);
    $user->grant(Role::Editor, on: $firstTeam);

    expect($user->roles(on: $firstTeam)->all())->toBe([Role::Editor])
        ->and($user->roles(on: $secondTeam))->toBeEmpty()
        ->and($user->permissions(on: $firstTeam)->all())->toEqualCanonicalizing(Permission::cases())
        ->and($user->permissions(on: $secondTeam)->all())->toBe([Permission::ViewReports]);
});

it('registers native Gate abilities with denial messages and scoped resolution', function (): void {
    $user = User::query()->create(['name' => 'Taylor']);
    $team = Team::query()->create(['name' => 'First']);
    $user->grant(Role::Editor, on: $team);

    expect(Gate::forUser($user)->allows(Permission::EditPosts, $team))->toBeTrue()
        ->and(Gate::forUser($user)->allows(Permission::EditPosts))->toBeFalse()
        ->and(Gate::forUser($user)->inspect(Permission::EditPosts)->message())->toBe('Editor access is required.');
});

it('denies instead of throwing for non-model gate arguments and unsaved models', function (): void {
    $user = User::query()->create(['name' => 'Taylor']);
    $user->grant(Role::Viewer);

    expect(Gate::forUser($user)->allows(Permission::ViewReports, Post::class))->toBeTrue()
        ->and(Gate::forUser($user)->allows(Permission::ViewReports, [new Team, 'extra']))->toBeTrue()
        ->and(Gate::forUser($user)->allows(Permission::EditPosts, 5))->toBeFalse()
        ->and(Gate::forUser(new User)->allows(Permission::ViewReports))->toBeFalse();
});

it('supports a single global role in column storage', function (): void {
    config()->set('grant.storage', 'column');
    $user = User::query()->create(['name' => 'Taylor']);
    $user->mergeCasts(['role' => Role::class]);

    $user->grant(Role::Editor);

    expect($user->roles()->all())->toBe([Role::Editor])
        ->and($user->fresh()->roles()->all())->toBe([Role::Editor]);

    $user->syncRoles([Role::Viewer]);
    expect($user->fresh()->roles()->all())->toBe([Role::Viewer]);

    $user->revoke(Role::Viewer);
    expect($user->fresh()->roles())->toBeEmpty();
});

it('rejects multiple or scoped roles in column storage', function (): void {
    config()->set('grant.storage', 'column');
    $user = User::query()->create(['name' => 'Taylor']);
    $team = Team::query()->create(['name' => 'First']);

    expect(fn () => $user->syncRoles([Role::Editor, Role::Viewer]))
        ->toThrow(ColumnStorageException::class, 'only one role')
        ->and(fn () => $user->grant(Role::Editor, on: $team))
        ->toThrow(ColumnStorageException::class, 'does not support scoped roles');
});

it('rejects unsaved scopes', function (): void {
    $user = User::query()->create(['name' => 'Taylor']);

    expect(fn () => $user->grant(Role::Editor, on: new Team))
        ->toThrow(UnsavedModelException::class, 'persisted Eloquent model');
});

it('supports facade assignments, factory states, and test helpers', function (): void {
    $user = User::factory()->role(Role::Viewer)->create();
    Grant::revoke($user, Role::Viewer);

    $this->actingAs($user)
        ->assertCannot(Permission::EditPosts)
        ->withRole(Role::Editor)
        ->assertCan(Permission::EditPosts);
});

it('allows a configured super admin before every other ability', function (): void {
    config()->set('grant.super_admin', Role::Admin);
    $user = User::query()->create(['name' => 'Taylor']);
    $user->grant(Role::Admin);

    expect(Gate::forUser($user)->allows('undefined-ability'))->toBeTrue();
});
