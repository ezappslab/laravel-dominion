<?php

use Infinity\Dominion\Models\Permission;
use Infinity\Dominion\Models\Role;
use Infinity\Dominion\Policies\DefaultPolicy;
use Infinity\Dominion\Services\DefaultAuthorizationResolver;
use Infinity\Dominion\Services\DefaultPermissionValueResolver;
use Infinity\Dominion\Services\DefaultRoleValueResolver;
use Infinity\Dominion\Services\DefaultTenantContext;
use Infinity\Dominion\Services\EnumAuthorizationCatalog;

return [

    /*
    |--------------------------------------------------------------------------
    | Authorization Catalog
    |--------------------------------------------------------------------------
    |
    | Define the application enums that represent your roles and permissions.
    | Role mappings are synchronized to the database by `dominion:sync`; use
    | an asterisk to grant a role every configured permission.
    |
    */

    'catalog' => [
        // The enum class containing every role managed by Dominion.
        'role_enum' => null,

        // One or more enum classes containing application permissions.
        'permission_enums' => [],

        // Maps role values to permission values; `*` grants the full catalog.
        'role_permissions' => [],

        // Remove database catalog entries that no longer exist in the enums.
        'prune' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Assignment Profiles
    |--------------------------------------------------------------------------
    |
    | Profiles are reusable sets of roles, permissions, and denials that may
    | be applied when creating a principal or provisioning a tenant member.
    |
    */

    // Each profile may contain `roles`, `permissions`, and `denials` arrays.
    'profiles' => [],

    /*
    |--------------------------------------------------------------------------
    | Dominion Models
    |--------------------------------------------------------------------------
    |
    | You may replace the package models with application models. Custom
    | models must extend their corresponding Dominion model class.
    |
    */

    'models' => [
        // The Eloquent model used to persist synchronized roles.
        'role' => Role::class,

        // The Eloquent model used to persist synchronized permissions.
        'permission' => Permission::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenancy
    |--------------------------------------------------------------------------
    |
    | Global assignments are inherited by tenant scopes by default. Disable
    | this option when every tenant must have completely isolated access.
    |
    */

    'tenancy' => [
        // The morph type used when a scalar tenant identifier is supplied.
        'tenant_type' => 'tenant',

        // Makes global roles and grants available inside tenant scopes.
        'global_inherits_into_tenant' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Gate Integration
    |--------------------------------------------------------------------------
    |
    | Unknown abilities normally abstain so Laravel's other gates and policies
    | may continue. Set `unknown_ability` to `deny` for authoritative access.
    |
    */

    'gate' => [
        // Registers Dominion's callback with Laravel's authorization Gate.
        'enabled' => true,

        // Use `abstain` to continue to other gates or `deny` to stop evaluation.
        'unknown_ability' => 'abstain',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Policy
    |--------------------------------------------------------------------------
    |
    | The default policy converts model abilities to `{table}.{ability}`. List
    | models here or provide explicit model-to-policy mappings as needed.
    |
    */

    'policy' => [
        // Enables automatic registration of the configured model policies.
        'enabled' => true,

        // The policy used for numeric entries in the models array below.
        'class' => DefaultPolicy::class,

        // Models using the default policy or explicit model-to-policy mappings.
        'models' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization Cache
    |--------------------------------------------------------------------------
    |
    | Dominion caches computed decisions while the database remains the source
    | of truth. A null store uses the application's default cache store.
    |
    */

    'cache' => [
        // Enables caching of resolved authorization decisions.
        'enabled' => true,

        // A Laravel cache store name; null uses the application's default.
        'store' => null,

        // The number of seconds a computed decision remains cached.
        'ttl' => 300,

        // Prefixes Dominion keys to avoid collisions with application data.
        'prefix' => 'dominion',
    ],

    /*
    |--------------------------------------------------------------------------
    | Service Implementations
    |--------------------------------------------------------------------------
    |
    | These services are resolved through Laravel's container and may be
    | replaced by application implementations of the matching contracts.
    |
    */

    'services' => [
        // Resolves the global or tenant scope for the current execution context.
        'tenant_context' => DefaultTenantContext::class,

        // Normalizes enum, model, and scalar permission values.
        'permission_value_resolver' => DefaultPermissionValueResolver::class,

        // Normalizes enum, model, and scalar role values.
        'role_value_resolver' => DefaultRoleValueResolver::class,

        // Reads configured enums and builds the role-permission catalog.
        'authorization_catalog' => EnumAuthorizationCatalog::class,

        // Evaluates denials, direct grants, and role-derived permissions.
        'authorization_resolver' => DefaultAuthorizationResolver::class,
    ],
];
