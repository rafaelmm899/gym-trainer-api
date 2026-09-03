<?php

use App\Enums\Cycle\CycleStatus;

// TC-31
it('has an incomplete case', function () {
    expect(CycleStatus::from('incomplete'))->toBe(CycleStatus::Incomplete)
        ->and(CycleStatus::tryFrom('incomplete'))->not->toBeNull();
});
