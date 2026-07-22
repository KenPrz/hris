<?php

declare(strict_types=1);

/*
| The rules in docs/04-backend-conventions.md, enforced by CI rather than by review.
| A convention nobody checks is a suggestion, and this pattern's entire value is that
| every system action looks like every other one.
*/

arch('actions never touch HTTP')
    ->expect('App\Actions')
    ->not->toUse([
        'Illuminate\Http\Request',
        'Illuminate\Http\Response',
        'Illuminate\Http\JsonResponse',
        'Illuminate\Http\Resources\Json\JsonResource',
        'Illuminate\Foundation\Http\FormRequest',
    ]);

arch('actions are final')
    ->expect('App\Actions')
    ->toBeClasses()
    ->toBeFinal();

arch('the domain layer is framework-agnostic')
    ->expect('App\Domain')
    ->not->toUse([
        'Illuminate\Http',
        'Illuminate\Foundation',
    ]);

arch('controllers are final single-action classes')
    ->expect('App\Http\Controllers')
    ->toBeFinal()
    ->toBeInvokable();

arch('env() is only ever called from config files')
    ->expect('env')
    ->not->toBeUsed();

arch('nothing debug-related survives to CI')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'die'])
    ->not->toBeUsed();

arch('domain exceptions extend the base so the render hook catches them')
    ->expect('App\Exceptions\Domain')
    ->toExtend('App\Exceptions\Domain\DomainException')
    ->ignoring('App\Exceptions\Domain\DomainException');

arch('strict types everywhere')
    ->expect('App')
    ->toUseStrictTypes();
