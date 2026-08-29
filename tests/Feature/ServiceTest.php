<?php

namespace Tests\Feature;

use Infinity\Dominion\Contracts\PermissionValueResolver;
use Infinity\Dominion\Contracts\RoleValueResolver;
use Infinity\Dominion\Contracts\TenantContext;
use Infinity\Dominion\DominionServiceProvider;
use Infinity\Dominion\Models\Role;
use Infinity\Dominion\Services\DefaultPermissionValueResolver;
use Infinity\Dominion\Services\DefaultRoleValueResolver;
use Infinity\Dominion\Services\DefaultTenantContext;
use Infinity\Dominion\Services\DominionDatabase;
use InvalidArgumentException;
use ReflectionMethod;
use RuntimeException;
use Tests\Support\ConnectedPermission;
use Tests\Support\ConnectedRole;
use Tests\Support\CustomTenantContext;
use Tests\Support\TestPermission;
use Tests\Support\TestRole;

it('binds default services', function (): void {
    expect(app(TenantContext::class))
        ->toBeInstanceOf(DefaultTenantContext::class)
        ->and(app(PermissionValueResolver::class))
        ->toBeInstanceOf(DefaultPermissionValueResolver::class)
        ->and(app(RoleValueResolver::class))
        ->toBeInstanceOf(DefaultRoleValueResolver::class);
});

it('can override a service via config', function (): void {
    config(['dominion.services.tenant_context' => CustomTenantContext::class]);

    $this->app->singleton(TenantContext::class, function () {
        $class = config('dominion.services.tenant_context');

        return new $class;
    });

    expect(app(TenantContext::class))
        ->toBeInstanceOf(CustomTenantContext::class)
        ->and(app(TenantContext::class)->currentScope()->tenantId)
        ->toBe('123');
});

it('normalizes permission enums', function (): void {
    $resolver = app(PermissionValueResolver::class);

    expect($resolver->resolve(TestPermission::CREATE))
        ->toBe('posts.create')
        ->and($resolver->resolve(TestPermission::UPDATE))
        ->toBe('posts.update');
});

it('normalizes role enums', function (): void {
    $resolver = app(RoleValueResolver::class);

    expect($resolver->resolve(TestRole::ADMIN))
        ->toBe('ADMIN')
        ->and($resolver->resolve(TestRole::EDITOR))
        ->toBe('EDITOR');
});

it('uses the shared connection configured on the catalog models', function (): void {
    config([
        'database.connections.dominion' => config('database.connections.sqlite'),
        'dominion.models.role' => ConnectedRole::class,
        'dominion.models.permission' => ConnectedPermission::class,
    ]);

    expect(app(DominionDatabase::class)->connection()->getName())->toBe('dominion');
});

it('rejects catalog models that use different connections', function (): void {
    config([
        'database.connections.dominion' => config('database.connections.sqlite'),
        'dominion.models.role' => Role::class,
        'dominion.models.permission' => ConnectedPermission::class,
    ]);

    expect(fn () => app(DominionDatabase::class)->connection())
        ->toThrow(InvalidArgumentException::class, 'must use the same database connection');
});

it('throws exception if service does not implement contract', closure: function (): void {
    config(['dominion.services.tenant_context' => \stdClass::class]);

    $this->app->singleton(TenantContext::class, function () {
        return new \stdClass;
    });

    $provider = new DominionServiceProvider($this->app);

    $method = new ReflectionMethod($provider, 'validateServiceImplementations');
    $method->setAccessible(true);

    expect(fn () => $method->invoke($provider))
        ->toThrow(
            RuntimeException::class,
            "The configured service for 'dominion.services.tenant_context' must implement Infinity\Dominion\Contracts\TenantContext.");
});
