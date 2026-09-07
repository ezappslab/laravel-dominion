<?php

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Infinity\Dominion\Contracts\DominionManager;
use Infinity\Dominion\Domain\AuthorizationScope;
use Infinity\Dominion\DominionManager as DefaultDominionManager;
use Infinity\Dominion\Facades\Dominion;
use Infinity\Dominion\PendingAuthorization;

arch()->preset()->php();

arch()->preset()->laravel();

arch('commands follow Laravel command conventions')
    ->expect('Infinity\Dominion\Commands')
    ->toExtend(Command::class)
    ->toHaveSuffix('Command')
    ->toHaveMethod('handle');

arch('models are Eloquent models')
    ->expect('Infinity\Dominion\Models')
    ->toExtend(Model::class)
    ->not->toUse('Illuminate\Support\Facades\DB');

arch('contracts contain interfaces only')
    ->expect('Infinity\Dominion\Contracts')
    ->toBeInterfaces();

arch('traits contain traits only')
    ->expect('Infinity\Dominion\Traits')
    ->toBeTraits();

arch('the public facade extends Laravel facade')
    ->expect(Dominion::class)
    ->toExtend(Facade::class);

arch('the default manager fulfills its public contract')
    ->expect(DefaultDominionManager::class)
    ->toImplement(DominionManager::class);

arch('scope-bound authorization objects remain immutable implementation details')
    ->expect([AuthorizationScope::class, PendingAuthorization::class])
    ->toBeFinal()
    ->toBeReadonly();

arch('domain objects do not depend on package infrastructure')
    ->expect('Infinity\Dominion\Domain')
    ->not->toUse([
        'Infinity\Dominion\Commands',
        'Infinity\Dominion\Facades',
        'Infinity\Dominion\Models',
        'Infinity\Dominion\Policies',
        'Infinity\Dominion\Services',
        'Infinity\Dominion\Traits',
    ]);

arch('services do not depend on presentation or integration layers')
    ->expect('Infinity\Dominion\Services')
    ->not->toUse([
        'Infinity\Dominion\Commands',
        'Infinity\Dominion\Facades',
        'Infinity\Dominion\Policies',
        'Infinity\Dominion\Traits',
    ]);
