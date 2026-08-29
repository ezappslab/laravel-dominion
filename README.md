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

Add the combined trait to any Eloquent principal:

```php
use Infinity\Dominion\Traits\HasDominionAuthorization;

class User extends Authenticatable
{
    use HasDominionAuthorization;
}
```

The separate `HasRoles` and `HasPermissions` traits remain available when preferred.

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
4. Deny when the permission is known but unassigned
5. Abstain when the ability is outside the Dominion catalog

An explicit denial therefore always wins. Set `tenancy.global_inherits_into_tenant` to `false` to isolate tenant checks from global assignments.

## Gate and policies

The Gate integration is enabled by default:

```php
$user->can(Permission::UsersUpdate->value);
```

Abilities absent from the synchronized Dominion catalog produce an `abstain` decision, allowing other Laravel gates and policies to run. Set `gate.unknown_ability` to `deny` for authoritative behavior.

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

Mutations performed through Dominion APIs invalidate principal versions. `dominion:sync` invalidates the catalog version.

## Customization

The following services are replaceable through configuration:

- `TenantContext`
- `PermissionValueResolver`
- `RoleValueResolver`
- `AuthorizationCatalog`
- `AuthorizationResolver`
- Role and permission Eloquent models

Configured services are resolved through Laravel's container and may use constructor injection.

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
