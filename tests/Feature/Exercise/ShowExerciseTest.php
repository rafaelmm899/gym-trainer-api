<?php

use App\Models\Exercise;
use App\Models\User;

beforeEach(function () {
    $this->withHeader('Origin', config('app.url'));
    $this->user = User::factory()->create();
});

function enrichedExercise(array $overrides = []): Exercise
{
    return Exercise::factory()->create([
        'name' => 'Barbell Bench Press',
        'slug' => 'barbell-bench-press',
        'equipment' => 'barbell',
        'target_muscle' => 'pectorals',
        'secondary_muscles' => ['triceps', 'shoulders'],
        'instructions' => [
            'en' => ['Lie on a bench.', 'Press the bar.'],
            'es' => ['Túmbate en un banco.', 'Empuja la barra.'],
        ],
        ...$overrides,
    ]);
}

// Catalogue spec TC-36
it('returns the instructions in the language of Accept-Language', function (?string $header, string $first) {
    $exercise = enrichedExercise();

    $response = $this->actingAs($this->user)
        ->getJson("/api/v1/exercises/{$exercise->uuid}", $header === null ? [] : ['Accept-Language' => $header]);

    $response->assertOk()
        ->assertJsonPath('data.id', $exercise->uuid)
        ->assertJsonPath('data.equipment', 'barbell')
        ->assertJsonPath('data.target_muscle', 'pectorals')
        ->assertJsonPath('data.secondary_muscles', ['triceps', 'shoulders'])
        ->assertJsonPath('data.instructions.0', $first)
        ->assertHeader('Vary', 'Accept-Language');
})->with([
    'spanish' => ['es', 'Túmbate en un banco.'],
    'regional spanish' => ['es-AR', 'Túmbate en un banco.'],
    'weighted list' => ['fr;q=0.9, es;q=0.8', 'Túmbate en un banco.'],
    'english' => ['en', 'Lie on a bench.'],
    'unsupported language' => ['fr', 'Lie on a bench.'],
    'no header' => [null, 'Lie on a bench.'],
]);

// Catalogue spec TC-37
it('falls back to English, and to an empty list, when there are no steps', function () {
    $englishOnly = enrichedExercise(['instructions' => ['en' => ['Lie on a bench.']]]);
    $legacy = Exercise::factory()->create(['instructions' => null, 'equipment' => null, 'secondary_muscles' => null]);

    $this->actingAs($this->user)->getJson("/api/v1/exercises/{$englishOnly->uuid}", ['Accept-Language' => 'es'])
        ->assertOk()
        ->assertJsonPath('data.instructions', ['Lie on a bench.']);

    $this->actingAs($this->user)->getJson("/api/v1/exercises/{$legacy->uuid}", ['Accept-Language' => 'es'])
        ->assertOk()
        ->assertJsonPath('data.instructions', [])
        ->assertJsonPath('data.equipment', null)
        ->assertJsonPath('data.secondary_muscles', []);
});

// Catalogue spec TC-38
it('requires authentication and a known exercise', function () {
    $exercise = enrichedExercise();

    $this->getJson("/api/v1/exercises/{$exercise->uuid}")->assertUnauthorized();

    $this->actingAs($this->user)
        ->getJson('/api/v1/exercises/'.fake()->uuid())
        ->assertNotFound()
        ->assertJsonPath('data.code', 'NOT_FOUND_EXCEPTION');
});

it('never exposes the internal id or created_by_ai', function () {
    $exercise = enrichedExercise();

    $data = $this->actingAs($this->user)->getJson("/api/v1/exercises/{$exercise->uuid}")->json('data');

    expect($data)->not->toHaveKeys(['created_by_ai', 'external_id'])
        ->and($data['id'])->toBe($exercise->uuid);
});
