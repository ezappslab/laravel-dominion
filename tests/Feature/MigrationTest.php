<?php

use Illuminate\Support\Facades\Schema;

it('can run the migrations', function () {
    $this->assertTrue(Schema::hasTable('roles'));
    $this->assertTrue(Schema::hasTable('permissions'));
    $this->assertTrue(Schema::hasTable('permission_role'));
    $this->assertTrue(Schema::hasTable('roleables'));
    $this->assertTrue(Schema::hasTable('permissionables'));
    $this->assertTrue(Schema::hasTable('denied_permissionables'));

    $this->assertTrue(Schema::hasTable('tenants'));
    $this->assertTrue(Schema::hasTable('users'));
});

it('checks the roles table if it has expected columns', function () {
    $this->assertTrue(Schema::hasColumns('roles', [
        'id', 'name', 'guard_name', 'created_at', 'updated_at',
    ]));
});

it('checks the roleables table if it has expected columns', function () {
    $this->assertTrue(Schema::hasColumns('roleables', [
        'id', 'role_id', 'roleable_id', 'roleable_type', 'tenant_id', 'created_at', 'updated_at',
    ]));
});
