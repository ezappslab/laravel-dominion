<?php

use Illuminate\Support\Facades\Hash;
use Infinity\Dominion\Models\Permission;
use Workbench\App\Models\User;

beforeEach(function () {
    $this->user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => Hash::make('password'),
    ]);

    $this->permission = Permission::create(['name' => 'posts.create']);
});

it('can allow a permission globally', function () {
    $this->user->allow($this->permission);

    expect($this->user->hasPermission($this->permission))
        ->toBeTrue();
});

it('can allow a permission for a tenant', function () {
    $this->user->allow($this->permission, 1);

    expect($this->user->hasPermission($this->permission, 1))
        ->toBeTrue()
        ->and($this->user->hasPermission($this->permission, 2))
        ->toBeFalse();
});

it('deny precedence over allow globally', function () {
    $this->user->allow($this->permission);
    $this->user->deny($this->permission);

    expect($this->user->hasPermission($this->permission))
        ->toBeFalse();
});

it('tenant deny precedence over global allow', function () {
    $this->user->allow($this->permission); // global allow
    $this->user->deny($this->permission, 1); // tenant deny

    expect($this->user->hasPermission($this->permission, 1))
        ->toBeFalse()
        ->and($this->user->hasPermission($this->permission))
        ->toBeTrue();
});

it('global deny precedence over tenant allow', function () {
    $this->user->deny($this->permission);
    $this->user->allow($this->permission, 1);

    expect($this->user->hasPermission($this->permission, 1))
        ->toBeFalse();
});

it('tenant override: tenant allow over global state (if not globally denied)', function () {
    // If not globally allowed or denied, tenant allow should work
    $this->user->allow($this->permission, 1);

    expect($this->user->hasPermission($this->permission, 1))
        ->toBeTrue()
        ->and($this->user->hasPermission($this->permission))
        ->toBeFalse();
});

it('resolves permission by name', function () {
    $this->user->allow('posts.create');

    expect($this->user->hasPermission('posts.create'))
        ->toBeTrue();
});
