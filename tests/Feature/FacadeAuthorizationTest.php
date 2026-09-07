<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Infinity\Dominion\DominionServiceProvider;
use Infinity\Dominion\Facades\Dominion;
use Infinity\Dominion\Models\Assignment;
use Infinity\Dominion\Models\Permission;
use Infinity\Dominion\Models\Role;
use Infinity\Dominion\Policies\BasePolicy;
use Infinity\Dominion\Services\AuthorizationCache;
use Tests\Support\CustomAssignment;
use Tests\Support\DuplicatePermission;
use Tests\Support\Principal;
use Tests\Support\SoftDeletingPrincipal;
use Tests\Support\Tenant;
use Tests\Support\TestPermission;
use Tests\Support\TestPolicy;
use Tests\Support\TestRole;

beforeEach(function (): void {
    config()->set('dominion.enums.role', TestRole::class);
    config()->set('dominion.enums.permissions', [TestPermission::class]);
    config()->set('dominion.tenant.model', Tenant::class);
    config()->set('dominion.roles', [TestRole::Member->value => [TestPermission::View]]);
    config()->set('dominion.defaults.allow', [TestPermission::Update]);
    config()->set('dominion.defaults.deny', [TestPermission::Delete]);
    config()->set('dominion.cache.enabled', false);

    Schema::create('principals', function (Blueprint $table): void {
        $table->id();
        $table->softDeletes();
    });
    $migration = require __DIR__.'/../../database/migrations/create_dominion_tables.php.stub';
    $migration->up();
    $this->artisan('dominion:sync')->assertSuccessful();
});

it('uses the facade for global and tenant role assignments', function (): void {
    $user = Principal::query()->create();
    $tenant = Tenant::query()->create(['name' => 'Acme']);

    Dominion::for($user)->globally()->grant(TestRole::Member);
    expect(Dominion::for($user)->in($tenant)->hasRole(TestRole::Member))->toBeTrue()
        ->and(Dominion::for($user)->in($tenant)->isAllowed(TestPermission::View))->toBeTrue();

    Dominion::for($user)->globally()->revoke(TestRole::Member);
    expect(Dominion::for($user)->in($tenant)->hasRole(TestRole::Member))->toBeFalse();
});

it('applies permission specificity before the denied fallback', function (): void {
    $user = Principal::query()->create();
    $tenant = Tenant::query()->create(['name' => 'Acme']);

    Dominion::for($user)->globally()->deny(TestPermission::View);
    Dominion::for($user)->in($tenant)->allow(TestPermission::View);

    expect(Dominion::for($user)->in($tenant)->isAllowed(TestPermission::View))->toBeTrue()
        ->and(Dominion::for($user)->globally()->isDenied(TestPermission::View))->toBeTrue()
        ->and(Dominion::for($user)->globally()->isAllowed(TestPermission::Update))->toBeTrue()
        ->and(Dominion::for($user)->globally()->isDenied(TestPermission::Delete))->toBeTrue();
});

it('replaces and forgets explicit permission decisions', function (): void {
    $user = Principal::query()->create();
    $auth = Dominion::for($user)->globally();

    $auth->allow(TestPermission::Delete);
    expect($auth->isAllowed(TestPermission::Delete))->toBeTrue();
    $auth->deny(TestPermission::Delete);
    expect($auth->isDenied(TestPermission::Delete))->toBeTrue()
        ->and(DB::table(config('dominion.tables.assignments'))->count())->toBe(1);
    $auth->forget(TestPermission::Delete);
    expect($auth->isDenied(TestPermission::Delete))->toBeTrue()
        ->and(DB::table(config('dominion.tables.assignments'))->count())->toBe(0);
});

it('applies profiles atomically and supports bulk checks', function (): void {
    config()->set('dominion.profiles.editor', ['roles' => [TestRole::Member], 'allow' => [TestPermission::Update], 'deny' => [TestPermission::Delete]]);
    $user = Principal::query()->create();

    $decisions = Dominion::for($user)->globally()->applyProfile('editor')->checkMany(TestPermission::cases());

    expect($decisions)->toBe([
        TestPermission::View->value => true,
        TestPermission::Update->value => true,
        TestPermission::Delete->value => false,
    ]);
});

it('uses identical precedence in query scopes', function (): void {
    $allowed = Principal::query()->create();
    $denied = Principal::query()->create();
    $tenant = Tenant::query()->create(['name' => 'Acme']);
    Dominion::for($allowed)->globally()->grant(TestRole::Member);
    Dominion::for($denied)->globally()->grant(TestRole::Member);
    Dominion::for($denied)->in($tenant)->deny(TestPermission::View);

    expect(Principal::query()->withRole(TestRole::Member)->count())->toBe(2)
        ->and(Principal::query()->withPermission(TestPermission::View, $tenant)->pluck('id')->all())->toBe([$allowed->getKey()]);
});

it('applies permission precedence in the global query scope', function (): void {
    $allowed = Principal::query()->create();
    $denied = Principal::query()->create();
    Dominion::for($allowed)->globally()->grant(TestRole::Member);
    Dominion::for($denied)->globally()->deny(TestPermission::View);

    expect(Principal::query()->withPermission(TestPermission::View)->pluck('id')->all())->toBe([$allowed->getKey()]);
});

it('keeps permission scopes equivalent to facade decisions across the precedence matrix', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Acme']);
    $principals = collect(range(1, 6))->map(fn () => Principal::query()->create());

    Dominion::for($principals[0])->globally()->deny(TestPermission::View);
    Dominion::for($principals[0])->in($tenant)->allow(TestPermission::View);
    Dominion::for($principals[1])->globally()->allow(TestPermission::View);
    Dominion::for($principals[1])->in($tenant)->deny(TestPermission::View);
    Dominion::for($principals[2])->in($tenant)->grant(TestRole::Member);
    Dominion::for($principals[2])->globally()->deny(TestPermission::View);
    Dominion::for($principals[3])->in($tenant)->grant(TestRole::Member);
    Dominion::for($principals[4])->globally()->grant(TestRole::Member);

    $expected = $principals
        ->filter(fn (Principal $principal): bool => Dominion::for($principal)->in($tenant)->isAllowed(TestPermission::View))
        ->pluck('id')
        ->all();

    expect(Principal::query()->withPermission(TestPermission::View, $tenant)->pluck('id')->all())->toBe($expected);
});

it('exposes role and direct permission relationships from their concern traits', function (): void {
    $user = Principal::query()->create();
    Dominion::for($user)->globally()->grant(TestRole::Member);
    Dominion::for($user)->globally()->allow(TestPermission::Update);
    Dominion::for($user)->globally()->deny(TestPermission::Delete);

    expect($user->roles()->pluck('name')->all())->toBe([TestRole::Member->value])
        ->and($user->permissions()->orderBy('name')->pluck('name')->all())->toBe([TestPermission::Delete->value, TestPermission::Update->value])
        ->and($user->allowedPermissions()->pluck('name')->all())->toBe([TestPermission::Update->value])
        ->and($user->deniedPermissions()->pluck('name')->all())->toBe([TestPermission::Delete->value]);
});

it('provides complete trait conveniences through the facade implementation', function (): void {
    $user = Principal::query()->create();
    $tenant = Tenant::query()->create(['name' => 'Acme']);

    expect($user->grantRole(TestRole::Member, $tenant))->toBe($user)
        ->and($user->hasRole(TestRole::Member, $tenant))->toBeTrue()
        ->and($user->allowPermission(TestPermission::Delete, $tenant))->toBe($user)
        ->and($user->hasPermission(TestPermission::Delete, $tenant))->toBeTrue()
        ->and($user->allowsPermission(TestPermission::Delete, $tenant))->toBeTrue()
        ->and($user->denyPermission(TestPermission::Delete, $tenant))->toBe($user)
        ->and($user->deniesPermission(TestPermission::Delete, $tenant))->toBeTrue()
        ->and($user->checkPermissions(TestPermission::cases(), $tenant))->toBe([
            TestPermission::View->value => true,
            TestPermission::Update->value => true,
            TestPermission::Delete->value => false,
        ])
        ->and($user->forgetPermission(TestPermission::Delete, $tenant))->toBe($user)
        ->and($user->revokeRole(TestRole::Member, $tenant))->toBe($user)
        ->and($user->hasRole(TestRole::Member, $tenant))->toBeFalse();
});

it('integrates with Laravel Gate for opted-in principals', function (): void {
    $user = Principal::query()->create();
    Dominion::for($user)->globally()->allow(TestPermission::View);

    expect(Gate::forUser($user)->allows(TestPermission::View->value))->toBeTrue()
        ->and(Gate::forUser($user)->denies('unknown.permission'))->toBeTrue();
});

it('leaves non-Dominion principals to other Gate handlers', function (): void {
    expect(Gate::forUser(Tenant::query()->create(['name' => 'Acme']))->allows('unregistered.ability'))->toBeFalse();
});

it('registers configured policies with Laravel Gate', function (): void {
    config()->set('dominion.gate.enabled', false);
    config()->set('dominion.policy.models', [Principal::class => TestPolicy::class]);

    (new DominionServiceProvider(app()))->packageBooted();

    expect(Gate::getPolicyFor(Principal::class))->toBeInstanceOf(TestPolicy::class);
});

it('requires every facade operation to choose a scope', function (): void {
    Dominion::for(Principal::query()->create())->isAllowed(TestPermission::View);
})->throws(InvalidArgumentException::class, 'Choose a scope');

it('requires a persisted principal before writing assignments', function (): void {
    Dominion::for(new Principal)->globally()->allow(TestPermission::View);
})->throws(InvalidArgumentException::class, 'principal must be persisted');

it('isolates tenant assignments while inheriting global assignments', function (): void {
    $user = Principal::query()->create();
    $first = Tenant::query()->create(['name' => 'First']);
    $second = Tenant::query()->create(['name' => 'Second']);

    Dominion::for($user)->in($first)->allow(TestPermission::Delete);

    expect(Dominion::for($user)->in($first)->isAllowed(TestPermission::Delete))->toBeTrue()
        ->and(Dominion::for($user)->in($second)->isDenied(TestPermission::Delete))->toBeTrue();

    Dominion::for($user)->globally()->allow(TestPermission::Delete);
    expect(Dominion::for($user)->in($second)->isAllowed(TestPermission::Delete))->toBeTrue();
});

it('prioritizes a direct global decision over a tenant role permission', function (): void {
    $user = Principal::query()->create();
    $tenant = Tenant::query()->create(['name' => 'Acme']);
    Dominion::for($user)->in($tenant)->grant(TestRole::Member);
    Dominion::for($user)->globally()->deny(TestPermission::View);

    expect(Dominion::for($user)->in($tenant)->isDenied(TestPermission::View))->toBeTrue();
});

it('covers both directions of tenant and global direct precedence', function (): void {
    $user = Principal::query()->create();
    $tenant = Tenant::query()->create(['name' => 'Acme']);

    Dominion::for($user)->globally()->allow(TestPermission::View);
    Dominion::for($user)->in($tenant)->deny(TestPermission::View);
    expect(Dominion::for($user)->in($tenant)->isDenied(TestPermission::View))->toBeTrue();

    Dominion::for($user)->in($tenant)->forget(TestPermission::View);
    expect(Dominion::for($user)->in($tenant)->isAllowed(TestPermission::View))->toBeTrue();
});

it('lets direct decisions override defaults in either direction', function (): void {
    $user = Principal::query()->create();
    $authorization = Dominion::for($user)->globally();

    expect($authorization->isAllowed(TestPermission::Update))->toBeTrue()
        ->and($authorization->isDenied(TestPermission::Delete))->toBeTrue();

    $authorization->deny(TestPermission::Update)->allow(TestPermission::Delete);

    expect($authorization->isDenied(TestPermission::Update))->toBeTrue()
        ->and($authorization->isAllowed(TestPermission::Delete))->toBeTrue();
});

it('keeps repeated assignments idempotent', function (): void {
    $user = Principal::query()->create();
    $auth = Dominion::for($user)->globally();

    $auth->grant(TestRole::Member)->grant(TestRole::Member);
    $auth->allow(TestPermission::View)->allow(TestPermission::View);

    expect(DB::table(config('dominion.tables.assignments'))->count())->toBe(2);
});

it('represents unified assignments with relationships and reusable scopes', function (): void {
    $user = Principal::query()->create();
    $tenant = Tenant::query()->create(['name' => 'Acme']);
    Dominion::for($user)->globally()->grant(TestRole::Member);
    Dominion::for($user)->in($tenant)->allow(TestPermission::View);

    $role = Assignment::query()->forPrincipal($user)->globally()->roles()->firstOrFail();
    $permission = Assignment::query()->forPrincipal($user)->forTenant($tenant)->permissions()->withEffect(Assignment::EFFECT_ALLOW)->firstOrFail();

    expect($role->principal->is($user))->toBeTrue()
        ->and($role->role->name)->toBe(TestRole::Member->value)
        ->and($permission->tenant->is($tenant))->toBeTrue()
        ->and($permission->permission->name)->toBe(TestPermission::View->value);
});

it('rejects assignments absent from the synchronized catalog', function (): void {
    Dominion::for(Principal::query()->create())->globally()->allow('missing.permission');
})->throws(InvalidArgumentException::class, 'Permission is not synchronized');

it('rejects unsynchronized roles and reports absent roles as false', function (): void {
    $user = Principal::query()->create();

    expect(Dominion::for($user)->globally()->hasRole('missing-role'))->toBeFalse();
    Dominion::for($user)->globally()->grant('missing-role');
})->throws(InvalidArgumentException::class, 'Role is not synchronized');

it('rejects a model that is not the configured tenant type', function (): void {
    Dominion::for(Principal::query()->create())->in(Principal::query()->create());
})->throws(InvalidArgumentException::class, 'Tenant must be an instance');

it('rolls back every profile assignment when one entry is invalid', function (): void {
    config()->set('dominion.profiles.broken', [
        'roles' => [TestRole::Member],
        'allow' => ['missing.permission'],
    ]);
    $user = Principal::query()->create();

    try {
        Dominion::for($user)->globally()->applyProfile('broken');
    } catch (InvalidArgumentException) {
        // The assertion below verifies that the earlier role grant was rolled back.
    }

    expect(Assignment::query()->forPrincipal($user)->doesntExist())->toBeTrue();
});

it('removes assignments when a principal is permanently deleted', function (): void {
    $user = Principal::query()->create();
    Dominion::for($user)->globally()->grant(TestRole::Member)->allow(TestPermission::View);

    $user->delete();

    expect(DB::table(config('dominion.tables.assignments'))->count())->toBe(0);
});

it('preserves assignments across soft deletion and removes them on force deletion', function (): void {
    $user = SoftDeletingPrincipal::query()->create();
    expect($user->dominionAuthorized())->toBeTrue();
    Dominion::for($user)->globally()->allow(TestPermission::View);

    $user->delete();
    expect(Assignment::query()->forPrincipal($user)->exists())->toBeTrue();

    $user->forceDelete();
    expect(Assignment::query()->forPrincipal($user)->doesntExist())->toBeTrue();
});

it('invalidates cached decisions after mutations', function (): void {
    config()->set('dominion.cache.enabled', true);
    Cache::store()->clear();
    $user = Principal::query()->create();
    $auth = Dominion::for($user)->globally();

    expect($auth->isDenied(TestPermission::View))->toBeTrue();
    $auth->allow(TestPermission::View);
    expect($auth->isAllowed(TestPermission::View))->toBeTrue();
});

it('materializes defaults and exposes the unified schema', function (): void {
    expect(Schema::hasColumns(config('dominion.tables.assignments'), [
        'principal_type', 'principal_id', 'tenant_type', 'tenant_id', 'scope_key',
        'role_id', 'permission_id', 'assignment_key', 'effect',
    ]))->toBeTrue()
        ->and(DB::table(config('dominion.tables.permissions'))->where('name', TestPermission::Update->value)->value('default_effect'))->toBe('allow')
        ->and(DB::table(config('dominion.tables.permissions'))->where('name', TestPermission::Delete->value)->value('default_effect'))->toBe('deny');
});

it('supports dry runs and explicit pruning', function (): void {
    DB::table(config('dominion.tables.permissions'))->insert(['name' => 'obsolete', 'created_at' => now(), 'updated_at' => now()]);

    $this->artisan('dominion:sync', ['--dry-run' => true])->assertSuccessful();
    expect(DB::table(config('dominion.tables.permissions'))->where('name', 'obsolete')->exists())->toBeTrue();

    $this->artisan('dominion:sync', ['--prune' => true])->assertSuccessful();
    expect(DB::table(config('dominion.tables.permissions'))->where('name', 'obsolete')->exists())->toBeFalse();
});

it('expands wildcard role mappings to the complete permission catalog', function (): void {
    config()->set('dominion.roles', [TestRole::Member->value => ['*']]);
    $this->artisan('dominion:sync')->assertSuccessful();

    $role = Role::query()->where('name', TestRole::Member->value)->firstOrFail();

    expect($role->permissions()->orderBy('name')->pluck('name')->all())->toBe([
        TestPermission::Delete->value,
        TestPermission::Update->value,
        TestPermission::View->value,
    ]);
});

it('uses the configured assignment model for mutations and cleanup', function (): void {
    config()->set('dominion.models.assignment', CustomAssignment::class);
    $user = Principal::query()->create();

    Dominion::for($user)->globally()->allow(TestPermission::View);
    expect(CustomAssignment::query()->first())->toBeInstanceOf(CustomAssignment::class);

    $user->delete();
    expect(CustomAssignment::query()->doesntExist())->toBeTrue();
});

it('invalidates memoized decisions when the catalog version changes', function (): void {
    config()->set('dominion.cache.enabled', true);
    Cache::store()->clear();
    $user = Principal::query()->create();
    $authorization = Dominion::for($user)->globally();

    expect($authorization->isDenied(TestPermission::View))->toBeTrue();
    Permission::query()->where('name', TestPermission::View->value)->update(['default_effect' => Assignment::EFFECT_ALLOW]);
    expect($authorization->isDenied(TestPermission::View))->toBeTrue();

    app(AuthorizationCache::class)->invalidateCatalog();
    expect($authorization->isAllowed(TestPermission::View))->toBeTrue();
});

it('delegates base policy permissions through the facade', function (): void {
    $user = Principal::query()->create();
    Dominion::for($user)->globally()->allow(TestPermission::View);
    $policy = new class extends BasePolicy
    {
        public function check(Principal $principal): bool
        {
            return $this->authorize($principal, 'documents', 'view');
        }
    };

    expect($policy->check($user))->toBeTrue();
});

it('removes stale role mappings during synchronization', function (): void {
    $user = Principal::query()->create();
    Dominion::for($user)->globally()->grant(TestRole::Member);
    expect(Dominion::for($user)->globally()->isAllowed(TestPermission::View))->toBeTrue();

    config()->set('dominion.roles', []);
    $this->artisan('dominion:sync')->assertSuccessful();

    expect(Dominion::for($user)->globally()->isDenied(TestPermission::View))->toBeTrue();
});

it('reports a healthy installation without mutating it', function (): void {
    $count = DB::table(config('dominion.tables.permissions'))->count();

    $this->artisan('dominion:doctor')->assertSuccessful();

    expect(DB::table(config('dominion.tables.permissions'))->count())->toBe($count);
});

it('reports doctor failures without mutating the catalog', function (): void {
    config()->set('dominion.defaults.allow', ['missing.permission']);
    $count = Permission::query()->count();

    $this->artisan('dominion:doctor')->assertFailed();

    expect(Permission::query()->count())->toBe($count);
});

it('rejects conflicting materialized defaults', function (): void {
    config()->set('dominion.defaults.allow', [TestPermission::View]);
    config()->set('dominion.defaults.deny', [TestPermission::View]);

    $this->artisan('dominion:sync');
})->throws(InvalidArgumentException::class, 'both allowed and denied');

it('rejects duplicate permission values during synchronization', function (): void {
    config()->set('dominion.enums.permissions', [TestPermission::class, DuplicatePermission::class]);

    $this->artisan('dominion:sync');
})->throws(InvalidArgumentException::class, 'must be unique');

it('rejects unknown default permissions during synchronization', function (): void {
    config()->set('dominion.defaults.allow', ['missing.permission']);

    $this->artisan('dominion:sync');
})->throws(InvalidArgumentException::class, 'Unknown default permission');

it('rejects unknown roles in synchronized role mappings', function (): void {
    config()->set('dominion.roles', ['missing-role' => [TestPermission::View]]);

    $this->artisan('dominion:sync');
})->throws(InvalidArgumentException::class, 'Unknown configured role');

it('rejects unknown permissions in synchronized role mappings', function (): void {
    config()->set('dominion.roles', [TestRole::Member->value => ['missing.permission']]);

    $this->artisan('dominion:sync');
})->throws(InvalidArgumentException::class, 'Unknown configured permission');

it('diagnoses duplicate permissions', function (): void {
    config()->set('dominion.enums.permissions', [TestPermission::class, DuplicatePermission::class]);

    $this->artisan('dominion:doctor')->assertFailed();
});

it('diagnoses overlapping defaults', function (): void {
    config()->set('dominion.defaults.allow', [TestPermission::View]);
    config()->set('dominion.defaults.deny', [TestPermission::View]);

    $this->artisan('dominion:doctor')->assertFailed();
});

it('diagnoses malformed profiles', function (mixed $profile): void {
    config()->set('dominion.profiles.broken', $profile);

    $this->artisan('dominion:doctor')->assertFailed();
})->with([
    'profile is not an array' => 'invalid',
    'profile section is not iterable' => [['roles' => 42]],
]);

it('diagnoses missing Dominion tables', function (): void {
    Schema::drop(config('dominion.tables.assignments'));

    $this->artisan('dominion:doctor')->assertFailed();
});

it('rejects unknown profiles', function (): void {
    Dominion::for(Principal::query()->create())->globally()->applyProfile('missing');
})->throws(InvalidArgumentException::class, 'is not configured');
