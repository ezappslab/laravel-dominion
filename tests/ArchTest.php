<?php

namespace Tests;

arch('contracts should be independent')
    ->expect('Infinity\Dominion\Contracts')
    ->toOnlyDependOn([
        'UnitEnum',
        'BackedEnum',
        'Illuminate\Database\Eloquent\Model',
    ]);

arch('services should be independent of traits, policies, and commands')
    ->expect('Infinity\Dominion\Services')
    ->toOnlyDependOn([
        'Infinity\Dominion\Contracts',
        'Infinity\Dominion\Models',
        'Illuminate\Contracts',
        'Illuminate\Support',
        'Illuminate\Database',
        'UnitEnum',
        'BackedEnum',
    ]);

arch('traits, policies, and commands should depend on contracts and services')
    ->expect([
        'Infinity\Dominion\Traits',
        'Infinity\Dominion\Policies',
        'Infinity\Dominion\Commands',
    ])
    ->toOnlyDependOn([
        'Infinity\Dominion\Contracts',
        'Infinity\Dominion\Services',
        'Infinity\Dominion\Models',
        'Illuminate\Contracts',
        'Illuminate\Support',
        'Illuminate\Database',
        'Spatie\LaravelPackageTools',
        'Workbench\App\Models',
        'Symfony\Component\Console',
    ])
    ->ignoring('Illuminate\Foundation');
