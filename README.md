# Laravel Dominion

Laravel Dominion is an enum-driven, tenant-aware authorization package for Laravel 12 and 13. Application enums define the catalog, while the database stores synchronized roles, permissions, defaults, and assignments.

## Installation

```bash
composer require ezappslab/laravel-dominion
php artisan dominion:install
php artisan migrate
```

Review the published migration before running it when principals or tenants use UUID or ULID keys.

## Configure the catalog

Define backed enums in the application:

```php
enum Role: string
{
    case Admin = 'admin';
    case Manager = 'manager';
}

enum Permission: string
{
    case InvoicesView = 'invoices.view';
    case InvoicesUpdate = 'invoices.update';
    case InvoicesDelete = 'invoices.delete';
}
```

Configure `config/dominion.php`:

```php
'enums' => [
    'role' => App\Enums\Role::class,
    'permissions' => [App\Enums\Permission::class],
],
'tenant' => ['model' => App\Models\Team::class],
'roles' => [
    Role::Admin->value => ['*'],
    Role::Manager->value => [Permission::InvoicesView, Permission::InvoicesUpdate],
],
'defaults' => [
    'allow' => [Permission::InvoicesView],
    'deny' => [Permission::InvoicesDelete],
],
```

Defaults are materialized in the permission catalog by synchronization. An unknown permission, or one without any applicable assignment or default, is always denied.

```bash
php artisan dominion:sync
php artisan dominion:sync --dry-run
php artisan dominion:sync --prune
php artisan dominion:doctor
```

`dominion:doctor` is read-only. It validates the configuration and schema. `dominion:sync` performs bulk catalog synchronization; pruning must be explicitly requested.

## Principal setup

```php
use Infinity\Dominion\Contracts\DominionPrincipal;
use Infinity\Dominion\Traits\HasAuthorization;

class User extends Authenticatable implements DominionPrincipal
{
    use HasAuthorization;
}
```

## Facade API

The regular way to use Dominion is its facade. A scope is always explicit, preventing accidental tenant leakage in long-running workers.

```php
use Infinity\Dominion\Facades\Dominion;

Dominion::for($user)->globally()->grant(Role::Admin);
Dominion::for($user)->globally()->revoke(Role::Admin);

Dominion::for($user)->in($team)->grant(Role::Manager);
Dominion::for($user)->in($team)->allow(Permission::InvoicesUpdate);
Dominion::for($user)->in($team)->deny(Permission::InvoicesDelete);
Dominion::for($user)->in($team)->forget(Permission::InvoicesDelete);

Dominion::for($user)->in($team)->hasRole(Role::Manager);
Dominion::for($user)->in($team)->isAllowed(Permission::InvoicesUpdate);
Dominion::for($user)->in($team)->isDenied(Permission::InvoicesDelete);
```

Resolve several abilities together for menus and dashboards:

```php
$decisions = Dominion::for($user)->in($team)->checkMany([
    Permission::InvoicesView,
    Permission::InvoicesUpdate,
    Permission::InvoicesDelete,
]);
```

The facade resolves an injectable `Infinity\Dominion\Contracts\DominionManager` contract. Application services may type-hint that contract instead of using the facade.

## Specificity and precedence

Dominion evaluates the most specific applicable decision first:

1. Tenant direct permission
2. Global direct permission
3. Tenant role permission
4. Global role permission
5. Materialized permission default
6. Deny fallback

At one scope, `allow()` and `deny()` replace each other atomically. A tenant allowance can therefore override a global denial, while a global direct decision remains more specific than an inherited role permission.

## Profiles

Profiles are configuration recipes, not models:

```php
'profiles' => [
    'manager' => [
        'roles' => [Role::Manager],
        'allow' => [Permission::InvoicesUpdate],
        'deny' => [Permission::InvoicesDelete],
    ],
],
```

Apply all profile assignments in one transaction:

```php
Dominion::for($user)->in($team)->applyProfile('manager');
```

## Query scopes, Gate, and policies

`HasAuthorization` provides query scopes:

```php
User::withRole(Role::Manager, $team)->get();
User::withPermission(Permission::InvoicesUpdate, $team)->get();
```

It also composes the complete `HasRoles` and `HasPermissions` model concerns: the
`roles()`, `permissions()`, `allowedPermissions()`, and `deniedPermissions()`
relationships; `withRole()` and `withPermission()` scopes; and explicit helpers
such as `hasRole()`, `grantRole()`, `revokeRole()`, `hasPermission()`,
`allowsPermission()`, `deniesPermission()`, `allowPermission()`,
`denyPermission()`, `forgetPermission()`, and `checkPermissions()`. Their optional
tenant argument selects tenant scope; omitting it selects global scope. These are
conveniences over the same facade implementation—the facade API remains the
recommended application entry point.

With Gate integration enabled, principals implementing `DominionPrincipal` can use Laravel authorization helpers. Unrecognized abilities are denied:

```php
Gate::forUser($user)->allows(Permission::InvoicesView->value);
```

Configure explicit model-to-policy mappings under `policy.models`. Custom policies can extend `Infinity\Dominion\Policies\BasePolicy` and delegate resource actions to Dominion.

## Storage and caching

Dominion uses one indexed assignment table for global and tenant role grants and direct permission effects. Permission and role foreign keys preserve catalog integrity, while a stable assignment key makes mutations idempotent.

Assignments are represented by `Infinity\Dominion\Models\Assignment`. Applications
may extend it and configure `models.assignment`, just like the role and permission
models. It exposes `principal`, `tenant`, `role`, and `permission` relationships,
plus scopes for principals, global or tenant context, assignment kind, and effect.

Computed decisions use request memoization and optional persistent caching. Cache keys contain principal and catalog version tokens, so mutations invalidate decisions without flushing unrelated application cache entries. Always mutate authorization through the facade.

Permanently deleting a principal removes its assignments. Soft deletion preserves them for restoration.

## Development

```bash
composer test
composer lint
```
