# Create a routine — `POST /api/v1/routines`

> **Partially superseded by `docs/plans/generate-first-cycle-spec.md` (Order 60).**
> That story changed first-cycle generation from a queued `GenerateCycleJob`
> (dispatched inside `RoutineCreateAction`'s transaction, as described below) to a
> **synchronous, all-or-nothing** step: `RoutineCreateAction` now calls
> `CyclePlannerService` *before* its transaction and, on success, persists the
> routine **and** its first `draft` cycle together; the `201` body carries the
> nested `cycle`; a planner failure returns `502 AI_GENERATION_FAILED` and writes
> nothing (no routine row, incumbent not archived). `GenerateCycleJob` is kept as
> a stub for the async cycle N+1 story. Where this document and
> `generate-first-cycle-spec.md` disagree about the job, the response body, or the
> `502` path, the latter wins. Everything else here (the `routines` table, the
> `Routine` model, `RoutineStatus`, `HasPublicUuid`, `ProfileIncompleteException`,
> `RoutinePolicy`, validation, permanent archival) still holds.

> Derived from the Notion ticket "Crear una rutina nueva" (Feature: Rutinas ·
> MVP · Must · Repo: API · Order 40) and the approved plan
> (`.claude/plans/tenemos-un-nuevo-requerimiento-staged-sutton.md`). Base
> contract: `docs/product-context.md` §2 / §4 (step 2) / §6 / §7,
> `docs/plans/data-model.md` §`routines` + §Identificadores + §Enums,
> `CLAUDE.md` "The pipeline" (its worked example *is* `StoreRoutineController` /
> `RoutineCreateAction`), `docs/plans/domain-exception-handling-spec.md` (its
> illustrative `ProfileIncompleteException` becomes the first real subclass
> here), and `docs/plans/create-user-profile-spec.md` (pipeline reference
> implementation).

## 1. Context

**Kind:** Greenfield Feature

**Stack:** PHP 8.5 · Laravel 13 · PostgreSQL 17 (runtime) / SQLite `:memory:`
(tests) · Pest 4 (`pest-plugin-laravel`) · `laravel/sanctum` 4 (SPA cookie
mode, already installed) · `spatie/laravel-data` 4.23 · `dedoc/scramble` 0.13 ·
Pint · Larastan level 6. Everything runs in Docker.

**Problem statement:** The API has authentication and the athlete profile
(`GET` / `PUT /api/v1/profile`), but no training domain. The first thing an
onboarded user does is create a **routine** — a named training programme with
its own goal. Creating a routine is the entry point of the product's core loop:
the new routine becomes the user's single `active` routine, any previously
active routine is archived **permanently**, and generation of the routine's
first weekly cycle is queued. This ticket adds the single write endpoint
`POST /api/v1/routines` to the existing `auth:sanctum` route group, plus the
`routines` table, the `Routine` model, the `RoutineStatus` enum, the shared
`HasPublicUuid` trait, the first concrete `DomainException` subclass, and a
placeholder `GenerateCycleJob`.

**In scope:**
- `POST /api/v1/routines` — create the authenticated user's routine from `name`,
  `goal` and an optional free-text `hint`. Returns `201` with the created
  routine.
- The `routines` table + `Routine` model + `User::routines()` relation +
  `RoutineFactory`.
- `App\Enums\Routine\RoutineStatus` (`active`, `archived`) — matching
  `docs/plans/data-model.md` §Enums.
- `App\Models\Concerns\HasPublicUuid` — the shared trait
  (`docs/plans/data-model.md` §Identificadores) that fills a public `uuid` (v4)
  on `creating` and sets `getRouteKeyName()` to `uuid`. First user of the
  pattern; every future API-exposed model reuses it.
- `App\Exceptions\Profile\ProfileIncompleteException` — the concrete
  `DomainException` subclass documented (as an example) in
  `docs/plans/domain-exception-handling-spec.md`: `code` `PROFILE_INCOMPLETE`,
  HTTP `409`, thrown when the user has no athlete profile.
- `App\Actions\Routine\RoutineCreateAction` — the whole use case in one class:
  the onboarding guard, then a transaction that archives the incumbent active
  routine, inserts the new one, and dispatches `GenerateCycleJob`. No Service
  layer — the "single active slot" logic is one guard clause plus one scoped
  `update`, with no business knowledge to isolate and no second caller, so a
  Service would be indirection only (`CLAUDE.md` rules 5–6).
- `App\Jobs\Cycle\GenerateCycleJob` — a **placeholder**: it is dispatched on
  routine creation (AC #4) so the contract is real and testable, but its
  `handle()` body (the AI cycle planner, the `cycles` schema) ships with the
  separate ticket "Recibir el primer ciclo apenas creo una rutina" (Order 60).
- `App\Policies\RoutinePolicy` — `create` returns `true` for any authenticated
  user; wired through `StoreRoutineRequest::authorize()`. Establishes the Policy
  pattern for the by-id routine / cycle endpoints in later tickets.
- One route added to the `auth:sanctum` group in `routes/api.php`.
- Adding the `hint` column to the `routines` table in `docs/plans/data-model.md`
  (the data model as written omits it; `docs/product-context.md` §4 step 8
  treats `hint` as a persisted routine property re-read for later cycles).
- Verifying the route is covered by Scramble's `security_strategy` (already on
  `main`) — one assertion added to `tests/Feature/Auth/DocsSecurityTest.php`.
- Pest feature + focused unit coverage of every acceptance criterion.

**Out of scope:**
- The `cycles` table, `App\Enums\Cycle\CycleStatus`, the AI cycle-planner agent
  and its wrapping Service, `ExerciseCatalogService`, the `failed`-state
  handling, AI fakes for tests — all in the "Recibir el primer ciclo" ticket
  (Order 60). `GenerateCycleJob::handle()` is an empty documented stub here.
- Listing routines (`GET /api/v1/routines`) — ticket "Listar mis rutinas"
  (Order 50).
- Reading, renaming, editing the `goal` of, or deleting a routine. In v1 a
  routine is immutable after creation (`docs/product-context.md` §6 "Fuera de la
  v1"). No `PUT` / `PATCH` / `DELETE`.
- Reactivating or cloning an `archived` routine (`docs/product-context.md` §6).
- A read endpoint for an `archived` routine's detail (`docs/product-context.md`
  §6). The history stays in the database; no endpoint in v1.
- Exposing `days_per_cycle` for editing — fixed at 5 in v1.
- Rate limiting on the route (authenticated, low-abuse; matches the profile
  routes).
- Any `users`-table change beyond adding the `routines()` relation.
- The `gym-trainer-spa/` frontend (separate repository).

---

## 2. API Surface

### 2.1 REST

The route joins the existing `Route::middleware('auth:sanctum')->group(...)` in
`routes/api.php` (added by the login & logout PR, alongside `logout` / `user`
and the profile routes), under the global `apiPrefix: 'api/v1'`. It is subject
to `EnsureFrontendRequestsAreStateful` + CSRF because the whole `api` group is
stateful (`$middleware->statefulApi()` in `bootstrap/app.php`).

| Method | Path | Auth | Request | Response | Status codes |
|---|---|---|---|---|---|
| POST | `/api/v1/routines` | `auth:sanctum` (session cookie, `web` guard) + `RoutinePolicy::create` | JSON: `name` (string, required, ≤255), `goal` (string, required, one of `hypertrophy` / `strength` / `fat_loss` / `general_health` / `endurance`), `hint` (string, optional/nullable, ≤2000) | `{ "data": { "id": string uuid, "name": string, "goal": string, "hint": string\|null, "days_per_cycle": 5, "status": "active", "archived_at": null, "created_at": string ISO-8601, "updated_at": string ISO-8601 } }` | `201` created · `422` validation (missing required field, value outside the `goal` enum, `name` / `hint` too long) · `409` `PROFILE_INCOMPLETE` (the user has no athlete profile) · `401` unauthenticated · `419` stateful request without a valid CSRF token |

Notes:
- The body exposes `id` = the routine's **`uuid`** (public identifier), never the
  internal `bigint` PK. `docs/plans/data-model.md` §Identificadores: the `uuid`
  is the only identifier that crosses the API boundary and it is the route key
  for the by-id endpoints in later tickets.
- `days_per_cycle` is **not** accepted in the request (AC #3). A `days_per_cycle`
  key in the body is silently ignored — it is not a validation rule, so it never
  reaches `$request->validated()`. The response always shows `5`.
- `hint` normalisation: omitted, `null`, `""` and whitespace-only all persist as
  `null` — collapsed in `prepareForValidation()`. The AI cycle planner never
  receives an empty string. Mirrors `athlete_profiles.notes`
  (`create-user-profile-spec.md`).
- On success the new routine is `active`; if the user already had an `active`
  routine, that row is transitioned to `archived` with `archived_at` set, in the
  same transaction, **before** the insert (AC #2). Archival is permanent: no
  endpoint reactivates or edits an `archived` routine in v1.
- `GenerateCycleJob` is dispatched inside the transaction (AC #4). The routine
  has no cycles until the job runs; this ticket ships the job as a stub.
- `409` `PROFILE_INCOMPLETE`: the request is well-formed but the account **state**
  forbids the action — the user must complete onboarding
  (`PUT /api/v1/profile`) first. Rendered by `App\Exceptions\ApiExceptionRenderer`
  (already wired in `bootstrap/app.php`) as
  `{ "data": { "code": "PROFILE_INCOMPLETE", "message": "Complete your athlete profile before creating a routine." } }`,
  no `errors` key.
- `403` does not occur in practice: `RoutinePolicy::create` returns `true` for
  every authenticated user. The Policy is wired for consistency and to establish
  the pattern; an unauthenticated request is stopped earlier by `auth:sanctum`
  with `401`.
- Errors are rendered as JSON by the exception handler configured for `api/*`.
  No hand-built JSON. CSRF is auto-bypassed in the test environment
  (`ValidateCsrfToken::runningUnitTests()`).
- `GET /sanctum/csrf-cookie` (registered by Sanctum at the app root) is
  unchanged; a browser client calls it before the `POST`.

### 2.2 CLI

Not applicable — no CLI commands.

### 2.3 Events

Not applicable — no domain events. `RoutineCreateAction` dispatches the
`GenerateCycleJob` queued job (§2.1, AC #4); it is a job, not an event, and has
no listeners. Eloquent's model events fire on the insert but the project
registers no listeners for `Routine`, and none are added here.

---

## 3. UI

### 3.1 Pages

Not applicable — no pages affected. This is a JSON REST API; the "create
routine" screen lives in the `gym-trainer-spa/` repository, outside this ticket.

### 3.2 Components

Not applicable — no components affected.

---

## 4. Database

### 4.1 Schema changes

| Table | Action | Details |
|---|---|---|
| `routines` | Create | `id` bigint PK · `uuid` uuid **`unique`** (public id / route key; filled by `HasPublicUuid` on `creating` with `Str::uuid()` v4) · `user_id` bigint, FK → `users.id`, `constrained()->cascadeOnDelete()` · `name` string · `goal` string (stores the `Goal` backed value) · `hint` text **nullable** · `days_per_cycle` `unsignedSmallInteger`, **default `5`** · `status` string (stores the `RoutineStatus` backed value) · `archived_at` `timestamp` nullable · `created_at` / `updated_at` timestamps |

- Migration file:
  `database/migrations/<timestamp>_create_routines_table.php`, anonymous class
  `return new class extends Migration`.
- **Partial unique index** — one `active` routine per user
  (`docs/plans/data-model.md` §`routines`): after `Schema::create`, a raw
  statement
  `DB::statement("CREATE UNIQUE INDEX routines_user_id_active_unique ON routines (user_id) WHERE status = 'active'")`.
  Laravel's schema builder has no portable partial-index API; the raw
  `WHERE status = 'active'` form parses on both PostgreSQL 17 and SQLite
  `:memory:`. It is a **backstop** — `RoutineCreateAction` archives the incumbent
  before inserting, so the index is never violated in the normal flow. `down()`
  drops the table (the index goes with it).
- `cascadeOnDelete` on `user_id`: a routine is meaningless without its user;
  matches the data-model FK convention (`ON DELETE CASCADE` when the child has no
  meaning without the parent).
- Enum columns (`goal`, `status`) are **plain `string`**, not native Postgres
  `enum`, no `CHECK` — portable across the Postgres runtime and the SQLite
  `:memory:` test DB. Membership is guarded by the backed-enum cast on the model
  plus `Rule::enum` in the Form Request. Matches `athlete_profiles`.
- `archived_at` is a plain nullable `timestamp` (matching the codebase's
  `$table->timestamps()` style; `docs/plans/data-model.md` labels it
  `timestamptz` — the existing `athlete_profiles` migration uses plain
  `timestamps()` and this follows suit).
- No soft deletes (`docs/plans/data-model.md`): history is kept by `status`, not
  by deleting rows.
- **Doc update:** add a `hint` row to the `routines` table in
  `docs/plans/data-model.md` §`routines`:
  `| `hint` | `text` null | Texto libre opcional para orientar a la IA ("quiero PPL", "full body en casa con mancuernas"). Se pasa al planificador sin procesar, en el primer ciclo y en los siguientes. |`.
- **Database isolation (`CLAUDE.md` → "Workflows — database isolation"):** this
  branch adds a migration, so the shared `gym_trainer` database must not be
  migrated directly. Before running `migrate` against Postgres:
  `docker compose exec pgsql createdb -U gym -T gym_trainer gym_trainer_create_routings`,
  then point this worktree at the clone (`DB_DATABASE=gym_trainer_create_routings`
  in the worktree's `.env` — the worktree keeps its own `.env` / `vendor/`, see
  the `worktree-docker-tooling` note). Drop the clone
  (`dropdb --if-exists gym_trainer_create_routings`) and revert `.env` on merge.
  The Pest suite is unaffected — SQLite `:memory:`.

### 4.2 Seeds

Not applicable — no seeds.

---

## 5. Auth & Authorization

### 5.1 Authentication

**Method:** Session (cookie) via **Laravel Sanctum in SPA / stateful mode** — the
same mechanism as every other endpoint. Reuses the `web` session guard
(`config('sanctum.guard') === ['web']`); no `api` guard, no `config/auth.php`
change, no tokens.

- `auth:sanctum` on the route group authenticates the request from the session
  cookie set at registration / login. An unauthenticated request throws
  `AuthenticationException` → `401` JSON (via the handler already configured for
  `api/*`).
- CSRF: `POST` is a stateful non-GET request, so it requires a valid
  `XSRF-TOKEN` (`419` otherwise). Auto-bypassed in the test environment
  (`ValidateCsrfToken::runningUnitTests()`).

### 5.2 Authorization

**`RoutinePolicy`**, wired through the Form Request. Unlike the profile routes
(which take a documented no-Policy exception), this ticket introduces the Policy
now to establish the pattern for the routine / cycle endpoints that **do** take
a `{routine}` / `{cycle}` id in later tickets.

| Role | Permissions |
|---|---|
| Authenticated user | Create a routine for **themselves**. `RoutinePolicy::create(User $user): bool` returns `true` — there is no per-user condition to check on a create with no target resource. There is no other permission and no other actor. |

- `App\Policies\RoutinePolicy` is auto-discovered by Laravel 13 for
  `App\Models\Routine` (`App\Policies\{Model}Policy` convention) — no
  `AuthServiceProvider` / `Gate::policy()` wiring.
- `StoreRoutineRequest::authorize()` returns
  `$this->user()->can('create', Routine::class)`.
- The route carries **no `{routine}` segment**; the routine is always created for
  `$request->user()` via `$user->routines()->create(...)`, so there is no code
  path that could touch another user's data. `403` therefore never occurs on
  this endpoint in practice; it becomes meaningful when a later ticket adds
  `view` / `update` abilities keyed to `$routine->user_id`.
- The onboarding precondition ("the user must have an athlete profile") is **not**
  an authorization decision — it is a business rule, enforced by a guard clause
  in `RoutineCreateAction` that throws `ProfileIncompleteException` (`409`), not
  by the Policy (§9).

---

## 6. Configuration

**Environment variables:**

| Variable | Value / Source | Purpose |
|---|---|---|
| `DB_DATABASE` | `gym_trainer_create_routings` (this worktree's `.env` only, during development) | Points the worktree at a clone of `gym_trainer` so the new migration never runs against the shared database. Reverted on merge. |

No new keys in `.env.example`. `phpunit.xml` already carries everything the tests
need (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `QUEUE_CONNECTION=sync`,
`SANCTUM_STATEFUL_DOMAINS=localhost`, `APP_URL=http://localhost`), and
`RefreshDatabase` is already active for the `Feature` suite in `tests/Pest.php`.

**Config files modified:**

| File | Change |
|---|---|
| `routes/api.php` | Add a `use` import for `StoreRoutineController` and, inside the existing `auth:sanctum` group, `POST routines` → `StoreRoutineController` (`routines.store`). |
| `docs/plans/data-model.md` | Add the `hint` row to the `routines` table (§4.1). Not a config file, listed here for completeness. |
| `tests/Feature/Auth/DocsSecurityTest.php` | Add one assertion that `/v1/routines` `post` inherits the global `security` (no per-operation override), alongside the existing profile / logout / user assertions. |
| `tests/Feature/ArchTest.php` | Add `arch('routine controllers are invokable')->expect('App\Http\Controllers\Routine')->toBeInvokable();`. |

No change to `bootstrap/app.php`, `config/auth.php`, `config/sanctum.php`,
`config/cors.php`, `config/scramble.php`, `config/queue.php`,
`bootstrap/providers.php`, `phpunit.xml`, `composer.json`.

---

## 7. Current vs New Behavior

| Behavior | Current | New |
|---|---|---|
| Routine domain | No table, no model, no endpoint, no enum. `app/Jobs/`, `app/Policies/` do not exist. | `routines` table (many per user); `POST /api/v1/routines` creates one. `App\Enums\Routine\RoutineStatus`, `App\Jobs\Cycle\GenerateCycleJob`, `App\Policies\RoutinePolicy` are created. |
| Single active routine | Not represented. | Exactly one `active` routine per user, enforced by a partial unique index and by `RoutineCreateAction` archiving the incumbent inside the create transaction. |
| Permanent archival | N/A. | Creating a routine sets any prior `active` routine to `archived` + `archived_at`. No endpoint reactivates or edits it; its history stays readable in the DB. |
| First-cycle generation | N/A. | `RoutineCreateAction` dispatches `GenerateCycleJob` on the queue. The job body is a stub in this ticket; "Recibir el primer ciclo" (Order 60) implements it. |
| Public identifiers | `athlete_profiles` is internal (no `uuid`); `users` has no `uuid`. No `HasPublicUuid` trait. | `App\Models\Concerns\HasPublicUuid` — fills a v4 `uuid` on `creating`, `getRouteKeyName() => 'uuid'`. `routines.uuid` is the response `id` and the future route key. |
| Domain exceptions | `App\Exceptions\DomainException` base + `ApiExceptionRenderer` exist (domain-exception PR); **no concrete subclass** ships (only test stubs). | `App\Exceptions\Profile\ProfileIncompleteException` — first real subclass (`PROFILE_INCOMPLETE` / `409`), thrown from `RoutineCreateAction`'s guard clause. |
| `User` model | `athleteProfile(): HasOne` only. | Adds `routines(): HasMany` + `@property-read` PHPDoc. |
| Authenticated routes | `auth:sanctum` group holds `logout`, `user`, `GET`/`PUT profile`. No `app/Policies/`. | Adds `POST routines` to the same group. First `app/Policies/` class (`RoutinePolicy`), auto-discovered. |
| Queue usage | `QUEUE_CONNECTION` configured (`database` dev / `sync` tests); nothing dispatched anywhere. | First job dispatch in the codebase. Tests introduce the first `Bus::fake()` usage. |
| OpenAPI auth docs | `MiddlewareAuthSecurityStrategy` on `main` covers any `auth:sanctum` route. | `POST /api/v1/routines` inherits the global `security`; `DocsSecurityTest` asserts it alongside the profile routes. |
| Routine-touching tests | None. | `tests/Feature/Routine/` (endpoint, action and DTO files), `tests/Unit/Routine/RoutinePolicyTest.php`, one `DocsSecurityTest` assertion, one `ArchTest` rule. |

---

## 8. Test Cases

Executable with Pest 4 on SQLite `:memory:` (`RefreshDatabase`, already wired).
Every feature test's `beforeEach` sets
`$this->withHeader('Origin', config('app.url'))` and `Bus::fake()`; authenticated
cases add `$this->user = User::factory()->create()` and, unless the case is
about the missing-profile path, `AthleteProfile::factory()->for($this->user)->create()`.
Helper defined at the top of the feature file:

```php
function routinePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Winter Volume',
        'goal' => 'hypertrophy',
        'hint' => 'PPL split, dumbbells only',
    ], $overrides);
}
```

TC-19 … TC-21 are white-box tests on the Action, the DTO and the Policy; the
HTTP behaviour they touch is already covered end to end by TC-1 … TC-18. TC-19
(Action) and TC-20 (`RoutineData::from()`, which needs the container for
`spatie/laravel-data`) live under `tests/Feature/`; TC-21 (Policy, a pure unit)
lives under `tests/Unit/Routine/`.

### POST `/api/v1/routines` — `tests/Feature/Routine/StoreRoutineTest.php`

**TC-1:** First routine is created `active` and queues cycle generation (AC #1, #2, #4, #5)
- **Given:** an authenticated user with an athlete profile and no `routines` row
- **When:** `POST /api/v1/routines` with the base valid payload
- **Expect:** `201`; `data.id` matches a UUID regex; `data.name` = `"Winter Volume"`, `data.goal` = `"hypertrophy"`, `data.hint` = `"PPL split, dumbbells only"`, `data.status` = `"active"`, `data.days_per_cycle` = `5`, `data.archived_at` = `null`; `assertDatabaseHas('routines', ['user_id' => $user->id, 'name' => 'Winter Volume', 'goal' => 'hypertrophy', 'status' => 'active', 'days_per_cycle' => 5])`; `assertDatabaseCount('routines', 1)`; `Bus::assertDispatched(GenerateCycleJob::class, fn ($job) => $job->routine->user_id === $user->id)`

**TC-2:** Creating a routine archives the previous active one, permanently (AC #2)
- **Given:** an authenticated user with a profile and `Routine::factory()->for($user)->create()` (active); capture its `id`
- **When:** `POST /api/v1/routines` with the base valid payload
- **Expect:** `201`; the previous routine reloaded has `status` = `"archived"` and a non-null `archived_at`; the new routine has `status` = `"active"`, `archived_at` = `null`; `assertDatabaseCount('routines', 2)`; exactly one row with `status = 'active'` for `$user->id`

**TC-3:** New routine is `active` with `archived_at` null when the user had no prior routine (AC #2)
- **Given:** an authenticated user with a profile and no routine
- **When:** `POST /api/v1/routines` with the base valid payload
- **Expect:** `201`; `data.status` = `"active"`; `data.archived_at` = `null`; `assertDatabaseHas('routines', ['user_id' => $user->id, 'status' => 'active', 'archived_at' => null])`

**TC-4:** `name` is required (AC #1)
- **Given:** an authenticated user with a profile
- **When:** `POST /api/v1/routines` with the base payload minus `name`
- **Expect:** `422`; `assertJsonValidationErrors('name', 'data.errors')`; `assertDatabaseCount('routines', 0)`; `Bus::assertNothingDispatched()`

**TC-5:** `goal` is required (AC #1)
- **Given:** an authenticated user with a profile
- **When:** `POST /api/v1/routines` with the base payload minus `goal`
- **Expect:** `422`; `assertJsonValidationErrors('goal', 'data.errors')`; `assertDatabaseCount('routines', 0)`; `Bus::assertNothingDispatched()`

**TC-6:** `goal` outside the allowed set → `422` on that field (AC #1)
- **Given:** an authenticated user with a profile
- **When:** `POST /api/v1/routines` with `goal` = `"powerlifting"`
- **Expect:** `422`; `assertJsonValidationErrors('goal', 'data.errors')`; `assertDatabaseCount('routines', 0)`

**TC-7:** Every valid `goal` value is accepted (AC #1)
- **Given:** an authenticated user with a profile and no routine
- **When:** `POST /api/v1/routines` with `goal` = each of `hypertrophy` / `strength` / `fat_loss` / `general_health` / `endurance` (dataset)
- **Expect:** `201`; `assertJsonPath('data.goal', $value)`

**TC-8:** `name` length boundary
- **Given:** an authenticated user with a profile
- **When:** `POST /api/v1/routines` with `name` = a 255-char string, then a 256-char string
- **Expect:** the 255-char save is `201`; the 256-char save is `422` with `assertJsonValidationErrors('name', 'data.errors')`

**TC-9:** `hint` omitted → saved as `null` (AC #1)
- **Given:** an authenticated user with a profile and no routine
- **When:** `POST /api/v1/routines` with the base payload minus `hint`
- **Expect:** `201`; `assertJsonPath('data.hint', null)`; `assertDatabaseHas('routines', ['user_id' => $user->id, 'hint' => null])`

**TC-10:** `hint` empty or whitespace-only → saved as `null` (AC #1)
- **Given:** an authenticated user with a profile and no routine
- **When:** `POST /api/v1/routines` with `hint` = `""`, then `hint` = `"   "` (dataset)
- **Expect:** `201`; `assertJsonPath('data.hint', null)`; `assertDatabaseHas('routines', ['hint' => null])`

**TC-11:** `hint` length boundary
- **Given:** an authenticated user with a profile
- **When:** `POST /api/v1/routines` with `hint` = a 2000-char string, then a 2001-char string
- **Expect:** the 2000-char save is `201`; the 2001-char save is `422` with `assertJsonValidationErrors('hint', 'data.errors')`

**TC-12:** `days_per_cycle` in the request is ignored (AC #3)
- **Given:** an authenticated user with a profile and no routine
- **When:** `POST /api/v1/routines` with the base payload plus `days_per_cycle` = `3`
- **Expect:** `201`; `assertJsonPath('data.days_per_cycle', 5)`; `assertDatabaseHas('routines', ['user_id' => $user->id, 'days_per_cycle' => 5])`

**TC-13:** No athlete profile → `409` `PROFILE_INCOMPLETE`, nothing written (AC — implicit onboarding precondition)
- **Given:** an authenticated user with **no** `athlete_profiles` row
- **When:** `POST /api/v1/routines` with the base valid payload
- **Expect:** `409`; `assertJsonPath('data.code', 'PROFILE_INCOMPLETE')`; `assertJsonPath('data.message', 'Complete your athlete profile before creating a routine.')`; `assertJsonMissingPath('data.errors')`; `assertDatabaseCount('routines', 0)`; `Bus::assertNothingDispatched()`

**TC-14:** Unauthenticated request → `401` (AC — auth)
- **Given:** no `actingAs` (the `Origin` header is still set)
- **When:** `POST /api/v1/routines` with the base valid payload
- **Expect:** `401`; `assertJsonPath('data.code', 'AUTHENTICATION_EXCEPTION')`; `assertDatabaseCount('routines', 0)`; `Bus::assertNothingDispatched()`

**TC-15:** Cross-user isolation — a create never touches another user's routine (AC #2)
- **Given:** `$other = User::factory()->create()` with a profile and `Routine::factory()->for($other)->create()` (active); `actingAs($this->user)` (a different user, also with a profile)
- **When:** `POST /api/v1/routines` with the base valid payload
- **Expect:** `201`; `assertDatabaseCount('routines', 2)`; `$other`'s routine reloaded is still `status = 'active'` with `archived_at = null`; both users have exactly one `active` routine (the partial index is per-user)

**TC-16:** The response exposes the `uuid` as `id`, never the internal PK (AC #5)
- **Given:** an authenticated user with a profile
- **When:** `POST /api/v1/routines` with the base valid payload
- **Expect:** `data.id` equals the created row's `uuid` and matches `/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/`; `data.id` is not the numeric PK; `assertJsonStructure(['data' => ['id', 'name', 'goal', 'hint', 'days_per_cycle', 'status', 'archived_at', 'created_at', 'updated_at']])`; `assertJsonMissingPath('data.user_id')`

**TC-17:** Enums serialise as strings, dates as ISO-8601 (AC #5)
- **Given:** an authenticated user with a profile
- **When:** `POST /api/v1/routines` with the base valid payload
- **Expect:** `data.status` is the string `"active"` (not an object); `data.goal` is `"hypertrophy"`; `data.created_at` matches `/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/`

**TC-18:** Strict-mode render guard — the Resource never triggers a lazy load
- **Given:** an authenticated user with a profile
- **When:** `POST /api/v1/routines` with the base valid payload
- **Expect:** `201` with no `500`; `data` has no `user` key

### Action — `tests/Feature/Routine/RoutineCreateActionTest.php`

**TC-19:** `handle()` archives the incumbent, inserts the new routine and dispatches the job
- **Given:** a `User` with an `AthleteProfile` and no routine; `Bus::fake()`; an `RoutineData` built from valid input (`name: 'Winter Volume'`, `goal: Goal::Hypertrophy`, `hint: null`)
- **When:** `app(RoutineCreateAction::class)->handle($user, $data)` is called, then called again with a different `name`
- **Expect:** after the first call `assertDatabaseCount('routines', 1)` with `status = 'active'` and `days_per_cycle = 5`, the return value's `wasRecentlyCreated` is `true`, `status` is `RoutineStatus::Active`, and `Bus::assertDispatched(GenerateCycleJob::class)` once; after the second call the first routine is `RoutineStatus::Archived` with a non-null `archived_at`, the second is `RoutineStatus::Active`, `assertDatabaseCount('routines', 2)`, and `GenerateCycleJob` was dispatched twice in total

**TC-20:** `handle()` for a user with no profile throws and writes nothing
- **Given:** a `User` with no `AthleteProfile`; `Bus::fake()`
- **When:** `app(RoutineCreateAction::class)->handle($user, $data)` is called
- **Expect:** it throws `App\Exceptions\Profile\ProfileIncompleteException`; `assertDatabaseCount('routines', 0)`; `Bus::assertNothingDispatched()`

### DTO — `tests/Feature/Routine/RoutineDataTest.php`

**TC-21:** `RoutineData::from()` maps input and casts the `goal` enum, defaulting `hint` to `null`
- **Given:** the array `['name' => 'Winter Volume', 'goal' => 'strength', 'hint' => 'PPL']`, and separately `['name' => 'Winter Volume', 'goal' => 'strength']`
- **When:** `RoutineData::from($array)` is built for each
- **Expect:** first — `name === 'Winter Volume'`, `goal === Goal::Strength`, `hint === 'PPL'`; second — `hint === null`

### Policy — `tests/Unit/Routine/RoutinePolicyTest.php`

**TC-22:** `RoutinePolicy::create` allows any user
- **Given:** a `new User()`
- **When:** `(new RoutinePolicy)->create($user)` is evaluated
- **Expect:** `true`

### Architecture — `tests/Feature/ArchTest.php` (added rule)

**TC-23:** Routine controllers are invokable
- **Given:** the project code
- **When:** the Pest architecture assertions run
- **Expect:** `App\Http\Controllers\Routine` is invokable (new `arch(...)` line); the existing rules (`App\Actions\*` final + `handle()`, `App\Http\Requests\*` extends `FormRequest`, no debug helpers) still pass — automatically covering `RoutineCreateAction` and `StoreRoutineRequest`

### OpenAPI — `tests/Feature/Auth/DocsSecurityTest.php` (extends the existing test)

**TC-24:** The generated OpenAPI spec marks `POST /api/v1/routines` secured
- **Given:** the app with `security_strategy` = `MiddlewareAuthSecurityStrategy` (already on `main`)
- **When:** the spec is generated in-process
- **Expect:** a global `security` requirement is present; `paths./v1/routines.post` has **no** per-operation `security` key (it inherits the global one), the same way `logout` / `user` / `profile` do. Added as an `->and(...)` assertion to the existing test.

---

## 9. Technical Decisions

| Decision area | What was decided | Why |
|---|---|---|
| Endpoint scope | This ticket ships **routine creation only** + a placeholder `GenerateCycleJob`. No `cycles` table, no `CycleStatus`, no AI planner. | AC #4 is "the job is enqueued"; the job's body and the whole Cycle domain are a separate, large ticket ("Recibir el primer ciclo", Order 60). Splitting keeps this PR reviewable and matches the backlog. Confirmed with the product owner. |
| HTTP verb & status | `POST /api/v1/routines`, `201` on success (via `->response()->setStatusCode(Response::HTTP_CREATED)`) | A user has many routines and each create makes a new resource — `POST` to the collection, `201`. Matches the `CLAUDE.md` `StoreRoutineController` example and `RegisterController`. |
| Onboarding precondition | Required. `RoutineCreateAction::ensureOnboardingComplete()` runs first: `throw_if($user->athleteProfile()->doesntExist(), new ProfileIncompleteException)` (`409`). | The routine exists to be fed to the AI cycle planner, which consumes the athlete profile (`docs/product-context.md` §4). Creating a routine with no profile would produce an ungeneratable cycle. Confirmed with the product owner. |
| Guard placement | `RoutineCreateAction::ensureOnboardingComplete()`, a private guard clause run first — not the Form Request and not the Policy. **No Service layer.** | The Form Request is shape-only; a missing profile is account **state**, not an authorization decision. `CLAUDE.md` puts guard clauses in a Service, but here that Service would hold one `throw_if` plus one scoped `update` — no business knowledge to isolate, no second caller — so it is indirection only (`CLAUDE.md` rules 5–6). The Action reads top-to-bottom as the whole use case. If the onboarding check is later reused (session creation, on-demand cycle generation) it graduates to a small Profile-domain Service then. |
| `PROFILE_INCOMPLETE` status | `409 Conflict`, not `422` | The request is well-formed; the resource/account state forbids the action. Matches the `DomainException` base default and the exact class documented in `domain-exception-handling-spec.md`. |
| `ProfileIncompleteException` identity & location | `App\Exceptions\Profile\ProfileIncompleteException`, `final`, `$errorCode = 'PROFILE_INCOMPLETE'`, inherits `409`, ctor passes the fixed message. | Verbatim the illustrative subclass in `domain-exception-handling-spec.md` §Technical Decisions. It is a Profile-domain rule ("your profile is incomplete") even though the trigger is a routine create. |
| Permanent archival mechanism | Inside `RoutineCreateAction`'s transaction, before the insert: `$user->routines()->where('status', RoutineStatus::Active)->update(['status' => RoutineStatus::Archived->value, 'archived_at' => now()])`. | AC #2. A single scoped `update` — no need to load a model. `archived_at` stays out of `$fillable` (mass-assignment guards `create`/`fill`, not query-builder `update`). Running before the insert means the partial unique index is never violated. |
| Single-active invariant backstop | Postgres/SQLite **partial unique index** `(user_id) WHERE status = 'active'` via raw `DB::statement`. | `docs/plans/data-model.md` §`routines`. Laravel's schema builder has no portable partial-index API; the raw form parses on both engines. The Action makes the normal flow never hit it; the index catches a concurrent double-create. |
| `days_per_cycle` handling | Fixed at `5`: DB column `default 5` **and** `protected $attributes = ['days_per_cycle' => 5]` on the model. Not a validation rule, so a body key is ignored. | AC #3. The model default mirrors the DB default so the freshly-created instance serialises `5` in the `201` body without a `refresh()` round-trip under `preventAccessingMissingAttributes` (a recently-created model returns `null`, not `5`, for an unset defaulted column). |
| `hint` — persistence & shape | New nullable `text` column on `routines`; request-optional; `max:2000`; blank / `""` / whitespace-only → `null` in `prepareForValidation()`; immutable after create in v1. `docs/plans/data-model.md` updated. | `docs/product-context.md` §4 step 8 reads `hint` "de la rutina" when generating cycle N+1 — it must be a persisted routine property, not a job-only parameter. Bound and null-normalised exactly like `athlete_profiles.notes` (protects the AI-prompt budget; the planner never gets `""`). The data model as written omitted it. Confirmed with the product owner. |
| Public identifier | Response `id` = `routines.uuid` (v4), filled by `App\Models\Concerns\HasPublicUuid` on `creating`; `getRouteKeyName() => 'uuid'`. Internal `bigint` PK never exposed. | `docs/plans/data-model.md` §Identificadores. First user of the shared trait; every future API-exposed model reuses it. **Not** Laravel's `HasUuids` (that would turn the PK into a non-incrementing string). |
| Policy | `App\Policies\RoutinePolicy` created now; `create(User): bool => true`; wired via `StoreRoutineRequest::authorize()` (`$user->can('create', Routine::class)`). Auto-discovered. | Establishes the Policy pattern for the by-id routine / cycle endpoints in later tickets, so the codebase gets `app/Policies/` once, here. Zero runtime effect on this endpoint (no `{routine}` id, always the caller's own data). Confirmed with the product owner. |
| DTO | `App\Data\Routine\RoutineData` (`spatie/laravel-data`), `readonly` promoted props `string $name`, `Goal $goal`, `?string $hint = null`. No `#[MapInputName]`. | `CLAUDE.md` convention (writes take a `Data` object). All keys are single words, so no snake→camel mapping is needed — matches `RegisterData`. `validation_strategy = OnlyRequests` → `::from($request->validated())` does not re-validate; the Form Request is the single authority; spatie casts the `goal` string via the global `BackedEnum` cast. |
| Enum storage | Backed **string** enums (`RoutineStatus`, `Goal`); DB columns are `string`, no native Postgres `enum`, no `CHECK`. | Portable across the Postgres runtime and the SQLite test DB. Cast + `Rule::enum` enforce membership. Matches `athlete_profiles` and `docs/plans/data-model.md` §Enums (`TitleCase` cases, DB stores the value). |
| `RoutineStatus` location | `App\Enums\Routine\RoutineStatus` (`active`, `archived`) | Domain-specific → `App\Enums\Routine`, exactly as planned in `docs/plans/data-model.md` §Enums. `Goal` stays in `App\Enums\Shared` (already shipped; routines carry their own `goal`). |
| `GenerateCycleJob` — location & body | `App\Jobs\Cycle\GenerateCycleJob`, `final implements ShouldQueue`, ctor `public Routine $routine`, `handle()` an empty documented stub. | The `Cycle` domain owns it (`CLAUDE.md` layout, `docs/plans/data-model.md`). It must exist to be dispatched (AC #4); the body is filled by the "Recibir el primer ciclo" ticket. A no-op `handle()` is harmless if it ever runs. |
| Job dispatch position | Inside `RoutineCreateAction`'s `DB::transaction` closure, after the insert. | Matches the `CLAUDE.md` example. Tests fake the bus, so commit-ordering is moot; the Cycle ticket can add `ShouldDispatchAfterCommit` when it implements real work. |
| Transaction boundary | The onboarding guard runs **before** `DB::transaction`; the transaction wraps the archival `update` + insert + `dispatch()`. | The guard is a read-only precondition — fail fast, no transaction overhead. The mutations are atomic together. |
| Resource | `App\Http\Resources\Routine\RoutineResource` (`@mixin Routine`): `id => $this->uuid`, `name`, `goal`/`status` via `->value`, `hint`, `days_per_cycle`, `archived_at` / `created_at` / `updated_at` via `?->toIso8601String()`. No `id` PK, no relations. | `CLAUDE.md` rule 3 (always a real `JsonResource`; `response()->json` banned). Mirrors `AthleteProfileResource`. Default Laravel `data`-wrapping (never disabled in this project) gives the `{ "data": ... }` envelope. |
| No GET / list here | Only `POST` in this ticket. | "Listar mis rutinas" (Order 50) owns `GET /api/v1/routines` + the collection Resource. Keeps the PR to one endpoint. |
| Rate limiting | None on the route. | Authenticated, low-abuse; matches the profile routes. `register` / `login` carry `throttle:6,1` only because they are public. |
| Scramble `security_strategy` | **No `config/scramble.php` change** — `MiddlewareAuthSecurityStrategy` with `['auth:sanctum']` is already on `main`. Only a `DocsSecurityTest` assertion is added. | The new route matches that middleware, so it is documented as secured automatically. |
| Model strictness | The incumbent is archived with a scoped query-builder `update` (no model load); the Resource reads only own columns; `protected $attributes` supplies `days_per_cycle`. | `Model::shouldBeStrict(!isProduction())` makes a lazy relation load and a missing-attribute access throw outside production. Nothing in the pipeline touches `$routine->user` or an unset attribute. |
| Tests: DB & queue | SQLite `:memory:` + `RefreshDatabase` (already wired); `Bus::fake()` per feature test; no `phpunit.xml` change. | Not the first DB-touching suite. `QUEUE_CONNECTION=sync` in tests means an un-faked dispatch would run the (stub) job inline; `Bus::fake()` both prevents that and enables `assertDispatched`. |
| Git artifacts | English only; **no** `Co-Authored-By: Claude` / `Claude-Session:` commit trailers; PR description with the `🤖 Generated with Claude Code` footer only. | Repo `CLAUDE.md` / `AGENTS.md` rule; it takes precedence over the session's attribution instruction, as the register / profile / login specs each note. |

---

## 10. Work Plan

Pipeline classes are created before wiring `routes/api.php` (which references
them). Each task's DoD is limited to the artifact existing, passing Pint +
PHPStan level 6, and — where the class carries logic — its focused test, authored
in the same task. Tasks 17–18 are the functional gate.

| # | Task | Definition of Done |
|---|---|---|
| 1 | Clone the runtime DB for isolation: `docker compose exec pgsql createdb -U gym -T gym_trainer gym_trainer_create_routings`; set `DB_DATABASE=gym_trainer_create_routings` in this worktree's `.env` | `php artisan db:show` (in the worktree toolchain) targets the clone; `gym_trainer` is untouched; the Pest suite still uses SQLite. |
| 2 | Create `app/Models/Concerns/HasPublicUuid.php`: `bootHasPublicUuid()` sets `uuid` to `(string) Str::uuid()` on `creating` when `blank($model->uuid)`; `getRouteKeyName(): string => 'uuid'` | File exists; Pint + PHPStan level 6 clean; a model using the trait gets a v4 `uuid` on `create()` and `->getRouteKeyName() === 'uuid'`. |
| 3 | Create `app/Enums/Routine/RoutineStatus.php` — string-backed, `case Active = 'active'`, `case Archived = 'archived'` | File exists; `RoutineStatus::from('archived') === RoutineStatus::Archived`; Pint + PHPStan clean. |
| 4 | Create the migration `<ts>_create_routines_table.php` per §4.1 (anonymous class; `uuid` unique; `user_id` `constrained()->cascadeOnDelete()`; `goal` / `status` as `string`; `hint` nullable text; `days_per_cycle` `unsignedSmallInteger` default 5; `archived_at` nullable timestamp; raw partial unique index). Add the `hint` row to `docs/plans/data-model.md` §`routines` | `php artisan migrate` runs on the clone and on a fresh SQLite; `php artisan db:table routines` shows the `user_id` FK and the `routines_user_id_active_unique` partial index; `data-model.md` lists `hint`. |
| 5 | Create `app/Models/Routine.php`: `use HasFactory, HasPublicUuid;` · `#[Fillable(['name', 'goal', 'hint', 'status'])]` · `protected $attributes = ['days_per_cycle' => 5]` · `casts()` (`goal` → `Goal::class`, `status` → `RoutineStatus::class`, `archived_at` → `immutable_datetime`, `days_per_cycle` → `integer`) · `user(): BelongsTo`. Add `routines(): HasMany` + `@property-read` PHPDoc to `app/Models/User.php` | Pint + PHPStan clean; `(new Routine)->getCasts()` has the `goal` / `status` enum casts; `(new User)->routines()` returns a `HasMany`. |
| 6 | Run `php artisan ide-helper:models --write` for `Routine` + `User`; `vendor/bin/pint app/Models`; hand-check the enum-cast `@property` lines and the `HasPublicUuid` `@method` | The PHPDoc blocks list every column / relation; the diff is limited to the two models; CI's "Model PHPDoc is up to date" step would pass. |
| 7 | Create `database/factories/RoutineFactory.php`: `user_id => User::factory()`, `name => fake()->words(2, true)`, `goal => fake()->randomElement(Goal::cases())`, `hint => fake()->optional()->sentence()`, `status => RoutineStatus::Active`; state `archived()` → `status => RoutineStatus::Archived`, `archived_at => now()` | `Routine::factory()->create()` and `Routine::factory()->archived()->create()` each persist one row with a `uuid`; `User::factory()->has(Routine::factory())->create()` works; Pint + PHPStan clean. |
| 8 | Create `app/Data/Routine/RoutineData.php` via `make:data`, move into `app/Data/Routine/`, fix the namespace; `readonly` promoted props `string $name`, `Goal $goal`, `?string $hint = null`. Write `tests/Feature/Routine/RoutineDataTest.php` (TC-21) | `vendor/bin/pest tests/Feature/Routine/RoutineDataTest.php` green; Pint + PHPStan clean. |
| 9 | Create `app/Exceptions/Profile/ProfileIncompleteException.php`: `final extends DomainException`; `protected string $errorCode = 'PROFILE_INCOMPLETE'`; `__construct()` → `parent::__construct('Complete your athlete profile before creating a routine.')` | File exists; `(new ProfileIncompleteException)->errorCode() === 'PROFILE_INCOMPLETE'` and `->statusCode() === 409`; Pint + PHPStan clean. |
| 10 | Create `app/Policies/RoutinePolicy.php`: `create(User $user): bool { return true; }`. Write `tests/Unit/Routine/RoutinePolicyTest.php` (TC-22) | `vendor/bin/pest tests/Unit/Routine/RoutinePolicyTest.php` green; `Gate::forUser($user)->allows('create', Routine::class)` is `true`; Pint + PHPStan clean. |
| 11 | Create `app/Http/Requests/Routine/StoreRoutineRequest.php`: `authorize()` → `$this->user()->can('create', Routine::class)`; `rules()` per §2.1; `prepareForValidation()` collapsing blank / whitespace `hint` → `null` | File exists; extends `FormRequest`; Pint + PHPStan clean; a unit assertion that `goal = 'powerlifting'` fails and a whitespace-only `hint` becomes `null`. |
| 12 | Create `app/Http/Resources/Routine/RoutineResource.php` (`@mixin Routine`): `id => $this->uuid`, `name`, `goal` / `status` via `->value`, `hint`, `days_per_cycle`, `archived_at` / `created_at` / `updated_at` via `?->toIso8601String()`; **no** internal `id`, no relations | File exists; Pint + PHPStan clean; `toArray()` has no `user`/`user_id` key and `id` is the `uuid`. |
| 13 | Create `app/Jobs/Cycle/GenerateCycleJob.php` (`final implements ShouldQueue; use Queueable;`), ctor `public Routine $routine`, `handle(): void` empty with a docblock pointing to the "Recibir el primer ciclo" ticket | File exists; Pint + PHPStan clean; `GenerateCycleJob::dispatch($routine)` compiles. |
| 14 | Create `app/Actions/Routine/RoutineCreateAction.php` (`final`, no constructor): private `ensureOnboardingComplete(User)` → `throw_if($user->athleteProfile()->doesntExist(), new ProfileIncompleteException)`; `handle(User, RoutineData): Routine` calls it, then `DB::transaction`: scoped `->where('status', RoutineStatus::Active)->update([...archived...])` → `$user->routines()->create([...'status' => RoutineStatus::Active])` → `GenerateCycleJob::dispatch($routine)` → return `$routine`. Write `tests/Feature/Routine/RoutineCreateActionTest.php` (TC-19, TC-20) | `final` + `handle()`; `vendor/bin/pest tests/Feature/Routine/RoutineCreateActionTest.php` green; Pint + PHPStan clean. |
| 15 | Create `app/Http/Controllers/Routine/StoreRoutineController.php` via `make:controller --invokable`, move + fix namespace: build `RoutineData::from($request->validated())`, call the Action, return `RoutineResource::make($routine)->response()->setStatusCode(Response::HTTP_CREATED)` | `final`, `__invoke` only; Pint + PHPStan clean. |
| 16 | Edit `routes/api.php`: add the `use` import and, in the existing `auth:sanctum` group, `Route::post('routines', StoreRoutineController::class)->name('routines.store')` | `php artisan route:list` shows `POST api/v1/routines` with `auth:sanctum`; PHPStan clean in `routes/`. |
| 17 | Write `tests/Feature/Routine/StoreRoutineTest.php` covering TC-1 … TC-18 (`beforeEach` sets the `Origin` header + `Bus::fake()`) | `vendor/bin/pest tests/Feature/Routine/StoreRoutineTest.php` all green; every TC-1 … TC-18 has a corresponding test. |
| 18 | Add `arch('routine controllers are invokable')->expect('App\Http\Controllers\Routine')->toBeInvokable()` to `tests/Feature/ArchTest.php` (TC-23); add the `/v1/routines` `post` assertion to `tests/Feature/Auth/DocsSecurityTest.php` (TC-24) | `vendor/bin/pest tests/Feature/ArchTest.php tests/Feature/Auth/DocsSecurityTest.php` green. |
| 19 | Run `vendor/bin/pint --dirty`, then `vendor/bin/phpstan analyse`, then a final `php artisan ide-helper:models --write` + `vendor/bin/pint app/Models` | Pint reports no diffs; PHPStan level 6 clean; the model PHPDoc is in sync with the migration. |
| 20 | Run `composer check` (Pint `--test` + PHPStan level 6 + full Pest — including the new `Routine` tests, the `RoutinePolicyTest`, the `DocsSecurityTest` addition and the arch rule) | All three steps green; no regression in Auth / Profile suites. |
| 21 | Manual check with `curl` against `http://localhost:8000`: `GET /sanctum/csrf-cookie` → register + login → `PUT /api/v1/profile` → `POST /api/v1/routines` (`201`, `status: active`) → `POST` again (`201`, previous routine now `archived`) → a second user with no profile → `POST` (`409`, `PROFILE_INCOMPLETE`) → invalid `goal` (`422` on the field) → no session (`401`). Review `GET /docs/api` | The `curl` calls return the expected codes; the endpoint appears in Scramble with the request inferred from `StoreRoutineRequest` and the response from `RoutineResource`, marked secured. |
| 22 | On merge / branch drop: `docker compose exec pgsql dropdb -U gym --if-exists gym_trainer_create_routings`; revert `DB_DATABASE` in the worktree `.env` | The clone is gone; `.env` is restored to `gym_trainer`. |

*Process note: branch name, commit messages and PR text follow `CLAUDE.md` /
`AGENTS.md` — English only, no `Co-Authored-By: Claude` / `Claude-Session:`
trailers, `🤖 Generated with Claude Code` footer in the PR description only.*
