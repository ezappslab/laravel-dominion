<?php

use Illuminate\Database\Eloquent\Model;
use Infinity\Dominion\Domain\AuthorizationScope;
use InvalidArgumentException;

it('creates a stable global scope', function (): void {
    $scope = AuthorizationScope::global();

    expect($scope->tenantType)->toBeNull()
        ->and($scope->tenantId)->toBeNull()
        ->and($scope->isGlobal())->toBeTrue()
        ->and($scope->key())->toBe('global');
});

it('creates a tenant scope from an explicit morph type and identifier', function (): void {
    $scope = AuthorizationScope::tenant('team', 42);

    expect($scope->tenantType)->toBe('team')
        ->and($scope->tenantId)->toBe('42')
        ->and($scope->isGlobal())->toBeFalse()
        ->and($scope->key())->toBe(hash('sha256', 'team|42'));
});

it('creates a tenant scope from a persisted model', function (): void {
    $tenant = new class extends Model
    {
        protected $table = 'teams';
    };
    $tenant->setAttribute($tenant->getKeyName(), 7);

    $scope = AuthorizationScope::tenant($tenant);

    expect($scope->tenantType)->toBe($tenant->getMorphClass())
        ->and($scope->tenantId)->toBe('7');
});

it('rejects an unpersisted tenant model', function (): void {
    $tenant = new class extends Model {};

    expect(fn () => AuthorizationScope::tenant($tenant))
        ->toThrow(InvalidArgumentException::class, 'must exist before it can be used');
});
