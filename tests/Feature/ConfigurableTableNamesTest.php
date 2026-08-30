<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Infinity\Dominion\Models\Permission;
use Infinity\Dominion\Models\Role;
use Infinity\Dominion\Services\TableRegistry;
use InvalidArgumentException;
use Workbench\App\Models\User;

it('uses configured table names throughout the authorization lifecycle', function (): void {
    $configured = [
        'roles' => 'dominion_roles',
        'permissions' => 'dominion_permissions',
        'role_permissions' => 'dominion_permission_role',
        'role_assignments' => 'dominion_role_assignments',
        'permission_grants' => 'dominion_permission_grants',
        'permission_denials' => 'dominion_permission_denials',
    ];

    foreach ($configured as $key => $table) {
        Schema::rename(config("dominion.tables.{$key}"), $table);
    }

    config(['dominion.tables' => $configured]);

    $user = User::create([
        'name' => 'Configured Tables User',
        'email' => 'configured-tables@example.com',
        'password' => Hash::make('password'),
    ]);
    $role = Role::create(['name' => 'EDITOR']);
    $granted = Permission::create(['name' => 'posts.update']);
    $denied = Permission::create(['name' => 'posts.delete']);
    $role->syncPermissions([$granted]);

    $user->assignRole($role);
    $user->grantPermission($granted, tenant: 42);
    $user->denyPermission($denied);

    expect($user->hasRole($role))->toBeTrue()
        ->and($user->hasPermission($granted, tenant: 42))->toBeTrue()
        ->and($user->hasPermission($denied))->toBeFalse()
        ->and(DB::table($configured['role_permissions'])->count())->toBe(1)
        ->and(DB::table($configured['role_assignments'])->count())->toBe(1)
        ->and(DB::table($configured['permission_grants'])->count())->toBe(1)
        ->and(DB::table($configured['permission_denials'])->count())->toBe(1);

    $user->delete();

    expect(DB::table($configured['role_assignments'])->count())->toBe(0)
        ->and(DB::table($configured['permission_grants'])->count())->toBe(0)
        ->and(DB::table($configured['permission_denials'])->count())->toBe(0);
});

it('rejects an invalid configured table name', function (): void {
    config(['dominion.tables.roles' => '']);

    expect(fn () => app(TableRegistry::class)->roles())
        ->toThrow(InvalidArgumentException::class, 'must be a non-empty string');
});
