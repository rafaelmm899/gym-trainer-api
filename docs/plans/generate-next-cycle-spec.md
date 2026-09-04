# Generate the next cycle on demand (cycle N+1)

> Derived from the Notion ticket "Generar el ciclo siguiente bajo demanda"
> (Feature: Ciclos & generación IA · MVP · Must · Repo: API · Order 150). Base
> contract: `docs/product-context.md` §2 / §4 (step 7) / §5 / §6 / §7,
> `docs/plans/data-model.md`, `docs/plans/generate-first-cycle-spec.md` (the
> synchronous **first**-cycle path this ticket extends into an asynchronous
> **N+1** path), `docs/plans/session-analysis-spec.md` (the queued-job +
> AI-service pattern this ticket follows), `docs/plans/routine-recommendations-endpoint-spec.md`
> (the endpoint this ticket changes), `docs/plans/domain-exception-handling-spec.md`,
> and `CLAUDE.md` "The pipeline" / "Jobs & AI".

## 1. Context

**Kind:** Brownfield Feature

**Stack:** PHP 8.5 · Laravel 13 · PostgreSQL 17 (runtime) / SQLite `:memory:`
(tests) · Pest 4 · `laravel/ai` (structured-output agents, same
`CyclePlannerAgent` this ticket extends) · `spatie/laravel-data` (DTOs) ·
`laravel/sanctum` 4 (SPA cookie mode) · Pint · Larastan level 6. `database`
queue driver in dev (`sync` under Pest). Everything runs in Docker.

**Problem statement:** A routine's first cycle is generated synchronously
inside `POST /api/v1/routines` (shipped). There is no way to generate the
*next* week — the user is stuck on cycle 1 forever. This ticket adds
`POST /api/v1/routines/{routine}/cycles`: it creates a `generating` cycle
row for the routine's active routine and queues `GenerateCycleJob`, which
feeds the planner AI the athlete profile, the routine's `goal`/`hint`, the
routine's currently-`active` exercise recommendations, and a PHP-computed
progression summary per exercise, then performs an atomic **rollover**: the
new cycle becomes `active`, the outgoing cycle becomes `completed` or
`incomplete` depending on whether all 5 of its days were trained, and the
recommendations for exercises actually trained in the outgoing cycle become
`applied` (recommendations for untrained exercises stay `active` and are
reused verbatim by the new plan). This closes the product's central loop
(`docs/product-context.md` §4, step 7 → back to step 4).

**In scope:**
- `POST /api/v1/routines/{routine}/cycles` — creates the `generating` cycle
  row synchronously (fast, no AI call in-request) and dispatches
  `GenerateCycleJob`. Returns `202` with the new cycle (empty `days`,
  `status: "generating"`).
- Guards, as **Service** business rules (not Form Request validation): the
  routine must be the caller's `active` routine (`RoutineNotActiveException`,
  `409`); the routine must not already have a cycle in `generating`
  (`CycleGenerationInProgressException`, `409`, referencing the existing
  cycle's id in the message).
- Rate limiting: `throttle:1,1` on the route — the first authenticated,
  per-user rate limit in this API.
- `App\Jobs\Cycle\GenerateCycleJob` — implemented (currently an empty stub).
  Lifecycle `generating` → `active` (success, via the rollover transaction) /
  `failed` (job's `failed()`, after retries are exhausted — same pattern as
  `SessionAnalysisJob`).
- `App\Services\Cycle\ProgressionSummaryService` — pure PHP, no I/O beyond
  Eloquent reads, no AI. For every exercise prescribed in the **outgoing**
  cycle: prescribed vs. actual (avg weight/reps, max RPE) from sets logged in
  that cycle, a `performed` flag, and a weight trend + plateau signal derived
  from the exercise's last two *completed* sessions across the whole routine.
- `App\Services\Cycle\CyclePlannerService::planNextCycle(...)` — a second
  entry point (same DTOs, same agent) that additionally feeds the active
  recommendations and the progression summary into the prompt.
- `App\Services\Cycle\CycleDraftService::persistDays(Cycle, CyclePlanData)` —
  extracted from the existing `persist()` so the rollover can write days onto
  an **already-existing** `generating` cycle row instead of creating a new one.
- `App\Actions\Cycle\CycleGenerateAction` (sync, controller-invoked — creates
  the `generating` row, dispatches the job) and
  `App\Actions\Cycle\CycleRolloverAction` (job-invoked — plans, then performs
  the atomic rollover transaction).
- `App\Services\Cycle\CycleGenerationGuardService`, `App\Services\Cycle\CycleCompletionService`.
- `exercise_recommendations.status` — new column (`active` default /
  `applied`), backed by `App\Enums\Recommendation\RecommendationStatus`. Every
  recommendation write (new or re-analyzed) sets/resets it to `active`; the
  rollover sets it to `applied` for exercises trained in the outgoing cycle.
- `App\Services\Recommendation\RecommendationCatalogService::listCurrentForRoutine()`
  (shipped, PR #23) — now also filters `status = active`, so an `applied`
  recommendation stops appearing on `GET /api/v1/routines/{routine}/recommendations`.
- Two new `App\Exceptions\Cycle\*` `DomainException` subclasses (§9);
  `CycleGenerationException` (shipped) is reused as-is for a planner failure
  on the N+1 path.
- A fix to two existing call sites that assumed `Routine::cycle` is always the
  `active` cycle, which stops being true once a `generating`/`failed` N+1
  cycle exists: `TrainingSessionOpeningService::guard()` and
  `RecommendationCatalogService::listCurrentForRoutine()`.
- `Routine::cycle()` redefined to mean **the active cycle** (not "highest
  `sequence_number`") + a new `Routine::pendingCycle()` (the latest
  `generating`/`failed` cycle, if any) so `GET /api/v1/routines/{routine}`
  keeps serving the trainable cycle's days *and* exposes generation status for
  polling, without one shadowing the other. `RoutineResource` gains an
  optional `pending_cycle` key. *(Refined during spec drafting — see §9
  "`Routine::cycle()` semantics".)*
- Test coverage for every acceptance criterion + the two regression fixes.

**Out of scope:**
- Rate-of-generation validation ("Validar el ritmo de generación de ciclos",
  Order 410, `Later`) — the N+1 can be requested at any time; an incomplete
  outgoing week is a valid, expected outcome (`incomplete`), not blocked.
- Any endpoint or screen in `gym-trainer-spa/` (separate repository) —
  including the "Generar ciclo con estado en vivo" (Order 210) and "Pantalla:
  crear rutina" (Order 200) screens, whose polling this ticket's `GET
  /api/v1/routines/{routine}` change supports but does not implement UI for.
- A dedicated cycle-history / cycle-detail-by-id endpoint (`GET
  /api/v1/cycles/{cycle}`, "Ver el detalle de un ciclo", not yet scheduled).
  Polling uses the existing `GET /api/v1/routines/{routine}`.
- A `hint` field on `POST /api/v1/routines/{routine}/cycles` — confirmed with
  the product owner: the endpoint always reuses `routine.hint` (the hint saved
  when the routine was created); it accepts no request body.
- A `confidence` field anywhere (recommendations or cycle prescriptions) —
  product decision already made in PR #21. "Low confidence" for an
  unperformed exercise is prompt guidance to the planner, not a persisted or
  returned field.
- Any DB-level guard (partial unique index, `SELECT … FOR UPDATE`) against a
  genuine race between two concurrent `POST` calls for the same routine — see
  §9 "Race safety net".
- Retrying a `failed` cycle in place. Retry = a fresh `POST` (a new
  `generating` cycle, next `sequence_number`); the `failed` row is left as
  history, matching how a `failed` `TrainingSession` analysis is retried today
  (a fresh session close, not a resurrection of the failed one).
- Any change to how the **first** cycle is generated (still fully synchronous,
  still `generate-first-cycle-spec.md`).

---

## 2. API Surface

### 2.1 REST

| Method | Path | Auth | Request | Response | Status codes |
|---|---|---|---|---|---|
| POST | `/api/v1/routines/{routine}/cycles` | `auth:sanctum` + `RoutinePolicy::generateCycle` + `throttle:1,1` | — (no body) | `{ "data": { "id": uuid, "sequence_number": int, "status": "generating", "split_rationale": null, "generated_at": null, "days": [] } }` | `202` accepted · `409` `ROUTINE_NOT_ACTIVE` (the routine is not the caller's active routine) · `409` `CYCLE_GENERATION_IN_PROGRESS` (a cycle is already `generating` for this routine) · `403` not the routine's owner · `404` unknown `{routine}` uuid · `429` `RATE_LIMIT_EXCEPTION` (more than 1 request/minute) · `401` unauthenticated · `419` stateful request without a valid CSRF token |
| GET | `/api/v1/routines/{routine}` *(existing route, response shape changes)* | unchanged | — | `{ "data": { …routine fields…, "cycle": <CycleResource\|omitted>, "pending_cycle": <CycleResource\|null> } }` | unchanged (`200` / `403` / `404` / `401`) |
| GET | `/api/v1/routines/{routine}/recommendations` *(existing route, filter changes)* | unchanged | — | unchanged shape; the list now excludes `applied` recommendations | unchanged |

Notes:
- **No request body.** The controller takes only the route-bound `Routine`;
  there is no Form Request (mirrors `ShowRoutineController` /
  `ListRoutineRecommendationsController` — both are also body-less,
  `->can(...)` route-gated reads/writes with no Form Request in this
  codebase). `routine.hint` (already stored) is what the planner uses.
- **Fast synchronous response.** `CycleGenerateAction` does **not** call the
  AI. It only runs the two guards, inserts the `cycles` row (`generating`,
  `sequence_number` = active cycle's `sequence_number + 1`, everything else
  `null`), dispatches `GenerateCycleJob`, and returns. The `202` is returned
  in milliseconds, unlike the first cycle's `201` (which waits ~30-60s for the
  agent in-request).
- **`409 ROUTINE_NOT_ACTIVE`.** Thrown by `CycleGenerationGuardService` when
  `$routine->status !== RoutineStatus::Active` — an archived routine (or, in
  practice, any routine that is not the caller's single active one, since
  ownership is already checked by the Policy) can never generate a cycle.
- **`409 CYCLE_GENERATION_IN_PROGRESS`.** Thrown when the routine already has
  a `cycles` row with `status = generating`. The message names that cycle's
  `uuid` (`"A cycle is already being generated for this routine (id
  {uuid})."`) — the envelope has no room for a structured reference field
  (`data.errors` is validation-only, `domain-exception-handling-spec.md`), so
  "referencing the existing draft" (AC wording) means naming it in the
  message text. A `failed` cycle does **not** block a new attempt — only
  `generating` does.
- **Rollover happens off-request**, inside `GenerateCycleJob` → `CycleRolloverAction`.
  The client discovers the outcome by polling `GET /api/v1/routines/{routine}`
  and reading `pending_cycle.status` (`generating` → `active` means it rolled
  in — `pending_cycle` disappears and `cycle` is now the new week; `failed`
  means the job exhausted its 3 tries).
- `403` / `404` / `401` / `419` follow the exact pattern of every other
  `{routine}`-scoped route (`routines.show`, `routines.recommendations.list`).

### 2.2 CLI

Not applicable — no CLI commands. `php artisan queue:work` (already run for
`SessionAnalysisJob`) picks up `GenerateCycleJob` the same way; no new queue
connection or worker configuration.

### 2.3 Events

Not applicable — no domain events. `laravel/ai` emits its own internal
framework events when the agent runs (unchanged from the first-cycle path);
no listeners registered.

---

## 3. UI

### 3.1 Pages

Not applicable — no pages affected. The "Generar ciclo con estado en vivo"
(Order 210) and "Pantalla: crear rutina" (Order 200) screens live in
`gym-trainer-spa/`.

### 3.2 Components

Not applicable — no components affected.

---

## 4. Database

### 4.1 Schema changes

| Table | Action | Details |
|---|---|---|
| `exercise_recommendations` | Add column | `status` string, **not nullable**, `default('active')` — backed by `App\Enums\Recommendation\RecommendationStatus` (`active` \| `applied`). No index beyond the column itself (the existing `(user_id, routine_id, exercise_id)` unique already scopes lookups; `RecommendationCatalogService` filters by `routine_id` + `status`, a small per-routine scan). |

Notes:
- Anonymous migration class, following house style
  (`database/migrations/2026_09_05_120000_add_status_to_exercise_recommendations_table.php`),
  `up()` = `Schema::table('exercise_recommendations', fn (Blueprint $table) =>
  $table->string('status')->default('active'))`; `down()` drops the column.
- `default('active')` means every **existing** row (from the shipped
  `SessionAnalyzeAction` flow, pre-dating this column) is implicitly `active`
  on migrate — correct: nothing has been "applied" by a cycle rollover yet in
  any environment this ships to.
- No `cycles` / `cycle_days` / `day_exercises` schema change — the rollover
  writes rows through the **existing** `CycleDraftService::persistDay()`
  (unchanged) onto a `cycles` row created by `CycleGenerateAction` (also
  through the existing schema — no new columns needed; `CycleStatus` already
  has every case this ticket uses).
- **Database isolation (`CLAUDE.md`):** this branch adds a migration, so:
  `docker compose exec pgsql createdb -U gym -T gym_trainer gym_trainer_generate_next_cycle`,
  set `DB_DATABASE=gym_trainer_generate_next_cycle` in this worktree's `.env`;
  drop the clone and revert `.env` on merge. The Pest suite is unaffected
  (SQLite `:memory:`).

### 4.2 Seeds

Not applicable — no seeds.

---

## 5. Auth & Authorization

### 5.1 Authentication

**Method:** unchanged — Laravel Sanctum SPA / stateful mode, `web` session
guard, `auth:sanctum` on the route group.

### 5.2 Authorization

| Role | Permissions |
|---|---|
| Authenticated user, owns the routine | May `POST /api/v1/routines/{routine}/cycles` for their own routine, subject to the `ROUTINE_NOT_ACTIVE` / `CYCLE_GENERATION_IN_PROGRESS` business guards. |
| Authenticated user, does not own the routine | `403` — never reaches the guards or the job. |

New ability: `RoutinePolicy::generateCycle(User $user, Routine $routine): bool
=> $routine->user_id === $user->id` — identical shape to the existing
`RoutinePolicy::view`. Wired as route middleware:
`->can('generateCycle', 'routine')`, exactly like `routines.show` /
`routines.recommendations.list` (no Form Request `authorize()` — there is no
Form Request on this route).

The "must be the caller's `active` routine" rule is **not** authorization —
per `CLAUDE.md`, a state precondition is a Service guard, not a Policy check
(same reasoning as `ProfileIncompleteException` in `RoutineCreateAction`, and
`RoutineNotActiveException` in `TrainingSessionOpeningService`). It lives in
`CycleGenerationGuardService` and renders `409`, not `403`.

---

## 6. Configuration

Not applicable — no new environment variables, config files, or queue
connections. `GenerateCycleJob` runs on the existing default queue connection
(`config('queue.default')`, `database` in dev / `sync` in tests), exactly like
`SessionAnalysisJob`. The planner reuses `CyclePlannerAgent` and
`config('training.cycle.exercises_per_day.*')` unchanged.

---

## 7. Current vs New Behavior

| Behavior | Current | New |
|---|---|---|
| `POST /api/v1/routines/{routine}/cycles` | Route does not exist. | Creates a `generating` cycle for the caller's active routine, queues `GenerateCycleJob`, returns `202`. |
| `GenerateCycleJob` | `final implements ShouldQueue` stub; `handle()` empty; never dispatched. | Dispatched by `CycleGenerateAction`; `handle()` delegates to `CycleRolloverAction`; `failed()` marks the cycle `failed` after 3 tries (mirrors `SessionAnalysisJob`). |
| `Routine::cycle()` | `hasOne(Cycle::class)->ofMany('sequence_number', 'max')` — "the highest-`sequence_number` cycle", regardless of status. | `hasOne(Cycle::class)->where('status', CycleStatus::Active)->ofMany('sequence_number', 'max')` — "the active cycle". A `generating`/`failed` N+1 cycle no longer shadows the still-trainable active one. |
| `Routine::pendingCycle()` | Does not exist. | New `hasOne`: the latest cycle whose status is `generating` or `failed`, if any — `null` once it rolls over or on a routine that never generated an N+1. |
| `GET /api/v1/routines/{routine}` | `cycle` = highest-`sequence_number` cycle (days included). No `pending_cycle` key. | `cycle` = the **active** cycle (days included, unaffected by an in-flight N+1). `pending_cycle` = the `generating`/`failed` N+1 cycle if one exists, else `null` — status-only (`days: []`), for SPA polling. |
| `TrainingSessionOpeningService::guard()` | Reads `$routine->cycle()->first()` and checks `status === Active` — with today's `cycle()` semantics this happened to always be the active cycle (there was never more than one row). | Reads the routine's `active`-status cycle **explicitly** (`$routine->cycles()->where('status', Active)->first()`) — correct regardless of `cycle()`'s definition; also now correct with a `generating` N+1 in flight. *(Note: after the `cycle()` redefinition above, `$routine->cycle` alone would already be correct here too — the explicit query is kept as the honest, self-contained statement of the invariant this Service relies on, not incidental to a relation's current definition.)* |
| `RecommendationCatalogService::listCurrentForRoutine()` | Reads `$routine->cycle` (any status) to scope "current cycle" exercises; returns every recommendation for those exercises regardless of `status` (the column didn't exist). | Reads the routine's `active` cycle explicitly (same reasoning as above); adds `->where('status', RecommendationStatus::Active)` — an `applied` recommendation no longer appears. |
| `exercise_recommendations` | No `status` column; every row is implicitly "current". | `status` column, `active` by default; `SessionAnalyzeAction`'s `updateOrCreate` sets it to `active` on every write (including re-activating a row a prior rollover had set to `applied`); the rollover sets it to `applied` for exercises trained in the outgoing cycle. |
| Rate limiting on an authenticated route | None exists anywhere in the API (`register`/`login` throttle by IP, pre-auth). | First authenticated per-user throttle: `throttle:1,1` on `POST /api/v1/routines/{routine}/cycles`. |

---

## 8. Test Cases

Executable with Pest 4 on SQLite `:memory:` (`RefreshDatabase`, already
wired). Feature tests use `$this->user = User::factory()->create()` +
`AthleteProfile::factory()->for($this->user)->create()` +
`$this->withHeader('Origin', config('app.url'))`, and the existing
`trainingRoutineWithCycle($user)` / `fakeCyclePlanner()` helpers from
`tests/Helpers.php` unless a case needs a hand-built fixture. New assertions
use `assertJsonPath('data.code', …)` for errors (per
`domain-exception-handling-spec.md`) and `uuidV4Pattern()` / `iso8601Pattern()`
for shape checks.

### POST `/api/v1/routines/{routine}/cycles` — `tests/Feature/Cycle/GenerateCycleTest.php`

**TC-1:** Success — `202`, a `generating` cycle is created and the job is queued (AC "202 / generating")
- **Given:** `$this->user` with `trainingRoutineWithCycle($this->user)` (active routine, active cycle `sequence_number = 1`); `Bus::fake([GenerateCycleJob::class])`
- **When:** `POST /api/v1/routines/{routine}/cycles`
- **Expect:** `202`; `data.status` = `"generating"`, `data.sequence_number` = `2`, `data.split_rationale` = `null`, `data.generated_at` = `null`, `data.days` = `[]`, `data.id` matches `uuidV4Pattern()`; `assertDatabaseHas('cycles', ['routine_id' => $routine->id, 'sequence_number' => 2, 'status' => 'generating'])`; `Bus::assertDispatched(GenerateCycleJob::class, fn ($job) => $job->cycle->sequence_number === 2)`

**TC-2:** Conflict — a cycle already `generating` for this routine → `409` `CYCLE_GENERATION_IN_PROGRESS`, no new cycle, no dispatch (AC "409 referencing the existing draft")
- **Given:** `$this->user` with a routine whose active cycle is `sequence_number = 1`, plus `Cycle::factory()->generating()->for($routine)->create(['sequence_number' => 2])`; `Bus::fake([GenerateCycleJob::class])`
- **When:** `POST /api/v1/routines/{routine}/cycles`
- **Expect:** `409`; `data.code` = `"CYCLE_GENERATION_IN_PROGRESS"`; `data.message` contains the existing generating cycle's uuid; `assertDatabaseCount('cycles', 2)` (unchanged); `Bus::assertNothingDispatched()`

**TC-3:** Conflict — a `failed` cycle does NOT block a new attempt
- **Given:** `$this->user` with an active cycle `sequence_number = 1`, plus `Cycle::factory()->failed()->for($routine)->create(['sequence_number' => 2])`; `Bus::fake([GenerateCycleJob::class])`
- **When:** `POST /api/v1/routines/{routine}/cycles`
- **Expect:** `202`; new cycle `sequence_number = 3`, `status = generating`; `assertDatabaseCount('cycles', 3)`; `Bus::assertDispatched(GenerateCycleJob::class)`

**TC-4:** Guard — routine is `archived` (not the caller's active routine) → `409` `ROUTINE_NOT_ACTIVE`
- **Given:** `$this->user` with an `archived` routine (`Routine::factory()->archived()->for($this->user)->create()`) carrying a `completed` cycle; `Bus::fake([GenerateCycleJob::class])`
- **When:** `POST /api/v1/routines/{that archived routine}/cycles`
- **Expect:** `409`; `data.code` = `"ROUTINE_NOT_ACTIVE"`; `Bus::assertNothingDispatched()`; `assertDatabaseCount('cycles', 1)` (unchanged)

**TC-5:** Rate limit — a second call within a minute → `429`
- **Given:** `$this->user` with `trainingRoutineWithCycle($this->user)`; `Bus::fake([GenerateCycleJob::class])`
- **When:** `POST /api/v1/routines/{routine}/cycles` twice in a row
- **Expect:** first call `202`; second call `429`, `data.code` = `"RATE_LIMIT_EXCEPTION"`; `Bus::assertDispatchedTimes(GenerateCycleJob::class, 1)`

**TC-6:** Rate limit is per user, not global
- **Given:** `$this->user` and `$other`, each with `trainingRoutineWithCycle(...)`; `Bus::fake([GenerateCycleJob::class])`
- **When:** `POST` as `$this->user`, then immediately `POST` as `$other` for `$other`'s own routine
- **Expect:** both `202`; `Bus::assertDispatchedTimes(GenerateCycleJob::class, 2)`

**TC-7:** Ownership — a caller can't generate a cycle for another user's routine → `403`
- **Given:** `$other` with `trainingRoutineWithCycle($other)`; `actingAs($this->user)`
- **When:** `POST /api/v1/routines/{$other's routine}/cycles`
- **Expect:** `403`; `assertDatabaseCount('cycles', 1)` (unchanged); no cycle created for `$other`

**TC-8:** Unknown routine uuid → `404`
- **Given:** `actingAs($this->user)`
- **When:** `POST /api/v1/routines/00000000-0000-4000-8000-000000000000/cycles`
- **Expect:** `404`

**TC-9:** Unauthenticated → `401`
- **When:** `POST /api/v1/routines/{any uuid}/cycles` with no session
- **Expect:** `401`

### `CycleGenerateAction` — `tests/Feature/Cycle/CycleGenerateActionTest.php`

**TC-10:** `handle()` creates a `generating` cycle with the next `sequence_number` and dispatches the job
- **Given:** a routine with an active cycle `sequence_number = 3`; `Bus::fake([GenerateCycleJob::class])`
- **When:** `app(CycleGenerateAction::class)->handle($routine)`
- **Expect:** returns a `Cycle` — `status = Generating`, `sequence_number = 4`, `split_rationale`/`generated_at`/`activated_at` all `null`, `cycleDays` relation loaded and empty; `Bus::assertDispatched(GenerateCycleJob::class, fn ($job) => $job->cycle->is($returned))`

**TC-11:** `handle()` throws `RoutineNotActiveException` for an archived routine, dispatches nothing
- **Given:** an `archived` routine with a `completed` cycle; `Bus::fake([GenerateCycleJob::class])`
- **When:** `handle($routine)`
- **Expect:** throws `App\Exceptions\Cycle\RoutineNotActiveException` (`->statusCode() === 409`); `Bus::assertNothingDispatched()`

**TC-12:** `handle()` throws `CycleGenerationInProgressException` when a `generating` cycle already exists
- **Given:** a routine with an active cycle `sequence_number = 1` and a `generating` one `sequence_number = 2`; `Bus::fake([GenerateCycleJob::class])`
- **When:** `handle($routine)`
- **Expect:** throws `App\Exceptions\Cycle\CycleGenerationInProgressException`; the message contains the existing cycle's uuid; `Bus::assertNothingDispatched()`

### `CycleGenerationGuardService` — `tests/Unit/Cycle/CycleGenerationGuardServiceTest.php`

**TC-13:** `guard()` returns the routine's active cycle when there is no conflict
- **Given:** a routine with an active cycle `sequence_number = 2`
- **When:** `app(CycleGenerationGuardService::class)->guard($routine)`
- **Expect:** returns that `Cycle` (`sequence_number === 2`)

**TC-14:** `guard()` throws for each conflict independently (dataset)
- **Given:** dataset — (a) `archived` routine + `completed` cycle, (b) `active` routine with a `generating` N+1 alongside its `active` cycle
- **When:** `guard($routine)`
- **Expect:** (a) throws `RoutineNotActiveException`; (b) throws `CycleGenerationInProgressException`

### `App\Jobs\Cycle\GenerateCycleJob` + `CycleRolloverAction` — `tests/Feature/Cycle/CycleRolloverActionTest.php`

*(Feature: needs factories, sessions and set logs — under `tests/Feature/`,
`RefreshDatabase` wired.)* Every case starts from a helper fixture built for
this spec: an active routine with an outgoing `active` cycle (`sequence_number
= 1`, 5 days, 3 `day_exercises` each, per `trainingRoutineWithCycle()`) and a
`generating` `sequence_number = 2` cycle already created (as
`CycleGenerateAction` would leave it) — call it `generatingCycle()` in
`tests/Helpers.php`; `fakeCyclePlanner()` fakes `CyclePlannerAgent` for both
`planFirstCycle` and `planNextCycle` (same agent).

**TC-15:** Success — rollover: new cycle active with 5 days, outgoing cycle `completed`, trained recommendations `applied` (AC "atomic rollover")
- **Given:** `generatingCycle()`; the outgoing cycle's 5 `cycle_days` each have one `completed` `TrainingSession` with ≥1 set logged for at least one of that day's exercises; an `ExerciseRecommendation` for each trained exercise (`status: active`); `fakeCyclePlanner()`
- **When:** `app(CycleRolloverAction::class)->handle($generatingCycle)`
- **Expect:** reloaded — new cycle `status = active`, `activated_at` non-null, `split_rationale` non-null, `generated_at` non-null, 5 `cycle_days` × its plan's exercise count; outgoing cycle `status = completed`, `completed_at` non-null; every trained exercise's `ExerciseRecommendation.status = applied`; the routine's `cycle` (relation) now resolves to the new cycle

**TC-16:** Success — outgoing cycle `incomplete` when fewer than 5 days were trained
- **Given:** `generatingCycle()`; only 3 of the outgoing cycle's 5 `cycle_days` have a `completed` session; `fakeCyclePlanner()`
- **When:** `->handle($generatingCycle)`
- **Expect:** outgoing cycle reloaded — `status = incomplete`, `completed_at` non-null; new cycle still rolls to `active` regardless

**TC-17:** Success — rollover still completes when the outgoing week was never trained at all
- **Given:** `generatingCycle()`; none of the outgoing cycle's 5 `cycle_days` has any session; `fakeCyclePlanner()`
- **When:** `->handle($generatingCycle)`
- **Expect:** `202`-path invariants hold — new cycle still rolls to `active` with a fresh 5-day plan; outgoing cycle `status = incomplete`, `completed_at` non-null; every `ProgressionSummaryService` entry for the outgoing cycle has `performed = false`; no `ExerciseRecommendation` becomes `applied` (nothing was trained)

**TC-18:** Success — recommendations for UNTRAINED exercises stay `active` and are reused
- **Given:** `generatingCycle()`; only 2 of the outgoing cycle's exercises trained; an `ExerciseRecommendation` exists for a 3rd, untrained exercise (`status: active`); `fakeCyclePlanner()`
- **When:** `->handle($generatingCycle)`
- **Expect:** the untrained exercise's recommendation `status` is still `active`; only the 2 trained exercises' recommendations become `applied`

**TC-19:** Success — an already-`applied` recommendation for a trained exercise stays `applied` (idempotent)
- **Given:** `generatingCycle()`; a trained exercise whose recommendation is already `status: applied` (from a previous rollover)
- **When:** `->handle($generatingCycle)`
- **Expect:** no error; still `applied`

**TC-20:** Failure — planner throws → cycle stays `generating` (not `failed` yet — see `failed()` in TC-22/23), nothing else changes
- **Given:** `generatingCycle()`; `CyclePlannerAgent::fake(fn () => throw new RuntimeException('provider down'))`
- **When:** `app(CycleRolloverAction::class)->handle($generatingCycle)`
- **Expect:** throws `App\Exceptions\Cycle\CycleGenerationException`; the `generating` cycle reloaded is unchanged (`status = generating`); the outgoing `active` cycle is unchanged; no recommendation changed

**TC-21:** Failure — a malformed plan (same validation as first-cycle) throws before any write
- **Given:** `generatingCycle()`; `fakeCyclePlanner(['days' => array_slice(cyclePlanPayload()['days'], 0, 4)])`
- **When:** `->handle($generatingCycle)`
- **Expect:** throws `CycleGenerationException`; `assertDatabaseCount('cycle_days', 5)` (only the outgoing cycle's — nothing added for the failed N+1)

**TC-22:** `GenerateCycleJob::failed()` marks the cycle `failed` after retries are exhausted
- **Given:** a `generating` `Cycle`; `$job = new GenerateCycleJob($cycle)`
- **When:** `$job->failed(new RuntimeException('boom'))`
- **Expect:** the cycle reloaded is `status = failed`

**TC-23:** `GenerateCycleJob::handle()` delegates to `CycleRolloverAction`
- **Given:** `generatingCycle()`; `fakeCyclePlanner()`
- **When:** `(new GenerateCycleJob($generatingCycle))->handle(app(CycleRolloverAction::class))`
- **Expect:** the cycle rolls over exactly as in TC-15 (same assertions)

**TC-24:** The prompt carries the profile, routine goal/hint, active recommendations and progression summary (AC "IA recibe…")
- **Given:** `generatingCycle()` on a routine with a distinctive `goal` + `hint`; an `AthleteProfile` with distinctive `notes`; one trained exercise with an `active` recommendation (`action: advance_weight`, distinctive `explanation`); `fakeCyclePlanner()`
- **When:** `->handle($generatingCycle)`
- **Expect:** `CyclePlannerAgent::assertPrompted(fn (string $p) => str_contains($p, $profile->notes) && str_contains($p, $routine->goal->value) && str_contains($p, $routine->hint) && str_contains($p, 'advance_weight') && str_contains($p, $exercise->name))`

**TC-25:** The prompt guides the planner to hold on an unperformed exercise (AC "sin datos → mismo objetivo, baja confianza")
- **Given:** `generatingCycle()`; one of the outgoing cycle's exercises has zero logged sets; `fakeCyclePlanner()`
- **When:** `->handle($generatingCycle)`
- **Expect:** `CyclePlannerAgent::assertPrompted(fn (string $p) => str_contains($p, 'performed: no') || str_contains($p, 'no data') )` — the prompt names that exercise with `performed = false` and the "keep the same target" instruction (exact string asserted against the built prompt, per `buildNextCyclePrompt()`)

### `ProgressionSummaryService` — `tests/Unit/Cycle/ProgressionSummaryServiceTest.php`

**TC-26:** `summarize()` marks an exercise `performed = false` when zero sets were logged in the outgoing cycle (AC "prescrito con 0 series = false")
- **Given:** an outgoing `active` cycle with one `cycle_day`/`day_exercise`; no `TrainingSession` against that day
- **When:** `app(ProgressionSummaryService::class)->summarize($routine, $cycle)`
- **Expect:** one entry, `performed === false`, `actualAvgWeightKg === null`, `trend === 'insufficient_data'`

**TC-27:** `summarize()` computes `actual` averages from completed sessions' sets in the outgoing cycle
- **Given:** an outgoing cycle; a `completed` `TrainingSession` on its first day with 3 `SetLog` rows for the prescribed exercise (weights 40/42.5/42.5, reps 10/9/8, rpe 7/8/9)
- **When:** `->summarize($routine, $cycle)`
- **Expect:** that exercise's entry — `performed === true`, `actualAvgWeightKg === 41.67` (rounded), `actualAvgReps === 9.0`, `actualMaxRpe === 9.0`

**TC-28:** `summarize()` ignores sets from an `in_progress` (not yet completed) session
- **Given:** an outgoing cycle; an `in_progress` session with sets logged for its exercise, no `completed` session for that day
- **When:** `->summarize($routine, $cycle)`
- **Expect:** `performed === false`

**TC-29:** `summarize()` trend is `insufficient_data` with fewer than 2 completed sessions for the exercise across the whole routine
- **Given:** an exercise trained in exactly one `completed` session, ever
- **When:** `->summarize($routine, $cycle)`
- **Expect:** `trend === 'insufficient_data'`, `plateauSignal === false`

**TC-30:** `summarize()` trend is `up` / `down` / `flat` from the exercise's last two completed sessions, routine-wide (dataset)
- **Given:** dataset — two completed sessions for the same exercise (any cycle), average weights (a) `40 → 42.5` (b) `42.5 → 40` (c) `40 → 40`
- **When:** `->summarize($routine, $cycleContainingTheSecondSession)`
- **Expect:** (a) `trend === 'up'`, `plateauSignal === false`; (b) `trend === 'down'`, `plateauSignal === true`; (c) `trend === 'flat'`, `plateauSignal === true`

**TC-31:** `summarize()` only reports exercises prescribed in the outgoing cycle (a free-session exercise outside the plan is not summarized)
- **Given:** an outgoing cycle with 3 prescribed exercises; a `completed` free session (`cycle_day_id: null`) logging a 4th, unrelated exercise
- **When:** `->summarize($routine, $cycle)`
- **Expect:** exactly 3 entries, the free-session exercise absent

### `CycleCompletionService` — `tests/Unit/Cycle/CycleCompletionServiceTest.php`

**TC-32:** `wasCompleted()` is `true` only when every one of the cycle's 5 days has ≥1 completed session
- **Given:** dataset — (a) all 5 days have a completed session, (b) 4 of 5, (c) 5 of 5 but one day's only session is `in_progress`
- **When:** `app(CycleCompletionService::class)->wasCompleted($cycle)`
- **Expect:** (a) `true`; (b) `false`; (c) `false`

**TC-33:** `wasCompleted()` does not count a free session (`cycle_day_id: null`) toward any day
- **Given:** 5 days, 4 with a completed session tied to that `cycle_day_id`, plus one extra completed **free** session
- **When:** `wasCompleted($cycle)`
- **Expect:** `false`

### `CyclePlannerService::planNextCycle()` — extends `tests/Feature/Cycle/CyclePlannerServiceTest.php`

**TC-34:** `planNextCycle()` maps a well-formed response the same way `planFirstCycle()` does
- **Given:** `fakeCyclePlanner()`; a profile, `Goal`, `hint`, an empty recommendations collection, an empty progression summary
- **When:** `->planNextCycle($profile, $goal, $hint, collect(), [])`
- **Expect:** a `CyclePlanData` — same shape/assertions as TC-24 in `generate-first-cycle-spec.md`

**TC-35:** `planNextCycle()` applies the same malformed-shape validation as `planFirstCycle()` (dataset reused)
- **Given:** the same malformed-payload dataset as the first-cycle spec's TC-25
- **When:** `->planNextCycle(...)`
- **Expect:** every case throws `CycleGenerationException`

### `CycleDraftService::persistDays()` — extends `tests/Feature/Cycle/CycleDraftServiceTest.php`

**TC-36:** `persistDays()` writes days/exercises onto an existing cycle without touching the cycle row itself
- **Given:** a `Cycle::factory()->generating()->create()`; a `CyclePlanData`
- **When:** `app(CycleDraftService::class)->persistDays($cycle, $plan)`
- **Expect:** `cycle_days` = 5, exercises match the plan; the `cycle` row's `status` is still `generating` (unchanged — the caller sets it separately)

### `RecommendationCatalogService` — extends `tests/Unit/Recommendation/RecommendationCatalogServiceTest.php`

**TC-37:** `listCurrentForRoutine()` excludes an `applied` recommendation
- **Given:** a routine with a current cycle containing one exercise; an `ExerciseRecommendation` for it with `status: applied`
- **When:** `->listCurrentForRoutine($routine)`
- **Expect:** `toHaveCount(0)`

**TC-38:** `listCurrentForRoutine()` resolves "current cycle" as the `active` one even while an N+1 is `generating`
- **Given:** a routine with an `active` cycle (exercise A) and a `generating` cycle `sequence_number + 1` with no days yet; a recommendation for exercise A (`status: active`)
- **When:** `->listCurrentForRoutine($routine)`
- **Expect:** `toHaveCount(1)` — the `active` cycle's exercise, not empty (which today's `$routine->cycle` bug would incorrectly produce, since the `generating` cycle has no `day_exercises` yet)

### `GET /api/v1/routines/{routine}/recommendations` — extends `tests/Feature/Recommendation/ListRoutineRecommendationsTest.php`

**TC-39:** An `applied` recommendation is absent from the response
- **Given:** as TC-37, via the HTTP endpoint
- **When:** `GET /api/v1/routines/{routine}/recommendations`
- **Expect:** `200`; `data` is an empty array

### `TrainingSessionOpeningService` — extends `tests/Unit/Session/TrainingSessionOpeningServiceTest.php` (or feature-level session-create test)

**TC-40:** A session can still be opened against the active cycle's day while an N+1 cycle is `generating` (regression guard for the bug fixed in §7)
- **Given:** a routine with an `active` cycle (5 days, exercises) and a `generating` `sequence_number + 1` cycle (no days); no open session for the user
- **When:** `guard($user, $routine, $activeCycle->cycleDays->first())` (or, at the feature level, `POST /api/v1/routines/{routine}/sessions` with that `day`)
- **Expect:** no exception / `201` — the still-active week remains trainable throughout generation

### `GET /api/v1/routines/{routine}` — extends `tests/Feature/Routine/ShowRoutineTest.php`

**TC-41:** `cycle` stays the active cycle (with its days) while an N+1 is `generating`; `pending_cycle` surfaces the generating one
- **Given:** an active routine with an `active` cycle (5 days) and, after calling `POST /api/v1/routines/{routine}/cycles`, a `generating` `sequence_number + 1` cycle
- **When:** `GET /api/v1/routines/{routine}`
- **Expect:** `200`; `data.cycle.status = "active"`, `data.cycle.days` has 5 entries; `data.pending_cycle.status = "generating"`, `data.pending_cycle.days = []`

**TC-42:** `pending_cycle` is `null` when there is no in-flight or failed N+1
- **Given:** an active routine with only its `active` cycle
- **When:** `GET /api/v1/routines/{routine}`
- **Expect:** `data.pending_cycle === null`

**TC-43:** After a successful rollover, `cycle` is the new week and `pending_cycle` is `null` again
- **Given:** the fixture from TC-15, after `CycleRolloverAction` has run
- **When:** `GET /api/v1/routines/{routine}`
- **Expect:** `data.cycle.sequence_number = 2`, `status = "active"`, `days` has 5 entries; `data.pending_cycle === null`

**TC-44:** After a failed rollover, `pending_cycle` reports `failed`
- **Given:** the fixture from TC-20/22, after `GenerateCycleJob::failed()` has run
- **When:** `GET /api/v1/routines/{routine}`
- **Expect:** `data.cycle` unchanged (still the original active week); `data.pending_cycle.status = "failed"`

### Architecture — extends `tests/Feature/ArchTest.php`

**TC-45:** New classes obey existing conventions
- **Expect:** `App\Services\Cycle\ProgressionSummaryService`, `CycleGenerationGuardService`, `CycleCompletionService` are `final`; `App\Actions\Cycle\CycleGenerateAction`, `CycleRolloverAction` are `final` with a `handle()` method (covered by the existing blanket `App\Actions` rule); `App\Http\Controllers\Cycle` is invokable (new rule, mirrors the other controller-namespace rules).

---

## 9. Technical Decisions

| Decision area | What was decided | Why |
|---|---|---|
| Sync creation vs async generation | `POST /api/v1/routines/{routine}/cycles` is split into a **fast synchronous** row-creation step (`CycleGenerateAction`, no AI call) and an **async** planning + rollover step (`GenerateCycleJob` → `CycleRolloverAction`). | Matches the AC exactly ("202… queda generating") and `SessionAnalysisJob`'s established shape: the request never blocks on an AI call for the N+1 path (unlike the first cycle, which is intentionally synchronous per its own spec). |
| 409 conflict check | "Already generating" means an existing `cycles` row with `status = generating` for the routine — **not** a literal `draft` status. `CycleStatus::Draft` stays reserved/unused in the MVP (per `data-model.md` / the enum's own docblock). | Confirmed with the product owner: the AC's "borrador existente" wording predates the current `generating`/`active` lifecycle; a `draft` check would never fire since nothing ever writes `Draft`. |
| `hint` on this endpoint | Not accepted. The planner always uses `routine.hint` (the value saved at routine creation). | Confirmed with the product owner. The AC only says the AI receives "goal/hint de la rutina" (the routine's own, already-stored hint); a per-generation hint is a SPA-story affordance (Order 210) not mirrored by this ticket's AC. |
| `exercise_recommendations.status` | New column added in this ticket (`active` default / `applied`), even though two prior PRs (#21, #23) explicitly deferred it "until a consumer exists". `SessionAnalyzeAction` sets it to `active` on every write (new or re-analyzed); `RecommendationCatalogService` (shipped) is updated to filter `status = active`. | This ticket is that consumer. Confirmed with the product owner: once `applied` exists, the shipped "vigentes" endpoint must stop showing `applied` rows or it becomes wrong (a rolled-over recommendation is no longer "vigente"). Re-activating on every analysis (rather than only on create) is necessary because `updateOrCreate` **overwrites** the same row keyed by `(user, routine, exercise)` — a fresh analysis after a rollover must make that exercise's recommendation current again. |
| Rate limit | `throttle:1,1` (1 request/minute), keyed automatically by the authenticated user id (Laravel's `ThrottleRequests` resolves the signature from `$request->user()?->getAuthIdentifier()` when present, falling back to IP only for guests — this route is always behind `auth:sanctum`). No named `RateLimiter::for(...)` registered; the inline `throttle:1,1` form matches the existing `register`/`login` precedent (`throttle:6,1`) rather than introducing `AppServiceProvider`/`bootstrap/app.php` rate-limiter wiring for a single route. | Confirmed with the product owner. Generation is an expensive, queued AI operation; a tight per-user limit stops accidental double-submits (e.g. a double-tapped "Generar ciclo" button) without needing a dedicated limiter class for one route. |
| `RoutineNotActiveException` / `CycleGenerationInProgressException` identity | Both `final extends App\Exceptions\DomainException`, `Cycle` domain folder (`app/Exceptions/Cycle/`), `statusCode = 409`. `RoutineNotActiveException` is a **new, Cycle-domain-scoped** class — not a reuse of the existing `App\Exceptions\Session\RoutineNotActiveException` (same rule, different domain), matching the codebase's "folders by domain" convention: each domain owns the exceptions its own Services throw, even for a conceptually-shared rule. `CycleGenerationInProgressException`'s constructor takes the conflicting `Cycle` so its message can name the uuid, since the envelope carries no structured reference field. | `CLAUDE.md` layout (`Exceptions/{Domain}/…`) + `domain-exception-handling-spec.md` envelope contract (`{code, message}`, `errors` validation-only). A `Cycle`-domain Service (`CycleGenerationGuardService`) should not import a `Session`-domain exception class. |
| `CycleGenerationException` reuse | The **existing** exception (`AI_GENERATION_FAILED`, `502`) is reused as-is for a `planNextCycle()` failure — no new exception. | It already means exactly "the planner call failed or returned an unusable plan"; the failure semantics are identical between the first cycle and N+1. On the N+1 path this exception never reaches an HTTP response directly (it's thrown inside the queued job, caught by `failed()` → the cycle becomes `status: failed`, observed via polling) — but keeping the same class means `CyclePlannerService`'s validation logic (§ malformed-plan checks) is shared verbatim by both `planFirstCycle()` and `planNextCycle()`. |
| `CycleGenerationGuardService` | New `final` Service, one method: `guard(Routine $routine): Cycle`, checked **in this order** (mirrors `TrainingSessionOpeningService`'s "guard clauses in order" shape — order matters because the checks aren't independent): (1) `throw_unless($routine->status === RoutineStatus::Active, new RoutineNotActiveException)` — checked **first**, since an archived routine typically has no `active`-status cycle at all, and must not be misreported as "no active cycle found" instead of "not active"; (2) `throw_if(Cycle::where('routine_id', $routine->id)->where('status', Generating)->exists(), new CycleGenerationInProgressException($existing))`; (3) only then `return Cycle::query()->where('routine_id', $routine->id)->where('status', Active)->sole()` — `sole()` deliberately: by this point the routine is confirmed active, so zero or >1 active cycles is an invariant violation, not a case to handle gracefully; it surfaces as a loud 404/500 rather than defensive code for a state the rest of the system guarantees can't happen. | `CLAUDE.md` rule 6 — a small, single-purpose Service the Action calls into, not an Action itself (it opens no transaction, dispatches nothing). |
| `CycleCompletionService` | New `final` Service, one method: `wasCompleted(Cycle $cycle): bool` — `$cycle->cycleDays->every(fn (CycleDay $day) => TrainingSession::where('cycle_day_id', $day->id)->where('status', SessionStatus::Completed)->exists())`. | Extracted as its own Service (not inlined in `CycleRolloverAction`) because it's an independently meaningful business rule ("what does 'the week was completed' mean") that `CycleRolloverAction` merely *calls*, matching the `RoutineActivationService` / `SessionCompletionService` precedent — a Service the Action reads top-to-bottom as one step, not five lines of ad hoc query logic buried in the Action. |
| `ProgressionSummaryService` scope | Summarizes **only** the exercises prescribed in the **outgoing** cycle's `day_exercises` (one entry per distinct `exercise_id`, first occurrence if it somehow appears on two days). A free-session exercise outside that set is not summarized. | Matches the AC framing ("resumen de progresión por ejercicio", tied to what the outgoing week prescribed) and mirrors `RecommendationCatalogService`'s existing "only exercises in the current cycle" scoping — one consistent notion of "the exercises that matter right now" across the codebase. |
| `performed` flag | `true` iff at least one `SetLog` exists for that exercise, logged against a `completed` `TrainingSession` whose `cycle_day_id` belongs to the outgoing cycle. A prescribed exercise with zero logged sets, or sets logged only in a still-`in_progress` session, is `performed = false`. | Direct translation of the source story's own note: "`performed: true/false` (prescrito con 0 series = false)". Restricting to `completed` sessions matches `CycleCompletionService`'s definition of "trained" and avoids counting sets from a session the user might still discard. |
| Trend & plateau signal | Computed **routine-wide** (not outgoing-cycle-only) from the exercise's last two `completed` sessions ever, ordered by `completed_at`: average logged weight per session, compared pairwise → `up` / `down` / `flat` / `insufficient_data` (fewer than 2 sessions). `plateauSignal = true` when `trend` is `down`, or `flat` with 2+ sessions on record. | A single outgoing cycle (one week) rarely trains the same exercise twice, so a same-cycle "trend" would almost always be `insufficient_data` — the useful signal needs the exercise's last two *sessions*, which may span cycles. This is a deliberately simple, defensible MVP algorithm (not a rolling regression or N-cycle window) — **flagged for scrutiny during spec review**: if the product owner wants a longer look-back or a different plateau threshold, this is the section to revise before implementation starts. |
| `CyclePlannerService::planNextCycle()` | A second public method on the **existing** Service (not a new Service, not a new Agent class) — same `CyclePlanData` DTO tree, same validation helpers (`mapPlan`/`mapDay`/`mapExercise`/`requireString`/`requireInt`, made available to both methods), a **different** prompt-builder method (`buildNextCyclePrompt`) that adds an "Active recommendations" section and a "Progression summary" section (including the explicit "no data → keep the same target" instruction per exercise with `performed = false`) on top of the same profile/goal/hint block `planFirstCycle()` already builds. `CyclePlannerAgent::instructions()` is reworded to be neutral to "first week" vs. "continuation using recent training data, when the prompt includes it" (it no longer states "the FIRST training week"). | The structured-output **schema** (5 days, full per-exercise prescription) is identical between the first cycle and N+1 — only the prompt content differs. A second Agent class would duplicate ~60 lines of schema/strict-mode boilerplate for the same JSON shape (`CLAUDE.md` rule 6: a new class must make the system simpler, not add a near-duplicate). Reworking one shared `instructions()` string keeps a single agent while staying accurate for both call sites. |
| `CycleDraftService::persistDays()` | The existing `persist()` (first-cycle path — creates the `cycles` row itself, hardcoded `sequence_number = 1`/`Active`) is **left untouched**. A new `persistDays(Cycle $cycle, CyclePlanData $plan): void` is extracted from its day-writing loop and reused by both `persist()` (internally) and `CycleRolloverAction` (directly, against the pre-existing `generating` row). No transaction opened by either method — the caller (`RoutineCreateAction` / `CycleRolloverAction`) owns it, unchanged convention. | The N+1 rollover writes days onto a **row that already exists** (created earlier, synchronously, by `CycleGenerateAction`) — `persist()`'s "create the cycle row" half doesn't apply. Splitting the method is the minimal change that avoids duplicating the day/exercise-writing loop, and leaves the first-cycle path's tested behavior completely unchanged. |
| `CycleGenerateAction` (controller-invoked) | `final`, `handle(Routine $routine): Cycle` — `DB::transaction`(`$activeCycle = $guard->guard($routine)` → insert `cycles` row `generating`, `sequence_number = $activeCycle->sequence_number + 1`, all other columns `null` → `GenerateCycleJob::dispatch($cycle)`) → return `$cycle->load('cycleDays')` (empty, so `CycleResource` never needs an unloaded-relation branch). | Mirrors `RoutineCreateAction`'s shape (guard → transaction → dispatch → return with the relation the Resource needs already loaded), but inverted: here the fast/cheap step (row creation) is what's transactional and synchronous, and the slow/external step (the AI call) is what's deferred to the job — the opposite of the first-cycle path, because unlike routine creation, a placeholder `generating` row is a valid, useful thing to persist immediately (it's exactly what `202` + polling needs). |
| `CycleRolloverAction` (job-invoked) | `final`, `handle(Cycle $generatingCycle): void`. Reads `$routine = $generatingCycle->routine`, `$outgoingCycle = Cycle::where('routine_id', $routine->id)->where('status', Active)->sole()`, `$profile = $routine->user->athleteProfile`, active recommendations, and `$summary = $progression->summarize($routine, $outgoingCycle)` — all **before** calling the planner. Calls `$planner->planNextCycle(...)` **before** opening any transaction (external call, same rule as `RoutineCreateAction`/`SessionAnalyzeAction`). On success, one `DB::transaction` does: `$draft->persistDays($generatingCycle, $plan)`; flip `$generatingCycle` to `active` (+ `split_rationale`/`generated_at`/`activated_at`); flip `$outgoingCycle` to `completed`/`incomplete` per `$completion->wasCompleted($outgoingCycle)` (+ `completed_at`); bulk-update `ExerciseRecommendation` to `applied` for the exercise ids where `$summary[...]->performed === true`. Throws (uncaught) on planner failure — the job's own retry/`failed()` machinery handles it; **no** try/catch inside the Action. | One Action = one use case, reading top-to-bottom as the rollover's story (`CLAUDE.md` rule 2/6). The read-then-plan-then-atomically-write shape mirrors `SessionAnalyzeAction` exactly (AI call outside any transaction; the eventual writes — here five things instead of two — are one transaction). Leaving exception propagation to the job (rather than swallowing it here) is what lets `GenerateCycleJob`'s existing `tries`/`backoff`/`failed()` machinery — copied verbatim from `SessionAnalysisJob` — do its job unmodified. |
| `GenerateCycleJob` | `final implements ShouldQueue`; `public function __construct(public Cycle $cycle) {}` (was `Routine $routine` — changed, since the job now needs the specific `generating` row, not just "some cycle of this routine"); `$tries = 3`; `backoff(): array { return [30, 120]; }`; `handle(CycleRolloverAction $action): void { $action->handle($this->cycle); }`; `failed(Throwable $e): void { $this->cycle->update(['status' => CycleStatus::Failed]); }`. No `analysis_state`-style intermediate "processing" flip — the cycle is already `generating` from the moment `CycleGenerateAction` created it, so there is no "not yet started" → "started" transition to record (unlike `SessionAnalysisJob`'s `pending` → `processing`). | Copies `SessionAnalysisJob`'s retry/backoff/failed shape verbatim (`docs/plans/session-analysis-spec.md` precedent, `CLAUDE.md` "Jobs & AI": "if analysis fails, … the recommendation simply doesn't appear until retry" — same idea here: if generation fails, the outgoing week stays `active` and trainable; the client sees `pending_cycle.status: failed` and can re-`POST` for a fresh attempt). |
| `Routine::cycle()` semantics | **Changed** from `hasOne(Cycle::class)->ofMany('sequence_number', 'max')` ("highest sequence number, any status") to `hasOne(Cycle::class)->where('status', CycleStatus::Active)->ofMany('sequence_number', 'max')` ("the active cycle"). New `Routine::pendingCycle(): HasOne = hasOne(Cycle::class)->whereIn('status', [CycleStatus::Generating, CycleStatus::Failed])->ofMany('sequence_number', 'max')`. `ShowRoutineController` loads `['cycle.cycleDays.dayExercises.exercise', 'pendingCycle.cycleDays']`; `RoutineResource` gains `'pending_cycle' => CycleResource::make($this->whenLoaded('pendingCycle'))`. | **This reconsiders a plan already discussed with the product owner** (originally: "leave `cycle()` alone, it's useful as-is for polling via `GET /routines/{routine}`"), refined during drafting once its consequence became concrete: leaving `cycle()` unchanged means that, the moment a `generating` N+1 exists, `GET /api/v1/routines/{routine}` would show `cycle.status: generating` with `days: []` — **hiding the still-active, still-trainable week's days** from that endpoint for the whole generation window (30-90s is tolerable; but on a `failed` outcome, that shadowing would persist **indefinitely**, until the user successfully generates again, since a `failed` cycle keeps the highest `sequence_number`). That is a functional regression, not just a naming nitpick — a client reading `GET /routines/{routine}` to show "your current week" would show nothing. Splitting into `cycle` (always the trainable week) + `pending_cycle` (status-only, for polling) fixes this while still giving the SPA's "estado en vivo" screen (Order 210) exactly what it needs from the same endpoint, no new route. **Flagged prominently for spec review** since it revises an earlier answer. |
| Fixing `TrainingSessionOpeningService` / `RecommendationCatalogService` | Both are updated to query the routine's `active`-status cycle **explicitly** (`$routine->cycles()->where('status', Active)->first()`), rather than relying on `$routine->cycle`. | Confirmed with the product owner as the fix for the bug this ticket's own AC surfaces. Kept explicit (rather than simply relying on the redefined `cycle()` above, which — after the redefinition — would technically already be correct here too) so each Service states the invariant it depends on in its own words, and so neither Service's correctness is coupled to `cycle()` keeping this exact definition in the future. |
| Race safety net | No new DB constraint (partial unique index) against two concurrent `POST` calls both passing `CycleGenerationGuardService::guard()` before either inserts. The **existing** `cycles` `(routine_id, sequence_number)` unique index is the safety net: a genuine race computes the same `sequence_number` for both and the loser's insert fails with a DB unique-constraint violation — surfaced as an unhandled `QueryException` → generic `500`, not a clean `409`. | Accepted as an explicit MVP gap, not silently ignored: `throttle:1,1` already makes the double-submit window (two requests processed concurrently, not merely close together) narrow — same-tab double-clicks are the realistic case, and the browser/SPA can debounce that. A partial unique index (`WHERE status = 'generating'`) plus catching the resulting `QueryException` to re-map it to `CycleGenerationInProgressException` is a reasonable follow-up if this proves to matter in practice, deliberately deferred to keep this ticket's write path to one straightforward guard-then-insert, per `CLAUDE.md` "simplicity first". |
| `App\Http\Controllers\Cycle\GenerateCycleController` | `final`, `__invoke(Routine $routine, CycleGenerateAction $action): JsonResponse { return CycleResource::make($action->handle($routine))->response()->setStatusCode(Response::HTTP_ACCEPTED); }`. No Form Request (no input to validate) — same shape as `ShowRoutineController` / `ListRoutineRecommendationsController`. | `CLAUDE.md` "Controller" — ~3 lines, owns only the HTTP status code. A Form Request whose `rules()` is `[]` and whose sole job is `authorize()` would just re-implement what the route's own `->can('generateCycle', 'routine')` middleware already does — the existing no-input, route-gated controllers in this codebase (not just the first-cycle spec) establish that a Form Request is for **input** validation, not a stand-in for the Policy gate. |
| Route registration | `Route::post('routines/{routine}/cycles', GenerateCycleController::class)->whereUuid('routine')->middleware('throttle:1,1')->can('generateCycle', 'routine')->name('routines.cycles.store');`, inside the existing `auth:sanctum` group, placed after the recommendations route (same `{routine}`-scoped block). | Matches the existing route-file conventions exactly (`whereUuid`, `->can(...)` middleware, a comment on the block explaining the gate) — see `routines.recommendations.list` immediately above it in `routes/api.php`. |
| Recommendation write on session analysis | `SessionAnalyzeAction`'s `ExerciseRecommendation::updateOrCreate(...)` payload gains `'status' => RecommendationStatus::Active` explicitly (previously the column didn't exist). | Confirmed with the product owner (§ "exercise_recommendations.status" row above): a fresh analysis always makes that exercise's recommendation current again, even if a prior rollover had marked it `applied`. Explicit rather than relying on the column's DB default, since `updateOrCreate` on an **existing** row does not re-apply column defaults — only a genuine `INSERT` would. |
| Migration / DB isolation | One migration on a `-T gym_trainer` clone `gym_trainer_generate_next_cycle`; `.env` `DB_DATABASE` repointed in the worktree, reverted on merge. Pest stays SQLite `:memory:`. | `CLAUDE.md` "Workflows — database isolation". |
| Scramble docs | The new `202` route and the changed `GET /api/v1/routines/{routine}` shape (`pending_cycle`) are inferred automatically from `CycleResource` / `RoutineResource` return types and the route's throw sites; no `#[...]` attribute needed. | `CLAUDE.md` "API documentation" — the pipeline stays typed, so Scramble needs no help. |
| Git artifacts | Branch `worktree-generate-next-cycle` (already checked out); English only; **no** `Co-Authored-By: Claude` / `Claude-Session:` trailers on commits, no "Generated with Claude Code" footer anywhere; single PR, spec-first (this document merges to the PR before any implementation commit). | Repo `CLAUDE.md` / `AGENTS.md` "Git" rules take precedence over any conflicting session-level attribution instruction — same precedent as every prior spec in `docs/plans/`. |

---

## 10. Work Plan

Inner-most first (migration → enum → exceptions → Services → Action/Job
plumbing → planner extension → controller/route → model/Resource fix →
tests). Each task's DoD is the artifact existing, `vendor/bin/pint --dirty` +
`vendor/bin/phpstan analyse` (level 6) clean, and — where the class carries
logic — its focused test, authored in the same task.

| # | Task | Definition of Done |
|---|---|---|
| 1 | Clone the runtime DB: `docker compose exec pgsql createdb -U gym -T gym_trainer gym_trainer_generate_next_cycle`; set `DB_DATABASE=gym_trainer_generate_next_cycle` in this worktree's `.env`. | `php artisan db:show` targets the clone; `gym_trainer` untouched; Pest still SQLite. |
| 2 | Create the migration adding `exercise_recommendations.status` (string, default `active`) per §4.1. Create `App\Enums\Recommendation\RecommendationStatus` (`Active`/`Applied`). | `php artisan migrate` runs on the clone and fresh SQLite; `RecommendationStatus::from('active')` works. |
| 3 | Update `App\Models\ExerciseRecommendation`: add `status` to `casts()` (→ `RecommendationStatus::class`) and `#[Fillable]`; refresh PHPDoc (`php artisan ide-helper:models --write` + hand-check). Update `database/factories/ExerciseRecommendationFactory.php` (`status` default `Active`, add an `applied()` state). | Pint + PHPStan clean; `ExerciseRecommendation::factory()->applied()->create()->status === RecommendationStatus::Applied`. |
| 4 | Create `App\Exceptions\Cycle\RoutineNotActiveException` and `App\Exceptions\Cycle\CycleGenerationInProgressException` (constructor takes the conflicting `Cycle`) per §9. | `->statusCode() === 409` for both; the second's default message contains the injected cycle's `uuid`; Pint + PHPStan clean. |
| 5 | Create `App\Services\Cycle\CycleGenerationGuardService` (`guard(Routine): Cycle`) and `App\Services\Cycle\CycleCompletionService` (`wasCompleted(Cycle): bool`) per §9. Write `tests/Unit/Cycle/CycleGenerationGuardServiceTest.php` (TC-13, TC-14) and `tests/Unit/Cycle/CycleCompletionServiceTest.php` (TC-32, TC-33). | Both Pest files green; Pint + PHPStan clean. |
| 6 | Create `App\Data\Cycle\ExerciseProgressionData` (readonly DTO per §9's field list) and `App\Services\Cycle\ProgressionSummaryService::summarize(Routine, Cycle): array` per §9. Write `tests/Unit/Cycle/ProgressionSummaryServiceTest.php` (TC-26…TC-31). | `vendor/bin/pest tests/Unit/Cycle/ProgressionSummaryServiceTest.php` green; Pint + PHPStan clean. |
| 7 | Extract `App\Services\Cycle\CycleDraftService::persistDays(Cycle, CyclePlanData): void` from the existing `persist()`'s day-writing loop; `persist()` calls it internally. Extend `tests/Feature/Cycle/CycleDraftServiceTest.php` (TC-36); confirm the existing first-cycle tests (TC-28/29 in `generate-first-cycle-spec.md`) still pass unmodified. | `vendor/bin/pest tests/Feature/Cycle/CycleDraftServiceTest.php` green (old + new cases); Pint + PHPStan clean. |
| 8 | Add `CyclePlannerService::planNextCycle(AthleteProfile, Goal, ?string, Collection $recommendations, array $progressionSummary): CyclePlanData` + `buildNextCyclePrompt(...)` private helper, sharing the existing `mapPlan`/`mapDay`/`mapExercise`/`require*` private methods with `planFirstCycle()`. Reword `App\Ai\Agents\Cycle\CyclePlannerAgent::instructions()` per §9 (drop "the FIRST training week" framing). Extend `tests/Feature/Cycle/CyclePlannerServiceTest.php` (TC-34, TC-35). | `vendor/bin/pest tests/Feature/Cycle/CyclePlannerServiceTest.php` green (old + new); Pint + PHPStan clean; existing first-cycle tests still pass (agent instructions change doesn't affect the schema/fake mechanics). |
| 9 | Rewrite `App\Jobs\Cycle\GenerateCycleJob` per §9 (`Cycle` constructor property, `tries`, `backoff()`, `handle()`, `failed()`). Create `App\Actions\Cycle\CycleRolloverAction::handle(Cycle): void` per §9. Write `tests/Feature/Cycle/CycleRolloverActionTest.php` (TC-15…TC-25) — including the new `generatingCycle()` fixture helper in `tests/Helpers.php`. | `vendor/bin/pest tests/Feature/Cycle/CycleRolloverActionTest.php` green; Pint + PHPStan clean. |
| 10 | Create `App\Actions\Cycle\CycleGenerateAction::handle(Routine): Cycle` per §9. Write `tests/Feature/Cycle/CycleGenerateActionTest.php` (TC-10…TC-12). | `vendor/bin/pest tests/Feature/Cycle/CycleGenerateActionTest.php` green; Pint + PHPStan clean. |
| 11 | Add `RoutinePolicy::generateCycle(User, Routine): bool` per §5.2. Create `App\Http\Controllers\Cycle\GenerateCycleController` per §9. Register the route in `routes/api.php` per §9 (after `routines.recommendations.list`). Write `tests/Feature/Cycle/GenerateCycleTest.php` (TC-1…TC-9). | `vendor/bin/pest tests/Feature/Cycle/GenerateCycleTest.php` green; `php artisan route:list` shows `POST routines/{routine}/cycles`; Pint + PHPStan clean. |
| 12 | Redefine `App\Models\Routine::cycle()` and add `pendingCycle()` per §9; refresh PHPDoc. Update `App\Http\Controllers\Routine\ShowRoutineController` to eager-load `pendingCycle.cycleDays`. Update `App\Http\Resources\Routine\RoutineResource` to add `pending_cycle`. Extend `tests/Feature/Routine/ShowRoutineTest.php` (TC-41…TC-44). | `vendor/bin/pest tests/Feature/Routine/ShowRoutineTest.php` green (old + new); Pint + PHPStan clean; the existing `POST /routines` (`201`) and list-routines tests are unaffected (neither eager-loads `pendingCycle`, so the key is simply absent there, per `whenLoaded`). |
| 13 | Fix `App\Services\Session\TrainingSessionOpeningService::guard()` and `App\Services\Recommendation\RecommendationCatalogService::listCurrentForRoutine()` to query the routine's `active`-status cycle explicitly, per §7/§9. Extend `tests/Unit/Recommendation/RecommendationCatalogServiceTest.php` (TC-37, TC-38) and the session-opening regression case (TC-40, in whichever existing test file covers `TrainingSessionOpeningService` / `StoreTrainingSessionController`). | The two updated test files green; the full existing `tests/Feature/Session` and `tests/Feature/Recommendation` suites still pass (no regression). |
| 14 | Update `App\Actions\Session\SessionAnalyzeAction`'s `updateOrCreate` payload to set `'status' => RecommendationStatus::Active` explicitly, per §9. Extend `tests/Feature/Recommendation/ListRoutineRecommendationsTest.php` (TC-39). | `vendor/bin/pest tests/Feature/Recommendation/ListRoutineRecommendationsTest.php` green; the existing session-analysis suite (`tests/Feature/Session`, session-analysis-spec.md's tests) still passes. |
| 15 | Add the `App\Http\Controllers\Cycle` invokable rule to `tests/Feature/ArchTest.php` (TC-45); confirm the `App\Services\Cycle` / `App\Actions\Cycle` additions already pass the existing blanket `App\Services` final / `App\Actions` final+handle() rules. | `vendor/bin/pest tests/Feature/ArchTest.php` green. |
| 16 | `vendor/bin/pint --dirty`, then `vendor/bin/phpstan analyse`, then a final `php artisan ide-helper:models --write` + `vendor/bin/pint app/Models`. | Pint no diffs; PHPStan level 6 clean; `Routine` / `ExerciseRecommendation` / `Cycle` PHPDoc in sync. |
| 17 | `docker compose exec app composer check` (Pint `--test` + PHPStan + full Pest — every suite, not just the new files, to catch any regression from the `Routine::cycle()` redefinition or the recommendation-status filter). | All three green; no regression anywhere in the suite. |
| 18 | Manual live check against `http://localhost:8000` with a real `AI_PROVIDER_API_KEY`: create a routine (first cycle), complete all 5 days with sets, `POST /api/v1/routines/{routine}/cycles` → `202`, poll `GET /api/v1/routines/{routine}` until `pending_cycle` disappears and `cycle.sequence_number` is `2` with 5 real days; confirm `GET /routines/{routine}/recommendations` no longer lists the exercises just rolled over as `applied`; review `GET /docs/api` for the new route and the `pending_cycle` field. | The endpoint rolls over correctly against a real provider; Scramble shows the new route and response shape. |
| 19 | On merge / branch drop: `docker compose exec pgsql dropdb -U gym --if-exists gym_trainer_generate_next_cycle`; revert `DB_DATABASE` in the worktree `.env`. | The clone is gone; `.env` restored to `gym_trainer`. |

*Process note: branch name, commit messages and PR text follow `CLAUDE.md` /
`AGENTS.md` — English only, no `Co-Authored-By: Claude` / `Claude-Session:`
trailers, no "Generated with Claude Code" footer. Per the user's request for
this ticket, the PR opened from this branch carries **only this spec
document** in its first commit(s) — implementation (tasks 1-19 above) is
added to the same PR only after the user has reviewed and confirmed this
spec, possibly after one or more revision rounds.*
