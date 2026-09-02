<?php

use App\Http\Requests\Auth\LoginRequest;

it('lowercases and trims the email before validation', function () {
    $request = LoginRequest::create('/api/v1/login', 'POST', [
        'email' => '  Ada@Example.COM ',
    ]);

    (new ReflectionMethod($request, 'prepareForValidation'))->invoke($request);

    expect($request->input('email'))->toBe('ada@example.com');
});

it('leaves a non-string email untouched', function () {
    $request = LoginRequest::create('/api/v1/login', 'POST', ['email' => null]);

    (new ReflectionMethod($request, 'prepareForValidation'))->invoke($request);

    expect($request->input('email'))->toBeNull();
});
