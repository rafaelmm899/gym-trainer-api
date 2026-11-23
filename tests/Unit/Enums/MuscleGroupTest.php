<?php

use App\Enums\Shared\MuscleGroup;

// TC-1
it('has twelve groups including forearms and cardio', function () {
    expect(MuscleGroup::values())
        ->toHaveCount(12)
        ->toContain('forearms', 'cardio');
});

// TC-2
it('maps every dataset target to a group', function (string $target, MuscleGroup $expected) {
    expect(MuscleGroup::fromDatasetTarget($target))->toBe($expected);
})->with([
    'abs' => ['abs', MuscleGroup::Core],
    'pectorals' => ['pectorals', MuscleGroup::Chest],
    'serratus anterior' => ['serratus anterior', MuscleGroup::Chest],
    'delts' => ['delts', MuscleGroup::Shoulders],
    'upper back' => ['upper back', MuscleGroup::Back],
    'lats' => ['lats', MuscleGroup::Back],
    'traps' => ['traps', MuscleGroup::Back],
    'spine' => ['spine', MuscleGroup::Back],
    'levator scapulae' => ['levator scapulae', MuscleGroup::Back],
    'adductors' => ['adductors', MuscleGroup::Glutes],
    'abductors' => ['abductors', MuscleGroup::Glutes],
    'forearms' => ['forearms', MuscleGroup::Forearms],
    'cardiovascular system' => ['cardiovascular system', MuscleGroup::Cardio],
    'biceps' => ['biceps', MuscleGroup::Biceps],
    'triceps' => ['triceps', MuscleGroup::Triceps],
    'quads' => ['quads', MuscleGroup::Quads],
    'hamstrings' => ['hamstrings', MuscleGroup::Hamstrings],
    'glutes' => ['glutes', MuscleGroup::Glutes],
    'calves' => ['calves', MuscleGroup::Calves],
]);

// TC-3
it('returns null for a target it does not know', function () {
    expect(MuscleGroup::fromDatasetTarget('xyz'))->toBeNull();
});
