<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Infinity\Dominion\Models\Permission;
use Infinity\Dominion\Models\Role;
use Workbench\App\Models\User;

beforeEach(function (): void {
    $this->user = User::create([
        'name' => 'Idempotent User',
        'email' => 'idempotent@example.com',
        'password' => Hash::make('password'),
    ]);
});

it('refreshes a repeated role assignment without creating a duplicate', function (): void {
    $role = Role::create(['name' => 'EDITOR']);
    $this->user->assignRole($role);
    $original = assignmentTimestamps('role_assignments');

    $this->travel(1)->second();
    $this->user->assignRole($role);
    $refreshed = assignmentTimestamps('role_assignments');

    expect(DB::table('role_assignments')->count())->toBe(1)
        ->and($refreshed->created_at)->toBe($original->created_at)
        ->and($refreshed->updated_at)->not->toBe($original->updated_at);
});

it('refreshes repeated direct permission effects without creating duplicates', function (string $method, string $table): void {
    $permission = Permission::create(['name' => 'posts.update']);
    $this->user->{$method}($permission);
    $original = assignmentTimestamps($table);

    $this->travel(1)->second();
    $this->user->{$method}($permission);
    $refreshed = assignmentTimestamps($table);

    expect(DB::table($table)->count())->toBe(1)
        ->and($refreshed->created_at)->toBe($original->created_at)
        ->and($refreshed->updated_at)->not->toBe($original->updated_at);
})->with([
    'grant' => ['grantPermission', 'permission_grants'],
    'denial' => ['denyPermission', 'permission_denials'],
]);

function assignmentTimestamps(string $table): object
{
    return DB::table($table)->sole(['created_at', 'updated_at']);
}
