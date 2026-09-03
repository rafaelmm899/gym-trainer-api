# Log & edit the sets of a training session — `POST` / `PUT` `/api/v1/sessions/{session}/sets` (+ `GET /api/v1/exercises`)

> Derived from the Notion ticket "Registrar, editar y borrar series de una
> sesión" (Feature: Registro de sesiones · MVP · Must · Repo: API · Order 110,
> `https://app.notion.com/p/3ce5cf08db2d8120aa0dfb5ec0c7c2ae`) and the planning
> conversation with the product owner (this session). Base contract:
> `docs/product-context.md` §2 (Terminología — *Registro de serie*,
> *Ejercicio del día*) / §4 (step 4) / §7, `docs/plans/data-model.md`
> §`set_logs` + §`exercises` + §`training_sessions` + §Identificadores + §Enums,
> `CLAUDE.md` "The pipeline" / "Layout" / "Conventions",
> `docs/plans/create-training-session-spec.md` (the shipped Session-domain
> reference this builds on — `TrainingSession` model, `SessionStatus` enum,
> `HasPublicUuid`, `TrainingSessionOpeningService`, the `App\Exceptions\Session\`
> `DomainException` subclasses),
> `docs/plans/list-routines-spec.md` (the trivial-read pipeline reference for
> `GET /api/v1/exercises` — invokable controller, no Form Request, no Action),
> `docs/plans/domain-exception-handling-spec.md` (the `DomainException` base and
> the `{ "data": { "code", "message" } }` error envelope).

## 1. Context

**Kind:** Brownfield Feature — the Session domain (table, model, enums, policy,
factory, opening pipeline) shipped in PR #18. This ticket adds the **set-logging
slice**: a new `set_logs` table + `App\Models\SetLog`, two write endpoints
nested under a session, one `HasMany` relation on `TrainingSession`, and three
`DomainException` subclasses. It also lands a small, deliberate scope addition
agreed with the product owner: a read-only `GET /api/v1/exercises` catalogue
endpoint (new `App\Http\Controllers\Exercise` folder + `ExerciseResource`), so a
free / off-plan session can obtain an `exercise_id` to log against.

**Stack:** PHP 8.5 · Laravel 13 · PostgreSQL 17 (runtime) / SQLite `:memory:`
(tests) · Pest 4 (`pest-plugin-laravel`, `RefreshDatabase` already wired for the
`Feature` suite) · `laravel/sanctum` 4 (SPA cookie mode) · `spatie/laravel-data`
4 · `dedoc/scramble` 0.13 · Pint · Larastan level 6. Everything runs in Docker.

**Problem statement:** A user can open a training session
(`POST /api/v1/routines/{routine}/sessions`, Order 100) but there is no way to
record what they actually did in it. The training-logging loop
(`docs/product-context.md` §4 step 4) is: open the session → **log each set
(weight, reps, RPE, note)** → close the day for AI analysis. This ticket adds the
two write endpoints for the middle step — create a set, and correct a set — plus
the catalogue read the free-session path needs.

**Ticket deviation — recorded up front (product-owner decision, this session):**
The story's acceptance criteria and title mention **deleting** a set. That is
**intentionally not implemented.** A set can be *logged* and *corrected*, never
*deleted*. Concretely:

- The story title "Registrar, editar y **borrar** series de una sesión" and the
  "Qué falta" note "spec POST/PUT/**DELETE** de series" — the `DELETE` half is
  dropped.
- **AC4** ("Se puede borrar una serie mientras la sesión no esté completed") is
  **removed**.
- **AC5** ("No se pueden editar ni borrar series de una sesión ya completed")
  becomes **"No se pueden editar series de una sesión ya completed."**

The product owner will update the Notion story. Rationale: keeping every logged
set preserves the progression history the AI analysis (Order 130) consumes; a
mistaken set is corrected in place, not erased.

**In scope:**

- **`GET /api/v1/exercises`** — read the global exercise catalogue.
  `?q=` (optional, case-insensitive substring match on `name`) and
  `?muscle_group=` (optional, `App\Enums\Shared\MuscleGroup` backed value).
  Ordered by `name` asc, capped at **50 rows**, **no pagination**. `200` with an
  `ExerciseResource` collection (`{ "data": [] }` when empty). No Policy — the
  catalogue belongs to no user. No Form Request, no Action (trivial read,
  mirrors `ListRoutinesController` / `ShowAthleteProfileController`).
  `App\Http\Controllers\Exercise\ListExercisesController` (invokable) +
  `App\Http\Resources\Exercise\ExerciseResource`.
- **`POST /api/v1/sessions/{session}/sets`** — log one set into a session the
  caller owns. `201` with a single `SetLogResource`. Body carries **exactly one**
  of `day_exercise_id` (the prescription's public `uuid` → backend resolves the
  catalogue `exercise_id`) or `exercise_id` (a catalogue exercise's public
  `uuid`, used directly — off-plan / free session), plus `set_number`,
  `weight_kg`, `reps`, and optional `rpe` / `note`.
- **`PUT /api/v1/sessions/{session}/sets/{set}`** — correct an existing set the
  caller owns, while its session is `in_progress`. `200` with the updated
  `SetLogResource`. Edits **only** `weight_kg`, `reps`, `rpe`, `note`. Never
  changes `set_number` or the exercise.
- The `set_logs` table + `App\Models\SetLog` + `TrainingSession::sets()`
  `HasMany` relation + `SetLogFactory`.
- `App\Data\Session\LogSetData` and `App\Data\Session\UpdateSetLogData` — the
  request DTOs.
- `App\Http\Requests\Session\{LogSetRequest, UpdateSetLogRequest}` — shape
  validation + authorization via `SetLogPolicy`.
- `App\Http\Controllers\Session\{LogSetController, UpdateSetLogController}` —
  invokable.
- `App\Actions\Session\{SetLogCreateAction, SetLogUpdateAction}` — each carries
  its own guard clauses inline (no service layer; see §9 "Guard placement").
- `App\Exceptions\Session\{SessionAlreadyCompletedException,
  DayExerciseNotInSessionException, NonContiguousSetNumberException}` — `final`,
  `DomainException` subclasses, `409`, distinct `code`s, rendered by the existing
  `ApiExceptionRenderer` with no wiring.
- `App\Http\Resources\Session\SetLogResource` — the `POST` `201` / `PUT` `200`
  body; embeds `ExerciseResource` for the set's exercise.
- `App\Policies\SetLogPolicy` — `create(User, TrainingSession)` and
  `update(User, TrainingSession)` — both gate on session ownership; auto-discovered; wired through the Form Requests.
- Three routes added to the `auth:sanctum` group in `routes/api.php`
  (`exercises.list`, `sessions.sets.store`, `sessions.sets.update`); the set
  routes constrained with `->whereUuid(...)` and the `PUT` route
  `->scopeBindings()` so `{set}` is scoped to `{session}`.
- `tests/Feature/ArchTest.php` — add an `App\Http\Controllers\Exercise`
  invokable rule; `tests/Feature/Auth/DocsSecurityTest.php` — assert the three
  new routes inherit the global `security`.
- Pest feature + focused unit coverage of every (revised) acceptance criterion.

**Out of scope:**

- **Deleting a set** — see the ticket deviation above. No `DELETE` route, no
  `SetLogPolicy::delete`.
- Completing a session (`POST /api/v1/sessions/{session}/complete`,
  `in_progress → completed`, dispatching the analysis job) — ticket "Completar
  una sesión" (Order 120). This ticket only *reads* `TrainingSession::status`;
  nothing here transitions it.
- The session-analysis job / agent, `exercise_recommendations`, the
  prescribed-vs-real progression summary — ticket "Recibir recomendaciones al
  cerrar el día" (Order 130). `set_logs` is the granular data those later
  stories read; this ticket only writes rows.
- Reading a session and its sets back (`GET /api/v1/sessions/{session}`, the
  session history) — "Listar el historial de sesiones" (Order 330, non-MVP).
  The `POST` / `PUT` responses return the single affected set; the SPA
  accumulates session state client-side.
- **Pagination / cursoring of `GET /api/v1/exercises`.** v1 returns the first 50
  matches, filtered by `?q=` / `?muscle_group=`, ordered by name. A later story
  adds paging if the catalogue outgrows a type-ahead list. Known simplification.
- A catalogue **write** endpoint (create / rename / merge an exercise). The
  catalogue is still populated only by `ExerciseCatalogService` during cycle
  generation. `created_by_ai` stays `true` for every row. The duplicate-merge
  admin panel is "Later" (`docs/product-context.md`).
- Accent-insensitive `?q=` search. The match is `LOWER(name) LIKE LOWER(%q%)` —
  case-insensitive, but "inclinacion" will not match "inclinación". Acceptable
  for a v1 type-ahead; noted.
- Any change to `TrainingSession` beyond adding the `sets()` relation and its
  PHPDoc. No change to `Routine`, `Cycle`, `CycleDay`, `DayExercise`, `Exercise`,
  their resources, or the cycle / session pipelines.
- The `gym-trainer-spa/` frontend (separate repository).

---

## 2. API Surface

### 2.1 REST

All three routes join the existing `Route::middleware('auth:sanctum')->group(...)`
in `routes/api.php`, under the global `apiPrefix: 'api/v1'`. They are stateful
(`$middleware->statefulApi()` in `bootstrap/app.php`) — subject to
`EnsureFrontendRequestsAreStateful` + CSRF on the non-GET verbs. CSRF is
auto-bypassed under `php artisan test` (`ValidateCsrfToken::runningUnitTests()`).

| Method | Path | Auth | Request | Response | Status codes |
|---|---|---|---|---|---|
| GET | `/api/v1/exercises` | `auth:sanctum` (session cookie, `web` guard). **No Policy.** | Query string: `q` (string, optional — case-insensitive substring of `name`); `muscle_group` (string, optional — one of the `MuscleGroup` backed values; any other value is **ignored**, not rejected). No body. | `{ "data": [ { "id": string uuid, "name": string, "slug": string, "primary_muscle_group": string\|null } ] }` — up to 50, ordered by `name` asc. `{ "data": [] }` when nothing matches. | `200` · `401` unauthenticated |
| POST | `/api/v1/sessions/{session}/sets` | `auth:sanctum` + `SetLogPolicy::create` (via the Form Request) | JSON. **Exactly one of:** `day_exercise_id` (string, v4 uuid, must exist in `day_exercises.uuid`) **or** `exercise_id` (string, v4 uuid, must exist in `exercises.uuid`). Plus: `set_number` (int ≥ 1, **required**), `weight_kg` (number ≥ 0, ≤ 1000, ≤ 2 decimals, **required**), `reps` (int 1–100, **required**), `rpe` (number 0–10 in 0.5 steps, nullable), `note` (string ≤ 1000, nullable). `""` / whitespace-only `day_exercise_id` / `exercise_id` / `note` collapse to `null`. Any other key is ignored. | `{ "data": { "id": string uuid, "exercise": { "id": string uuid, "name": string, "slug": string, "primary_muscle_group": string\|null }, "set_number": int, "weight_kg": number, "reps": int, "rpe": number\|null, "note": string\|null, "created_at": string ISO-8601, "updated_at": string ISO-8601 } }` | `201` created · `422` validation (missing/both exercise refs, unknown uuid, out-of-range numeric, bad `rpe` step, `note` too long) · `409` `SESSION_ALREADY_COMPLETED` · `409` `DAY_EXERCISE_NOT_IN_SESSION` · `409` `NON_CONTIGUOUS_SET_NUMBER` · `401` unauthenticated · `403` `AUTHORIZATION_EXCEPTION` (`{session}` owned by another user) · `404` `NOT_FOUND_EXCEPTION` (`{session}` uuid unknown or not a uuid) · `419` stateful request without a valid CSRF token |
| PUT | `/api/v1/sessions/{session}/sets/{set}` | `auth:sanctum` + `SetLogPolicy::update` (via the Form Request) | JSON: `weight_kg` (**required**, same rules as POST), `reps` (**required**, same), `rpe` (nullable, same), `note` (nullable, same). `set_number`, `day_exercise_id`, `exercise_id` and any other key are **ignored**. | Same shape as the `POST` `201` body, reflecting the updated values. | `200` OK · `422` validation · `409` `SESSION_ALREADY_COMPLETED` · `401` unauthenticated · `403` `AUTHORIZATION_EXCEPTION` (`{set}`'s session owned by another user) · `404` `NOT_FOUND_EXCEPTION` (`{session}` / `{set}` uuid unknown, not a uuid, or `{set}` not a set of `{session}`) · `419` stateful request without a valid CSRF token |

Notes:

- **`{session}` binding.** `->whereUuid('session')` — a non-uuid segment never
  matches → `404`. Implicit binding resolves `{session}` to `TrainingSession` by
  `uuid` (`HasPublicUuid::getRouteKeyName()`, matched to the `TrainingSession
  $session` parameter by name); an unknown uuid → `ModelNotFoundException` →
  `404` (`NOT_FOUND_EXCEPTION`). The Policy runs after binding.
- **`{set}` binding & scoping.** `->whereUuid('set')` + `->scopeBindings()` —
  Laravel resolves `{set}` through `$session->sets()`, so a `uuid` that is a
  real `SetLog` but belongs to a **different session** → `404`, not `403`. This
  is why the `sets()` relation is named for the route parameter (§9).
- **Exactly one exercise reference.** `day_exercise_id` and `exercise_id` are
  each `nullable|uuid|exists:…` **plus** `required_without` the other **plus**
  `prohibits` the other. Both present → `422` on both. Neither → `422` on both.
  `exists:*,uuid` only proves the row is real; whether that prescription belongs
  to *this* session is the Service's job (`409` `DAY_EXERCISE_NOT_IN_SESSION`).
- **`day_exercise_id` → planned/prescribed.** The backend loads the
  `DayExercise`, checks `day_exercise.cycle_day_id === session.cycle_day_id`
  (which also fails, correctly, when the session is free and has no
  `cycle_day_id`), and stores `set_logs.exercise_id = day_exercise.exercise_id`.
  `set_logs` never references `day_exercises` (`docs/plans/data-model.md`
  §`set_logs`: "Va directo … para que las sesiones libres … también registren").
- **`exercise_id` → direct.** Used as-is. Works for a free session and for an
  off-plan exercise added to a planned session. There is no requirement that the
  exercise be part of any prescription.
- **`set_number` is client-supplied and must be contiguous.** The client sends
  the 1-based index of the set within its exercise **in this session**. The
  Service requires it to equal `(count of existing set_logs for
  (session_id, exercise_id)) + 1` — you can only append the next set, per
  exercise. Not contiguous → `409` `NON_CONTIGUOUS_SET_NUMBER` (message names the
  expected number). Contiguity is **per exercise**: the first set of a second
  exercise in the same session is `set_number = 1`. A DB `unique(session_id,
  exercise_id, set_number)` index is the concurrency backstop; in the normal
  flow the guard rejects a duplicate with a clean `409` first.
- **Session must be `in_progress`.** For both `POST` and `PUT`, if the target
  session's `status` is `SessionStatus::Completed` → `409`
  `SESSION_ALREADY_COMPLETED`. (`SessionStatus` has only `in_progress` /
  `completed`, so "not completed" ≡ `in_progress`.) This is the revised AC5.
- **`PUT` is a full replace of the mutable fields.** `weight_kg` and `reps` are
  required; omitting `rpe` or `note` sets them to `null`. `set_number` and the
  exercise are immutable through this endpoint — a value sent for them is
  ignored (not in `rules()`, so not in `validated()`).
- **No routine re-check.** The set endpoints do **not** re-verify that the
  session's routine is still `active`. Once a session is open, its sets can be
  logged and corrected until it is completed, even if the routine was archived
  meanwhile (e.g. the user created a new routine). The `active`-routine gate
  lives at session *open* time (Order 100).
- **`GET /api/v1/exercises` has no Form Request.** `q` is read straight off the
  request and `trim`med; `muscle_group` is passed through
  `MuscleGroup::tryFrom()` — an unrecognised value yields `null` and the filter
  is simply not applied (no `422`). This matches the trivial-read precedent
  (`ListRoutinesController` takes no params, `ShowAthleteProfileController` no
  Form Request). A reviewer who prefers a `422` on a bad `muscle_group` can say
  so (§9).
- **Server-set `set_logs` fields.** `session_id` from the route (`$session->sets()
  ->create(...)`); `exercise_id` resolved by the Service; `uuid` by
  `HasPublicUuid` on `creating`; `created_at` / `updated_at` by Eloquent. The
  request body never sets an id.
- Errors are rendered as JSON by `App\Exceptions\ApiExceptionRenderer` (wired for
  `api/*` in `bootstrap/app.php`) as
  `{ "data": { "code": "...", "message": "..." } }`, with a `data.errors` map
  only for `VALIDATION_EXCEPTION`. No hand-built JSON.

### 2.2 CLI

Not applicable — no CLI commands.

### 2.3 Events

Not applicable — no domain events and no jobs. Logging a set inserts a row and
nothing else; the session-analysis job is dispatched when a session is
**completed** (Order 120), not when a set is written. The project registers no
model-event listeners for `SetLog` and none are added here.

---

## 3. UI

### 3.1 Pages

Not applicable — no pages affected. This is a JSON REST API; the "registrar
sesión" screen (Order 230) lives in `gym-trainer-spa/`.

### 3.2 Components

Not applicable — no components affected.

---

## 4. Database

### 4.1 Schema changes

| Table | Action | Details |
|---|---|---|
| `set_logs` | Create | `id` bigint PK · `uuid` uuid **`unique`** (public id / route key; filled by `HasPublicUuid` on `creating`) · `session_id` bigint FK → **`training_sessions.id`** `constrained('training_sessions')->cascadeOnDelete()` · `exercise_id` bigint FK → `exercises.id` `constrained()->restrictOnDelete()` · `set_number` `unsignedSmallInteger` · `weight_kg` `decimal(6,2)` · `reps` `unsignedSmallInteger` · `rpe` `decimal(3,1)` **nullable** · `note` `text` **nullable** · `created_at` / `updated_at` timestamps · **`unique(['session_id', 'exercise_id', 'set_number'])`** |

- Migration file:
  `database/migrations/2026_09_03_130000_create_set_logs_table.php`, anonymous
  class `return new class extends Migration`. The timestamp sorts **after**
  `2026_09_03_120000_create_training_sessions_table.php`.
- **`session_id` FK target is explicit.** `foreignId('session_id')->constrained()`
  would infer the table `sessions` (Laravel's session store). It must be
  `->constrained('training_sessions')` — the same `sessions` / `training_sessions`
  clash the parent spec documents.
- **`exercise_id` never cascades.** `restrictOnDelete()` — the catalogue is
  permanent (`docs/plans/data-model.md`: "`exercise_id` **nunca** cascadea: el
  catálogo es permanente"). In practice the catalogue is never deleted; the
  constraint states the invariant.
- **`session_id` cascades.** A set has no meaning without its session; if a
  session row is ever deleted its sets go with it. Matches the `data-model.md`
  FK convention (cascade only when the child is meaningless without the parent).
- **`unique(session_id, exercise_id, set_number)`** — a plain composite unique
  index (`$table->unique([...])`), **not** partial: `set_logs` has no status
  column, every row participates. It is the concurrency backstop for the
  contiguity guard; a race that beats the guard surfaces as a `QueryException` →
  `500`, the same tradeoff the routine / session specs accept for their unique
  indexes.
- Enum columns: **none.** `set_logs` stores no enum.
- No native Postgres types, no `CHECK` — plain `decimal` / `smallint` columns,
  portable across the Postgres runtime and the SQLite `:memory:` test DB.
  Numeric bounds (0–1000 kg, 1–100 reps, 0–10 RPE) are enforced by the Form
  Requests, not the schema.
- No soft deletes — and, per the ticket deviation, no delete path at all.
- **Doc update:** none required. `docs/plans/data-model.md` §`set_logs` already
  matches this schema; add a one-line note under that heading that `set_number`
  is client-supplied and validated contiguous per `(session, exercise)`, and
  that `(session_id, exercise_id, set_number)` is unique.
- **Database isolation (`CLAUDE.md` → "Workflows — database isolation"):** this
  branch adds a migration, so `gym_trainer` must not be migrated directly.
  Before `migrate` against Postgres:
  `docker compose exec pgsql createdb -U gym -T gym_trainer gym_trainer_store_set_logs`,
  then set `DB_DATABASE=gym_trainer_store_set_logs` in this worktree's `.env`.
  Drop the clone
  (`dropdb -U gym --if-exists gym_trainer_store_set_logs`) and revert `.env` on
  merge. The Pest suite is unaffected — SQLite `:memory:`.

### 4.2 Seeds

Not applicable — no seeds. `GET /api/v1/exercises` reads whatever
`ExerciseCatalogService` has inserted during cycle generation; tests build
catalogue rows with `Exercise::factory()`.

---

## 5. Auth & Authorization

### 5.1 Authentication

**Method:** Session (cookie) via **Laravel Sanctum in SPA / stateful mode** — the
same mechanism as every other endpoint. `auth:sanctum` on the route group
authenticates from the session cookie; unauthenticated → `AuthenticationException`
→ `401` JSON. `POST` / `PUT` are stateful non-GET requests → require a valid
`XSRF-TOKEN` (`419` otherwise), auto-bypassed under `php artisan test`.

### 5.2 Authorization

**`SetLogPolicy`**, auto-discovered by Laravel 13 for `App\Models\SetLog`
(`App\Policies\{Model}Policy` convention) — no `AuthServiceProvider` /
`Gate::policy()` wiring, matching `RoutinePolicy` / `TrainingSessionPolicy`.
Wired through the Form Requests. `GET /api/v1/exercises` has **no** Policy — the
catalogue is global and owned by no one (like `POST /api/v1/logout`,
`GET /api/v1/user`).

| Role | Permissions |
|---|---|
| Authenticated user | Read the global exercise catalogue (no ownership dimension). Log a set into a **training session they own** — `SetLogPolicy::create(User $user, TrainingSession $session): bool` returns `$session->user_id === $user->id`. Correct a set under a **session they own** — `SetLogPolicy::update(User $user, TrainingSession $session): bool` returns `$session->user_id === $user->id` (the `{set}` is scope-bound to `{session}`, so owning the session is owning its sets). No other permission, no other actor. **No delete permission** (ticket deviation). |

- `LogSetRequest::authorize()` →
  `$this->user()?->can('create', [SetLog::class, $this->route('session')]) ?? false`.
  Route-model binding runs before Form Request authorization, so
  `$this->route('session')` is the bound `TrainingSession` (or the request 404s
  first). Foreign session → `AuthorizationException` → `403`.
- `UpdateSetLogRequest::authorize()` →
  `$this->user()?->can('update', [SetLog::class, $this->route('session')]) ?? false`.
  `{set}` is scope-bound to `{session}`, so a `{set}` from another of the user's
  own sessions passed under the wrong `{session}` 404s before the Policy; a
  `{session}` that belongs to another user → `403`.
- `SetLogPolicy::update` gates on the bound `{session}` (not the `{set}`): the
  route scopes `{set}` to `{session}`, so session ownership is set ownership,
  and the Policy stays a pure `user_id` comparison with no relation access.
- The session's `completed` / `in_progress` state and the `day_exercise`
  ownership are **business rules** (Service guards → `409`), **not**
  authorization. An owned but completed session → `409`, never `403`.

---

## 6. Configuration

**Environment variables:**

| Variable | Value / Source | Purpose |
|---|---|---|
| `DB_DATABASE` | `gym_trainer_store_set_logs` (this worktree's `.env` only, during development) | Points the worktree at a clone of `gym_trainer` so the new migration never runs against the shared database. Reverted on merge. |

No new keys in `.env.example`. `phpunit.xml` already carries everything the tests
need (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `QUEUE_CONNECTION=sync`,
`SANCTUM_STATEFUL_DOMAINS=localhost`, `APP_URL=http://localhost`);
`RefreshDatabase` is already active for the `Feature` suite in `tests/Pest.php`.

**Config / non-source files modified:**

| File | Change |
|---|---|
| `routes/api.php` | Add `use` imports for `ListExercisesController`, `LogSetController`, `UpdateSetLogController`; inside the existing `auth:sanctum` group add `GET exercises` → `ListExercisesController` (`exercises.list`), `POST sessions/{session}/sets` → `LogSetController` (`sessions.sets.store`, `->whereUuid('session')`), `PUT sessions/{session}/sets/{set}` → `UpdateSetLogController` (`sessions.sets.update`, `->whereUuid('session')->whereUuid('set')->scopeBindings()`). |
| `docs/plans/data-model.md` | One-line note under §`set_logs`: `set_number` is client-supplied and validated contiguous per `(session, exercise)`; `(session_id, exercise_id, set_number)` is unique. |
| `tests/Feature/ArchTest.php` | Add `arch('exercise controllers are invokable')->expect('App\Http\Controllers\Exercise')->toBeInvokable();`. |
| `tests/Feature/Auth/DocsSecurityTest.php` | Add three `->and($spec['paths'][…])->not->toHaveKey('security')` assertions for `/api/v1/exercises` (get), `/api/v1/sessions/{session}/sets` (post), `/api/v1/sessions/{session}/sets/{set}` (put). |

No change to `bootstrap/app.php`, `config/*`, `bootstrap/providers.php`,
`phpunit.xml`, `composer.json`.

---

## 7. Current vs New Behavior

| Behavior | Current | New |
|---|---|---|
| Logging a set | Impossible. `set_logs` table, `SetLog` model, the set pipeline — none exist. | `POST /api/v1/sessions/{session}/sets` inserts one `set_logs` row against a resolved catalogue `exercise_id`, `201` with the created set. |
| Correcting a set | Impossible. | `PUT /api/v1/sessions/{session}/sets/{set}` replaces `weight_kg` / `reps` / `rpe` / `note` while the session is `in_progress`; `200` with the updated set. |
| Deleting a set | Impossible. | **Still impossible — intentionally.** No `DELETE` route (ticket deviation; AC4 removed, AC5 revised). |
| Exercise catalogue read | No endpoint. The catalogue is only written, by `ExerciseCatalogService` during cycle generation. | `GET /api/v1/exercises` — filter by `?q=` / `?muscle_group=`, ordered by name, first 50, no pagination. Lets a free session pick an `exercise_id`. |
| `set_logs` table | Does not exist. | Created: `session_id` (cascade) / `exercise_id` (restrict) FKs, `set_number`, `weight_kg`, `reps`, `rpe?`, `note?`, `unique(session_id, exercise_id, set_number)`. |
| `SetLog` model / factory / policy | None. | `App\Models\SetLog` (flat, `HasPublicUuid`), `SetLogFactory`, `App\Policies\SetLogPolicy` (`create`, `update`). |
| `TrainingSession` relations | `user()`, `routine()`, `cycleDay()`. | Adds `sets(): HasMany<SetLog>` (explicit FK `session_id`) + `@property-read` PHPDoc. Nothing else changes. |
| Domain exceptions | `App\Exceptions\Session\{SessionInProgressException, RoutineNotActiveException, CycleDayNotInActiveCycleException}` (all `409`). | Adds `SessionAlreadyCompletedException`, `DayExerciseNotInSessionException`, `NonContiguousSetNumberException` under `App\Exceptions\Session\` — all `409`, distinct `code`s. |
| Authenticated routes | `auth:sanctum` group holds auth, profile, routine and session-open routes. | Adds `GET exercises`, `POST sessions/{session}/sets`, `PUT sessions/{session}/sets/{set}`. First route with a **scoped** nested binding (`{set}` under `{session}`). |
| Controllers layout | `Auth`, `Profile`, `Routine`, `Session` folders. | Adds `App\Http\Controllers\Exercise\` (+ `App\Http\Resources\Exercise\`). |
| Set-touching tests | None. | `tests/Feature/Exercise/ListExercisesTest.php`, `tests/Feature/Session/{LogSetTest, UpdateSetLogTest, SetLogActionTest}.php`, `tests/Unit/Session/SetLogPolicyTest.php`, one `ArchTest` rule, three `DocsSecurityTest` assertions. |
| OpenAPI | Scramble documents every `auth:sanctum` route via the global scheme. | `GET /api/v1/exercises`, `POST` and `PUT` `/api/v1/sessions/{session}/sets` documented automatically (requests from the Form Requests, responses from `SetLogResource` / `ExerciseResource`); `DocsSecurityTest` asserts they inherit `security`. |

---

## 8. Test Cases

Executable with Pest 4 on SQLite `:memory:` (`RefreshDatabase`, already wired).
Feature tests' `beforeEach` sets `$this->withHeader('Origin', config('app.url'))`,
then `$this->user = User::factory()->create()` and
`AthleteProfile::factory()->for($this->user)->create()`. AI is never called —
tests build catalogue rows and cycle trees with factories.

Two shared helpers in `tests/Helpers.php` (alongside the existing
`trainingRoutineWithCycle`) build the fixtures — `openPlannedSession(User)` (an
open session against the first day of a real active cycle) and
`openFreeSession(User)` (an open session with no cycle day; reuses the user's
routine if they already have one):

```php
// tests/Helpers.php — reuses the existing trainingRoutineWithCycle() helper.
function openPlannedSession(User $user): TrainingSession
{
    $routine = trainingRoutineWithCycle($user);                    // active routine + 5-day active cycle

    return TrainingSession::factory()->for($user)->for($routine)
        ->planned($routine->cycle->cycleDays->first())->create()   // status: in_progress
        ->load('cycleDay.dayExercises.exercise');
}

function openFreeSession(User $user): TrainingSession
{
    // One active routine per user — reuse it if the user already has one.
    $routine = $user->routines()->first() ?? Routine::factory()->for($user)->create();

    return TrainingSession::factory()->for($user)->for($routine)->create(); // cycle_day_id null
}
```

One `in_progress` session per user is a DB invariant (`training_sessions_user_in_progress_unique`), so a test needing a *second* session for the same user makes it `->completed()`.

### GET `/api/v1/exercises` — `tests/Feature/Exercise/ListExercisesTest.php`

**TC-1:** Lists the catalogue ordered by name (AC: free session can find an exercise)
- **Given:** an authenticated user; `Exercise::factory()->create(['name' => 'Zercher Squat'])`, `['name' => 'Bench Press']`, `['name' => 'Deadlift']`
- **When:** `GET /api/v1/exercises`
- **Expect:** `200`; `assertJsonCount(3, 'data')`; `data.0.name === 'Bench Press'`, `data.2.name === 'Zercher Squat'`; `assertJsonStructure(['data' => [['id', 'name', 'slug', 'primary_muscle_group']]])`; `data.0.id` matches a v4 uuid regex

**TC-2:** `?q=` filters by case-insensitive substring of `name`
- **Given:** an authenticated user; exercises `'Barbell Bench Press'`, `'Incline Bench Press'`, `'Deadlift'`
- **When:** `GET /api/v1/exercises?q=bench`
- **Expect:** `200`; `assertJsonCount(2, 'data')`; every `data.*.name` contains "Bench"

**TC-3:** `?muscle_group=` filters; an unknown value is ignored, not rejected (dataset)
- **Given:** an authenticated user; one exercise with `primary_muscle_group = MuscleGroup::Chest`, one with `Back`, one `null`
- **When:** `GET /api/v1/exercises?muscle_group=chest`, then `?muscle_group=not-a-group`
- **Expect:** first → `200`, `assertJsonCount(1, 'data')`, `data.0.primary_muscle_group === 'chest'`; second → `200`, `assertJsonCount(3, 'data')` (filter not applied)

**TC-4:** Result set is capped at 50 rows
- **Given:** an authenticated user; `Exercise::factory()->count(60)->create()`
- **When:** `GET /api/v1/exercises`
- **Expect:** `200`; `assertJsonCount(50, 'data')`

**TC-5:** Empty / no-match catalogue → `{ "data": [] }`
- **Given:** an authenticated user; no exercises (or `?q=` matching none)
- **When:** `GET /api/v1/exercises?q=nothingmatches`
- **Expect:** `200`; `assertExactJson(['data' => []])`

**TC-6:** Response exposes the uuid as `id`, never the internal id or `created_by_ai`
- **Given:** an authenticated user; one exercise
- **When:** `GET /api/v1/exercises`
- **Expect:** `200`; `data.0.id` equals the row's `uuid`; `assertJsonMissingPath('data.0.created_by_ai')`; `assertJsonMissingPath('data.0.created_at')`

**TC-7:** Unauthenticated → `401`
- **Given:** no `actingAs` (the `Origin` header is still set)
- **When:** `GET /api/v1/exercises`
- **Expect:** `401`; `assertJsonPath('data.code', 'AUTHENTICATION_EXCEPTION')`

### POST `/api/v1/sessions/{session}/sets` — `tests/Feature/Session/LogSetTest.php`

**TC-8:** Planned set via `day_exercise_id` — happy path (AC1: peso + reps requeridos; AC2: series por ejercicio)
- **Given:** an authenticated user; `$session = openPlannedSession($user)`; `$dayExercise = $session->cycleDay->dayExercises->first()`
- **When:** `POST /api/v1/sessions/{$session->uuid}/sets` with `{ "day_exercise_id": $dayExercise->uuid, "set_number": 1, "weight_kg": 80, "reps": 8 }`
- **Expect:** `201`; `data.id` matches a v4 uuid regex; `data.exercise.id === $dayExercise->exercise->uuid`; `data.set_number === 1`; `data.weight_kg === 80`; `data.reps === 8`; `data.rpe === null`; `data.note === null`; `assertDatabaseHas('set_logs', ['uuid' => data.id, 'session_id' => $session->id, 'exercise_id' => $dayExercise->exercise_id, 'set_number' => 1])`; `assertDatabaseCount('set_logs', 1)`

**TC-9:** Free set via `exercise_id` on a free session (session `cycle_day_id` null)
- **Given:** an authenticated user; `$session = openFreeSession($user)`; `$exercise = Exercise::factory()->create()`
- **When:** `POST` with `{ "exercise_id": $exercise->uuid, "set_number": 1, "weight_kg": 100.5, "reps": 5, "rpe": 8, "note": "felt strong" }`
- **Expect:** `201`; `data.exercise.id === $exercise->uuid`; `data.rpe === 8`; `data.note === "felt strong"`; `assertDatabaseHas('set_logs', ['session_id' => $session->id, 'exercise_id' => $exercise->id, 'set_number' => 1])`

**TC-10:** Off-plan `exercise_id` on a *planned* session → `201` (an extra exercise is allowed)
- **Given:** an authenticated user; `$session = openPlannedSession($user)`; `$extra = Exercise::factory()->create()` (not in any `day_exercise` of the cycle)
- **When:** `POST` with `{ "exercise_id": $extra->uuid, "set_number": 1, "weight_kg": 20, "reps": 12 }`
- **Expect:** `201`; `data.exercise.id === $extra->uuid`; `assertDatabaseCount('set_logs', 1)`

**TC-11:** Multiple sets of the same exercise — `set_number` 1, 2, 3 (AC2)
- **Given:** an authenticated user; `$session = openFreeSession($user)`; `$exercise = Exercise::factory()->create()`
- **When:** three `POST`s with `set_number` 1, then 2, then 3 (same `exercise_id`)
- **Expect:** each `201`; `assertDatabaseCount('set_logs', 3)`; the rows carry `set_number` 1, 2, 3

**TC-12:** `rpe` and `note` are optional (AC1)
- **Given:** an authenticated user; `$session = openFreeSession($user)`; an exercise
- **When:** `POST` with only `{ exercise_id, set_number: 1, weight_kg: 60, reps: 10 }`
- **Expect:** `201`; `data.rpe === null`; `data.note === null`; row has `rpe` null, `note` null

**TC-13:** Both `day_exercise_id` and `exercise_id` → `422` on both, nothing written
- **Given:** an authenticated user; `$session = openPlannedSession($user)`; a `dayExercise`; a stray `Exercise`
- **When:** `POST` with both ids set + valid numeric fields
- **Expect:** `422`; `assertJsonValidationErrors(['day_exercise_id', 'exercise_id'], 'data.errors')`; `assertDatabaseCount('set_logs', 0)`

**TC-14:** Neither `day_exercise_id` nor `exercise_id` → `422` on both
- **Given:** an authenticated user; `$session = openFreeSession($user)`
- **When:** `POST` with `{ "set_number": 1, "weight_kg": 60, "reps": 10 }`
- **Expect:** `422`; `assertJsonValidationErrors(['day_exercise_id', 'exercise_id'], 'data.errors')`

**TC-15:** `day_exercise_id` not a uuid / absent from `day_exercises` → `422` (dataset)
- **Given:** an authenticated user; `$session = openPlannedSession($user)`
- **When:** `POST` with `day_exercise_id` = `"not-a-uuid"`, then `(string) Str::uuid()`
- **Expect:** each `422`; `assertJsonValidationErrors('day_exercise_id', 'data.errors')`; nothing written

**TC-16:** `exercise_id` well-formed uuid absent from `exercises` → `422`
- **Given:** an authenticated user; `$session = openFreeSession($user)`
- **When:** `POST` with `exercise_id` = `(string) Str::uuid()` + valid fields
- **Expect:** `422`; `assertJsonValidationErrors('exercise_id', 'data.errors')`

**TC-17:** `weight_kg` out of range → `422` (dataset: `-1`, `1000.01`, `80.123`)
- **Given:** an authenticated user; `$session = openFreeSession($user)`; an exercise
- **When:** `POST` with each bad `weight_kg`
- **Expect:** each `422`; `assertJsonValidationErrors('weight_kg', 'data.errors')`; `assertDatabaseCount('set_logs', 0)`

**TC-18:** `reps` out of range → `422` (dataset: `0`, `101`, `8.5`)
- **Given:** an authenticated user; a free session + exercise
- **When:** `POST` with each bad `reps`
- **Expect:** each `422`; `assertJsonValidationErrors('reps', 'data.errors')`

**TC-19:** `rpe` out of range or not a 0.5 step → `422` (dataset: `-0.5`, `10.5`, `7.3`)
- **Given:** an authenticated user; a free session + exercise
- **When:** `POST` with each bad `rpe`
- **Expect:** each `422`; `assertJsonValidationErrors('rpe', 'data.errors')`

**TC-20:** `note` longer than 1000 chars → `422`
- **Given:** an authenticated user; a free session + exercise
- **When:** `POST` with `note` = `str_repeat('x', 1001)`
- **Expect:** `422`; `assertJsonValidationErrors('note', 'data.errors')`

**TC-21:** Non-contiguous `set_number` → `409` `NON_CONTIGUOUS_SET_NUMBER`, nothing written (dataset)
- **Given:** an authenticated user; a free session + exercise; zero existing sets
- **When:** `POST` with `set_number: 2` (expected 1); separately, after one set exists, `POST` with `set_number: 1` and with `set_number: 3`
- **Expect:** each `409`; `assertJsonPath('data.code', 'NON_CONTIGUOUS_SET_NUMBER')`; `assertJsonMissingPath('data.errors')`; the offending `POST` writes nothing

**TC-22:** Contiguity is per exercise — a second exercise restarts at `set_number` 1
- **Given:** an authenticated user; a free session; `$a`, `$b` two exercises; one set already logged for `$a` (`set_number` 1)
- **When:** `POST` with `{ exercise_id: $b->uuid, set_number: 1, ... }`
- **Expect:** `201`; `assertDatabaseCount('set_logs', 2)`

**TC-23:** `day_exercise_id` not part of this session's training day → `409` `DAY_EXERCISE_NOT_IN_SESSION` (dataset), nothing written
- **Given:** an authenticated user. Case A: `$session = openFreeSession($user)` (no `cycle_day`) + a `dayExercise` from any cycle. Case B: `$session = openPlannedSession($user)` + a `dayExercise` from a **different** `CycleDay` of the same cycle. Case C: a `dayExercise` from **another user's** routine.
- **When:** `POST` with that `day_exercise_id` + valid fields
- **Expect:** each `409`; `assertJsonPath('data.code', 'DAY_EXERCISE_NOT_IN_SESSION')`; `assertDatabaseCount('set_logs', 0)`

**TC-24:** Session is `completed` → `409` `SESSION_ALREADY_COMPLETED`, nothing written (revised AC5)
- **Given:** an authenticated user; `$session = TrainingSession::factory()->for($user)->for(Routine::factory()->for($user))->completed()->create()`; an exercise
- **When:** `POST /api/v1/sessions/{$session->uuid}/sets` with `{ exercise_id, set_number: 1, weight_kg: 60, reps: 10 }`
- **Expect:** `409`; `assertJsonPath('data.code', 'SESSION_ALREADY_COMPLETED')`; `assertDatabaseCount('set_logs', 0)`

**TC-25:** `{session}` belongs to another user → `403`, nothing written
- **Given:** `$other = User::factory()->create()`; `$otherSession = openFreeSession($other)`; `actingAs($this->user)`; an exercise
- **When:** `POST /api/v1/sessions/{$otherSession->uuid}/sets` with valid fields
- **Expect:** `403`; `assertJsonPath('data.code', 'AUTHORIZATION_EXCEPTION')`; `assertDatabaseCount('set_logs', 0)`

**TC-26:** Unknown / non-uuid `{session}` → `404` (dataset)
- **Given:** an authenticated user
- **When:** `POST /api/v1/sessions/{(string) Str::uuid()}/sets` and `POST /api/v1/sessions/42/sets` with valid fields
- **Expect:** each `404`; for the uuid case `assertJsonPath('data.code', 'NOT_FOUND_EXCEPTION')`

**TC-27:** Unauthenticated → `401`, nothing written
- **Given:** no `actingAs`; `$session = openFreeSession(User::factory()->create())`
- **When:** `POST /api/v1/sessions/{$session->uuid}/sets` with valid fields
- **Expect:** `401`; `assertJsonPath('data.code', 'AUTHENTICATION_EXCEPTION')`; `assertDatabaseCount('set_logs', 0)`

**TC-28:** Response exposes uuids only and serialises types correctly
- **Given:** an authenticated user; a free session + exercise
- **When:** `POST` with `{ exercise_id, set_number: 1, weight_kg: 82.5, reps: 8, rpe: 7.5 }`
- **Expect:** `201`; `data.id` is the row's `uuid`; `data.exercise.id` is the exercise's `uuid`; `assertJsonMissingPath('data.session_id')`, `assertJsonMissingPath('data.exercise_id')`, `assertJsonMissingPath('data.exercise.created_by_ai')`; `data.weight_kg === 82.5` (number, not `"82.50"`); `data.rpe === 7.5`; `data.created_at` matches `/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/`

**TC-29:** Cross-user isolation — logging never touches another user's sets
- **Given:** `$other = User::factory()->create()`; `$otherSession = openFreeSession($other)` with one existing set; `$session = openFreeSession($this->user)`; an exercise
- **When:** `actingAs($this->user)` → `POST /api/v1/sessions/{$session->uuid}/sets` with valid fields
- **Expect:** `201`; `$other`'s session still has exactly one set; the new row's `session_id === $session->id`

**TC-30:** Strict-mode render guard — the Resource never triggers a lazy load
- **Given:** an authenticated user; a planned session + `dayExercise`
- **When:** `POST` with `{ day_exercise_id, set_number: 1, weight_kg: 80, reps: 8 }`
- **Expect:** `201` with no `500`; `data.exercise` is a populated object (eager-loaded)

### PUT `/api/v1/sessions/{session}/sets/{set}` — `tests/Feature/Session/UpdateSetLogTest.php`

**TC-31:** Correct a set while the session is `in_progress` — happy path (revised AC3)
- **Given:** an authenticated user; a free session + exercise; `$set = SetLog::factory()->for($session, 'session')->for($exercise)->create(['set_number' => 1, 'weight_kg' => 80, 'reps' => 8, 'rpe' => null, 'note' => null])`
- **When:** `PUT /api/v1/sessions/{$session->uuid}/sets/{$set->uuid}` with `{ "weight_kg": 82.5, "reps": 7, "rpe": 8, "note": "last set hard" }`
- **Expect:** `200`; `data.weight_kg === 82.5`; `data.reps === 7`; `data.rpe === 8`; `data.note === "last set hard"`; `assertDatabaseHas('set_logs', ['id' => $set->id, 'weight_kg' => 82.5, 'reps' => 7])`

**TC-32:** Omitting `rpe` / `note` nulls them (PUT is a full replace of the mutable fields)
- **Given:** an authenticated user; a free session + exercise; `$set` with `rpe = 8`, `note = "x"`
- **When:** `PUT` with `{ "weight_kg": 80, "reps": 8 }`
- **Expect:** `200`; `data.rpe === null`; `data.note === null`; row updated accordingly

**TC-33:** Missing `weight_kg` or `reps` → `422` (dataset)
- **Given:** an authenticated user; a free session + exercise; a `$set`
- **When:** `PUT` with `{ "reps": 8 }`, then `{ "weight_kg": 80 }`
- **Expect:** each `422`; `assertJsonValidationErrors('weight_kg', 'data.errors')` / `assertJsonValidationErrors('reps', 'data.errors')`; the row is unchanged

**TC-34:** Out-of-range values → `422` (dataset mirrors TC-17…TC-20)
- **Given:** an authenticated user; a free session + exercise; a `$set`
- **When:** `PUT` with `weight_kg: 1000.01`, `reps: 0`, `rpe: 7.3`, `note` of 1001 chars (each in its own case)
- **Expect:** each `422` on the matching field; the row is unchanged

**TC-35:** Session is `completed` → `409` `SESSION_ALREADY_COMPLETED`, row unchanged (revised AC5)
- **Given:** an authenticated user; a session + exercise + `$set`; then the session is set to `completed` (`$session->update(['status' => SessionStatus::Completed, 'completed_at' => now()])`)
- **When:** `PUT` with a valid body
- **Expect:** `409`; `assertJsonPath('data.code', 'SESSION_ALREADY_COMPLETED')`; `assertDatabaseHas('set_logs', ['id' => $set->id, 'weight_kg' => <original>])`

**TC-36:** `{set}` belongs to a different session than `{session}` in the URL → `404` (scoped binding)
- **Given:** an authenticated user; two of the user's own free sessions `$a`, `$b`; `$set` belongs to `$b`
- **When:** `PUT /api/v1/sessions/{$a->uuid}/sets/{$set->uuid}` with a valid body
- **Expect:** `404`; `assertJsonPath('data.code', 'NOT_FOUND_EXCEPTION')`; the row is unchanged

**TC-37:** `{set}`'s session belongs to another user → `403`, row unchanged
- **Given:** `$other = User::factory()->create()`; `$otherSession = openFreeSession($other)`; `$set` belongs to `$otherSession`; `actingAs($this->user)`
- **When:** `PUT /api/v1/sessions/{$otherSession->uuid}/sets/{$set->uuid}` with a valid body
- **Expect:** `403`; `assertJsonPath('data.code', 'AUTHORIZATION_EXCEPTION')`

**TC-38:** Unknown / non-uuid `{set}` → `404` (dataset)
- **Given:** an authenticated user; a free session `$session`
- **When:** `PUT /api/v1/sessions/{$session->uuid}/sets/{(string) Str::uuid()}` and `.../sets/42` with a valid body
- **Expect:** each `404`

**TC-39:** Unauthenticated → `401`, row unchanged
- **Given:** no `actingAs`; a session + `$set` owned by another factory user
- **When:** `PUT` with a valid body
- **Expect:** `401`; `assertJsonPath('data.code', 'AUTHENTICATION_EXCEPTION')`

**TC-40:** `set_number` and exercise are immutable through PUT
- **Given:** an authenticated user; a free session; `$a`, `$b` exercises; `$set` on `$a` with `set_number` 1
- **When:** `PUT` with `{ "weight_kg": 80, "reps": 8, "set_number": 9, "exercise_id": $b->uuid }`
- **Expect:** `200`; `data.set_number === 1`; `data.exercise.id === $a->uuid`; row's `set_number` / `exercise_id` unchanged

**TC-41:** Response shape matches the `POST` body
- **Given:** an authenticated user; a free session + exercise + `$set`
- **When:** `PUT` with a valid body
- **Expect:** `200`; `assertJsonStructure(['data' => ['id', 'exercise' => ['id', 'name', 'slug', 'primary_muscle_group'], 'set_number', 'weight_kg', 'reps', 'rpe', 'note', 'created_at', 'updated_at']])`; `data.id === $set->uuid`; `data.updated_at` matches the ISO-8601 regex

### Actions — `tests/Feature/Session/SetLogActionTest.php`

**TC-42:** `SetLogCreateAction::handle()` resolves the exercise from `day_exercise_id`, runs the guards, returns the row with `exercise` loaded
- **Given:** a `User`; `$session = openPlannedSession($user)`; `$dayExercise = $session->cycleDay->dayExercises->first()`; `$data = LogSetData::from(['day_exercise_id' => $dayExercise->uuid, 'set_number' => 1, 'weight_kg' => 80, 'reps' => 8])`
- **When:** `app(SetLogCreateAction::class)->handle($session, $data)`
- **Expect:** the returned `SetLog` has `exercise_id === $dayExercise->exercise_id`, `set_number === 1`, `wasRecentlyCreated === true`, `relationLoaded('exercise') === true`; `assertDatabaseCount('set_logs', 1)`

**TC-43:** `SetLogCreateAction::handle()` with `exercise_id` opens a free-session set
- **Given:** a `User`; `$session = openFreeSession($user)`; `$exercise = Exercise::factory()->create()`; `$data = LogSetData::from(['exercise_id' => $exercise->uuid, 'set_number' => 1, 'weight_kg' => 60, 'reps' => 10])`
- **When:** `handle($session, $data)`
- **Expect:** returned `SetLog` has `exercise_id === $exercise->id`; row persisted

**TC-44:** `SetLogCreateAction::handle()` rejects a completed session and writes nothing
- **Given:** a completed `TrainingSession`; a catalogue `Exercise`
- **When:** `app(SetLogCreateAction::class)->handle($session, LogSetData::from([...]))`
- **Expect:** throws `App\Exceptions\Session\SessionAlreadyCompletedException` (`errorCode() === 'SESSION_ALREADY_COMPLETED'`, `statusCode() === 409`); `assertDatabaseCount('set_logs', 0)`

**TC-45:** `SetLogCreateAction::handle()` rejects a `day_exercise_id` outside the session's training day
- **Given:** a `User`; `$session = openPlannedSession($user)`; a `DayExercise` from a **different** `CycleDay` of the same cycle; separately, a free session + any `DayExercise`
- **When:** `handle($session, LogSetData::from(['day_exercise_id' => ..., ...]))`
- **Expect:** each throws `DayExerciseNotInSessionException` (`errorCode() === 'DAY_EXERCISE_NOT_IN_SESSION'`, `409`); nothing written

**TC-46:** `SetLogCreateAction::handle()` requires `set_number` to be `count + 1`, counted per `(session, exercise)`
- **Given:** a free session; exercise `A` with two logged sets (`set_number` 1, 2); exercise `B` with none
- **When:** `handle()` with `set_number` 3 for `A` and 1 for `B` (both succeed); then a stale `set_number` for either
- **Expect:** the in-order calls persist; a stale `set_number` throws `NonContiguousSetNumberException` (`errorCode() === 'NON_CONTIGUOUS_SET_NUMBER'`, `409`), message names the expected next number

**TC-47:** `SetLogUpdateAction::handle()` guards on the session and updates only the mutable fields
- **Given:** a `User`; an `in_progress` session + `$set` (`set_number` 1, `weight_kg` 80); `$data = UpdateSetLogData::from(['weight_kg' => 82.5, 'reps' => 7])`
- **When:** `app(SetLogUpdateAction::class)->handle($session, $set, $data)`
- **Expect:** returned `SetLog` has `weight_kg == 82.5`, `reps === 7`, `set_number === 1` (unchanged), `relationLoaded('exercise') === true`; with a completed `$session` the same call throws `SessionAlreadyCompletedException` and the row is unchanged

### Policy — `tests/Unit/Session/SetLogPolicyTest.php`

**TC-48:** `SetLogPolicy::create` allows the session owner, denies others
- **Given:** `$owner`, `$stranger` as `User` instances with distinct ids; `$session` with `user_id = $owner->id`
- **When:** `(new SetLogPolicy)->create($owner, $session)` and `->create($stranger, $session)`
- **Expect:** `true` for the owner, `false` for the stranger

**TC-49:** `SetLogPolicy::update` allows the owner of the set's session, denies others
- **Given:** `$owner`, `$stranger`; a persisted `SetLog` whose session's `user_id = $owner->id`
- **When:** `->update($owner, $set)` and `->update($stranger, $set)`
- **Expect:** `true` / `false`; the Policy is a pure `user_id` comparison on the bound session (no DB, no relation access)

### Architecture — `tests/Feature/ArchTest.php` (added rule)

**TC-50:** Exercise controllers are invokable
- **Given:** the project code
- **When:** the Pest architecture assertions run
- **Expect:** `App\Http\Controllers\Exercise` is invokable (new `arch(...)` line); the existing rules still pass — covering `SetLogCreateAction` / `SetLogUpdateAction` (`final` + `handle()`), `LogSetRequest` / `UpdateSetLogRequest` (extend `FormRequest`), and no debug helpers

### OpenAPI — `tests/Feature/Auth/DocsSecurityTest.php` (extends the existing test)

**TC-51:** The generated OpenAPI spec marks the three new routes secured
- **Given:** the app with `security_strategy = MiddlewareAuthSecurityStrategy` (already on `main`)
- **When:** the spec is generated in-process
- **Expect:** `$spec['paths']['/api/v1/exercises']['get']`, `['/api/v1/sessions/{session}/sets']['post']` and `['/api/v1/sessions/{session}/sets/{set}']['put']` each have **no** per-operation `security` key (they inherit the global one), asserted alongside the existing profile / routine / session paths

---

## 9. Technical Decisions

| Decision area | What was decided | Why |
|---|---|---|
| Endpoint scope | **Log a set (`POST`) and correct a set (`PUT`) only.** No `DELETE`. Plus a read-only `GET /api/v1/exercises`. | Product-owner decision (this session): a logged set is corrected, never erased, so the AI progression history stays intact. The ticket's AC4 is dropped and AC5 is narrowed to editing (§1). `GET /api/v1/exercises` was added at the product owner's request because the free-session path needs a way to obtain an `exercise_id` and there is no catalogue endpoint yet. |
| `GET /api/v1/exercises` shape | Invokable controller, **no Form Request, no Action**, reads `q` / `muscle_group` off the `Request`; `Exercise::query()->when(...)->orderBy('name')->limit(50)->get()`; returns `ExerciseResource::collection(...)`. No Policy. No pagination. | Trivial read with no side effects — the `list-routines-spec.md` / `ShowAthleteProfileController` precedent (`CLAUDE.md` rules 5–6: no layer that only adds indirection). The catalogue is global, so ownership / Policy do not apply. 50 rows is a type-ahead budget; paging is a later story if the catalogue grows (recorded as a known simplification). |
| `?muscle_group=` unknown value | **Ignored** (`MuscleGroup::tryFrom()` → `null` → filter skipped), not `422`. | Consistent with "no Form Request for a trivial read". A reviewer who wants a strict `422` here can add a minimal `abort_unless` or promote the endpoint to a Form Request — called out for the spec review. |
| `?q=` matching | `whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($term).'%'])` — case-insensitive substring on `name`. | Portable across Postgres 17 (`LIKE` is case-sensitive; `LOWER()` normalises) and SQLite `:memory:`. Substring on the human `name` is the most intuitive type-ahead. Accent-insensitivity is out of scope (noted). |
| URL shape for sets | **Nested under the session:** `POST /api/v1/sessions/{session}/sets`, `PUT /api/v1/sessions/{session}/sets/{set}`. `{session}` and `{set}` bound by `uuid`. | `docs/plans/data-model.md` puts `SetLog` under the `Session` domain; a set has no meaning outside its session. `session_id` comes from the route, not the body. Mirrors the parent spec's nested `routines/{routine}/sessions`. |
| `{set}` scoped binding | `->scopeBindings()` on the `PUT` route; the `TrainingSession` relation is named **`sets()`** (not `setLogs()`) so Laravel resolves `{set}` through `$session->sets()`. | Scoped implicit binding derives the relation from the route parameter (`{set}` → `sets`). A `{set}` from another session → `404` (not a leak-prone `403`). "A session has many sets" reads naturally; the model stays `SetLog`. |
| Model & table name | Model `App\Models\SetLog` (flat, singular); table `set_logs`. Pipeline classes: `LogSetController`, `UpdateSetLogController`, `SetLogCreateAction`, `SetLogUpdateAction`, `SetLogResource`, `SetLogPolicy`, `LogSetData`, `UpdateSetLogData`, `LogSetRequest`, `UpdateSetLogRequest`. Folders stay `Session`. | `data-model.md` §`set_logs`. Verb-first controllers (`CLAUDE.md`): `LogSet` for the create, `UpdateSetLog` for the edit. |
| `session_id` FK target | `foreignId('session_id')->constrained('training_sessions')->cascadeOnDelete()` — table named **explicitly**. | `->constrained()` would infer `sessions` (the Laravel session store). Same clash the parent spec documents. Cascade because a set is meaningless without its session. |
| `exercise_id` FK behaviour | `constrained()->restrictOnDelete()` — **never cascades**. | `data-model.md`: "`exercise_id` **nunca** cascadea: el catálogo es permanente." The catalogue is not user-owned and is never deleted; the constraint states the invariant. |
| `set_number` ownership | **Client-supplied**, validated in `SetLogCreateAction` to equal `(count of set_logs for (session_id, exercise_id)) + 1`. Contiguity is **per exercise**. Immutable through `PUT`. | Product-owner decision (this session): the client is explicit about ordering and a desync is detected rather than silently papered over. Since sets can never be deleted and `set_number` can never change, contiguity is preserved for free — the only check needed is "the next index" on create. |
| `set_number` violation → 409 (not 422) | `NonContiguousSetNumberException extends DomainException` → `409` `NON_CONTIGUOUS_SET_NUMBER`, thrown from the Service. | The check needs DB state (the current count) — that is business knowledge, and `CLAUDE.md` puts state-dependent guards in a Service, which render at `409`. Called out so a reviewer who prefers a field-level `422` (via a closure rule reading the count) can push back. |
| Exercise identification | Body carries **exactly one** of `day_exercise_id` (prescription `uuid`) or `exercise_id` (catalogue `uuid`). `required_without` + `prohibits` on each. `day_exercise_id` → Service loads the `DayExercise` (`with('exercise')`), asserts `cycle_day_id === session.cycle_day_id`, stores `day_exercise.exercise_id`. `exercise_id` → used directly. | Product-owner decision (this session). `set_logs.exercise_id` is always the catalogue FK (`data-model.md`) so a free session works. `day_exercise_id` keeps the prescribed path exact (no name-matching), `exercise_id` covers off-plan and free sessions. `DayExerciseResource`'s existing `id` field is already the `day_exercise` uuid — no resource change. |
| `day_exercise` mismatch → 409 | `DayExerciseNotInSessionException` → `409` `DAY_EXERCISE_NOT_IN_SESSION`. One code covers: session is free (no `cycle_day_id`), day from another `CycleDay`, day from another cycle, day from another user's routine. | Same pattern as the parent spec's `CYCLE_DAY_NOT_IN_ACTIVE_CYCLE`. The `exists:day_exercises,uuid` rule only proves the row is real; the cross-entity check is the Service's. |
| Session-completed guard | `SessionAlreadyCompletedException` → `409` `SESSION_ALREADY_COMPLETED`, a one-line `throw_if` at the top of both `SetLogCreateAction::handle()` and `SetLogUpdateAction::handle()`. `SessionStatus` has only `in_progress` / `completed`, so "not completed" ≡ `in_progress`. | The revised AC5. The request is well-formed and the session is owned; the state forbids the write → `409`, not `403` / `422`. |
| No routine re-check on writes | The set endpoints do not verify the session's routine is still `active`. | The `active`-routine gate is at session-*open* time (Order 100). An open session remains fillable until completed even if the routine was archived meanwhile (creating a new routine archives the old one but does not close its open session — a known parent-spec limitation). |
| Guard placement | **No service.** Each guard is a named private method on the Action, so `handle()` reads as a list of steps: `SetLogCreateAction::handle()` → `ensureSessionOpen()`, `resolveExercise()` (which calls `ensurePrescriptionInSession()`), `ensureSetNumberIsNext()`, then the insert; `SetLogUpdateAction::handle()` → `ensureSessionOpen()`, then the update. Mirrors `RoutineCreateAction::ensureOnboardingComplete()`. | A `SetLoggingService` was drafted and rejected in review (`CLAUDE.md` rule 6): it wrapped one `throw_if`, one `firstOrFail` and one `count` for a single real caller and split the use-case story across two files. Private methods keep the story in one place and still read top-to-bottom. `ensureSessionOpen()` is the one repeated line across the two Actions — cheaper than a class. Covered white-box (`SetLogActionTest`) and over HTTP. |
| Domain exceptions | Three `final` classes under `App\Exceptions\Session\`, each `extends App\Exceptions\DomainException`, `protected string $errorCode` (SCREAMING_SNAKE), default `409`. `NonContiguousSetNumberException::__construct(int $expected)` builds a message naming the expected number; the other two take no args. No handler wiring. | Matches the existing Session exceptions. `ApiExceptionRenderer`'s `DomainException` arm renders them at `409` automatically. A dynamic message for the contiguity error is a genuine aid to the client; the base only fixes `code` + status, not the message. |
| `SetLog` casts | `set_number` → `integer`, `weight_kg` → `decimal:2`, `reps` → `integer`, `rpe` → `decimal:1`. `weight_kg` / `rpe` therefore read back as strings; `SetLogResource` casts them to `(float)` / `null`. | `decimal:*` is the codebase convention for money-like precision (`DayExercise::target_weight_kg` / `target_rpe`). The Resource float-casts exactly as `DayExerciseResource` does. |
| Response body | `SetLogResource`: `id` (uuid), `exercise` = `ExerciseResource::make($this->whenLoaded('exercise'))`, `set_number`, `weight_kg` (float), `reps` (int), `rpe` (float\|null), `note`, `created_at`, `updated_at` (ISO-8601). `POST` → `201` via `->response()->setStatusCode(...)`; `PUT` → `200` via a bare `SetLogResource::make(...)` return. | `CLAUDE.md` rule 3 (always a real `JsonResource`) + "one per entity, compose with nested resources". The client accumulates session state from these single-set responses; a whole-session serializer with its set list is Order 330's job. |
| `exercise` reuses `ExerciseResource` | The nested `exercise` block in `SetLogResource` is the same `ExerciseResource` the catalogue endpoint returns — `{ id, name, slug, primary_muscle_group }`. | `CLAUDE.md`: one Resource per entity, composed. `slug` / `primary_muscle_group` are harmless extra context and keep a single source of truth for the exercise shape. |
| Eager loading | Both Actions return `$set->load('exercise')`. `SetLogCreateAction::resolveExercise()` (a private method) loads the `DayExercise` with `with('exercise')`. `SetLogPolicy::update` gates on the bound `{session}`, not the `{set}`, so it needs no relation access. | `Model::shouldBeStrict(!isProduction())` makes any lazy load in the Resource or Policy throw. Same discipline as `TrainingSessionCreateAction`'s `->load(...)`. |
| Request DTOs | `App\Data\Session\LogSetData` (`?string $day_exercise_id`, `?string $exercise_id`, `int $set_number`, `float $weight_kg`, `int $reps`, `?float $rpe`, `?string $note`) and `App\Data\Session\UpdateSetLogData` (`float $weight_kg`, `int $reps`, `?float $rpe`, `?string $note`), both `final … extends Data`, built with `::from($request->validated())`. Required params first (PHP promotion), snake_case names map 1:1 to the validated keys. | `CLAUDE.md` convention (writes take a `Data` object). Snake_case props avoid a `#[MapInputName]`. |
| `rpe` 0.5-step rule | An inline closure rule in `rules()`: `fn ($attr, $value, $fail) => fmod((float) $value * 2, 1.0) !== 0.0 && $fail(...)`. No rule class. | One trivial check used in two Form Requests — a dedicated `Rule` object would be indirection with no reuse pressure (`CLAUDE.md` rule 6). If a third caller appears, extract it then. |
| `""` / whitespace normalisation | `prepareForValidation()` collapses whitespace-only `day_exercise_id` / `exercise_id` / `note` to `null` (`$this->merge([...])`), mirroring `StoreRoutineRequest::hint` / `StoreTrainingSessionRequest::day`. | Keeps `required_without` / `nullable` honest when a client sends `""` for "not set". |
| Authorization | `SetLogPolicy::create(User, TrainingSession)` and `update(User, TrainingSession)` — both gate on session ownership (`{set}` is scope-bound to `{session}`); `UpdateSetLogRequest::authorize()` calls `can('update', [SetLog::class, $this->route('session')])`. Auto-discovered; wired via the Form Requests. No `delete`. Route-model binding runs first, so unknown ids → `404` before `403`. | First endpoint pair where a Policy `403` is reachable on a nested write. Ownership is the only rule; session state and the day check are `409` business rules. |
| `TrainingSession::sets()` FK | `$this->hasMany(SetLog::class, 'session_id')` — the FK is named **explicitly**; `SetLog::session()` is `belongsTo(TrainingSession::class)` (method name `session` → `session_id`, inferred). | `TrainingSession` would otherwise infer `training_session_id`. `session_id` is the `data-model.md` column. |
| Mass assignment | `SetLog` `#[Fillable(['session_id', 'exercise_id', 'set_number', 'weight_kg', 'reps', 'rpe', 'note'])]`. The create Action writes through `$session->sets()->create([...])` (the relation sets `session_id`); `exercise_id` is set from the Service-resolved model, never from the request body. | `Model::preventSilentlyDiscardingAttributes()` (strict mode) throws on a non-fillable `create()` key. `session_id` / `exercise_id` are server-derived (never from `$request->validated()`), so there is no mass-assignment exposure — same reasoning as `TrainingSession` carrying `user_id` / `cycle_day_id`. |
| Unique index vs guard | Composite `unique(session_id, exercise_id, set_number)` **and** the contiguity guard. | The guard gives a clean `409` in the normal flow; the index is the concurrency backstop. A double-submit race that beats the guard → `QueryException` → `500` — the tradeoff the routine / session specs already accept. |
| Enum storage & location | No new enums. `SetLog` stores no enum column. `GET /api/v1/exercises` filters on `MuscleGroup` (`App\Enums\Shared`, already exists). | `data-model.md` §`set_logs` has no status/enum field. |
| Rate limiting | None. | Authenticated, low-abuse; matches every other data route. |
| Scramble `security_strategy` | No `config/scramble.php` change — `MiddlewareAuthSecurityStrategy` on `main` already covers any `auth:sanctum` route. Three `DocsSecurityTest` assertions added. | The new routes match that middleware, so they are documented as secured automatically. |
| Tests: DB & no AI | SQLite `:memory:` + `RefreshDatabase` (already wired). Catalogue rows via `Exercise::factory()`, cycle trees via the Cycle factories, never the planner. | Faster, deterministic; the planner is covered by the cycle specs. |
| Git artifacts | English only. **No AI attribution anywhere** — no `Co-Authored-By: Claude` / `Claude-Session:` commit trailers, no `🤖 Generated with Claude Code` (or any "generated by" / tool-credit) line in a commit message, PR title, PR description or review comment. | Repo `CLAUDE.md` / `AGENTS.md` "Git" rule; it takes precedence over the session's attribution instruction. |

---

## 10. Work Plan

Pipeline classes are created before wiring `routes/api.php`. Each task's DoD is
the artifact existing, passing Pint + PHPStan level 6, and — where the class
carries logic — its focused test authored in the same task. Tasks 15–17 (the
endpoint feature tests) are the functional gate.

| # | Task | Definition of Done |
|---|---|---|
| 1 | Clone the runtime DB: `docker compose exec pgsql createdb -U gym -T gym_trainer gym_trainer_store_set_logs`; set `DB_DATABASE=gym_trainer_store_set_logs` in this worktree's `.env` | The worktree toolchain targets the clone; `gym_trainer` untouched; the Pest suite still uses SQLite. |
| 2 | Create `database/migrations/2026_09_03_130000_create_set_logs_table.php` per §4.1 (anonymous class; `uuid` unique; `session_id` → `constrained('training_sessions')->cascadeOnDelete()`; `exercise_id` → `constrained()->restrictOnDelete()`; `set_number` / `reps` `unsignedSmallInteger`; `weight_kg` `decimal(6,2)`; `rpe` `decimal(3,1)` nullable; `note` text nullable; `timestamps()`; `unique(['session_id', 'exercise_id', 'set_number'])`) | `php artisan migrate` runs on the clone and on a fresh SQLite; `php artisan db:table set_logs` shows the two FKs and the composite unique index. |
| 3 | Create `app/Models/SetLog.php`: `use HasFactory, HasPublicUuid;` · `#[Fillable(['session_id', 'exercise_id', 'set_number', 'weight_kg', 'reps', 'rpe', 'note'])]` · `casts()` (`set_number`/`reps` → `integer`, `weight_kg` → `decimal:2`, `rpe` → `decimal:1`) · `session(): BelongsTo` (`belongsTo(TrainingSession::class)`), `exercise(): BelongsTo`. Add `sets(): HasMany` → `hasMany(SetLog::class, 'session_id')` + `@property-read Collection<int, SetLog> $sets` / `@property-read int|null $sets_count` PHPDoc to `app/Models/TrainingSession.php` | Pint + PHPStan clean; `(new SetLog)->getCasts()` has the four casts; `(new TrainingSession)->sets()` is a `HasMany` on `session_id`. |
| 4 | Run `php artisan ide-helper:models --write` for `SetLog` + `TrainingSession`; `vendor/bin/pint app/Models`; hand-check the decimal `@property` types (string) and the `HasPublicUuid` / relation `@method` lines | PHPDoc blocks list every column / relation / scope; diff limited to the two models. |
| 5 | Create `database/factories/SetLogFactory.php` (`@extends Factory<SetLog>`): `session_id => TrainingSession::factory()`, `exercise_id => Exercise::factory()`, `set_number => 1`, `weight_kg => fake()->randomFloat(2, 20, 150)`, `reps => fake()->numberBetween(3, 12)`, `rpe => fake()->optional()->randomElement([6.0, 6.5, 7.0, 7.5, 8.0, 8.5, 9.0])`, `note => fake()->optional()->sentence()` | `SetLog::factory()->create()` persists one row with a `uuid`; `->for($session, 'session')->for($exercise)->create()` works; Pint + PHPStan clean. |
| 6 | Create `app/Data/Session/LogSetData.php` and `app/Data/Session/UpdateSetLogData.php` (`make:data`, move to `app/Data/Session/`, fix namespace) per §9 (required params first, snake_case props) | `LogSetData::from(['exercise_id' => $u, 'set_number' => 1, 'weight_kg' => 60, 'reps' => 10])->exercise_id === $u`; `UpdateSetLogData::from([...])` maps the four fields; Pint + PHPStan clean. |
| 7 | Create `app/Exceptions/Session/SessionAlreadyCompletedException.php`, `DayExerciseNotInSessionException.php`, `NonContiguousSetNumberException.php` — `final extends DomainException`; `$errorCode` = `SESSION_ALREADY_COMPLETED` / `DAY_EXERCISE_NOT_IN_SESSION` / `NON_CONTIGUOUS_SET_NUMBER`; the first two set a fixed message, `NonContiguousSetNumberException::__construct(int $expected)` interpolates it | `(new SessionAlreadyCompletedException)->errorCode()` / `->statusCode() === 409` for each; `(new NonContiguousSetNumberException(3))->getMessage()` contains `3`; Pint + PHPStan clean. |
| 8 | Create `app/Policies/SetLogPolicy.php`: `create(User $user, TrainingSession $session): bool => $session->user_id === $user->id`; `update(User $user, TrainingSession $session): bool => $session->user_id === $user->id` (both gate on the session; `{set}` is scope-bound to `{session}`). Write `tests/Unit/Session/SetLogPolicyTest.php` (TC-48, TC-49) as pure in-memory tests. | `vendor/bin/pest tests/Unit/Session/SetLogPolicyTest.php` green; Pint + PHPStan clean. |
| 9 | Create `app/Actions/Session/SetLogCreateAction.php` (`final`, **no constructor**). `handle()` reads as four steps: `$this->ensureSessionOpen($session)`; `$exercise = $this->resolveExercise($session, $data)`; `$this->ensureSetNumberIsNext($session, $exercise, $data->set_number)`; `DB::transaction(fn () => $session->sets()->create([...six fields...]))`; return `$set->load('exercise')`. Private guard methods: `ensureSessionOpen()` (`throw_if($session->status === SessionStatus::Completed, new SessionAlreadyCompletedException)`); `resolveExercise()` (branch on `day_exercise_id`: `DayExercise::query()->with('exercise')->where('uuid', …)->firstOrFail()` then `ensurePrescriptionInSession()`, return `->exercise`; else `Exercise::query()->where('uuid', $data->exercise_id)->firstOrFail()`); `ensurePrescriptionInSession()` (`throw_unless($dayExercise->cycle_day_id === $session->cycle_day_id, new DayExerciseNotInSessionException)`); `ensureSetNumberIsNext()` (`$expected = $session->sets()->where('exercise_id', $exercise->id)->count() + 1; throw_unless($setNumber === $expected, new NonContiguousSetNumberException($expected))`). | `final` + `handle()`; covered by TC-42…TC-46; no strict-mode lazy-load; Pint + PHPStan clean. |
| 10 | Create `app/Actions/Session/SetLogUpdateAction.php` (`final`, **no constructor**): `handle(TrainingSession $session, SetLog $set, UpdateSetLogData $data): SetLog` — `$this->ensureSessionOpen($session)` (same private guard as task 9); `DB::transaction(fn () => $set->update(['weight_kg' => …, 'reps' => …, 'rpe' => …, 'note' => …]))`; return `$set->load('exercise')`. Write `tests/Feature/Session/SetLogActionTest.php` (TC-42…TC-47). | Both Actions `final` + `handle()`; `vendor/bin/pest tests/Feature/Session/SetLogActionTest.php` green; Pint + PHPStan clean. |
| 11 | Create `app/Http/Requests/Session/LogSetRequest.php`: `authorize()` → `$this->user()?->can('create', [SetLog::class, $this->route('session')]) ?? false`; `rules()` per §2.1 (`day_exercise_id` / `exercise_id` each `['nullable','uuid','exists:…','required_without:<other>','prohibits:<other>']`; `set_number` `['required','integer','min:1']`; `weight_kg` `['required','numeric','min:0','max:1000','decimal:0,2']`; `reps` `['required','integer','min:1','max:100']`; `rpe` `['nullable','numeric','min:0','max:10', <0.5-step closure>]`; `note` `['nullable','string','max:1000']`); `prepareForValidation()` nulls whitespace-only `day_exercise_id` / `exercise_id` / `note` | extends `FormRequest`; Pint + PHPStan clean; both-ids and neither-id inputs fail validation; a whitespace id becomes `null`. |
| 12 | Create `app/Http/Requests/Session/UpdateSetLogRequest.php`: `authorize()` → `$this->user()?->can('update', [SetLog::class, $this->route('session')]) ?? false`; `rules()` → `weight_kg` / `reps` required + `rpe` / `note` nullable (same constraints as task 11); `prepareForValidation()` nulls whitespace-only `note` | extends `FormRequest`; Pint + PHPStan clean; `set_number` / `exercise_id` are absent from `rules()`. |
| 13 | Create `app/Http/Resources/Exercise/ExerciseResource.php` (`@mixin Exercise`): `id => $this->uuid`, `name`, `slug`, `primary_muscle_group => $this->primary_muscle_group?->value`. Create `app/Http/Resources/Session/SetLogResource.php` (`@mixin SetLog`): `id => $this->uuid`, `exercise => ExerciseResource::make($this->whenLoaded('exercise'))`, `set_number`, `weight_kg => (float) $this->weight_kg`, `reps`, `rpe => $this->rpe !== null ? (float) $this->rpe : null`, `note`, `created_at` / `updated_at` via `?->toIso8601String()` | `toArray()` of each has no internal `*_id` key; `SetLogResource` `id` is the uuid; Pint + PHPStan clean. |
| 14 | Create `app/Http/Controllers/Exercise/ListExercisesController.php` (`make:controller --invokable`, move + fix namespace): `__invoke(Request $request): AnonymousResourceCollection` — `$term = trim((string) $request->query('q', ''))`; `$mg = MuscleGroup::tryFrom((string) $request->query('muscle_group', ''))`; `Exercise::query()->when($term !== '', fn (Builder $q) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($term).'%']))->when($mg !== null, fn (Builder $q) => $q->where('primary_muscle_group', $mg->value))->orderBy('name')->limit(50)->get()`; return `ExerciseResource::collection(...)`. Create `app/Http/Controllers/Session/LogSetController.php` (`__invoke(LogSetRequest $request, TrainingSession $session, SetLogCreateAction $action): JsonResponse` → `SetLogResource::make($action->handle($session, LogSetData::from($request->validated())))->response()->setStatusCode(Response::HTTP_CREATED)`). Create `app/Http/Controllers/Session/UpdateSetLogController.php` (`__invoke(UpdateSetLogRequest $request, TrainingSession $session, SetLog $set, SetLogUpdateAction $action): SetLogResource` → `SetLogResource::make($action->handle($session, $set, UpdateSetLogData::from($request->validated())))`) | Each `final`, `__invoke` only; Pint + PHPStan clean. |
| 15 | Edit `routes/api.php`: add the three `use` imports; inside the `auth:sanctum` group add `Route::get('exercises', ListExercisesController::class)->name('exercises.list')`, `Route::post('sessions/{session}/sets', LogSetController::class)->whereUuid('session')->name('sessions.sets.store')`, `Route::put('sessions/{session}/sets/{set}', UpdateSetLogController::class)->whereUuid('session')->whereUuid('set')->scopeBindings()->name('sessions.sets.update')` | `php artisan route:list` shows the three routes under `auth:sanctum`; PHPStan clean in `routes/`. |
| 16 | Write `tests/Feature/Exercise/ListExercisesTest.php` (TC-1…TC-7) and `tests/Feature/Session/LogSetTest.php` (TC-8…TC-30, with the `openPlannedSession` / `openFreeSession` helpers and the `Origin` + user + profile `beforeEach`) | `vendor/bin/pest tests/Feature/Exercise/ListExercisesTest.php tests/Feature/Session/LogSetTest.php` all green; every TC has a test. |
| 17 | Write `tests/Feature/Session/UpdateSetLogTest.php` (TC-31…TC-41) | `vendor/bin/pest tests/Feature/Session/UpdateSetLogTest.php` all green. |
| 18 | Add `arch('exercise controllers are invokable')->expect('App\Http\Controllers\Exercise')->toBeInvokable()` to `tests/Feature/ArchTest.php` (TC-50); add the three `not->toHaveKey('security')` assertions for the new paths to `tests/Feature/Auth/DocsSecurityTest.php` (TC-51) | `vendor/bin/pest tests/Feature/ArchTest.php tests/Feature/Auth/DocsSecurityTest.php` green. |
| 19 | Update `docs/plans/data-model.md` §`set_logs`: one line that `set_number` is client-supplied and validated contiguous per `(session, exercise)`, and that `(session_id, exercise_id, set_number)` is unique | The note is present; the section still reads coherently; no other `data-model.md` change. |
| 20 | `vendor/bin/pint --dirty`, then `vendor/bin/phpstan analyse`, then a final `php artisan ide-helper:models --write` + `vendor/bin/pint app/Models` | Pint reports no diffs; PHPStan level 6 clean; `SetLog` / `TrainingSession` PHPDoc in sync with the migration. |
| 21 | `composer check` (Pint `--test` + PHPStan level 6 + full Pest — the new Exercise / Session tests, the Policy + Service tests, the arch rule, the `DocsSecurityTest` additions) | All three steps green; no regression in Auth / Profile / Routine / Cycle / Session suites. |
| 22 | Manual check with `curl` against `http://localhost:8000` (worktree app pointed at the clone): register + login → `PUT /api/v1/profile` → `POST /api/v1/routines` → `POST /api/v1/routines/{uuid}/sessions` (`{}`, `201`, free session) → `GET /api/v1/exercises?q=press` → `POST /api/v1/sessions/{uuid}/sets` with `{ exercise_id, set_number: 1, weight_kg: 80, reps: 8 }` (`201`) → again with `set_number: 1` (`409` `NON_CONTIGUOUS_SET_NUMBER`) → `PUT /api/v1/sessions/{uuid}/sets/{set}` `{ weight_kg: 82.5, reps: 7 }` (`200`) → a foreign session (`403`) → an unknown session uuid (`404`). Review `GET /docs/api` | The `curl` calls return the expected codes; the three endpoints appear in Scramble with requests from the Form Requests and responses from `SetLogResource` / `ExerciseResource`, marked secured. |
| 23 | On merge / branch drop: `docker compose exec pgsql dropdb -U gym --if-exists gym_trainer_store_set_logs`; revert `DB_DATABASE` in the worktree `.env` | The clone is gone; `.env` restored to `gym_trainer`. |

*Process note: branch name, commit messages and PR text follow `CLAUDE.md` /
`AGENTS.md` — English only, and no AI attribution anywhere (no
`Co-Authored-By: Claude` / `Claude-Session:` trailers, no `🤖 Generated with
Claude Code` / "generated by" line in any commit, PR title, PR description or
comment).*
