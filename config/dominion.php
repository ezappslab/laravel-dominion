<?php

use Infinity\Dominion\Models\Permission;
use Infinity\Dominion\Models\Role;
use Infinity\Dominion\Policies\DefaultPolicy;
use Infinity\Dominion\Services\DefaultAuthorizationResolver;
use Infinity\Dominion\Services\DefaultPermissionValueResolver;
use Infinity\Dominion\Services\DefaultRoleValueResolver;
use Infinity\Dominion\Services\DefaultTenantContext;

return [

    /*
    |--------------------------------------------------------------------------
    | Role Enum
    |--------------------------------------------------------------------------
    |
    | The fully qualified class name of the enum that defines your roles.
    | When set, it will be used for role validation and authorization.
    |
    | Default: null
    */

    'role_enum' => null,

    /*
    |--------------------------------------------------------------------------
    | Permission Enums
    |--------------------------------------------------------------------------
    |
    | An array of fully qualified class names of the enums that define your
    | permissions. You can group permissions into multiple enums.
    |
    | Default: []
    */

    'permission_enums' => [],

    /*
    |--------------------------------------------------------------------------
    | Permission Enum Discovery
    |--------------------------------------------------------------------------
    |
    | If enabled, the package will automatically discover permission enums
    | in your application.
    |
    */

    'permission_enum_discovery' => [
        'enabled' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant Configuration
    |--------------------------------------------------------------------------
    |
    | Here you can configure how the package handles multi-tenancy.
    |
    | 'table': The name of the tenants table.
    | 'foreign_key': The column name used for tenant identification.
    */

    'tenant' => [
        'table' => 'tenants',
        'foreign_key' => 'tenant_id',
    ],

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | You can override the default models used by the package.
    | If set to null, the package will use its internal default models.
    */

    'models' => [
        'role' => Role::class,
        'permission' => Permission::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Settings
    |--------------------------------------------------------------------------
    |
    | Configure how the package caches permissions and roles.
    |
    | 'enabled': Whether to use caching.
    | 'store': The cache store to use (null uses default).
    | 'ttl': Time-to-live for cached items in seconds.
    | 'prefix': Prefix for cache keys.
    */

    'cache' => [
        'enabled' => true,
        'store' => null,
        'ttl' => 300,
        'prefix' => 'dominion',
    ],

    /*
    |--------------------------------------------------------------------------
    | Gate Integration
    |--------------------------------------------------------------------------
    |
    | If enabled, the package will automatically register its permissions
    | with the Laravel Gate.
    */

    'gate' => [
        'enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Policies
    |--------------------------------------------------------------------------
    |
    | Configure the default policy and which models it should apply to.
    |
    */

    'policy' => [
        'class' => DefaultPolicy::class,
        'models' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Core Services
    |--------------------------------------------------------------------------
    |
    | The following classes are used for core functionality.
    | You can override them with your own implementations.
    */

    'services' => [
        'tenant_context' => DefaultTenantContext::class,
        'permission_value_resolver' => DefaultPermissionValueResolver::class,
        'role_value_resolver' => DefaultRoleValueResolver::class,
        'authorization_resolver' => DefaultAuthorizationResolver::class,
    ],

];
