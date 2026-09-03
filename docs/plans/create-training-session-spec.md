# Create a training session — `POST /api/v1/routines/{routine}/sessions`

> Derived from the Notion ticket "Crear una sesión de entrenamiento" (Feature:
> Registro de sesiones · MVP · Must · Repo: API · Order 100) and the approved
> plan (`.claude/plans/tenemos-un-nuevo-requerimiento-clever-treasure.md`). Base
> contract: `docs/plans/product-context.md` §2 (Terminología — *Sesión*,
> *Día del ciclo*) / §4 (step 4) / §6 / §7, `docs/plans/data-model.md`
> §`sessions` + §Identificadores + §Enums, `CLAUDE.md` "The pipeline" (its worked
> example is `StoreRoutineController` / `RoutineCreateAction`),
> `docs/plans/create-routine-spec.md` and `docs/plans/generate-first-cycle-spec.md`
> (pipeline + cycle reference implementations),
> `docs/plans/domain-exception-handling-spec.md` (the error envelope and the
> `DomainException` base this ticket adds three subclasses to).

## 1. Context

**Kind:** Greenfield Feature (with one in-scope brownfield change — the first
cycle is born `active` instead of `draft`).

**Stack:** PHP 8.5 · Laravel 13 · PostgreSQL 17 (runtime) / SQLite `:memory:`
(tests) · Pest 4 (`pest-plugin-laravel`) · `laravel/sanctum` 4 (SPA cookie
mode) · `spatie/laravel-data` 4 · `dedoc/scramble` 0.13 · Pint · Larastan
level 6. Everything runs in Docker.

**Problem statement:** The API can create a routine and its first weekly cycle
(days + per-exercise prescription), but there is no way to record that the user
actually trained. The training-logging loop
(`docs/plans/product-context.md` §4 step 4) starts by **opening a session** —
"un día de entrenamiento realmente ejecutado" — which is then filled set by set
and finally closed for AI analysis. This ticket adds the single write endpoint
that opens a session, nested under its routine: `POST /api/v1/routines/{routine}/sessions`.
A session is created either **for a day of the routine's active cycle** (the
client sends that day's id) or as a **free / off-plan session** (no day). It is
born `in_progress`, empty, ready to receive sets. It also lands the first slice
of the Session domain (table, model, enums, policy, factory) and makes the
first cycle be born `active` (there is no "activate a cycle" step in the MVP).

**In scope:**
- `POST /api/v1/routines/{routine}/sessions` — open a training session for the
  authenticated user under `{routine}`. Optional body `day` = a `CycleDay`
  `uuid`; present → planned session validated against the routine's active
  cycle, absent → free session (`cycle_day_id` null). Returns `201` with the
  created session and, for a planned session, the linked cycle day and its
  prescription tree.
- The `training_sessions` table + `App\Models\TrainingSession` +
  `User::trainingSessions()` relation + `TrainingSessionFactory`.
- `App\Enums\Session\SessionStatus` (`in_progress`, `completed`) and
  `App\Enums\Session\AnalysisState` (`pending`, `processing`, `done`, `failed`) —
  matching `docs/plans/data-model.md` §Enums. Only `in_progress` / `pending` are
  written here.
- `App\Data\Session\CreateTrainingSessionData` — the request DTO (`?string $day`).
- `App\Http\Requests\Session\StoreTrainingSessionRequest` — shape validation
  (`day` nullable uuid, `exists:cycle_days,uuid`) + authorization via
  `TrainingSessionPolicy`.
- `App\Http\Controllers\Session\StoreTrainingSessionController` — invokable.
- `App\Actions\Session\TrainingSessionCreateAction` — resolve the day, run the
  opening guard, open the session in a transaction, return it with the cycle
  tree eager-loaded.
- `App\Services\Session\TrainingSessionOpeningService` — the three business
  invariants of opening a session (routine active, day belongs to the active
  cycle, no other open session for the user), each raising a `DomainException`
  subclass.
- `App\Exceptions\Session\{SessionInProgressException, RoutineNotActiveException,
  CycleDayNotInActiveCycleException}` — `final`, `DomainException` subclasses,
  `409`, distinct `code`s, rendered by the existing `ApiExceptionRenderer` with
  no wiring.
- `App\Http\Resources\Session\TrainingSessionResource` — the `201` body; embeds
  `App\Http\Resources\Cycle\CycleDayResource` (→ `DayExerciseResource`) for a
  planned session, `null` for a free one.
- `App\Policies\TrainingSessionPolicy` — `create(User, Routine)` returns
  `$routine->user_id === $user->id`; auto-discovered; wired through the Form
  Request.
- One route added to the `auth:sanctum` group in `routes/api.php`
  (name `routines.sessions.store`), with `->whereUuid('routine')`.
- **First cycle born `active`:** add `CycleStatus::Incomplete`; change
  `CycleDraftService::persist()` to write `CycleStatus::Active` +
  `activated_at = now()`; update `CycleFactory` (default state + a `draft()` /
  `incomplete()` state) and the three tests that assert `'draft'`
  (`StoreRoutineTest`, `CycleDraftServiceTest`, `RoutineCreateActionTest`).
- **Doc update:** rename the `sessions` table to `training_sessions` throughout
  `docs/plans/data-model.md` (heading, ER diagram, `set_logs` /
  `exercise_recommendations` FK references, the "Llevan `uuid`" list,
  decision #6) and add a one-line note about the Laravel session-store clash.
- `tests/Feature/ArchTest.php` — add `App\Http\Controllers\Session` invokable
  rule; `tests/Feature/Auth/DocsSecurityTest.php` — assert the new route
  inherits the global `security`.
- Pest feature + focused unit coverage of every acceptance criterion.

**Out of scope:**
- Logging, editing or deleting sets (`set_logs` table, `POST/PUT/DELETE`
  `/api/v1/sessions/{session}/sets`) — ticket "Registrar, editar y borrar
  series de una sesión" (Order 110).
- Completing a session (`POST /api/v1/sessions/{session}/complete`,
  `in_progress → completed`, `completed_at`, dispatching the analysis job) —
  ticket "Completar una sesión" (Order 120). **Consequence:** until Order 120
  ships there is no way to close or abandon an open session, so a user with a
  stuck `in_progress` session cannot open a new one (they get `409`
  `SESSION_IN_PROGRESS`). Accepted — Order 120 is the next story.
- The `exercise_recommendations` table, the session-analysis job and agent, the
  `analysis_state` transitions past `pending` — ticket "Recibir recomendaciones
  al cerrar el día" (Order 130). `analysis_state` ships as a column defaulting
  to `pending` and is never changed by this endpoint. `conversation_id` ships
  as a nullable column (faithful to `data-model.md`) and is never written here.
- Listing sessions (`GET`), reading a session by id, the session history —
  "Listar el historial de sesiones" (Order 330, non-MVP).
- Any `days_per_cycle` other than 5; multiple concurrent cycles; the rollover of
  a cycle to `completed` / `incomplete` — "Generar el ciclo siguiente"
  (Order 150). This ticket only *adds* the `Incomplete` enum case and makes the
  first cycle `active`; nothing transitions a cycle here.
- The `gym-trainer-spa/` frontend (separate repository).

---

## 2. API Surface

### 2.1 REST

The route joins the existing `Route::middleware('auth:sanctum')->group(...)` in
`routes/api.php`, under the global `apiPrefix: 'api/v1'`. It is stateful
(`$middleware->statefulApi()` in `bootstrap/app.php`) — subject to
`EnsureFrontendRequestsAreStateful` + CSRF.

| Method | Path | Auth | Request | Response | Status codes |
|---|---|---|---|---|---|
| POST | `/api/v1/routines/{routine}/sessions` | `auth:sanctum` (session cookie, `web` guard) + `TrainingSessionPolicy::create` | JSON: `day` (string, **optional/nullable**, must be a v4 uuid that exists in `cycle_days.uuid`). Any other key is ignored. Empty body `{}` is valid. | `{ "data": { "id": string uuid, "status": "in_progress", "analysis_state": "pending", "started_at": string ISO-8601, "completed_at": null, "created_at": string ISO-8601, "updated_at": string ISO-8601, "cycle_day": { "id": string uuid, "order": int, "label": string, "focus_muscle_groups": string[], "rationale": string, "exercises": [ { "id": string uuid, "order": int, "name": string, "sets": int, "rep_min": int, "rep_max": int, "target_weight_kg": number, "target_rpe": number\|null, "rest_seconds": int, "rationale": string } ] } \| null } }` | `201` created · `422` validation (`day` not a uuid / not found in `cycle_days`) · `409` `SESSION_IN_PROGRESS` · `409` `ROUTINE_NOT_ACTIVE` · `409` `CYCLE_DAY_NOT_IN_ACTIVE_CYCLE` · `401` unauthenticated · `403` `AUTHORIZATION_EXCEPTION` (`{routine}` belongs to another user) · `404` `NOT_FOUND_EXCEPTION` (`{routine}` uuid unknown or not a uuid) · `419` stateful request without a valid CSRF token |

Notes:
- **`{routine}` binding.** `->whereUuid('routine')` — a non-uuid segment never
  matches the route → `404`. Implicit binding resolves `{routine}` by `uuid`
  (`HasPublicUuid::getRouteKeyName()`); an unknown uuid → `ModelNotFoundException`
  → `404` (`NOT_FOUND_EXCEPTION`, message `"Resource not found."`). The Policy
  runs after binding: a routine owned by another user → `AuthorizationException`
  → `403`.
- **`day` present → planned session.** `day` is the `CycleDay`'s public `uuid`.
  The `training_sessions.cycle_day_id` FK is set from it. The session's cycle day
  must be a day of `{routine}`'s **current cycle** (`Routine::cycle()` — the
  highest `sequence_number`, which in v1 is the only cycle) **and** that cycle's
  `status` must be `active`. Otherwise `409` `CYCLE_DAY_NOT_IN_ACTIVE_CYCLE`
  (this covers "a day from another user's routine", "a day from an older cycle",
  and the defensive "the cycle is not active"). The `exists:cycle_days,uuid`
  rule only proves the row exists — the cross-entity check is the Service's job
  (§9).
- **`day` absent / `null` / `""` → free session.** `cycle_day_id` is `null`;
  `routine_id` is still `{routine}`. `data.cycle_day` is `null` in the body.
  `docs/plans/data-model.md` §`sessions`: "una sesión libre tiene
  `cycle_day_id = null` pero **siempre** `routine_id`". `prepareForValidation()`
  collapses `""` / whitespace-only `day` to `null`, mirroring `routines.hint`.
- **`{routine}` must be `active`.** For both planned and free sessions, if
  `{routine}.status` is `archived` the request returns `409` `ROUTINE_NOT_ACTIVE`
  — the product only logs against the active routine
  (`docs/plans/product-context.md` §6). An archived routine's history stays
  readable; you cannot add to it.
- **One open session per user.** If the user has **any** `training_sessions` row
  with `status = 'in_progress'` (under any routine), the request returns `409`
  `SESSION_IN_PROGRESS`. A partial unique index on `(user_id) WHERE status =
  'in_progress'` is the concurrency backstop (§4.1); a pure double-submit race
  that beats the guard surfaces as a `500` from the `QueryException`, the same
  tradeoff `create-routine-spec.md` takes for its partial index.
- **Re-training a day is allowed.** `training_sessions.cycle_day_id` is **not**
  unique (`docs/plans/data-model.md` §`sessions`). A `completed` session already
  pointing at that `CycleDay` does not block a new one.
- **Server-set fields.** `status = SessionStatus::InProgress`,
  `started_at = now()`, `analysis_state` defaults to `'pending'` at the DB level
  (the endpoint never sets it), `completed_at = null`, `conversation_id = null`.
- **The `201` body carries the cycle-day tree** (planned session) so the SPA
  logging screen (Order 230) has the prescription in one call. It reuses
  `CycleDayResource` → `DayExerciseResource` unchanged. There is **no** `routine`
  block — the client already holds the routine id (it is in the URL).
- Errors are rendered as JSON by `App\Exceptions\ApiExceptionRenderer` (wired for
  `api/*` in `bootstrap/app.php`) as
  `{ "data": { "code": "...", "message": "..." } }`, with a `data.errors` map
  only for `VALIDATION_EXCEPTION`. No hand-built JSON. CSRF is auto-bypassed
  under `php artisan test` (`ValidateCsrfToken::runningUnitTests()`).

### 2.2 CLI

Not applicable — no CLI commands.

### 2.3 Events

Not applicable — no domain events and no jobs. Dispatching the session-analysis
job happens when a session is **completed** (Order 120), not when it is opened.
Eloquent's model events fire on the insert but the project registers no
listeners for `TrainingSession`, and none are added here.

---

## 3. UI

### 3.1 Pages

Not applicable — no pages affected. This is a JSON REST API; the
"registrar sesión" screen (Order 230) lives in `gym-trainer-spa/`.

### 3.2 Components

Not applicable — no components affected.

---

## 4. Database

### 4.1 Schema changes

| Table | Action | Details |
|---|---|---|
| `training_sessions` | Create | `id` bigint PK · `uuid` uuid **`unique`** (public id / route key; filled by `HasPublicUuid` on `creating`) · `user_id` bigint FK → `users.id` `constrained()->cascadeOnDelete()` · `routine_id` bigint FK → `routines.id` `constrained()->cascadeOnDelete()` · `cycle_day_id` bigint **nullable** FK → `cycle_days.id` `nullable()->constrained()->nullOnDelete()` · `status` string (stores the `SessionStatus` backed value) · `analysis_state` string **default `'pending'`** (stores the `AnalysisState` backed value) · `started_at` `timestamp` · `completed_at` `timestamp` **nullable** · `conversation_id` `string(36)` **nullable** (no FK — mirrors `cycles.conversation_id`) · `created_at` / `updated_at` timestamps |

- Migration file:
  `database/migrations/<timestamp>_create_training_sessions_table.php`, anonymous
  class `return new class extends Migration`. `<timestamp>` sorts **after**
  `2026_09_02_140003_create_day_exercises_table.php`.
- **Table name.** `data-model.md` calls it `sessions`; Laravel already ships a
  `sessions` table (the session store, created in
  `0001_01_01_000000_create_users_table.php`). The domain table is therefore
  **`training_sessions`** and the model **`TrainingSession`** (§9). The doc is
  updated to match.
- **Partial unique index** — one open session per user
  (`docs/plans/data-model.md` §`sessions`, same technique as
  `routines_user_id_active_unique`): after `Schema::create`, a raw
  `DB::statement("CREATE UNIQUE INDEX training_sessions_user_in_progress_unique ON training_sessions (user_id) WHERE status = 'in_progress'")`.
  The `WHERE status = 'in_progress'` form parses on PostgreSQL 17 and SQLite
  `:memory:`. Backstop only — `TrainingSessionOpeningService` rejects a second
  open session with a clean `409` in the normal flow.
- **FK deletes.** `user_id` / `routine_id` cascade — a session is meaningless
  without either. `cycle_day_id` is `nullOnDelete` — a session **does** retain
  meaning if its planned day is later removed (it degrades to a historical
  free session); it must not disappear with the day. `data-model.md` FK
  convention: cascade only when the child has no meaning without the parent.
- Enum columns (`status`, `analysis_state`) are **plain `string`**, no native
  Postgres `enum`, no `CHECK` — portable across the Postgres runtime and the
  SQLite test DB; membership is guarded by the backed-enum cast on the model and
  by `Rule::enum` where user input reaches an enum (not the case here — the user
  never sets `status`). Matches `routines` / `cycles`.
- `started_at` / `completed_at` are plain `timestamp` columns matching the
  codebase's `$table->timestamps()` style (`data-model.md` labels them
  `timestamptz`; `cycles.activated_at` etc. use plain `timestamp` and this
  follows suit).
- No soft deletes (`docs/plans/data-model.md`): history is kept by `status`.
- **Doc update:** in `docs/plans/data-model.md`, rename `sessions` →
  `training_sessions` in the §`sessions` heading, the Mermaid ER block
  (`users ||--o{ training_sessions`, `routines ||--o{ training_sessions`,
  `cycle_days |o--o{ training_sessions`, `training_sessions ||--o{ set_logs`,
  `training_sessions |o--o{ exercise_recommendations`), the `set_logs.session_id`
  and `exercise_recommendations.source_session_id` FK target text, the
  "**Llevan `uuid`:**" list, and decision #6 ("Se deriva por
  `training_sessions.cycle_day_id`"). Add under the §`training_sessions`
  heading: *"Tabla `training_sessions` (no `sessions`): Laravel ya usa `sessions`
  para el session store."*
- **Database isolation (`CLAUDE.md` → "Workflows — database isolation"):** this
  branch adds a migration, so `gym_trainer` must not be migrated directly.
  Before `migrate` against Postgres:
  `docker compose exec pgsql createdb -U gym -T gym_trainer gym_trainer_create_trainning_sessions`,
  then set `DB_DATABASE=gym_trainer_create_trainning_sessions` in this worktree's
  `.env`. Drop the clone
  (`dropdb -U gym --if-exists gym_trainer_create_trainning_sessions`) and revert
  `.env` on merge. The Pest suite is unaffected — SQLite `:memory:`.

### 4.2 Seeds

Not applicable — no seeds.

---

## 5. Auth & Authorization

### 5.1 Authentication

**Method:** Session (cookie) via **Laravel Sanctum in SPA / stateful mode** — the
same mechanism as every other endpoint. `auth:sanctum` on the route group
authenticates from the session cookie; unauthenticated → `AuthenticationException`
→ `401` JSON. `POST` is a stateful non-GET request → requires a valid
`XSRF-TOKEN` (`419` otherwise), auto-bypassed under `php artisan test`.

### 5.2 Authorization

**`TrainingSessionPolicy`**, wired through the Form Request. The route carries a
`{routine}` segment, so — unlike `POST /api/v1/routines` — a `403` is reachable
and meaningful here.

| Role | Permissions |
|---|---|
| Authenticated user | Open a training session under a routine **they own**. `TrainingSessionPolicy::create(User $user, Routine $routine): bool` returns `$routine->user_id === $user->id`. No other permission, no other actor. |

- `App\Policies\TrainingSessionPolicy` is auto-discovered by Laravel 13 for
  `App\Models\TrainingSession` (`App\Policies\{Model}Policy` convention) — no
  `AuthServiceProvider` / `Gate::policy()` wiring, matching `RoutinePolicy`.
- `StoreTrainingSessionRequest::authorize()` returns
  `$this->user()?->can('create', [TrainingSession::class, $this->route('routine')])`.
  Route-model binding runs before Form Request authorization, so
  `$this->route('routine')` is the bound `Routine` (or the request 404s first).
- The routine's `active` / `archived` state is **not** an authorization decision
  — it is a business rule, enforced by `TrainingSessionOpeningService` which
  throws `RoutineNotActiveException` (`409`), not the Policy (§9). An owned but
  archived routine → `403`? No — `409`. Ownership is the Policy's only concern.

---

## 6. Configuration

**Environment variables:**

| Variable | Value / Source | Purpose |
|---|---|---|
| `DB_DATABASE` | `gym_trainer_create_trainning_sessions` (this worktree's `.env` only, during development) | Points the worktree at a clone of `gym_trainer` so the new migration never runs against the shared database. Reverted on merge. |

No new keys in `.env.example`. `phpunit.xml` already carries everything the tests
need (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `QUEUE_CONNECTION=sync`,
`SANCTUM_STATEFUL_DOMAINS=localhost`, `APP_URL=http://localhost`);
`RefreshDatabase` is already active for the `Feature` suite in `tests/Pest.php`.

**Config / non-source files modified:**

| File | Change |
|---|---|
| `routes/api.php` | Add a `use` import for `StoreTrainingSessionController` and, inside the existing `auth:sanctum` group, `POST routines/{routine}/sessions` → `StoreTrainingSessionController` (`routines.sessions.store`), `->whereUuid('routine')`. |
| `docs/plans/data-model.md` | Rename `sessions` → `training_sessions` throughout and add the session-store clash note (§4.1). |
| `tests/Feature/ArchTest.php` | Add `arch('session controllers are invokable')->expect('App\Http\Controllers\Session')->toBeInvokable();`. |
| `tests/Feature/Auth/DocsSecurityTest.php` | Add `->and($spec['paths']['/api/v1/routines/{routine}/sessions']['post'])->not->toHaveKey('security')` to the existing assertion chain. |

No change to `bootstrap/app.php`, `config/*`, `bootstrap/providers.php`,
`phpunit.xml`, `composer.json`.

---

## 7. Current vs New Behavior

| Behavior | Current | New |
|---|---|---|
| Session domain | No table, no model, no enum, no policy, no factory. `app/*/Session/` do not exist. | `training_sessions` table; `App\Models\TrainingSession`; `App\Enums\Session\{SessionStatus, AnalysisState}`; `App\Policies\TrainingSessionPolicy`; `TrainingSessionFactory`; the full `Session` pipeline folders. |
| Opening a session | Impossible. | `POST /api/v1/routines/{routine}/sessions` opens one — planned (against a day of the active cycle) or free — born `in_progress` / `analysis_state = pending`, empty. |
| One open session per user | Not represented. | Enforced by `TrainingSessionOpeningService` (`409` `SESSION_IN_PROGRESS`) and a partial unique index `(user_id) WHERE status = 'in_progress'`. |
| First cycle status | `CycleDraftService` writes `CycleStatus::Draft`; the `201` from `POST /api/v1/routines` shows `data.cycle.status = "draft"`; `CycleFactory` default is `Draft`. | `CycleDraftService` writes `CycleStatus::Active` + `activated_at = now()`; the `201` shows `"active"` with a non-null `activated_at`; `CycleFactory` default is `Active` (+ `draft()` / `incomplete()` states). There is no "activate a cycle" step in the MVP. |
| `CycleStatus` enum | `generating`, `draft`, `active`, `completed`, `failed`. | Adds `incomplete` (used by the rollover story, Order 150; added now per the Order 60 backlog note). |
| `User` model | `athleteProfile(): HasOne`, `routines(): HasMany`. | Adds `trainingSessions(): HasMany` + `@property-read` PHPDoc. |
| Domain exceptions | `ProfileIncompleteException` (`409`), `CycleGenerationException` (`502`). | Adds `SessionInProgressException`, `RoutineNotActiveException`, `CycleDayNotInActiveCycleException` — all `409`, under `App\Exceptions\Session\`. |
| Authenticated routes | `auth:sanctum` group holds auth, profile and routine routes. | Adds `POST routines/{routine}/sessions`. First **nested** write route; first route where a `403` from a Policy is reachable on a write. |
| Session-touching tests | None. | `tests/Feature/Session/` (endpoint + action files), `tests/Unit/Session/TrainingSessionPolicyTest.php`, one `ArchTest` rule, one `DocsSecurityTest` assertion; edits to `StoreRoutineTest` / `CycleDraftServiceTest` / `RoutineCreateActionTest` for the `active`-cycle change. |
| OpenAPI | Scramble documents every `auth:sanctum` route as secured via the global scheme. | `POST /api/v1/routines/{routine}/sessions` is documented automatically (request from `StoreTrainingSessionRequest`, response from `TrainingSessionResource`); `DocsSecurityTest` asserts it. |

---

## 8. Test Cases

Executable with Pest 4 on SQLite `:memory:` (`RefreshDatabase`, already wired).
Every feature test's `beforeEach` sets
`$this->withHeader('Origin', config('app.url'))`, then
`$this->user = User::factory()->create()` and
`AthleteProfile::factory()->for($this->user)->create()`. AI is never called —
these tests build cycles with factories, not the planner.

A helper at the top of `tests/Feature/Session/StoreTrainingSessionTest.php`
builds an active routine with a real cycle tree:

```php
function activeRoutineWithCycle(User $user): Routine
{
    $routine = Routine::factory()->for($user)->create(); // status: active
    $cycle = Cycle::factory()->active()->for($routine)->create();
    CycleDay::factory()->count(5)->for($cycle)->sequence(
        fn ($seq) => ['order' => $seq->index + 1]
    )->create()->each(
        fn (CycleDay $d) => DayExercise::factory()->count(3)->for($d)->sequence(
            fn ($seq) => ['order' => $seq->index + 1]
        )->create()
    );

    return $routine;
}
```

### POST `/api/v1/routines/{routine}/sessions` — `tests/Feature/Session/StoreTrainingSessionTest.php`

**TC-1:** Planned session — happy path (AC: "asociada a un día del ciclo activo", "nace sin completar")
- **Given:** an authenticated user; `$routine = activeRoutineWithCycle($user)`; `$day = $routine->cycle->cycleDays->first()`
- **When:** `POST /api/v1/routines/{$routine->uuid}/sessions` with `{ "day": $day->uuid }`
- **Expect:** `201`; `data.id` matches a v4 uuid regex; `data.status === "in_progress"`; `data.analysis_state === "pending"`; `data.started_at` matches ISO-8601; `data.completed_at === null`; `data.cycle_day.id === $day->uuid`; `assertDatabaseHas('training_sessions', ['uuid' => data.id, 'user_id' => $user->id, 'routine_id' => $routine->id, 'cycle_day_id' => $day->id, 'status' => 'in_progress', 'analysis_state' => 'pending', 'completed_at' => null])`; `assertDatabaseCount('training_sessions', 1)`

**TC-2:** Free session — no `day` (AC: "o como sesión libre (fuera de plan)")
- **Given:** an authenticated user; `$routine = activeRoutineWithCycle($user)`
- **When:** `POST /api/v1/routines/{$routine->uuid}/sessions` with `{}`
- **Expect:** `201`; `data.cycle_day === null`; `assertDatabaseHas('training_sessions', ['user_id' => $user->id, 'routine_id' => $routine->id, 'cycle_day_id' => null, 'status' => 'in_progress'])`

**TC-3:** `day` sent as `null` / `""` / whitespace → free session (dataset)
- **Given:** an authenticated user; `$routine = activeRoutineWithCycle($user)`
- **When:** `POST` with `{ "day": null }`, then `{ "day": "" }`, then `{ "day": "   " }`
- **Expect:** each `201`; `data.cycle_day === null`; `cycle_day_id` is `null` in the row

**TC-4:** The embedded `cycle_day` carries the full prescription tree
- **Given:** an authenticated user; `$routine = activeRoutineWithCycle($user)`; `$day` = its first cycle day (3 `day_exercises`)
- **When:** `POST` with `{ "day": $day->uuid }`
- **Expect:** `201`; `assertJsonStructure(['data' => ['cycle_day' => ['id', 'order', 'label', 'focus_muscle_groups', 'rationale', 'exercises' => [['id', 'order', 'name', 'sets', 'rep_min', 'rep_max', 'target_weight_kg', 'target_rpe', 'rest_seconds', 'rationale']]]]])`; `data.cycle_day.exercises` has 3 entries ordered by `order`

**TC-5:** Response exposes uuids only, never internal ids, and no `routine` block
- **Given:** an authenticated user; planned-session request as TC-1
- **When:** `POST` with `{ "day": $day->uuid }`
- **Expect:** `201`; `data.id` is the row's `uuid`; `assertJsonMissingPath('data.user_id')`, `assertJsonMissingPath('data.routine_id')`, `assertJsonMissingPath('data.routine')`, `assertJsonMissingPath('data.cycle_day.cycle_id')`, `assertJsonMissingPath('data.cycle_day.exercises.0.exercise_id')`; `data.cycle_day.id` matches the uuid regex

**TC-6:** Enums serialise as strings, dates as ISO-8601
- **Given:** an authenticated user; free-session request
- **When:** `POST` with `{}`
- **Expect:** `data.status` is the string `"in_progress"`; `data.analysis_state` is `"pending"`; `data.started_at` and `data.created_at` match `/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/`

**TC-7:** `day` is not a uuid → `422` on that field, nothing written
- **Given:** an authenticated user; `$routine = activeRoutineWithCycle($user)`
- **When:** `POST` with `{ "day": "not-a-uuid" }`
- **Expect:** `422`; `assertJsonValidationErrors('day', 'data.errors')`; `assertDatabaseCount('training_sessions', 0)`

**TC-8:** `day` is a well-formed uuid absent from `cycle_days` → `422` on `day`
- **Given:** an authenticated user; `$routine = activeRoutineWithCycle($user)`
- **When:** `POST` with `{ "day": (string) Str::uuid() }`
- **Expect:** `422`; `assertJsonValidationErrors('day', 'data.errors')`; `assertDatabaseCount('training_sessions', 0)`

**TC-9:** `day` from another user's routine → `409` `CYCLE_DAY_NOT_IN_ACTIVE_CYCLE`
- **Given:** `$other = User::factory()->create()`; `$otherRoutine = activeRoutineWithCycle($other)`; `$foreignDay = $otherRoutine->cycle->cycleDays->first()`; `actingAs($this->user)` with `$routine = activeRoutineWithCycle($this->user)`
- **When:** `POST /api/v1/routines/{$routine->uuid}/sessions` with `{ "day": $foreignDay->uuid }`
- **Expect:** `409`; `assertJsonPath('data.code', 'CYCLE_DAY_NOT_IN_ACTIVE_CYCLE')`; `assertJsonMissingPath('data.errors')`; `assertDatabaseCount('training_sessions', 0)`

**TC-10:** `day` from a non-active (older) cycle of the same routine → `409` `CYCLE_DAY_NOT_IN_ACTIVE_CYCLE`
- **Given:** an authenticated user; a routine with `Cycle::factory()->completed()->create(['sequence_number' => 1])` and `Cycle::factory()->active()->create(['sequence_number' => 2])` (each with days); `$oldDay` from the `completed` cycle
- **When:** `POST` with `{ "day": $oldDay->uuid }`
- **Expect:** `409`; `assertJsonPath('data.code', 'CYCLE_DAY_NOT_IN_ACTIVE_CYCLE')`; nothing written

**TC-11:** User already has an open session under the same routine → `409` `SESSION_IN_PROGRESS`
- **Given:** an authenticated user; `$routine = activeRoutineWithCycle($user)`; `TrainingSession::factory()->for($user)->for($routine)->create()` (default `in_progress`)
- **When:** `POST` with `{}`
- **Expect:** `409`; `assertJsonPath('data.code', 'SESSION_IN_PROGRESS')`; `assertDatabaseCount('training_sessions', 1)`

**TC-12:** Open-session guard is per-user, not per-routine → `409` even for a different routine
- **Given:** an authenticated user with two active routines is impossible (single-active invariant); instead: `$archived = Routine::factory()->for($user)->archived()->create()` with a cycle, and an `in_progress` `TrainingSession` under `$archived`; `$active = activeRoutineWithCycle($user)`
- **When:** `POST /api/v1/routines/{$active->uuid}/sessions` with `{}`
- **Expect:** `409`; `assertJsonPath('data.code', 'SESSION_IN_PROGRESS')`

**TC-13:** Only `completed` sessions exist → `201` (a closed session does not block)
- **Given:** an authenticated user; `$routine = activeRoutineWithCycle($user)`; `TrainingSession::factory()->for($user)->for($routine)->completed()->create()`
- **When:** `POST` with `{}`
- **Expect:** `201`; `assertDatabaseCount('training_sessions', 2)`

**TC-14:** Re-training an already-trained day is allowed (`cycle_day_id` not unique)
- **Given:** an authenticated user; `$routine = activeRoutineWithCycle($user)`; `$day` its first day; `TrainingSession::factory()->for($user)->for($routine)->completed()->create(['cycle_day_id' => $day->id])`
- **When:** `POST` with `{ "day": $day->uuid }`
- **Expect:** `201`; two `training_sessions` rows share `cycle_day_id = $day->id`

**TC-15:** `{routine}` is `archived` → `409` `ROUTINE_NOT_ACTIVE`, nothing written
- **Given:** an authenticated user; `$archived = Routine::factory()->for($user)->archived()->create()` with a cycle + days
- **When:** `POST /api/v1/routines/{$archived->uuid}/sessions` with `{}`, then with `{ "day": <a day of that cycle> }`
- **Expect:** both `409`; `assertJsonPath('data.code', 'ROUTINE_NOT_ACTIVE')`; `assertDatabaseCount('training_sessions', 0)`

**TC-16:** `{routine}` belongs to another user → `403`, nothing written
- **Given:** `$other = User::factory()->create()`; `$otherRoutine = activeRoutineWithCycle($other)`; `actingAs($this->user)`
- **When:** `POST /api/v1/routines/{$otherRoutine->uuid}/sessions` with `{}`
- **Expect:** `403`; `assertJsonPath('data.code', 'AUTHORIZATION_EXCEPTION')`; `assertDatabaseCount('training_sessions', 0)`

**TC-17:** Unknown `{routine}` uuid → `404`
- **Given:** an authenticated user
- **When:** `POST /api/v1/routines/{(string) Str::uuid()}/sessions` with `{}`
- **Expect:** `404`; `assertJsonPath('data.code', 'NOT_FOUND_EXCEPTION')`

**TC-18:** Non-uuid `{routine}` segment → `404` (route constraint)
- **Given:** an authenticated user
- **When:** `POST /api/v1/routines/42/sessions` with `{}`
- **Expect:** `404`

**TC-19:** Unauthenticated → `401`, nothing written
- **Given:** no `actingAs` (the `Origin` header is still set); `$routine = activeRoutineWithCycle(User::factory()->create())`
- **When:** `POST /api/v1/routines/{$routine->uuid}/sessions` with `{}`
- **Expect:** `401`; `assertJsonPath('data.code', 'AUTHENTICATION_EXCEPTION')`; `assertDatabaseCount('training_sessions', 0)`

**TC-20:** Cross-user isolation — a create never touches another user's data
- **Given:** `$other = User::factory()->create()`; `$otherRoutine = activeRoutineWithCycle($other)` with an `in_progress` `TrainingSession`; `$routine = activeRoutineWithCycle($this->user)`
- **When:** `actingAs($this->user)` → `POST /api/v1/routines/{$routine->uuid}/sessions` with `{}`
- **Expect:** `201`; `$other`'s session is untouched; `$other` still has exactly one session; the new row's `user_id` is `$this->user->id`

**TC-21:** Strict-mode render guard — the Resource never triggers a lazy load
- **Given:** an authenticated user; planned-session request
- **When:** `POST` with `{ "day": $day->uuid }`
- **Expect:** `201` with no `500`; `data` has no `user` key and no `routine` key; `data.cycle_day.exercises` is present (eager-loaded, not lazy)

### Action — `tests/Feature/Session/TrainingSessionCreateActionTest.php`

**TC-22:** `handle()` opens the session and returns it with the cycle day loaded
- **Given:** a `User`; `$routine = activeRoutineWithCycle($user)`; `$day` its first day; `$data = CreateTrainingSessionData::from(['day' => $day->uuid])`
- **When:** `app(TrainingSessionCreateAction::class)->handle($user, $routine, $data)`
- **Expect:** the returned `TrainingSession` has `status === SessionStatus::InProgress`, `analysis_state === AnalysisState::Pending`, `started_at` not null, `completed_at` null, `cycle_day_id === $day->id`, `wasRecentlyCreated === true`, and `relationLoaded('cycleDay')` is `true` with `cycleDay->relationLoaded('dayExercises')` `true`; `assertDatabaseCount('training_sessions', 1)`

**TC-23:** `handle()` with `day = null` opens a free session
- **Given:** a `User`; `$routine = activeRoutineWithCycle($user)`; `$data = CreateTrainingSessionData::from([])`
- **When:** `handle($user, $routine, $data)`
- **Expect:** the returned session has `cycle_day_id === null`; `relationLoaded('cycleDay')` is `true` and `cycleDay` is `null`

**TC-24:** `handle()` throws when the user already has an open session, writes nothing
- **Given:** a `User` with an `in_progress` `TrainingSession`; `$routine = activeRoutineWithCycle($user)`; `$data = CreateTrainingSessionData::from([])`
- **When:** `handle($user, $routine, $data)`
- **Expect:** throws `App\Exceptions\Session\SessionInProgressException`; `$e->errorCode() === 'SESSION_IN_PROGRESS'`; `$e->statusCode() === 409`; `assertDatabaseCount('training_sessions', 1)`

**TC-25:** `handle()` throws when the routine is archived
- **Given:** a `User`; `$archived = Routine::factory()->for($user)->archived()->create()` with a cycle; `$data = CreateTrainingSessionData::from([])`
- **When:** `handle($user, $archived, $data)`
- **Expect:** throws `RoutineNotActiveException`; `errorCode() === 'ROUTINE_NOT_ACTIVE'`; nothing written

**TC-26:** `handle()` throws when the day is not in the routine's active cycle
- **Given:** a `User`; `$routine = activeRoutineWithCycle($user)`; `$foreignDay` from another user's cycle; `$data = CreateTrainingSessionData::from(['day' => $foreignDay->uuid])`
- **When:** `handle($user, $routine, $data)`
- **Expect:** throws `CycleDayNotInActiveCycleException`; `errorCode() === 'CYCLE_DAY_NOT_IN_ACTIVE_CYCLE'`; nothing written

### Policy — `tests/Unit/Session/TrainingSessionPolicyTest.php`

**TC-27:** `TrainingSessionPolicy::create` allows the owner, denies others
- **Given:** `$owner` and `$stranger` as `User` instances with distinct ids; `$routine` with `user_id = $owner->id`
- **When:** `(new TrainingSessionPolicy)->create($owner, $routine)` and `->create($stranger, $routine)`
- **Expect:** `true` for the owner, `false` for the stranger

### Cycle born `active` — updated existing tests

**TC-28:** `CycleDraftService::persist()` writes an `active` cycle (edit `tests/Feature/Cycle/CycleDraftServiceTest.php`)
- **Given:** a routine and a validated `CyclePlanData` (as today)
- **When:** `app(CycleDraftService::class)->persist($routine, $plan)`
- **Expect:** `$cycle->status === CycleStatus::Active`; `$cycle->activated_at` is not null; the days / prescriptions assertions are unchanged

**TC-29:** `POST /api/v1/routines` returns an `active` first cycle (edit `tests/Feature/Routine/StoreRoutineTest.php`)
- **Given:** an onboarded user (as today)
- **When:** `POST /api/v1/routines` with the base payload
- **Expect:** `data.cycle.status === "active"`; `assertDatabaseHas('cycles', ['sequence_number' => 1, 'status' => 'active'])`; the "every valid goal" dataset test asserts `data.cycle.status === "active"`

**TC-30:** `RoutineCreateAction` persists the first cycle `active` (edit `tests/Feature/Routine/RoutineCreateActionTest.php`)
- **Given:** an onboarded user (as today)
- **When:** `app(RoutineCreateAction::class)->handle($user, $data)`
- **Expect:** `assertDatabaseHas('cycles', ['routine_id' => $routine->id, 'sequence_number' => 1, 'status' => 'active'])`; `$routine->cycle->status === CycleStatus::Active`

**TC-31:** `CycleStatus::Incomplete` exists
- **Given:** the enum
- **When:** `CycleStatus::from('incomplete')`
- **Expect:** returns `CycleStatus::Incomplete`; `CycleStatus::tryFrom('incomplete')` is non-null

### Architecture — `tests/Feature/ArchTest.php` (added rule)

**TC-32:** Session controllers are invokable
- **Given:** the project code
- **When:** the Pest architecture assertions run
- **Expect:** `App\Http\Controllers\Session` is invokable (new `arch(...)` line); the existing rules (`App\Actions\*` final + `handle()`, `App\Services\*` final, `App\Http\Requests\*` extends `FormRequest`, no debug helpers) still pass — covering `TrainingSessionCreateAction`, `TrainingSessionOpeningService` and `StoreTrainingSessionRequest`

### OpenAPI — `tests/Feature/Auth/DocsSecurityTest.php` (extends the existing test)

**TC-33:** The generated OpenAPI spec marks the new route secured
- **Given:** the app with `security_strategy = MiddlewareAuthSecurityStrategy` (already on `main`)
- **When:** the spec is generated in-process
- **Expect:** `$spec['paths']['/api/v1/routines/{routine}/sessions']['post']` has **no** per-operation `security` key (it inherits the global one), asserted alongside the existing profile / routine paths

---

## 9. Technical Decisions

| Decision area | What was decided | Why |
|---|---|---|
| Endpoint scope | **Open a session only.** No set logging, no completion, no analysis job, no `set_logs` / `exercise_recommendations` tables. | The backlog splits these into Orders 110 / 120 / 130, each `Ready`. Keeps this PR reviewable. Confirmed with the user. |
| URL shape | **Nested:** `POST /api/v1/routines/{routine}/sessions`, `day` in the body. Not a top-level `POST /api/v1/sessions`. | A session always belongs to a routine; the URL should say so. `routine_id` comes from the route, not the body — one less thing to validate for coherence. Confirmed with the user. |
| HTTP verb & status | `POST` to the nested collection, `201` via `->response()->setStatusCode(Response::HTTP_CREATED)`. | A user has many sessions per routine; each open creates a resource. Matches `StoreRoutineController`. |
| Model & table name | Model `App\Models\TrainingSession`, table `training_sessions`. Pipeline classes are `TrainingSession*` (`StoreTrainingSessionController`, `TrainingSessionCreateAction`, `TrainingSessionOpeningService`, `TrainingSessionResource`, `TrainingSessionPolicy`, `CreateTrainingSessionData`, `StoreTrainingSessionRequest`); folders and the route path stay `Session` / `sessions`. | Laravel already ships a `sessions` table (session store, in `0001_01_01_000000_create_users_table.php`) and the `Session` facade. `TrainingSession` avoids a `$table` override and a name clash, and reads unambiguously. The domain is still "Session" so folders/routes keep that name. Confirmed with the user; `data-model.md` updated. |
| `day` — presence & type | Optional. `day` = the `CycleDay` **public `uuid`**. Absent / `null` / blank → free session. `StoreTrainingSessionRequest` validates `['nullable', 'uuid', 'exists:cycle_days,uuid']` and collapses blank → absent in `prepareForValidation()`. | The acceptance criteria require both planned and free sessions. `exists` is shape only (the row is real); "the row belongs to *this* routine's active cycle" is a cross-entity rule (below). Blank-normalisation mirrors `routines.hint`. |
| Free session support | In scope. `cycle_day_id` nullable; `routine_id` always set from the route. | AC lists it explicitly ("o como sesión libre (fuera de plan)"). `data-model.md` §`sessions`: free session has `cycle_day_id = null` but always `routine_id`. Confirmed with the user. |
| Routine must be `active` | Business rule in `TrainingSessionOpeningService` → `RoutineNotActiveException` (`409` `ROUTINE_NOT_ACTIVE`). Applies to planned **and** free sessions. Not a Policy concern. | `docs/plans/product-context.md` §6 — logging is "contra el ciclo `active`". An archived routine is read-only. The request is well-formed and the routine is owned, so it is a state conflict (`409`), not `403` / `422`. |
| Day-in-active-cycle rule | Business rule in `TrainingSessionOpeningService`: the `CycleDay` must belong to `Routine::cycle()` (highest `sequence_number`) **and** that cycle's `status` must be `CycleStatus::Active` → else `CycleDayNotInActiveCycleException` (`409` `CYCLE_DAY_NOT_IN_ACTIVE_CYCLE`). | One check, one code, covers "day from another routine", "day from an older cycle", and the defensive "cycle not active". `409` for the same reason as above — the uuid is valid, the state forbids it. In v1 a routine has exactly one cycle, so "current cycle" and "active cycle" coincide; checking `status` keeps it correct if that changes. |
| One open session per user | `TrainingSessionOpeningService` rejects if the user has **any** `in_progress` session (any routine) → `SessionInProgressException` (`409` `SESSION_IN_PROGRESS`). Backstop: partial unique index `(user_id) WHERE status = 'in_progress'`. | "Un día de entrenamiento a la vez" — the user finishes (Order 120) or, later, abandons one session before opening the next. Per-user, not per-routine, because a user has one active routine and trains one day at a time. Confirmed with the user. Race past the guard → `QueryException` → `500`, the same tradeoff `create-routine-spec.md` accepts for its partial index. |
| No abandon path yet | Known limitation: with no "complete session" endpoint (Order 120) a stuck `in_progress` session blocks new sessions — including after the user creates a **new routine** (which archives the old one but does **not** close its open session). | Accepted — Order 120 is the next story and adds the `in_progress → completed` transition. Not worth a throwaway "abandon" endpoint here. |
| Guard placement | A dedicated **Service** — `App\Services\Session\TrainingSessionOpeningService` — holds the three invariants; `TrainingSessionCreateAction` resolves the day, calls the guard, then opens the session in a transaction. | Unlike `create-routine-spec.md` (one `throw_if`, kept inline), this is three cross-entity business rules with three distinct client-facing `code`s — real business knowledge to isolate (`CLAUDE.md` "Service" section, guard-clause rule). It keeps the Action a readable top-to-bottom story and gives the rules a unit-testable home. |
| Domain exceptions | Three `final` classes under `App\Exceptions\Session\`, each `extends App\Exceptions\DomainException`, `protected string $errorCode` (SCREAMING_SNAKE), default `409`, fixed default message. No handler wiring. | Matches `ProfileIncompleteException` / `CycleGenerationException`. The error envelope requires a distinct `code` per rule the client branches on; `ApiExceptionRenderer`'s `DomainException` arm renders them at `409` automatically. |
| Server-set session fields | `status = in_progress`, `started_at = now()`, `completed_at = null`. `analysis_state` is **not** set — it takes the DB `default 'pending'`. `conversation_id = null`. | AC "nace sin completar". The user never supplies these. `analysis_state` / `conversation_id` belong to the analysis story (Order 130); the columns ship now to keep `training_sessions` a faithful realisation of `data-model.md` in one migration, and are inert here. |
| `analysis_state` / `conversation_id` columns now vs later | Ship both now. | `data-model.md` §`sessions` is the schema contract; both are nullable/defaulted and inert; Order 120 (which sets `analysis_state`) is the immediate next story, so a second migration for them is churn. |
| `201` body | `TrainingSessionResource`: `id` (uuid), `status`, `analysis_state`, `started_at`, `completed_at`, `created_at`, `updated_at`, and `cycle_day` = `CycleDayResource::make($this->whenLoaded('cycleDay'))` (→ `DayExerciseResource`), which serialises to `null` for a free session. **No `routine` block.** | `CLAUDE.md` rule 3 (always a real `JsonResource`). The SPA logging screen (Order 230) needs the day's prescription in the open call — reuse the existing Cycle resources unchanged. The routine id is already in the URL the client called, so nesting it is noise. |
| Eager loading | `TrainingSessionCreateAction` returns `$session->load('cycleDay.dayExercises.exercise')` (a free session loads `cycleDay` as `null`). | `Model::shouldBeStrict(!isProduction())` makes a lazy load in the Resource throw. Same pattern as `RoutineCreateAction`'s `->load('cycle.cycleDays.dayExercises.exercise')`. |
| Request DTO | `App\Data\Session\CreateTrainingSessionData` (`spatie/laravel-data`), one `readonly ?string $day = null`. Built with `::from($request->validated())`. | `CLAUDE.md` convention (writes take a `Data` object). One field, single word — no `#[MapInputName]`. |
| Authorization | `TrainingSessionPolicy::create(User $user, Routine $routine): bool => $routine->user_id === $user->id`; auto-discovered; wired via `StoreTrainingSessionRequest::authorize()` → `can('create', [TrainingSession::class, $this->route('routine')])`. Route adds `->whereUuid('routine')`. | First write route with a `{parent}` id → first place a Policy `403` is reachable on a write. Ownership is the only rule; `active` / `archived` and the day check are business rules, not authorization (`409`). Unknown routine uuid → `404` before the Policy. |
| First cycle born `active` (in scope) | Add `CycleStatus::Incomplete`; `CycleDraftService::persist()` writes `CycleStatus::Active` + `activated_at = now()`; `CycleFactory` default becomes `Active` (keep `active()`, add `draft()` and `incomplete()` states); update the three tests asserting `'draft'`. | The MVP has no "activate a cycle" step (`docs/plans/product-context.md` §2 / §4 step 4). "The day of the **active** cycle" in this ticket's AC only makes sense if the cycle is `active`. `Incomplete` is added now per the Order 60 backlog note (used by the rollover story, Order 150). Confirmed with the user. |
| `Routine::cycle()` reused as "active cycle" | No new relation. The guard reads the current cycle with an explicit `$routine->cycle()->first()` query (not the `$routine->cycle` property — `Model::shouldBeStrict` forbids the lazy load) and asserts its `status === Active`. | In v1 a routine has exactly one cycle, created synchronously and now `active`. A `status`-scoped relation would be indirection with no second caller (`CLAUDE.md` rules 5–6); the explicit `status` assertion in the guard is clearer and survives multi-cycle routines. |
| Enum storage & location | `App\Enums\Session\SessionStatus` (`in_progress`, `completed`) and `App\Enums\Session\AnalysisState` (`pending`, `processing`, `done`, `failed`) — string-backed, `TitleCase` cases; DB columns plain `string`, no `CHECK`. | `data-model.md` §Enums. Portable Postgres / SQLite. Only `in_progress` / `pending` are written by this endpoint; the rest exist for Orders 120 / 130. |
| FK delete behaviour | `user_id` / `routine_id` `cascadeOnDelete`; `cycle_day_id` `nullOnDelete`. | A session is meaningless without its user or routine. It **keeps** meaning without its planned day (becomes a historical free session) — nulling is correct, cascading would lose history. Matches the `data-model.md` FK convention. |
| Partial unique index | Raw `DB::statement` `CREATE UNIQUE INDEX ... (user_id) WHERE status = 'in_progress'`. | Laravel's schema builder has no portable partial-index API; the raw `WHERE` form parses on Postgres 17 and SQLite `:memory:`. Same technique as `routines_user_id_active_unique`. |
| Rate limiting | None. | Authenticated, low-abuse; matches the routine and profile routes. |
| Scramble `security_strategy` | No `config/scramble.php` change — `MiddlewareAuthSecurityStrategy` on `main` already covers any `auth:sanctum` route. One `DocsSecurityTest` assertion added. | The new route matches that middleware, so it is documented as secured automatically. |
| Tests: DB & no AI | SQLite `:memory:` + `RefreshDatabase` (already wired). Cycles are built with `CycleFactory`/`CycleDayFactory`/`DayExerciseFactory`, never the planner — the endpoint has no AI path. | Faster, deterministic; the planner is already covered by the cycle specs. |
| Git artifacts | English only; **no** `Co-Authored-By: Claude` / `Claude-Session:` commit trailers; PR description carries the `🤖 Generated with Claude Code` footer only. | Repo `CLAUDE.md` / `AGENTS.md` rule; it takes precedence over the session's attribution instruction, as every prior spec notes. |

---

## 10. Work Plan

Pipeline classes are created before wiring `routes/api.php`. Each task's DoD is
the artifact existing, passing Pint + PHPStan level 6, and — where the class
carries logic — its focused test authored in the same task. Tasks 18–19 are the
functional gate.

| # | Task | Definition of Done |
|---|---|---|
| 1 | Clone the runtime DB: `docker compose exec pgsql createdb -U gym -T gym_trainer gym_trainer_create_trainning_sessions`; set `DB_DATABASE=gym_trainer_create_trainning_sessions` in this worktree's `.env` | The worktree toolchain targets the clone; `gym_trainer` untouched; the Pest suite still uses SQLite. |
| 2 | Add `case Incomplete = 'incomplete';` to `app/Enums/Cycle/CycleStatus.php`; refresh its docblock to say the first cycle is born `active` and `incomplete` is set by the cycle N+1 rollover | `CycleStatus::from('incomplete') === CycleStatus::Incomplete`; Pint + PHPStan clean; TC-31. |
| 3 | Create `app/Enums/Session/SessionStatus.php` (`InProgress = 'in_progress'`, `Completed = 'completed'`) and `app/Enums/Session/AnalysisState.php` (`Pending`, `Processing`, `Done`, `Failed`) — string-backed | `SessionStatus::from('in_progress')` / `AnalysisState::from('pending')` resolve; Pint + PHPStan clean. |
| 4 | Create the migration `<ts>_create_training_sessions_table.php` per §4.1 (anonymous class; `uuid` unique; `user_id` / `routine_id` `constrained()->cascadeOnDelete()`; `cycle_day_id` `nullable()->constrained()->nullOnDelete()`; `status` / `analysis_state` `string`, `analysis_state` default `'pending'`; `started_at` timestamp; `completed_at` nullable; `conversation_id` `string(36)` nullable; raw partial unique index) | `php artisan migrate` runs on the clone and a fresh SQLite; `php artisan db:table training_sessions` shows the FKs and `training_sessions_user_in_progress_unique`. |
| 5 | Rename `sessions` → `training_sessions` throughout `docs/plans/data-model.md` (heading, ER diagram, `set_logs` / `exercise_recommendations` FK text, "Llevan `uuid`" list, decision #6) and add the session-store clash note under the heading | `grep -n '`sessions`' docs/plans/data-model.md` returns only the Laravel session-store mention; the doc reads coherently. |
| 6 | Create `app/Models/TrainingSession.php`: `use HasFactory, HasPublicUuid;` · `#[Fillable(['status', 'analysis_state', 'started_at', 'completed_at'])]` · `casts()` (`status` → `SessionStatus::class`, `analysis_state` → `AnalysisState::class`, `started_at` / `completed_at` → `immutable_datetime`) · `user(): BelongsTo`, `routine(): BelongsTo`, `cycleDay(): BelongsTo` (nullable). Add `trainingSessions(): HasMany` + `@property-read` PHPDoc to **both** `app/Models/User.php` and `app/Models/Routine.php` | Pint + PHPStan clean; `(new TrainingSession)->getCasts()` has the enum + datetime casts; `(new User)->trainingSessions()` and `(new Routine)->trainingSessions()` are each a `HasMany`. |
| 7 | Run `php artisan ide-helper:models --write` for `TrainingSession` + `User` + `Routine`; `vendor/bin/pint app/Models`; hand-check the enum-cast `@property` lines and the `HasPublicUuid` `@method` | PHPDoc blocks list every column / relation; diff limited to the three models. |
| 8 | Create `database/factories/TrainingSessionFactory.php`: `user_id => User::factory()`, `routine_id => Routine::factory()`, `cycle_day_id => CycleDay::factory()`, `status => SessionStatus::InProgress`, `analysis_state => AnalysisState::Pending`, `started_at => now()`, `completed_at => null`; states `completed()` (`status => Completed`, `completed_at => now()`) and `free()` (`cycle_day_id => null`) | `TrainingSession::factory()->create()`, `->completed()->create()`, `->free()->create()` each persist one row with a `uuid`; Pint + PHPStan clean. |
| 9 | Update `database/factories/CycleFactory.php`: default `status => CycleStatus::Active` + `activated_at => now()`; keep `active()`; add `draft()` (`status => Draft`, `activated_at => null`) and `incomplete()` (`status => Incomplete`, `activated_at => now()->subWeek()`, `completed_at => now()`) | `Cycle::factory()->create()->status === CycleStatus::Active`; `->draft()` / `->incomplete()` work; Pint + PHPStan clean. |
| 10 | Change `app/Services/Cycle/CycleDraftService.php::persist()` to write `'status' => CycleStatus::Active` and `'activated_at' => now()`; update the `Cycle` model docblock line ("born `active`"). Update `tests/Feature/Cycle/CycleDraftServiceTest.php` (TC-28), `tests/Feature/Routine/StoreRoutineTest.php` (TC-29), `tests/Feature/Routine/RoutineCreateActionTest.php` (TC-30) | `vendor/bin/pest tests/Feature/Cycle tests/Feature/Routine` green with the `active` assertions. |
| 11 | Create `app/Data/Session/CreateTrainingSessionData.php` (`make:data`, move to `app/Data/Session/`, fix namespace): `readonly ?string $day = null` | `CreateTrainingSessionData::from(['day' => $uuid])->day === $uuid`; `::from([])->day === null`; Pint + PHPStan clean. |
| 12 | Create `app/Exceptions/Session/SessionInProgressException.php`, `RoutineNotActiveException.php`, `CycleDayNotInActiveCycleException.php` — `final extends DomainException`; `$errorCode` = `SESSION_IN_PROGRESS` / `ROUTINE_NOT_ACTIVE` / `CYCLE_DAY_NOT_IN_ACTIVE_CYCLE`; each `__construct()` sets a fixed message | `(new SessionInProgressException)->errorCode()` / `->statusCode() === 409` for each; Pint + PHPStan clean. |
| 13 | Create `app/Policies/TrainingSessionPolicy.php`: `create(User $user, Routine $routine): bool => $routine->user_id === $user->id`. Write `tests/Unit/Session/TrainingSessionPolicyTest.php` (TC-27) | `vendor/bin/pest tests/Unit/Session/TrainingSessionPolicyTest.php` green; Pint + PHPStan clean. |
| 14 | Create `app/Services/Session/TrainingSessionOpeningService.php` (`final`): one method `guard(User $user, Routine $routine, ?CycleDay $day): void` — `throw_unless($routine->status === RoutineStatus::Active, new RoutineNotActiveException)`; if `$day !== null`, load `$cycle = $routine->cycle()->first()` and `throw_unless($cycle?->status === CycleStatus::Active && $day->cycle_id === $cycle->id, new CycleDayNotInActiveCycleException)`; `throw_if($user->trainingSessions()->where('status', SessionStatus::InProgress)->exists(), new SessionInProgressException)`. Order the checks routine → day → open-session. | `final`; Pint + PHPStan clean; no strict-mode lazy-load (uses `->cycle()->first()`); guard paths covered by the Action test (TC-24…TC-26). |
| 15 | Create `app/Http/Requests/Session/StoreTrainingSessionRequest.php`: `authorize()` → `$this->user()?->can('create', [TrainingSession::class, $this->route('routine')]) ?? false`; `rules()` → `['day' => ['nullable', 'uuid', 'exists:cycle_days,uuid']]`; `prepareForValidation()` collapses blank/whitespace `day` → unset | extends `FormRequest`; Pint + PHPStan clean; a whitespace `day` is dropped, `goal`-style bad value fails. |
| 16 | Create `app/Http/Resources/Session/TrainingSessionResource.php` (`@mixin TrainingSession`): `id => $this->uuid`, `status` / `analysis_state` via `->value`, `started_at` / `completed_at` / `created_at` / `updated_at` via `?->toIso8601String()`, `cycle_day => CycleDayResource::make($this->whenLoaded('cycleDay'))`; no internal ids, no `routine` | `toArray()` has no `user`/`routine`/`*_id` key; `id` is the uuid; Pint + PHPStan clean. |
| 17 | Create `app/Actions/Session/TrainingSessionCreateAction.php` (`final`, ctor injects `TrainingSessionOpeningService`): `handle(User $user, Routine $routine, CreateTrainingSessionData $data): TrainingSession` — resolve `$day = $data->day ? CycleDay::query()->where('uuid', $data->day)->first() : null`; `$this->guard->guard($user, $routine, $day)`; `DB::transaction(fn () => $routine->trainingSessions()->create([... 'status' => SessionStatus::InProgress, 'started_at' => now(), 'user_id' => $user->id, 'cycle_day_id' => $day?->id]))`; return `$session->load('cycleDay.dayExercises.exercise')` (a free session's `cycleDay` loads as `null`). Write `tests/Feature/Session/TrainingSessionCreateActionTest.php` (TC-22…TC-26) | `final` + `handle()`; `vendor/bin/pest tests/Feature/Session/TrainingSessionCreateActionTest.php` green; Pint + PHPStan clean. |
| 18 | Create `app/Http/Controllers/Session/StoreTrainingSessionController.php` (`make:controller --invokable`, move + fix namespace): `__invoke(StoreTrainingSessionRequest $request, Routine $routine, TrainingSessionCreateAction $action)` → build `CreateTrainingSessionData::from($request->validated())`, call the Action with `$request->user()`, `$routine`, the DTO, return `TrainingSessionResource::make($session)->response()->setStatusCode(Response::HTTP_CREATED)` | `final`, `__invoke` only; Pint + PHPStan clean. |
| 19 | Edit `routes/api.php`: add the `use` import and, in the `auth:sanctum` group, `Route::post('routines/{routine}/sessions', StoreTrainingSessionController::class)->whereUuid('routine')->name('routines.sessions.store')` | `php artisan route:list` shows `POST api/v1/routines/{routine}/sessions` with `auth:sanctum`; PHPStan clean in `routes/`. |
| 20 | Write `tests/Feature/Session/StoreTrainingSessionTest.php` covering TC-1 … TC-21 (`beforeEach` sets the `Origin` header + user + profile; the `activeRoutineWithCycle()` helper) | `vendor/bin/pest tests/Feature/Session/StoreTrainingSessionTest.php` all green; every TC-1 … TC-21 has a test. |
| 21 | Add `arch('session controllers are invokable')->expect('App\Http\Controllers\Session')->toBeInvokable()` to `tests/Feature/ArchTest.php` (TC-32); add the `/api/v1/routines/{routine}/sessions` `post` assertion to `tests/Feature/Auth/DocsSecurityTest.php` (TC-33) | `vendor/bin/pest tests/Feature/ArchTest.php tests/Feature/Auth/DocsSecurityTest.php` green. |
| 22 | `vendor/bin/pint --dirty`, then `vendor/bin/phpstan analyse`, then a final `php artisan ide-helper:models --write` + `vendor/bin/pint app/Models` | Pint reports no diffs; PHPStan level 6 clean; model PHPDoc in sync with the migration. |
| 23 | `composer check` (Pint `--test` + PHPStan level 6 + full Pest — new Session tests, the Policy test, the arch rule, the `DocsSecurityTest` addition, and the updated Cycle / Routine suites) | All three steps green; no regression in Auth / Profile / Routine / Cycle suites. |
| 24 | Manual check with `curl` against `http://localhost:8000` (worktree app pointed at the clone): register + login → `PUT /api/v1/profile` → `POST /api/v1/routines` (`201`, `cycle.status: active`) → `POST /api/v1/routines/{uuid}/sessions` with `{}` (`201`, free) → `POST` again (`409` `SESSION_IN_PROGRESS`) → with `{ "day": "<a day uuid>" }` after clearing the open row (`201`, embedded prescription) → foreign routine (`403`) → unknown routine uuid (`404`). Review `GET /docs/api` | The `curl` calls return the expected codes; the endpoint appears in Scramble with the request from `StoreTrainingSessionRequest` and the response from `TrainingSessionResource`, marked secured. |
| 25 | On merge / branch drop: `docker compose exec pgsql dropdb -U gym --if-exists gym_trainer_create_trainning_sessions`; revert `DB_DATABASE` in the worktree `.env` | The clone is gone; `.env` restored to `gym_trainer`. |

*Process note: branch name, commit messages and PR text follow `CLAUDE.md` /
`AGENTS.md` — English only, no `Co-Authored-By: Claude` / `Claude-Session:`
trailers, `🤖 Generated with Claude Code` footer in the PR description only.*
