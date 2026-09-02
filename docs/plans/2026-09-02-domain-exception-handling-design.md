# Domain exception handling & normalized error envelope — design

Date: 2026-09-02
Status: approved, ready for an implementation plan

## Problem

The API has no exception handling of its own. `bootstrap/app.php` only sets
`shouldRenderJsonWhen`; every error body is a Laravel default, so shapes differ
per case (`422` → `{message, errors}`, `401` → `{message}`, `500` → debug dump)
and none carries a machine-readable code. `CLAUDE.md` already anticipates domain
guard clauses in Services (`throw_if($routine->isArchived(), new RoutineArchivedException())`)
but there is no base class for them and nothing renders them.

## Goal

1. One error envelope for **every** error response under `/api/*`.
2. A machine-readable `code` on every error.
3. A base `DomainException` for business-rule violations thrown from Services,
   each subclass carrying its own specific `code` and HTTP status.

Out of scope: no concrete domain exception is built now — the `Routine` / `Cycle`
/ `Session` domains do not exist yet. This change is infrastructure plus tests
plus docs. `ProfileIncompleteException` appears here only as the worked example;
it ships with `POST /routines`.

## Error envelope

Applied when `$request->is('api/*')` or `$request->expectsJson()`:

```json
{ "code": "SCREAMING_SNAKE_CASE", "message": "Human-readable sentence." }
```

- `code` — `SCREAMING_SNAKE_CASE`. For framework-level failures it is the
  category (`VALIDATION_EXCEPTION`, `AUTHENTICATION_EXCEPTION`, …). For a domain
  rule it is the specific identifier (`ROUTINE_ARCHIVED`, `CYCLE_NOT_DRAFT`, …);
  the base `DomainException` falls back to `DOMAIN_EXCEPTION`.
- `message` — a complete sentence, safe to show to an end user. Never leaks an
  internal class name, SQL, or a stack frame.
- `errors` — present **only** for `VALIDATION_EXCEPTION`: Laravel's existing
  `{ "field": ["msg", …] }` map, unchanged.
- No `details` / context field.

Adding `code` is additive: existing assertions
(`assertJsonValidationErrors`, `assertJsonMissingPath('errors')`,
`assertJsonPath('message', …)`) keep passing.

## Code catalog

| Trigger | `code` | HTTP | Notes |
|---|---|---|---|
| `Illuminate\Validation\ValidationException` | `VALIDATION_EXCEPTION` | 422 | adds `errors`; keeps Laravel's `message` |
| `Illuminate\Auth\AuthenticationException` | `AUTHENTICATION_EXCEPTION` | 401 | keeps the exception message ("These credentials do not match our records.", "Unauthenticated.") |
| `Illuminate\Auth\Access\AuthorizationException`, `Symfony ... AccessDeniedHttpException`, `abort(403)` | `AUTHORIZATION_EXCEPTION` | 403 | |
| `Illuminate\Database\Eloquent\ModelNotFoundException`, `Symfony ... NotFoundHttpException`, `abort(404)` | `NOT_FOUND_EXCEPTION` | 404 | generic `"Resource not found."` — never the model class |
| `Symfony ... MethodNotAllowedHttpException` | `METHOD_NOT_ALLOWED_EXCEPTION` | 405 | |
| `Illuminate\Session\TokenMismatchException`, `abort(419)` | `CSRF_TOKEN_MISMATCH` | 419 | |
| `Illuminate\Http\Exceptions\ThrottleRequestsException` | `RATE_LIMIT_EXCEPTION` | 429 | `Retry-After` / `X-RateLimit-*` headers preserved |
| any other `Symfony ... HttpExceptionInterface` | `HTTP_EXCEPTION` | its `getStatusCode()` | fallback for a bare `abort($code)` |
| `App\Exceptions\DomainException` (base) | `DOMAIN_EXCEPTION` | 409 | a subclass overrides both |
| anything else | `SERVER_EXCEPTION` | 500 | `"Server error."` in production; **in debug the renderer returns `null`** so Laravel's verbose handler (message + trace) still shows |

## Components

### `app/Exceptions/DomainException.php` — abstract base

```php
namespace App\Exceptions;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

abstract class DomainException extends RuntimeException
{
    protected string $errorCode = 'DOMAIN_EXCEPTION';

    protected int $statusCode = Response::HTTP_CONFLICT; // 409

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
```

- `abstract` — a domain error is always a named subclass, so `code` is always
  meaningful. No ad-hoc `throw new DomainException(...)`.
- SPL's `$code` is `int`; the string identifier is exposed through `errorCode()`.
- A subclass sets the two properties and a default message. Concrete classes live
  in `app/Exceptions/{Domain}/` — e.g. `app/Exceptions/Routine/RoutineArchivedException.php`.

Worked example (built later, with `POST /routines`):

```php
namespace App\Exceptions\Profile;

use App\Exceptions\DomainException;

final class ProfileIncompleteException extends DomainException
{
    protected string $errorCode = 'PROFILE_INCOMPLETE';
    // inherits 409

    public function __construct()
    {
        parent::__construct('Complete your athlete profile before creating a routine.');
    }
}
```

Thrown as a Service guard clause, per `CLAUDE.md`:

```php
throw_if($user->athleteProfile()->doesntExist(), new ProfileIncompleteException());
```

### `app/Exceptions/ApiExceptionRenderer.php` — the mapping

`final class`, one method `render(Throwable $e): ?JsonResponse`.

- Type-check ladder, specific classes first: `ValidationException`,
  `AuthenticationException`, `AuthorizationException`, `ModelNotFoundException`,
  `TokenMismatchException`, `ThrottleRequestsException`, `DomainException`.
- Then `HttpExceptionInterface` → a `status ⇒ code` lookup table
  (403/404/405/419/429 map to their named codes, everything else `HTTP_EXCEPTION`),
  status from `getStatusCode()`.
- Then the fallback: `config('app.debug') === true` → `return null` (Laravel's
  verbose handler renders); otherwise `SERVER_EXCEPTION` / 500 / `"Server error."`.
- Builds `['code' => …, 'message' => …]`; adds `errors => $e->errors()` only for
  `ValidationException`; merges `$e->getHeaders()` for any `HttpException` so
  `Retry-After` survives.
- This is the one sanctioned place raw JSON is assembled — `CLAUDE.md` rule 3
  ("rendered by the exception handler — never hand-built JSON"). It is the whole
  feature, not indirection, and is unit-testable in isolation (rule 6). No error
  "Resource" — Resources are for success bodies.

### `bootstrap/app.php` — wiring

The existing `withExceptions` closure gains one `render()` callback; everything
else stays:

```php
$exceptions->render(function (Throwable $e, Request $request) {
    if (! $request->is('api/*') && ! $request->expectsJson()) {
        return null;
    }

    return app(ApiExceptionRenderer::class)->render($e);
});
```

No controller, action, or service changes.

## Data flow

```
Service guard  ──throw──▶  DomainException subclass
Framework      ──throw──▶  ValidationException / AuthenticationException / …
                                   │
                     bootstrap/app.php render() callback
                                   │  (api/* or expectsJson)
                          ApiExceptionRenderer::render()
                                   │
                   JsonResponse { code, message, errors? }  +  preserved headers
```

## Testing

- `tests/Unit/Exceptions/ApiExceptionRendererTest.php` — feed the renderer one
  `Throwable` per catalog row, assert `code`, status, body keys, preserved
  headers, and the debug-`null` fallback (`config(['app.debug' => true])`).
  A tiny stub `DomainException` subclass (in the test file or `tests/Fixtures/`)
  covers the base and an overriding subclass.
- `tests/Feature/Shared/ApiExceptionHandlingTest.php` — end to end through real
  behaviour:
  - unauthenticated `GET /api/v1/user` → 401 `AUTHENTICATION_EXCEPTION`,
    `assertJsonMissingPath('errors')`.
  - wrong `POST /api/v1/login` → 401 `AUTHENTICATION_EXCEPTION`, message
    unchanged.
  - `POST /api/v1/register` with a bad body → 422 `VALIDATION_EXCEPTION` with
    `errors`.
  - 7× `POST /api/v1/login` → 429 `RATE_LIMIT_EXCEPTION` with a `Retry-After`
    header.
  - unknown `/api/v1/...` path → 404 `NOT_FOUND_EXCEPTION`.
  - a test-only route that throws a stub `DomainException` → 409 with the stub's
    `code`; another that throws a generic `RuntimeException` under
    `config(['app.debug' => false])` → 500 `SERVER_EXCEPTION`, `"Server error."`,
    no leak.
- Existing `LoginTest` / `RegisterTest` / profile tests must stay green
  unchanged.

## Docs to update in the same change

- `CLAUDE.md` **and** `AGENTS.md` (edited together): a short "Errors" subsection —
  the envelope, the catalog table, and "to add a domain error: extend
  `DomainException` in `app/Exceptions/{Domain}/`, set `$errorCode` /
  `$statusCode` / a default message, throw it from a Service guard."
- `CLAUDE.md` layout tree: add `Exceptions/{Domain}/…Exception.php` and the base.
- Optional last step — `Scramble::extendOpenApi()` (or `config/scramble.php`) to
  register the `{code, message, errors?}` error schema so `/docs/api` shows it;
  drop if fiddly.

## Decisions

| Decision | Choice | Why |
|---|---|---|
| Envelope | flat `{code, message}` (+ `errors` for validation) | closest to Laravel's existing `message`-first bodies; `code` is purely additive |
| `code` form | `SCREAMING_SNAKE_CASE` | requested |
| Domain error identity | `code` **is** the specific identifier (`ROUTINE_ARCHIVED`); `DOMAIN_EXCEPTION` only as the base fallback | a client branches on `code`, not on prose |
| Scope of normalization | every error response, framework exceptions included | one shape everywhere; existing tests unaffected |
| Base `DomainException` status | 409 default, subclass overrides | separates "malformed input" (422) from "state forbids this" (409) |
| `DomainException` abstract? | yes | forces a named subclass so `code` is always meaningful |
| Concrete exceptions now | none — infra only; `ProfileIncompleteException` documented as the example | `Routine` / `Cycle` / `Session` don't exist; no speculative class |
| Where the mapping lives | `ApiExceptionRenderer`, called from one `render()` callback | encapsulated, unit-testable; the sanctioned place for hand-built error JSON |
