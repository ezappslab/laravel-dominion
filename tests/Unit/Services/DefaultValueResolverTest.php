<?php

use Infinity\Dominion\Services\DefaultPermissionValueResolver;
use Infinity\Dominion\Services\DefaultRoleValueResolver;
use Tests\Support\TestPermission;
use Tests\Support\TestRole;

it('resolves backed permission enums and scalar permission values', function (): void {
    $resolver = new DefaultPermissionValueResolver;

    expect($resolver->resolve(TestPermission::CREATE))->toBe('posts.create')
        ->and($resolver->resolve(TestRole::EDITOR))->toBe('EDITOR')
        ->and($resolver->resolve('posts.publish'))->toBe('posts.publish')
        ->and($resolver->resolve(42))->toBe('42');
});

it('resolves unit role enums and scalar role values', function (): void {
    $resolver = new DefaultRoleValueResolver;

    expect($resolver->resolve(TestRole::ADMIN))->toBe('ADMIN')
        ->and($resolver->resolve(TestPermission::UPDATE))->toBe('posts.update')
        ->and($resolver->resolve('owner'))->toBe('owner')
        ->and($resolver->resolve(42))->toBe('42');
});
