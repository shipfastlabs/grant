<?php

declare(strict_types=1);

use Shipfastlabs\Grant\Models\RoleAssignment;
use Shipfastlabs\Grant\Tests\Fixtures\Role;
use Shipfastlabs\Grant\Tests\Fixtures\User;

it('lists registered permission abilities', function (): void {
    $this->artisan('grant:list')
        ->expectsTable(
            ['Permission', 'Ability'],
            [
                ['EditPosts', 'edit-posts'],
                ['DeletePosts', 'delete-posts'],
                ['ViewReports', 'view-reports'],
            ],
        )
        ->assertSuccessful();
});

it('shows a user’s roles and resolved permissions', function (): void {
    $user = User::query()->create(['name' => 'Taylor']);
    $user->grant(Role::Editor);

    $this->artisan('grant:show')
        ->expectsQuestion('Which user should be shown?', $user->getKey())
        ->expectsTable([], [
            ['Roles', 'editor'],
            ['Permissions', 'edit-posts, delete-posts'],
        ])
        ->assertSuccessful();
});

it('remaps or deletes stored roles that no longer match the enum', function (): void {
    $user = User::query()->create(['name' => 'Taylor']);
    $other = User::query()->create(['name' => 'Other']);
    $user->grant(Role::Editor);
    RoleAssignment::query()->insert([
        ['user_id' => $user->getKey(), 'role' => 'legacy-admin', 'scopeable_type' => null, 'scopeable_id' => null],
        ['user_id' => $other->getKey(), 'role' => 'legacy-admin', 'scopeable_type' => null, 'scopeable_id' => null],
        ['user_id' => $other->getKey(), 'role' => 'removed', 'scopeable_type' => null, 'scopeable_id' => null],
    ]);

    $this->artisan('grant:sync', ['--no-interaction' => true])
        ->expectsOutputToContain('legacy-admin, removed')
        ->assertFailed();

    $this->artisan('grant:sync')
        ->expectsQuestion('Stored role [legacy-admin] no longer exists. What should its 2 assignments become?', 'editor')
        ->expectsQuestion('Stored role [removed] no longer exists. What should its 1 assignments become?', '__delete')
        ->expectsOutputToContain('Mapped 2 assignments of [legacy-admin] to [editor].')
        ->expectsOutputToContain('Deleted 1 assignments of [removed].')
        ->assertSuccessful();

    expect($user->roles()->all())->toBe([Role::Editor])
        ->and($other->roles()->all())->toBe([Role::Editor])
        ->and(RoleAssignment::query()->count())->toBe(2);

    $this->artisan('grant:sync')
        ->expectsOutputToContain('Every stored role matches')
        ->assertSuccessful();
});

it('installs the config, migration, and starter enums', function (): void {
    config()->set('grant.permissions', 'App\\Enums\\Permission');
    config()->set('grant.roles', 'App\\Enums\\Role');

    try {
        $this->artisan('grant:install')->assertSuccessful();

        expect(file_get_contents(app_path('Enums/Permission.php')))
            ->toContain('namespace App\\Enums;')
            ->toContain('enum Permission: string implements Ability')
            ->and(file_get_contents(app_path('Enums/Role.php')))
            ->toContain('enum Role: string implements RoleContract')
            ->toContain('self::Admin => Permission::cases(),')
            ->and(file_exists(config_path('grant.php')))->toBeTrue();
    } finally {
        @unlink(app_path('Enums/Permission.php'));
        @unlink(app_path('Enums/Role.php'));
        @unlink(config_path('grant.php'));
        array_map(unlink(...), glob(database_path('migrations/*_create_role_assignments_table.php')) ?: []);
    }
});
