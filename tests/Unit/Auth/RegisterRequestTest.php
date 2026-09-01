<?php

use App\Http\Requests\Auth\RegisterRequest;

it('lowercases and trims the email before validation', function () {
    $request = RegisterRequest::create('/api/v1/register', 'POST', [
        'email' => '  Ada@Example.COM ',
    ]);

    (new ReflectionMethod($request, 'prepareForValidation'))->invoke($request);

    expect($request->input('email'))->toBe('ada@example.com');
});

it('leaves a non-string email untouched', function () {
    $request = RegisterRequest::create('/api/v1/register', 'POST', ['email' => null]);

    (new ReflectionMethod($request, 'prepareForValidation'))->invoke($request);

    expect($request->input('email'))->toBeNull();
});
