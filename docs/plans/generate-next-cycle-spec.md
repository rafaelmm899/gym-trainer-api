# Generate the next cycle on demand (cycle N+1)

> Derived from the Notion ticket "Generar el ciclo siguiente bajo demanda"
> (Feature: Ciclos & generación IA · MVP · Must · Repo: API · Order 150). Base
> contract: `docs/product-context.md` §2 / §4 (step 7) / §5 / §6 / §7,
> `docs/plans/data-model.md`, `docs/plans/generate-first-cycle-spec.md` (the
> synchronous, all-or-nothing pattern this ticket **reuses verbatim** for
> cycle N+1 — see "Adjusted during requirements gathering" below),
> `docs/plans/routine-recommendations-endpoint-spec.md` (the endpoint this
> ticket changes), `docs/plans/domain-exception-handling-spec.md`, and
> `CLAUDE.md` "The pipeline".

## 1. Context

**Kind:** Brownfield Feature

**Stack:** PHP 8.5 · Laravel 13 · PostgreSQL 17 (runtime) / SQLite `:memory:`
(tests) · Pest 4 · `laravel/ai` (structured-output agents, same
`CyclePlannerAgent` this ticket extends) · `spatie/laravel-data` (DTOs) ·
`laravel/sanctum` 4 (SPA cookie mode) · Pint · Larastan level 6. Everything
runs in Docker.

**Problem statement:** A routine's first cycle is generated synchronously
inside `POST /api/v1/routines` (shipped). There is no way to generate the
*next* week — the user is stuck on cycle 1 forever. This ticket adds
`POST /api/v1/routines/{routine}/cycles`: **synchronously**, in the same
request — no queued job, no `generating`/`failed` cycle state — it feeds the
planner AI the athlete profile, the routine's `goal`/`hint`, the routine's
currently-`active` exercise recommendations, and a PHP-computed progression
summary per exercise; on success it writes the new cycle `active` **and**
atomically rolls the outgoing cycle over: `completed` or `incomplete`
depending on whether all 5 of its days were trained, and the recommendations
for exercises actually trained in the outgoing cycle become `applied`
(recommendations for untrained exercises stay `active` and are reused
verbatim by the new plan). On failure, nothing is persisted — same
all-or-nothing contract as the first cycle. This closes the product's central
loop (`docs/product-context.md` §4, step 7 → back to step 4).

**Adjusted during requirements gathering:** the Notion ticket's AC and
`docs/product-context.md` §4/§6 both describe this as an **asynchronous**,
queued-job flow (`202` → `generating` → polling → `active`/`failed`), and the
first draft of this spec was written that way — a `GenerateCycleJob`, a
`generating`/`failed` `Cycle` row, a `pending_cycle` field on
`GET /api/v1/routines/{routine}` for polling, and a `409
CYCLE_GENERATION_IN_PROGRESS` guard against a second request while one was
mid-flight. **Product decision, this session: cycle N+1 generation is
synchronous instead — the exact same pattern as the first cycle
(`generate-first-cycle-spec.md`).** This is a deliberate, explicit deviation
from both the Notion AC and `docs/product-context.md` §4 step 7 / §6, which
still describe the async version; **not** a case of "the story predates the
current design" (the async version *is* the current, still-unimplemented
design) — it is a fresh product call made while building this spec, and
`docs/product-context.md` needs a follow-up edit once this ships (out of
scope here — see below). One concrete side effect worth flagging: the
`gym-trainer-spa/` story "Pantalla: generar ciclo con estado en vivo" (Order
210, separate repo) is built entirely around polling a `generating` state
this endpoint no longer produces — that screen's design needs to be
revisited when its repo picks up the story, but doing so is out of scope for
this API-only ticket.

**In scope:**
- `POST /api/v1/routines/{routine}/cycles` — synchronous, all-or-nothing,
  same shape as `POST /api/v1/routines`'s cycle step. On success: `201` with
  the new cycle **fully nested** (`status: "active"`, 5 real days). On
  failure (the planner call errors or returns an unusable plan): `502
  AI_GENERATION_FAILED` (the **existing** `CycleGenerationException`, reused
  as-is), nothing persisted — the outgoing cycle is untouched, still `active`.
- Guard, as a **Service** business rule (not Form Request validation): the
  routine must be the caller's `active` routine (`RoutineNotActiveException`,
  `409`). *(The "already generating" conflict from the original async draft
  no longer applies — see §9 "What the sync pivot removes".)*
- Rate limiting: `throttle:1,1` on the route — the first authenticated,
  per-user rate limit in this API. Still required (the AC's 429 criterion is
  independent of sync vs. async), and now doubles as the only practical
  defense against a rapid double-submit during the ~30-60s in-request AI call
  (see §9 "Race safety net").
- `App\Services\Cycle\ProgressionSummaryService` — pure PHP, no I/O beyond
  Eloquent reads, no AI. For every exercise prescribed in the **outgoing**
  cycle: prescribed vs. actual (avg weight/reps, max RPE) from sets logged in
  that cycle, a `performed` flag, and a weight trend + plateau signal derived
  from the exercise's last two *completed* sessions across the whole routine.
- `App\Services\Cycle\CyclePlannerService::planNextCycle(...)` — a second
  entry point (same DTOs, same agent) that additionally feeds the active
  recommendations and the progression summary into the prompt.
- `App\Services\Cycle\CycleDraftService::persistDays(Cycle, CyclePlanData)` —
  extracted from the existing `persist()` so the same day/exercise-writing
  logic can target a cycle row the calling Action creates itself (first cycle
  and N+1 differ only in who creates the `cycles` row and what
  `sequence_number`/status it starts with).
- `App\Actions\Cycle\CycleGenerateAction` — **one** synchronous Action, same
  shape as `RoutineCreateAction`: guard → plan (outside any transaction) →
  one transaction that creates the new cycle, persists its days, rolls the
  outgoing cycle to `completed`/`incomplete`, and marks trained-exercise
  recommendations `applied`.
- `App\Services\Cycle\CycleCompletionService` — "was this cycle's week fully
  trained" as its own small, reusable Service.
- `exercise_recommendations.status` — new column (`active` default /
  `applied`), backed by `App\Enums\Recommendation\RecommendationStatus`. Every
  recommendation write (new or re-analyzed) sets/resets it to `active`; the
  rollover sets it to `applied` for exercises trained in the outgoing cycle.
- `App\Services\Recommendation\RecommendationCatalogService::listCurrentForRoutine()`
  (shipped, PR #23) — now also filters `status = active`, so an `applied`
  recommendation stops appearing on `GET /api/v1/routines/{routine}/recommendations`.
- `App\Exceptions\Cycle\RoutineNotActiveException` (new); `CycleGenerationException`
  (shipped) reused as-is for a planner failure on the N+1 path, exactly as on
  the first-cycle path.
- Test coverage for every acceptance criterion.

**Out of scope:**
- Rate-of-generation validation ("Validar el ritmo de generación de ciclos",
  Order 410, `Later`) — the N+1 can be requested at any time; an incomplete
  outgoing week is a valid, expected outcome (`incomplete`), not blocked.
- Any endpoint or screen in `gym-trainer-spa/` (separate repository),
  including reconciling the "Generar ciclo con estado en vivo" (Order 210)
  screen's polling design with this endpoint's now-synchronous behavior (see
  "Adjusted during requirements gathering" above).
- Updating `docs/product-context.md` §4 step 7 / §6 to describe the
  synchronous flow (currently describes the async one). Flagged as a
  necessary follow-up, not bundled into this ticket to keep the diff focused
  on the API change itself — **confirm with the product owner whether to
  fold it into this same PR before implementation starts.**
- `App\Jobs\Cycle\GenerateCycleJob` — **deleted** in this ticket (see §9). If
  a future story needs async generation again, it is re-created then, not
  kept as a speculative stub (`CLAUDE.md` — no "we might need it later" code).
- A `hint` field on `POST /api/v1/routines/{routine}/cycles` — confirmed with
  the product owner: the endpoint always reuses `routine.hint` (the hint saved
  when the routine was created); it accepts no request body.
- A `confidence` field anywhere (recommendations or cycle prescriptions) —
  product decision already made in PR #21. "Low confidence" for an
  unperformed exercise is prompt guidance to the planner, not a persisted or
  returned field.
- Any DB-level guard against a genuine race between two concurrent `POST`
  calls for the same routine — see §9 "Race safety net".
- Any change to how the **first** cycle is generated (still
  `generate-first-cycle-spec.md`, untouched).

---

## 2. API Surface

### 2.1 REST

| Method | Path | Auth | Request | Response | Status codes |
|---|---|---|---|---|---|
| POST | `/api/v1/routines/{routine}/cycles` | `auth:sanctum` + `RoutinePolicy::generateCycle` + `throttle:1,1` | — (no body) | `{ "data": { "id": uuid, "sequence_number": int, "status": "active", "split_rationale": string, "generated_at": ISO-8601, "days": [ { "id": uuid, "order": 1..5, "label": string, "focus_muscle_groups": string[], "rationale": string, "exercises": [ { "id": uuid, "order": int, "name": string, "sets": int, "rep_min": int, "rep_max": int, "target_weight_kg": number, "target_rpe": number\|null, "rest_seconds": int, "rationale": string } ] } ] } }` | `201` created · `409` `ROUTINE_NOT_ACTIVE` (the routine is not the caller's active routine) · **`502` `AI_GENERATION_FAILED`** (planner call failed or returned an unusable plan — nothing persisted, outgoing cycle untouched) · `403` not the routine's owner · `404` unknown `{routine}` uuid · `429` `RATE_LIMIT_EXCEPTION` (more than 1 request/minute) · `401` unauthenticated · `419` stateful request without a valid CSRF token · **`500`** generic `SERVER_EXCEPTION` on the documented, accepted race-condition edge case only — see §9 "Race safety net" |
| GET | `/api/v1/routines/{routine}/recommendations` *(existing route, filter changes)* | unchanged | — | unchanged shape; the list now excludes `applied` recommendations | unchanged |

`GET /api/v1/routines/{routine}` is **unaffected** by this ticket — no
`pending_cycle` field, no change to `Routine::cycle()`. Because nothing is
ever persisted before the whole generation either fully succeeds or fully
fails, the routine's highest-`sequence_number` cycle is *always* its `active`
one (or, on a failed attempt, the previous `active` cycle, untouched) — the
same invariant the shipped `ofMany('sequence_number', 'max')` relation
already relies on. There is no in-flight state to observe, so no polling
mechanism is needed.

Notes:
- **No request body.** The controller takes only the route-bound `Routine`;
  there is no Form Request (mirrors `ShowRoutineController` /
  `ListRoutineRecommendationsController` — both are also body-less,
  `->can(...)` route-gated endpoints with no Form Request in this codebase).
  `routine.hint` (already stored) is what the planner uses.
- **Synchronous, same shape as the first cycle.** `CycleGenerateAction` calls
  `CyclePlannerService::planNextCycle(...)` **before** opening any
  transaction — the ~30-60s AI call (same `CyclePlannerAgent`,
  `#[Timeout(60)]`) happens in-request, exactly like `RoutineCreateAction`
  calling `planFirstCycle(...)`. On success, one transaction creates the new
  `cycles` row `active`, writes its 5 days, flips the outgoing cycle to
  `completed`/`incomplete`, and marks trained-exercise recommendations
  `applied`. The `201` carries the new cycle with its full nested tree.
- **All-or-nothing on failure**, identical to the first cycle: if
  `planNextCycle` throws, no transaction was opened — no new `cycles` row, no
  change to the outgoing cycle's status, no recommendation touched. The
  endpoint returns
  `{ "data": { "code": "AI_GENERATION_FAILED", "message": "The training plan could not be generated. Please try again." } }`
  with HTTP `502`; the client retries by re-sending the `POST`.
- **`409 ROUTINE_NOT_ACTIVE`.** Thrown when `$routine->status !==
  RoutineStatus::Active` — an archived routine (or, in practice, any routine
  that is not the caller's single active one, since ownership is already
  checked by the Policy) can never generate a cycle.
- `403` / `404` / `401` / `419` follow the exact pattern of every other
  `{routine}`-scoped route (`routines.show`, `routines.recommendations.list`).

### 2.2 CLI

Not applicable — no CLI commands. Generation runs in the HTTP request; no
queue worker is involved on this path.

### 2.3 Events

Not applicable — no domain events. `laravel/ai` emits its own internal
framework events when the agent runs (unchanged from the first-cycle path);
no listeners registered.

---

## 3. UI

### 3.1 Pages

Not applicable — no pages affected. The create-cycle screen lives in
`gym-trainer-spa/` (backlog Order 210, out of scope — see §1).

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
- Plain `string` column, no native PostgreSQL `enum` / `CHECK` constraint —
  matches the existing `athlete_profiles` / `routines` / `cycles` enum-storage
  convention (`generate-first-cycle-spec.md` §9 "Enum storage"): the
  `RecommendationStatus` cast plus application-level validation enforce
  membership, not the database.
- No `cycles` / `cycle_days` / `day_exercises` schema change — the rollover
  writes rows through the **existing** `CycleDraftService::persistDay()`
  (unchanged), onto a `cycles` row `CycleGenerateAction` creates through the
  existing schema. `CycleStatus` already has every case any path in this API
  uses; N+1 only ever writes `Active`, `Completed`, `Incomplete` (never
  `Generating` / `Failed` / `Draft` — those three stay reserved/unused, same
  as the first-cycle path).
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
| Authenticated user, owns the routine | May `POST /api/v1/routines/{routine}/cycles` for their own routine, subject to the `ROUTINE_NOT_ACTIVE` business guard. |
| Authenticated user, does not own the routine | `403` — never reaches the guard or the planner. |

New ability: `RoutinePolicy::generateCycle(User $user, Routine $routine): bool
=> $routine->user_id === $user->id` — identical shape to the existing
`RoutinePolicy::view`. Wired as route middleware:
`->can('generateCycle', 'routine')`, exactly like `routines.show` /
`routines.recommendations.list` (no Form Request `authorize()` — there is no
Form Request on this route).

The "must be the caller's `active` routine" rule is **not** authorization —
per `CLAUDE.md`, a state precondition is a business guard, not a Policy check
(same reasoning as `ProfileIncompleteException` in `RoutineCreateAction`, and
`RoutineNotActiveException` in `TrainingSessionOpeningService`, a different
domain's exception of the same name — see §9). It lives in
`CycleGenerateAction` and renders `409`, not `403`.

---

## 6. Configuration

Not applicable — no new environment variables, config files, or queue
connections. The planner reuses `CyclePlannerAgent` and
`config('training.cycle.exercises_per_day.*')` unchanged, and the same
`config('ai.default')` provider/model the first cycle already uses.

---

## 7. Current vs New Behavior

| Behavior | Current | New |
|---|---|---|
| `POST /api/v1/routines/{routine}/cycles` | Route does not exist. | Synchronously plans and rolls over cycle N+1 for the caller's active routine; `201` with the full new cycle, or `502` with nothing persisted. |
| `App\Jobs\Cycle\GenerateCycleJob` | `final implements ShouldQueue` stub; `handle()` empty; never dispatched; docblock points at this ticket. | **Deleted** — this ticket implements the story the stub was reserved for, synchronously, with no job. |
| `exercise_recommendations` | No `status` column; every row is implicitly "current". | `status` column, `active` by default; `SessionAnalyzeAction`'s `updateOrCreate` sets it to `active` on every write (including re-activating a row a prior rollover had set to `applied`); the rollover sets it to `applied` for exercises trained in the outgoing cycle. |
| `RecommendationCatalogService::listCurrentForRoutine()` | Returns every recommendation for the current cycle's exercises regardless of `status` (the column didn't exist). | Adds `->where('status', RecommendationStatus::Active)` — an `applied` recommendation no longer appears. *(No other change to this Service — `$routine->cycle` still correctly means "the active cycle": see §2.1's invariant note.)* |
| Rate limiting on an authenticated route | None exists anywhere in the API (`register`/`login` throttle by IP, pre-auth). | First authenticated per-user throttle: `throttle:1,1` on `POST /api/v1/routines/{routine}/cycles`. |

---

## 8. Test Cases

Executable with Pest 4 on SQLite `:memory:` (`RefreshDatabase`, already
wired). Feature tests use `$this->user = User::factory()->create()` +
`AthleteProfile::factory()->for($this->user)->create()` +
`$this->withHeader('Origin', config('app.url'))`, and the existing
`trainingRoutineWithCycle($user)` / `fakeCyclePlanner()` / `cyclePlanPayload()`
helpers from `tests/Helpers.php` unless a case needs a hand-built fixture. New
assertions use `assertJsonPath('data.code', …)` for errors (per
`domain-exception-handling-spec.md`) and `uuidV4Pattern()` / `iso8601Pattern()`
for shape checks.

### POST `/api/v1/routines/{routine}/cycles` — `tests/Feature/Cycle/GenerateCycleTest.php`

**TC-1:** Success — `201`, a real 5-day `active` cycle is returned and persisted (AC "genera el ciclo siguiente")
- **Given:** `$this->user` with `trainingRoutineWithCycle($this->user)` (active routine, active cycle `sequence_number = 1`, all 5 days trained via a `completed` session each); `fakeCyclePlanner()`
- **When:** `POST /api/v1/routines/{routine}/cycles`
- **Expect:** `201`; `data.status` = `"active"`, `data.sequence_number` = `2`, `data.split_rationale` non-empty, `data.generated_at` ISO-8601, `data.id` matches `uuidV4Pattern()`; `data.days` has 5 entries with `order` 1..5, each exercise carrying `name`/`sets`/`rep_min`/`rep_max`/`target_weight_kg`/`target_rpe`/`rest_seconds`/`rationale`; `assertDatabaseHas('cycles', ['routine_id' => $routine->id, 'sequence_number' => 2, 'status' => 'active'])`

**TC-2:** Success — the outgoing cycle rolls to `completed` when all 5 days were trained
- **Given:** as TC-1
- **When:** `POST /api/v1/routines/{routine}/cycles`
- **Expect:** `201`; the outgoing cycle (`sequence_number = 1`) reloaded — `status = "completed"`, `completed_at` non-null

**TC-3:** Success — the outgoing cycle rolls to `incomplete` when fewer than 5 days were trained
- **Given:** `trainingRoutineWithCycle($this->user)` with only 3 of the 5 days having a `completed` session; `fakeCyclePlanner()`
- **When:** `POST /api/v1/routines/{routine}/cycles`
- **Expect:** `201` (generation still succeeds); the outgoing cycle reloaded — `status = "incomplete"`, `completed_at` non-null

**TC-4:** Success — rollover still completes when the outgoing week was never trained at all
- **Given:** `trainingRoutineWithCycle($this->user)` with zero sessions against any of its 5 days; `fakeCyclePlanner()`
- **When:** `POST /api/v1/routines/{routine}/cycles`
- **Expect:** `201`; outgoing cycle `status = "incomplete"`; no `ExerciseRecommendation` becomes `applied` (nothing was trained)

**TC-5:** Success — recommendations for TRAINED exercises become `applied`; UNTRAINED ones stay `active` (AC "las recomendaciones usadas… quedan applied")
- **Given:** `trainingRoutineWithCycle($this->user)`; 2 of its exercises trained (completed sessions with sets), 1 not; an `ExerciseRecommendation` (`status: active`) for each of the 3 exercises; `fakeCyclePlanner()`
- **When:** `POST /api/v1/routines/{routine}/cycles`
- **Expect:** `201`; the 2 trained exercises' recommendations reloaded — `status = "applied"`; the untrained one's — still `status = "active"`

**TC-6:** Success — an already-`applied` recommendation for a trained exercise stays `applied` (idempotent)
- **Given:** as TC-5, but one trained exercise's recommendation is already `status: applied` (from a hypothetical prior rollover)
- **When:** `POST /api/v1/routines/{routine}/cycles`
- **Expect:** `201`; still `applied`, no error

**TC-7:** The prompt carries the profile, routine goal/hint, active recommendations and progression summary (AC "la IA recibe…")
- **Given:** `trainingRoutineWithCycle($this->user)` on a routine with a distinctive `goal` + `hint`; an `AthleteProfile` with distinctive `notes`; one trained exercise with an `active` recommendation (`action: advance_weight`, distinctive `explanation`); `fakeCyclePlanner()`
- **When:** `POST /api/v1/routines/{routine}/cycles`
- **Expect:** `201`; `CyclePlannerAgent::assertPrompted(fn (string $p) => str_contains($p, $profile->notes) && str_contains($p, $routine->goal->value) && str_contains($p, $routine->hint) && str_contains($p, 'advance_weight') && str_contains($p, $exercise->name))`

**TC-8:** The prompt guides the planner to hold on an unperformed exercise (AC "sin datos → mismo objetivo, baja confianza")
- **Given:** `trainingRoutineWithCycle($this->user)`; one of the outgoing cycle's exercises has zero logged sets; `fakeCyclePlanner()`
- **When:** `POST /api/v1/routines/{routine}/cycles`
- **Expect:** `201`; `CyclePlannerAgent::assertPrompted(fn (string $p) => str_contains($p, $unperformedExercise->name) && str_contains($p, 'Progression summary') && str_contains($p, 'performed: no') && str_contains($p, 'no data — keep the current target'))` — the literal marker strings (`'Progression summary'`, `'performed: no'`, `'no data — keep the current target'`) are the ones `buildNextCyclePrompt()` must emit verbatim (task 9); the test asserts on these fixed strings, not on whatever wording the implementation happens to produce

**TC-9:** Failure — planner throws → `502`, nothing persisted, outgoing cycle untouched (AC-equivalent to the first cycle's failure contract)
- **Given:** `trainingRoutineWithCycle($this->user)`; `CyclePlannerAgent::fake(fn () => throw new RuntimeException('provider unavailable'))`
- **When:** `POST /api/v1/routines/{routine}/cycles`
- **Expect:** `502`; `data.code` = `"AI_GENERATION_FAILED"`; `assertDatabaseCount('cycles', 1)` (unchanged — only the outgoing one exists); the outgoing cycle reloaded is still `status = "active"`; no `ExerciseRecommendation` changed

**TC-10:** Failure — a malformed plan (not 5 days) → `502`, nothing persisted
- **Given:** `trainingRoutineWithCycle($this->user)`; `fakeCyclePlanner(['days' => array_slice(cyclePlanPayload()['days'], 0, 4)])`
- **When:** `POST /api/v1/routines/{routine}/cycles`
- **Expect:** `502`; `assertDatabaseCount('cycles', 1)`; `assertDatabaseCount('cycle_days', 5)` (only the outgoing cycle's)

**TC-11:** Guard — routine is `archived` (not the caller's active routine) → `409` `ROUTINE_NOT_ACTIVE`
- **Given:** `$this->user` with an `archived` routine (`Routine::factory()->archived()->for($this->user)->create()`) carrying a `completed` cycle
- **When:** `POST /api/v1/routines/{that archived routine}/cycles`
- **Expect:** `409`; `data.code` = `"ROUTINE_NOT_ACTIVE"`; `assertDatabaseCount('cycles', 1)` (unchanged); `CyclePlannerAgent::assertNeverPrompted()`

**TC-12:** Rate limit — a second call within a minute → `429`
- **Given:** `trainingRoutineWithCycle($this->user)`; `fakeCyclePlanner()`
- **When:** `POST /api/v1/routines/{routine}/cycles` twice in a row
- **Expect:** first call `201`; second call `429`, `data.code` = `"RATE_LIMIT_EXCEPTION"`

**TC-13:** Rate limit is per user, not global
- **Given:** `$this->user` and `$other`, each with `trainingRoutineWithCycle(...)`; `fakeCyclePlanner()`
- **When:** `POST` as `$this->user`, then immediately `POST` as `$other` for `$other`'s own routine
- **Expect:** both `201`

**TC-14:** Ownership — a caller can't generate a cycle for another user's routine → `403`
- **Given:** `$other` with `trainingRoutineWithCycle($other)`; `actingAs($this->user)`
- **When:** `POST /api/v1/routines/{$other's routine}/cycles`
- **Expect:** `403`; `assertDatabaseCount('cycles', 1)` (unchanged); no cycle created for `$other`; `CyclePlannerAgent::assertNeverPrompted()`

**TC-15:** Unknown routine uuid → `404`
- **Given:** `actingAs($this->user)`
- **When:** `POST /api/v1/routines/00000000-0000-4000-8000-000000000000/cycles`
- **Expect:** `404`

**TC-16:** Unauthenticated → `401`
- **Given:** no authenticated session
- **When:** `POST /api/v1/routines/{any uuid}/cycles`
- **Expect:** `401`

**TC-17:** Response exposes uuids, never internal PKs
- **Given:** `trainingRoutineWithCycle($this->user)`; `fakeCyclePlanner()`
- **When:** `POST /api/v1/routines/{routine}/cycles`
- **Expect:** `data.id` and every `data.days[].id` / `data.days[].exercises[].id` match `uuidV4Pattern()`; `assertJsonMissingPath('data.routine_id')`; `assertJsonMissingPath('data.days.0.cycle_id')`

### `CycleGenerateAction` — `tests/Feature/Cycle/CycleGenerateActionTest.php`

*(The HTTP-level cases above already exercise this Action end-to-end; these
add cases that are awkward to assert through the controller — sequence
numbering across repeated calls, and the exact set of guard/plan/persist
steps in isolation.)*

**TC-18:** `handle()` plans, then creates the new cycle, persists its days, and rolls the outgoing cycle over — all in one transaction
- **Given:** a routine with an active cycle `sequence_number = 2`, all 5 days trained; `fakeCyclePlanner()`
- **When:** `app(CycleGenerateAction::class)->handle($routine)`
- **Expect:** returns a `Cycle` — `sequence_number = 3`, `status = Active`, `cycleDays`/`dayExercises`/`exercise` already loaded (no lazy load under `Model::shouldBeStrict()`); the prior `sequence_number = 2` cycle reloaded is `completed`

**TC-19:** `handle()` throws `RoutineNotActiveException` for an archived routine and writes nothing
- **Given:** an `archived` routine with a `completed` cycle; `fakeCyclePlanner()`
- **When:** `handle($routine)`
- **Expect:** throws `App\Exceptions\Cycle\RoutineNotActiveException` (`->statusCode() === 409`); `CyclePlannerAgent::assertNeverPrompted()`; `assertDatabaseCount('cycles', 1)`

**TC-20:** `handle()` throws `CycleGenerationException` and writes nothing when planning fails
- **Given:** a routine with an active cycle; `CyclePlannerAgent::fake(fn () => throw new RuntimeException('boom'))`
- **When:** `handle($routine)`
- **Expect:** throws `App\Exceptions\Cycle\CycleGenerationException` (`->statusCode() === 502`); the active cycle reloaded is unchanged; `assertDatabaseCount('cycles', 1)`

### `ProgressionSummaryService` — `tests/Unit/Cycle/ProgressionSummaryServiceTest.php`

**TC-21:** `summarize()` marks an exercise `performed = false` when zero sets were logged in the outgoing cycle (AC "prescrito con 0 series = false")
- **Given:** an outgoing `active` cycle with one `cycle_day`/`day_exercise`; no `TrainingSession` against that day
- **When:** `app(ProgressionSummaryService::class)->summarize($routine, $cycle)`
- **Expect:** one entry, `performed === false`, `actualAvgWeightKg === null`, `trend === 'insufficient_data'`

**TC-22:** `summarize()` computes `actual` averages from completed sessions' sets in the outgoing cycle
- **Given:** an outgoing cycle; a `completed` `TrainingSession` on its first day with 3 `SetLog` rows for the prescribed exercise (weights 40/42.5/42.5, reps 10/9/8, rpe 7/8/9)
- **When:** `->summarize($routine, $cycle)`
- **Expect:** that exercise's entry — `performed === true`, `actualAvgWeightKg === 41.67` (rounded), `actualAvgReps === 9.0`, `actualMaxRpe === 9.0`

**TC-23:** `summarize()` ignores sets from an `in_progress` (not yet completed) session
- **Given:** an outgoing cycle; an `in_progress` session with sets logged for its exercise, no `completed` session for that day
- **When:** `->summarize($routine, $cycle)`
- **Expect:** `performed === false`

**TC-24:** `summarize()` trend is `insufficient_data` with fewer than 2 completed sessions for the exercise across the whole routine
- **Given:** an exercise trained in exactly one `completed` session, ever
- **When:** `->summarize($routine, $cycle)`
- **Expect:** `trend === 'insufficient_data'`, `plateauSignal === false`

**TC-25:** `summarize()` trend is `up` / `down` / `flat` from the exercise's last two completed sessions, routine-wide (dataset)
- **Given:** dataset — two completed sessions for the same exercise (any cycle), average weights (a) `40 → 42.5` (b) `42.5 → 40` (c) `40 → 40`
- **When:** `->summarize($routine, $cycleContainingTheSecondSession)`
- **Expect:** (a) `trend === 'up'`, `plateauSignal === false`; (b) `trend === 'down'`, `plateauSignal === true`; (c) `trend === 'flat'`, `plateauSignal === true`

**TC-26:** `summarize()` only reports exercises prescribed in the outgoing cycle (a free-session exercise outside the plan is not summarized)
- **Given:** an outgoing cycle with 3 prescribed exercises; a `completed` free session (`cycle_day_id: null`) logging a 4th, unrelated exercise
- **When:** `->summarize($routine, $cycle)`
- **Expect:** exactly 3 entries, the free-session exercise absent

### `CycleCompletionService` — `tests/Unit/Cycle/CycleCompletionServiceTest.php`

**TC-27:** `wasCompleted()` is `true` only when every one of the cycle's 5 days has ≥1 completed session
- **Given:** dataset — (a) all 5 days have a completed session, (b) 4 of 5, (c) 5 of 5 but one day's only session is `in_progress`
- **When:** `app(CycleCompletionService::class)->wasCompleted($cycle)`
- **Expect:** (a) `true`; (b) `false`; (c) `false`

**TC-28:** `wasCompleted()` does not count a free session (`cycle_day_id: null`) toward any day
- **Given:** 5 days, 4 with a completed session tied to that `cycle_day_id`, plus one extra completed **free** session
- **When:** `wasCompleted($cycle)`
- **Expect:** `false`

### `CyclePlannerService::planNextCycle()` — extends `tests/Feature/Cycle/CyclePlannerServiceTest.php`

**TC-29:** `planNextCycle()` maps a well-formed response the same way `planFirstCycle()` does
- **Given:** `fakeCyclePlanner()`; a profile, `Goal`, `hint`, an empty recommendations collection, an empty progression summary
- **When:** `->planNextCycle($profile, $goal, $hint, collect(), [])`
- **Expect:** a `CyclePlanData` — same shape/assertions as TC-24 in `generate-first-cycle-spec.md`

**TC-30:** `planNextCycle()` applies the same malformed-shape validation as `planFirstCycle()` (dataset reused)
- **Given:** the same malformed-payload dataset as the first-cycle spec's TC-25
- **When:** `->planNextCycle(...)`
- **Expect:** every case throws `CycleGenerationException`

### `CycleDraftService::persistDays()` — extends `tests/Feature/Cycle/CycleDraftServiceTest.php`

**TC-31:** `persistDays()` writes days/exercises onto an existing cycle without touching the cycle row itself
- **Given:** a persisted `Cycle` (any status); a `CyclePlanData`
- **When:** `app(CycleDraftService::class)->persistDays($cycle, $plan)`
- **Expect:** `cycle_days` = 5, exercises match the plan; the `cycle` row's own columns (`status`, `sequence_number`, …) are unchanged — the caller sets those separately

### `RecommendationCatalogService` — extends `tests/Unit/Recommendation/RecommendationCatalogServiceTest.php`

**TC-32:** `listCurrentForRoutine()` excludes an `applied` recommendation
- **Given:** a routine with a current cycle containing one exercise; an `ExerciseRecommendation` for it with `status: applied`
- **When:** `->listCurrentForRoutine($routine)`
- **Expect:** `toHaveCount(0)`

### `GET /api/v1/routines/{routine}/recommendations` — extends `tests/Feature/Recommendation/ListRoutineRecommendationsTest.php`

**TC-33:** An `applied` recommendation is absent from the response
- **Given:** as TC-32, via the HTTP endpoint
- **When:** `GET /api/v1/routines/{routine}/recommendations`
- **Expect:** `200`; `data` is an empty array

**TC-34:** A recommendation re-activated by a new session analysis reappears after having been `applied`
- **Given:** an `ExerciseRecommendation` with `status: applied`; a new completed session analyzed for the same exercise (`fakeSessionAnalyst()`)
- **When:** the session-close flow runs, then `GET /api/v1/routines/{routine}/recommendations`
- **Expect:** the recommendation reloaded is `status: active`; it appears in the response

### Architecture — extends `tests/Feature/ArchTest.php`

**TC-35:** New classes obey existing conventions
- **Given:** the classes under `App\Services\Cycle`, `App\Actions\Cycle` and `App\Http\Controllers\Cycle` added by this ticket
- **When:** `vendor/bin/pest tests/Feature/ArchTest.php` runs
- **Expect:** `App\Services\Cycle\ProgressionSummaryService`, `CycleCompletionService` are `final`; `App\Actions\Cycle\CycleGenerateAction` is `final` with a `handle()` method (covered by the existing blanket `App\Actions` rule); `App\Http\Controllers\Cycle` is invokable (new rule, mirrors the other controller-namespace rules).

---

## 9. Technical Decisions

| Decision area | What was decided | Why |
|---|---|---|
| **Sync vs. async (the pivot)** | Cycle N+1 generation is **fully synchronous**, inside `POST /api/v1/routines/{routine}/cycles` — no queued job, no `generating`/`failed` `Cycle` row, no polling. Same all-or-nothing contract as the first cycle: success persists everything atomically, failure persists nothing. | Product decision this session (see §1 "Adjusted during requirements gathering"), explicitly overriding the Notion AC and `docs/product-context.md`'s described async flow. Reusing the first cycle's already-shipped, already-tested pattern verbatim removes an entire category of state (`generating`, `failed`, a job, a "which cycle is really active right now" ambiguity) for a comparable AI-call latency (~30-60s either way). |
| What the sync pivot removes | Relative to the original (async) draft of this spec: `App\Jobs\Cycle\GenerateCycleJob` (deleted, not kept as a stub — see below); `App\Actions\Cycle\CycleRolloverAction` (merged into `CycleGenerateAction` — there is no longer a separate "job-invoked" step); `App\Exceptions\Cycle\CycleGenerationInProgressException` and its `409 CYCLE_GENERATION_IN_PROGRESS` (no persisted `generating` state ever exists to conflict with); `Routine::pendingCycle()` and `RoutineResource.pending_cycle` (no in-flight state to poll); the planned redefinition of `Routine::cycle()` and the "fix" to `TrainingSessionOpeningService` / `RecommendationCatalogService` (both were only needed because a `generating`/`failed` N+1 cycle could otherwise **outrank** the active one by `sequence_number` while shadowing it in `$routine->cycle` — that scenario can no longer occur, since nothing is written until the whole generation succeeds). | Each of these existed to manage a state (an in-flight or failed cycle sitting alongside the still-active one) that the synchronous design never creates. Keeping any of them would be exactly the kind of "we might need it" indirection `CLAUDE.md` rule 6 rejects. |
| `GenerateCycleJob` fate | **Deleted**, not kept as an unused stub. | The stub's own (shipped) docblock says it is "the seam for" this exact story. Implementing that story synchronously means the seam is now provably dead code with a misleading docblock — `CLAUDE.md`: "no speculative generality, no 'we might need it later'". If async generation is wanted later, it is a new, deliberate ticket that re-introduces the job against the requirements of that ticket, not a stub carried forward on spec. |
| 409 conflict check (superseded) | The original AC's "if a draft/generating cycle already exists, respond 409" no longer applies — there is no persisted intermediate state for a second request to collide with. A rapid double-submit is instead handled by the rate limit (below) and, in the rare true-concurrency case, by the existing `cycles` `(routine_id, sequence_number)` unique index (see "Race safety net"). | Direct consequence of the sync pivot. Documented explicitly (rather than silently dropped) since it changes a testable AC from the source ticket — same kind of deviation `generate-first-cycle-spec.md` already made for its own AC set when it moved the first cycle from async to sync. |
| `hint` on this endpoint | Not accepted. The planner always uses `routine.hint` (the value saved at routine creation). | Confirmed with the product owner (unaffected by the sync pivot). The AC only says the AI receives "goal/hint de la rutina" (the routine's own, already-stored hint). |
| `exercise_recommendations.status` | New column added in this ticket (`active` default / `applied`), even though two prior PRs (#21, #23) explicitly deferred it "until a consumer exists". `SessionAnalyzeAction` sets it to `active` on every write (new or re-analyzed); `RecommendationCatalogService` (shipped) is updated to filter `status = active`. | This ticket is that consumer — unaffected by sync vs. async. Confirmed with the product owner: once `applied` exists, the shipped "vigentes" endpoint must stop showing `applied` rows or it becomes wrong. Re-activating on every analysis (rather than only on create) is necessary because `updateOrCreate` **overwrites** the same row keyed by `(user, routine, exercise)` — a fresh analysis after a rollover must make that exercise's recommendation current again. |
| Rate limit | `throttle:1,1` (1 request/minute), keyed automatically by the authenticated user id (Laravel's `ThrottleRequests` resolves the signature from `$request->user()?->getAuthIdentifier()` when present — this route is always behind `auth:sanctum`). No named `RateLimiter::for(...)` registered; the inline `throttle:1,1` form matches the existing `register`/`login` precedent (`throttle:6,1`). | Confirmed with the product owner; kept through the sync pivot because the AC's 429 criterion is independent of the transport mechanism, and — now more than before — it is the primary guard against a double-submit racing the in-request AI call (see "Race safety net"). Notably, `generate-first-cycle-spec.md` explicitly *deferred* rate limiting on the analogous first-cycle endpoint; this ticket keeps it because the AC for **this** story explicitly requires it, not because the sync shape demands it. |
| `RoutineNotActiveException` identity | `final extends App\Exceptions\DomainException`, `Cycle` domain folder (`app/Exceptions/Cycle/`), `statusCode = 409`, own default message. A **new**, Cycle-domain-scoped class — not a reuse of the existing `App\Exceptions\Session\RoutineNotActiveException` (same rule, different domain). | `CLAUDE.md` layout (`Exceptions/{Domain}/…`) — each domain owns the exceptions its own guard clauses throw, even for a conceptually-shared rule, matching the codebase's "folders by domain" convention. |
| `CycleGenerationException` reuse | The **existing** exception (`AI_GENERATION_FAILED`, `502`) is reused as-is for a `planNextCycle()` failure — no new exception, and it now behaves *identically* to the first-cycle path (thrown synchronously in-request, not caught by a job's `failed()`). | It already means exactly "the planner call failed or returned an unusable plan"; the sync pivot makes its use on this path a straight copy of the first-cycle path rather than a job-boundary special case. Keeping the same class means `CyclePlannerService`'s validation logic is shared verbatim by both `planFirstCycle()` and `planNextCycle()`. |
| Guard placement | The "routine must be active" check is a **private method on `CycleGenerateAction`** (`ensureRoutineActive(Routine): Cycle`, returning the routine's active cycle via `Cycle::query()->where('routine_id', $routine->id)->where('status', Active)->sole()`), **not** a separate Service class. | With the "already generating" branch gone, the guard is down to one condition — creating a dedicated `CycleGenerationGuardService` for a single `throw_unless` would be exactly the "class that only adds indirection" `CLAUDE.md` rule 6 rejects. Mirrors the closest existing precedent directly: `RoutineCreateAction::ensureOnboardingComplete()` is *also* a private method on the Action, not a separate Service, for the same reason (`ProfileIncompleteException`'s guard). `sole()` is deliberate: by this point the routine is confirmed active, so zero or >1 active cycles is an invariant violation, not a case to handle gracefully — it surfaces as a loud 404/500 rather than defensive code for a state the rest of the system guarantees can't happen. |
| `CycleCompletionService` | New `final` Service, one method: `wasCompleted(Cycle $cycle): bool` — `$cycle->cycleDays->every(fn (CycleDay $day) => TrainingSession::where('cycle_day_id', $day->id)->where('status', SessionStatus::Completed)->exists())`. | Kept as its own Service (unlike the guard above) because it's an independently meaningful business rule ("what does 'the week was completed' mean") — not a trivial one-liner, and one `CycleGenerateAction` merely *calls*, matching the `SessionCompletionService` precedent: a Service the Action reads top-to-bottom as one step, not query logic buried inline. |
| `ProgressionSummaryService` scope | Summarizes **only** the exercises prescribed in the **outgoing** cycle's `day_exercises` (one entry per distinct `exercise_id`, first occurrence if it somehow appears on two days). A free-session exercise outside that set is not summarized. Unaffected by the sync pivot. | Matches the AC framing ("resumen de progresión por ejercicio", tied to what the outgoing week prescribed) and mirrors `RecommendationCatalogService`'s existing "only exercises in the current cycle" scoping. |
| `performed` flag | `true` iff at least one `SetLog` exists for that exercise, logged against a `completed` `TrainingSession` whose `cycle_day_id` belongs to the outgoing cycle. | Direct translation of the source story's own note: "`performed: true/false` (prescrito con 0 series = false)". Restricting to `completed` sessions matches `CycleCompletionService`'s definition of "trained". |
| Trend & plateau signal | Computed **routine-wide** (not outgoing-cycle-only) from the exercise's last two `completed` sessions ever, ordered by `completed_at`: average logged weight per session, compared pairwise → `up` / `down` / `flat` / `insufficient_data` (fewer than 2 sessions). `plateauSignal = true` when `trend` is `down`, or `flat` with 2+ sessions on record. | A single outgoing cycle (one week) rarely trains the same exercise twice, so a same-cycle "trend" would almost always be `insufficient_data` — the useful signal needs the exercise's last two *sessions*, which may span cycles. This is a deliberately simple, defensible MVP algorithm — **flagged for scrutiny during spec review**: if a different look-back window or plateau threshold is wanted, this is the section to revise before implementation starts. |
| `CyclePlannerService::planNextCycle()` | A second public method on the **existing** Service (not a new Service, not a new Agent class) — same `CyclePlanData` DTO tree, same validation helpers (`mapPlan`/`mapDay`/`mapExercise`/`requireString`/`requireInt`, shared with `planFirstCycle()`), a **different** prompt-builder method (`buildNextCyclePrompt`) that adds an "Active recommendations" section and a section literally headed `"Progression summary"` — one line per exercise, `"performed: yes"` / `"performed: no"`, and, for every `performed: no` line, the literal instruction `"no data — keep the current target"` appended — on top of the same profile/goal/hint block `planFirstCycle()` already builds. These exact marker strings are load-bearing: TC-8 asserts on them verbatim rather than on the implementation's own wording. `CyclePlannerAgent::instructions()` is reworded to be neutral to "first week" vs. "continuation using recent training data, when the prompt includes it" (it no longer states "the FIRST training week"). | The structured-output **schema** (5 days, full per-exercise prescription) is identical between the first cycle and N+1 — only the prompt content differs. A second Agent class would duplicate ~60 lines of schema/strict-mode boilerplate for the same JSON shape (`CLAUDE.md` rule 6). |
| `CycleDraftService::persistDays()` | The existing `persist()` (first-cycle path — creates the `cycles` row itself, hardcoded `sequence_number = 1`/`Active`) is **left untouched**. A new `persistDays(Cycle $cycle, CyclePlanData $plan): void` is extracted from its day-writing loop and reused by both `persist()` (internally) and `CycleGenerateAction` (directly, against the `cycles` row it creates for N+1). No transaction opened by either method — the caller owns it, unchanged convention. | The N+1 path creates its `cycles` row with different starting values (`sequence_number = N+1`, computed at call time) than the first cycle's hardcoded `1` — splitting the method is the minimal change that avoids duplicating the day/exercise-writing loop, and leaves the first-cycle path's tested behavior completely unchanged. |
| `CycleGenerateAction` | `final`, `handle(Routine $routine): Cycle` — `$outgoingCycle = $this->ensureRoutineActive($routine)` (throws `RoutineNotActiveException`) → gather profile/active-recommendations/`$summary = $progression->summarize($routine, $outgoingCycle)` → `$plan = $this->planner->planNextCycle(...)` (**outside** any transaction — external call, same rule as `RoutineCreateAction`/`SessionAnalyzeAction`) → `DB::transaction`( create the new `cycles` row `active` with `sequence_number = $outgoingCycle->sequence_number + 1` → `$draft->persistDays($newCycle, $plan)` → flip `$outgoingCycle` to `completed`/`incomplete` per `$completion->wasCompleted($outgoingCycle)` → bulk-update `ExerciseRecommendation` to `applied` for exercise ids where `$summary[...]->performed === true` ) → return `$newCycle->load('cycleDays.dayExercises.exercise')`. | One Action = one use case, reading top-to-bottom as the story of "generate the next cycle" (`CLAUDE.md` rule 2/6) — the same shape as `RoutineCreateAction`, just with a rollover instead of an archive-and-create. This is the merge point of what the async draft split across `CycleGenerateAction` (row creation) and `CycleRolloverAction` (planning + rollover) — with no job boundary between them, there is no reason to keep them as two classes. |
| `App\Http\Controllers\Cycle\GenerateCycleController` | `final`, `__invoke(Routine $routine, CycleGenerateAction $action): JsonResponse { return CycleResource::make($action->handle($routine))->response()->setStatusCode(Response::HTTP_CREATED); }`. No Form Request (no input to validate) — same shape as `ShowRoutineController` / `ListRoutineRecommendationsController` and, now, `StoreRoutineController`'s own `201` pattern. | `CLAUDE.md` "Controller" — ~3 lines, owns only the HTTP status code. |
| Route registration | `Route::post('routines/{routine}/cycles', GenerateCycleController::class)->whereUuid('routine')->middleware('throttle:1,1')->can('generateCycle', 'routine')->name('routines.cycles.store');`, inside the existing `auth:sanctum` group, placed after the recommendations route (same `{routine}`-scoped block). | Matches the existing route-file conventions exactly (`whereUuid`, `->can(...)` middleware, a comment on the block explaining the gate) — see `routines.recommendations.list` immediately above it in `routes/api.php`. |
| Recommendation write on session analysis | `SessionAnalyzeAction`'s `ExerciseRecommendation::updateOrCreate(...)` payload gains `'status' => RecommendationStatus::Active` explicitly (previously the column didn't exist). Unaffected by the sync pivot. | A fresh analysis always makes that exercise's recommendation current again, even if a prior rollover had marked it `applied`. Explicit rather than relying on the column's DB default, since `updateOrCreate` on an **existing** row does not re-apply column defaults — only a genuine `INSERT` would. |
| Race safety net | No new DB constraint (partial unique index, advisory lock) against two truly concurrent `POST` calls for the same routine both passing the guard before either commits. The **existing** `cycles` `(routine_id, sequence_number)` unique index is the safety net: a genuine race computes the same `sequence_number` for both and the loser's insert fails with a DB unique-constraint violation — surfaced as an unhandled `QueryException` → generic `500`, not a clean `409`. | Accepted as an explicit MVP gap, not silently ignored. `throttle:1,1` already narrows the double-submit window to true concurrency (two requests processed at the same instant, not merely close together) — same-tab double-clicks are the realistic case, and this is exactly the same posture `generate-first-cycle-spec.md` already took for the (also synchronous, also AI-call-in-request) first-cycle endpoint, which has no lock at all. A partial unique index plus catching the resulting `QueryException` is a reasonable follow-up if this proves to matter in practice, deliberately deferred per `CLAUDE.md` "simplicity first". |
| Migration / DB isolation | One migration on a `-T gym_trainer` clone `gym_trainer_generate_next_cycle`; `.env` `DB_DATABASE` repointed in the worktree, reverted on merge. Pest stays SQLite `:memory:`. | `CLAUDE.md` "Workflows — database isolation". |
| Scramble docs | The new `201`/`502` route is inferred automatically from `CycleResource`'s return type and the thrown exceptions; no `#[...]` attribute needed. | `CLAUDE.md` "API documentation" — the pipeline stays typed, so Scramble needs no help. |
| Git artifacts | Branch `worktree-generate-next-cycle` (already checked out and pushed); English only; **no** `Co-Authored-By: Claude` / `Claude-Session:` trailers on commits, no "Generated with Claude Code" footer anywhere; single PR, spec-first (this document merges to the PR before any implementation commit). | Repo `CLAUDE.md` / `AGENTS.md` "Git" rules take precedence over any conflicting session-level attribution instruction — same precedent as every prior spec in `docs/plans/`. |

---

## 10. Work Plan

Inner-most first (migration → enum → exception → Services → Action →
controller/route → tests). Each task's DoD is the artifact existing,
`vendor/bin/pint --dirty` + `vendor/bin/phpstan analyse` (level 6) clean, and
— where the class carries logic — its focused test, authored in the same
task.

| # | Task | Definition of Done |
|---|---|---|
| 1 | Clone the runtime DB: `docker compose exec pgsql createdb -U gym -T gym_trainer gym_trainer_generate_next_cycle`; set `DB_DATABASE=gym_trainer_generate_next_cycle` in this worktree's `.env`. | `php artisan db:show` targets the clone; `gym_trainer` untouched; Pest still SQLite. |
| 2 | Delete `App\Jobs\Cycle\GenerateCycleJob` and its (empty) test coverage, if any. | `grep -r GenerateCycleJob app tests` returns nothing; `composer check` still green (nothing referenced it). |
| 3 | Create the migration adding `exercise_recommendations.status` (string, default `active`) per §4.1. Create `App\Enums\Recommendation\RecommendationStatus` (`Active`/`Applied`). | `php artisan migrate` runs on the clone and fresh SQLite; `RecommendationStatus::from('active')` works. |
| 4 | Update `App\Models\ExerciseRecommendation`: add `status` to `casts()` (→ `RecommendationStatus::class`) and `#[Fillable]`; refresh PHPDoc (`php artisan ide-helper:models --write` + hand-check). Update `database/factories/ExerciseRecommendationFactory.php` (`status` default `Active`, add an `applied()` state). | Pint + PHPStan clean; `ExerciseRecommendation::factory()->applied()->create()->status === RecommendationStatus::Applied`. |
| 5 | Create `App\Exceptions\Cycle\RoutineNotActiveException` per §9. | `->statusCode() === 409`; Pint + PHPStan clean. |
| 6 | Create `App\Data\Cycle\ExerciseProgressionData` (readonly DTO) and `App\Services\Cycle\ProgressionSummaryService::summarize(Routine, Cycle): array` per §9. Write `tests/Unit/Cycle/ProgressionSummaryServiceTest.php` (TC-21…TC-26). | `vendor/bin/pest tests/Unit/Cycle/ProgressionSummaryServiceTest.php` green; Pint + PHPStan clean. |
| 7 | Create `App\Services\Cycle\CycleCompletionService::wasCompleted(Cycle): bool` per §9. Write `tests/Unit/Cycle/CycleCompletionServiceTest.php` (TC-27, TC-28). | `vendor/bin/pest tests/Unit/Cycle/CycleCompletionServiceTest.php` green; Pint + PHPStan clean. |
| 8 | Extract `App\Services\Cycle\CycleDraftService::persistDays(Cycle, CyclePlanData): void` from the existing `persist()`'s day-writing loop; `persist()` calls it internally. Extend `tests/Feature/Cycle/CycleDraftServiceTest.php` (TC-31); confirm the existing first-cycle tests still pass unmodified. | `vendor/bin/pest tests/Feature/Cycle/CycleDraftServiceTest.php` green (old + new); Pint + PHPStan clean. |
| 9 | Add `CyclePlannerService::planNextCycle(AthleteProfile, Goal, ?string, Collection $recommendations, array $progressionSummary): CyclePlanData` + `buildNextCyclePrompt(...)` private helper, sharing the existing `mapPlan`/`mapDay`/`mapExercise`/`require*` private methods with `planFirstCycle()`. Reword `App\Ai\Agents\Cycle\CyclePlannerAgent::instructions()` per §9 (drop "the FIRST training week" framing). Extend `tests/Feature/Cycle/CyclePlannerServiceTest.php` (TC-29, TC-30). | `vendor/bin/pest tests/Feature/Cycle/CyclePlannerServiceTest.php` green (old + new); Pint + PHPStan clean; existing first-cycle tests still pass. |
| 10 | Create `App\Actions\Cycle\CycleGenerateAction::handle(Routine): Cycle` per §9 (guard → gather → plan → transactional rollover). Write `tests/Feature/Cycle/CycleGenerateActionTest.php` (TC-18…TC-20). | `vendor/bin/pest tests/Feature/Cycle/CycleGenerateActionTest.php` green; Pint + PHPStan clean. |
| 11 | Add `RoutinePolicy::generateCycle(User, Routine): bool` per §5.2. Create `App\Http\Controllers\Cycle\GenerateCycleController` per §9. Register the route in `routes/api.php` per §9 (after `routines.recommendations.list`). Write `tests/Feature/Cycle/GenerateCycleTest.php` (TC-1…TC-17). | `vendor/bin/pest tests/Feature/Cycle/GenerateCycleTest.php` green; `php artisan route:list` shows `POST routines/{routine}/cycles`; Pint + PHPStan clean. |
| 12 | Update `App\Services\Recommendation\RecommendationCatalogService::listCurrentForRoutine()` to add `->where('status', RecommendationStatus::Active)`, per §7/§9. Update `App\Actions\Session\SessionAnalyzeAction`'s `updateOrCreate` payload to set `'status' => RecommendationStatus::Active` explicitly. Extend `tests/Unit/Recommendation/RecommendationCatalogServiceTest.php` (TC-32) and `tests/Feature/Recommendation/ListRoutineRecommendationsTest.php` (TC-33, TC-34). | Both updated test files green; the full existing `tests/Feature/Session` and `tests/Feature/Recommendation` suites still pass (no regression). |
| 13 | Add the `App\Http\Controllers\Cycle` invokable rule to `tests/Feature/ArchTest.php` (TC-35); confirm the `App\Services\Cycle` / `App\Actions\Cycle` additions already pass the existing blanket `App\Services` final / `App\Actions` final+handle() rules. | `vendor/bin/pest tests/Feature/ArchTest.php` green. |
| 14 | `vendor/bin/pint --dirty`, then `vendor/bin/phpstan analyse`, then a final `php artisan ide-helper:models --write` + `vendor/bin/pint app/Models`. | Pint no diffs; PHPStan level 6 clean; `ExerciseRecommendation` / `Cycle` PHPDoc in sync. |
| 15 | `docker compose exec app composer check` (Pint `--test` + PHPStan + full Pest — every suite, not just the new files, to catch any regression from the recommendation-status filter or the deleted job). | All three green; no regression anywhere in the suite. |
| 16 | Manual live check against `http://localhost:8000` with a real `AI_PROVIDER_API_KEY`: create a routine (first cycle), complete all 5 days with sets, `POST /api/v1/routines/{routine}/cycles` → `201` with a real second week; confirm the outgoing cycle is `completed`; confirm `GET /routines/{routine}/recommendations` no longer lists the exercises just rolled over as `applied`; review `GET /docs/api` for the new route. | `data.sequence_number` on the response is the outgoing cycle's `sequence_number + 1`, `data.status = "active"`, `data.days` has 5 entries with real prescriptions; a DB check confirms the outgoing cycle row is `status = completed`; `GET /routines/{routine}/recommendations` no longer lists the exercises trained that week; `GET /docs/api` lists `POST routines/{routine}/cycles` with `201`/`409`/`502`/`429` among its documented responses. |
| 17 | On merge / branch drop: `docker compose exec pgsql dropdb -U gym --if-exists gym_trainer_generate_next_cycle`; revert `DB_DATABASE` in the worktree `.env`. | The clone is gone; `.env` restored to `gym_trainer`. |

*Process note: branch name, commit messages and PR text follow `CLAUDE.md` /
`AGENTS.md` — English only, no `Co-Authored-By: Claude` / `Claude-Session:`
trailers, no "Generated with Claude Code" footer. Per the user's request for
this ticket, the PR opened from this branch carries **only this spec
document** in its first commit(s) — implementation (tasks 1-17 above) is
added to the same PR only after the user has reviewed and confirmed this
spec, possibly after one or more revision rounds.*
