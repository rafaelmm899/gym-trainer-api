<?php

return [

    /*
    |--------------------------------------------------------------------------
    | First-cycle planning
    |--------------------------------------------------------------------------
    |
    | How many exercises the AI planner prescribes per training day, keyed by the
    | athlete's experience level. Each value is a "min-max" string. The planner
    | states the range in its prompt and rejects a plan whose days fall outside
    | it. Days per cycle stays fixed at 5 in v1.
    |
    */

    'cycle' => [
        'exercises_per_day' => [
            'beginner' => env('CYCLE_EXERCISES_PER_DAY_BEGINNER', '3-5'),
            'intermediate' => env('CYCLE_EXERCISES_PER_DAY_INTERMEDIATE', '4-6'),
            'advanced' => env('CYCLE_EXERCISES_PER_DAY_ADVANCED', '5-8'),
        ],
    ],

];
