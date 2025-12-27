<?php

namespace Tests\Feature;

use Infinity\Dominion\Models\Permission;
use Infinity\Dominion\Models\Role;
use Tests\Support\TestPermission;
use Tests\Support\TestPermissionOther;
use Tests\Support\TestRole;

it('syncs roles from enum', function () {
    config(['dominion.role_enum' => TestRole::class]);

    $this->artisan('dominion:sync')
        ->assertExitCode(0)
        ->expectsOutput('Syncing roles...')
        ->expectsOutput('Dominion sync completed.');

    expect(Role::where('name', 'ADMIN')->exists())
        ->toBeTrue()
        ->and(Role::where('name', 'EDITOR')->exists())
        ->toBeTrue();
});

it('syncs permissions from multiple enums', function () {
    config(['dominion.permission_enums' => [
        TestPermission::class,
        TestPermissionOther::class,
    ]]);

    $this->artisan('dominion:sync')
        ->assertExitCode(0)
        ->expectsOutput('Syncing permissions...')
        ->expectsOutput('Dominion sync completed.');

    expect(Permission::where('name', 'posts.create')->exists())
        ->toBeTrue()
        ->and(Permission::where('name', 'posts.update')->exists())
        ->toBeTrue()
        ->and(Permission::where('name', 'others.delete')->exists())
        ->toBeTrue()
        ->and(Permission::where('name', 'others.view')->exists())
        ->toBeTrue();
});

it('does not make changes in dry-run mode', function () {
    config(['dominion.role_enum' => TestRole::class]);
    config(['dominion.permission_enums' => [TestPermission::class]]);

    $this->artisan('dominion:sync --dry-run')
        ->assertExitCode(0)
        ->expectsOutput('Would create/update role: ADMIN')
        ->expectsOutput('Would create/update role: EDITOR')
        ->expectsOutput('Would create/update permission: posts.create')
        ->expectsOutput('Would create/update permission: posts.update');

    expect(Role::count())->toBe(0)
        ->and(Permission::count())
        ->toBe(0);
});

it('prunes roles and permissions', function () {
    Role::create(['name' => 'OLD_ROLE']);
    Permission::create(['name' => 'olds.permission']);

    config(['dominion.role_enum' => TestRole::class]);
    config(['dominion.permission_enums' => [TestPermission::class]]);

    // Without prune, they should stay
    $this->artisan('dominion:sync')
        ->assertExitCode(0);

    expect(Role::where('name', 'OLD_ROLE')->exists())
        ->toBeTrue()
        ->and(Permission::where('name', 'olds.permission')->exists())
        ->toBeTrue();

    // With prune, they should be removed
    $this->artisan('dominion:sync --prune')
        ->assertExitCode(0);

    expect(Role::where('name', 'OLD_ROLE')->exists())
        ->toBeFalse()
        ->and(Permission::where('name', 'olds.permission')->exists())
        ->toBeFalse()
        ->and(Role::count())->toBe(2) // ADMIN, EDITOR
        ->and(Permission::count())
        ->toBe(2);

    // posts.create, posts.update
});

it('prunes in dry-run mode without deleting', function () {
    Role::create(['name' => 'OLD_ROLE']);

    config(['dominion.role_enum' => TestRole::class]);

    $this->artisan('dominion:sync --prune --dry-run')
        ->assertExitCode(0)
        ->expectsOutput('Would delete role: OLD_ROLE');

    expect(Role::where('name', 'OLD_ROLE')->exists())
        ->toBeTrue();
});

it('outputs stub message for sync-pivots', function () {
    $this->artisan('dominion:sync --sync-pivots')
        ->expectsOutput('Pivot syncing is currently a stub.');
});
