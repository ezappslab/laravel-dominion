<?php

namespace Tests\Feature;

use Infinity\Dominion\Models\Permission;
use Infinity\Dominion\Models\Role;
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
