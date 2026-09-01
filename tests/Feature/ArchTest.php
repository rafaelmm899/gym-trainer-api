<?php

// TC-15 — pipeline conventions (see docs/plans/register-user-spec.md §8).

arch('actions are final and expose handle()')
    ->expect('App\Actions')
    ->toBeFinal()
    ->toHaveMethod('handle');

arch('auth controllers are invokable')
    ->expect('App\Http\Controllers\Auth')
    ->toBeInvokable();

arch('form requests extend FormRequest')
    ->expect('App\Http\Requests')
    ->toExtend('Illuminate\Foundation\Http\FormRequest');

arch('no debug helpers leak into app code')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'die'])
    ->not->toBeUsed();
