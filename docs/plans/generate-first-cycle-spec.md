# Generate the first cycle synchronously on routine creation

> Derived from the Notion ticket "Recibir el primer ciclo apenas creo una rutina"
> (Feature: Ciclos & generación IA · MVP · Must · Repo: API · Order 60) — rewritten
> in this session so first-cycle generation is **synchronous and all-or-nothing**:
> it runs inside `POST /api/v1/routines`, and if it fails the routine is not
> created and the incumbent active routine is not archived. Base contract:
> `docs/product-context.md` §2 / §4 (steps 2–3) / §5 / §7, `docs/plans/data-model.md`
> §`cycles` / §`cycle_days` / §`day_exercises` / §`exercises` / §Identificadores /
> §Enums, `docs/plans/create-routine-spec.md` (the merged story this ticket
> **reopens**), `docs/plans/domain-exception-handling-spec.md`, and `CLAUDE.md`
> "The pipeline".

## 1. Context

**Kind:** Brownfield Feature (extends the merged `POST /api/v1/routines`)

**Stack:** PHP 8.5 · Laravel 13 · PostgreSQL 17 (runtime) / SQLite `:memory:`
(tests) · Pest 4 (`pest-plugin-laravel`) · `laravel/ai` 0.6.8 (structured-output
agents) · `spatie/laravel-data` 4.23 · `laravel/sanctum` 4 (SPA cookie mode) ·
`dedoc/scramble` 0.13 · Pint · Larastan level 6. Everything runs in Docker.

**Problem statement:** `POST /api/v1/routines` (merged, PR #8) creates the user's
single `active` routine, archives any prior one **permanently**, and dispatches a
placeholder `GenerateCycleJob`. The Cycle domain does not exist. Product decision
this session: the first weekly cycle is generated **synchronously, in the same
request**, by an AI planner that reads the athlete profile + the routine's
`goal`/`hint`. On success the `201` response carries the routine **and** its
first cycle as a `draft` with 5 fully-prescribed days. On failure **nothing is
persisted** — no routine row, no archival of the incumbent — and the endpoint
returns `502 AI_GENERATION_FAILED`; the client retries by re-sending the `POST`.
There is no `generating` state and no `failed` cycle on this path: the first
cycle is born `draft` or not at all.

**In scope:**
- The Cycle-domain schema — `exercises`, `cycles`, `cycle_days`, `day_exercises`
  tables + `App\Models\Exercise` / `Cycle` / `CycleDay` / `DayExercise` +
  factories, exactly per `docs/plans/data-model.md` (no extra columns).
- `App\Enums\Cycle\CycleStatus` (`generating`, `draft`, `active`, `completed`,
  `failed` — the full documented lifecycle) and `App\Enums\Shared\MuscleGroup`
  (the `data-model.md` §Enums list).
- `App\Ai\Agents\Cycle\CyclePlannerAgent` — a `laravel/ai` agent implementing
  `Laravel\Ai\Contracts\Agent` + `HasStructuredOutput` (`Promptable`), running on
  `config('ai.default')` (`anthropic`) with `#[UseCheapestModel]`
  (`claude-haiku-4-5-*`) and a 60 s timeout.
- `App\Services\Cycle\CyclePlannerService::planFirstCycle(AthleteProfile, Goal, ?string $hint): CyclePlanData`
  — builds the prompt, invokes the agent, validates the returned shape, maps it
  to the DTO tree. Throws `App\Exceptions\Cycle\CycleGenerationException` on a
  provider failure or an unusable response.
- `App\Services\Cycle\CycleDraftService::persist(Routine, CyclePlanData): Cycle`
  — writes the `cycles` (`draft`) + `cycle_days` + `day_exercises` rows from a
  plan DTO, resolving each exercise through `ExerciseCatalogService`. Opens **no**
  transaction (its caller does).
- `App\Services\Exercise\ExerciseCatalogService::resolve(string $name, ?string $muscleGroupHint): Exercise`
  — slug-normalises an AI exercise name and `firstOrCreate`s the global row;
  `Log::info` on a probable near-duplicate (no alias table in v1).
- `App\Exceptions\Cycle\CycleGenerationException extends App\Exceptions\DomainException`
  — `errorCode` `AI_GENERATION_FAILED`, HTTP `502`. Rendered by the existing
  `ApiExceptionRenderer` `DomainException` branch — no renderer change.
- `App\Data\Cycle\CyclePlanData` / `CyclePlanDayData` / `CyclePlanExerciseData`
  — the typed plan the planner returns and the draft service consumes.
- `App\Http\Resources\Cycle\CycleResource` / `CycleDayResource` /
  `DayExerciseResource` — the nested cycle payload embedded in the `201`.
  `docs/plans/…` Order 80 ("Ver el detalle de un ciclo") is thereby reduced to
  adding a `GET` route + Policy + route-model binding that reuse these Resources.
- **Reopening the merged create-routine story** in this PR:
  - `App\Actions\Routine\RoutineCreateAction` — no longer dispatches
    `GenerateCycleJob`; instead calls `CyclePlannerService` **before** its
    transaction, then inside the transaction archives the incumbent, inserts the
    routine, and calls `CycleDraftService::persist(...)`. Returns the routine with
    `cycle.cycleDays.dayExercises` eager-loaded.
  - `App\Http\Resources\Routine\RoutineResource` — adds a `cycle` key
    (`CycleResource::make($this->whenLoaded('cycle'))`).
  - `App\Models\Routine` — adds a `cycle(): HasOne` relation
    (`hasOne(Cycle::class)->ofMany('sequence_number', 'max')`) and `cycles(): HasMany`.
  - `docs/plans/create-routine-spec.md` — updated for the synchronous behaviour,
    the `502` status, the nested response, and the revised test list.
  - `tests/Feature/Routine/StoreRoutineTest.php` +
    `tests/Feature/Routine/RoutineCreateActionTest.php` — reworked: the
    `Bus::fake()` / `GenerateCycleJob` assertions are replaced with the planner
    fake, nested-cycle assertions, and the failure-path cases.
- `App\Jobs\Cycle\GenerateCycleJob` — **kept as a documented stub**, its docblock
  now pointing at the on-demand cycle-N+1 story (Order 150), which remains
  asynchronous. No longer dispatched anywhere.
- A test AI-fake helper (`fakeCyclePlanner()` + `cyclePlanPayload()`) in
  `tests/Pest.php`; Pest coverage of every acceptance criterion; new
  `tests/Feature/ArchTest.php` rules for the new namespaces.
- `docs/plans/data-model.md` §Enums — mark `CycleStatus` / `MuscleGroup` as
  shipped (drop the "*(a validar)*" / "primera lista" caveats).

**Out of scope:**
- **Asynchronous / queued** first-cycle generation. It is fully synchronous now;
  `GenerateCycleJob` is neither dispatched nor implemented here.
- Any `generating` or `failed` **cycle row** on the first-cycle path. Those
  `CycleStatus` values stay in the enum for the future async N+1 path (Order 150)
  but nothing in this ticket writes them. No `failed_at` / `failure_reason`
  columns are added (AC is "nothing is persisted on failure").
- A retry **endpoint**. Retry = re-send `POST /api/v1/routines` (idempotent from
  the client's view: the failed attempt left no rows).
- `GET /api/v1/cycles/{cycle}` and its Policy / route-model binding (Order 80) —
  this ticket ships the Resources it will reuse, not the route.
- Cycle **activation** (`draft` → `active`, previous cycle → `completed`, the "1
  active + 1 draft per routine" guard) — Order 90.
- `ProgressionSummaryService`, exercise recommendations, and everything specific
  to cycle **N+1** (Orders 130 / 150). This ticket only builds
  `sequence_number = 1` with **no** prior training history.
- Populating `cycles.conversation_id` (the nullable column exists per
  `data-model.md`; not set in v1).
- Native PostgreSQL `enum` types or `CHECK` constraints — enum columns are plain
  `string`, matching `athlete_profiles` / `routines`.
- Rate limiting on `POST /api/v1/routines` (the synchronous AI call raises the
  cost of the endpoint, but throttling it is deferred — matches the profile
  routes; the login/register throttles exist only because they are public).
- The `gym-trainer-spa/` frontend (separate repository).

---

## 2. API Surface

### 2.1 REST

The route is the **existing** `POST /api/v1/routines` in the `auth:sanctum`
group under `apiPrefix: 'api/v1'`; this ticket changes its behaviour and response
body, not its path, method or auth.

| Method | Path | Auth | Request | Response | Status codes |
|---|---|---|---|---|---|
| POST | `/api/v1/routines` | `auth:sanctum` (session cookie, `web` guard) + `RoutinePolicy::create` | JSON: `name` (string, required, ≤255), `goal` (string, required, one of `hypertrophy` / `strength` / `fat_loss` / `general_health` / `endurance`), `hint` (string, optional/nullable, ≤2000; blank → `null`) | `{ "data": { …routine fields…, "cycle": { "id": uuid, "sequence_number": 1, "status": "draft", "split_rationale": string, "generated_at": ISO-8601, "days": [ { "id": uuid, "order": 1..5, "label": string, "focus_muscle_groups": string[], "rationale": string, "exercises": [ { "id": uuid, "order": int, "name": string, "sets": int, "rep_min": int, "rep_max": int, "target_weight_kg": number, "target_rpe": number\|null, "rest_seconds": int, "rationale": string } ] } ] } } }` | `201` created (routine + cycle) · `422` validation · `409` `PROFILE_INCOMPLETE` (no athlete profile) · **`502` `AI_GENERATION_FAILED`** (planner call failed or returned an unusable plan — nothing persisted) · `401` unauthenticated · `419` stateful request without a valid CSRF token |

Routine fields in `data` are unchanged from `create-routine-spec.md` §2.1:
`id` (uuid), `name`, `goal`, `hint`, `days_per_cycle` (`5`), `status`
(`"active"`), `archived_at` (`null`), `created_at`, `updated_at`.

Notes:
- **Synchronous generation.** `RoutineCreateAction` calls
  `CyclePlannerService::planFirstCycle($user->athleteProfile, $data->goal, $data->hint)`
  **before** opening any transaction. The agent runs in-request (`#[UseCheapestModel]`,
  60 s timeout). On success, one transaction archives the incumbent active
  routine, inserts the new routine `active`, and writes the cycle tree; the `201`
  carries the routine with `cycle` embedded.
- **All-or-nothing on failure.** If `planFirstCycle` throws, no transaction was
  opened: no routine row, and the incumbent active routine is **untouched** (still
  `active`, `archived_at` still `null`). The endpoint returns
  `{ "data": { "code": "AI_GENERATION_FAILED", "message": "The training plan could not be generated. Please try again." } }`
  with HTTP `502`, no `errors` key.
- **What counts as a failure:** the provider call errors or times out; the
  structured response is missing keys; the plan is not exactly 5 days; a day has
  0 exercises; `rep_min > rep_max`; `sets < 1`; `target_weight_kg` is
  missing/null/negative on the first cycle; a `focus_muscle_groups` entry is not
  a `MuscleGroup`. All surface as `CycleGenerationException` → `502`.
- **`target_weight_kg` is always numeric on the first cycle** — the agent
  estimates a starting load from `experience_level` + `notes`; the planner
  service rejects a null/absent weight. The DB column stays nullable for later
  cycles / bodyweight moves.
- `days_per_cycle` is still fixed at `5` and not accepted from the request
  (`create-routine-spec.md`).
- `403` still never occurs in practice (`RoutinePolicy::create` returns `true`);
  `auth:sanctum` yields `401` first for an unauthenticated call.
- CSRF is auto-bypassed under tests (`ValidateCsrfToken::runningUnitTests()`).

### 2.2 CLI

Not applicable — no CLI commands. Generation runs in the HTTP request; there is
no queue worker involved on this path.

### 2.3 Events

Not applicable — no domain events. `RoutineCreateAction` no longer dispatches
`GenerateCycleJob` (or anything). `laravel/ai` emits internal framework events
(`PromptingAgent`, `AgentPrompted`) when the agent runs; the app registers no
listeners and adds none.

---

## 3. UI

### 3.1 Pages

Not applicable — no pages affected. The create-routine and cycle-review screens
live in `gym-trainer-spa/` (backlog Orders 200 / 220).

### 3.2 Components

Not applicable — no components affected.

---

## 4. Database

### 4.1 Schema changes

Four migrations, anonymous classes (`return new class extends Migration`),
following the `database/migrations` house style (`$table->id()`,
`$table->timestamps()`, `constrained()->cascadeOnDelete()`), enum values stored
as plain `string`. Order and semantics follow `docs/plans/data-model.md`
verbatim — **no** extra columns.

| Table | Action | Details |
|---|---|---|
| `exercises` | Create | `id` bigint PK · `uuid` uuid **`unique`** (filled by `HasPublicUuid`) · `name` string · `slug` string **`unique`** · `primary_muscle_group` string **nullable** (a `MuscleGroup` value) · `created_by_ai` boolean **default `true`** · `created_at` / `updated_at`. No `user_id`; never targeted by a cascade. |
| `cycles` | Create | `id` bigint PK · `uuid` uuid **`unique`** · `routine_id` bigint FK → `routines.id`, `constrained()->cascadeOnDelete()` · `sequence_number` `unsignedInteger` · `status` string (a `CycleStatus` value) · `split_rationale` text **nullable** · `conversation_id` string(36) **nullable** (not populated in v1) · `generated_at` / `activated_at` / `completed_at` `timestamp` **nullable** · `created_at` / `updated_at`. **Unique** `(routine_id, sequence_number)`. |
| `cycle_days` | Create | `id` bigint PK · `uuid` uuid **`unique`** · `cycle_id` bigint FK → `cycles.id`, `constrained()->cascadeOnDelete()` · `order` `unsignedSmallInteger` · `label` string · `focus_muscle_groups` **`json`** (array of `MuscleGroup` values) · `created_at` / `updated_at`. **Unique** `(cycle_id, order)`. |
| `day_exercises` | Create | `id` bigint PK · `uuid` uuid **`unique`** · `cycle_day_id` bigint FK → `cycle_days.id`, `constrained()->cascadeOnDelete()` · `exercise_id` bigint FK → `exercises.id`, `constrained()` (**no** cascade — `restrict`) · `order` `unsignedSmallInteger` · `sets` `unsignedSmallInteger` · `rep_min` `unsignedSmallInteger` · `rep_max` `unsignedSmallInteger` · `target_weight_kg` `decimal(6,2)` **nullable** · `target_rpe` `decimal(3,1)` **nullable** · `rest_seconds` `unsignedSmallInteger` · `rationale` text · `created_at` / `updated_at`. **Unique** `(cycle_day_id, order)`. |

Notes:
- `focus_muscle_groups` uses `$table->json(...)` (not `jsonb`): portable across
  PostgreSQL 17 and SQLite `:memory:`; the Eloquent `array` cast behaves
  identically for the whole-array replace pattern. `data-model.md` says `jsonb` —
  documented deviation.
- `exercise_id` is `constrained()` with **no** cascade → DB default `RESTRICT`: a
  catalogued exercise can never be deleted from under a prescription
  (`data-model.md` §Convenciones).
- The only DB invariants are the two composite uniques + `exercises.slug` unique.
  "One `active` + one `draft` per routine" is an activation-story guard (Order
  90), not here.
- `sequence_number` `unsignedInteger`; this ticket only ever writes `1`.
- No soft deletes; no `failed_at` / `failure_reason` (a failed generation
  persists nothing).
- `down()` per migration: `Schema::dropIfExists(<table>)` in reverse FK order
  (`day_exercises`, `cycle_days`, `cycles`, `exercises`).
- Migration timestamps sort after `2026_09_02_130000_create_routines_table` and
  run `exercises` → `cycles` → `cycle_days` → `day_exercises`.
- **Doc update** — `docs/plans/data-model.md` §Enums: drop the "*(a validar)*"
  note on `MuscleGroup` and the "*(primera lista)*" caveat; both enums are now
  real. §`cycles` is unchanged (no new columns).
- **Database isolation (`CLAUDE.md`):** this branch adds migrations, so:
  `docker compose exec pgsql createdb -U gym -T gym_trainer gym_trainer_generate_first_cycle`,
  set `DB_DATABASE=gym_trainer_generate_first_cycle` in the worktree `.env`; drop
  the clone and revert `.env` on merge. The Pest suite is unaffected (SQLite
  `:memory:`).

### 4.2 Seeds

Not applicable — no seeds. The `exercises` catalogue is filled at runtime by
`ExerciseCatalogService` (`created_by_ai = true`); no curated seed in v1.

---

## 5. Auth & Authorization

### 5.1 Authentication

**Method:** unchanged — Laravel Sanctum SPA / stateful mode, `web` session guard.
`auth:sanctum` on the route group; an unauthenticated request throws
`AuthenticationException` → `401` JSON. CSRF applies to the `POST` (`419`
otherwise; auto-bypassed under tests).

### 5.2 Authorization

Unchanged from `create-routine-spec.md` §5.2. `RoutinePolicy::create(User): bool`
returns `true`; wired via `StoreRoutineRequest::authorize()`. The route carries no
`{routine}` segment; the routine (and now its cycle) is always created for
`$request->user()`. No new Policy, Gate, ability or middleware. The synchronous
AI call introduces no actor and reads no `auth()` beyond the already-resolved
`$request->user()`.

The onboarding precondition (the user must have an athlete profile) stays a
**business guard** in `RoutineCreateAction` (`ProfileIncompleteException` →
`409`), not an authorization check — unchanged.

---

## 6. Configuration

**Environment variables:**

| Variable | Value / Source | Purpose |
|---|---|---|
| `DB_DATABASE` | `gym_trainer_generate_first_cycle` (this worktree's `.env` only, during development) | Points the worktree at a clone of `gym_trainer` so the four new migrations never run against the shared database. Reverted on merge. |
| `AI_PROVIDER` | `anthropic` (already in `.env.example`; unchanged) | `config('ai.default')` — the provider `CyclePlannerAgent` resolves. |
| `ANTHROPIC_API_KEY` | already in `.env.example` (empty by default; unchanged) | Needed only for a live manual check; the Pest suite fakes the agent. |

No new `.env.example` keys. `phpunit.xml` already sets `DB_CONNECTION=sqlite`,
`DB_DATABASE=:memory:`, `QUEUE_CONNECTION=sync`, `SANCTUM_STATEFUL_DOMAINS`,
`APP_URL`; `RefreshDatabase` is wired for `Feature` in `tests/Pest.php`.

**Config files / docs modified:**

| File | Change |
|---|---|
| `docs/plans/create-routine-spec.md` | Rewrite the parts that assumed an async job: §1 in/out of scope, §2.1 (nested `cycle` in the response, `502` status), §2.3 (no dispatch), §7, §8 (test list), §9 (drop "Job dispatch position", revise "GenerateCycleJob"), §10 tasks. |
| `docs/plans/data-model.md` | §Enums — mark `CycleStatus` / `MuscleGroup` shipped. |
| `tests/Pest.php` | Add `fakeCyclePlanner(array $overrides = [])` + `cyclePlanPayload(array $overrides = [])` global helpers. |
| `tests/Feature/ArchTest.php` | Add `arch()` rules: `App\Services` final; `App\Ai\Agents` final. (`App\Actions` rule already covers `App\Actions\Cycle` should any land there.) |

No change to `config/ai.php`, `config/queue.php`, `config/data.php`,
`bootstrap/app.php`, `bootstrap/providers.php`, `routes/api.php`, `phpunit.xml`,
`composer.json`, `app/Exceptions/ApiExceptionRenderer.php`,
`app/Enums/Shared/ErrorCode.php`.

---

## 7. Current vs New Behavior

| Behavior | Current (post-PR #8) | New |
|---|---|---|
| `POST /api/v1/routines` on success | `201` with the routine only; `GenerateCycleJob` queued; routine has no cycles until the (stub) job runs. | `201` with the routine **and** a nested `cycle` (`draft`, 5 days, full prescription + rationales), all written in one transaction. No job. |
| `POST /api/v1/routines` when generation fails | N/A (stub job can't fail). | `502` `AI_GENERATION_FAILED`; **no** routine row created; a prior `active` routine stays `active` (not archived). |
| `RoutineCreateAction` | Guard → `DB::transaction`(archive incumbent → insert routine → `GenerateCycleJob::dispatch`). | Guard → `CyclePlannerService::planFirstCycle(...)` (pre-transaction, may throw `CycleGenerationException`) → `DB::transaction`(archive incumbent → insert routine → `CycleDraftService::persist(routine, plan)`) → return routine with `cycle.cycleDays.dayExercises` loaded. |
| `GenerateCycleJob` | Empty stub, dispatched on create, docblock points to this ticket. | Empty stub, **not dispatched**, docblock points to the on-demand cycle-N+1 ticket (Order 150). |
| Cycle domain | No tables / models / enums. | `exercises` / `cycles` / `cycle_days` / `day_exercises` + `HasPublicUuid` models + factories; `App\Enums\Cycle\CycleStatus`, `App\Enums\Shared\MuscleGroup`. |
| AI usage | Installed, unused; no agent classes. | `App\Ai\Agents\Cycle\CyclePlannerAgent` (structured output) wrapped by `CyclePlannerService`; runs in-request; tests fake it. |
| Exercise catalogue | No table. | `ExerciseCatalogService` slug-normalises AI names, `firstOrCreate`s a shared `exercises` row, reuses on slug match, logs a probable near-duplicate. |
| Error envelope | `ApiExceptionRenderer` handles `ValidationException` / auth / `DomainException` / … | Unchanged code. A new `DomainException` subclass (`CycleGenerationException`, `502`, `AI_GENERATION_FAILED`) rides the existing `DomainException` branch. |
| `RoutineResource` | Routine scalar fields only. | Adds `cycle` = `CycleResource::make($this->whenLoaded('cycle'))` (omitted when the relation is not loaded, e.g. the future list endpoint). |
| `Routine` model | `user()`, (planned) `cycles()`. | `cycles(): HasMany` + `cycle(): HasOne` (`ofMany('sequence_number', 'max')`) + PHPDoc. |
| Tests | `StoreRoutineTest` (18), `RoutineCreateActionTest`, `RoutineDataTest`, `RoutinePolicyTest`; `Bus::fake()` used for the job. | `StoreRoutineTest` / `RoutineCreateActionTest` reworked (planner fake, nested-cycle + `502` cases, no `Bus`). New: `tests/Feature/Cycle/CyclePlannerServiceTest.php`, `CycleDraftServiceTest.php`, `tests/Feature/Exercise/ExerciseCatalogServiceTest.php`; `ArchTest` rules. |

---

## 8. Test Cases

Executable with Pest 4 on SQLite `:memory:` (`RefreshDatabase`, already wired).
Every feature test's `beforeEach` sets `$this->withHeader('Origin', config('app.url'))`
and calls `fakeCyclePlanner()` (so no test hits a real provider); authenticated
cases add `$this->user = User::factory()->create()` and, unless the case is about
the missing-profile path, `AthleteProfile::factory()->for($this->user)->create()`.
Helpers in `tests/Pest.php`:

```php
function cyclePlanPayload(array $overrides = []): array
{
    $ex = fn (string $name, string $group) => [
        'name' => $name, 'primary_muscle_group' => $group,
        'sets' => 3, 'rep_min' => 8, 'rep_max' => 12,
        'target_weight_kg' => 40.0, 'target_rpe' => 7.0,
        'rest_seconds' => 90, 'rationale' => "Start moderate on {$name}.",
    ];
    $day = fn (string $label, array $groups, array $exercises) => [
        'label' => $label, 'focus_muscle_groups' => $groups,
        'day_rationale' => "Focus on {$label}.", 'exercises' => $exercises,
    ];

    return array_replace_recursive([
        'split_rationale' => 'Five-day split for hypertrophy.',
        'days' => [
            $day('Chest', ['chest', 'triceps'], [$ex('Barbell Bench Press', 'chest'), $ex('Incline Dumbbell Press', 'chest')]),
            $day('Back', ['back', 'biceps'], [$ex('Barbell Row', 'back'), $ex('Lat Pulldown', 'back')]),
            $day('Legs', ['quads', 'glutes'], [$ex('Back Squat', 'quads'), $ex('Romanian Deadlift', 'hamstrings')]),
            $day('Shoulders', ['shoulders'], [$ex('Overhead Press', 'shoulders'), $ex('Lateral Raise', 'shoulders')]),
            $day('Arms', ['biceps', 'triceps'], [$ex('Barbell Curl', 'biceps'), $ex('Triceps Pushdown', 'triceps')]),
        ],
    ], $overrides);
}

function fakeCyclePlanner(array $overrides = []): void
{
    \App\Ai\Agents\Cycle\CyclePlannerAgent::fake([cyclePlanPayload($overrides)]);
}
```

### POST `/api/v1/routines` — `tests/Feature/Routine/StoreRoutineTest.php` (reworked)

**TC-1:** Success — routine created `active` with a nested `draft` cycle of 5 days (AC #1, #3)
- **Given:** an authenticated user with an athlete profile and no routine; `fakeCyclePlanner()`
- **When:** `POST /api/v1/routines` with the base valid payload
- **Expect:** `201`; `data.status` = `"active"`, `data.days_per_cycle` = `5`; `data.cycle.status` = `"draft"`, `data.cycle.sequence_number` = `1`, `data.cycle.split_rationale` non-empty, `data.cycle.generated_at` ISO-8601; `data.cycle.days` has 5 entries with `order` 1..5 and the expected `label`s; each day's `focus_muscle_groups` is a non-empty array of strings; every day's `exercises[]` entry has `name`, `sets`, `rep_min`, `rep_max`, `target_weight_kg` (number, non-null), `target_rpe`, `rest_seconds`, `rationale`; `assertDatabaseCount('routines', 1)`, `assertDatabaseCount('cycles', 1)`, `assertDatabaseCount('cycle_days', 5)`, `assertDatabaseCount('day_exercises', 10)`

**TC-2:** Success — the previous active routine is archived permanently (AC — from create-routine, still holds)
- **Given:** a user with a profile and `Routine::factory()->for($user)->create()` (active); `fakeCyclePlanner()`
- **When:** `POST /api/v1/routines`
- **Expect:** `201`; the prior routine reloaded is `status = "archived"` with non-null `archived_at`; exactly one `active` routine for the user; `assertDatabaseCount('routines', 2)`, `assertDatabaseCount('cycles', 1)` (only the new routine has a cycle)

**TC-3:** Failure — planner throws → `502`, nothing persisted (AC #4-new)
- **Given:** a user with a profile and no routine; `CyclePlannerAgent::fake(fn () => throw new RuntimeException('provider unavailable'))`
- **When:** `POST /api/v1/routines` with the base valid payload
- **Expect:** `502`; `assertJsonPath('data.code', 'AI_GENERATION_FAILED')`; `assertJsonMissingPath('data.errors')`; `assertDatabaseCount('routines', 0)`; `assertDatabaseCount('cycles', 0)`; `assertDatabaseCount('cycle_days', 0)`

**TC-4:** Failure — the incumbent active routine is NOT archived when generation fails (AC #4-new)
- **Given:** a user with a profile and `Routine::factory()->for($user)->create()` (active); capture its id; a throwing planner fake
- **When:** `POST /api/v1/routines`
- **Expect:** `502`; the incumbent reloaded is still `status = "active"`, `archived_at` = `null`; `assertDatabaseCount('routines', 1)`; `assertDatabaseCount('cycles', 0)`

**TC-5:** Failure — malformed plan (not 5 days) → `502`, nothing persisted (AC #3, #4-new)
- **Given:** a user with a profile; `fakeCyclePlanner(['days' => array_slice(cyclePlanPayload()['days'], 0, 4)])`
- **When:** `POST /api/v1/routines`
- **Expect:** `502`; `data.code` = `"AI_GENERATION_FAILED"`; `assertDatabaseCount('routines', 0)`, `assertDatabaseCount('cycles', 0)`

**TC-6:** Failure — a day with 0 exercises → `502`
- **Given:** a user with a profile; `fakeCyclePlanner()` with the last day's `exercises` set to `[]`
- **When:** `POST /api/v1/routines`
- **Expect:** `502`; nothing persisted

**TC-7:** Failure — `rep_min > rep_max` → `502`
- **Given:** a user with a profile; `fakeCyclePlanner()` with the first exercise overridden to `rep_min = 12, rep_max = 8`
- **When:** `POST /api/v1/routines`
- **Expect:** `502`; nothing persisted

**TC-8:** Failure — missing / null `target_weight_kg` on the first cycle → `502` (AC — always numeric)
- **Given:** a user with a profile; `fakeCyclePlanner()` with one exercise's `target_weight_kg` set to `null`
- **When:** `POST /api/v1/routines`
- **Expect:** `502`; nothing persisted

**TC-9:** The prompt carries the athlete profile and the routine `goal` + `hint` (AC #2-new)
- **Given:** `AthleteProfile::factory()->for($user)->create(['experience_level' => ExperienceLevel::Intermediate, 'days_per_week' => 5, 'session_minutes' => 60, 'goal' => Goal::Strength, 'notes' => 'Left shoulder impingement, avoid heavy overhead pressing.'])`; payload `goal = 'hypertrophy'`, `hint = 'PPL split, dumbbells only'`; `fakeCyclePlanner()`
- **When:** `POST /api/v1/routines`
- **Expect:** `201`; `CyclePlannerAgent::assertPrompted(fn (string $p) => str_contains($p, 'Left shoulder impingement') && str_contains($p, 'intermediate') && str_contains($p, '60') && str_contains($p, 'hypertrophy') && str_contains($p, 'PPL split, dumbbells only'))` — the routine `goal`, not only the profile `goal`, reaches the prompt

**TC-10:** No athlete profile → `409` `PROFILE_INCOMPLETE`, planner never called, nothing persisted
- **Given:** an authenticated user with no `athlete_profiles` row
- **When:** `POST /api/v1/routines` with the base valid payload
- **Expect:** `409`; `data.code` = `"PROFILE_INCOMPLETE"`; `CyclePlannerAgent::assertNeverPrompted()`; `assertDatabaseCount('routines', 0)`, `assertDatabaseCount('cycles', 0)`

**TC-11:** Validation failure → `422`, planner never called (AC #1 — shape first)
- **Given:** an authenticated user with a profile
- **When:** `POST /api/v1/routines` missing `name` (dataset also: `goal = 'powerlifting'`)
- **Expect:** `422`; `assertJsonValidationErrors(..., 'data.errors')`; `CyclePlannerAgent::assertNeverPrompted()`; `assertDatabaseCount('routines', 0)`

**TC-12:** `hint` omitted / blank → planner runs, prompt has no empty hint, routine `hint` is `null`
- **Given:** a user with a profile; base payload minus `hint` (dataset: `hint = ''`, `hint = '   '`); `fakeCyclePlanner()`
- **When:** `POST /api/v1/routines`
- **Expect:** `201`; `data.hint` = `null`; `assertDatabaseHas('routines', ['hint' => null])`; `CyclePlannerAgent::assertPrompted(fn (string $p) => ! str_contains($p, 'Hint:') && ! str_contains($p, 'null'))`

**TC-13:** Every valid `goal` value is accepted and reaches generation
- **Given:** a user with a profile and no routine; `fakeCyclePlanner()`
- **When:** `POST /api/v1/routines` with `goal` = each of the five enum values (dataset)
- **Expect:** `201`; `data.goal` matches; `data.cycle.status` = `"draft"`

**TC-14:** `name` / `hint` length boundaries (unchanged from create-routine)
- **Given:** a user with a profile; `fakeCyclePlanner()`
- **When:** `name` = 255 then 256 chars; `hint` = 2000 then 2001 chars
- **Expect:** 255 / 2000 → `201`; 256 / 2001 → `422` on the field

**TC-15:** `days_per_cycle` in the body is ignored (unchanged)
- **Given:** a user with a profile; `fakeCyclePlanner()`
- **When:** `POST /api/v1/routines` with `days_per_cycle = 3`
- **Expect:** `201`; `data.days_per_cycle` = `5`; `assertDatabaseHas('routines', ['days_per_cycle' => 5])`

**TC-16:** Response exposes uuids, never internal PKs (AC #3)
- **Given:** a user with a profile; `fakeCyclePlanner()`
- **When:** `POST /api/v1/routines`
- **Expect:** `data.id`, `data.cycle.id`, every `data.cycle.days[].id` and `data.cycle.days[].exercises[].id` match the UUID regex; `assertJsonMissingPath('data.cycle.routine_id')`; `assertJsonMissingPath('data.cycle.days.0.cycle_id')`

**TC-17:** Cross-user isolation — a create never touches another user's routine/cycle
- **Given:** `$other` with a profile and `Routine::factory()->for($other)->create()` (active); `actingAs($this->user)` (also with a profile); `fakeCyclePlanner()`
- **When:** `POST /api/v1/routines`
- **Expect:** `201`; `$other`'s routine still `active`, `archived_at` null; `$other` has no `cycles` row; `assertDatabaseCount('cycles', 1)`

**TC-18:** Strict-mode render guard — the nested Resource never triggers a lazy load
- **Given:** a user with a profile; `fakeCyclePlanner()`
- **When:** `POST /api/v1/routines`
- **Expect:** `201`, no `500`; the JSON contains the full `cycle.days[].exercises[]` tree (proving `RoutineCreateAction` eager-loaded `cycle.cycleDays.dayExercises`)

**TC-19:** Exercise catalogue — repeated names insert once, slugged, `created_by_ai` (AC #3)
- **Given:** a user with a profile; `fakeCyclePlanner()` (its payload names `Barbell Bench Press` on two days)
- **When:** `POST /api/v1/routines`
- **Expect:** `assertDatabaseHas('exercises', ['slug' => 'barbell-bench-press', 'created_by_ai' => true])`; exactly one row for that slug; distinct `exercises` rows = distinct names in the payload

**TC-20:** Exercise catalogue — an existing row is reused across requests
- **Given:** `Exercise::factory()->create(['name' => 'Bench Press', 'slug' => 'barbell-bench-press', 'created_by_ai' => false])`; a user with a profile; `fakeCyclePlanner()`
- **When:** `POST /api/v1/routines`
- **Expect:** `201`; still one row `slug = 'barbell-bench-press'`, its `name` / `created_by_ai` unchanged; the matching `day_exercises.exercise_id` points at it

### Action — `tests/Feature/Routine/RoutineCreateActionTest.php` (reworked)

**TC-21:** `handle()` plans, then archives the incumbent, inserts the routine and persists the cycle tree
- **Given:** a `User` with an `AthleteProfile` and no routine; `fakeCyclePlanner()`; a valid `RoutineData`
- **When:** `app(RoutineCreateAction::class)->handle($user, $data)`, then again with a different `name`
- **Expect:** after call 1 — `assertDatabaseCount('routines', 1)` (`active`, `days_per_cycle = 5`), `assertDatabaseCount('cycles', 1)` (`draft`, `sequence_number = 1`), `cycle_days` = 5, `day_exercises` = 10; the return value is a `Routine` with `cycle`, `cycle.cycleDays` and `cycle.cycleDays.dayExercises` already loaded (`$routine->relationLoaded('cycle')` etc.); after call 2 — routine 1 is `archived` with `archived_at`, routine 2 is `active` with its own `draft` cycle, `assertDatabaseCount('cycles', 2)`

**TC-22:** `handle()` throws `CycleGenerationException` and writes nothing when planning fails
- **Given:** a `User` with an `AthleteProfile`; `CyclePlannerAgent::fake(fn () => throw new RuntimeException('boom'))`
- **When:** `app(RoutineCreateAction::class)->handle($user, $data)`
- **Expect:** throws `App\Exceptions\Cycle\CycleGenerationException` (`->statusCode() === 502`, `->errorCode() === 'AI_GENERATION_FAILED'`); `assertDatabaseCount('routines', 0)`, `assertDatabaseCount('cycles', 0)`

**TC-23:** `handle()` for a user with no profile throws `ProfileIncompleteException` before planning
- **Given:** a `User` with no `AthleteProfile`; `fakeCyclePlanner()`
- **When:** `handle($user, $data)`
- **Expect:** throws `App\Exceptions\Profile\ProfileIncompleteException`; `CyclePlannerAgent::assertNeverPrompted()`; `assertDatabaseCount('routines', 0)`

### Planner service — `tests/Feature/Cycle/CyclePlannerServiceTest.php`

*(Under `tests/Feature/` — needs factories + the container; `RefreshDatabase` is wired only for `Feature`.)*

**TC-24:** `planFirstCycle()` maps a well-formed structured response into the DTO tree
- **Given:** `fakeCyclePlanner()`; an `AthleteProfile` (factory); `app(CyclePlannerService::class)`
- **When:** `->planFirstCycle($profile, Goal::Hypertrophy, 'PPL')`
- **Expect:** a `CyclePlanData` — `splitRationale` matches; `days` is a 5-element collection of `CyclePlanDayData`; each `->exercises` a collection of `CyclePlanExerciseData` with typed `string $name`, `?string $primaryMuscleGroup`, `int $sets`, `int $repMin`, `int $repMax`, `float $targetWeightKg`, `?float $targetRpe`, `int $restSeconds`, `string $rationale`

**TC-25:** `planFirstCycle()` throws `CycleGenerationException` on each malformed shape
- **Given:** `app(CyclePlannerService::class)`, a valid `AthleteProfile`; a dataset of faked payloads — 4 days; 6 days; a day with `exercises = []`; `rep_min > rep_max`; `sets = 0`; `target_weight_kg` null; `focus_muscle_groups` with an unknown value; a missing top-level key
- **When:** `->planFirstCycle($profile, Goal::Strength, null)` for each
- **Expect:** every case throws `App\Exceptions\Cycle\CycleGenerationException` with `->statusCode() === 502`; the message names the offending rule

**TC-26:** `planFirstCycle()` throws `CycleGenerationException` when the agent itself throws
- **Given:** `CyclePlannerAgent::fake(fn () => throw new RuntimeException('timeout'))`; `app(CyclePlannerService::class)`
- **When:** `->planFirstCycle($profile, Goal::Strength, null)`
- **Expect:** throws `App\Exceptions\Cycle\CycleGenerationException`; the original message is wrapped / included

**TC-27:** `planFirstCycle()` builds the prompt from every profile field + routine `goal`/`hint`
- **Given:** `fakeCyclePlanner()`; a profile with distinctive values
- **When:** `->planFirstCycle($profile, Goal::Hypertrophy, 'dumbbells only')`
- **Expect:** `CyclePlannerAgent::assertPrompted(fn (string $p) => ...)` asserting `experience_level`, `days_per_week`, `session_minutes`, `notes`, the routine `goal` and `hint` all appear, plus the "exactly 5 days" and "kilograms" instructions

### Draft service — `tests/Feature/Cycle/CycleDraftServiceTest.php`

**TC-28:** `persist()` writes the cycle, 5 days and all prescriptions from a plan DTO
- **Given:** a persisted `Routine`; a hand-built `CyclePlanData` (or `cyclePlanPayload()` mapped through `CyclePlanData`); `app(CycleDraftService::class)`
- **When:** `DB::transaction(fn () => $service->persist($routine, $plan))`
- **Expect:** returns a `Cycle` with `status = CycleStatus::Draft`, `sequence_number = 1`, non-null `generated_at`, `split_rationale` set; `cycle_days` count = 5 with `order` 1..5 and typed `focus_muscle_groups` arrays; `day_exercises` count = sum of the plan's exercises, each field copied from the DTO; every `exercise_id` resolves to an `exercises` row

**TC-29:** `persist()` reuses catalogue rows and does not open its own transaction
- **Given:** a pre-existing `Exercise` matching one plan slug; a plan naming the same exercise twice
- **When:** `$service->persist($routine, $plan)` **without** a wrapping transaction
- **Expect:** the pre-existing exercise row is reused (not duplicated); the rows are written (proving no implicit rollback); one `exercises` row per distinct slug

### Exercise catalogue service — `tests/Feature/Exercise/ExerciseCatalogServiceTest.php`

**TC-30:** `resolve()` normalises a name to a slug and creates the row
- **Given:** an empty `exercises` table; `app(ExerciseCatalogService::class)`
- **When:** `->resolve('Press de Banca Inclinado', 'chest')`
- **Expect:** one row `slug = 'press-de-banca-inclinado'`, `name = 'Press de Banca Inclinado'`, `primary_muscle_group = 'chest'`, `created_by_ai = true`; returns that `Exercise`

**TC-31:** `resolve()` is case- / accent- / whitespace-insensitive on the slug key
- **Given:** `Exercise::factory()->create(['slug' => 'barbell-bench-press', 'name' => 'Barbell Bench Press'])`
- **When:** `->resolve('  BARBELL  Bench  Préss ', null)` (dataset: `'barbell bench press'`, `'Barbell-Bench-Press'`)
- **Expect:** no new row; the existing `Exercise` returned unchanged; count stays 1

**TC-32:** `resolve()` with an unknown muscle-group hint stores `null`
- **Given:** an empty table
- **When:** `->resolve('Sled Push', 'conditioning')`
- **Expect:** one row, `primary_muscle_group = null`; no exception

**TC-33:** `resolve()` rejects a blank name
- **Given:** an empty table
- **When:** `->resolve('   ', 'chest')` (dataset: `''`)
- **Expect:** throws `InvalidArgumentException`; no row created

**TC-34:** `resolve()` logs a probable near-duplicate but still returns the new row
- **Given:** `Exercise::factory()->create(['name' => 'Bench Press', 'slug' => 'bench-press'])`; `Log::spy()`
- **When:** `->resolve('Barbell Bench Press', 'chest')`
- **Expect:** a new row `slug = 'barbell-bench-press'` created and returned; `Log::shouldHaveReceived('info')` once, message referencing both slugs

### Architecture — `tests/Feature/ArchTest.php` (added rules)

**TC-35:** New namespaces obey conventions
- **Expect:** `App\Services` classes are `final`; `App\Ai\Agents` classes are `final`; the existing `App\Actions` (final + `handle()`), form-request and "no debug helpers" rules still pass

---

## 9. Technical Decisions

| Decision area | What was decided | Why |
|---|---|---|
| Sync vs async | First-cycle generation is **synchronous, inside `POST /api/v1/routines`**. `GenerateCycleJob` is not dispatched. | Product decision this session. Removes the `generating`/`failed` observable states and the need for a status-polling and a retry endpoint for the first cycle. The client gets a ready-to-train plan in the create response, or a clean failure. |
| All-or-nothing on failure | If `CyclePlannerService::planFirstCycle()` throws, **no** routine row is created and the incumbent active routine is **not** archived. | Product decision (rewritten AC): "si falla, no se crea la rutina". Achieved by calling the planner **before** `DB::transaction` — a failure means no transaction was ever opened, so there is nothing to roll back and no archival happened. |
| Planner call position | `RoutineCreateAction`: guard → `planFirstCycle()` (no DB writes) → `DB::transaction`( archive incumbent → insert routine → `CycleDraftService::persist()` ). | Never hold a DB transaction open across a multi-second external API call. The only mutation sequence (archival + inserts) stays short and atomic; the AI call is a pre-condition that either yields a valid plan or aborts the request. |
| Failure HTTP status & code | `502 Bad Gateway`, `code` `AI_GENERATION_FAILED`, message `"The training plan could not be generated. Please try again."`, no `errors` key. | The request was well-formed; a downstream dependency (the AI provider) failed or returned garbage. `502` communicates "retry, it's not your input". Chosen over `409` (not a state conflict) and `503` (we are up; the dependency isn't). |
| `CycleGenerationException` identity | `App\Exceptions\Cycle\CycleGenerationException extends App\Exceptions\DomainException`; `protected string $errorCode = 'AI_GENERATION_FAILED'`; `protected int $statusCode = 502`; default message set in `__construct()`, optional `previous` for the provider error. | Reuses the existing `ApiExceptionRenderer` `DomainException` branch (`{data:{code,message}}` envelope, no renderer edit) exactly as `ProfileIncompleteException` does for `409 PROFILE_INCOMPLETE`. `CLAUDE.md` sanctions overriding `$statusCode` "when 409 is wrong". Thrown from a Service (`CyclePlannerService`), matching the "raised from a Service" convention. The domain-specific `code` lives on the subclass, not in `ErrorCode` (that enum is for framework-level failures). |
| Reopening create-routine | `RoutineCreateAction`, `RoutineResource`, `Routine` model, `create-routine-spec.md` and the routine test files are modified in **this** PR. | The synchronous first cycle is inseparable from routine creation now; there is no clean seam to add it elsewhere. Confirmed with the product owner. |
| `RoutineCreateAction` return | Returns the `Routine` with `cycle.cycleDays.dayExercises` eager-loaded (via `->load(...)` after `persist()` inside the transaction, or `loadMissing` after). | `Model::shouldBeStrict()` makes a lazy load in the Resource throw. The controller stays 3 lines; the Action owns what the Resource needs. |
| Nested response depth | The `201` embeds the **full** cycle: `cycle` → `days[]` → `exercises[]` with every prescription field + rationales. New `CycleResource` / `CycleDayResource` / `DayExerciseResource`. | Confirmed with the product owner ("ciclo completo anidado"). Matches the rewritten AC #3. Order 80 ("Ver el detalle de un ciclo") is reduced to a `GET` route + Policy + route-model binding that reuse these Resources. |
| `RoutineResource.cycle` | `'cycle' => CycleResource::make($this->whenLoaded('cycle'))` — the key is absent when the relation is not loaded. `Routine::cycle(): HasOne` = `hasOne(Cycle::class)->ofMany('sequence_number', 'max')`. | `whenLoaded` keeps the future `GET /api/v1/routines` list endpoint (Order 50) free to not load cycles. `ofMany(..., 'max')` returns "the current cycle" and is forward-compatible with cycle N+1. |
| Layer split: planner vs draft | `CyclePlannerService` (AI wrapper: prompt → agent → validate → **pure DTO**, no DB) and `CycleDraftService` (DTO + `Routine` → rows, **no transaction**). The Action wires them and owns the transaction. | `CLAUDE.md`: a Service does one piece of work and never opens transactions/dispatches. The planner must run before the transaction and must not persist; the draft mapping (DTO → `cycle_days`/`day_exercises` + `ExerciseCatalogService` calls) is a cohesive unit reused by Order 150 later. Two focused Services beat one Service with a mode flag or a long inline block in the Action. |
| `CyclePlannerService` signature | `planFirstCycle(AthleteProfile $profile, Goal $goal, ?string $hint): CyclePlanData`. Not `planFirstCycle(Routine)`. | At planning time the routine does not exist yet (it is only inserted if planning succeeds). Passing the profile + goal + hint is the honest input set. |
| Shape validation location | In `CyclePlannerService`, after the agent returns: exactly 5 days; each day 1–8 exercises; `rep_min ≤ rep_max`; `sets ≥ 1`; `rest_seconds ≥ 0`; `target_weight_kg` present and `≥ 0`; every `focus_muscle_groups` entry a `MuscleGroup`. Any violation → `CycleGenerationException`. | The structured-output JSON schema constrains a well-behaved provider; the explicit check defends against a bad provider **and** malformed test fakes, and is the single place the "unusable plan → 502" rule lives. Keeps `CycleDraftService` and the Action dealing only in valid DTOs. |
| First-cycle `target_weight_kg` | The agent must return a numeric weight (estimated from `experience_level` + `notes`); a null/absent weight is a validation failure → `502`. DB column stays nullable for later cycles / bodyweight moves. | Confirmed with the product owner. The rewritten AC #3 lists "peso objetivo" as a field the user sees on the first cycle. |
| AI model | `CyclePlannerAgent` carries `#[UseCheapestModel]` → `claude-haiku-4-5-*` on `config('ai.default')` (`anthropic`); `#[Timeout(60)]`. No `#[Provider]` / `#[Model]` (env-configurable). | Confirmed with the product owner ("haiku"). The call is now in the request path, so a **60 s** timeout (not 120 s) bounds the worst-case HTTP latency; structured cycle planning is well-bounded and cheap on haiku. |
| `GenerateCycleJob` fate | Kept as a `final implements ShouldQueue` **stub** with `handle()` empty; docblock rewritten to point at the on-demand cycle-N+1 story (Order 150), which stays asynchronous with its own `generating`/`failed` lifecycle. Not dispatched anywhere now. | Confirmed with the product owner ("conservarlo para N+1"). Deleting it would just mean re-creating it for Order 150; a no-op stub is harmless and documents the seam. |
| `CycleStatus` completeness | Keep all five cases (`generating`, `draft`, `active`, `completed`, `failed`) in the enum even though the sync path only ever writes `draft` (then Order 90 writes `active`/`completed`). | `data-model.md` §Enums defines the full lifecycle; the async N+1 path (Order 150) uses `generating`/`failed`. Trimming now and re-adding later churns the enum and the model casts. |
| `exercises` catalogue | `ExerciseCatalogService::resolve($name, $hint)`: slug = `Str::slug(Str::ascii(trim($name)))`; `Exercise::firstOrCreate(['slug' => $slug], ['name' => trim($name), 'primary_muscle_group' => MuscleGroup::tryFrom((string) $hint)?->value, 'created_by_ai' => true])`; blank name → `InvalidArgumentException`; after a create, a token-overlap check logs `Log::info` on a probable near-duplicate. | `data-model.md` §`exercises` + §5 + Decision #5 ("Solo log; sin tabla `exercise_aliases` en v1"). One responsibility; no alias table; no merge logic. |
| DTO shape | `CyclePlanData { string $splitRationale; DataCollection<int, CyclePlanDayData> $days }`, `CyclePlanDayData { string $label; list<string> $focusMuscleGroups; string $rationale; DataCollection<int, CyclePlanExerciseData> $exercises }`, `CyclePlanExerciseData { string $name; ?string $primaryMuscleGroup; int $sets; int $repMin; int $repMax; float $targetWeightKg; ?float $targetRpe; int $restSeconds; string $rationale }`. `readonly` promoted props, built **explicitly** by `CyclePlannerService` from `$response->toArray()` (no `#[MapInputName]`; `config/data.php` has no global input mapper). | `CLAUDE.md` (typed `Data` across layers) + rule 6: the tree is the contract `CycleDraftService` consumes and gives PHPStan traction. Explicit construction keeps the AI's snake_case JSON out of the DTOs and puts "is this key present / valid" in one readable place. |
| `CyclePlannerAgent` | `final implements Agent, HasStructuredOutput { use Promptable; }`; `#[UseCheapestModel]`, `#[Timeout(60)]`; `instructions(): string` (trainer role, "exactly 5 training days", kilograms, the `MuscleGroup` vocabulary, "always set a starting target weight from experience level", required per-day / per-exercise fields); `schema(JsonSchema $schema): array` (5-item day array with bounds + `->enum(MuscleGroup::values())`). No constructor. | `data-model.md` §5. `laravel/ai` returns a `StructuredAgentResponse` (array-accessible) when the agent implements `HasStructuredOutput`; `Promptable` gives `::make()` / `->prompt()` / `::fake()` / `::assertPrompted()`. |
| Models & PHPDoc | `Exercise`, `Cycle`, `CycleDay`, `DayExercise` each `use HasFactory, HasPublicUuid;`, `#[Fillable([...])]`, `casts()` (enums; `focus_muscle_groups` → `array`; `*_at` → `immutable_datetime`; `target_weight_kg` → `decimal:2`, `target_rpe` → `decimal:1`), typed relations. Full `@property` / `@method` PHPDoc via `php artisan ide-helper:models --write`, hand-checked. | `CLAUDE.md` Conventions (complete PHPDoc block; declared mass-assignment; backed enums for status). Mirrors `Routine` / `AthleteProfile`. |
| Relations | `Routine hasMany cycles()` + `hasOne cycle()` (added); `Cycle belongsTo routine()`, `hasMany cycleDays()`; `CycleDay belongsTo cycle()`, `hasMany dayExercises()`; `DayExercise belongsTo cycleDay()`, `belongsTo exercise()`. `Exercise` gets no back-relation in v1. | Only the relations the Action / Resources / tests traverse (`CLAUDE.md` rule 5). |
| Factories | `ExerciseFactory` (`slug` from `name`, `created_by_ai = true`); `CycleFactory` (`routine_id` => `Routine::factory()`, `sequence_number = 1`, `status = Draft`, `generated_at = now()`) + states `generating()`, `active()`, `completed()`, `failed()`; `CycleDayFactory`, `DayExerciseFactory` for direct unit use. | Standard pattern (mirrors `RoutineFactory::archived()`). States cover every `CycleStatus` later stories need. |
| Enum storage | Backed **string** enums; DB columns `string`, no native PG `enum` / `CHECK`. | Portable across PostgreSQL 17 and SQLite `:memory:`; the cast + the planner-service check + the JSON schema enforce membership. Matches `routines` / `athlete_profiles`. |
| `focus_muscle_groups` column | `$table->json(...)` + `'array'` cast, not `jsonb`. | Portable; identical behaviour for whole-array replace. `data-model.md` deviation noted. |
| Test strategy for the agent | `CyclePlannerAgent::fake([$structuredArray])` (happy path); `CyclePlannerAgent::fake(fn () => throw ...)` (failure); `CyclePlannerAgent::assertPrompted(...)` / `assertNeverPrompted()` (AC #2-new / guard ordering). `fakeCyclePlanner()` + `cyclePlanPayload()` in `tests/Pest.php`. The endpoint / Action is invoked normally; no `queue:work`. | `CLAUDE.md` "Jobs & AI" ("the suite never hits a real provider"). `laravel/ai`'s `FakeTextGateway` marshals an array into a `StructuredAgentResponse` and a throwing closure to propagate an exception — the two paths the ACs need. |
| Migration / DB isolation | Four migrations on a `-T gym_trainer` clone `gym_trainer_generate_first_cycle`; `.env` `DB_DATABASE` repointed in the worktree, reverted on merge. Pest stays SQLite `:memory:`. | `CLAUDE.md` "Workflows — database isolation". |
| Scramble docs | The `201` response shape changes (nested `cycle`) and a `502` is added; `dedoc/scramble` infers both from `RoutineResource` / `CycleResource` return types and the thrown `CycleGenerationException`. No `#[...]` attribute needed. `DocsSecurityTest` still passes (auth unchanged). | `CLAUDE.md` "API documentation" — keeping the pipeline typed is the docs. |
| Git artifacts | Branch `worktree-generate-first-cycle` (the worktree's branch, matching the `worktree-*` precedent); English only; **no** `Co-Authored-By: Claude` / `Claude-Session:` commit trailers (repo `CLAUDE.md` / `AGENTS.md`, which take precedence over the session attribution instruction); single PR; PR description carries only the `🤖 Generated with Claude Code` footer. | Repo `CLAUDE.md` / `AGENTS.md` "Git" rules; every prior spec notes the precedence. |

---

## 10. Work Plan

Inner-most first (enums → migrations → models → factories → DTOs → exception →
agent → Services → Resources → reopen the routine pipeline → tests). Each task's
DoD is the artifact existing, `vendor/bin/pint --dirty` + `vendor/bin/phpstan
analyse` (level 6) clean, and — where the class carries logic — its focused test,
authored in the same task. All commands run through Docker or the worktree
toolchain (`worktree-docker-tooling` memo).

| # | Task | Definition of Done |
|---|---|---|
| 1 | Clone the runtime DB: `docker compose exec pgsql createdb -U gym -T gym_trainer gym_trainer_generate_first_cycle`; set `DB_DATABASE=gym_trainer_generate_first_cycle` in this worktree's `.env`. | `php artisan db:show` targets the clone; `gym_trainer` untouched; Pest still SQLite. |
| 2 | Create `app/Enums/Cycle/CycleStatus.php` (`Generating`/`Draft`/`Active`/`Completed`/`Failed`) and `app/Enums/Shared/MuscleGroup.php` (`Chest`,`Back`,`Quads`,`Hamstrings`,`Glutes`,`Shoulders`,`Biceps`,`Triceps`,`Calves`,`Core`) with `MuscleGroup::values(): array`. | `CycleStatus::from('draft')` / `MuscleGroup::tryFrom('chest')` work; `MuscleGroup::values()` → 10 strings; Pint + PHPStan clean. |
| 3 | Create the four migrations per §4.1 (`exercises`, `cycles`, `cycle_days`, `day_exercises`), FK order, `json` for `focus_muscle_groups`, non-cascading `exercise_id`, the two composite uniques. **No** failure columns. | `php artisan migrate` runs on the clone and fresh SQLite; `php artisan db:table cycles` shows `cycles_routine_id_sequence_number_unique`; `db:table day_exercises` shows the non-cascading `exercise_id` FK. |
| 4 | Update `docs/plans/data-model.md` §Enums — drop the `MuscleGroup` "*(a validar)*" / "primera lista" caveats; both enums shipped. | The doc lists the final enum values; diff limited to §Enums. |
| 5 | Create `app/Models/Exercise.php`, `Cycle.php`, `CycleDay.php`, `DayExercise.php` per §9. Add `cycles(): HasMany` + `cycle(): HasOne` (`ofMany('sequence_number', 'max')`) + PHPDoc to `app/Models/Routine.php`. | Pint + PHPStan clean; `(new Cycle)->getCasts()` has the enum + date/decimal casts; `(new Routine)->cycle()` is a `HasOne`; `(new CycleDay)->dayExercises()` a `HasMany`. |
| 6 | `php artisan ide-helper:models --write` for the four new models + `Routine`; `vendor/bin/pint app/Models`; hand-check enum-cast `@property`, `HasPublicUuid` `@method`, the `cycle` / `cycles` relations. | Every column / relation in each PHPDoc block; diff limited to the five models. |
| 7 | Create `database/factories/ExerciseFactory.php`, `CycleFactory.php` (+ states `generating()`, `active()`, `completed()`, `failed()`), `CycleDayFactory.php`, `DayExerciseFactory.php` per §9. | `Cycle::factory()->create()` persists a `draft` row with a `uuid`; `Routine::factory()->has(Cycle::factory())->create()` works; Pint + PHPStan clean. |
| 8 | Create `app/Data/Cycle/CyclePlanData.php`, `CyclePlanDayData.php`, `CyclePlanExerciseData.php` (`spatie/laravel-data`, `readonly` promoted props, `DataCollection` children) per §9. | PHPStan clean; a throwaway assertion builds a nested `CyclePlanData`. |
| 9 | Create `app/Exceptions/Cycle/CycleGenerationException.php` (`final extends App\Exceptions\DomainException`; `$errorCode = 'AI_GENERATION_FAILED'`; `$statusCode = 502`; ctor sets the default message, accepts an optional `?Throwable $previous`). | `(new CycleGenerationException)->errorCode() === 'AI_GENERATION_FAILED'` and `->statusCode() === 502`; Pint + PHPStan clean. |
| 10 | Create `app/Ai/Agents/Cycle/CyclePlannerAgent.php` per §9 (`#[UseCheapestModel]`, `#[Timeout(60)]`, `instructions()`, `schema()` with bounds + `MuscleGroup` enum). | Pint + PHPStan clean; `CyclePlannerAgent::fake([...]); CyclePlannerAgent::make()->prompt('x')->toArray()` returns the fake payload. |
| 11 | Create `app/Services/Exercise/ExerciseCatalogService.php` (`final`, `resolve()` per §9). Write `tests/Feature/Exercise/ExerciseCatalogServiceTest.php` (TC-30…TC-34). | `vendor/bin/pest tests/Feature/Exercise/ExerciseCatalogServiceTest.php` green; Pint + PHPStan clean. |
| 12 | Create `app/Services/Cycle/CyclePlannerService.php` (`final`; `planFirstCycle(AthleteProfile, Goal, ?string): CyclePlanData` — build prompt, `CyclePlannerAgent::make()->prompt()`, validate shape, map to DTOs, wrap any failure in `CycleGenerationException`). Add `fakeCyclePlanner()` / `cyclePlanPayload()` to `tests/Pest.php`. Write `tests/Feature/Cycle/CyclePlannerServiceTest.php` (TC-24…TC-27). | `vendor/bin/pest tests/Feature/Cycle/CyclePlannerServiceTest.php` green (incl. the malformed-shape dataset); Pint + PHPStan clean. |
| 13 | Create `app/Services/Cycle/CycleDraftService.php` (`final`, ctor promotes `ExerciseCatalogService`; `persist(Routine, CyclePlanData): Cycle` — create the `cycles` row `draft` + `generated_at` + `split_rationale`, the 5 `cycle_days`, the `day_exercises` via `resolve()`; **no** transaction). Write `tests/Feature/Cycle/CycleDraftServiceTest.php` (TC-28, TC-29). | `vendor/bin/pest tests/Feature/Cycle/CycleDraftServiceTest.php` green; Pint + PHPStan clean. |
| 14 | Create `app/Http/Resources/Cycle/DayExerciseResource.php`, `CycleDayResource.php` (`exercises` via `whenLoaded('dayExercises')` + nested `DayExerciseResource::collection`), `CycleResource.php` (`days` via `whenLoaded('cycleDays')`); `id` = `uuid`, enums via `->value`, decimals as numbers, dates ISO-8601; no internal ids, no back-relations. | Pint + PHPStan clean; `CycleResource` `toArray()` has no `routine_id` / `cycle_id`, `id` is the `uuid`. |
| 15 | Rework `app/Actions/Routine/RoutineCreateAction.php`: ctor promotes `CyclePlannerService` + `CycleDraftService`; `handle(User, RoutineData): Routine` = onboarding guard → `$plan = $planner->planFirstCycle($user->athleteProfile, $data->goal, $data->hint)` → `DB::transaction`( scoped archive `update` → `$routine = $user->routines()->create([...Active])` → `$cycleDraftService->persist($routine, $plan)` ) → `$routine->load('cycle.cycleDays.dayExercises')` → return. Remove the `GenerateCycleJob` import + dispatch. | `final` + `handle()`; PHPStan clean; behaviour covered by TC-21…TC-23. |
| 16 | Update `app/Http/Resources/Routine/RoutineResource.php`: add `'cycle' => CycleResource::make($this->whenLoaded('cycle'))`. | Pint + PHPStan clean; the key is absent when `cycle` is not loaded, present (full tree) when it is. |
| 17 | Update `app/Jobs/Cycle/GenerateCycleJob.php`: keep the `final implements ShouldQueue` stub; rewrite the docblock to point at the on-demand cycle-N+1 story (Order 150, asynchronous). No dispatch anywhere. | Pint + PHPStan clean; `php artisan queue:work --once` on an empty queue is a no-op; nothing dispatches it. |
| 18 | Rework `tests/Feature/Routine/StoreRoutineTest.php` (TC-1…TC-20) and `tests/Feature/Routine/RoutineCreateActionTest.php` (TC-21…TC-23): `beforeEach` uses `fakeCyclePlanner()`; drop `Bus::fake()` / `GenerateCycleJob` assertions; add the nested-cycle assertions, the `502` failure cases, the "incumbent not archived on failure" case, and `assertNeverPrompted()` on the `422` / `409` guards. | `vendor/bin/pest tests/Feature/Routine` all green; every TC-1…TC-23 has a matching test. |
| 19 | Update `docs/plans/create-routine-spec.md` for the synchronous behaviour: §1 scope, §2.1 (nested `cycle`, `502`), §2.3 (no dispatch), §7, §8 (revised TC list — cross-reference this spec), §9 (drop "Job dispatch position"; revise "GenerateCycleJob"), §10. | The create-routine spec no longer describes an async job; it points here for the cycle detail. |
| 20 | Add the arch rules to `tests/Feature/ArchTest.php` (TC-35): `App\Services` final; `App\Ai\Agents` final. | `vendor/bin/pest tests/Feature/ArchTest.php` green; existing rules pass. |
| 21 | `vendor/bin/pint --dirty`, then `vendor/bin/phpstan analyse`, then a final `php artisan ide-helper:models --write` + `vendor/bin/pint app/Models`. | Pint no diffs; PHPStan level 6 clean; model PHPDoc in sync with the migrations. |
| 22 | `docker compose exec app composer check` (Pint `--test` + PHPStan + full Pest, incl. the reworked Routine suites, the new Cycle / Exercise suites, `DocsSecurityTest`, the arch rules). | All three green; no regression in Auth / Profile suites. |
| 23 | Manual live check against `http://localhost:8000` with a real `ANTHROPIC_API_KEY` (not gating): `GET /sanctum/csrf-cookie` → register → login → `PUT /api/v1/profile` → `POST /api/v1/routines` → inspect the `201` (`data.cycle.days[].exercises[]` populated, `status: draft`) and the `cycles` / `cycle_days` / `day_exercises` rows; then force a failure (bad key) → `502 AI_GENERATION_FAILED`, `assertDatabaseCount('routines', 0)`; review `GET /docs/api` (nested `cycle` in the `201`, `502` listed). | The endpoint returns a coherent nested cycle from a real provider; the failure path returns `502` and persists nothing; Scramble shows the new shape. |
| 24 | On merge / branch drop: `docker compose exec pgsql dropdb -U gym --if-exists gym_trainer_generate_first_cycle`; revert `DB_DATABASE` in the worktree `.env`. | The clone is gone; `.env` restored to `gym_trainer`. |

*Process note: branch name, commit messages and PR text follow `CLAUDE.md` /
`AGENTS.md` — English only, no `Co-Authored-By: Claude` / `Claude-Session:`
trailers, `🤖 Generated with Claude Code` footer in the PR description only.*
