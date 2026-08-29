<?php

it('can load the config', function (): void {
    expect(config('dominion'))->not->toBeNull()
        ->and(config('dominion.cache.prefix'))->toEqual('dominion')
        ->and(config('dominion.permission_enum_discovery.enabled'))->toBeFalse();
});
