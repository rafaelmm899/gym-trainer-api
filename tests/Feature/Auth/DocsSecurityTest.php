<?php

use Dedoc\Scramble\Generator;

// TC-21 (login & logout spec) + TC-27 (create-user-profile spec)
it('documents auth with a cookie scheme and keeps the public routes open', function () {
    $spec = app(Generator::class)();

    // Exactly one security scheme: an apiKey carried in a cookie (not http/bearer).
    expect($spec['components']['securitySchemes'])->toHaveCount(1);

    $scheme = array_values($spec['components']['securitySchemes'])[0];
    expect($scheme['type'])->toBe('apiKey')
        ->and($scheme['in'])->toBe('cookie');

    // The scheme is applied at the document root, so the auth:sanctum operations
    // inherit it without a per-operation override.
    expect($spec['security'])->not->toBeEmpty()
        ->and($spec['paths']['/api/v1/logout']['post'])->not->toHaveKey('security')
        ->and($spec['paths']['/api/v1/user']['get'])->not->toHaveKey('security')
        ->and($spec['paths']['/api/v1/profile']['get'])->not->toHaveKey('security')
        ->and($spec['paths']['/api/v1/profile']['put'])->not->toHaveKey('security')
        ->and($spec['paths']['/api/v1/routines']['post'])->not->toHaveKey('security')
        ->and($spec['paths']['/api/v1/routines']['get'])->not->toHaveKey('security')
        ->and($spec['paths']['/api/v1/routines/{routine}']['get'])->not->toHaveKey('security');

    // The public operations opt out explicitly.
    expect($spec['paths']['/api/v1/login']['post']['security'])->toBe([])
        ->and($spec['paths']['/api/v1/register']['post']['security'])->toBe([])
        ->and($spec['paths']['/sanctum/csrf-cookie']['get']['security'])->toBe([]);
});
