<?php

use App\Models\Exercise;

// TC-4
it('gives the same slug for every Unicode hyphen', function (string $hyphen) {
    expect(Exercise::slugFor("Pull{$hyphen}Ups"))->toBe('pull-ups');
})->with([
    'hyphen U+2010' => "\u{2010}",
    'non-breaking hyphen U+2011' => "\u{2011}",
    'figure dash U+2012' => "\u{2012}",
    'en dash U+2013' => "\u{2013}",
    'em dash U+2014' => "\u{2014}",
    'horizontal bar U+2015' => "\u{2015}",
    'plain hyphen' => '-',
]);

it('keeps the existing rule for case, accents and spacing', function () {
    expect(Exercise::slugFor('  BARBELL  Bench  Préss '))->toBe('barbell-bench-press')
        ->and(Exercise::slugFor('Sled 45° Leg Press'))->toBe('sled-45-leg-press');
});
