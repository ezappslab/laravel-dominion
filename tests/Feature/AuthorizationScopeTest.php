<?php

namespace Tests\Feature;

use Infinity\Dominion\Domain\AuthorizationScope;

it('uses the configured morph type for scalar tenant identifiers', function (): void {
    config(['dominion.tenancy.tenant_type' => 'workspace']);

    $scope = AuthorizationScope::tenant(42);

    expect($scope->tenantType)->toBe('workspace')
        ->and($scope->tenantId)->toBe('42');
});
