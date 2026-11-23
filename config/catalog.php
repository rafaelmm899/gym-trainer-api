<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Exercise dataset
    |--------------------------------------------------------------------------
    |
    | Where `exercises:import` downloads hasaneyldrm/exercises-dataset from when
    | no `--path` is given. The URL is pinned to a commit so an import is
    | reproducible; override it with CATALOG_DATASET_URL to use a mirror or a
    | newer commit. The dataset's data is MIT; its images are not used.
    |
    */

    'dataset' => [
        'url' => env(
            'CATALOG_DATASET_URL',
            'https://raw.githubusercontent.com/hasaneyldrm/exercises-dataset/7455efae41b330c265e7cd4b78dfa848e7ce5ebd/data/exercises.json',
        ),
        'timeout' => 120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Instruction languages
    |--------------------------------------------------------------------------
    |
    | The languages whose instructions are stored and that `Accept-Language` is
    | negotiated against. The first one is the fallback.
    |
    */

    'locales' => ['en', 'es'],

    /*
    |--------------------------------------------------------------------------
    | Name fixes
    |--------------------------------------------------------------------------
    |
    | Known encoding damage in dataset names, applied before the name is
    | title-cased: "sled 45в° leg press" is "sled 45° leg press".
    |
    */

    'name_fixes' => [
        'в°' => '°',
    ],

];
