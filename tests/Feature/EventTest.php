<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Infinity\Dominion\Domain\AuthorizationScope;
use Infinity\Dominion\Events\CatalogSynchronized;
use Infinity\Dominion\Events\PermissionDenied;
use Infinity\Dominion\Events\PermissionGranted;
use Infinity\Dominion\Events\PermissionRevoked;
use Infinity\Dominion\Events\RoleAssigned;
use Infinity\Dominion\Events\RolePermissionsSynchronized;
use Infinity\Dominion\Events\RoleRemoved;
use Infinity\Dominion\Models\Permission;
use Infinity\Dominion\Models\Role;
use RuntimeException;
use Tests\Support\TestPermission;
use Tests\Support\TestRole;
use Workbench\App\Models\User;

beforeEach(function (): void {
    Event::fake();
    $this->user = User::create([
        'name' => 'Event User',
        'email' => fake()->unique()->safeEmail(),
        'password' => Hash::make('password'),
    ]);
});

it('dispatches role events after actual assignment changes', function (): void {
    $role = Role::create(['name' => 'editor']);

    $this->user->assignRole($role, 42);
    $this->user->assignRole($role, 42);
    $this->user->removeRole($role, 42);

    Event::assertDispatchedTimes(RoleAssigned::class, 1);
    Event::assertDispatched(RoleAssigned::class, fn (RoleAssigned $event): bool => $event->principal->is($this->user)
        && $event->role === 'editor'
        && $event->scope->key() === AuthorizationScope::tenant(42)->key());
    Event::assertDispatchedTimes(RoleRemoved::class, 1);
});

it('dispatches permission effect events after commit', function (): void {
    $permission = Permission::create(['name' => 'posts.edit']);

    $this->user->grantPermission($permission);
    $this->user->denyPermission($permission);
    $this->user->revokePermission($permission);

    Event::assertDispatchedTimes(PermissionGranted::class, 1);
    Event::assertDispatchedTimes(PermissionDenied::class, 1);
    Event::assertDispatchedTimes(PermissionRevoked::class, 1);
});

it('does not dispatch assignment events for rolled back transactions', function (): void {
    $permission = Permission::create(['name' => 'posts.edit']);

    try {
        DB::transaction(function () use ($permission): void {
            $this->user->grantPermission($permission);

            throw new RuntimeException('Rollback assignment.');
        });
    } catch (RuntimeException) {
        // The rollback is expected by this regression test.
    }

    Event::assertNotDispatched(PermissionGranted::class);
});

it('dispatches role permission and catalog synchronization events', function (): void {
    $role = Role::create(['name' => 'editor']);
    $permission = Permission::create(['name' => 'posts.edit']);

    $role->syncPermissions([$permission]);

    config([
        'dominion.catalog.role_enum' => TestRole::class,
        'dominion.catalog.permission_enums' => [TestPermission::class],
        'dominion.catalog.role_permissions' => [TestRole::EDITOR->name => [TestPermission::UPDATE]],
    ]);
    $this->artisan('dominion:sync')->assertSuccessful();

    Event::assertDispatchedTimes(RolePermissionsSynchronized::class, 1);
    Event::assertDispatched(CatalogSynchronized::class, fn (CatalogSynchronized $event): bool => $event->roles === ['ADMIN', 'EDITOR']
        && $event->permissions === ['posts.create', 'posts.update']
        && $event->pruned === false);
});
