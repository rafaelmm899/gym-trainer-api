# Complete a training session — `POST /api/v1/sessions/{session}/complete`

> Derived from the Notion ticket "Completar una sesión" (Feature: Registro de
> sesiones · MVP · Must · Repo: API · Order 120,
> `https://app.notion.com/p/3ce5cf08db2d817e8ffcdbadd05ae3c6`) and the planning
> conversation with the product owner (this session). Base contract:
> `docs/product-context.md` §4 (steps 4–5) / §5 ("Dos momentos de análisis") /
> §7, `docs/plans/data-model.md` §`training_sessions` + §Identificadores +
> §Enums, `CLAUDE.md` "The pipeline" / "Layout" / "Conventions" / "Jobs & AI",
> `docs/plans/store-set-logs-spec.md` (the shipped, direct precedent this
> builds on — `TrainingSession.status`, `SetLog`, the `App\Exceptions\Session\`
> `DomainException` subclasses, the `openFreeSession` / `openPlannedSession`
> test helpers), `docs/plans/create-training-session-spec.md` (`TrainingSession`
> model, `SessionStatus` / `AnalysisState` enums, `HasPublicUuid`), and
> `docs/plans/domain-exception-handling-spec.md` (the `DomainException` base and
> the `{ "data": { "code", "message" } }` error envelope).

## 1. Context

**Kind:** Brownfield Feature — the Session domain (table, model, enums,
policy, factory, opening pipeline, set-logging pipeline) shipped in PR #18 and
PR #19. This ticket adds the **closing slice**: one write endpoint that
transitions a session `in_progress → completed`, two new nullable columns on
`training_sessions` (`note`, `perceived_effort`), one new `DomainException`,
one new `TrainingSessionPolicy` ability, and the seam for the AI analysis that
a separate, still-un-specced ticket fills in.

**Stack:** PHP 8.5 · Laravel 13 · PostgreSQL 17 (runtime) / SQLite `:memory:`
(tests) · Pest 4 (`pest-plugin-laravel`, `RefreshDatabase` already wired for
the `Feature` suite) · `laravel/sanctum` 4 (SPA cookie mode) ·
`spatie/laravel-data` 4 · `dedoc/scramble` 0.13 · Pint · Larastan level 6.
Everything runs in Docker.

**Problem statement:** A user opens a session
(`POST /api/v1/routines/{routine}/sessions`, Order 100) and logs sets into it
(`POST` / `PUT /api/v1/sessions/{session}/sets`, Order 110), but a session
never closes — it stays `in_progress` forever and no analysis ever runs. The
training-logging loop (`docs/product-context.md` §4 steps 4–5) needs its last
step: the user marks the day done, optionally leaves a general note and a
perceived-effort rating, and that triggers the AI analysis that will (in a
later ticket) turn today's sets into per-exercise recommendations for next
time.

**Product-owner decisions recorded from this session's planning conversation:**

- **`perceived_effort` is a subjective input, never calculated.** Deriving it
  from `weight_kg` × `reps` (e.g. via an estimated 1RM / RIR table) was
  proposed and explicitly rejected: it needs a known 1RM to be meaningful,
  which the app does not have, and baking a load→effort formula into the
  backend would contradict `docs/product-context.md` §5 — *"Motor de
  progresión: 100% IA. No hay reglas deterministas."* The client sends it (or
  doesn't); the backend stores it as-is.
- **This ticket is trigger-only for AI analysis.** The Notion "Qué falta" note
  on this ticket says the analysis job is *"compartido con 'Recibir
  recomendaciones al cerrar el día'"* (Order 130, Feature: Recomendaciones IA)
  — a separate, still-un-specced, non-MVP-blocking-sized ticket covering the
  `exercise_recommendations` table, the `RecommendationAction` /
  `RecommendationStatus` / `RecommendationConfidence` enums, the prescribed-vs-
  actual progression summary, the `laravel/ai` analyst agent, and the
  `analysis_state` `pending → processing → done/failed` lifecycle with retry.
  This ticket only **dispatches** the job (`App\Jobs\Session\
  SessionAnalysisJob`) as an empty placeholder — same shape as the existing
  `App\Jobs\Cycle\GenerateCycleJob` seam — so `analysis_state` stays `pending`
  for the whole life of this ticket. The user's own example (curl de bíceps:
  4×10 @ 20 kg ejecutado tal cual → sugerir subir peso y/o reps) is exactly
  what Order 130's agent will do, once it exists; this ticket only guarantees
  the trigger fires and never blocks the close.
- **Already-`completed` → reuse the existing `SessionAlreadyCompletedException`**
  (409 `SESSION_ALREADY_COMPLETED`, shipped in PR #19). No idempotent-200
  behavior, no new exception for this case.
- **No sets logged → 422, a new exception.** The AC explicitly mandates `422`
  (not the `DomainException` default of `409`) for this guard.

**In scope:**

- **`POST /api/v1/sessions/{session}/complete`** — close a session the caller
  owns. Body: optional `note` (string, ≤ 1000 chars) and optional
  `perceived_effort` (integer, 1–5). `200` with the updated
  `TrainingSessionResource`.
- Two new nullable columns on `training_sessions`: `note` (`text`) and
  `perceived_effort` (`unsignedTinyInteger`). A new migration (the original
  `create_training_sessions_table` migration is not edited).
- `App\Data\Session\CompleteSessionData` — the request DTO.
- `App\Http\Requests\Session\CompleteTrainingSessionRequest` — shape
  validation + authorization via `TrainingSessionPolicy::complete`.
- `App\Http\Controllers\Session\CompleteTrainingSessionController` — invokable.
- `App\Actions\Session\SessionCloseAction` — the only layer that opens the
  transaction and dispatches `SessionAnalysisJob`.
- `App\Services\Session\SessionCompletionService` — the two business guards
  (already completed; no sets logged).
- `App\Exceptions\Session\SessionHasNoSetsException` — `final`,
  `DomainException` subclass, `422`.
- `App\Jobs\Session\SessionAnalysisJob` — new placeholder job, empty `handle()`.
- `App\Policies\TrainingSessionPolicy::complete(User, TrainingSession)` — new
  ability alongside the existing `create`.
- `App\Http\Resources\Session\TrainingSessionResource` — exposes `note` and
  `perceived_effort`.
- `TrainingSession` model, factory and PHPDoc updated for the two new columns.
- One route added to the `auth:sanctum` group in `routes/api.php`
  (`sessions.complete`), constrained with `->whereUuid('session')`.
- `tests/Feature/Auth/DocsSecurityTest.php` — assert the new route inherits
  the global `security` (no new `ArchTest` rule: `App\Http\Controllers\Session`
  is already covered by the existing "session controllers are invokable" rule).
- Pest feature + unit coverage of every acceptance criterion.

**Out of scope:**

- **The AI analysis engine itself** — the analyst agent, the prescribed-vs-
  actual progression summary, `exercise_recommendations` + its enums, and the
  `analysis_state` lifecycle beyond staying `pending`. All of that is ticket
  "Recibir recomendaciones al cerrar el día" (Order 130). `SessionAnalysisJob`
  exists here only as the dispatch target — its `handle()` body is empty.
- **Reading a session back** (`GET /api/v1/sessions/{session}`, the session
  history) — "Listar el historial de sesiones" (Order 330, non-MVP). The
  `POST /complete` response returns the full session state; nothing here adds
  a dedicated read endpoint.
- **Reopening a completed session.** There is no `in_progress ← completed`
  transition, no endpoint for it, and none is implied by any AC.
- **Any per-set change.** This ticket never touches `set_logs` — it only reads
  `session->sets()->exists()` for the empty-session guard. Correcting a set
  stays `PUT /api/v1/sessions/{session}/sets/{set}` (Order 110), and is already
  blocked once the session is `completed`.
- **Calculating `perceived_effort` from load/volume/e1RM.** Explicitly
  rejected — see "Product-owner decisions" above.
- **A routine re-check on close.** Like the set endpoints (Order 110), closing
  does not re-verify the session's routine is still `active`. An open session
  can be completed even if its routine was archived meanwhile.
- The `gym-trainer-spa/` frontend (separate repository) — "Pantalla: completar
  sesión" (Order 240) is a distinct, later, UI-only ticket.

---

## 2. API Surface

### 2.1 REST

The route joins the existing `Route::middleware('auth:sanctum')->group(...)`
in `routes/api.php`, under the global `apiPrefix: 'api/v1'`. It is stateful
(`$middleware->statefulApi()` in `bootstrap/app.php`) — subject to
`EnsureFrontendRequestsAreStateful` + CSRF. CSRF is auto-bypassed under
`php artisan test` (`ValidateCsrfToken::runningUnitTests()`).

| Method | Path | Auth | Request | Response | Status codes |
|---|---|---|---|---|---|
| POST | `/api/v1/sessions/{session}/complete` | `auth:sanctum` (session cookie, `web` guard) + `TrainingSessionPolicy::complete` (via the Form Request) | JSON, both optional: `note` (string, ≤ 1000 chars, nullable), `perceived_effort` (integer, 1–5, nullable). `""` / whitespace-only `note` collapses to `null`. Any other key is ignored. | `{ "data": { "id": string uuid, "status": "completed", "analysis_state": "pending", "note": string\|null, "perceived_effort": int\|null, "started_at": string ISO-8601, "completed_at": string ISO-8601, "created_at": string ISO-8601, "updated_at": string ISO-8601, "cycle_day": {...}\|null } }` | `200` OK · `422` validation (`note` too long, `perceived_effort` out of 1–5 or non-integer) · `422` `SESSION_HAS_NO_SETS` · `409` `SESSION_ALREADY_COMPLETED` · `401` unauthenticated · `403` `AUTHORIZATION_EXCEPTION` (`{session}` owned by another user) · `404` `NOT_FOUND_EXCEPTION` (`{session}` uuid unknown or not a uuid) · `419` stateful request without a valid CSRF token |

Notes:

- **`{session}` binding.** `->whereUuid('session')` — a non-uuid segment never
  matches → `404`. Implicit binding resolves `{session}` to `TrainingSession`
  by `uuid` (`HasPublicUuid::getRouteKeyName()`); an unknown uuid →
  `ModelNotFoundException` → `404` (`NOT_FOUND_EXCEPTION`). The Policy runs
  after binding.
- **Both body fields optional, independently.** Neither is required to close a
  session — an empty JSON body (`{}`) is valid and produces `note: null`,
  `perceived_effort: null`.
- **`note` normalisation.** A whitespace-only string collapses to `null` in
  `prepareForValidation()`, matching `LogSetRequest` / `UpdateSetLogRequest`'s
  handling of their own `note` field.
- **`perceived_effort` is a plain integer 1–5, stored as sent.** No decimal /
  half-step rule (unlike the set-level `rpe`, which is 0–10 in 0.5 steps) — a
  coarser, whole-session "how hard was today" rating. Not calculated from any
  other field (see "Product-owner decisions", §1).
- **Guard order: already-completed before no-sets.** Re-completing a session
  that is `completed` (and therefore necessarily has sets) always returns
  `409 SESSION_ALREADY_COMPLETED`, never `422`. The no-sets check only ever
  fires against an `in_progress` session.
- **`analysis_state` is untouched by this endpoint.** It stays `pending`
  (its value since the session was opened) in both the response body and the
  database — this ticket only enqueues `SessionAnalysisJob`; nothing here
  advances the state machine (see §1, §9).
- **Server-set fields.** `status` → `SessionStatus::Completed`; `completed_at`
  → `now()`. The request body never sets either.
- Errors are rendered as JSON by `App\Exceptions\ApiExceptionRenderer` (wired
  for `api/*` in `bootstrap/app.php`) as
  `{ "data": { "code": "...", "message": "..." } }`, with a `data.errors` map
  only for `VALIDATION_EXCEPTION`. No hand-built JSON.

### 2.2 CLI

Not applicable — no CLI commands.

### 2.3 Events

No domain events. One queued job is dispatched:

| Event name | Producer | Consumer | Payload | Trigger condition |
|---|---|---|---|---|
| `SessionAnalysisJob` dispatch | `App\Actions\Session\SessionCloseAction` | `App\Jobs\Session\SessionAnalysisJob` (queue `database`, per `docs/product-context.md` §"Async"; placeholder `handle()` — empty in this ticket, see §1) | The completed `TrainingSession` (public property `session`) | Every successful `POST /api/v1/sessions/{session}/complete` (i.e. after both guards pass and the session row is updated), inside the same `DB::transaction` closure that performs the update |

---

## 3. UI

### 3.1 Pages

Not applicable — no pages affected. This is a JSON REST API; "Pantalla:
completar sesión" (Order 240) lives in `gym-trainer-spa/`.

### 3.2 Components

Not applicable — no components affected.

---

## 4. Database

### 4.1 Schema changes

| Table | Action | Details |
|---|---|---|
| `training_sessions` | Add column | `note` `text` **nullable** — general free-text note the user leaves when closing the day. |
| `training_sessions` | Add column | `perceived_effort` `unsignedTinyInteger` **nullable** — subjective 1–5 whole-session effort rating, stored as sent (never calculated). |

- New migration file:
  `database/migrations/2026_09_04_120000_add_note_and_perceived_effort_to_training_sessions_table.php`,
  anonymous class `return new class extends Migration`. Timestamp sorts
  **after** `2026_09_03_130000_create_set_logs_table.php`. The existing
  `create_training_sessions_table` migration is **not** edited — it already
  ran against `gym_trainer` and any developer's local DB.
- `Schema::table('training_sessions', function (Blueprint $table) { $table->text('note')->nullable(); $table->unsignedTinyInteger('perceived_effort')->nullable(); });`
  in `up()`; `dropColumn(['note', 'perceived_effort'])` in `down()`. No
  `->after(...)` positional modifier — Postgres silently ignores it (columns
  land at the end of the table regardless), so the migration does not imply an
  ordering guarantee it can't keep across the Postgres runtime and the SQLite
  test DB.
- No `CHECK` constraint for the 1–5 bound — enforced by the Form Request, the
  same tradeoff `set_logs.rpe` / `weight_kg` already accept (portable across
  Postgres and SQLite, per `docs/plans/store-set-logs-spec.md` §4.1).
- No new enum column.
- No soft deletes, no unique index — these are plain nullable attributes with
  no uniqueness or state-machine role.
- **Doc update:** `docs/plans/data-model.md` §`training_sessions` gets two new
  rows in its column table (`note`, `perceived_effort`) and a one-line note
  that both are subjective, client-supplied, and never derived from
  `set_logs`.
- **Database isolation (`CLAUDE.md` → "Workflows — database isolation"):** this
  branch adds a migration, so `gym_trainer` must not be migrated directly.
  Before `migrate` against Postgres:
  `docker compose exec pgsql createdb -U gym -T gym_trainer gym_trainer_complete_sessions`,
  then set `DB_DATABASE=gym_trainer_complete_sessions` in this worktree's
  `.env`. Drop the clone
  (`dropdb -U gym --if-exists gym_trainer_complete_sessions`) and revert `.env`
  on merge. The Pest suite is unaffected — SQLite `:memory:`.
- **Worktree tooling caveat** (not `CLAUDE.md`, a standing note for this
  repository's Claude-workflow setup): the `app` Docker service bind-mounts
  the **main checkout**, not this git worktree, and a worktree starts with no
  `.env` / `vendor/`. `artisan migrate` / `composer` / `pest` here run via a
  throwaway container mounting the worktree path instead of
  `docker compose exec app` — see §10 task 1.

### 4.2 Seeds

Not applicable — no seeds.

---

## 5. Auth & Authorization

### 5.1 Authentication

**Method:** Session (cookie) via **Laravel Sanctum in SPA / stateful mode** —
the same mechanism as every other endpoint. `auth:sanctum` on the route group
authenticates from the session cookie; unauthenticated → `AuthenticationException`
→ `401` JSON. `POST` is a stateful non-GET request → requires a valid
`XSRF-TOKEN` (`419` otherwise), auto-bypassed under `php artisan test`.

### 5.2 Authorization

**`TrainingSessionPolicy`**, already auto-discovered for `App\Models\
TrainingSession`. This ticket adds one ability alongside the existing
`create`:

| Role | Permissions |
|---|---|
| Authenticated user | Complete a **training session they own** — `TrainingSessionPolicy::complete(User $user, TrainingSession $session): bool` returns `$session->user_id === $user->id`. No other actor, no other permission added by this ticket. |

- `CompleteTrainingSessionRequest::authorize()` →
  `$this->user()?->can('complete', $this->route('session')) ?? false`. Route-
  model binding runs before Form Request authorization, so
  `$this->route('session')` is the bound `TrainingSession` (or the request
  404s first). Because the ability is checked directly against the model
  instance (not `[TrainingSession::class, $context]`, which is `create`'s
  shape for a not-yet-existing resource), Laravel's Gate resolves
  `TrainingSessionPolicy` from `$session`'s class and calls
  `complete($user, $session)`. Foreign session → `AuthorizationException` →
  `403`.
- The session's `completed` / no-sets state is a **business rule** (Service
  guards → `409` / `422`), **not** authorization. An owned session in either
  bad state still authorizes; it fails later, at the Service.

---

## 6. Configuration

**Environment variables:**

| Variable | Value / Source | Purpose |
|---|---|---|
| `DB_DATABASE` | `gym_trainer_complete_sessions` (this worktree's `.env` only, during development) | Points the worktree at a clone of `gym_trainer` so the new migration never runs against the shared database. Reverted on merge. |

No new keys in `.env.example`. `phpunit.xml` already carries everything the
tests need (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`,
`QUEUE_CONNECTION=sync`, `SANCTUM_STATEFUL_DOMAINS=localhost`,
`APP_URL=http://localhost`); `RefreshDatabase` is already active for the
`Feature` suite in `tests/Pest.php`.

**Config / non-source files modified:**

| File | Change |
|---|---|
| `routes/api.php` | Add a `use` import for `CompleteTrainingSessionController`; inside the existing `auth:sanctum` group, after the `sessions.sets.update` route, add `POST sessions/{session}/complete` → `CompleteTrainingSessionController` (`sessions.complete`, `->whereUuid('session')`). |
| `docs/plans/data-model.md` | Two new rows in §`training_sessions`'s column table (`note`, `perceived_effort`) + a one-line note that both are subjective/client-supplied. |
| `tests/Feature/Auth/DocsSecurityTest.php` | Add one `->and($spec['paths']['/api/v1/sessions/{session}/complete']['post'])->not->toHaveKey('security')` assertion. |

No change to `bootstrap/app.php`, `config/*`, `bootstrap/providers.php`,
`phpunit.xml`, `composer.json`, `tests/Feature/ArchTest.php` (the existing
"session controllers are invokable" rule already covers
`App\Http\Controllers\Session\CompleteTrainingSessionController`).

---

## 7. Current vs New Behavior

| Behavior | Current | New |
|---|---|---|
| Completing a session | Impossible. `TrainingSession::status` only ever moves to `in_progress` at creation; nothing transitions it to `completed`. | `POST /api/v1/sessions/{session}/complete` moves `status` to `completed`, sets `completed_at`, persists optional `note` / `perceived_effort`, and enqueues `SessionAnalysisJob`. `200` with the updated session. |
| AI analysis trigger | Nothing ever dispatches `SessionAnalysisJob` — the class does not exist. | `SessionCloseAction` dispatches `App\Jobs\Session\SessionAnalysisJob` on every successful close. The job is an empty placeholder in this ticket (Order 130 fills in the agent). |
| `training_sessions` schema | `id`, `uuid`, `user_id`, `routine_id`, `cycle_day_id`, `status`, `analysis_state`, `started_at`, `completed_at`, `conversation_id`, timestamps. | Adds `note` (`text`, nullable) and `perceived_effort` (`unsignedTinyInteger`, nullable). |
| `TrainingSessionResource` payload | `id`, `status`, `analysis_state`, `started_at`, `completed_at`, `created_at`, `updated_at`, `cycle_day`. | Adds `note` and `perceived_effort`. |
| Domain exceptions | `App\Exceptions\Session\{SessionAlreadyCompletedException, DayExerciseNotInSessionException, NonContiguousSetNumberException, CycleDayNotInActiveCycleException, RoutineNotActiveException, SessionInProgressException}` (all `409`). | Adds `SessionHasNoSetsException` — `422` (the first `Session` domain exception that is not `409`). |
| `TrainingSessionPolicy` | `create(User, Routine)`. | Adds `complete(User, TrainingSession)`. |
| Authenticated routes | `auth:sanctum` group holds auth, profile, routine, session-open and set-logging routes. | Adds `POST sessions/{session}/complete`. |
| Set-logging endpoints | Already reject a write once `status === completed` (`SESSION_ALREADY_COMPLETED`, Order 110). | Unchanged — this ticket is the first thing that can actually *put* a session into that state; the existing guard now has a real caller. |

---

## 8. Test Cases

Executable with Pest 4 on SQLite `:memory:` (`RefreshDatabase`, already
wired). Feature tests' `beforeEach` sets
`$this->withHeader('Origin', config('app.url'))`, then
`$this->user = User::factory()->create()`. Reuses `openFreeSession(User)` /
`openPlannedSession(User)` from `tests/Helpers.php` (shipped in PR #19). No AI
call in this ticket — `SessionAnalysisJob` is asserted queued via
`Bus::fake()`, never executed.

### POST `/api/v1/sessions/{session}/complete` — `tests/Feature/Session/CompleteTrainingSessionTest.php`

**TC-1:** Completes a session with one set and no body — happy path (AC1: both fields optional; AC2: job enqueued)
- **Given:** `Bus::fake([SessionAnalysisJob::class])`; an authenticated user; `$session = openFreeSession($user)`; `SetLog::factory()->for($session, 'session')->for(Exercise::factory())->create()`
- **When:** `POST /api/v1/sessions/{$session->uuid}/complete` with `{}`
- **Expect:** `200`; `data.status === 'completed'`; `data.analysis_state === 'pending'`; `data.note === null`; `data.perceived_effort === null`; `data.completed_at` matches `iso8601Pattern()`; `assertDatabaseHas('training_sessions', ['id' => $session->id, 'status' => 'completed'])`; `Bus::assertDispatched(SessionAnalysisJob::class, fn ($job) => $job->session->is($session))`

**TC-2:** Completes with `note` and `perceived_effort` — both persisted and serialised (AC1)
- **Given:** `Bus::fake([SessionAnalysisJob::class])`; an authenticated user; `$session = openFreeSession($user)` with one set logged
- **When:** `POST .../complete` with `{ "note": "felt strong today", "perceived_effort": 4 }`
- **Expect:** `200`; `data.note === "felt strong today"`; `data.perceived_effort === 4`; `assertDatabaseHas('training_sessions', ['id' => $session->id, 'note' => 'felt strong today', 'perceived_effort' => 4])`

**TC-3:** A whitespace-only `note` collapses to `null`
- **Given:** an authenticated user; a session with one set
- **When:** `POST .../complete` with `{ "note": "   " }`
- **Expect:** `200`; `data.note === null`

**TC-4:** `perceived_effort` out of range or non-integer → `422` (dataset)
- **Given:** an authenticated user; a session with one set
- **When:** `POST .../complete` with `perceived_effort` = `0`, `6`, `2.5`
- **Expect:** each `422`; `assertJsonValidationErrors('perceived_effort', 'data.errors')`; `assertDatabaseHas('training_sessions', ['id' => $session->id, 'status' => 'in_progress'])`

**TC-5:** `note` longer than 1000 chars → `422`
- **Given:** an authenticated user; a session with one set
- **When:** `POST .../complete` with `note` = `str_repeat('x', 1001)`
- **Expect:** `422`; `assertJsonValidationErrors('note', 'data.errors')`; session stays `in_progress`

**TC-6:** A session with zero sets cannot be completed — `422 SESSION_HAS_NO_SETS` (AC4), job not queued
- **Given:** `Bus::fake([SessionAnalysisJob::class])`; an authenticated user; `$session = openFreeSession($user)` (no sets logged)
- **When:** `POST .../complete` with `{}`
- **Expect:** `422`; `assertJsonPath('data.code', 'SESSION_HAS_NO_SETS')`; `assertJsonMissingPath('data.errors')` (a domain error, not a validation one); `assertDatabaseHas('training_sessions', ['id' => $session->id, 'status' => 'in_progress'])`; `Bus::assertNotDispatched(SessionAnalysisJob::class)`

**TC-7:** An already-`completed` session → `409 SESSION_ALREADY_COMPLETED`, job not re-queued (AC3, re-complete decision)
- **Given:** `Bus::fake([SessionAnalysisJob::class])`; an authenticated user; `$session = TrainingSession::factory()->for($user)->for(Routine::factory()->for($user))->completed()->create()`; one set logged against it beforehand
- **When:** `POST .../complete` with `{}`
- **Expect:** `409`; `assertJsonPath('data.code', 'SESSION_ALREADY_COMPLETED')`; `Bus::assertNotDispatched(SessionAnalysisJob::class)`

**TC-8:** A session with zero sets that is also already completed → `409`, not `422` (guard order)
- **Given:** an authenticated user; `$session = TrainingSession::factory()->for($user)->for(Routine::factory()->for($user))->completed()->create()` with **no** sets logged
- **When:** `POST .../complete` with `{}`
- **Expect:** `409`; `assertJsonPath('data.code', 'SESSION_ALREADY_COMPLETED')` (never `SESSION_HAS_NO_SETS` — completed-first ordering)

**TC-9:** The analysis-failing-later invariant: completion never depends on `SessionAnalysisJob` succeeding (AC3)
- **Given:** an authenticated user; a session with one set; the real (non-faked) sync queue driver (`QUEUE_CONNECTION=sync` per `phpunit.xml`) — `SessionAnalysisJob::handle()` is an empty no-op, so it cannot throw
- **When:** `POST .../complete` with `{}`
- **Expect:** `200`; `data.status === 'completed'`; the request completes successfully even though the job actually ran synchronously in-process

**TC-10:** `{session}` belongs to another user → `403`, session unchanged
- **Given:** `$other = User::factory()->create()`; `$otherSession = openFreeSession($other)` with one set; `actingAs($this->user)`
- **When:** `POST /api/v1/sessions/{$otherSession->uuid}/complete` with `{}`
- **Expect:** `403`; `assertJsonPath('data.code', 'AUTHORIZATION_EXCEPTION')`; `assertDatabaseHas('training_sessions', ['id' => $otherSession->id, 'status' => 'in_progress'])`

**TC-11:** Unknown / non-uuid `{session}` → `404` (dataset)
- **Given:** an authenticated user
- **When:** `POST /api/v1/sessions/{(string) Str::uuid()}/complete` and `POST /api/v1/sessions/42/complete`
- **Expect:** each `404`; for the uuid case `assertJsonPath('data.code', 'NOT_FOUND_EXCEPTION')`

**TC-12:** Unauthenticated → `401`
- **Given:** no `actingAs`; a session with one set owned by another factory user
- **When:** `POST .../complete` with `{}`
- **Expect:** `401`; `assertJsonPath('data.code', 'AUTHENTICATION_EXCEPTION')`; session unchanged

**TC-13:** Response shape and type serialisation
- **Given:** an authenticated user; a planned session (`openPlannedSession`) with one set logged
- **When:** `POST .../complete` with `{ "note": "solid", "perceived_effort": 3 }`
- **Expect:** `200`; `assertJsonStructure(['data' => ['id', 'status', 'analysis_state', 'note', 'perceived_effort', 'started_at', 'completed_at', 'created_at', 'updated_at', 'cycle_day']])`; `data.id === $session->uuid`; `data.perceived_effort` is an `int` (`3`, not `"3"`); `data.completed_at` matches `iso8601Pattern()`

### Unit — `tests/Unit/Session/SessionCompletionServiceTest.php`

**TC-14:** `guard()` throws `SessionAlreadyCompletedException` for a completed session, before checking sets
- **Given:** a `TrainingSession` built in-memory (no DB) with `status = SessionStatus::Completed`
- **When:** `app(SessionCompletionService::class)->guard($session)`
- **Expect:** throws `SessionAlreadyCompletedException`

**TC-15:** `guard()` throws `SessionHasNoSetsException` for an `in_progress` session with zero sets
- **Given:** `$session = openFreeSession(User::factory()->create())` (persisted, no sets)
- **When:** `app(SessionCompletionService::class)->guard($session)`
- **Expect:** throws `SessionHasNoSetsException`

**TC-16:** `guard()` does not throw for an `in_progress` session with at least one set
- **Given:** `$session = openFreeSession($user)`; one `SetLog` logged against it
- **When:** `app(SessionCompletionService::class)->guard($session)`
- **Expect:** no exception thrown

---

## 9. Technical Decisions

| Decision area | What was decided | Why |
|---|---|---|
| `perceived_effort` semantics | A **subjective, client-supplied** integer 1–5, stored as sent — never derived from `weight_kg` / `reps` / any e1RM estimate. | Product-owner decision (this session): recovering "how close to failure" from load and reps alone needs a known 1RM, which the app doesn't have and won't fabricate; and a deterministic load→effort formula would contradict `docs/product-context.md` §5 ("100% IA. No hay reglas deterministas"). |
| `perceived_effort` scale | Plain integer 1–5 (not the 0–10-half-step scale `set_logs.rpe` uses). | Product-owner decision (this session): a coarser "how hard did today feel overall" rating is a distinct measurement from per-set RPE; no half-steps, no 0/10 endpoints. |
| AI-analysis scope | This ticket only **dispatches** `SessionAnalysisJob` as an empty placeholder (mirrors the existing `App\Jobs\Cycle\GenerateCycleJob` seam). The agent, the recommendations, and the `analysis_state` lifecycle belong to the separate "Recibir recomendaciones al cerrar el día" ticket (Order 130). | Product-owner decision (this session): Order 130 is un-specced and large enough (new table, three new enums, an agent, a retryable state machine) to warrant its own spec and PR. Folding it in here would make this ticket's scope drift far past its four ACs. `analysis_state` stays `pending` throughout this ticket's life — no lifecycle claim is made that a later ticket must un-make. |
| No-sets guard status code | `SessionHasNoSetsException` sets `protected int $statusCode = Response::HTTP_UNPROCESSABLE_ENTITY` (422), overriding the `DomainException` base's `409` default. | The ticket AC is explicit: *"Una sesión sin ninguna serie registrada no puede completarse (responde 422)."* `CLAUDE.md`'s `DomainException` base explicitly supports this override ("set `protected int $statusCode` when 409 is wrong"). It is still a business rule (needs to count `set_logs` rows), so it stays a `DomainException` thrown from a Service guard — not a Form Request rule (`CLAUDE.md`: "Can this user have a second active routine?"-style checks belong in a Service, not validation). |
| Already-completed guard | Reuses the **existing** `SessionAlreadyCompletedException` (`409 SESSION_ALREADY_COMPLETED`, shipped in PR #19) instead of a new exception or an idempotent `200`. | Product-owner decision (this session): the same conflict the set-logging endpoints already report for a completed session; introducing a second exception or a silent no-op for the identical state would be needless indirection (`CLAUDE.md` rule 6). |
| Guard ordering | `SessionCompletionService::guard()` checks already-completed **before** no-sets. | A completed session always has at least one set (nothing else can complete it), so the ordering has no normal-flow ambiguity — but it fixes the contract for a session manually forced into a bad state (TC-8): always `409`, never `422`, once `completed`. |
| Guard placement | One small `SessionCompletionService` (not inline private methods on the Action, unlike `SetLogCreateAction`'s precedent). | Two guards, each needing its own `throw_if`/`throw_unless` against session state, plus a clear seam for whatever Order 130 needs to check before re-running analysis later — a class name it can grow into is warranted here, unlike `store-set-logs-spec.md`'s single-guard-per-Action case (`CLAUDE.md` rule 6: this class earns its place because it isolates the two rules the AC calls out by name, and only those). |
| Job dispatch point | `SessionAnalysisJob::dispatch($session)` is the **last line** inside `SessionCloseAction`'s `DB::transaction` closure, after the `update()`. | `CLAUDE.md`: "the only layer that opens transactions, dispatches jobs". `config/queue.php` sets `after_commit => false` on every connection, so the dispatch is **not** deferred to commit time — it queues immediately, before the transaction closure returns. Placing it last (after the `update()`) still guarantees the row is updated in-memory and about to be committed before the job is queued; its `handle()` is a no-op regardless, so this ticket carries no ordering risk either way. A later ticket (Order 130, once the job does real work) is the one that must actually care whether the row is committed by the time the job runs. |
| `SessionAnalysisJob` shape | `App\Jobs\Session\SessionAnalysisJob implements ShouldQueue`, `use Queueable`, `public function __construct(public TrainingSession $session)`, empty `handle()`. | Mirrors the existing `App\Jobs\Cycle\GenerateCycleJob` placeholder exactly — same pattern already accepted in this codebase for "the seam exists, the body doesn't yet". Constructor takes the whole `TrainingSession` (not just its id) so Order 130 can reach `sets`, `cycleDay.dayExercises`, and `routine` off it without a second query. |
| `TrainingSessionPolicy::complete` | `complete(User $user, TrainingSession $session): bool => $session->user_id === $user->id`. Checked via `can('complete', $this->route('session'))` — the instance form, not `create`'s `[Model::class, $context]` form. | The session already exists at completion time (unlike `create`, which authorizes against a not-yet-created resource under a `Routine`). Matches `RoutinePolicy::view`'s instance-based `can('view', 'routine')` shape used in `routes/api.php`. |
| New columns' nullability & type | `note` `text` nullable; `perceived_effort` `unsignedTinyInteger` nullable. No `CHECK` constraint. | Both are optional per the AC. `text` for an unbounded-in-schema note (the 1000-char cap is a Form Request rule, matching `set_logs.note`'s own `text` + Form-Request-capped precedent). `unsignedTinyInteger` comfortably holds 1–5; the 1–5 bound itself is enforced by the Form Request, the same tradeoff `set_logs.rpe` / `weight_kg` already accept for portability across Postgres and SQLite. |
| `perceived_effort` cast | `TrainingSession::casts()` gains `'perceived_effort' => 'integer'`. `note` needs no cast (a `text` column already reads back as a PHP string). | PDO_PGSQL returns numeric columns as strings unless Eloquent casts them — the exact reason `SetLog` already casts `set_number` / `reps` as `'integer'` despite both being plain integer columns. Without this cast `perceived_effort` would read back as `"3"` on the Postgres runtime, breaking §2.1's documented `int\|null` contract and failing a strict `assertJsonPath` comparison — a defect that would pass unnoticed under the SQLite test DB (which tends to return native PHP types already) and only surface on Postgres. |
| Eager loading | `SessionCloseAction::handle()` returns `$session->load('cycleDay')` (not a bare `$session`). | `TrainingSessionResource`'s `cycle_day` field is `CycleDayResource::make($this->whenLoaded('cycleDay'))`; on an unloaded relation Laravel drops the key entirely rather than emitting `null`, which would silently violate §2.1's always-present `"cycle_day": {...}\|null` contract for every caller (not just a test artifact). `CompleteTrainingSessionController` resolves `$session` through plain route-model binding, which does not eager-load any relation, so the Action is the only place left to do it — mirrors `TrainingSessionCreateAction`'s own `return $session->load('cycleDay.dayExercises.exercise');`. `Model::shouldBeStrict()` does not catch this: `whenLoaded()` is deliberately lazy-load-safe, so the omission fails silently instead of throwing. |
| Separate migration, not editing the original | A new `add_note_and_perceived_effort_to_training_sessions_table` migration; `create_training_sessions_table` is untouched. | The original migration already ran against `gym_trainer` and any developer's local DB (PR #18, merged). Editing a migration that already ran in a shared environment is unsafe; adding a column via a new migration is the standard Laravel pattern. |
| Response shape | `TrainingSessionResource` gains `note` and `perceived_effort` as plain scalar fields, read straight off the model (no Resource-level cast) — correctness relies on the model's own `'perceived_effort' => 'integer'` cast (see the "perceived_effort cast" row above), not on anything in the Resource. | `CLAUDE.md` rule 3: always a real Resource. Both are flat attributes with no relation, so no `whenLoaded()` / nested resource is warranted; unlike `SetLogResource`'s `decimal:*` columns, neither new column needs a Resource-level `(float)`/`(int)` cast once the model cast is in place. |
| Request DTO | `App\Data\Session\CompleteSessionData` (`?string $note = null`, `?int $perceived_effort = null`), `final … extends Data`, built with `::from($request->validated())`. | `CLAUDE.md` convention (writes take a `Data` object, not `$request->all()`/`validated()` directly in the Action). Matches `LogSetData` / `UpdateSetLogData`'s shape (nullable optional fields with defaults). |
| `note` whitespace normalisation | `prepareForValidation()` collapses a whitespace-only `note` to `null`. | Mirrors `LogSetRequest` / `UpdateSetLogRequest`'s identical handling of their own `note` field — same field, same rule, same place. |
| Authorization vs business state | Policy checks ownership only; "already completed" and "no sets" are `DomainException`s thrown from the Service, not Policy failures. | `CLAUDE.md` / the `SetLogPolicy` precedent: an owned-but-wrong-state resource is a `409`/`422` business conflict, never a `403`. |
| Mass assignment | `TrainingSession` `#[Fillable]` gains `note`, `perceived_effort` alongside the existing six fields. `SessionCloseAction` writes through `$session->update([...])` with all four server-relevant keys (`status`, `completed_at`, `note`, `perceived_effort`) in one call. | `Model::preventSilentlyDiscardingAttributes()` (strict mode) throws on a non-fillable `update()` key. Matches the existing `#[Fillable(['user_id', 'cycle_day_id', 'status', 'analysis_state', 'started_at', 'completed_at'])]` pattern — just extended. |
| Test job assertion | `Bus::fake([SessionAnalysisJob::class])` + `Bus::assertDispatched(SessionAnalysisJob::class, fn ($job) => $job->session->is($session))` for the happy-path / no-op tests; the sync-driver test (TC-9) runs the job for real to prove its empty `handle()` cannot break completion. | First job dispatch in the codebase with an actual caller (`GenerateCycleJob` has none yet) — this ticket sets the `Bus::fake` precedent for job-dispatch assertions. |
| Rate limiting | None. | Authenticated, low-abuse; matches every other data route. |
| Scramble `security_strategy` | No `config/scramble.php` change — `MiddlewareAuthSecurityStrategy` on `main` already covers any `auth:sanctum` route. One `DocsSecurityTest` assertion added. | The new route matches that middleware, so it is documented as secured automatically. |
| Tests: DB & no AI | SQLite `:memory:` + `RefreshDatabase` (already wired). No AI agent is invoked anywhere in this ticket. | Faster, deterministic; Order 130 owns all AI-fake coverage. |
| Git artifacts | English only. **No AI attribution anywhere** — no `Co-Authored-By: Claude` / `Claude-Session:` commit trailers, no `🤖 Generated with Claude Code` (or any "generated by" / tool-credit) line in a commit message, PR title, PR description or review comment. | Repo `CLAUDE.md` / `AGENTS.md` "Git" rule; it takes precedence over any session-level attribution instruction. |

---

## 10. Work Plan

Pipeline classes are created before wiring `routes/api.php`. Each task's DoD is
the artifact existing, passing Pint + PHPStan level 6, and — where the class
carries logic — its focused test authored in the same task. Task 12 (the
endpoint feature test) is the functional gate.

Per the worktree-tooling caveat (§4.1): the `app` Docker service bind-mounts
the **main checkout**, not this worktree. Run `artisan` / `composer` / `pint`
/ `phpstan` here via a throwaway container mounting this worktree's path
(`docker run --rm --network gym-trainer-api_gym -v <abs-worktree-path>:/var/www/html -w /var/www/html gym-trainer-api/php:8.5 <cmd>`)
after copying `.env` and `vendor/` in from the main checkout. `pest` needs
none of this (SQLite `:memory:`, no network).

| # | Task | Definition of Done |
|---|---|---|
| 1 | Prepare the worktree: `cp <main>/.env ./.env`, `cp -r <main>/vendor ./vendor`; clone the runtime DB: `docker compose exec pgsql createdb -U gym -T gym_trainer gym_trainer_complete_sessions`; set `DB_DATABASE=gym_trainer_complete_sessions` in this worktree's `.env` | The worktree has its own `.env` / `vendor/`; `artisan` runs via the throwaway container; `gym_trainer` untouched; the Pest suite still uses SQLite. |
| 2 | Create `database/migrations/2026_09_04_120000_add_note_and_perceived_effort_to_training_sessions_table.php` per §4.1 (`Schema::table`, add `note` `text` nullable + `perceived_effort` `unsignedTinyInteger` nullable; `down()` drops both) | `php artisan migrate` runs on the clone and on a fresh SQLite; `php artisan db:table training_sessions` shows both new nullable columns. |
| 3 | Update `app/Models/TrainingSession.php`: add `note`, `perceived_effort` to `#[Fillable]`; add `'perceived_effort' => 'integer'` to `casts()` (`note` needs no cast); add `@property string|null $note` / `@property int|null $perceived_effort` PHPDoc | Pint + PHPStan clean; `(new TrainingSession)->getFillable()` includes both; `(new TrainingSession)->getCasts()['perceived_effort'] === 'integer'`. |
| 4 | Run `php artisan ide-helper:models --write` for `TrainingSession`; `vendor/bin/pint app/Models`; hand-check the two new `@property` lines and the refreshed `where*` `@method` lines | PHPDoc block lists every column including the two new ones; diff limited to `TrainingSession`. |
| 5 | Update `database/factories/TrainingSessionFactory.php`: add `'note' => null, 'perceived_effort' => null` to `definition()` | `TrainingSession::factory()->create()` persists a row with both columns `null`; existing `completed()` / `planned()` states untouched; Pint + PHPStan clean. |
| 6 | Create `app/Exceptions/Session/SessionHasNoSetsException.php` — `final extends DomainException`; `protected string $errorCode = 'SESSION_HAS_NO_SETS'`; `protected int $statusCode = Response::HTTP_UNPROCESSABLE_ENTITY`; fixed default message | `(new SessionHasNoSetsException)->errorCode() === 'SESSION_HAS_NO_SETS'`; `->statusCode() === 422`; Pint + PHPStan clean. |
| 7 | Create `app/Services/Session/SessionCompletionService.php` (`final`): `guard(TrainingSession $session): void` — `throw_if($session->status === SessionStatus::Completed, new SessionAlreadyCompletedException)`; `throw_if($session->sets()->doesntExist(), new SessionHasNoSetsException)`. Write `tests/Unit/Session/SessionCompletionServiceTest.php` (TC-14…TC-16) | `vendor/bin/pest tests/Unit/Session/SessionCompletionServiceTest.php` green; Pint + PHPStan clean. |
| 8 | Create `app/Jobs/Session/SessionAnalysisJob.php` per §9 (`implements ShouldQueue`, `use Queueable`, `public function __construct(public TrainingSession $session)`, empty `handle()`, doc-comment pointing to Order 130) | Pint + PHPStan clean; `SessionAnalysisJob::dispatch($session)` queues without error under `QUEUE_CONNECTION=sync`. |
| 9 | Create `app/Data/Session/CompleteSessionData.php` (`make:data`, move to `app/Data/Session/`, fix namespace): `?string $note = null`, `?int $perceived_effort = null` | `CompleteSessionData::from(['note' => 'x', 'perceived_effort' => 3])->perceived_effort === 3`; `CompleteSessionData::from([])->note === null`; Pint + PHPStan clean. |
| 10 | Create `app/Actions/Session/SessionCloseAction.php` (`final`, constructor-injects `SessionCompletionService`): `handle(TrainingSession $session, CompleteSessionData $data): TrainingSession` — `DB::transaction` closure: `$this->completion->guard($session)`; `$session->update(['status' => SessionStatus::Completed, 'completed_at' => now(), 'note' => $data->note, 'perceived_effort' => $data->perceived_effort])`; `SessionAnalysisJob::dispatch($session)`; return `$session->load('cycleDay')` | `final` + `handle()`; the returned session has `relationLoaded('cycleDay') === true`; covered by the feature tests in task 12 (TC-13's `cycle_day` structure assertion in particular); Pint + PHPStan clean. |
| 11 | Add `complete(User $user, TrainingSession $session): bool => $session->user_id === $user->id` to `app/Policies/TrainingSessionPolicy.php` (import `TrainingSession`); create `app/Http/Requests/Session/CompleteTrainingSessionRequest.php` (`authorize()` → `$this->user()?->can('complete', $this->route('session')) ?? false`; `rules()` → `note` `['nullable','string','max:1000']`, `perceived_effort` `['nullable','integer','min:1','max:5']`; `prepareForValidation()` nulls a whitespace-only `note`); create `app/Http/Controllers/Session/CompleteTrainingSessionController.php` (`make:controller --invokable`, move + fix namespace): `__invoke(CompleteTrainingSessionRequest $request, TrainingSession $session, SessionCloseAction $action): TrainingSessionResource` → `TrainingSessionResource::make($action->handle($session, CompleteSessionData::from($request->validated())))`; add `note`, `perceived_effort` to `app/Http/Resources/Session/TrainingSessionResource.php` | Each `final` where applicable, `__invoke` only for the controller; Pint + PHPStan clean. |
| 12 | Edit `routes/api.php`: add the `use` import for `CompleteTrainingSessionController`; inside the `auth:sanctum` group, after `sessions.sets.update`, add `Route::post('sessions/{session}/complete', CompleteTrainingSessionController::class)->whereUuid('session')->name('sessions.complete')`. Write `tests/Feature/Session/CompleteTrainingSessionTest.php` (TC-1…TC-13) | `php artisan route:list` shows the new route under `auth:sanctum`; `vendor/bin/pest tests/Feature/Session/CompleteTrainingSessionTest.php` all green; every TC has a test. |
| 13 | Add the one `not->toHaveKey('security')` assertion for `/api/v1/sessions/{session}/complete` (post) to `tests/Feature/Auth/DocsSecurityTest.php` | `vendor/bin/pest tests/Feature/Auth/DocsSecurityTest.php` green. |
| 14 | Update `docs/plans/data-model.md` §`training_sessions`: add `note` and `perceived_effort` rows to the column table + a one-line note that both are subjective/client-supplied and never derived from `set_logs` | The two rows are present; the section still reads coherently; no other `data-model.md` change. |
| 15 | `vendor/bin/pint --dirty`, then `vendor/bin/phpstan analyse`, then a final `php artisan ide-helper:models --write` + `vendor/bin/pint app/Models` | Pint reports no diffs; PHPStan level 6 clean; `TrainingSession` PHPDoc in sync with the migration. |
| 16 | `composer check` (Pint `--test` + PHPStan level 6 + full Pest — the new Session tests, the Policy addition, the Service unit tests, the `DocsSecurityTest` addition) | All three steps green; no regression in Auth / Profile / Routine / Cycle / Session suites. |
| 17 | Manual check with `curl` against the worktree app pointed at the clone: register + login → `POST /api/v1/routines` → `POST /api/v1/routines/{uuid}/sessions` (`{}`, free session) → `POST /api/v1/sessions/{uuid}/sets` (one set) → `POST /api/v1/sessions/{uuid}/complete` with `{ "note": "...", "perceived_effort": 4 }` (`200`) → again (`409 SESSION_ALREADY_COMPLETED`) → a fresh session with no sets → `.../complete` (`422 SESSION_HAS_NO_SETS`). Review `GET /docs/api` | The `curl` calls return the expected codes; `/api/v1/sessions/{session}/complete` appears in Scramble with its request from `CompleteTrainingSessionRequest` and response from `TrainingSessionResource`, marked secured. |
| 18 | On merge / branch drop: `docker compose exec pgsql dropdb -U gym --if-exists gym_trainer_complete_sessions`; revert `DB_DATABASE` in the worktree `.env` | The clone is gone; `.env` restored to `gym_trainer`. |

*Process note: branch name, commit messages and PR text follow `CLAUDE.md` /
`AGENTS.md` — English only, and no AI attribution anywhere (no
`Co-Authored-By: Claude` / `Claude-Session:` trailers, no `🤖 Generated with
Claude Code` / "generated by" line in any commit, PR title, PR description or
comment).*
