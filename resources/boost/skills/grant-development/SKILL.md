---
name: grant-development
description: >
  Install and use Grant's enum-first roles and native Laravel Gate integration.
license: MIT
metadata:
  author: Pushpak Chhajed
---

# Grant

Use this skill when a Laravel application needs enum-defined roles and permissions with database-backed assignments.

## Primary Goal

Apply `shipfastlabs/grant` while keeping every authorization check in Laravel's native Gate API.

## Workflow

1. Install and migrate with `php artisan grant:install && php artisan migrate`.
2. Define string-backed `App\Enums\Permission` cases implementing `Shipfastlabs\Grant\Ability` and denial messages.
3. Adjust the generated string-backed `App\Enums\Role` implementing `Shipfastlabs\Grant\Role`. It ships with `Admin` mapped to all permissions and `Member` mapped to none.
4. Add `Shipfastlabs\Grant\HasRoles` to the auth user model configured in `auth.providers.users.model`.
5. Assign with `grant`, `revoke`, or `syncRoles`; the user must be persisted, and `on:` accepts another persisted model as the scope.
6. Check only with `can()`, `Gate`, `@can`, `can:` middleware, Form Requests, or Policies.
7. Use `#[Requires(Permission::...)]` on a policy method when capability and model-context checks should compose.

## References

- `config/grant.php` — enum classes, super-admin role, storage mode, and the assignment model class
- `app/Enums/Permission.php` and `app/Enums/Role.php` — application definitions
- `php artisan grant:list` — registered permissions
- `php artisan grant:show {user} --on='ModelClass:id'` — resolved assignments
- `php artisan grant:sync` — after changing a role's value or removing a case, remap or delete the stored assignments that no longer match; `--no-interaction` only reports them and fails
- `Shipfastlabs\Grant\Models\RoleAssignment` — Eloquent model for `role_assignments`, extend it and set `grant.model` to customize; `$user->roleAssignments` exposes the rows
- `php artisan make:permission {Name}` / `php artisan make:role {Name}` — append a kebab-cased string case to the configured enum, generating it from the stub when missing
- `Shipfastlabs\Grant\Facades\Grant` — `grant`, `revoke`, `syncRoles`, `hasRole`, `roles`, `permissions` when a model method is not convenient
- Publish tags: `grant` (everything), `grant-config`, `grant-migrations`, `grant-stubs`

## Examples

```php
$user->grant(Role::Editor);
$user->grant(Role::Admin, on: $team);

$user->can(Permission::EditPosts);
$user->can(Permission::EditPosts, $team); // scoped roles fall back to global roles
```

For tests, add `Shipfastlabs\Grant\Testing\InteractsWithGrant` to the base test case, then use `assertCan`, `assertCannot`, and `withRole`. Factories support `->role(Role::Editor, on: $team)`.

## Anti-patterns

- Do not add a package-specific check wrapper; use Laravel Gate.
- Do not check roles for authorization; check permissions.
- Do not store role or permission definitions in the database.
- Do not put ownership or model-state rules in role definitions; keep them in Policies.
- Do not use scoped or multiple roles with `storage => column`.
