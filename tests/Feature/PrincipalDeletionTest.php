<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Infinity\Dominion\Models\Permission;
use Infinity\Dominion\Models\Role;
use RuntimeException;
use Tests\Support\SoftDeletingPrincipal;
use Workbench\App\Models\User;

it('purges every authorization assignment when a principal is deleted', function (): void {
    $user = User::create([
        'name' => 'Deleted User',
        'email' => 'deleted@example.com',
        'password' => Hash::make('password'),
    ]);
    assignAuthorizationData($user);

    $user->delete();

    expectPrincipalAssignmentsToExist(false);
});

it('invalidates cached decisions before a principal identifier is reused', function (): void {
    $user = User::create([
        'name' => 'Original User',
        'email' => 'original@example.com',
        'password' => Hash::make('password'),
    ]);
    $permission = Permission::create(['name' => 'posts.update']);
    $user->grantPermission($permission);

    expect($user->hasPermission($permission))->toBeTrue();

    $id = $user->getKey();
    $user->delete();

    $replacement = new User([
        'name' => 'Replacement User',
        'email' => 'replacement@example.com',
        'password' => Hash::make('password'),
    ]);
    $replacement->setAttribute($replacement->getKeyName(), $id);
    $replacement->save();

    expect($replacement->hasPermission($permission))->toBeFalse();
});

it('restores assignment cleanup when the principal deletion rolls back', function (): void {
    $user = User::create([
        'name' => 'Restored User',
        'email' => 'restored@example.com',
        'password' => Hash::make('password'),
    ]);
    assignAuthorizationData($user);

    try {
        DB::transaction(function () use ($user): void {
            $user->delete();

            throw new RuntimeException('Rollback principal deletion.');
        });
    } catch (RuntimeException) {
        // The rollback is expected by this regression test.
    }

    expectPrincipalAssignmentsToExist();
});

it('preserves assignments on soft delete and purges them on force delete', function (): void {
    $principal = SoftDeletingPrincipal::create(['name' => 'Soft-deleted Principal']);
    assignAuthorizationData($principal);

    $principal->delete();

    expectPrincipalAssignmentsToExist();

    $principal->forceDelete();

    expectPrincipalAssignmentsToExist(false);
});

function assignAuthorizationData(User|SoftDeletingPrincipal $principal): void
{
    $role = Role::create(['name' => 'EDITOR']);
    $granted = Permission::create(['name' => 'posts.update']);
    $denied = Permission::create(['name' => 'posts.delete']);

    $principal->assignRole($role);
    $principal->grantPermission($granted, tenant: 42);
    $principal->denyPermission($denied);
}

function expectPrincipalAssignmentsToExist(bool $expected = true): void
{
    expect(DB::table('role_assignments')->exists())->toBe($expected)
        ->and(DB::table('permission_grants')->exists())->toBe($expected)
        ->and(DB::table('permission_denials')->exists())->toBe($expected);
}
