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

it('generates the enum from the stub when it does not exist yet', function (): void {
    $class = 'App\\Enums\\Role'.bin2hex(random_bytes(6));
    $path = app_path('Enums/'.class_basename($class).'.php');
    config()->set('grant.roles', $class);
    config()->set('grant.permissions', 'App\\Enums\\Permission');

    try {
        $this->artisan('make:role', ['name' => 'admin'])
            ->expectsOutputToContain('created successfully')
            ->assertSuccessful();

        expect(file_get_contents($path))
            ->toContain('namespace App\\Enums;')
            ->toContain('use App\\Enums\\Permission;')
            ->toContain('enum '.class_basename($class).': string implements RoleContract')
            ->toContain("case Admin = 'admin';")
            ->toContain('self::Admin => Permission::cases(),')
            ->toContain('default => [],');
    } finally {
        @unlink($path);
    }
});

it('adds a kebab-cased case to a configured enum', function (): void {
    $class = 'Permission'.bin2hex(random_bytes(6));
    $path = sys_get_temp_dir()."/{$class}.php";
    $source = <<<PHP
    <?php
    enum {$class}: string implements \\Shipfastlabs\\Grant\\Ability
    {
        case Existing = 'existing';
        public function deniedMessage(): string { return 'Denied'; }
    }
    function {$class}_helper(): void {}
    PHP;

    file_put_contents($path, $source);
    require $path;
    config()->set('grant.permissions', $class);

    try {
        $this->artisan('make:permission', ['name' => 'Publish Posts'])
            ->expectsOutputToContain("Added PublishPosts to {$class}.")
            ->assertSuccessful();

        expect(file_get_contents($path))
            ->toContain("case PublishPosts = 'publish-posts';\n}\nfunction {$class}_helper");

        $this->artisan('make:permission', ['name' => 'existing'])
            ->expectsOutputToContain('already exists')
            ->assertFailed();
    } finally {
        @unlink($path);
    }
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

it('syncs the role column in column storage', function (): void {
    config()->set('grant.storage', 'column');
    $user = User::query()->create(['name' => 'Taylor', 'role' => 'legacy']);

    $this->artisan('grant:sync')
        ->expectsQuestion('Stored role [legacy] no longer exists. What should its 1 assignments become?', 'viewer')
        ->assertSuccessful();

    expect($user->fresh()?->roles()->all())->toBe([Role::Viewer]);

    $user->forceFill(['role' => 'dropped'])->save();

    $this->artisan('grant:sync')
        ->expectsQuestion('Stored role [dropped] no longer exists. What should its 1 assignments become?', '__delete')
        ->assertSuccessful();

    expect(User::query()->count())->toBe(1)
        ->and($user->fresh()?->roles())->toBeEmpty();
});
