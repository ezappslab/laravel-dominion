<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Infinity\Dominion\Contracts\AuthorizationCatalog;
use Infinity\Dominion\Exceptions\InvalidCatalogConfiguration;
use Infinity\Dominion\Models\Permission;
use Infinity\Dominion\Models\Role;
use Tests\Support\TestDuplicatePermission;
use Tests\Support\TestPermission;
use Tests\Support\TestPermissionOther;
use Tests\Support\TestRole;

beforeEach(function (): void {
    config([
        'dominion.catalog.role_enum' => TestRole::class,
        'dominion.catalog.permission_enums' => [TestPermission::class, TestPermissionOther::class],
        'dominion.catalog.role_permissions' => [
            TestRole::ADMIN->name => ['*'],
            TestRole::EDITOR->name => [TestPermission::UPDATE],
        ],
    ]);
});

it('synchronizes enum catalogs and role mappings', function (): void {
    $this->artisan('dominion:sync')
        ->assertSuccessful()
        ->expectsOutput('Dominion catalog synchronized.');

    expect(Role::pluck('name')->all())->toEqualCanonicalizing(['ADMIN', 'EDITOR'])
        ->and(Permission::pluck('name')->all())->toEqualCanonicalizing([
            'posts.create', 'posts.update', 'others.delete', 'others.view',
        ])
        ->and(Role::where('name', 'ADMIN')->firstOrFail()->permissions)->toHaveCount(4)
        ->and(Role::where('name', 'EDITOR')->firstOrFail()->permissions->pluck('name')->all())
        ->toBe(['posts.update']);
});

it('builds a consistent validated catalog snapshot', function (): void {
    $snapshot = app(AuthorizationCatalog::class)->snapshot();

    expect($snapshot->roles)->toBe(['ADMIN', 'EDITOR'])
        ->and($snapshot->permissions)->toBe([
            'posts.create', 'posts.update', 'others.delete', 'others.view',
        ])
        ->and($snapshot->rolePermissions)->toBe([
            'ADMIN' => ['posts.create', 'posts.update', 'others.delete', 'others.view'],
            'EDITOR' => ['posts.update'],
        ]);
});

it('resolves persisted catalog models by name', function (): void {
    $role = Role::create(['name' => 'OWNER']);
    $permission = Permission::create(['name' => 'posts.publish']);
    $catalog = app(AuthorizationCatalog::class);

    expect($catalog->resolveRole($role))->toBe('OWNER')
        ->and($catalog->resolvePermission($permission))->toBe('posts.publish');
});

it('reports a dry run without changing the catalog', function (): void {
    $this->artisan('dominion:sync --dry-run')
        ->assertSuccessful()
        ->expectsOutput('Would synchronize 2 roles, 4 permissions, and 2 role mappings.');

    expect(Role::count())->toBe(0)
        ->and(Permission::count())->toBe(0);
});

it('prunes catalog records absent from enums', function (): void {
    Role::create(['name' => 'obsolete']);
    Permission::create(['name' => 'obsolete.permission']);

    $this->artisan('dominion:sync --prune')->assertSuccessful();

    expect(Role::where('name', 'obsolete')->exists())->toBeFalse()
        ->and(Permission::where('name', 'obsolete.permission')->exists())->toBeFalse();
});

it('prunes every catalog record when the configured catalog is empty', function (): void {
    Role::create(['name' => 'obsolete']);
    Permission::create(['name' => 'obsolete.permission']);
    config([
        'dominion.catalog.role_enum' => null,
        'dominion.catalog.permission_enums' => [],
        'dominion.catalog.role_permissions' => [],
    ]);

    $this->artisan('dominion:sync --prune')
        ->assertSuccessful()
        ->expectsOutput('Synchronizing 0 roles, 0 permissions, and 0 role mappings.')
        ->expectsOutput('Dominion catalog synchronized.');

    expect(Role::count())->toBe(0)
        ->and(Permission::count())->toBe(0);
});

it('reports an empty catalog prune during a dry run without deleting records', function (): void {
    Role::create(['name' => 'obsolete']);
    Permission::create(['name' => 'obsolete.permission']);
    config([
        'dominion.catalog.role_enum' => null,
        'dominion.catalog.permission_enums' => [],
        'dominion.catalog.role_permissions' => [],
    ]);

    $this->artisan('dominion:sync --prune --dry-run')
        ->assertSuccessful()
        ->expectsOutput('Would synchronize 0 roles, 0 permissions, and 0 role mappings.');

    expect(Role::count())->toBe(1)
        ->and(Permission::count())->toBe(1);
});

it('prunes an empty catalog when pruning is enabled in configuration', function (): void {
    Role::create(['name' => 'obsolete']);
    Permission::create(['name' => 'obsolete.permission']);
    config([
        'dominion.catalog.role_enum' => null,
        'dominion.catalog.permission_enums' => [],
        'dominion.catalog.role_permissions' => [],
        'dominion.catalog.prune' => true,
    ]);

    $this->artisan('dominion:sync')->assertSuccessful();

    expect(Role::count())->toBe(0)
        ->and(Permission::count())->toBe(0);
});

it('bulk synchronization is idempotent', function (): void {
    $this->artisan('dominion:sync')->assertSuccessful();
    $this->artisan('dominion:sync')->assertSuccessful();

    expect(Role::count())->toBe(2)
        ->and(Permission::count())->toBe(4)
        ->and(DB::table('permission_role')->count())->toBe(5);
});

it('clears stale permissions when a configured role mapping is removed', function (): void {
    $this->artisan('dominion:sync')->assertSuccessful();
    config(['dominion.catalog.role_permissions' => [
        TestRole::ADMIN->name => ['*'],
    ]]);

    $this->artisan('dominion:sync')->assertSuccessful();

    expect(Role::where('name', 'EDITOR')->firstOrFail()->permissions)->toBeEmpty()
        ->and(Role::where('name', 'ADMIN')->firstOrFail()->permissions)->toHaveCount(4);
});

it('rejects invalid permission enum classes', function (): void {
    config(['dominion.catalog.permission_enums' => ['App\\Enums\\MissingPermission']]);

    expect(fn () => app(AuthorizationCatalog::class)->permissions())
        ->toThrow(InvalidCatalogConfiguration::class, '[App\\Enums\\MissingPermission] is not an enum class.');
});

it('rejects malformed catalog configuration', function (string $key, mixed $value, string $message): void {
    config(["dominion.catalog.{$key}" => $value]);

    $catalog = app(AuthorizationCatalog::class);
    $operation = match ($key) {
        'permission_enums' => fn () => $catalog->permissions(),
        'role_enum' => fn () => $catalog->roles(),
        default => fn () => $catalog->rolePermissions(),
    };

    expect($operation)->toThrow(InvalidCatalogConfiguration::class, $message);
})->with([
    'permission enums must be an array' => ['permission_enums', TestPermission::class, 'value must be an array'],
    'role enum must be a class name' => ['role_enum', 42, 'value must be an enum class name or null'],
    'role map must be an array' => ['role_permissions', 'ADMIN', 'value must be an array keyed by role name'],
    'role permissions must be an array' => ['role_permissions', ['ADMIN' => 'posts.create'], 'permissions for role [ADMIN] must be an array'],
]);

it('rejects duplicate permission values across enums', function (): void {
    config(['dominion.catalog.permission_enums' => [TestPermission::class, TestDuplicatePermission::class]]);

    expect(fn () => app(AuthorizationCatalog::class)->permissions())
        ->toThrow(InvalidCatalogConfiguration::class, 'permission or role value [posts.create] is declared more than once.');
});

it('rejects roles absent from the configured enum', function (): void {
    config(['dominion.catalog.role_permissions' => ['OWNER' => [TestPermission::CREATE]]]);

    expect(fn () => app(AuthorizationCatalog::class)->rolePermissions())
        ->toThrow(InvalidCatalogConfiguration::class, 'role [OWNER] is not declared by the configured role enum.');
});

it('rejects permissions absent from the configured enums', function (): void {
    config(['dominion.catalog.role_permissions' => [TestRole::EDITOR->name => ['posts.delete']]]);

    expect(fn () => app(AuthorizationCatalog::class)->rolePermissions())
        ->toThrow(InvalidCatalogConfiguration::class, 'permission [posts.delete] for role [EDITOR] is not declared');
});

it('rejects wildcard mappings combined with explicit permissions', function (): void {
    config(['dominion.catalog.role_permissions' => [TestRole::ADMIN->name => ['*', TestPermission::CREATE]]]);

    expect(fn () => app(AuthorizationCatalog::class)->rolePermissions())
        ->toThrow(InvalidCatalogConfiguration::class, 'wildcard for role [ADMIN] must be the only permission value.');
});

it('rejects duplicate permissions within a role mapping', function (): void {
    config(['dominion.catalog.role_permissions' => [
        TestRole::EDITOR->name => [TestPermission::CREATE, TestPermission::CREATE],
    ]]);

    expect(fn () => app(AuthorizationCatalog::class)->rolePermissions())
        ->toThrow(InvalidCatalogConfiguration::class, 'role [EDITOR] contains duplicate permissions.');
});
