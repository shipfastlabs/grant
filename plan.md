# Grant: Minimal Roles and Permissions for Laravel

Package: **Shipfastlabs/grant**. Namespace `Shipfastlabs\Grant`. Config, facade and artisan prefix: `grant`. See Naming at the end.

## One-line pitch

Permissions are an enum. Roles are an enum. Assignments are a table. Checks are the Gate.

## Core opinions

1. **No check API.** The package ships nothing for asking "can this user do X." Every check is native Laravel: `can()`, `Gate::`, `@can`, `can:` middleware, `authorize()`, Form Requests, Policies.
2. **Definitions live in code.** Roles and permissions are PHP backed enums, versioned in git, refactorable, type-safe. Changing what a role means is a deploy.
3. **Only assignments live in the database.** Which user holds which role, optionally scoped to a team/org/project. Admins change this at runtime.
4. **Roles are for assigning, permissions are for checking.** No `role:` middleware, no `@role` directive. If you need to gate on "admin", add a permission.
5. **Policies own model-level context.** Ownership, status checks, "own posts only" all stay in Policies. The package grants capability; policies add context.
6. **Ejectable.** Removing the package leaves every `can()` call valid. Inlining it should take about an hour.

## What the package ships

| Piece | Purpose |
|---|---|
| Gate bridge | Boot-time `Gate::define` per Permission case, `deniedMessage()` into `Response::deny`, super-admin `Gate::before`, scoped resolution |
| `Ability` interface | Marks the Permission enum; implementing it is the registration |
| `Role` contract | Requires `permissions(): array` on the Role enum |
| `HasRoles` trait | `grant`, `revoke`, `syncRoles`, `hasRole`, `roles()`, `permissions()` |
| One migration | `role_assignments` table with a `user_id` foreign key and nullable polymorphic scope, backed by a configurable `RoleAssignment` model |
| `#[Requires]` attribute | Policy-method gate, wired via reflection |
| Testing helpers | Factory state, `assertCan` / `assertCannot`, `withRole()` |
| Artisan | `grant:install`, `grant:list`, `grant:show {user}`, `grant:sync`, `make:permission`, `make:role` |
| Config | One file, five keys |

## Data model

```sql
role_assignments
  id
  user_id        (FK users.id, cascade on delete)
  role           (string, cast to Role enum)
  scopeable_type (nullable)
  scopeable_id   (nullable)
  unique (user_id, role, scopeable_type, scopeable_id)
```

No `roles` table. No `permissions` table. No `role_has_permissions`. No cache table.

Optional `column` mode: a single `role` column on `users` for single-role apps.

## Config

```php
// config/grant.php
return [
    'roles'       => App\Enums\Role::class,
    'permissions' => App\Enums\Permission::class,
    'super_admin' => App\Enums\Role::Admin,   // or null
    'storage'     => 'pivot',                 // 'pivot' | 'column'
];
```

## Defining

```php
// app/Enums/Permission.php
enum Permission: string implements Ability
{
    case EditPosts   = 'edit-posts';
    case DeletePosts = 'delete-posts';
    case ViewReports = 'view-reports';

    public function deniedMessage(): string
    {
        return match ($this) {
            self::EditPosts => 'You need editor access to change posts.',
            default         => 'You are not allowed to do that.',
        };
    }
}

// app/Enums/Role.php
enum Role: string implements RoleContract
{
    case Admin  = 'admin';
    case Editor = 'editor';
    case Viewer = 'viewer';

    public function permissions(): array
    {
        return match ($this) {
            self::Admin  => Permission::cases(),
            self::Editor => [Permission::EditPosts, Permission::DeletePosts],
            self::Viewer => [Permission::ViewReports],
        };
    }
}
```

```php
// app/Models/User.php
class User extends Authenticatable
{
    use HasRoles;
}
```

## Assigning (the only new vocabulary)

```php
$user->grant(Role::Editor);
$user->grant(Role::Admin, on: $team);
$user->revoke(Role::Editor);
$user->revoke(Role::Admin, on: $team);
$user->syncRoles([Role::Editor, Role::Viewer]);
$user->syncRoles([Role::Admin], on: $team);

$user->hasRole(Role::Admin);              // bool, for UI badges only
$user->roles();                           // Collection<Role>
$user->roles(on: $team);                  // scoped
$user->permissions();                     // Collection<Permission>, derived
```

## Checking (all native, nothing from the package)

```php
$user->can(Permission::EditPosts);
$user->can(Permission::EditPosts, $team);          // scoped
Gate::allows(Permission::EditPosts);
Gate::authorize(Permission::EditPosts);            // throws with deniedMessage()
Gate::inspect(Permission::EditPosts)->message();
Gate::forUser($user)->allows(Permission::EditPosts, $team);
```

```blade
@can(Permission::EditPosts)
    <x-edit-button />
@endcan
```

```php
Route::put('/posts/{post}', ...)->can(Permission::EditPosts);
Route::middleware('can:edit-posts')->group(...);

// Controller
$this->authorize(Permission::EditPosts);

// Form Request
public function authorize(): bool
{
    return $this->user()->can(Permission::EditPosts);
}
```

## Policies

Laravel resolves policies before defined abilities when a model is passed, so the two compose:

```php
class PostPolicy
{
    public function update(User $user, Post $post): bool
    {
        return $user->can(Permission::EditPosts)
            && $post->author_id === $user->id;
    }
}
```

With the attribute, the capability line disappears:

```php
#[Requires(Permission::EditPosts)]
public function update(User $user, Post $post): bool
{
    return $post->author_id === $user->id;
}
```

Implementation: `Gate::before` reflects the resolved policy method, checks the attribute, returns `false` on failure and `null` on success so the policy still runs.

## Super admin

```php
// exactly this, nothing more
Gate::before(fn ($user) => $user->hasRole(config('grant.super_admin')) ?: null);
```

Returning `null` (never `false`) keeps every other check intact.

## Scoping (teams, orgs, projects)

Handled by the nullable scope columns. No feature flag, no separate mode.

- `grant(Role::X)` with no scope = global.
- `grant(Role::X, on: $model)` = scoped.
- `$user->can(Permission::Y, $model)` resolves against roles held on `$model`, falling back to global roles.

Custom, user-created roles are out of scope. If needed later, a `custom_roles` table storing a permission list per row can be bolted on without touching the enum core.

## Frontend

```php
// Inertia / Livewire shared data
'permissions' => $user->permissions()->map->value,
```

Enum values are the ability strings, so frontend and backend use identical names.

## Testing

```php
User::factory()->role(Role::Editor)->create();
User::factory()->role(Role::Admin, on: $team)->create();

$this->actingAs($user)->assertCan(Permission::EditPosts);
$this->actingAs($user)->assertCannot(Permission::DeletePosts);
$this->actingAs($user)->withRole(Role::Admin);
```

## Artisan

```
php artisan grant:install           # config, migration, enums via make:permission / make:role
php artisan grant:list              # Gate::abilities() filtered to the enum
php artisan grant:show {user}        # roles + resolved permissions, optional --on=
php artisan grant:sync              # remap or delete stored roles missing from the enum
php artisan make:permission Name    # adds a case to Permission enum
php artisan make:role Name          # adds a case to Role enum
```

## Deliberately excluded

| Excluded | Why |
|---|---|
| DB-defined permissions / roles | Removes drift, seeding, and the entire cache-invalidation class of bugs |
| Admin UI | Belongs in the app, or in Spatie if you need dynamic roles |
| Role inheritance / hierarchy | `Permission::cases()` and spread operators cover it explicitly |
| Wildcard strings | Enums already give structure; super admin covers "all" |
| Multi-guard | Default guard only; document as out of scope |
| `role:` middleware, `@role` directive | Roles are not check targets |
| Model / instance abilities, ownership, forbids | Policies do this better |
| Caching layer | Nothing to cache beyond one eager-loaded relationship |
| Custom runtime roles | Point to Spatie; optional future add-on |

## API directions explored

### A. Enum-first hybrid (chosen)

Enums for definitions, pivot for assignments, native Gate for checks. Type-safe, IDE-friendly, zero cache. Loses runtime editability on purpose.

### B. String-first, config-based

```php
// config/roles.php
return [
    'admin'  => ['*'],
    'editor' => ['edit-posts', 'delete-posts'],
];
$user->assignRole('editor');
$user->can('edit-posts');
```

Familiar to Spatie users and terse, but loses autocompletion and refactoring safety. Could be offered as a compatibility layer, not the default.

### C. Fluent, Bouncer-lite

```php
Grant::give($user)->role(Role::Editor);
Grant::is($user)->an(Role::Admin);
```

Reads well but introduces a DSL and a facade-based check API, which violates opinion 1. Rejected.

### Also considered

- Class-based abilities (one class per permission): too heavy for a minimal package.
- Attributes on controllers (`#[Can(...)]`): route `->can()` already covers it; kept only for policy methods.
- Naming the enum `Ability` to match Laravel vocabulary: kept `Permission` for discoverability, named the interface `Ability`.

## Comparison

| | Spatie | Bouncer | ajimoti | ahrengot | Grant |
|---|---|---|---|---|---|
| Permission definitions | DB | DB | enum | config | enum |
| Role definitions | DB | DB | enum | enum | enum |
| Assignments | DB (2 pivots) | DB | DB | column | DB (1 pivot) |
| Tables | 5 | 4 | 1+ | 0 | 1 |
| Native PHP enums | partial | no | no | yes | yes |
| Scoped / team roles | opt-in flag, decide before migrating | scopes | `of()` | no | nullable scope, always on |
| Gate integration | `before` hook | `before` hook | weak | weak | `define` per ability + `before` for super admin |
| Cache | 24h, manual flush | yes | no | no | none |
| Policy attribute | no | no | no | no | yes |
| Runtime-editable roles | yes | yes | no | no | no (by design) |
| Maintained | yes | yes | no (2022) | low | you |

**Closest prior art:** ajimoti/roles-and-permissions proved the model but is abandoned, predates native enums, and adds hierarchy magic. laravarc/authorizer (2026) shares the "source of truth is code" stance but discovers abilities from policy classes, stores roles in DB with tenant ids, and runs everything through `Gate::before` ahead of policies, which is the opposite of the composition model here. The gap is a current, polished, enum-first, Gate-native version.

## Naming

**Package: `Shipfastlabs/grant`.** Namespace `Shipfastlabs\Grant`.

Why Grant: it names the one thing the package ships. You grant roles; Laravel's Gate does the checking. Verb and noun, short, sounds first-party, and makes the assignment API read naturally: `$user->grant(Role::Editor)`, `$user->revoke(Role::Editor)`.

Everything internal uses the same word, no separate `authz` prefix:

- `config/grant.php`
- `Grant` facade (assignment helpers only, never checks)
- `grant:install`, `grant:list`, `grant:show`
- `Shipfastlabs\Grant\HasRoles`, `Shipfastlabs\Grant\Ability`, `Shipfastlabs\Grant\Requires`

**Rejected**

| Name | Why not |
|---|---|
| authz | casbin/laravel-authz has 320k+ installs; aiarmada/authz exists; describes the category, not the package |
| Keycard, Latch, Clearance, Permit, Roster, Pass | fine, but Grant says what it does |
| Guestlist, Vibecheck, Plusone, Turnstile, Badge, Rank, Doorman | playful, weaker fit |
| Warden, Gatekeeper, Guardian, Sentinel, Shield, Bouncer | taken |

A Packagist search turned up no Laravel roles package named `grant`, but that is not a registry lookup. Confirm `Shipfastlabs/grant` on packagist.org and the GitHub repo name before announcing.
