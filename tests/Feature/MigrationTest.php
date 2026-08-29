<?php

use Illuminate\Support\Facades\Schema;

it('can run the migrations', function (): void {
    expect(Schema::hasTable('roles'))->toBeTrue()
        ->and(Schema::hasTable('permissions'))->toBeTrue()
        ->and(Schema::hasTable('permission_role'))->toBeTrue()
        ->and(Schema::hasTable('roleables'))->toBeTrue()
        ->and(Schema::hasTable('permissionables'))->toBeTrue()
        ->and(Schema::hasTable('denied_permissionables'))->toBeTrue()
        ->and(Schema::hasTable('tenants'))->toBeTrue()
        ->and(Schema::hasTable('users'))->toBeTrue();
});

it('checks the roles table if it has expected columns', function (): void {
    expect(Schema::hasColumns('roles', [
        'id', 'name', 'guard_name', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('checks the roleables table if it has expected columns', function (): void {
    expect(Schema::hasColumns('roleables', [
        'id', 'role_id', 'roleable_id', 'roleable_type', 'tenant_id', 'created_at', 'updated_at',
    ]))->toBeTrue();
});
