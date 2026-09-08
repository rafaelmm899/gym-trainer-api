<?php

// User-facing text of the CSV training-day export
// (App\Services\Cycle\CycleDayCsvExportService). v1 output is Spanish-only; the
// Service pulls these with a locale pinned to `es`. The `#` comment templates are
// filled with `strtr`, not the translator's own replacer, so a value that
// contains a `:token` is never re-expanded.

return [
    'filename' => [
        'cycle' => 'ciclo',
        'day' => 'dia',
    ],

    'comment' => [
        'day' => 'rutina: :routine | ciclo :cycle | dia :day (:label) | foco: :focus',
        'split_rationale' => 'racional del split: :rationale',
        'exercise' => ':exercise — prescripcion: :prescription. Racional: :rationale.',
        'exercise_recommendation' => 'Recomendacion: :action — :explanation',
    ],

    'prescription' => [
        'rest' => 'descanso',
    ],
];
