<?php

use Illuminate\Support\Facades\Hash;
use Infinity\Dominion\Models\Permission;
use Infinity\Dominion\Models\Role;
use Workbench\App\Models\User;

it('grants permission via role', function () {
    $user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => Hash::make('password'),
    ]);

    $role = Role::create(['name' => 'editor']);
    $permission = Permission::create(['name' => 'posts.edit']);

    $role->permissions()->attach($permission);
    $user->addRole($role);

    expect($user->hasPermission('posts.edit'))
        ->toBeTrue();
});

it('denies permission even if granted via role', function () {
    $user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => Hash::make('password'),
    ]);

    $role = Role::create(['name' => 'editor']);
    $permission = Permission::create(['name' => 'posts.edit']);

    $role->permissions()->attach($permission);
    $user->addRole($role);
    $user->deny('posts.edit');

    expect($user->hasPermission('posts.edit'))
        ->toBeFalse();
});

it('respects tenant scoping for role-based permissions', function () {
    $user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => Hash::make('password'),
    ]);

    $role = Role::create(['name' => 'editor']);
    $permission = Permission::create(['name' => 'posts.edit']);

    $role->permissions()->attach($permission);

    // Assign role to user for tenant 1
    $user->addRole($role, 1);

    // Should have permission for tenant 1
    // Should NOT have permission for tenant 2
    // Should NOT have permission globally (null tenant)
    expect($user->hasPermission('posts.edit', 1))
        ->toBeTrue()
        ->and($user->hasPermission('posts.edit', 2))
        ->toBeFalse()
        ->and($user->hasPermission('posts.edit', null))
        ->toBeFalse();
});

it('handles multiple roles with overlapping permissions', function () {
    $user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => Hash::make('password'),
    ]);

    $role1 = Role::create(['name' => 'editor']);
    $role2 = Role::create(['name' => 'publisher']);
    $permission = Permission::create(['name' => 'posts.publish']);

    $role2->permissions()->attach($permission);

    $user->addRole($role1);
    $user->addRole($role2);

    expect($user->hasPermission('posts.publish'))
        ->toBeTrue();
});

it('overrides role permission with explicit tenant deny', function () {
    $user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => Hash::make('password'),
    ]);

    $role = Role::create(['name' => 'editor']);
    $permission = Permission::create(['name' => 'posts.edit']);

    $role->permissions()->attach($permission);
    $user->addRole($role, 1);

    // Explicitly deny for tenant 1
    $user->deny('posts.edit', 1);

    expect($user->hasPermission('posts.edit', 1))
        ->toBeFalse();
});
