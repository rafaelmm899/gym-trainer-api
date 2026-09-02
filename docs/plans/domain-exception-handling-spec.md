# Domain Exception Handling & Normalized Error Envelope

## 1. Context

**Kind:** Brownfield Feature

**Stack:** PHP 8.5 (Composer floor `^8.3`) · Laravel 13 · Pest 4 (feature tests boot the app via `Tests\TestCase` + `RefreshDatabase`) · Larastan level 6 · Pint

**Problem statement:** The API has no exception handling of its own. `bootstrap/app.php` only sets `shouldRenderJsonWhen`, so every error body is a Laravel default: `422` → `{message, errors}`, `401` → `{message}`, `500` → a debug dump. Shapes differ per case and none carries a machine-readable code. `CLAUDE.md` already prescribes domain guard clauses in Services (`throw_if($routine->isArchived(), new RoutineArchivedException())`), but there is no base class for them and nothing renders them. This change introduces one error envelope for every `/api/*` response, a machine-readable `code` on every error, and an abstract `DomainException` base for business-rule violations.

**In scope:**
- `App\Exceptions\DomainException` — abstract base for Service-level business-rule violations (`code` fallback `DOMAIN_EXCEPTION`, HTTP 409, both overridable per subclass).
- `App\Exceptions\ApiExceptionRenderer` — maps any `Throwable` to a `data`-wrapped `JsonResponse` (never null; every error path is wrapped).
- One `$exceptions->render(...)` callback in `bootstrap/app.php` wiring the renderer for JSON requests only.
- Normalization of the framework exceptions listed in §9 into the `{ code, message, errors? }` envelope.
- Feature tests for every catalog branch and a regression pass of the existing suite.
- Docs: an "Errors" subsection and the `Exceptions/` layout entry in `CLAUDE.md` **and** `AGENTS.md` (identical files, edited together).

**Out of scope:**
- Any concrete `DomainException` subclass. The `Routine` / `Cycle` / `Session` domains do not exist yet; the first real subclass ships with `POST /routines`. `ProfileIncompleteException` appears in this spec only as an illustrative example.
- Any change to a controller, Action, Service, Form Request, Resource, route, or the database.
- Fixing the pre-existing test-suite isolation bug (the `app` container exports `CACHE_STORE=redis` / `SESSION_DRIVER=redis`, which `phpunit.xml`'s `<env>` entries do not override without `force="true"`, so rate-limiter and session state leak across tests). The full suite is already red on the untouched branch base for this reason; this change is verified against that baseline, not against a green suite.

**Adjusted during implementation:**
- The error body is wrapped in a top-level `data` key (product-owner decision, mid-implementation) — so *all* API responses, success and error, share the `{ "data": … }` shape. `data.errors` holds the validation map.
- Existing assertions updated to match: `assertExactJson([...])` on 401 bodies re-nested under `data` (`tests/Feature/Auth/{CurrentUserTest,LogoutTest,LoginTest}.php`); ~16 `assertJsonValidationErrors('field')` calls across `RegisterTest`, `LoginTest`, `UpdateAthleteProfileTest` given the `'data.errors'` response key; `assertJsonMissingPath('errors')` → `'data.errors'`. No test deleted.
- The debug passthrough was dropped: `render()` no longer returns `null` for an unhandled throwable while `app.debug` is on. It always returns the `data`-wrapped envelope; in debug the real message plus `exception`/`file`/`line`/`trace` sit under `data`. `render()` is now `: JsonResponse`, non-nullable. The only `null` short-circuit is the non-JSON check in the `bootstrap/app.php` callback.
- Changing HTTP status codes of existing responses (only the body gains `code`; `errors` is unchanged for validation).
- A `details` / context field on the envelope.
- Localization / translation of error messages.

---

## 2. API Surface

### 2.1 REST

Not applicable — no new endpoints. This change rewrites the **error body** of every existing `/api/*` response (and any future one). Every response body in the API is wrapped in a top-level `data` key — success bodies already are, via JSON Resources; errors now match. The contract for an error body:

```json
{ "data": { "code": "SCREAMING_SNAKE_CASE", "message": "Human-readable sentence." } }
```

- `data.errors` (`{ "field": ["msg", …] }`, Laravel's existing map) is present **only** when `data.code` is `VALIDATION_EXCEPTION`.
- Success bodies are untouched. Per-exception `code` / status mapping is in §9; the before/after per behavior is in §7.
- In tests: `assertJsonPath('data.code', …)`, `assertJsonMissingPath('data.errors')`, `assertJsonValidationErrors([...], 'data.errors')`.

Two **test-only** routes are registered inside the test files (never in `routes/api.php`) to exercise branches with no natural trigger yet: `GET /api/v1/_test/domain` (throws a stub `DomainException`) and `GET /api/v1/_test/boom` (throws a plain `RuntimeException`).

### 2.2 CLI

Not applicable — no CLI commands.

### 2.3 Events

Not applicable — no events.

---

## 3. UI

### 3.1 Pages

Not applicable — no pages affected.

### 3.2 Components

Not applicable — no components affected.

---

## 4. Database

Not applicable — no data or schema changes.

### 4.1 Schema changes

Not applicable — no data or schema changes.

### 4.2 Seeds

Not applicable — no seeds.

---

## 5. Auth & Authorization

### 5.1 Authentication

**Method:** Session (Laravel Sanctum, SPA mode — cookie + CSRF). Unchanged. The mechanism, guards, and middleware are not touched; only the **failure body** changes: a failed `auth:sanctum` check still raises `Illuminate\Auth\AuthenticationException` and still returns HTTP 401, now rendered as `{ "code": "AUTHENTICATION_EXCEPTION", "message": "<unchanged message>" }`. A stateful request without a valid CSRF token still returns 419, now as `{ "code": "CSRF_TOKEN_MISMATCH", "message": "..." }`.

### 5.2 Authorization

Not applicable — no authorization changes. Policies and gates are unchanged. A denied Policy check still raises `Illuminate\Auth\Access\AuthorizationException` and returns HTTP 403, now rendered as `{ "code": "AUTHORIZATION_EXCEPTION", "message": "..." }`.

---

## 6. Configuration

No new environment variables. `APP_DEBUG` (existing) gains a second effect used by `ApiExceptionRenderer`:

| Variable | Value / Source | Purpose |
|---|---|---|
| `APP_DEBUG` | existing `.env` / `config('app.debug')` | Only affects the `SERVER_EXCEPTION` branch, and only its **body detail** — the envelope and the `data` wrapper are always applied. `false` → `{ "data": { "code": "SERVER_EXCEPTION", "message": "Server error." } }`. `true` → `message` is the real exception message and `data` also carries `exception`, `file`, `line`, `trace`. HTTP 500 either way. |

---

## 7. Current vs New Behavior

Every "New" body below is wrapped in a top-level `data` key — e.g. the row that reads `{ code: "X", message }` is on the wire as `{ "data": { "code": "X", "message": … } }`. Validation's `errors` map sits at `data.errors`.

| Behavior | Current | New |
|---|---|---|
| Error body shape (any `/api/*` error) | Varies per exception; no `code`; not wrapped | Always `{ data: { code, message } }` (+ `data.errors` for validation) |
| Validation failure | `422` `{ message, errors }` | `422` `{ code: "VALIDATION_EXCEPTION", message, errors }` — `message` and `errors` byte-for-byte as before, `code` added |
| Unauthenticated request | `401` `{ message: "Unauthenticated." }` | `401` `{ code: "AUTHENTICATION_EXCEPTION", message: "Unauthenticated." }` |
| Bad login credentials | `401` `{ message: "These credentials do not match our records." }` | `401` `{ code: "AUTHENTICATION_EXCEPTION", message: "These credentials do not match our records." }`, still no `errors` key |
| Denied Policy / gate | `403` `{ message }` | `403` `{ code: "AUTHORIZATION_EXCEPTION", message }` |
| Missing model / unknown route | `404` `{ message }` (may echo the model class) | `404` `{ code: "NOT_FOUND_EXCEPTION", message: "Resource not found." }` — model class never leaked |
| Wrong HTTP method | `405` `{ message }` | `405` `{ code: "METHOD_NOT_ALLOWED_EXCEPTION", message }` |
| CSRF token mismatch | `419` `{ message }` | `419` `{ code: "CSRF_TOKEN_MISMATCH", message }` |
| Rate limit exceeded | `429` `{ message }` + `Retry-After` header | `429` `{ code: "RATE_LIMIT_EXCEPTION", message }` + `Retry-After` / `X-RateLimit-*` headers preserved |
| Bare `abort($code)` with no dedicated mapping | `$code` `{ message }` | `$code` `{ code: "HTTP_EXCEPTION", message }` |
| Business-rule violation from a Service | No mechanism (would surface as an unhandled 500) | `DomainException` subclass → its `errorCode()` / `statusCode()` (base: `DOMAIN_EXCEPTION` / 409), rendered as `{ code, message }` |
| Unhandled `Throwable`, `APP_DEBUG=false` | `500` Laravel generic JSON `{ message: "Server Error" }` | `500` `{ data: { code: "SERVER_EXCEPTION", message: "Server error." } }` |
| Unhandled `Throwable`, `APP_DEBUG=true` | `500` verbose flat JSON (`message`, `exception`, `file`, `line`, `trace` at the root) | `500` `{ data: { code: "SERVER_EXCEPTION", message: <real>, exception, file, line, trace } }` — same detail, wrapped |
| Non-JSON request (no `api/*` path, no `Accept: application/json`) | Laravel default (HTML error page / redirect) | Unchanged — the `bootstrap/app.php` callback returns `null` before the renderer is reached |

---

## 8. Test Cases

All executable with `docker compose exec app vendor/bin/pest`. Two files, both at `tests/Feature/` root. Every body assertion targets the `data.*` path (`data.code`, `data.message`, `data.errors`); `getData(true)` in the renderer test returns `['data' => [...]]`.

### `tests/Feature/ApiExceptionRendererTest.php`

Resolves `ApiExceptionRenderer` from the container and calls `render($throwable)` directly. A stub `final class StubDomainException extends DomainException` (default code/status) and a `final class StubQuotaException extends DomainException` (overrides `$errorCode = 'STUB_QUOTA'`, `$statusCode = 422`) are declared at the top of the file.

**TC-1:** ValidationException is normalized
- **Given:** `ValidationException::withMessages(['email' => ['The email field is required.']])`
- **When:** `render()` is called with it
- **Expect:** status `422`; JSON `code === "VALIDATION_EXCEPTION"`; `message` equals the exception's `getMessage()`; `errors` equals `$e->errors()`

**TC-2:** AuthenticationException is normalized
- **Given:** `new AuthenticationException('Unauthenticated.')`
- **When:** `render()`
- **Expect:** status `401`; `code === "AUTHENTICATION_EXCEPTION"`; `message === "Unauthenticated."`; no `errors` key

**TC-3:** AuthorizationException is normalized
- **Given:** `new AuthorizationException('This action is unauthorized.')`
- **When:** `render()`
- **Expect:** status `403`; `code === "AUTHORIZATION_EXCEPTION"`

**TC-4:** ModelNotFoundException does not leak the model class
- **Given:** `(new ModelNotFoundException)->setModel(App\Models\User::class, [1])`
- **When:** `render()`
- **Expect:** status `404`; `code === "NOT_FOUND_EXCEPTION"`; `message === "Resource not found."`; body contains neither `"User"` nor `"App\\Models"`

**TC-5:** NotFoundHttpException is normalized
- **Given:** `new NotFoundHttpException`
- **When:** `render()`
- **Expect:** status `404`; `code === "NOT_FOUND_EXCEPTION"`; `message === "Resource not found."`

**TC-6:** MethodNotAllowedHttpException is normalized
- **Given:** `new MethodNotAllowedHttpException(['GET'])`
- **When:** `render()`
- **Expect:** status `405`; `code === "METHOD_NOT_ALLOWED_EXCEPTION"`

**TC-7:** TokenMismatchException is normalized
- **Given:** `new TokenMismatchException('CSRF token mismatch.')`
- **When:** `render()`
- **Expect:** status `419`; `code === "CSRF_TOKEN_MISMATCH"`

**TC-8:** ThrottleRequestsException preserves headers
- **Given:** `new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => 60, 'X-RateLimit-Limit' => 6])`
- **When:** `render()`
- **Expect:** status `429`; `code === "RATE_LIMIT_EXCEPTION"`; response has header `Retry-After: 60` and `X-RateLimit-Limit: 6`

**TC-9:** Generic HttpException falls back by status
- **Given:** `new HttpException(503, 'Down for maintenance.')`
- **When:** `render()`
- **Expect:** status `503`; `code === "HTTP_EXCEPTION"`; `message === "Down for maintenance."`

**TC-10:** Base DomainException uses its defaults
- **Given:** `new StubDomainException('This routine is archived.')`
- **When:** `render()`
- **Expect:** status `409`; `code === "DOMAIN_EXCEPTION"`; `message === "This routine is archived."`; no `errors` key

**TC-11:** DomainException subclass overrides code and status
- **Given:** `new StubQuotaException('You already have an active routine.')`
- **When:** `render()`
- **Expect:** status `422`; `code === "STUB_QUOTA"`; `message === "You already have an active routine."`

**TC-12:** Unknown Throwable with `APP_DEBUG=false` becomes SERVER_EXCEPTION
- **Given:** `config(['app.debug' => false])`; `new RuntimeException('Redis connection refused at 10.0.0.5.')`
- **When:** `render()`
- **Expect:** status `500`; `code === "SERVER_EXCEPTION"`; `message === "Server error."`; body does not contain `"Redis"` or `"10.0.0.5"`

**TC-13:** Unknown Throwable with `APP_DEBUG=true` is still wrapped, with debug detail
- **Given:** `config(['app.debug' => true])`; `new RuntimeException('boom')`
- **When:** `render()`
- **Expect:** status `500`; `data.code === "SERVER_EXCEPTION"`; `data.message === "boom"`; `data.exception === RuntimeException::class`; `data` has keys `file`, `line`, `trace`

### `tests/Feature/ApiExceptionHandlingTest.php`

Drives real HTTP through the kernel so the `bootstrap/app.php` wiring is covered. Registers the two test-only routes with `Route::get('/api/v1/_test/domain', ...)` / `Route::get('/api/v1/_test/boom', ...)` inside a `beforeEach`.

**TC-14:** Unauthenticated protected endpoint returns the envelope
- **Given:** no session
- **When:** `getJson('/api/v1/user')`
- **Expect:** `401`; `assertJsonPath('code', 'AUTHENTICATION_EXCEPTION')`; `assertJsonMissingPath('errors')`

**TC-15:** Wrong login credentials return the envelope, no field errors
- **Given:** a `User` with a known password
- **When:** `postJson('/api/v1/login', ['email' => …, 'password' => 'wrong'])`
- **Expect:** `401`; `assertJsonPath('code', 'AUTHENTICATION_EXCEPTION')`; `assertJsonPath('message', 'These credentials do not match our records.')`; `assertJsonMissingPath('errors')`

**TC-16:** Validation failure keeps `message` + `errors` and adds `code`
- **Given:** —
- **When:** `postJson('/api/v1/register', [])`
- **Expect:** `422`; `assertJsonPath('code', 'VALIDATION_EXCEPTION')`; `assertJsonValidationErrors(['name', 'email', 'password'])`; `assertJsonStructure(['code', 'message', 'errors'])`

**TC-17:** *(removed)* — an end-to-end rate-limit test cannot be isolated in this suite: Laravel's unnamed `throttle:N,1` keys its bucket by domain + IP only, so any throttled route shares one counter with `login` / `register`, and hitting it corrupts the pre-existing throttle-count tests. `RATE_LIMIT_EXCEPTION` + `Retry-After` preservation is covered by TC-8 at the renderer level.

**TC-18:** Unknown `/api/v1` path returns the envelope
- **Given:** —
- **When:** `getJson('/api/v1/does-not-exist')`
- **Expect:** `404`; `assertJsonPath('code', 'NOT_FOUND_EXCEPTION')`; `assertJsonPath('message', 'Resource not found.')`

**TC-19:** A thrown `DomainException` is rendered end to end
- **Given:** the `/api/v1/_test/domain` route throws `new StubDomainException('This routine is archived.')`
- **When:** `getJson('/api/v1/_test/domain')`
- **Expect:** `409`; `assertExactJson(['code' => 'DOMAIN_EXCEPTION', 'message' => 'This routine is archived.'])`

**TC-20:** An unhandled Throwable is masked when `APP_DEBUG=false`
- **Given:** `config(['app.debug' => false])`; the `/api/v1/_test/boom` route throws `new RuntimeException('internal detail')`
- **When:** `getJson('/api/v1/_test/boom')`
- **Expect:** `500`; `assertJsonPath('data.code', 'SERVER_EXCEPTION')`; `assertJsonPath('data.message', 'Server error.')`; `assertJsonMissingPath('data.trace')`; response body does not contain `'internal detail'`

**TC-20b:** An unhandled Throwable stays wrapped when `APP_DEBUG=true`
- **Given:** `config(['app.debug' => true])`; the `/api/v1/_test/boom` route throws `new RuntimeException('internal detail')`
- **When:** `getJson('/api/v1/_test/boom')`
- **Expect:** `500`; `assertJsonPath('data.code', 'SERVER_EXCEPTION')`; `assertJsonPath('data.message', 'internal detail')`; `assertJsonStructure(['data' => ['code', 'message', 'exception', 'file', 'line', 'trace']])`; `assertJsonMissingPath('message')` (nothing at the root)

**TC-21:** A non-JSON request is untouched
- **Given:** `config(['app.debug' => false])`; a non-`api/*` route `/_test/boom-web` that throws
- **When:** `get('/_test/boom-web')` with header `Accept: text/html`
- **Expect:** the `bootstrap/app.php` callback returns `null` before the renderer; Laravel's default HTML handler runs; `Content-Type` is not `application/json`

### Regression

**TC-22:** No new failures versus the baseline
- **Given:** the suite's pre-existing failures on the untouched branch base (isolation bug, see §1), captured with Redis flushed
- **When:** `vendor/bin/pest` on this branch, Redis flushed
- **Expect:** the failure set is a subset of the baseline — the four `assertExactJson` tests listed in §1 now pass, the 21 new tests pass, and no previously green test turns red. `tests/Feature/Profile/*` and `tests/Unit/*` untouched and green.

---

## 9. Technical Decisions

The base class and an example subclass (the subclass is **not** built in this change — shown only as the pattern):

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

// thrown from a Service guard, per CLAUDE.md:
// throw_if($user->athleteProfile()->doesntExist(), new ProfileIncompleteException());
```

Data flow: a `Throwable` (framework or `DomainException` subclass) reaches the `$exceptions->render(...)` callback in `bootstrap/app.php`. That callback returns `null` for a non-`api/*`, non-`expectsJson()` request (Laravel's default rendering then runs); otherwise it calls `ApiExceptionRenderer::render()`, which always returns a `JsonResponse` of `{ "data": { code, message, errors? } }` with any `HttpException` headers preserved.

| Decision area | What was decided | Why |
|---|---|---|
| Envelope shape | `{ data: { code, message } }`; `data.errors` added only for `VALIDATION_EXCEPTION`; no `details` field | Every response body in the API is wrapped in `data` (success via Resources, errors via the renderer) for one consistent shape. `assertJsonValidationErrors` takes `'data.errors'` as its response key; `assertExactJson` calls on error bodies were re-nested |
| `code` format | `SCREAMING_SNAKE_CASE` string | Requested by the product owner |
| Domain error identity | For a domain rule, `code` **is** the specific identifier (`ROUTINE_ARCHIVED`, `CYCLE_NOT_DRAFT`, …); `DOMAIN_EXCEPTION` is only the base-class fallback | A client branches on `code`, never on prose |
| Framework exception mapping | `ValidationException` → `VALIDATION_EXCEPTION` / 422 (+ `errors`); `AuthenticationException` → `AUTHENTICATION_EXCEPTION` / 401; `AuthorizationException` + `AccessDeniedHttpException` → `AUTHORIZATION_EXCEPTION` / 403; `ModelNotFoundException` + `NotFoundHttpException` → `NOT_FOUND_EXCEPTION` / 404 with a generic message; `MethodNotAllowedHttpException` → `METHOD_NOT_ALLOWED_EXCEPTION` / 405; `TokenMismatchException` → `CSRF_TOKEN_MISMATCH` / 419; `ThrottleRequestsException` → `RATE_LIMIT_EXCEPTION` / 429; any other `HttpExceptionInterface` → `HTTP_EXCEPTION` / its own status; everything else → `SERVER_EXCEPTION` / 500 | One shape for every error response; existing status codes are preserved |
| Scope of normalization | Every error response under `api/*` or `expectsJson()`, framework exceptions included | The catalog is only meaningful if it is exhaustive |
| Base `DomainException` HTTP status | 409 Conflict by default; subclass overrides via a `protected int $statusCode` property | Separates "malformed input" (422) from "the resource's state forbids this" (409) |
| `DomainException` is `abstract` | Yes — no ad-hoc `throw new DomainException(...)` | Forces a named subclass so every domain `code` is meaningful (decision above) |
| String code vs SPL `$code` | Expose the string through `errorCode()`; leave SPL's `int $code` alone | `Throwable::$code` is typed `int`; overloading it fights the language |
| Concrete subclasses in this change | None — infrastructure only; `ProfileIncompleteException` is documented as the example | `Routine` / `Cycle` / `Session` do not exist; a speculative unused class violates `CLAUDE.md` rules 5–6 |
| Where the mapping lives | A dedicated `final class App\Exceptions\ApiExceptionRenderer` with one method `render(Throwable): JsonResponse`, invoked from a single `$exceptions->render(...)` callback (the callback owns the non-JSON `null` short-circuit) | Encapsulated and testable in isolation; it *is* the feature, not indirection (rule 6). The exception handler is the one place `CLAUDE.md` rule 3 sanctions hand-built error JSON — no error "Resource" (Resources are for success bodies) |
| Non-JSON requests | Renderer returns `null` when `! $request->is('api/*') && ! $request->expectsJson()`, before any mapping | The API is JSON-only, but a stray browser hit should still get Laravel's default, not a bare JSON blob |
| `APP_DEBUG=true` + unknown `Throwable` | Renderer still returns the `data`-wrapped envelope, with the real `message` plus `exception` / `file` / `line` / `trace` under `data` | The `data` wrapper is unconditional — there is no unwrapped error path. Local debugging keeps the full detail, just nested |
| Header preservation | For any `HttpExceptionInterface`, merge `$e->getHeaders()` into the `JsonResponse` | `Retry-After` on 429 and `Allow` on 405 must survive |
| HTTP status constants | `Symfony\Component\HttpFoundation\Response::HTTP_*` | Already the convention in the codebase (`StoreRoutineController` example in `CLAUDE.md`) |
| Test location | Both files at `tests/Feature/` root, next to `ArchTest.php`; renderer exercised through a booted app (matches the project keeping `*ActionTest` under `Feature`, since Pest only binds `TestCase` there) | Product-owner preference; `ApiExceptionRenderer` needs the container for `response()` / `config()` |
| Test-only routes | Declared inside the test files via `Route::get(...)`, never in `routes/api.php` | The `DomainException` and `SERVER_EXCEPTION` branches have no real trigger until later domains exist |
| Scramble / OpenAPI | Register the `{ code, message, errors? }` error schema once via `Scramble::extendOpenApi()` (or `config/scramble.php`); last task, may be dropped if it fights inference | `/docs/api` should show the real error shape; the custom renderer is invisible to static analysis |

---

## 10. Work Plan

| # | Task | Definition of Done |
|---|---|---|
| 1 | Create `app/Exceptions/DomainException.php` — `abstract class DomainException extends RuntimeException` with `protected string $errorCode = 'DOMAIN_EXCEPTION'`, `protected int $statusCode = Response::HTTP_CONFLICT`, and public `errorCode(): string` / `statusCode(): int` accessors. Complete PHPDoc block. | File exists; `vendor/bin/pint app/Exceptions/DomainException.php --test` and `vendor/bin/phpstan analyse` are clean |
| 2 | Create `app/Exceptions/ApiExceptionRenderer.php` — `final class` with `render(Throwable $e): JsonResponse` (never null). Type-check ladder in the §9 order; `HttpExceptionInterface` → `status ⇒ code` lookup with `HTTP_EXCEPTION` default; `SERVER_EXCEPTION` / 500 fallback — `"Server error."` normally, or the real message plus `exception`/`file`/`line`/`trace` when `config('app.debug')`. Body always `['data' => ['code' => …, 'message' => …, …]]`; add `errors` only for `ValidationException`; merge `$e->getHeaders()` for any `HttpException`. Generic message for `NOT_FOUND_EXCEPTION` (`"Resource not found."`). | Pint + PHPStan clean; every branch reachable by a TC in §8 |
| 3 | Wire `bootstrap/app.php` — inside the existing `withExceptions` closure add `$exceptions->render(function (Throwable $e, Request $request) { if (! $request->is('api/*') && ! $request->expectsJson()) { return null; } return app(ApiExceptionRenderer::class)->render($e); });`. Leave `shouldRenderJsonWhen` in place. | `docker compose exec app vendor/bin/pest` still boots; no controller/route/service touched |
| 4 | Add `tests/Feature/ApiExceptionRendererTest.php` with the two stub subclasses and TC-1 … TC-13. | `vendor/bin/pest tests/Feature/ApiExceptionRendererTest.php` green |
| 5 | Add `tests/Feature/ApiExceptionHandlingTest.php` with the two test-only routes and TC-14 … TC-21. | `vendor/bin/pest tests/Feature/ApiExceptionHandlingTest.php` green |
| 6 | Update `CLAUDE.md` **and** `AGENTS.md` (identical): add an "Errors" subsection under "The pipeline" (the envelope, the §9 catalog table, and "to add a domain error: extend `DomainException` in `app/Exceptions/{Domain}/`, set `$errorCode` / `$statusCode` / a default message, throw it from a Service guard"); add `Exceptions/DomainException.php` + `Exceptions/{Domain}/…Exception.php` to the layout tree. | `diff -q CLAUDE.md AGENTS.md` reports no difference; both mention the envelope |
| 7 | *(Optional, last)* Register the error schema with Scramble — `Scramble::extendOpenApi(...)` in `AppServiceProvider::boot()` or `config/scramble.php` — so `GET /docs/api` documents `{ code, message, errors? }`. Drop if it conflicts with inference. | `GET /docs/api.json` shows the error response schema, or the task is explicitly skipped with a one-line note |
| 8 | Run `docker compose exec app composer check` (Pint `--test` + PHPStan + full Pest). | All three pass; TC-22 holds — no pre-existing test modified |

---
