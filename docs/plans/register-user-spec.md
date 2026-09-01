# User registration — `POST /api/v1/register`

> Derived from the Notion ticket "Registrarse en la aplicación" (Feature: Auth &
> perfil · MVP · Must) and the approved plan. Base contract:
> `docs/product-context.md` and `docs/plans/data-model.md`.

## 1. Context

**Kind:** Greenfield Feature

**Stack:** PHP 8.5 · Laravel 13 · PostgreSQL 17 (runtime) / SQLite `:memory:` (tests) ·
Pest 4 (`pest-plugin-laravel`) · `laravel/sanctum` 4 (new dependency) ·
`spatie/laravel-data` 4.23 · Pint · Larastan level 6. Everything runs in Docker.

**Problem statement:** The project has no authentication and no API endpoints. We
need the first endpoint: a visitor creates their account with `name`, `email` and
`password`, and is authenticated by session (cookie) in the same response, so they
can start using the app with their own data. This ticket also stands up the API +
auth infrastructure from zero (`/api/v1` routing, Sanctum in SPA mode, CORS with
credentials) that login, logout and profile will build on.

**In scope:**
- Public `POST /api/v1/register` endpoint following the mandatory `CLAUDE.md`
  pipeline (Form Request → invokable Controller → Action → JSON Resource).
- Install and wire `laravel/sanctum` in **SPA / stateful cookie mode** (no tokens).
- Wire the `api` route group with the `/api/v1` prefix in `bootstrap/app.php`.
- `config/cors.php` with `supports_credentials`.
- Email normalisation (lowercase + trim) before the uniqueness check.
- Session login after sign-up (`Auth::login` + session regeneration).
- Rate limiting `throttle:6,1` on the route.
- Pest feature suite covering the four acceptance criteria + negative cases.

**Out of scope:**
- Login, logout, session refresh, password reset — separate tickets.
- Email verification (`email_verified_at` stays `null`; no `Registered` event).
- Token authentication / `HasApiTokens` / `personal_access_tokens` table.
- Athlete profile, routines and any other domain table: the freshly created user
  has nothing beyond its `users` row (AC #4 is satisfied by construction).
- `GET /api/v1/user` or `/profile` endpoint.
- Enabling `security_strategy` in `config/scramble.php` (deferred until the first
  protected route exists).
- The `gym-trainer-spa/` frontend (separate repository).
- Transactional handling of the duplicate-email race under concurrency (see §9).

---

## 2. API Surface

### 2.1 REST

| Method | Path | Auth | Request | Response | Status codes |
|---|---|---|---|---|---|
| POST | `/api/v1/register` | None (public route). Subject to `EnsureFrontendRequestsAreStateful` + CSRF because it sits in the stateful `api` group, and to `throttle:6,1`. | JSON: `name` (string, required, ≤255), `email` (string, required, email, ≤255, unique), `password` (string, required, ≥8, `confirmed`), `password_confirmation` (string, required, == `password`) | `201`: `{ "data": { "name": string, "email": string, "created_at": string ISO-8601 } }`. The response sets the session cookie (`config('session.cookie')`) and refreshes `XSRF-TOKEN`. | `201` created and session started · `422` validation (email already registered → error on `email`; password unconfirmed or `<8` → error on `password`; `name`/`email`/`password` missing or malformed) · `429` rate limit exceeded · `419` stateful request without a valid CSRF token (client did not call `GET /sanctum/csrf-cookie` first) |

Notes:
- The body **does not** expose `id`. `docs/plans/data-model.md` states that `users`
  carries no `uuid` in v1 and that the `bigint` PK never crosses the API boundary
  ("the API only ever operates on the authenticated user, never by id").
- `GET /sanctum/csrf-cookie` is registered by `SanctumServiceProvider` at the app
  root (not under `/api/v1`). It is not defined by hand. A real browser client
  must call it before the `POST`.
- Errors are rendered as JSON by the exception handler (already configured in
  `bootstrap/app.php` for `api/*`). No hand-built JSON.

### 2.2 CLI

Not applicable — no CLI commands.

### 2.3 Events

| Event name | Producer | Consumer | Payload | Trigger condition |
|---|---|---|---|---|
| `Illuminate\Auth\Events\Login` | `Auth::login($user)` inside `UserRegisterAction` | None (no project listeners) | guard (`web`), `User`, `remember` (false) | Fired automatically when the session starts after sign-up |

`Illuminate\Auth\Events\Registered` is **not** fired: it only does anything with
`User implements MustVerifyEmail` + a listener, and email verification is out of
scope. It is a one-line addition to the Action if introduced later.

---

## 3. UI

### 3.1 Pages

Not applicable — no pages affected. This is a JSON REST API; the frontend lives in
the `gym-trainer-spa/` repository, outside this ticket.

### 3.2 Components

Not applicable — no components affected.

---

## 4. Database

Not applicable — no data or schema changes. The endpoint uses the stock `users`,
`sessions` and `password_reset_tokens` tables (created by
`0001_01_01_000000_create_users_table.php`) as-is. `php artisan install:api`
publishes a `*_create_personal_access_tokens_table.php` migration that is
**deleted without running** (SPA-only mode never issues tokens). Result: this
feature adds **zero migrations**, so the `CLAUDE.md` database-isolation workflow
(cloning `gym_trainer`) does not apply.

### 4.1 Schema changes

Not applicable — no schema changes.

### 4.2 Seeds

Not applicable — no seeds.

---

## 5. Auth & Authorization

### 5.1 Authentication

**Method:** Session (cookie) via **Laravel Sanctum in SPA / stateful mode**.

Flow:
1. `install:api --stateful` adds `$middleware->statefulApi()` in
   `bootstrap/app.php`, which prepends `EnsureFrontendRequestsAreStateful` to the
   `api` group. For a request whose `Origin`/`Referer` host is in
   `config('sanctum.stateful')`, that middleware injects the `web` group
   (`EncryptCookies`, `StartSession`, `ValidateCsrfToken`,
   `AddQueuedCookiesToResponse`).
2. `UserRegisterAction` creates the `User`, calls `Auth::login($user)` (default
   `web` guard), then `request()->session()->regenerate()`.
3. `StartSession` / `AddQueuedCookiesToResponse` emit the session cookie and the
   refreshed `XSRF-TOKEN` on the `201` response → the user is authenticated for
   subsequent requests with no separate `/login` call (AC #1).

Configuration decisions:
- **No `api` guard** in `config/auth.php`. SPA auth rides the existing `web`
  session guard. `config('sanctum.guard')` = `['web']` (default).
- **No `HasApiTokens`** on the `User` model.
- `config('sanctum.expiration')` = `null` (lifetime is the session's).
- CORS: `supports_credentials => true` and an explicit `allowed_origins` (never
  `*` with credentials) — see §6.
- In the test environment, `ValidateCsrfToken` auto-bypasses
  (`runningUnitTests()`), but the stateful domain must be configured so the
  session initialises (see §6 and §8).

### 5.2 Authorization

Not applicable — no authorization changes. The route is public: no actor, no
resource, no Policy. It is the **explicit exception** to `CLAUDE.md` rule 4
("every data route: `auth:sanctum` + a Policy") — documented as such in
`routes/api.php`. `RegisterRequest::authorize()` returns `true`.

---

## 6. Configuration

**Environment variables** (`.env.example` + local `.env`):

| Variable | Value / Source | Purpose |
|---|---|---|
| `FRONTEND_URL` | `http://localhost:5173` | Allowed origin in CORS (`config/cors.php` → `allowed_origins`). |
| `SANCTUM_STATEFUL_DOMAINS` | `localhost:5173,localhost:8000,127.0.0.1:5173,127.0.0.1:8000` | Hosts whose requests are treated as stateful (session + CSRF). |
| `SESSION_DOMAIN` | `localhost` (dev). Prod: real registrable domain, e.g. `.example.com` | Session cookie scope. Currently `null`. |
| `AUTH_GUARD` | **Not added** (or commented, value `web`) | `Auth::login()` targets `config('auth.defaults.guard')`; setting it to `sanctum` would silently break stateful login. |

**Prod (document, not touched in this ticket):** `SESSION_SECURE_COOKIE=true`,
HTTPS, `SESSION_SAME_SITE=lax` (review), `SESSION_DOMAIN` set to the real domain.

**Test env** (`phpunit.xml`):

| Variable | Value | Purpose |
|---|---|---|
| `SANCTUM_STATEFUL_DOMAINS` | `localhost` | Without it, `postJson` (no `Origin`) does not trigger the `web` group, `StartSession` never runs, and `session()->regenerate()` in the Action throws `RuntimeException: Session store not set on request`. |
| `APP_URL` | `http://localhost` | Determinism: the `Origin` header the tests send and `SANCTUM_STATEFUL_DOMAINS` must agree on host. |

**Config files modified:**

| File | Change |
|---|---|
| `composer.json` / `composer.lock` | `require laravel/sanctum ^4`. |
| `bootstrap/app.php` | `withRouting(...)`: add `api: __DIR__.'/../routes/api.php'` and `apiPrefix: 'api/v1'`. `withMiddleware(...)`: `$middleware->statefulApi();`. `withExceptions(...)` unchanged. |
| `routes/api.php` | New. One route: `Route::post('register', RegisterController::class)->middleware('throttle:6,1')->name('auth.register');` with a `use` for the class (PHPStan analyses `routes/`). |
| `config/cors.php` | Publish. `paths => ['api/*', 'sanctum/csrf-cookie']`; `allowed_methods => ['*']`; `allowed_origins => [env('FRONTEND_URL', 'http://localhost:5173')]`; `allowed_headers => ['*']`; `supports_credentials => true`. |
| `config/sanctum.php` | Publish. Keep visible `stateful` (from `SANCTUM_STATEFUL_DOMAINS`), `guard => ['web']`, `expiration => null`. Rest stock. |
| `config/auth.php` | No change. |
| `config/scramble.php` | No change (`security_strategy => null`). |
| `tests/Pest.php` | Uncomment `->use(RefreshDatabase::class)` (scope `Feature`). |
| `phpunit.xml` | Add the two `<env>` entries from the test table. |
| `database/migrations/*_create_personal_access_tokens_table.php` | **Delete** (published by `install:api`, not run). |

---

## 7. Current vs New Behavior

| Behavior | Current | New |
|---|---|---|
| User sign-up | Does not exist. `users` is only populated by `DatabaseSeeder` / factory. | `POST /api/v1/register` creates the account, starts a cookie session and returns `201` with `{ data: { name, email, created_at } }`. |
| API routing | `bootstrap/app.php` only registers `web`, `console`, `health`. No `routes/api.php`, no `/api` prefix. | `api` group registered with the `/api/v1` prefix and `statefulApi()` middleware. |
| Authentication | None. `config/auth.php` is stock (`web` guard unused). Sanctum not installed. | Sanctum SPA (cookie + CSRF) over the `web` guard. `GET /sanctum/csrf-cookie` available. |
| CORS | No `config/cors.php` (framework defaults, no credentials). | `config/cors.php` published with `supports_credentials => true` and an explicit origin. |
| Email normalisation | N/A | Email lowercased + trimmed in `prepareForValidation()` before the `unique` check; persisted normalised. |
| Rate limiting | The `api` group does not exist, no throttle. | `throttle:6,1` on the registration route (`429` on exceed). |
| DB-touching tests | `RefreshDatabase` commented out in `tests/Pest.php`; only `ExampleTest`. | `RefreshDatabase` active for `Feature`; suite `tests/Feature/Auth/RegisterTest.php`. |

---

## 8. Test Cases

Executable with Pest 4 on SQLite `:memory:` (`RefreshDatabase`). `beforeEach` sets
`$this->withHeader('Origin', config('app.url'))` so the request is stateful. Base
valid payload: `name` = `"Ada Lovelace"`, `email` = `"ada@example.com"`,
`password` = `password_confirmation` = `"secret-password"`.

**TC-1:** A valid sign-up creates the user and authenticates them
- **Given:** no user with `email` `ada@example.com`
- **When:** `POST /api/v1/register` with the base valid payload
- **Expect:** `201`; `assertAuthenticated()` (`web` guard); `assertDatabaseHas('users', ['email' => 'ada@example.com', 'name' => 'Ada Lovelace'])`; `assertDatabaseCount('users', 1)`; `Hash::check('secret-password', User::first()->password)` is `true`; `assertJsonPath('data.email', 'ada@example.com')` and `assertJsonPath('data.name', 'Ada Lovelace')`

**TC-2:** The sign-up response sets the session cookie
- **Given:** no user with that email
- **When:** `POST /api/v1/register` with the base valid payload
- **Expect:** `201`; `assertCookie(config('session.cookie'))` (or a `Set-Cookie` header present)

**TC-3:** The email is normalised to lowercase and persisted normalised
- **Given:** no user with that email
- **When:** `POST /api/v1/register` with `email` = `"  Ada@Example.COM  "`
- **Expect:** `201`; `assertDatabaseHas('users', ['email' => 'ada@example.com'])`; `assertJsonPath('data.email', 'ada@example.com')`

**TC-4:** Already-registered email → `422` with an error on `email`
- **Given:** `User::factory()->create(['email' => 'taken@example.com'])`
- **When:** `POST /api/v1/register` with `email` = `"taken@example.com"` and the rest of the valid payload
- **Expect:** `422`; `assertJsonValidationErrors('email')`; `assertDatabaseCount('users', 1)`; `assertGuest()`

**TC-5:** Already-registered email with different casing → `422` with an error on `email`
- **Given:** `User::factory()->create(['email' => 'taken@example.com'])`
- **When:** `POST /api/v1/register` with `email` = `"TAKEN@example.com"`
- **Expect:** `422`; `assertJsonValidationErrors('email')`; `assertDatabaseCount('users', 1)`

**TC-6:** Password that does not match its confirmation → `422` on `password`
- **Given:** no user with that email
- **When:** `POST /api/v1/register` with `password` = `"secret-password"` and `password_confirmation` = `"other-password"`
- **Expect:** `422`; `assertJsonValidationErrors('password')`; `assertDatabaseCount('users', 0)`; `assertGuest()`

**TC-7:** Password shorter than the minimum (8) → `422` on `password`
- **Given:** no user with that email
- **When:** `POST /api/v1/register` with `password` = `password_confirmation` = `"short"`
- **Expect:** `422`; `assertJsonValidationErrors('password')`; `assertGuest()`

**TC-8:** Missing `name` → `422` on `name`
- **Given:** no user with that email
- **When:** `POST /api/v1/register` without `name`
- **Expect:** `422`; `assertJsonValidationErrors('name')`

**TC-9:** Missing or malformed email → `422` on `email` (dataset: `null`, `"not-an-email"`)
- **Given:** no user with that email
- **When:** `POST /api/v1/register` with each dataset value as `email`
- **Expect:** `422`; `assertJsonValidationErrors('email')`

**TC-10:** Missing `password` → `422` on `password`
- **Given:** no user with that email
- **When:** `POST /api/v1/register` without `password` or `password_confirmation`
- **Expect:** `422`; `assertJsonValidationErrors('password')`

**TC-11:** The response does not expose the internal id
- **Given:** no user with that email
- **When:** `POST /api/v1/register` with the base valid payload
- **Expect:** `201`; `assertJsonMissingPath('data.id')`; `assertJsonStructure(['data' => ['name', 'email', 'created_at']])`

**TC-12:** A freshly registered user has no athlete profile and no routines
- **Given:** no user with that email
- **When:** `POST /api/v1/register` with the base valid payload
- **Expect:** `201`; `assertDatabaseCount('users', 1)`; the body includes no `profile` or `routines`. Comment in the test: AC #4 holds by construction (registration creates nothing beyond the `users` row); this case will gain real assertions (`$user->athleteProfile()->exists()` false, `$user->routines()->count() === 0`) once the Profile / Routine domains exist.

**TC-13:** Rate limiting on the registration route
- **Given:** no prior user
- **When:** 7 consecutive `POST /api/v1/register` from the same IP (distinct emails)
- **Expect:** the first 6 responses are `201`/`422` (not `429`); the 7th is `429`

**TC-14 (arch, optional — outside the AC):** pipeline conventions
- **Given:** the project code
- **When:** Pest architecture assertions run
- **Expect:** `App\Actions\*` is `final` and has a `handle` method; `App\Http\Controllers\Auth\*` is invokable; `App\Http\Requests\*` extends `FormRequest`; `dd`/`dump`/`ray` are not used in `app/`

---

## 9. Technical Decisions

| Decision area | What was decided | Why |
|---|---|---|
| Path / versioning | `POST /api/v1/register` with a global `apiPrefix: 'api/v1'` | `CLAUDE.md` rule 1 requires `/api/v1`. The ticket ("`POST /api/register`") is read as "the registration route", not a literal path. |
| `name` field | Required in the request (`string`, `max:255`) | The stock `users.name` column is NOT NULL; requiring it avoids a migration and matches a typical sign-up screen. |
| Auth mechanism | `laravel/sanctum` in SPA / stateful cookie mode; no tokens; no `HasApiTokens` | `docs/product-context.md` and `docs/plans/data-model.md` fix "Sanctum SPA mode (cookie + CSRF)". AC #1 asks for a cookie session. |
| Guard | Reuse the `web` session guard; no `api` guard in `config/auth.php` | Sanctum SPA auth rides the session; `config('sanctum.guard')` = `['web']` by default. Adding a guard would be useless indirection (rule 6). |
| Service layer | **No** Service; the Action calls `User::create()` directly | No business knowledge: uniqueness = `unique:` rule (Form Request), hashing = the model's `hashed` cast, "no profile/routines" = do nothing. `CLAUDE.md` rule 6 + the `RoutineCreateAction` example. |
| Where `Auth::login()` + `session()->regenerate()` go | In `UserRegisterAction`, after `User::create()` | The Action is the only layer that fires side effects/events (`Login`); "create + authenticate" is one indivisible use case. The controller keeps the 201 status and the shape (Resource). Uses the `request()` helper, not an injected `Request`. |
| `Registered` event | Not fired in v1 | Without `MustVerifyEmail` or a listener it does nothing; email verification is out of scope (rule 5). `Auth::login()` already fires `Login`. |
| Resource contents | `UserResource` exposes `name`, `email`, `created_at` (ISO-8601). **No `id`**. Located at `app/Http/Resources/Auth/` | `docs/plans/data-model.md`: `users` has no `uuid` in v1 and the `bigint` PK does not cross the API. `created_at` as an explicit string so Scramble infers `type: string`. |
| DTO | `App\Data\Auth\RegisterData` (`spatie/laravel-data`), readonly `name/email/password`, no `password_confirmation`; `RegisterData::from($request->validated())` | `CLAUDE.md` convention (writes take a `Data` object). `validation_strategy` = `OnlyRequests` → building from the validated array does not re-validate; the Form Request is the single validation authority. |
| Password rule | `Password::min(8)` (length only; no complexity, no HIBP) and no global configuration | AC #3 only asks for "a minimum length"; 8 is Laravel's standard floor. Can be hardened in the login ticket. |
| Email normalisation | `prepareForValidation()` lowercases + `trim`s before the `unique` check; persisted normalised | Avoids duplicate accounts from casing; `Ada@X.com` collides with `ada@x.com` → `422`. |
| Rate limiting | `throttle:6,1` on the route | Public, unauthenticated, write endpoint: an abuse target. Value aligned with Laravel's default for auth routes. |
| Duplicate-email race | Validation only (`unique:` + the unique index). Accept that, under two concurrent requests with the same email, one may return `500` (unique violation 23505) instead of `422`. Documented risk. | In v1 (low traffic) the exact collision is unlikely; catching the `QueryException` in the Action adds complexity not justified now. Can be hardened if traffic demands it. |
| `personal_access_tokens` migration | Delete the one published by `install:api` without running it → zero migrations | SPA-only mode never issues tokens; Sanctum 4 does not auto-load package migrations. Avoids the `CLAUDE.md` DB-cloning workflow. |
| CORS | `config/cors.php` published: `supports_credentials => true`, explicit `allowed_origins` (never `*`), `paths` with `api/*` and `sanctum/csrf-cookie` | Without credentials the browser drops the `Set-Cookie` from the registration response. The CORS spec forbids `*` with credentials. |
| Scramble `security_strategy` | No change (`null`) | `register` is public; with the strategy off it is already documented as unsecured. It will be enabled (for cookie/apiKey, **not** bearer) when the first protected route exists. |
| Tests: stateful session | `SANCTUM_STATEFUL_DOMAINS=localhost` + `APP_URL=http://localhost` in `phpunit.xml`; `Origin` header in every test | Without a stateful domain the `web` group is not injected and `session()->regenerate()` in the Action blows up. Exercises the real SPA path. CSRF auto-bypasses in tests. |
| Tests: `RefreshDatabase` | Uncomment the global line in `tests/Pest.php` (scope `Feature`) | This is the first DB-touching test; every feature test after it needs it. |
| Commit attribution | Follow `CLAUDE.md`: **no** `Co-Authored-By: Claude` / `Claude-Session:` trailers | `CLAUDE.md` is the repo contract and forbids it explicitly. No trailer check in `.github/`, but the contract governs. (Outside functional scope; noted for the commit phase.) |

---

## 10. Work Plan

The pipeline classes are created before wiring `routes/api.php` (which references
them). The Test Cases are implemented and run last (task 15); the DoD for tasks
8–12 is limited to the artifact existing and passing Pint + PHPStan.

| # | Task | Definition of Done |
|---|---|---|
| 1 | `composer require laravel/sanctum ^4` | `composer.lock` pins Sanctum 4.x; `composer install` in the container without errors. |
| 2 | `php artisan install:api --stateful --no-interaction`, then verify the scaffolding; hand-edit `bootstrap/app.php` if anything is missing | `bootstrap/app.php` has `api:` in `withRouting()` and `$middleware->statefulApi()` in `withMiddleware()`; `routes/api.php` exists; the `*_create_personal_access_tokens_table.php` migration exists. No migrations were run. |
| 3 | Delete `database/migrations/*_create_personal_access_tokens_table.php` | The file does not exist; `php artisan migrate --pretend` does not list that table; the feature adds no migrations. |
| 4 | Adjust `bootstrap/app.php`: `apiPrefix: 'api/v1'` in `withRouting()` | `php artisan route:list` shows the `api` group routes under the `api/v1` prefix. |
| 5 | Publish and edit `config/cors.php` (`supports_credentials => true`, `allowed_origins` from `FRONTEND_URL`, `paths` with `api/*` and `sanctum/csrf-cookie`, `allowed_methods`/`allowed_headers` `['*']`) | The file exists with those values; PHPStan level 6 clean in `config/`. |
| 6 | Publish `config/sanctum.php`, keep `stateful` (from env), `guard => ['web']`, `expiration => null` | The file exists; `config('sanctum.guard')` === `['web']`. |
| 7 | Add `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN` to `.env.example` (and local `.env`) | `.env.example` contains the three keys with the §6 values; `config('sanctum.stateful')` resolves the expected list. |
| 8 | Create `app/Http/Requests/Auth/RegisterRequest.php` (`authorize(): true`; `rules()` for `name` `['required','string','max:255']`, `email` `['required','string','email','max:255','unique:users,email']`, `password` `['required','confirmed', Password::min(8)]`; `prepareForValidation()` lowercases + `trim`s the email) | The file exists; Pint + PHPStan level 6 clean. |
| 9 | Create `app/Data/Auth/RegisterData.php` (readonly `string $name/$email/$password`, no `password_confirmation`) via `make:data`, move to `app/Data/Auth/`, fix the namespace | `RegisterData::from(['name'=>…,'email'=>…,'password'=>…])` builds the DTO; Pint + PHPStan clean. |
| 10 | Create `app/Http/Resources/Auth/UserResource.php` (`toArray()` → `name`, `email`, `created_at` as ISO-8601; no `id`, no relations) | The file exists; Pint + PHPStan clean. |
| 11 | Create `app/Actions/Auth/UserRegisterAction.php` (`final`, `handle(RegisterData $data): User` → `User::create([...])` → `Auth::login($user)` → `request()->session()->regenerate()` → `return $user`; no `DB::transaction`, no `Registered` event) | The file exists; Pint + PHPStan clean. |
| 12 | Create `app/Http/Controllers/Auth/RegisterController.php` (`final`, `__invoke(RegisterRequest $request, UserRegisterAction $action): JsonResponse` → `RegisterData::from($request->validated())` → `$action->handle(...)` → `UserResource::make($user)->response()->setStatusCode(201)`) via `make:controller --invokable`, move and fix the namespace | The file exists; Pint + PHPStan clean. |
| 13 | Write `routes/api.php`: `Route::post('register', RegisterController::class)->middleware('throttle:6,1')->name('auth.register')` with `use App\Http\Controllers\Auth\RegisterController;` | `php artisan route:list` shows `POST api/v1/register` → `RegisterController` with `throttle:6,1` middleware; PHPStan clean in `routes/`. |
| 14 | Uncomment `->use(RefreshDatabase::class)` in `tests/Pest.php`; add `SANCTUM_STATEFUL_DOMAINS=localhost` and `APP_URL=http://localhost` to `phpunit.xml` | `docker compose exec app vendor/bin/pest` starts without the DB `RuntimeException` or `Session store not set on request`; `ExampleTest` still green. |
| 15 | Write `tests/Feature/Auth/RegisterTest.php` with TC-1..TC-13 (`beforeEach` sets `withHeader('Origin', config('app.url'))`) | `docker compose exec app vendor/bin/pest tests/Feature/Auth/RegisterTest.php` all green. |
| 16 | (Optional, outside the AC) Add the arch test TC-14 (`tests/Arch/PipelineTest.php` or a shared arch file) | The arch test passes. |
| 17 | `docker compose exec app composer check` (Pint `--test` + PHPStan level 6 + full Pest) | All three steps green. |
| 18 | Manual check with `curl` against `http://localhost:8000` (`GET /sanctum/csrf-cookie` → `POST /api/v1/register` `201` with `Set-Cookie` → repeated email `422` on `email` → unconfirmed password `422` on `password`); review `GET /docs/api` | The `curl` calls return the expected codes; the endpoint appears in Scramble with the body inferred from `RegisterRequest` and the `201` response from `UserResource`. |
