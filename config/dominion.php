<?php

use Infinity\Dominion\Models\Assignment;
use Infinity\Dominion\Models\Permission;
use Infinity\Dominion\Models\Role;

return [

    /*
    |--------------------------------------------------------------------------
    | Authorization Enums
    |--------------------------------------------------------------------------
    |
    | The application owns the backed enums that define Dominion's catalog.
    | A single role enum and any number of permission enums may be supplied.
    | Permission values must be unique across every configured enum.
    |
    */

    'enums' => [
        // Backed enum class containing every role managed by Dominion.
        'role' => null,

        // Backed enum classes whose combined cases form the permission catalog.
        'permissions' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant Model
    |--------------------------------------------------------------------------
    |
    | Set the application model accepted by the facade's in() method. Global
    | assignments remain available through globally() and are inherited by
    | tenant checks according to Dominion's specificity rules.
    |
    */

    'tenant' => [
        // Eloquent model class accepted as the tenant for scoped assignments.
        'model' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Role Permissions
    |--------------------------------------------------------------------------
    |
    | Map role enum values to permission enum cases or values. The wildcard
    | grants every configured permission to that role during synchronization.
    |
    */

    'roles' => [],

    /*
    |--------------------------------------------------------------------------
    | Assignment Profiles
    |--------------------------------------------------------------------------
    |
    | Profiles are configuration recipes, not persisted models. Each profile
    | may contain "roles", "allow", and "deny" arrays and is applied within
    | one transaction by the facade.
    |
    */

    'profiles' => [],

    /*
    |--------------------------------------------------------------------------
    | Permission Defaults
    |--------------------------------------------------------------------------
    |
    | These effects are materialized on permission catalog rows by sync. The
    | same permission cannot appear in both lists. With no applicable direct,
    | role, or default decision, Dominion always denies access.
    |
    */

    'defaults' => [
        // Permissions allowed when no direct or role-derived decision applies.
        'allow' => [],

        // Permissions explicitly denied at the catalog-default precedence level.
        'deny' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Models and Tables
    |--------------------------------------------------------------------------
    |
    | Custom catalog models may be supplied when they extend the corresponding
    | Dominion model. Configure table names before publishing the migration.
    |
    */

    'models' => [
        // Eloquent model used for unified global and tenant assignments.
        'assignment' => Assignment::class,

        // Eloquent model used to persist synchronized roles.
        'role' => Role::class,

        // Eloquent model used to persist synchronized permissions and defaults.
        'permission' => Permission::class,
    ],

    'tables' => [
        // Catalog table containing normalized role values.
        'roles' => 'dominion_roles',

        // Catalog table containing permission values and materialized defaults.
        'permissions' => 'dominion_permissions',

        // Pivot table containing synchronized role-to-permission mappings.
        'role_permissions' => 'dominion_role_permissions',

        // Unified table containing global and tenant-scoped assignments.
        'assignments' => 'dominion_assignments',
    ],

    /*
    |--------------------------------------------------------------------------
    | Laravel Authorization Integration
    |--------------------------------------------------------------------------
    |
    | Gate integration controls the global Gate callback. Policy mappings use model
    | class names as keys and their application policy classes as values.
    |
    */

    'gate' => [
        // Register Dominion's callback with Laravel's authorization Gate.
        'enabled' => true,
    ],

    'policy' => [
        // Register the explicit model-to-policy mappings below.
        'enabled' => true,

        // Map application model classes to their corresponding policy classes.
        'models' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization Cache
    |--------------------------------------------------------------------------
    |
    | Final decisions use request memoization and optionally Laravel's cache.
    | Version tokens invalidate only Dominion decisions for a principal or
    | catalog and must live longer than the cached decisions themselves.
    |
    */

    'cache' => [
        // Cache resolved decisions in addition to request-level memoization.
        'enabled' => true,

        // Laravel cache store name; null selects the application's default store.
        'store' => null,

        // Number of seconds for which a resolved decision remains cached.
        'ttl' => 300,

        // Lifetime of invalidation tokens; this must exceed the decision TTL.
        'version_ttl' => 3600,

        // Namespace applied to every cache key created by Dominion.
        'prefix' => 'dominion',
    ],
];
