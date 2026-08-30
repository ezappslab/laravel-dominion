<?php

use Illuminate\Support\Facades\Schema;

it('can run the migrations', function (): void {
    expect(Schema::hasTable('roles'))->toBeTrue()
        ->and(Schema::hasTable('permissions'))->toBeTrue()
        ->and(Schema::hasTable('permission_role'))->toBeTrue()
        ->and(Schema::hasTable('role_assignments'))->toBeTrue()
        ->and(Schema::hasTable('permission_grants'))->toBeTrue()
        ->and(Schema::hasTable('permission_denials'))->toBeTrue()
        ->and(Schema::hasTable('tenants'))->toBeTrue()
        ->and(Schema::hasTable('users'))->toBeTrue();
});

it('checks the roles table if it has expected columns', function (): void {
    expect(Schema::hasColumns('roles', [
        'id', 'name', 'created_at', 'updated_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumn('roles', 'guard_name'))->toBeFalse();
});

it('checks the permissions table if it has expected columns', function (): void {
    expect(Schema::hasColumns('permissions', [
        'id', 'name', 'created_at', 'updated_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumn('permissions', 'guard_name'))->toBeFalse();
});

it('checks the role assignments table if it has expected columns', function (): void {
    expect(Schema::hasColumns('role_assignments', [
        'id', 'role_id', 'principal_id', 'principal_type', 'tenant_type', 'tenant_id', 'scope_key', 'created_at', 'updated_at',
    ]))->toBeTrue();
});
