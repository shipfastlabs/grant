<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Enums\Role;
use Shipfastlabs\Grant\Models\RoleAssignment;

return [

    /*
    |--------------------------------------------------------------------------
    | Role Enum
    |--------------------------------------------------------------------------
    |
    | This string-backed enum defines every role that your application grants.
    | Each case maps to the permissions it holds through its permissions()
    | method, so changing what a role means is a code change and deploy.
    |
    */

    'roles' => Role::class,

    /*
    |--------------------------------------------------------------------------
    | Permission Enum
    |--------------------------------------------------------------------------
    |
    | This string-backed enum defines every permission checked through the Gate.
    | Each case gets registered as a native Laravel ability at boot, so you
    | may use can(), @can, and the can: middleware with the enum values.
    |
    */

    'permissions' => Permission::class,

    /*
    |--------------------------------------------------------------------------
    | Super Admin Role
    |--------------------------------------------------------------------------
    |
    | Users holding this role will pass every Gate check before any other
    | ability or policy is consulted. Set this to a case of your role enum,
    | such as Role::Admin, or leave it as null to disable this shortcut.
    |
    */

    'super_admin' => null,

    /*
    |--------------------------------------------------------------------------
    | Assignment Model
    |--------------------------------------------------------------------------
    |
    | This Eloquent model represents a single row of the role_assignments table
    | and backs each query Grant makes. You may subclass it and point this
    | option at your subclass to add relationships, scopes, or methods.
    |
    */

    'model' => RoleAssignment::class,

];
