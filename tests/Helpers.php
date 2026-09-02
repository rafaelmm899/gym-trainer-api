<?php

/*
|--------------------------------------------------------------------------
| Test Helpers
|--------------------------------------------------------------------------
|
| Global helper functions shared across the test suite. Registered through
| `autoload-dev.files` in composer.json so they are available in every test
| without an import.
|
*/

/**
 * Regex matching a canonical lowercase UUID v4 string — the shape every API
 * `id` / route key takes (`HasPublicUuid`).
 */
function uuidV4Pattern(): string
{
    return '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
}

/**
 * Regex matching an ISO-8601 datetime with a numeric timezone offset — the
 * shape every timestamp takes in a JSON Resource (`toIso8601String()`).
 */
function iso8601Pattern(): string
{
    return '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/';
}
