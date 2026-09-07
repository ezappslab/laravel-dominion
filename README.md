# Laravel Dominion

Laravel Dominion is an enum-driven authorization package for Laravel 12 and 13. It supports global and tenant-scoped roles, explicit permission allows and denies, database-materialized defaults, profiles, Gate and policy integration, query scopes, and cached decisions.

## Requirements

- PHP 8.4 or newer
- Laravel 12 or 13
- Backed string enums for roles and permissions

## Installation

```bash
composer require ezappslab/laravel-dominion
php artisan dominion:install
```

The install command publishes `config/dominion.php` and the Dominion migration. Review the migration, especially when principals or tenants use UUID or ULID keys, and then run:

```bash
php artisan migrate
```

Laravel package discovery registers `Infinity\Dominion\DominionServiceProvider` automatically.

## 1. Define roles and permissions

Create one backed role enum:

```php
<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Manager = 'manager';
    case Member = 'member';
}
```

Create one or more backed permission enums. Values must be unique across all configured permission enums:

```php
<?php

namespace App\Enums;

enum Permission: string
{
    case InvoicesView = 'invoices.view';
    case InvoicesCreate = 'invoices.create';
    case InvoicesUpdate = 'invoices.update';
    case InvoicesDelete = 'invoices.delete';
}
```

The `resource.action` naming convention works well with Dominion policies, but any non-empty string is valid.

## 2. Configure the catalog

Update `config/dominion.php`:

```php
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Team;

'enums' => [
    'role' => Role::class,
    'permissions' => [Permission::class],
],

'tenant' => [
    'model' => Team::class,
],

'roles' => [
    Role::Admin->value => ['*'],
    Role::Manager->value => [
        Permission::InvoicesView,
        Permission::InvoicesCreate,
        Permission::InvoicesUpdate,
    ],
    Role::Member->value => [Permission::InvoicesView],
],

'defaults' => [
    'allow' => [],
    'deny' => [Permission::InvoicesDelete],
],
```

`'*'` maps a role to every configured permission. Defaults are persisted on synchronized permission records. A permission cannot be both allowed and denied by default. If no assignment or default applies, Dominion always denies access.

### Configurable models and tables

Dominion uses these models by default:

```php
'models' => [
    'assignment' => Infinity\Dominion\Models\Assignment::class,
    'role' => Infinity\Dominion\Models\Role::class,
    'permission' => Infinity\Dominion\Models\Permission::class,
],

'tables' => [
    'roles' => 'dominion_roles',
    'permissions' => 'dominion_permissions',
    'role_permissions' => 'dominion_role_permissions',
    'assignments' => 'dominion_assignments',
],
```

Configure table names before publishing or running the migration. A custom model should extend the corresponding Dominion model.

## 3. Synchronize the database

```bash
php artisan dominion:sync
```

Run synchronization after changing role enums, permission enums, defaults, or role mappings.

```bash
php artisan dominion:sync --dry-run  # validate without writing
php artisan dominion:sync --prune    # remove catalog rows absent from config
php artisan dominion:doctor          # diagnose config and schema without writing
```

Pruning is deliberately explicit because assignments and role mappings reference catalog rows with foreign keys.

## 4. Prepare the principal model

```php
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Infinity\Dominion\Contracts\DominionPrincipal;
use Infinity\Dominion\Traits\HasAuthorization;

class User extends Authenticatable implements DominionPrincipal
{
    use HasAuthorization;
}
```

Principals and tenants must be persisted Eloquent models. Permanent principal deletion removes assignments. Soft deletion preserves them until force deletion.

## Facade usage

`Infinity\Dominion\Facades\Dominion` is the recommended entry point. Scope selection is always explicit:

```php
use App\Enums\Permission;
use App\Enums\Role;
use Infinity\Dominion\Facades\Dominion;

$global = Dominion::for($user)->globally();
$tenant = Dominion::for($user)->in($team);
```

This avoids implicit tenant state leaking between requests or worker jobs.

### Roles

```php
$global->grant(Role::Admin);
$tenant->grant(Role::Manager);

$tenant->hasRole(Role::Manager); // tenant roles plus inherited global roles

$tenant->revoke(Role::Manager);
$global->revoke(Role::Admin);
```

Revoking a tenant role does not revoke the same role globally.

### Direct permissions

```php
$tenant->allow(Permission::InvoicesUpdate);
$tenant->deny(Permission::InvoicesDelete);
$tenant->forget(Permission::InvoicesDelete);
```

At one scope, `allow()` and `deny()` replace each other. `forget()` removes the direct decision and lets roles or defaults decide.

### Check permissions

```php
$tenant->isAllowed(Permission::InvoicesUpdate);
$tenant->isDenied(Permission::InvoicesDelete);

$decision = $tenant->decision(Permission::InvoicesView);
$decisions = $tenant->checkMany(Permission::cases());
```

`decision()` returns `Infinity\Dominion\Domain\AuthorizationDecision` (`Allow` or `Deny`). `checkMany()` returns booleans keyed by normalized permission value. Unknown permissions are denied.

The facade resolves `Infinity\Dominion\Contracts\DominionManager`, which can be injected into application services.

## Permission precedence

Dominion evaluates the most specific applicable rule first:

1. Tenant direct permission
2. Global direct permission
3. Tenant role permission
4. Global role permission
5. Materialized permission default
6. Deny fallback

Consequently, a tenant direct allowance overrides a global direct denial, while a global direct denial overrides a tenant role permission.

## Profiles

Profiles are configuration recipes, not models:

```php
'profiles' => [
    'manager' => [
        'roles' => [Role::Manager],
        'allow' => [Permission::InvoicesCreate],
        'deny' => [Permission::InvoicesDelete],
    ],
],
```

```php
Dominion::for($user)->in($team)->applyProfile('manager');
```

Profile application is transactional. An invalid or unsynchronized entry rolls back every assignment in that profile operation.

## Trait helpers and relationships

`HasAuthorization` composes `Infinity\Dominion\Traits\HasRoles` and `Infinity\Dominion\Traits\HasPermissions`. Its model conveniences delegate to the facade:

```php
$user->grantRole(Role::Manager, $team);
$user->hasRole(Role::Manager, $team);
$user->revokeRole(Role::Manager, $team);

$user->allowPermission(Permission::InvoicesUpdate, $team);
$user->denyPermission(Permission::InvoicesDelete, $team);
$user->forgetPermission(Permission::InvoicesDelete, $team);
$user->hasPermission(Permission::InvoicesUpdate, $team);
$user->allowsPermission(Permission::InvoicesUpdate, $team);
$user->deniesPermission(Permission::InvoicesDelete, $team);
$user->checkPermissions(Permission::cases(), $team);
```

Omitting the tenant argument selects global scope. Relationships expose stored assignments across all scopes:

```php
$user->roles();
$user->permissions();
$user->allowedPermissions();
$user->deniedPermissions();
```

`permissions()` contains direct assignments only, not role-derived permissions. Pivot data includes `tenant_type`, `tenant_id`, `scope_key`, and `effect`.

## Query scopes

```php
use App\Models\User;

User::query()->withRole(Role::Admin)->get();
User::query()->withRole(Role::Manager, $team)->get();
User::query()->withPermission(Permission::InvoicesUpdate, $team)->get();
```

`withRole()` matches the exact selected scope. `withPermission()` applies the complete facade precedence through correlated Eloquent subqueries in one database query.

## Gate integration

```php
'gate' => ['enabled' => true],
```

```php
use Illuminate\Support\Facades\Gate;

Gate::forUser($user)->allows(Permission::InvoicesView->value);
$user->can(Permission::InvoicesView->value);
```

The Gate callback evaluates global scope. Use `Dominion::for($user)->in($team)` for tenant checks. Non-Dominion users are left to Laravel's other authorization handlers.

## Policy integration

```php
'policy' => [
    'enabled' => true,
    'models' => [
        App\Models\Invoice::class => App\Policies\InvoicePolicy::class,
    ],
],
```

Policies may extend `Infinity\Dominion\Policies\BasePolicy`:

```php
namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;
use Infinity\Dominion\Policies\BasePolicy;

class InvoicePolicy extends BasePolicy
{
    public function update(User $user, Invoice $invoice): bool
    {
        return $this->authorize($user, 'invoices', 'update');
    }
}
```

The protected `authorize()` helper checks a global `resource.action` permission. Passing a model as the resource uses its table name. Use the facade directly in tenant-aware policy methods.

## Assignment model

`Infinity\Dominion\Models\Assignment` provides:

- `principal()`, `tenant()`, `role()`, and `permission()` relationships
- `forPrincipal($principal)`, `globally()`, and `forTenant($tenant)` scopes
- `roles()`, `permissions()`, and `withEffect($effect)` scopes
- `EFFECT_GRANT`, `EFFECT_ALLOW`, and `EFFECT_DENY` constants

Always mutate authorization through the facade so invariants and cache invalidation are preserved.

## Caching

```php
'cache' => [
    'enabled' => true,
    'store' => null,
    'ttl' => 300,
    'version_ttl' => 3600,
    'prefix' => 'dominion',
],
```

Dominion memoizes decisions for the shared authorization service lifetime. Optional persistent caching uses Laravel's configured cache store. Assignment changes rotate a principal version; synchronization rotates the catalog version. This invalidates Dominion decisions without flushing unrelated cache data.

`version_ttl` must exceed `ttl`. Avoid direct assignment writes unless you also manage invalidation.

## Deployment

```bash
php artisan migrate --force
php artisan dominion:doctor
php artisan dominion:sync
```

## Development

```bash
composer test
composer lint
```
