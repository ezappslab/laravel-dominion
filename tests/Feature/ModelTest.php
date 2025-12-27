<?php

use Infinity\Dominion\Models\Permission;
use Infinity\Dominion\Models\Role;

it('can attach permission to role', function () {
    $role = Role::create(['name' => 'editor']);
    $permission = Permission::create(['name' => 'articles.update']);

    $role->permissions()->attach($permission);

    expect($role->permissions)
        ->toHaveCount(1)
        ->and($role->permissions->first()->name)
        ->toBe('articles.update');
});

it('checks if the inverse relationship works', function () {
    $role = Role::create(['name' => 'editor']);
    $permission = Permission::create(['name' => 'articles.update']);

    $role->permissions()->attach($permission);

    expect($permission->roles)
        ->toHaveCount(1)
        ->and($permission->roles->first()->name)
        ->toBe('editor');
});

it('checks if the pivot table has timestamps', function () {
    $role = Role::create(['name' => 'editor']);
    $permission = Permission::create(['name' => 'articles.update']);

    $role->permissions()->attach($permission);

    $pivot = $role->permissions()->first()->pivot;

    expect($pivot->created_at)->not->toBeNull()
        ->and($pivot->updated_at)->not->toBeNull();
});
