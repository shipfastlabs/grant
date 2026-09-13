# Grant

- [Introduction](#introduction)
- [Installation](#installation)
    - [Publishing Resources](#publishing-resources)
- [Defining Permissions and Roles](#defining-permissions-and-roles)
    - [Permissions](#permissions)
    - [Roles](#roles)
    - [Inheriting Permissions](#inheriting-permissions)
    - [Renaming and Removing Roles](#renaming-and-removing-roles)
- [Assigning Roles](#assigning-roles)
    - [Preparing Your User Model](#preparing-your-user-model)
    - [Granting and Revoking Roles](#granting-and-revoking-roles)
    - [Scoped Roles](#scoped-roles)
    - [The Grant Facade](#the-grant-facade)
- [Authorizing Actions](#authorizing-actions)
    - [Via the Gate](#via-the-gate)
    - [Via Policies](#via-policies)
    - [Super Admins](#super-admins)
- [Configuration](#configuration)
    - [Customizing the Assignment Model](#customizing-the-assignment-model)
    - [Column Storage](#column-storage)
    - [UUID and ULID Keys](#uuid-and-ulid-keys)
- [Artisan Commands](#artisan-commands)
- [Testing](#testing)
- [Contributing](#contributing)
- [License](#license)

## Introduction

Grant provides minimal, enum-first roles and permissions for Laravel. Permissions are a PHP enum, roles are a PHP enum, assignments belong to any Eloquent model, and every authorization check uses Laravel's native Gate:

```php
$user->grant(Role::Editor);

$user->can(Permission::EditPosts);
```

Because definitions live in code, they are versioned, refactorable, and type-safe. Only assignments live in the database. Grant ships no check API of its own, so removing the package leaves every `can` call in your application valid.

Grant requires PHP 8.3+ and Laravel 12 or 13.

## Installation

You may install Grant via the Composer package manager:

```shell
composer require shipfastlabs/grant
```

Next, run the `grant:install` Artisan command. This command publishes Grant's configuration file and migration, then creates `app/Enums/Permission.php` and `app/Enums/Role.php` with `Admin` and `Member` roles:

```shell
php artisan grant:install

php artisan migrate
```

Finally, add the `Shipfastlabs\Grant\HasRoles` trait to your `User` model, or to any Eloquent model that should hold roles.

### Publishing Resources

The install command publishes everything you need. If you would like to publish an individual resource, you may use the `vendor:publish` Artisan command with one of Grant's publish tags:

```shell
php artisan vendor:publish --tag=grant-config
php artisan vendor:publish --tag=grant-migrations
php artisan vendor:publish --tag=grant-stubs
```

The `grant-stubs` tag copies `permission.stub` and `role.stub` into your application's `stubs` directory. The `make:permission` and `make:role` commands will use your customized stubs when generating enums.

## Defining Permissions and Roles

### Permissions

Permissions are the abilities your application checks. They are defined as a string-backed enum that implements the `Shipfastlabs\Grant\Ability` interface. The interface requires a `deniedMessage` method, which Grant passes to the Gate's denial response:

```php
<?php

namespace App\Enums;

use Shipfastlabs\Grant\Ability;

enum Permission: string implements Ability
{
    case EditPosts = 'edit-posts';
    case DeletePosts = 'delete-posts';
    case ViewReports = 'view-reports';

    public function deniedMessage(): string
    {
        return match ($this) {
            self::EditPosts => 'You need editor access to change posts.',
            default => 'You are not allowed to do that.',
        };
    }
}
```

Each case's backing value is the ability string registered with the Gate, so `Permission::EditPosts` and `'edit-posts'` refer to the same ability.

### Roles

Roles group permissions for assignment. They are a string-backed enum that implements the `Shipfastlabs\Grant\Role` interface, which requires a `permissions` method returning the permissions the role holds:

```php
<?php

namespace App\Enums;

use Shipfastlabs\Grant\Role as RoleContract;

enum Role: string implements RoleContract
{
    case Admin = 'admin';
    case Editor = 'editor';
    case Viewer = 'viewer';

    public function permissions(): array
    {
        return match ($this) {
            self::Admin => Permission::cases(),
            self::Editor => [Permission::EditPosts, Permission::DeletePosts],
            self::Viewer => [Permission::ViewReports],
        };
    }
}
```

> [!NOTE]
> Roles are for assigning and permissions are for checking. Grant intentionally provides no `role:` middleware or `@role` Blade directive. If you need to gate on a role, add a permission to it instead.

### Inheriting Permissions

Grant has no role hierarchy feature because PHP already provides one. Since `permissions` returns an array, you may spread another role's permissions into a case to build one role on top of another:

```php
public function permissions(): array
{
    return match ($this) {
        self::Admin => Permission::cases(),
        self::Editor => [
            Permission::EditPosts,
            Permission::DeletePosts,
            ...self::Viewer->permissions(),
        ],
        self::Viewer => [Permission::ViewReports],
    };
}
```

The `Editor` role now holds every `Viewer` permission in addition to its own. Grant removes duplicate permissions when resolving a user's abilities, so overlapping lists are harmless.

### Renaming and Removing Roles

Only a role's backing value is stored in the database, so renaming a case while keeping its value, or renaming any permission, requires no data changes. Changing a role's value or removing a case leaves stored assignments that no longer match the enum. Grant ignores those rows when resolving roles, so affected users silently lose the role rather than triggering an error.

After changing the enum, run the `grant:sync` Artisan command. It lists every stored value without a matching case and asks what each one should become:

```shell
php artisan grant:sync
```

```
 Stored role [admin] no longer exists. What should its 12 assignments become?
 › administrator
   editor
   viewer
   Delete them
```

Assignments are remapped or deleted immediately. If a user already holds the target role in the same scope, the orphaned row is simply removed. When run with `--no-interaction`, the command only reports orphaned values and exits with a failure status, which makes it a convenient deployment check:

```shell
php artisan grant:sync --no-interaction
```

## Assigning Roles

### Preparing Your User Model

Add the `Shipfastlabs\Grant\HasRoles` trait to your `User` model. Grant resolves the user model from your `auth.providers.users.model` configuration, so any class configured there will work:

```php
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Shipfastlabs\Grant\HasRoles;

class User extends Authenticatable
{
    use HasRoles;
}
```

Assignments are stored in the `role_assignments` table with a `user_id` foreign key that cascades on delete, so removing a user removes their assignments at the database level. The trait also provides a `roleAssignments` relationship for direct access to those rows:

```php
$user->roleAssignments; // Collection<RoleAssignment>
```

> [!NOTE]
> Grant supports a single user model. If your application authenticates several models on different guards, only the model configured as the default users provider may hold roles.

### Granting and Revoking Roles

The trait provides a small set of methods for managing a user's roles:

```php
use App\Enums\Role;

$user->grant(Role::Editor);

$user->revoke(Role::Editor);

$user->syncRoles([Role::Editor, Role::Viewer]);
```

You may inspect the roles and permissions a user currently holds:

```php
$user->hasRole(Role::Admin);

$user->roles(); // Collection<Role>

$user->permissions(); // Collection<Permission>
```

Permissions are always derived from roles at call time, so there is nothing to cache or invalidate. Enum values are the ability strings, which makes them convenient to share with your frontend:

```php
'permissions' => $user->permissions()->map->value,
```

### Scoped Roles

A role may be granted globally or scoped to any persisted Eloquent model, such as a team or project. To scope a role, pass the model as the `on` argument:

```php
$user->grant(Role::Admin, on: $team);

$user->revoke(Role::Admin, on: $team);

$user->syncRoles([Role::Admin], on: $team);

$user->roles(on: $team);
```

When authorizing with a scope, Grant resolves the roles held on that model and falls back to the user's global roles. A scoped check never grants less than an unscoped check would:

```php
$user->can(Permission::EditPosts, $team);

$user->permissions(on: $team);
```

### The Grant Facade

The `Grant` facade exposes the same assignment operations for situations where calling a method on the model is not convenient. The facade never performs authorization checks:

```php
use Shipfastlabs\Grant\Facades\Grant;

Grant::grant($user, Role::Editor);
Grant::revoke($user, Role::Editor);
Grant::syncRoles($user, [Role::Viewer]);
Grant::hasRole($user, Role::Editor);
```

## Authorizing Actions

### Via the Gate

At boot, Grant defines a Gate ability for every case of your permission enum. There is no package-specific check API. Instead, you use each of Laravel's authorization features as you normally would:

```php
use App\Enums\Permission;
use Illuminate\Support\Facades\Gate;

$user->can(Permission::EditPosts);

Gate::allows(Permission::EditPosts);

Gate::authorize(Permission::DeletePosts); // throws with deniedMessage()

Gate::inspect(Permission::EditPosts)->message();
```

```blade
@can(Permission::EditPosts)
    <x-edit-button />
@endcan
```

```php
Route::put('/posts/{post}', UpdatePostController::class)
    ->can(Permission::EditPosts);

Route::middleware('can:edit-posts')->group(function () {
    // ...
});
```

Form requests and controller authorization work the same way:

```php
public function authorize(): bool
{
    return $this->user()->can(Permission::EditPosts);
}
```

### Via Policies

Policies continue to own model-level context such as ownership and status. Grant supplies the capability and your policy adds the context:

```php
public function update(User $user, Post $post): bool
{
    return $user->can(Permission::EditPosts)
        && $post->author_id === $user->id;
}
```

The `Shipfastlabs\Grant\Requires` attribute removes the repeated capability check. When a policy method carries the attribute, the Gate denies the action unless the user holds the given permission, then runs the policy method as usual:

```php
use Shipfastlabs\Grant\Requires;

#[Requires(Permission::EditPosts)]
public function update(User $user, Post $post): bool
{
    return $post->author_id === $user->id;
}
```

The Gate arguments are forwarded to the permission check, so `#[Requires]` honors roles scoped to the policy's model.

### Super Admins

You may designate one role as a super admin in your configuration file. Users holding that role pass every Gate check, including policies, before any other ability is consulted:

```php
'super_admin' => App\Enums\Role::Admin,
```

Set the option to `null` to disable the bypass entirely.

## Configuration

Grant's configuration file is published to `config/grant.php` and contains five options:

```php
return [
    'roles' => App\Enums\Role::class,
    'permissions' => App\Enums\Permission::class,
    'super_admin' => null,
    'storage' => 'pivot',
    'model' => Shipfastlabs\Grant\Models\RoleAssignment::class,
];
```

### Customizing the Assignment Model

Each row of the `role_assignments` table is represented by the `Shipfastlabs\Grant\Models\RoleAssignment` Eloquent model, which provides `user` and `scopeable` relationships. To add relationships, query scopes, or casts of your own, extend the model and point the `model` option at your subclass:

```php
<?php

namespace App\Models;

use Shipfastlabs\Grant\Models\RoleAssignment as BaseRoleAssignment;

class RoleAssignment extends BaseRoleAssignment
{
    // ...
}
```

```php
'model' => App\Models\RoleAssignment::class,
```

Grant uses the configured model for every query it makes, including the `roleAssignments` relationship on your user model.

### Column Storage

Applications where each user holds exactly one global role may set `storage` to `column`. In this mode, Grant stores the role in a `role` column on the users table instead of the `role_assignments` table:

```php
'storage' => 'column',
```

You are responsible for adding a nullable `role` string column in your own migration. Casting the column to your `Role` enum is supported.

> [!WARNING]
> Column storage supports a single global role per user. Granting multiple roles or a scoped role will throw an `InvalidArgumentException`.

### UUID and ULID Keys

The published migration uses `foreignId` for the user and `nullableMorphs` for the scope. If your users use UUID or ULID primary keys, change the user column to `foreignUuid` or `foreignUlid`. If your scopes do, change the scope columns to `nullableUuidMorphs` or `nullableUlidMorphs`. Make these edits before running the migration.

## Artisan Commands

Grant provides a handful of Artisan commands for inspecting and extending your definitions:

```shell
# List every permission registered with the Gate...
php artisan grant:list

# Show a user's roles and resolved permissions...
php artisan grant:show 1
php artisan grant:show 1 --on='App\Models\Team:5'

# Add a case to the configured enum...
php artisan make:permission PublishPosts
php artisan make:role Publisher

# Remap or delete stored roles that no longer match the enum...
php artisan grant:sync
```

The `make:permission` and `make:role` commands append a kebab-cased, string-backed case to the configured enum. If the enum does not exist yet, it is generated from the stub. After adding a role, remember to map its permissions in `Role::permissions`.

## Testing

Grant registers a `role` state on every Eloquent factory, allowing you to create models with roles already assigned:

```php
$editor = User::factory()->role(Role::Editor)->create();

$admin = User::factory()->role(Role::Admin, on: $team)->create();
```

For fluent authorization assertions, add the `Shipfastlabs\Grant\Testing\InteractsWithGrant` trait to your base test case:

```php
use Shipfastlabs\Grant\Testing\InteractsWithGrant;

abstract class TestCase extends BaseTestCase
{
    use InteractsWithGrant;
}
```

The trait provides `assertCan`, `assertCannot`, and `withRole` helpers that operate on the currently authenticated user:

```php
$this->actingAs($user)
    ->assertCannot(Permission::EditPosts)
    ->withRole(Role::Editor)
    ->assertCan(Permission::EditPosts);
```

## Contributing

Thank you for considering contributing to Grant. Please review the [contribution guide](.github/CONTRIBUTING.md) before opening a pull request, and run the full test suite before submitting:

```shell
composer test
```

Security vulnerabilities should be reported according to the [security policy](.github/SECURITY.md).

## License

Grant is open-sourced software licensed under the [MIT license](LICENSE.md).
