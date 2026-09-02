<?php

use App\Data\Routine\RoutineData;
use App\Enums\Shared\Goal;

// TC-21
it('maps input, casts the goal enum and defaults hint to null', function () {
    $withHint = RoutineData::from([
        'name' => 'Winter Volume',
        'goal' => 'strength',
        'hint' => 'PPL',
    ]);

    expect($withHint->name)->toBe('Winter Volume')
        ->and($withHint->goal)->toBe(Goal::Strength)
        ->and($withHint->hint)->toBe('PPL');

    $withoutHint = RoutineData::from([
        'name' => 'Winter Volume',
        'goal' => 'strength',
    ]);

    expect($withoutHint->hint)->toBeNull();
});
