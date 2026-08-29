<?php

use Illuminate\Support\Facades\Hash;
use Infinity\Dominion\Domain\AuthorizationScope;
use Infinity\Dominion\Models\Permission;
use Workbench\App\Models\User;

beforeEach(function (): void {
    $this->user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => Hash::make('password'),
    ]);

    $this->permission = Permission::create(['name' => 'posts.create']);
});

it('can allow a permission globally', function (): void {
    $this->user->allow($this->permission);

    expect($this->user->hasPermission($this->permission))
        ->toBeTrue();
});

it('can allow a permission for a tenant', function (): void {
    $this->user->allow($this->permission, 1);

    expect($this->user->hasPermission($this->permission, 1))
        ->toBeTrue()
        ->and($this->user->hasPermission($this->permission, 2))
        ->toBeFalse();
});

it('deny precedence over allow globally', function (): void {
    $this->user->allow($this->permission);
    $this->user->deny($this->permission);

    expect($this->user->hasPermission($this->permission))
        ->toBeFalse();
});

it('replaces an existing denial when granting the same permission', function (): void {
    $this->user->deny($this->permission);
    $this->user->allow($this->permission);

    expect($this->user->hasPermission($this->permission))->toBeTrue()
        ->and($this->user->deniedPermissions()->whereKey($this->permission->getKey())->exists())->toBeFalse()
        ->and($this->user->permissions()->whereKey($this->permission->getKey())->exists())->toBeTrue();
});

it('replaces an existing grant when denying the same permission', function (): void {
    $this->user->allow($this->permission);
    $this->user->deny($this->permission);

    expect($this->user->hasPermission($this->permission))->toBeFalse()
        ->and($this->user->permissions()->whereKey($this->permission->getKey())->exists())->toBeFalse()
        ->and($this->user->deniedPermissions()->whereKey($this->permission->getKey())->exists())->toBeTrue();
});

it('only replaces the opposite effect in the selected scope', function (): void {
    $this->user->deny($this->permission);
    $this->user->deny($this->permission, 1);
    $this->user->allow($this->permission, 1);

    expect($this->user->hasPermission($this->permission))->toBeFalse()
        ->and($this->user->hasPermission($this->permission, 1))->toBeFalse()
        ->and($this->user->permissions()->wherePivot('scope_key', AuthorizationScope::tenant(1)->key())->exists())->toBeTrue();
});

it('tenant deny precedence over global allow', function (): void {
    $this->user->allow($this->permission); // global allow
    $this->user->deny($this->permission, 1); // tenant deny

    expect($this->user->hasPermission($this->permission, 1))
        ->toBeFalse()
        ->and($this->user->hasPermission($this->permission))
        ->toBeTrue();
});

it('global deny precedence over tenant allow', function (): void {
    $this->user->deny($this->permission);
    $this->user->allow($this->permission, 1);

    expect($this->user->hasPermission($this->permission, 1))
        ->toBeFalse();
});

it('tenant override: tenant allow over global state (if not globally denied)', function (): void {
    // If not globally allowed or denied, tenant allow should work
    $this->user->allow($this->permission, 1);

    expect($this->user->hasPermission($this->permission, 1))
        ->toBeTrue()
        ->and($this->user->hasPermission($this->permission))
        ->toBeFalse();
});

it('resolves permission by name', function (): void {
    $this->user->allow('posts.create');

    expect($this->user->hasPermission('posts.create'))
        ->toBeTrue();
});
