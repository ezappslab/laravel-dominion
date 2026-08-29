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
