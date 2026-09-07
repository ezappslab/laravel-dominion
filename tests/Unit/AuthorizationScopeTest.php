<?php

use Infinity\Dominion\Domain\AuthorizationScope;
use Tests\Support\Tenant;

it('creates a stable global scope', function (): void {
    expect(AuthorizationScope::global()->isGlobal())->toBeTrue()
        ->and(AuthorizationScope::global()->key())->toBe('global');
});

it('rejects an unpersisted tenant', function (): void {
    AuthorizationScope::tenant(new Tenant);
})->throws(InvalidArgumentException::class, 'must be persisted');
