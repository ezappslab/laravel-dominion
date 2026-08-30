# Laravel Dominion

Laravel Dominion provides enum-driven, tenant-aware roles and permissions for Laravel applications. Application enums define the authorization catalog, the database stores assignments, and an optional versioned cache accelerates decisions.

## Requirements

- PHP 8.4+
- Laravel 12 or 13

## Installation

```bash
composer require ezappslab/laravel-dominion
php artisan dominion:install
php artisan migrate
```

Implement the marker contract and add the combined trait to any Eloquent principal:

```php
use Infinity\Dominion\Contracts\DominionPrincipal;
use Infinity\Dominion\Traits\HasDominionAuthorization;

class User extends Authenticatable implements DominionPrincipal
{
    use HasDominionAuthorization;
}
```

The `DominionPrincipal` contract opts the model into Dominion's global Gate callback. The separate `HasRoles` and `HasPermissions` traits remain available when preferred, but their model must also implement this contract for Gate integration.

## Define the catalog

Create application-level enums:

```php
enum Role: string
{
    case Admin = 'admin';
    case Manager = 'manager';
    case Member = 'member';
}

enum Permission: string
{
    case UsersView = 'users.view';
    case UsersCreate = 'users.create';
    case UsersUpdate = 'users.update';
}
```

Configure them in `config/dominion.php`:

```php
'catalog' => [
    'role_enum' => App\Enums\Role::class,
    'permission_enums' => [
        App\Enums\Permission::class,
    ],
    'role_permissions' => [
        App\Enums\Role::Admin->value => ['*'],
        App\Enums\Role::Manager->value => [
            App\Enums\Permission::UsersView,
            App\Enums\Permission::UsersUpdate,
        ],
    ],
],
```

Synchronize the catalog and role map:

```bash
php artisan dominion:sync
php artisan dominion:sync --dry-run
php artisan dominion:sync --prune
```

`'*'` expands to every configured permission during synchronization.

## Assign authorization

```php
use Infinity\Dominion\Domain\AuthorizationScope;

$user->assignRole(Role::Manager, tenant: $tenant);
$user->removeRole(Role::Manager, tenant: $tenant);

$user->grantPermission(Permission::UsersUpdate, tenant: $tenant);
$user->denyPermission(Permission::UsersCreate, tenant: $tenant);
$user->revokePermission(Permission::UsersCreate, tenant: $tenant);

$user->hasRole(Role::Manager, tenant: $tenant);
$user->hasPermission(Permission::UsersUpdate, tenant: $tenant);

$user->assignRole(Role::Admin, AuthorizationScope::global());
```

Assignments are idempotent. A repeated assignment updates its timestamp rather than inserting a duplicate.

Deleting a principal permanently removes all of its role, grant, and denial assignments. Soft deletion preserves assignments so authorization state is available after restoration; force deletion removes them.

### Mutating assignments

Always mutate authorization state through Dominion's assignment API:

```php
$user->assignRole($role, tenant: $tenant);
$user->removeRole($role, tenant: $tenant);
$user->grantPermission($permission, tenant: $tenant);
$user->denyPermission($permission, tenant: $tenant);
$user->revokePermission($permission, tenant: $tenant);
$user->assignAuthorizationProfile('member', tenant: $tenant);
```

The `roles()`, `permissions()`, and `deniedPermissions()` relationships are available for querying authorization data. Do not call relationship mutation methods such as `attach`, `detach`, `sync`, `updateExistingPivot`, or write directly to Dominion tables. Those writes bypass Dominion's transactions, grant/denial conflict handling, domain events, and cache invalidation, which can leave authorization decisions stale or inconsistent.

Passing `null` resolves the configured current tenant. Use `AuthorizationScope::global()` to request global scope explicitly. Global grants and roles inherit into tenant scopes by default.

## Assignment profiles

Define reusable maps for user creation workflows:

```php
'profiles' => [
    'member' => [
        'roles' => [Role::Member],
        'permissions' => [],
        'denials' => [],
    ],
],
```

Apply a profile inside the application's transaction:

```php
DB::transaction(function () use ($attributes, $tenant) {
    $user = User::create($attributes);
    $user->assignAuthorizationProfile('member', tenant: $tenant);
});
```

This explicit workflow is the default. Applications may call it from their own user-created listener when automatic assignment is desired.

## Decision precedence

For a tenant-scoped request Dominion evaluates:

1. Tenant or global direct denial
2. Tenant or global direct grant
3. Tenant or global role permission
4. Deny when no explicit allow applies, including unknown abilities

An explicit denial therefore always wins. Set `tenancy.global_inherits_into_tenant` to `false` to isolate tenant checks from global assignments.

## Gate and policies

The Gate integration is enabled by default:

```php
$user->can(Permission::UsersUpdate->value);
```

Dominion is authoritative only for models implementing `DominionPrincipal`. For those models, every ability that does not resolve to an explicit allow is denied. Other authenticated model types bypass Dominion and continue through Laravel's gates and policies.

Register the default resource policy:

```php
'policy' => [
    'enabled' => true,
    'class' => Infinity\Dominion\Policies\DefaultPolicy::class,
    'models' => [
        App\Models\Post::class,
    ],
],
```

The policy maps Laravel abilities to `{table}.{ability}`, such as `posts.update`. Associative model-to-policy mappings are also accepted.

## Tenant context

Implement the tenant context contract:

```php
use Infinity\Dominion\Contracts\TenantContext;
use Infinity\Dominion\Domain\AuthorizationScope;

class CurrentTenantContext implements TenantContext
{
    public function currentScope(): AuthorizationScope
    {
        return tenant()
            ? AuthorizationScope::tenant(tenant())
            : AuthorizationScope::global();
    }
}
```

Configure it under `services.tenant_context`. Tenant models, integer keys, string keys, UUIDs, and ULIDs are supported.

## Cache behavior

The database is always the assignment source of truth. Cache stores computed decisions only. Principal and catalog version numbers are embedded in cache keys, so invalidation works across taggable and non-taggable Laravel stores without flushing unrelated application cache entries.

When `cache.enabled` is `false`, Dominion does not resolve a cache store or validate cache lifetime settings; authorization continues directly against the database.

Mutations performed through Dominion's assignment APIs invalidate principal versions. `Role::syncPermissions()` and `dominion:sync` invalidate the catalog version. Direct relationship or table writes are unsupported and do not trigger invalidation.

## Customization

The following services are replaceable through configuration:

- `TenantContext`
- `PermissionValueResolver`
- `RoleValueResolver`
- `AuthorizationCatalog`
- `AuthorizationResolver`
- Role and permission Eloquent models

Configured services are resolved through Laravel's container and may use constructor injection.

Every Dominion table name can be changed under the `tables` configuration key. Configure table names before publishing and running the package migration; models, relationships, synchronization, assignment cleanup, and authorization queries all use these values.

Dominion keeps application boot validation lightweight. Configured service classes and enabled policy mappings are validated during boot. Profiles are validated when applied, table names when the schema or models use them, cache settings when caching is resolved, and the complete enum catalog when `dominion:sync` runs. This avoids loading unused subsystems during ordinary requests while preserving validation at each execution boundary.

## Development

Run the behavioral test suite:

```bash
composer test
```

Run formatting, static analysis, and Rector's dry run:

```bash
composer lint
```

The suite exercises enum synchronization, scope precedence, profiles, caching, Gate fall-through, policies, migrations, and compatibility aliases.

Release CI should exercise every supported PHP and Laravel combination and run SQLite, MySQL, and PostgreSQL integration jobs because assignment indexes and foreign keys are database-sensitive.
