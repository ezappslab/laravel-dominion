<?php

use Infinity\Dominion\Services\Catalog;
use Tests\Support\TestPermission;

it('normalizes backed enums and enumerates their values', function (): void {
    $catalog = new Catalog;

    expect($catalog->permission(TestPermission::View))->toBe('documents.view')
        ->and($catalog->enumValues(TestPermission::class))->toBe([
            'documents.view', 'documents.update', 'documents.delete',
        ]);
});

it('rejects invalid catalog values', function (): void {
    (new Catalog)->permission('');
})->throws(InvalidArgumentException::class);

it('rejects non-enum catalog classes', function (): void {
    (new Catalog)->enumValues(stdClass::class);
})->throws(InvalidArgumentException::class, 'must be a backed enum');

it('returns no values when an enum is not configured', function (): void {
    expect((new Catalog)->enumValues(null))->toBe([]);
});
