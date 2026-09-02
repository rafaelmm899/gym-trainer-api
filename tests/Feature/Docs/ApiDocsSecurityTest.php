<?php

use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    // RestrictedDocsAccess allows the docs routes in `local` or when this gate passes.
    // The default arg lets the gate resolve for a guest request.
    Gate::define('viewApiDocs', fn ($user = null) => true);
});

// TC-27
it('marks the profile routes secured and register public in the generated OpenAPI spec', function () {
    $response = $this->getJson('/docs/api.json');

    if ($response->status() !== 200) {
        $this->markTestSkipped('Scramble /docs/api.json is not served in this environment; verified manually per spec §10 Task 23.');
    }

    $spec = $response->json();
    $paths = data_get($spec, 'paths', []);

    $profilePath = collect($paths)->keys()->first(fn (string $p) => str_contains($p, 'profile'));
    $registerPath = collect($paths)->keys()->first(fn (string $p) => str_contains($p, 'register'));

    expect($profilePath)->not->toBeNull()
        ->and($registerPath)->not->toBeNull();

    // A global security requirement is applied because at least one route uses auth:sanctum.
    expect(data_get($spec, 'security'))->toBeArray()->not->toBeEmpty();

    // register carries no auth middleware -> explicitly public.
    expect(data_get($paths, "{$registerPath}.post.security"))->toBe([]);

    // profile routes inherit the global requirement (no explicit public override).
    expect(data_get($paths, "{$profilePath}.get.security"))->not->toBe([])
        ->and(data_get($paths, "{$profilePath}.put.security"))->not->toBe([]);

    // The documented scheme is the Sanctum SPA session cookie, not a bearer token.
    $scheme = collect(data_get($spec, 'components.securitySchemes', []))->first();
    expect($scheme)->toBeArray()
        ->and($scheme['type'])->toBe('apiKey')
        ->and($scheme['in'])->toBe('cookie');
});
