<?php

it('can load the config', function () {
    $this->assertNotNull(config('dominion'));
    $this->assertEquals('dominion', config('dominion.cache.prefix'));
    $this->assertFalse(config('dominion.permission_enum_discovery.enabled'));
});
