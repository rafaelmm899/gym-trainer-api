# User login & logout — `POST /api/v1/login`, `POST /api/v1/logout`, `GET /api/v1/user`

> Derived from the Notion ticket "Iniciar y cerrar sesión" (Feature: Auth & perfil
> · MVP · Must · Repo: API) and the approved plan. Base contract:
> `docs/product-context.md`, `docs/plans/data-model.md` and the register spec
> `docs/plans/register-user-spec.md` (which stood up the auth infrastructure).

## 1. Context

**Kind:** Brownfield Feature

**Stack:** PHP 8.5 · Laravel 13 · PostgreSQL 17 (runtime) / SQLite `:memory:` (tests) ·
Pest 4 (`pest-plugin-laravel`) · `laravel/sanctum` 4 · `spatie/laravel-data` 4.23 ·
`dedoc/scramble` · Pint · Larastan level 6. Everything runs in Docker.

**Problem statement:** Registration already authenticates a new user by session
cookie, but a returning user has no way back in and no way out. This ticket adds
the three endpoints that complete session auth: `POST /api/v1/login` (verify
credentials, start a cookie session), `POST /api/v1/logout` (end the session), and
a minimal `GET /api/v1/user` (the authenticated user's identity) — the first
`auth:sanctum`-protected routes, which also lets us switch on Scramble's auth
security scheme. All the plumbing (Sanctum SPA mode, `/api/v1` routing, CORS with
credentials, `GET /sanctum/csrf-cookie`, `UserResource`, `RefreshDatabase`, the
`phpunit.xml` stateful test env) was delivered by the register ticket and is
reused unchanged.

**In scope:**
- Public `POST /api/v1/login` following the mandatory `CLAUDE.md` pipeline
  (Form Request → invokable Controller → Action → JSON Resource), `throttle:6,1`.
- `POST /api/v1/logout` behind `auth:sanctum` — invalidates the session, `204`.
- `GET /api/v1/user` behind `auth:sanctum` — returns the authenticated user via
  the existing `UserResource` (`{ name, email, created_at }`, no `id`).
- Email normalisation (lowercase + trim) on login, mirroring register.
- Generic, field-agnostic failure for bad credentials (`401`, one message for
  "unknown email" and "wrong password" alike).
- Enable `security_strategy` in `config/scramble.php` (cookie scheme, not bearer),
  now that protected routes exist.
- Pest feature suite covering the four acceptance criteria + negative cases, plus
  focused unit tests for the two Actions.

**Out of scope:**
- Password reset / forgot-password, email verification, "magic link".
- Token authentication / `HasApiTokens` / `personal_access_tokens`.
- "Remember me" (no `remember` flag; session lifetime is `config('session.lifetime')`).
- "Log out of all devices" / session listing.
- Session-refresh / sliding-expiration endpoint.
- The athlete-profile endpoint. `GET /api/v1/user` returns identity only; the
  Profile ticket extends it (or adds `/api/v1/profile`) later.
- Hardening the register password policy (`RegisterRequest` keeps `Password::min(8)`).
- Any new `env` var, config publish, or migration — the register ticket added them.
- The `gym-trainer-spa/` frontend (separate repository).

---

## 2. API Surface

### 2.1 REST

| Method | Path | Auth | Request | Response | Status codes |
|---|---|---|---|---|---|
| POST | `/api/v1/login` | None (public route). Subject to `EnsureFrontendRequestsAreStateful` + CSRF because it sits in the stateful `api` group, and to `throttle:6,1`. | JSON: `email` (string, required, email), `password` (string, required) | `200`: `{ "data": { "name": string, "email": string, "created_at": string ISO-8601 } }`. Sets the session cookie (`config('session.cookie')`) and refreshes `XSRF-TOKEN`. | `200` authenticated · `401` credentials do not match (`{ "message": "These credentials do not match our records." }` — identical for unknown email and wrong password) · `422` validation (`email` missing/malformed, `password` missing) · `429` rate limit exceeded · `419` stateful request without a valid CSRF token (client did not call `GET /sanctum/csrf-cookie` first) |
| POST | `/api/v1/logout` | `auth:sanctum` (session, `web` guard). No Policy — acts only on the caller. | — (no body) | `204` No Content. Clears the session cookie / invalidates the session. | `204` session ended · `401` no active session (`{ "message": "Unauthenticated." }`) · `419` missing CSRF token |
| GET | `/api/v1/user` | `auth:sanctum` (session, `web` guard). No Policy — returns only the caller. | — | `200`: `{ "data": { "name": string, "email": string, "created_at": string ISO-8601 } }` | `200` · `401` no active session |

Notes:
- No endpoint exposes `id` — `docs/plans/data-model.md`: `users` carries no `uuid`
  in v1 and the `bigint` PK never crosses the API boundary.
- `GET /sanctum/csrf-cookie` is registered by `SanctumServiceProvider` at the app
  root (not under `/api/v1`); it already exists. A browser client calls it once
  before the first `POST` (login or register) — AC #1.
- Bad credentials are surfaced as `Illuminate\Auth\AuthenticationException` with a
  custom message, thrown from `UserLoginAction`. The exception handler
  (`bootstrap/app.php`, `shouldRenderJsonWhen` matches `api/*`) renders it as
  `{ "message": <message> }` with `401`. No hand-built JSON, no custom exception
  class.
- `401` is chosen over Laravel Breeze's `422`-on-`email` so the response never
  attaches the failure to a field (AC #2) and matches the `401` that `logout` /
  `user` return with no session.

### 2.2 CLI

Not applicable — no CLI commands.

### 2.3 Events

| Event name | Producer | Consumer | Payload | Trigger condition |
|---|---|---|---|---|
| `Illuminate\Auth\Events\Attempting` | `Auth::attempt()` in `UserLoginAction` | None (no project listeners) | guard (`web`), credentials, `remember` (false) | Every login attempt |
| `Illuminate\Auth\Events\Failed` | `Auth::attempt()` on a bad match | None | guard, `User`\|`null`, credentials | Credentials do not match |
| `Illuminate\Auth\Events\Login` | `Auth::attempt()` on success | None | guard (`web`), `User`, `remember` (false) | Credentials match — session starts |
| `Illuminate\Auth\Events\Logout` | `Auth::guard('web')->logout()` in `UserLogoutAction` | None | guard (`web`), `User` | `POST /api/v1/logout` with an active session |

All four are framework-fired and have no project listeners; they are listed for
completeness, not wired to anything.

---

## 3. UI

### 3.1 Pages

Not applicable — no pages affected. JSON REST API; the frontend lives in the
`gym-trainer-spa/` repository, outside this ticket.

### 3.2 Components

Not applicable — no components affected.

---

## 4. Database

Not applicable — no data or schema changes. Login reads the stock `users` table;
logout and login write only the `sessions` table through the framework session
driver. **Zero migrations**, so the `CLAUDE.md` database-isolation workflow
(cloning `gym_trainer`) does not apply.

### 4.1 Schema changes

Not applicable — no schema changes.

### 4.2 Seeds

Not applicable — no seeds.

---

## 5. Auth & Authorization

### 5.1 Authentication

**Method:** Session (cookie) via **Laravel Sanctum in SPA / stateful mode**, over
the existing `web` guard. Unchanged from the register ticket — no new guard, no
`api` guard, no `HasApiTokens`, `config('sanctum.guard')` = `['web']`,
`config('sanctum.expiration')` = `null`.

**Login flow:**
1. The request reaches the stateful `api` group; because its `Origin`/`Referer`
   host is in `config('sanctum.stateful')`, `EnsureFrontendRequestsAreStateful`
   injects the `web` group (`EncryptCookies`, `StartSession`, `ValidateCsrfToken`,
   `AddQueuedCookiesToResponse`).
2. `LoginController` hands `LoginData` to `UserLoginAction`, which calls
   `Auth::attempt(['email' => …, 'password' => …])` on the default (`web`) guard.
3. On a bad match →
   `throw new AuthenticationException('These credentials do not match our records.')`
   (`401`, generic). On success → `request()->session()->regenerate()` (session
   fixation defence) and the Action returns the now-authenticated `User`.
4. `StartSession` / `AddQueuedCookiesToResponse` emit the session cookie and a
   fresh `XSRF-TOKEN` on the `200` response.
5. If a session is already active (a different user), step 2 still runs:
   `Auth::attempt` replaces the authenticated user and step 3 regenerates the id —
   **last login wins**, no guard, no `409`.

**Logout flow:** `UserLogoutAction` calls `Auth::guard('web')->logout()`, then
`request()->session()->invalidate()` and `request()->session()->regenerateToken()`
(the Laravel Breeze `destroy()` sequence). `LogoutController` returns
`response()->noContent()` (`204`).

**`GET /api/v1/user`:** no Action — `CurrentUserController` returns
`UserResource::make($request->user())`. The `auth:sanctum` middleware throws
`AuthenticationException` (`401`) when there is no session.

- In the test environment `ValidateCsrfToken` auto-bypasses (`runningUnitTests()`);
  the stateful domain is already configured in `phpunit.xml`
  (`SANCTUM_STATEFUL_DOMAINS=localhost`, `APP_URL=http://localhost`), so the `web`
  group runs and the session initialises. Tests set the `Origin` header per §8.

### 5.2 Authorization

| Role | Permissions |
|---|---|
| Guest (no session) | `POST /api/v1/login` only. `logout` and `user` → `401`. |
| Authenticated user | `POST /api/v1/logout` and `GET /api/v1/user`, each acting **only on themselves**. `POST /api/v1/login` still allowed (re-authenticates). |

No Policy on any route: `login` is public (no actor), and `logout` / `user`
operate on the authenticated actor itself, never another user's resource — the
same documented exception to `CLAUDE.md` rule 4 that `routes/api.php` already
carries for `register`. `LoginRequest::authorize()` returns `true`.

---

## 6. Configuration

**Environment variables:** none added or changed. `FRONTEND_URL`,
`SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN` (dev) and the `phpunit.xml` test
entries (`SANCTUM_STATEFUL_DOMAINS=localhost`, `APP_URL=http://localhost`) were all
added by the register ticket and already cover login/logout.

**Config files modified:**

| File | Change |
|---|---|
| `config/scramble.php` | Enable `security_strategy`. Replace `'security_strategy' => null` with the `[class, options]` form of `MiddlewareAuthSecurityStrategy` so matched routes carry a **cookie** `apiKey` scheme (session cookie), not the default `bearer`: `['middleware' => ['auth:sanctum'], 'scheme' => SecurityScheme::apiKey('cookie', config('session.cookie'))]`. Confirm the exact `SecurityScheme` factory signature against the installed `dedoc/scramble` version (`composer show dedoc/scramble` / `search-docs`). Routes without `auth:sanctum` (`register`, `login`) stay `security: []`. The scheme is **informational only** — the session cookie is encrypted and dynamically named, so the docs UI "Try it" cannot authenticate with it. |
| `routes/api.php` | Add `POST login` (with `throttle:6,1`) and an `auth:sanctum` group holding `POST logout` + `GET user`; add the three `use` imports (PHPStan analyses `routes/`). |

No `composer` change (no new dependency), no config publish (all already
published), no `.env` / `.env.example` / `phpunit.xml` / `tests/Pest.php` change.

---

## 7. Current vs New Behavior

| Behavior | Current | New |
|---|---|---|
| Returning-user login | No endpoint. A user authenticated only as a side effect of `POST /api/v1/register`; once the session expired there was no way back in. | `POST /api/v1/login` verifies `email` + `password` and starts a cookie session; `200` with `{ data: { name, email, created_at } }`. |
| Logout | No endpoint. The session could only lapse by expiry. | `POST /api/v1/logout` invalidates the session and returns `204`; the session cookie no longer authenticates. |
| Current-user identity | No endpoint (`GET /api/v1/user` was explicitly deferred by the register spec). | `GET /api/v1/user` returns the authenticated user (`200`) or `401` with no session. |
| Bad credentials | N/A | `401` with a single generic message for both "unknown email" and "wrong password" — no field disclosure. |
| Protected routes | None existed. | `logout` and `user` require `auth:sanctum`; `401` (JSON) with no session. |
| API docs security | `config('scramble.security_strategy')` = `null`; every route rendered as unsecured. | Strategy enabled with a cookie scheme; `logout` / `user` documented as protected, `register` / `login` as public. |
| Login rate limiting | N/A | `throttle:6,1` on `POST /api/v1/login` (`429` on exceed), mirroring `register`. |

---

## 8. Test Cases

Executable with Pest 4 on SQLite `:memory:` (`RefreshDatabase`, already active for
`Feature`). Each feature test file's `beforeEach` sets
`$this->withHeader('Origin', config('app.url'))` so the request is stateful (as in
`RegisterTest`). A user fixture is `User::factory()->create(['email' => 'ada@example.com'])`
whose password is the factory default `'password'`. The exact bad-credentials body
is `{ "message": "These credentials do not match our records." }`.

TC-18…TC-20 are deliberate white-box coverage of the two Actions layered on top of
the HTTP-level cases (the same success / failure / logout paths are also exercised
through the real endpoints in TC-1/TC-3, TC-4/TC-5 and TC-13), mirroring the
existing `UserRegisterActionTest`.

### Login — `tests/Feature/Auth/LoginTest.php`

**TC-1:** Valid credentials start a session and return the user
- **Given:** a user `ada@example.com` / `password`
- **When:** `POST /api/v1/login` with `{ email: "ada@example.com", password: "password" }`
- **Expect:** `200`; `assertAuthenticatedAs($user)` (`web` guard); `assertJsonPath('data.email', 'ada@example.com')` and `assertJsonPath('data.name', $user->name)`

**TC-2:** The login response sets the session cookie
- **Given:** the same user
- **When:** `POST /api/v1/login` with valid credentials
- **Expect:** `200`; `assertCookie(config('session.cookie'))`

**TC-3:** The email is normalised before the credential check
- **Given:** a user `ada@example.com`
- **When:** `POST /api/v1/login` with `email` = `"  Ada@Example.COM  "` and the correct password
- **Expect:** `200`; `assertAuthenticatedAs($user)`

**TC-4:** Wrong password → `401` with the generic message, no session
- **Given:** the user `ada@example.com`
- **When:** `POST /api/v1/login` with `email` = `"ada@example.com"`, `password` = `"wrong-password"`
- **Expect:** `401`; `assertExactJson(['message' => 'These credentials do not match our records.'])`; `assertGuest()`

**TC-5:** Unknown email → `401` with the **same** message (no field disclosure)
- **Given:** no user with `email` `nobody@example.com`
- **When:** `POST /api/v1/login` with `email` = `"nobody@example.com"`, `password` = `"password"`
- **Expect:** `401`; body identical to TC-4; `assertJsonMissingPath('errors')`; `assertGuest()`

**TC-6:** Missing `email` → `422` on `email`
- **Given:** an empty `users` table; an unauthenticated request
- **When:** `POST /api/v1/login` without `email`
- **Expect:** `422`; `assertJsonValidationErrors('email')`

**TC-7:** Missing `password` → `422` on `password`
- **Given:** an empty `users` table; an unauthenticated request
- **When:** `POST /api/v1/login` without `password`
- **Expect:** `422`; `assertJsonValidationErrors('password')`

**TC-8:** Malformed `email` → `422` on `email` (dataset: `"not-an-email"`, `123`)
- **Given:** an empty `users` table; an unauthenticated request
- **When:** `POST /api/v1/login` with each dataset value as `email` and a valid `password`
- **Expect:** `422`; `assertJsonValidationErrors('email')`

**TC-9:** Logging in while already authenticated switches the user (last login wins)
- **Given:** users `a@example.com` and `b@example.com`, the request `actingAs` user A
- **When:** `POST /api/v1/login` with user B's valid credentials
- **Expect:** `200`; `assertAuthenticatedAs($userB)`; `assertJsonPath('data.email', 'b@example.com')`

**TC-10:** The login body does not expose the internal id
- **Given:** the user `ada@example.com`
- **When:** `POST /api/v1/login` with valid credentials
- **Expect:** `200`; `assertJsonMissingPath('data.id')`; `assertJsonStructure(['data' => ['name', 'email', 'created_at']])`

**TC-11:** Rate limiting on the login route
- **Given:** the user `ada@example.com`
- **When:** 7 consecutive `POST /api/v1/login` from the same IP (any mix of valid/invalid)
- **Expect:** the first 6 responses are `200`/`401` (not `429`); the 7th is `429`

**TC-12:** The CSRF-cookie handshake precedes a successful login (AC #1)
- **Given:** the user `ada@example.com`; `config('app.url')` is an allowed origin
- **When:** `GET /sanctum/csrf-cookie` (with the `Origin` header), then `POST /api/v1/login` with valid credentials on the same client
- **Expect:** the handshake returns `204` with an `XSRF-TOKEN` cookie; the login returns `200` and `assertAuthenticatedAs($user)`

### Logout — `tests/Feature/Auth/LogoutTest.php`

**TC-13:** An authenticated user logs out
- **Given:** the request `actingAs` a user
- **When:** `POST /api/v1/logout`
- **Expect:** `204`; `assertNoContent()`; `assertGuest()`

**TC-14:** Logout without an active session → `401`
- **Given:** no session
- **When:** `POST /api/v1/logout`
- **Expect:** `401`; `assertExactJson(['message' => 'Unauthenticated.'])`

**TC-15:** After logout, the user endpoint rejects the same session (AC #3)
- **Given:** a login via `POST /api/v1/login`, then `POST /api/v1/logout`, reusing the cookie jar the test client keeps between calls
- **When:** `GET /api/v1/user`
- **Expect:** `401`

### Current user — `tests/Feature/Auth/CurrentUserTest.php`

**TC-16:** An authenticated user reads their identity
- **Given:** the request `actingAs` a user
- **When:** `GET /api/v1/user`
- **Expect:** `200`; `assertJsonPath('data.email', $user->email)`; `assertJsonMissingPath('data.id')`; `assertJsonStructure(['data' => ['name', 'email', 'created_at']])`

**TC-17:** The user endpoint requires a session (AC #4)
- **Given:** no session
- **When:** `GET /api/v1/user`
- **Expect:** `401`; `assertExactJson(['message' => 'Unauthenticated.'])`

### Action units — `tests/Feature/Auth/UserLoginActionTest.php`, `tests/Feature/Auth/UserLogoutActionTest.php`

(Under `tests/Feature/` so `RefreshDatabase` applies, mirroring
`UserRegisterActionTest`; each binds a session store to the request before calling
the Action: `$this->app['request']->setLaravelSession($this->app->make('session.store'));`.)

**TC-18:** `UserLoginAction::handle()` with correct credentials authenticates
- **Given:** a user `ada@example.com` / `password`
- **When:** `app(UserLoginAction::class)->handle(new LoginData('ada@example.com', 'password'))`
- **Expect:** the returned `User` is that user; `Auth::check()` is `true`; `Auth::id()` === `$user->id`

**TC-19:** `UserLoginAction::handle()` with a bad password throws the generic exception
- **Given:** the user `ada@example.com`
- **When:** `handle(new LoginData('ada@example.com', 'nope'))`
- **Expect:** throws `Illuminate\Auth\AuthenticationException` whose `getMessage()` is `'These credentials do not match our records.'`; `Auth::check()` is `false`

**TC-20:** `UserLogoutAction::handle()` clears authentication
- **Given:** a user logged in on the current request (`Auth::login($user)`)
- **When:** `app(UserLogoutAction::class)->handle()`
- **Expect:** `Auth::check()` is `false`

### API docs security — `tests/Feature/Auth/DocsSecurityTest.php`

**TC-21:** Scramble marks the protected routes as secured and the public ones as open
- **Given:** `config('scramble.security_strategy')` enabled with the cookie scheme (§6); routes registered
- **When:** `GET /docs/api.json`
- **Expect:** `200`; `security` on `paths./api/v1/logout.post` and `paths./api/v1/user.get` is a non-empty array referencing the cookie `apiKey` scheme; `paths./api/v1/login.post` and `paths./api/v1/register.post` have `security: []`; the document defines exactly one `components.securitySchemes` entry and its `type` is `apiKey` with `in: cookie` (not `http`/`bearer`)

### Architecture coverage (no new test case)

The existing `tests/Feature/ArchTest.php` assertions (`App\Actions\*` final +
`handle()`, `App\Http\Controllers\Auth\*` invokable, `App\Http\Requests\*` extends
`FormRequest`) automatically cover `UserLoginAction`, `UserLogoutAction`,
`LoginController`, `LogoutController`, `CurrentUserController` and `LoginRequest`.
No new arch test is added; task 13 confirms the suite still passes with the new
classes in place.

---

## 9. Technical Decisions

| Decision area | What was decided | Why |
|---|---|---|
| Paths / versioning | `POST /api/v1/login`, `POST /api/v1/logout`, `GET /api/v1/user` under the global `apiPrefix: 'api/v1'` | `CLAUDE.md` rule 1. The ticket's `/api/login` etc. name the routes, not literal paths — same reading the register spec used. |
| `GET /api/v1/user` in this ticket | Added as a minimal `auth:sanctum` route returning the authenticated user | AC #3 ("after logout, `GET /api/user` returns 401") and AC #4 ("any user-data endpoint returns 401 without a session") both need a concrete protected route to assert against. It is also the natural first protected route for the Scramble switch. The register spec deferred it; this ticket is where it lands. |
| Invalid-credentials status | `401` with `{ "message": "These credentials do not match our records." }`, identical for unknown email and wrong password | AC #2 requires no field disclosure. `401` (not Breeze's `422`-on-`email`) keeps the failure off any field and matches the `401` the protected routes return with no session. |
| How the failure is raised | `throw new AuthenticationException('These credentials do not match our records.')` from `UserLoginAction`; rendered by the existing handler | `AuthenticationException` renders as `{ "message": getMessage() }` + `401` for `api/*`. No custom exception class — it would only add indirection (rule 6). |
| Credential check location | In `UserLoginAction` (`Auth::attempt` + the guard clause + `session()->regenerate()`); **no Service** | Mirrors `UserRegisterAction`, which deliberately skipped a Service. The only "business knowledge" is `Auth::attempt`; a Service wrapping one call is indirection (rule 6). The Action is also the only layer allowed to fire events (`Login`/`Failed`). |
| Already-authenticated login | Re-run `Auth::attempt` with the new credentials, regenerate the session, return the new user; no guard, no `409` | Simpler and forgiving; "last login wins" is the common SPA behaviour. A guard would add a code path and a test the ticket never asked for. |
| Login Form Request | `LoginRequest`: `email` `['required','string','email']`, `password` `['required','string']`; `prepareForValidation()` lowercases + trims `email` (copied from `RegisterRequest`) | Shape/format validation only (rule: no business rules in a Form Request). Email normalisation must match register so `Ada@X.com` logs into the `ada@x.com` account. `password` is not re-checked against a policy — you authenticate against the stored hash. |
| DTO | `App\Data\Auth\LoginData` (`spatie/laravel-data`), readonly `string $email, string $password`; `LoginData::from($request->validated())` | `CLAUDE.md` convention (writes take a `Data` object). `validation_strategy` = `OnlyRequests`, so building from `validated()` does not re-validate. |
| Logout: no Form Request | `LogoutController` type-hints `Illuminate\Http\Request` directly | No input to validate; a `FormRequest` with an empty `rules()` is pure indirection (rule 6). Auth is enforced by the route middleware. |
| Logout response | `response()->noContent()` (`204`), no Resource | `CLAUDE.md` rule 3 explicitly blesses `204` for a no-content action. Nothing meaningful to return. |
| Logout without a session | Route behind `auth:sanctum` → `401` (not an idempotent `204`) | Directly satisfies AC #4 and lets the logout suite double as the "protected route rejects guests" check. |
| Logout internals | `Auth::guard('web')->logout()` → `session()->invalidate()` → `session()->regenerateToken()` | The Laravel Breeze `AuthenticatedSessionController::destroy()` sequence: drop the user, kill the session data, issue a fresh CSRF token. |
| `GET /api/v1/user`: no Action | `CurrentUserController` returns `UserResource::make($request->user())` | Pure read of an already-resolved model; an Action would be an empty pass-through (rule 6). Matches the "plain 200 read" shortcut in `CLAUDE.md`. |
| Resource | Reuse `App\Http\Resources\Auth\UserResource` unchanged (`name`, `email`, `created_at`; no `id`) | Identical contract to `POST /api/v1/register`. `data-model.md`: no public id in v1. The Profile ticket extends the identity payload if it needs more. |
| Policies | None on `logout` / `user` | Each acts only on the authenticated actor — the same documented exception to rule 4 that `register` carries. `login` is public. |
| Rate limiting | Inline `throttle:6,1` on `POST /api/v1/login` | Public unauthenticated endpoint and a brute-force target. Mirrors `register` exactly — no bespoke limiter, no `Lockout` event (rule 5). |
| Password policy | `RegisterRequest` keeps `Password::min(8)`; `LoginRequest` only checks `password` is a required string | Out of scope for this ticket (see §1). Login validates presence, not policy. |
| Scramble `security_strategy` | Enable `MiddlewareAuthSecurityStrategy` via the `[class, options]` form with a **cookie `apiKey`** scheme (session cookie), matched to `auth:sanctum` | The register spec deferred this "until the first protected route exists" — it now does. Default is `bearer`, which misdescribes SPA cookie auth; the options form sets a cookie scheme. `register` / `login` stay `security: []`. |
| Test env / stateful session | Reuse `phpunit.xml` (`SANCTUM_STATEFUL_DOMAINS=localhost`, `APP_URL=http://localhost`) and the per-file `Origin` header | Already added by the register ticket. Without the stateful domain the `web` group is not injected and `Auth::attempt` / `session()->regenerate()` fail. |
| Toolchain in this worktree | Run Pint / PHPStan / Pest via a one-off `docker run -v "$PWD":/var/www/html -w /var/www/html gym-trainer-api/php:8.5 …` | The compose `app` service mounts the **main** checkout, not this worktree. The suite is SQLite `:memory:` + static analysis — no DB, no network needed. |

---

## 10. Work Plan

Tasks 1–8 create pipeline artifacts and are **not independently shippable**;
tasks 9–12 are the functional gate that exercises them end to end. Each Action
task also authors its own unit test (so no task's DoD depends on a test file a
later task produces); the other earlier tasks' DoD is limited to the artifact
existing and passing Pint + PHPStan. Run the toolchain via the one-off container
from §9.

| # | Task | Definition of Done |
|---|---|---|
| 1 | Create `app/Http/Requests/Auth/LoginRequest.php` (`authorize(): true`; `rules()` → `email` `['required','string','email']`, `password` `['required','string']`; `prepareForValidation()` lowercases + trims `email`, copied from `RegisterRequest`) via `make:request`, move to `app/Http/Requests/Auth/`, fix the namespace | The file exists; Pint + PHPStan level 6 clean; a unit assertion that `prepareForValidation()` maps `'  Ada@Example.COM '` → `'ada@example.com'` passes (mirrors `RegisterRequestTest`). |
| 2 | Create `app/Data/Auth/LoginData.php` (`final`, readonly `string $email, string $password`) via `make:data`, move to `app/Data/Auth/`, fix the namespace | `LoginData::from(['email' => …, 'password' => …])` builds the DTO; Pint + PHPStan clean. |
| 3 | Create `app/Actions/Auth/UserLoginAction.php` (`final`, `handle(LoginData $data): User` → `throw_unless(Auth::attempt(['email' => $data->email, 'password' => $data->password]), new AuthenticationException('These credentials do not match our records.'))` → `request()->session()->regenerate()` → `return Auth::user()`) via `make:class`, place under `app/Actions/Auth/`; write `tests/Feature/Auth/UserLoginActionTest.php` (TC-18, TC-19) | The file and its test exist; Pint + PHPStan clean; `pest tests/Feature/Auth/UserLoginActionTest.php` green (TC-18, TC-19). |
| 4 | Create `app/Http/Controllers/Auth/LoginController.php` (`final`, `__invoke(LoginRequest $request, UserLoginAction $action): UserResource` → `return UserResource::make($action->handle(LoginData::from($request->validated())))` — a bare Resource for the plain `200`, as in `CLAUDE.md`) via `make:controller --invokable`, move and fix the namespace | The file exists; Pint + PHPStan clean; the return type is `UserResource` (no `->response()` / status override). |
| 5 | Create `app/Actions/Auth/UserLogoutAction.php` (`final`, `handle(): void` → `Auth::guard('web')->logout()` → `request()->session()->invalidate()` → `request()->session()->regenerateToken()`) via `make:class`; write `tests/Feature/Auth/UserLogoutActionTest.php` (TC-20) | The file and its test exist; Pint + PHPStan clean; `pest tests/Feature/Auth/UserLogoutActionTest.php` green (TC-20). |
| 6 | Create `app/Http/Controllers/Auth/LogoutController.php` (`final`, `__invoke(Request $request, UserLogoutAction $action): Response` → `$action->handle()` → `return response()->noContent()`) via `make:controller --invokable`, move and fix the namespace | The file exists; Pint + PHPStan clean. |
| 7 | Create `app/Http/Controllers/Auth/CurrentUserController.php` (`final`, `__invoke(Request $request): UserResource` → `return UserResource::make($request->user())`) via `make:controller --invokable`, move and fix the namespace | The file exists; Pint + PHPStan clean. |
| 8 | Wire `routes/api.php`: add `Route::post('login', LoginController::class)->middleware('throttle:6,1')->name('auth.login')` (with the public-route comment) and `Route::middleware('auth:sanctum')->group(function () { Route::post('logout', LogoutController::class)->name('auth.logout'); Route::get('user', CurrentUserController::class)->name('auth.user'); })`; add the three `use` imports | `php artisan route:list` shows `POST api/v1/login` (`throttle:6,1`), `POST api/v1/logout` and `GET api/v1/user` (both `auth:sanctum`); PHPStan clean in `routes/`. |
| 9 | Write `tests/Feature/Auth/LoginTest.php` covering TC-1 … TC-12 (`beforeEach` sets `withHeader('Origin', config('app.url'))`) | `pest tests/Feature/Auth/LoginTest.php` all green; each of TC-1…TC-12 has a corresponding test. |
| 10 | Write `tests/Feature/Auth/LogoutTest.php` covering TC-13 … TC-15 | `pest tests/Feature/Auth/LogoutTest.php` all green; TC-13…TC-15 each have a test. |
| 11 | Write `tests/Feature/Auth/CurrentUserTest.php` covering TC-16 … TC-17 | `pest tests/Feature/Auth/CurrentUserTest.php` all green. |
| 12 | Enable `config/scramble.php` `security_strategy` = `[MiddlewareAuthSecurityStrategy::class, ['middleware' => ['auth:sanctum'], 'scheme' => <cookie apiKey scheme>]]`; confirm the `SecurityScheme` factory against the installed `dedoc/scramble` version; write `tests/Feature/Auth/DocsSecurityTest.php` (TC-21) | PHPStan clean in `config/`; `pest tests/Feature/Auth/DocsSecurityTest.php` green — `/api/v1/logout` and `/api/v1/user` carry a non-empty `security` referencing the cookie `apiKey` scheme, `/api/v1/login` and `/api/v1/register` have `security: []`, and the single `securitySchemes` entry is `type: apiKey`, `in: cookie`. |
| 13 | Run the full check in the one-off container: Pint `--test` on `app routes config`, then PHPStan level 6, then the whole Pest suite | All three green; the existing `tests/Feature/ArchTest.php` passes with the new classes in place; `RegisterTest` and `ExampleTest` still pass (no regression). |
| 14 | Manual `curl` against `http://localhost:8000`: `GET /sanctum/csrf-cookie` → `POST /api/v1/login` `200` + `Set-Cookie` → `GET /api/v1/user` `200` → `POST /api/v1/logout` `204` → `GET /api/v1/user` `401`; bad password → `401` with the generic message. Review `GET /docs/api`. | The `curl` sequence returns the codes above; `login` / `logout` / `user` appear in Scramble with request/response inferred from `LoginRequest` and `UserResource`, and the cookie security scheme shows on the two protected routes. |

*Process note: branch `feature/login-logout` off `main`; branch name, commit
messages and PR text follow `CLAUDE.md` / `AGENTS.md` — English only, **no**
`Co-Authored-By: Claude` / `Claude-Session:` trailers. The PR description keeps
only the `🤖 Generated with Claude Code` footer.*
