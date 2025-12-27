# Laravel Dominion

Laravel Dominion is a comprehensive authorization package for Laravel that provides tenant-aware, polymorphic roles and permissions. It is designed for applications that require fine-grained access control across multiple tenants while maintaining a clean, developer-friendly API.

Dominion solves the complexity of managing permissions in multi-tenant environments by providing explicit allow/deny rules, deterministic precedence, and seamless integration with Laravel's native authorization system.

### Key Use Cases
- **Multi-tenant RBAC**: Manage roles and permissions that can be either global or scoped to specific tenants.
- **Polymorphic Authorization**: Assign roles and permissions to any Eloquent model (e.g., Users, API Clients, Teams).
- **Tenant-Aware Gate & Policies**: Automatically resolve the current tenant context for authorization checks.
- **Catalog Syncing**: Keep your database-backed roles and permissions in sync with your codebase using PHP Enums.

---

## Core Concepts

Dominion is built around several key concepts that work together to provide a robust authorization engine:

- **Domains & Tenancy**: Authorization can be **global** (applies everywhere) or **tenant-scoped** (applies only within a specific tenant).
- **Roles**: Logical groupings of permissions (e.g., `admin`, `editor`).
- **Permissions**: Granular abilities represented by strings (e.g., `posts.update`).
- **Policies**: Dominion integrates with Laravel's policy system to map model actions to permission strings.
- **Services**: Configurable components that handle tenant resolution and value normalization.
- **Allow / Deny**: Explicitly grant or block permissions for a specific principal. **Deny** always takes precedence over **Allow**.

### Precedence Rules
When checking if a principal has a permission for a specific tenant, Dominion follows these deterministic rules:
1. **Tenant Deny**: Explicitly denied for the principal in the current tenant.
2. **Global Deny**: Explicitly denied for the principal globally.
3. **Tenant Allow**: Explicitly allowed for the principal in the current tenant.
4. **Global Allow**: Explicitly allowed for the principal globally.
5. **Role-based Permission**: Granted via an assigned role (either tenant-scoped or global).
6. **Default Deny**: If no rules match, access is denied.

---

## Architecture

Dominion is structured into several layers to ensure flexibility and maintainability:

- **Traits**: Provide the public API (`HasRoles`, `HasPermissions`) for your Eloquent models.
- **Authorization Engine**: The core logic (`AuthorizationResolver`) that evaluates the precedence rules.
- **Contracts**: Replaceable interfaces (`TenantContext`, `PermissionValueResolver`, `RoleValueResolver`) that allow you to customize core behavior.
- **Persistence**: Database tables for roles, permissions, and their polymorphic assignments (`roleables`, `permissionables`).
- **Gate Integration**: Hooks into Laravel's `Gate::before` to provide seamless `$user->can()` checks.

### Authorization Flow
1. **Gate Check**: When `$user->can('posts.update')` is called, Dominion's `Gate::before` hook intercepts the call.
2. **Tenant Resolution**: The `TenantContext` service identifies the current tenant ID.
3. **Resolution**: The `AuthorizationResolver` evaluates the precedence rules against the database and returns a boolean.

---

## Installation

### Requirements
- PHP 8.2+
- Laravel 11 or 12

### Steps
1. **Install via Composer**:
   ```bash
   composer require ezappslab/laravel-dominion
   ```

2. **Run the Installer**:
   This command publishes the configuration file (`config/dominion.php`).
   ```bash
   php artisan dominion:install
   ```

3. **Run Migrations**:
   ```bash
   php artisan migrate
   ```

---

## Configuration

The `config/dominion.php` file allows you to customize almost every aspect of the package.

### Tenancy Configuration
Define your tenant table and foreign key:
```php
'tenant' => [
    'table' => 'tenants',
    'foreign_key' => 'tenant_id',
],
```

### Service Overrides
You can swap default implementations with your own by updating the `services` array:
```php
'services' => [
    'tenant_context' => App\Services\CustomTenantContext::class,
    'permission_value_resolver' => Infinity\Dominion\Services\DefaultPermissionValueResolver::class,
    'role_value_resolver' => Infinity\Dominion\Services\DefaultRoleValueResolver::class,
],
```

---

## Roles & Permissions

To get started, add the `HasRoles` and `HasPermissions` traits to your model (e.g., `User` model).

### Assigning Roles
```php
$user->addRole('admin');          // Global role
$user->addRole('member', $tenant); // Tenant-scoped role

$user->removeRole('admin');
```

### Managing Permissions
```php
$user->allow('posts.update');          // Global allow
$user->allow('posts.update', $tenant); // Tenant-scoped allow

$user->deny('posts.delete');           // Global deny
```

### Checking Access
```php
$user->hasRole('admin');
$user->hasPermission('posts.update');

// Native Laravel Gate integration
$user->can('posts.update');
```

---

## Policies

Dominion ships with a `DefaultPolicy` that maps Laravel's policy methods to permission strings using the convention `{table}.{ability}`.

### Enabling the Default Policy
Register your models in `config/dominion.php`:
```php
'policy' => [
    'class' => Infinity\Dominion\Policies\DefaultPolicy::class,
    'models' => [
        App\Models\Post::class, // maps update() -> posts.update
    ],
],
```

### Custom Policy Mapping
You can also map specific models to your own policy classes:
```php
'models' => [
    App\Models\Invoice::class => App\Policies\InvoicePolicy::class,
],
```

---

## Enums

Dominion encourages the use of string-backed PHP Enums for type-safe roles and permissions.

### Defining Enums
```php
enum Role: string {
    case Admin = 'admin';
}

enum PostPermissions: string {
    case Update = 'posts.update';
}
```

### Usage
```php
$user->addRole(Role::Admin);
$user->allow(PostPermissions::Update);
```

---

## Services

Dominion relies on internal services that you can extend or replace:

- **`TenantContext`**: Responsible for determining the current tenant ID during authorization checks.
- **`PermissionValueResolver`**: Normalizes permission inputs (strings or enums) into a consistent format.
- **`RoleValueResolver`**: Normalizes role inputs into a consistent format.

To implement a custom service, implement the corresponding contract in `Infinity\Dominion\Contracts` and update your config.

---

## Tenancy Support

Dominion is "tenancy-aware" by design. Every role assignment and permission grant can be associated with a `tenant_id`.

### Resolution Flow
When an authorization check is performed without an explicit tenant, Dominion calls `TenantContext::currentTenantId()`. This allows you to resolve the tenant from the session, a route parameter, or a header.

### Tenant Argument
Most methods accept an optional `$tenant` argument, which can be:
- An Eloquent model instance.
- A primary key (`int` or `string`).
- `null` for global scope.

---

## Sync Command

The `dominion:sync` command allows you to synchronize your PHP Enums with the database catalogs.

### Configuration
Register your enums in `config/dominion.php`:
```php
'role_enum' => App\Enums\Role::class,
'permission_enums' => [
    App\Enums\PostPermissions::class,
],
```

### Usage
```bash
# Basic sync
php artisan dominion:sync

# Preview changes
php artisan dominion:sync --dry-run

# Remove records no longer in enums
php artisan dominion:sync --prune
```

---

## Testing & CI

When testing applications using Dominion:
- **Seed Permissions**: Use `php artisan dominion:sync` in your test setup to ensure the catalogs are populated.
- **Tenant Context**: If testing tenant-aware logic, ensure your `TenantContext` service is properly mocked or configured for the test environment.
- **Cache**: Dominion caches authorization results. Use `php artisan cache:clear` if you are manually modifying database records during tests.

---

## Design Principles

- **Explicitness**: Precedence rules are clear and deterministic. Deny always wins.
- **Safety**: Using Enums prevents typos and ensures a single source of truth for your permission set.
- **Predictability**: Dominion honors Laravel's native authorization patterns while adding multi-tenancy.
- **Laravel-Native**: Built to feel like a natural extension of the Laravel framework.

---

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
