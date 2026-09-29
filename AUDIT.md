# Code Audit

Tick an item when it lands, and note the commit next to it.

- P0: broken today, the behaviour is wrong.
- P1: misleading today, users or contributors will get it wrong.
- P2: inconsistent with siblings or other layers, or costly to change.
- P3: polish, duplication, dead code.

## P0: Broken

- [x] 1. (7d8843f) `GrantServiceProvider::registerGate()` (`src/GrantServiceProvider.php:45`) resolves the `scoped` `Grant` once at boot and captures it in every Gate ability (`:68`), the super-admin hook (`:83`) and the `saved`/`deleted` listeners (`:55`). Queue workers (`Queue/QueueServiceProvider.php:263`) and Octane call `forgetScopedInstances()` between jobs and requests, so `HasRoles` and the facade get a new instance while the Gate keeps reading the boot instance's memo for the life of the worker. After the first job, a role revoked elsewhere, or revoked in the same worker through `revoke()` (a query-builder delete that fires no event and flushes only the new instance), still passes `can()` until the worker restarts. The README promises the memo "never leaks between users or jobs". Fix: resolve `Grant` inside each closure (Inline Temp: `app(Grant::class)` at call time), so every check uses the current scoped instance.

## P1: Misleading

- [x] 2. (7d8843f) The README says assignments "belong to any Eloquent model" (`README.md:42`) and invites adding `HasRoles` "to any Eloquent model that should hold roles" (`README.md:70`). But assignments are keyed only by `user_id` (`src/Grant.php:123`, `:136`, `:167`), the memo is keyed only by primary key (`src/Grant.php:154`), and the migration constrains `user_id` to `users` (`database/migrations/..._create_role_assignments_table.php:15`). A second model using the trait would share roles with the user that has the same id. `README.md:215` already says only one user model is supported. Fix: reword both sentences to name the configured `auth.providers.users.model`.

## P2: Inconsistent

- [x] 3. (7d8843f) `#[Requires]` denials drop the permission's `deniedMessage()`. `enforcePolicyAttributes()` returns a bare `false` (`src/GrantServiceProvider.php:118`), so `Gate::authorize('update', $post)` fails with Laravel's generic "This action is unauthorized." A direct `Gate::authorize(Permission::EditPosts)` returns the enum's message (`:76`). The Gate passes a `Response` returned from a `before` callback straight through `inspect()`. Fix: Substitute Algorithm, i.e. `inspect()` the required ability and return the denial `Response` instead of `false`.
- [x] 4. (7d8843f) `Grant::grant()` writes through the model (`firstOrCreate`, `src/Grant.php:24`), but `revoke()` (`:33`) and `syncRoles()` (`:49-50`) use query-builder `delete()`/`insert()`. A configured `grant.model` subclass, which the README advertises for customization, sees `created`/`saved` events on grant but no `deleted` events on revoke or sync. `syncRoles()` also deletes and re-inserts roles that did not change, so the ids of those rows change on every sync. Fix: delete rows through the model, and have `syncRoles()` remove only the rows that are no longer wanted and `firstOrCreate` the rest (Substitute Algorithm).
- [x] 5. (7d8843f) `grant.super_admin` is the only config key that isn't validated. A string such as `'admin'`, or a case of a different enum, fails `instanceof Role`/`containsStrict` in `allowSuperAdmin()` (`src/GrantServiceProvider.php:86-89`) and silently disables the bypass. `roles`, `permissions` and `model` all throw `InvalidConfigurationException` (`src/Grant.php:112`, `:181`). Fix: Extract Method `Grant::superAdmin(): ?Role`, which validates against `roleClass()` and throws like its siblings.
- [x] 6. (7d8843f) `grant:show --on=` prints scoped roles next to merged permissions (`src/Console/Commands/ShowCommand.php:38-39`). `roles($user, $scope)` excludes global roles and `permissions($user, $scope)` includes them, so a user with a global `Editor` shows `Roles: None` next to `Permissions: edit-posts, delete-posts`. Fix: print global roles, scoped roles and resolved permissions as separate rows.
- [x] 7. (7d8843f) `flushOnWrite()` flushes only the row's current `user_id` (`src/GrantServiceProvider.php:56-58`). Re-pointing an assignment to another user (`$assignment->update(['user_id' => $other->id])`) leaves the original user's memo holding the role for the rest of the request. The README says saving a `RoleAssignment` "clears the affected user". Fix: also flush `getOriginal('user_id')`, which the `saved` event still exposes before `syncOriginal()`.

## P3: Polish

### Dead code

- [x] 8. (7d8843f) `ListCommand` filters cases by `$gate->has($permission)` (`src/Console/Commands/ListCommand.php:21`). The provider defines every case of `permissionClass()` at boot (`src/GrantServiceProvider.php:67`), and `permissionClass()` throws when the config is invalid, so the filter is always true. Fix: Remove the filter and the `Gate` parameter.

### Stale documentation

- [x] 9. (7d8843f) `plan.md` still describes features that commit `f0aca76` dropped: the `storage`/`column` mode (`plan.md:30`, `:46`, `:56`), `make:permission`/`make:role` (`:29`, `:221`, `:225-226`), and "Cache: none" (`:240`, `:289`). `README.md:241` says there is "nothing to cache or invalidate" directly above the Caching section that documents `Grant::flush()`. Fix: bring both in line with the shipped behaviour.

### Tooling

- [x] 10. (7d8843f) `phpunit.xml.dist` declares a `Unit` suite for `tests/Unit`, a directory that was never committed, so `composer test:unit` and `vendor/bin/pest` abort with "Test directory not found" before running any test, on the base branch too. Fix: remove the empty suite.
