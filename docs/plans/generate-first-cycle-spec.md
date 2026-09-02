# Generate the first cycle on routine creation — `GenerateCycleJob`

> Derived from the Notion ticket "Recibir el primer ciclo apenas creo una rutina"
> (Feature: Ciclos & generación IA · MVP · Must · Repo: API · Order 60) and the
> approved plan for this session. Base contract: `docs/product-context.md` §2 /
> §4 (steps 2–3) / §5 / §6 / §7, `docs/plans/data-model.md` §`cycles` /
> §`cycle_days` / §`day_exercises` / §`exercises` / §Identificadores / §Enums,
> `docs/plans/create-routine-spec.md` (the merged story that already dispatches
> `GenerateCycleJob`), and `CLAUDE.md` "The pipeline" + "Jobs & AI".

## 1. Context

**Kind:** Greenfield Feature

**Stack:** PHP 8.5 · Laravel 13 · PostgreSQL 17 (runtime) / SQLite `:memory:`
(tests) · Pest 4 (`pest-plugin-laravel`) · `laravel/ai` 0.6.8 (structured-output
agents) · `spatie/laravel-data` 4.23 · queue driver `database` (dev) / `sync`
(tests) · Pint · Larastan level 6. Everything runs in Docker.

**Problem statement:** The routine-creation endpoint (`POST /api/v1/routines`,
merged) already inserts the `active` routine and dispatches
`App\Jobs\Cycle\GenerateCycleJob` — but the job's `handle()` is an empty stub and
the whole Cycle domain does not exist. This ticket makes the job real: it builds
the routine's **first weekly cycle** with an AI planner agent, using the athlete
profile and the routine's `goal` + `hint`, and persists a `draft` cycle of 5
days with a full prescription (sets, rep range, target weight, RPE, rest) and an
AI rationale per day and per exercise. If generation fails, the routine stays and
the cycle is recorded as `failed` with a reason. This is the generation **engine
only** — no HTTP endpoint is added; reading, activating and retrying a cycle are
separate backlog stories (Orders 70 / 80 / 90 / 150).

**In scope:**
- The Cycle-domain schema: `exercises`, `cycles`, `cycle_days`, `day_exercises`
  tables + `App\Models\Exercise` / `Cycle` / `CycleDay` / `DayExercise` +
  factories, per `docs/plans/data-model.md`.
- `App\Enums\Cycle\CycleStatus` (`generating`, `draft`, `active`, `completed`,
  `failed`) and `App\Enums\Shared\MuscleGroup` (the `data-model.md` §Enums list).
- Two extra columns on `cycles` not in `data-model.md` as written —
  `failed_at` (`timestamp` null) and `failure_reason` (`text` null) — to satisfy
  AC #5 ("el ciclo se muestra como fallido con un motivo"). `data-model.md`
  §`cycles` is updated to match.
- `App\Ai\Agents\Cycle\CyclePlannerAgent` — a `laravel/ai` agent implementing
  `Laravel\Ai\Contracts\Agent` + `HasStructuredOutput`, using `Promptable`. It
  runs on the configured default provider (`config('ai.default')` = `anthropic`)
  with `#[UseCheapestModel]` (`claude-haiku-4-5-*`) and a 120 s timeout.
- `App\Services\Cycle\CyclePlannerService` — wraps the agent: assembles the
  prompt from the athlete profile + routine `goal` + `hint`, invokes the agent,
  validates the returned shape, and maps it to a typed DTO tree
  (`App\Data\Cycle\CyclePlanData` / `CyclePlanDayData` / `CyclePlanExerciseData`).
- `App\Services\Exercise\ExerciseCatalogService` — normalises an AI-supplied
  exercise name to a slug and `firstOrCreate`s the global `exercises` row;
  `Log::info` on a probable near-duplicate (no alias table in v1).
- `App\Exceptions\Cycle\CycleGenerationException` — a plain `RuntimeException`
  the planner service throws on an unusable AI response; its message becomes the
  stored `failure_reason`.
- `App\Actions\Cycle\CycleGenerateAction` — the use case: create-or-reset the
  `sequence_number = 1` cycle as `generating`, call the planner service, persist
  the days + prescription in one transaction and move the cycle to `draft`; on
  any failure record `failed` + reason and return without rethrowing.
- Filling in `App\Jobs\Cycle\GenerateCycleJob::handle()` to call the Action
  (`$tries = 1`).
- A test AI-fake helper (`fakeCyclePlanner()`) in `tests/Pest.php` and Pest
  coverage of every acceptance criterion (happy path, AC #4 prompt content,
  failure path, malformed-response path) plus focused unit tests for the two
  Services.
- Updating `docs/plans/data-model.md` §`cycles` (the `failed_at` /
  `failure_reason` columns) and §Enums (mark `MuscleGroup` / `CycleStatus` as
  shipped).
- `tests/Feature/ArchTest.php` rules for the new `App\Actions\Cycle` /
  `App\Services\*` / `App\Ai\Agents\*` namespaces.

**Out of scope:**
- **Any HTTP route.** No read endpoint for a cycle (`GET /api/v1/cycles/{cycle}`
  — Order 80), no status-polling mechanism / `CycleResource` (Order 70), no
  `POST /api/v1/cycles/{cycle}/activate` (Order 90), no
  `POST /api/v1/routines/{routine}/cycles` for cycle N+1 (Order 150). AC #2 / #3
  / #5 are verified in this ticket through database state and job behaviour, not
  through an API response.
- A user-facing **retry** trigger. AC #5's "con opción de reintentar" is
  satisfied here only at the data level (the `failed` state + `failure_reason`
  are recorded and a re-dispatch of the job would resume cleanly). The endpoint
  that re-runs generation is a later story.
- `ProgressionSummaryService`, exercise recommendations, and everything specific
  to generating cycle **N+1** (Orders 130 / 150). This ticket only handles
  `sequence_number = 1` with **no** prior training history.
- Persisting the AI conversation: `cycles.conversation_id` is created as a
  nullable column (per `data-model.md`) but is **not** populated in v1.
- Automatic job retry / backoff. One attempt; a failure lands in the `failed`
  cycle state, not in repeated queue attempts.
- Native PostgreSQL `enum` types or `CHECK` constraints — enum columns are plain
  `string`, matching `athlete_profiles` / `routines`.
- Cycle activation side effects (previous cycle → `completed`, the "1 active + 1
  draft per routine" guard) — Order 90.
- The `gym-trainer-spa/` frontend (separate repository).

---

## 2. API Surface

### 2.1 REST

Not applicable — no REST endpoints. This ticket implements a queued job and its
supporting domain classes. First-cycle generation is triggered by the existing
`POST /api/v1/routines` (which dispatches `GenerateCycleJob`); that endpoint's
contract is unchanged. Reading the resulting cycle is a separate story (Order
80).

### 2.2 CLI

Not applicable — no CLI commands. `GenerateCycleJob` is processed by the standard
`php artisan queue:work`; no bespoke command is added.

### 2.3 Events

Not applicable — no domain events are defined or consumed.

`RoutineCreateAction` already dispatches the `GenerateCycleJob` **queued job**
(not an event) inside its transaction; this ticket only fills in that job's body.
`laravel/ai` emits its own internal framework events (`PromptingAgent`,
`AgentPrompted`) when the agent runs; the application registers no listeners for
them and adds none here.

---

## 3. UI

### 3.1 Pages

Not applicable — no pages affected. This is a backend-only ticket; the
live-status and draft-review screens live in `gym-trainer-spa/` (backlog Orders
210 / 220).

### 3.2 Components

Not applicable — no components affected.

---

## 4. Database

### 4.1 Schema changes

All four migrations are anonymous classes (`return new class extends Migration`),
follow the existing `database/migrations` style (`$table->id()`,
`$table->timestamps()`, `constrained()->cascadeOnDelete()`), and store enum
values as plain `string`. Column order and semantics follow
`docs/plans/data-model.md`.

| Table | Action | Details |
|---|---|---|
| `exercises` | Create | `id` bigint PK · `uuid` uuid **`unique`** (filled by `HasPublicUuid`) · `name` string · `slug` string **`unique`** · `primary_muscle_group` string **nullable** (stores a `MuscleGroup` value) · `created_by_ai` boolean **default `true`** · `created_at` / `updated_at`. **No** `user_id`; never targeted by a cascade. |
| `cycles` | Create | `id` bigint PK · `uuid` uuid **`unique`** · `routine_id` bigint FK → `routines.id`, `constrained()->cascadeOnDelete()` · `sequence_number` `unsignedInteger` · `status` string (stores `CycleStatus`) · `split_rationale` text **nullable** · `conversation_id` string(36) **nullable** (not populated in v1) · `generated_at` / `activated_at` / `completed_at` / `failed_at` `timestamp` **nullable** · `failure_reason` text **nullable** · `created_at` / `updated_at`. **Unique** `(routine_id, sequence_number)`. |
| `cycle_days` | Create | `id` bigint PK · `uuid` uuid **`unique`** · `cycle_id` bigint FK → `cycles.id`, `constrained()->cascadeOnDelete()` · `order` `unsignedSmallInteger` · `label` string · `focus_muscle_groups` **`json`** (array of `MuscleGroup` values) · `created_at` / `updated_at`. **Unique** `(cycle_id, order)`. |
| `day_exercises` | Create | `id` bigint PK · `uuid` uuid **`unique`** · `cycle_day_id` bigint FK → `cycle_days.id`, `constrained()->cascadeOnDelete()` · `exercise_id` bigint FK → `exercises.id`, `constrained()` (**no** cascade — `restrict`) · `order` `unsignedSmallInteger` · `sets` `unsignedSmallInteger` · `rep_min` `unsignedSmallInteger` · `rep_max` `unsignedSmallInteger` · `target_weight_kg` `decimal(6,2)` **nullable** · `target_rpe` `decimal(3,1)` **nullable** · `rest_seconds` `unsignedSmallInteger` · `rationale` text · `created_at` / `updated_at`. **Unique** `(cycle_day_id, order)`. |

Notes:
- `focus_muscle_groups` uses `$table->json(...)` (not `jsonb`): the codebase's
  test DB is SQLite `:memory:`, where `json` maps to `TEXT` and the Eloquent
  `array` cast works identically; on PostgreSQL 17 `json` is a real JSON column.
  `data-model.md` labels it `jsonb`; `json` is the portable equivalent and is
  what the schema builder emits — noted as a deliberate deviation.
- `exercise_id` is `constrained()` with **no** `cascadeOnDelete()` /
  `nullOnDelete()`: the default DB behaviour is `RESTRICT`, so a catalogued
  exercise can never be deleted out from under a prescription
  (`data-model.md` §Convenciones: "`exercise_id` **nunca** cascadea").
- No partial unique indexes here (unlike `routines`). "One `active` + one
  `draft` per routine" is a Service guard in the **activation** story (Order 90),
  not this one; the only DB invariant now is `(routine_id, sequence_number)`
  unique, which makes the first cycle idempotent.
- `sequence_number` is `unsignedInteger` (matches `data-model.md` `int`); this
  ticket only ever writes `1`.
- No soft deletes (`data-model.md`): lifecycle is tracked by `status`.
- `down()` on each migration is `Schema::dropIfExists(<table>)`, in reverse FK
  order (`day_exercises`, `cycle_days`, `cycles`, `exercises`).
- **Migration timestamps** order after `2026_09_02_130000_create_routines_table`
  and run `exercises` → `cycles` → `cycle_days` → `day_exercises` so each FK
  target exists first.
- **Doc update** — `docs/plans/data-model.md` §`cycles`: add two rows —
  `| \`failed_at\` | \`timestamptz\` null | Cuándo el job marcó el ciclo como fallido. \`null\` salvo que la generación haya fallado. |`
  and
  `| \`failure_reason\` | \`text\` null | Motivo legible del fallo de generación (mensaje de la excepción, recortado). \`null\` salvo estado \`failed\`. |`
  — and note in the `status` row that `failed` is reached by the job's catch
  block.
- **Database isolation (`CLAUDE.md` → "Workflows — database isolation"):** this
  branch adds migrations, so the shared `gym_trainer` database must not be
  migrated directly. Before running `migrate` against PostgreSQL:
  `docker compose exec pgsql createdb -U gym -T gym_trainer gym_trainer_generate_first_cycle`,
  then set `DB_DATABASE=gym_trainer_generate_first_cycle` in this worktree's
  `.env`. Drop the clone
  (`docker compose exec pgsql dropdb -U gym --if-exists gym_trainer_generate_first_cycle`)
  and revert `.env` on merge. The Pest suite is unaffected — SQLite `:memory:`.

### 4.2 Seeds

Not applicable — no seeds. The `exercises` catalogue is populated at runtime by
`ExerciseCatalogService` from AI-supplied names (`data-model.md` §`exercises`:
`created_by_ai` defaults `true`, "deja lugar a un seed curado a futuro"). No
curated seed in v1.

---

## 5. Auth & Authorization

Not applicable — no auth or authorization changes.

`GenerateCycleJob` runs on the queue with no HTTP request and no authenticated
user in scope; it acts on the `Routine` it was constructed with. The routine's
ownership was already established at creation time by `StoreRoutineController` +
`RoutinePolicy` (merged). No Policy, Gate, guard or middleware is added or
altered. The job does not read `auth()` / `request()`.

---

## 6. Configuration

**Environment variables:**

| Variable | Value / Source | Purpose |
|---|---|---|
| `DB_DATABASE` | `gym_trainer_generate_first_cycle` (this worktree's `.env` only, during development) | Points the worktree at a clone of `gym_trainer` so the four new migrations never run against the shared database. Reverted on merge. |
| `AI_PROVIDER` | `anthropic` (already in `.env.example`; unchanged) | `config('ai.default')` — the provider `CyclePlannerAgent` resolves when none is passed explicitly. |
| `ANTHROPIC_API_KEY` | already in `.env.example` (empty by default; unchanged) | Real key only needed for a live manual check; the Pest suite fakes the agent and never calls a provider. |

No new keys are added to `.env.example`. `phpunit.xml` already sets
`QUEUE_CONNECTION=sync`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`;
`RefreshDatabase` is active for the `Feature` suite in `tests/Pest.php`.

**Config files modified:**

| File | Change |
|---|---|
| `docs/plans/data-model.md` | Add `failed_at` / `failure_reason` rows to §`cycles`; annotate the `status` row; mark `CycleStatus` / `MuscleGroup` as shipped in §Enums. (Doc, not a config file — listed for completeness.) |
| `tests/Pest.php` | Add the `fakeCyclePlanner(array $overrides = [])` global helper (returns a canned structured payload and calls `CyclePlannerAgent::fake(...)`). Replace the scaffold `something()` / `toBeOne` only if Pint/PHPStan require it — otherwise leave them. |
| `tests/Feature/ArchTest.php` | Add `arch()` rules: `App\Services` classes are `final`; `App\Ai\Agents` classes are `final`; the existing `App\Actions` rule already covers `App\Actions\Cycle`. |

No change to `config/ai.php`, `config/queue.php`, `config/data.php`,
`bootstrap/app.php`, `bootstrap/providers.php`, `routes/api.php`, `phpunit.xml`,
`composer.json`.

---

## 7. Current vs New Behavior

| Behavior | Current | New |
|---|---|---|
| `GenerateCycleJob::handle()` | Empty stub with a docblock pointing to this ticket. Dispatched on routine creation but does nothing when processed. | Resolves `CycleGenerateAction` from the container and calls `handle($this->routine)`. `public int $tries = 1`. |
| Cycle domain | No `cycles` / `cycle_days` / `day_exercises` / `exercises` tables; no models; no `CycleStatus` / `MuscleGroup` enums. | Four tables + four `HasPublicUuid` models + factories; `App\Enums\Cycle\CycleStatus`, `App\Enums\Shared\MuscleGroup`. |
| First-cycle content | A freshly created routine has zero cycles, forever (job is a no-op). | Processing the job creates cycle `sequence_number = 1`: `generating` → (AI planner) → `draft` with 5 `cycle_days`, each with `label` + `focus_muscle_groups` + a day rationale, and 1–8 `day_exercises` carrying `sets` / `rep_min` / `rep_max` / `target_weight_kg` / `target_rpe` / `rest_seconds` / `rationale`; the cycle carries `split_rationale` + `generated_at`. |
| AI usage | `laravel/ai` installed and configured; no agent classes; nothing calls a provider. | First agent: `App\Ai\Agents\Cycle\CyclePlannerAgent` (structured output), wrapped by `CyclePlannerService`. Tests fake it; the suite never hits a provider. |
| Exercise catalogue | No `exercises` table. | `ExerciseCatalogService` normalises each AI exercise name to a slug and `firstOrCreate`s a shared `exercises` row (`created_by_ai = true`), reusing an existing row on slug match; logs a probable near-duplicate. |
| Generation failure | N/A (job is a no-op, never fails). | Any exception during planning/persistence is caught: the cycle row is set to `status = failed`, `failed_at = now()`, `failure_reason = <trimmed message>`; the routine is untouched; the job returns normally (no rethrow, nothing in `failed_jobs`). |
| Idempotency of first-cycle generation | N/A. | `CycleGenerateAction` `firstOrNew`s the `(routine_id, sequence_number = 1)` cycle; a re-run of the job reuses that row, resetting a `failed`/`generating` row back to `generating` and clearing `failed_at` / `failure_reason` / stale days before re-planning. |
| `data-model.md` `cycles` | Columns as listed; no failure-reason column. | `failed_at` + `failure_reason` documented; `status = failed` transition annotated. |
| Tests | `tests/Feature/Routine`, `tests/Feature/Auth`, `tests/Feature/Profile`, `tests/Unit/Routine`; no `Bus`/agent fakes beyond routine creation. | Adds `tests/Feature/Cycle/GenerateFirstCycleTest.php`, `tests/Feature/Cycle/CyclePlannerServiceTest.php`, `tests/Feature/Exercise/ExerciseCatalogServiceTest.php`; first use of `CyclePlannerAgent::fake()` / `assertPrompted()`. |

---

## 8. Test Cases

Executable with Pest 4 on SQLite `:memory:` (`RefreshDatabase`, already wired).
The job is invoked directly
(`(new GenerateCycleJob($routine))->handle(app(CycleGenerateAction::class))`) or
the Action is called directly — no `queue:work` — and `CyclePlannerAgent` is
always faked, so no test touches a real AI provider. TC-16 is the one case that
goes through `GenerateCycleJob::dispatch()` (queue `sync`) to prove nothing
lands in `failed_jobs`. A shared helper in `tests/Pest.php`:

```php
function cyclePlanPayload(array $overrides = []): array
{
    $day = fn (string $label, array $groups, array $exercises) => [
        'label' => $label,
        'focus_muscle_groups' => $groups,
        'day_rationale' => "Focus on {$label}.",
        'exercises' => $exercises,
    ];
    $ex = fn (string $name, string $group) => [
        'name' => $name,
        'primary_muscle_group' => $group,
        'sets' => 3, 'rep_min' => 8, 'rep_max' => 12,
        'target_weight_kg' => 40.0, 'target_rpe' => 7.0,
        'rest_seconds' => 90, 'rationale' => "Start moderate on {$name}.",
    ];

    return array_replace_recursive([
        'split_rationale' => 'Five-day upper/lower split for hypertrophy.',
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
    CyclePlannerAgent::fake([cyclePlanPayload($overrides)]);
}
```

### First-cycle generation — `tests/Feature/Cycle/GenerateFirstCycleTest.php`

**TC-1:** Happy path — job builds a `draft` cycle with 5 fully-prescribed days (AC #2, #3)
- **Given:** a `User` with an `AthleteProfile` and a `Routine::factory()->for($user)->create()` (active, no cycles); `fakeCyclePlanner()`
- **When:** `(new GenerateCycleJob($routine))->handle(app(CycleGenerateAction::class))`
- **Expect:** exactly one `cycles` row for the routine with `sequence_number = 1`, `status = 'draft'`, non-null `generated_at`, `split_rationale = 'Five-day upper/lower split for hypertrophy.'`, `failed_at` / `failure_reason` null; `cycle_days` count = 5 with `order` 1..5 and the expected `label`s; each `cycle_days.focus_muscle_groups` is a non-empty array of `MuscleGroup` values; `day_exercises` count = 10, each with `sets = 3`, `rep_min = 8`, `rep_max = 12`, `target_weight_kg = 40.00`, `target_rpe = 7.0`, `rest_seconds = 90`, a non-empty `rationale`, and `order` starting at 1 within its day

**TC-2:** Cycle is left `generating` only transiently — never observable as the final state on success (AC #2)
- **Given:** the TC-1 setup; `fakeCyclePlanner()`
- **When:** the job runs to completion
- **Expect:** the final `cycles.status` is `'draft'`, never `'generating'`; a cycle row **does** exist (proving the `generating` row was created before the AI call, not only after success)

**TC-3:** The prompt carries the athlete profile and the routine `goal` + `hint` (AC #4)
- **Given:** a `User` with `AthleteProfile::factory()->for($user)->create(['experience_level' => ExperienceLevel::Intermediate, 'days_per_week' => 5, 'session_minutes' => 60, 'goal' => Goal::Strength, 'notes' => 'Left shoulder impingement, avoid heavy overhead pressing.'])` and `Routine::factory()->for($user)->create(['goal' => Goal::Hypertrophy, 'hint' => 'PPL split, dumbbells only'])`; `fakeCyclePlanner()`
- **When:** the job runs
- **Expect:** `CyclePlannerAgent::assertPrompted(fn (string $prompt) => str_contains($prompt, 'Left shoulder impingement') && str_contains($prompt, 'intermediate') && str_contains($prompt, '60') && str_contains($prompt, 'hypertrophy') && str_contains($prompt, 'PPL split, dumbbells only'))` — the routine `goal` (`hypertrophy`), not only the profile `goal` (`strength`), reaches the prompt

**TC-4:** `hint` is `null` — generation still runs, prompt omits the hint gracefully (AC #4)
- **Given:** a `User` with a profile and `Routine::factory()->for($user)->create(['hint' => null])`; `fakeCyclePlanner()`
- **When:** the job runs
- **Expect:** `status = 'draft'`; `CyclePlannerAgent::assertPrompted(fn (string $p) => ! str_contains($p, 'null') && ! str_contains($p, 'Hint:'))` (no literal `"null"` / empty hint label injected)

**TC-5:** Exercise catalogue — new names are inserted once, slugged, `created_by_ai` (AC #3, `data-model.md` §`exercises`)
- **Given:** the TC-1 setup with `fakeCyclePlanner()` whose payload names `Barbell Bench Press` on two different days
- **When:** the job runs
- **Expect:** `assertDatabaseHas('exercises', ['slug' => 'barbell-bench-press', 'name' => 'Barbell Bench Press', 'created_by_ai' => true])`; exactly **one** `exercises` row for that slug; every `day_exercises.exercise_id` points at an `exercises` row; total distinct `exercises` rows = number of distinct names in the payload

**TC-6:** Exercise catalogue — an existing row is reused, not duplicated
- **Given:** a pre-existing `Exercise::factory()->create(['name' => 'Bench Press', 'slug' => 'barbell-bench-press', 'created_by_ai' => false])`; the TC-1 setup; `fakeCyclePlanner()` naming `Barbell Bench Press`
- **When:** the job runs
- **Expect:** still exactly one row with `slug = 'barbell-bench-press'`; its `name` / `created_by_ai` are unchanged (`'Bench Press'` / `false`); the relevant `day_exercises.exercise_id` equals that row's id

**TC-7:** Failure path — AI call throws → cycle `failed` with a reason, routine intact, no rethrow (AC #5)
- **Given:** a `User` with a profile and a `Routine::factory()->for($user)->create()`; `CyclePlannerAgent::fake(fn () => throw new RuntimeException('provider unavailable'))`
- **When:** `(new GenerateCycleJob($routine))->handle(app(CycleGenerateAction::class))`
- **Expect:** the call does **not** throw; `assertDatabaseHas('cycles', ['routine_id' => $routine->id, 'sequence_number' => 1, 'status' => 'failed'])`; that row's `failure_reason` is a non-empty string containing `'provider unavailable'` and `failed_at` is non-null; `generated_at` / `split_rationale` are null; `cycle_days` count = 0; `day_exercises` count = 0; the `routines` row is unchanged (`status = 'active'`)

**TC-8:** Malformed response — wrong day count → treated as a generation failure (AC #3, #5)
- **Given:** the TC-1 setup; `fakeCyclePlanner(['days' => array_slice(cyclePlanPayload()['days'], 0, 4)])` (only 4 days)
- **When:** the job runs
- **Expect:** no throw; `cycles.status = 'failed'`; `failure_reason` mentions the day count (e.g. contains `'5 days'` or `'expected 5'`); `cycle_days` count = 0

**TC-9:** Malformed response — `rep_min > rep_max` in one exercise → generation failure
- **Given:** the TC-1 setup; `fakeCyclePlanner()` with the first day's first exercise overridden to `rep_min = 12, rep_max = 8`
- **When:** the job runs
- **Expect:** no throw; `cycles.status = 'failed'`; `failure_reason` is non-empty; nothing persisted to `cycle_days` / `day_exercises`

**TC-10:** Malformed response — a day with zero exercises → generation failure
- **Given:** the TC-1 setup; `fakeCyclePlanner()` with the last day's `exercises` set to `[]`
- **When:** the job runs
- **Expect:** no throw; `cycles.status = 'failed'`; `failure_reason` non-empty; `cycle_days` / `day_exercises` counts = 0 (all-or-nothing)

**TC-11:** All-or-nothing persistence — persistence error after planning leaves no partial cycle days
- **Given:** the TC-1 setup; `fakeCyclePlanner()` with one exercise `name` set to `''` (empty) so `ExerciseCatalogService` rejects it mid-persist
- **When:** the job runs
- **Expect:** no throw; `cycles.status = 'failed'`; `cycle_days` count = 0 and `day_exercises` count = 0 (the transaction rolled back); `failure_reason` non-empty

**TC-12:** Idempotent re-run after failure — a second job run resumes and succeeds (AC #5, retry-readiness)
- **Given:** the TC-7 outcome (a `failed` cycle for the routine); then `fakeCyclePlanner()` (healthy)
- **When:** `(new GenerateCycleJob($routine))->handle(app(CycleGenerateAction::class))` runs again
- **Expect:** still exactly one `cycles` row for `(routine, sequence_number = 1)`; its `status` is now `'draft'`, `failed_at` / `failure_reason` back to null, `generated_at` set; `cycle_days` count = 5

**TC-13:** Idempotent re-run after success — re-running does not duplicate or corrupt the draft
- **Given:** the TC-1 outcome (a `draft` cycle with 5 days); `fakeCyclePlanner()`
- **When:** the job runs a second time
- **Expect:** exactly one `cycles` row for `(routine, 1)`; `cycle_days` count = 5 (not 10); `day_exercises` count = 10 (not 20); `status = 'draft'`

**TC-14:** `focus_muscle_groups` round-trips as a typed array (AC #3)
- **Given:** the TC-1 setup; `fakeCyclePlanner()`
- **When:** the job runs, then `CycleDay::query()->where('order', 1)->first()`
- **Expect:** `->focus_muscle_groups` is a PHP `array` of strings each equal to a `MuscleGroup::value`; `assertDatabaseHas('cycle_days', ['order' => 1])` with the JSON column populated

**TC-15:** `target_rpe` / `target_weight_kg` decimals persist at the declared precision (AC #3)
- **Given:** the TC-1 setup; `fakeCyclePlanner()` with an exercise `target_weight_kg = 42.5`, `target_rpe = 8.5`
- **When:** the job runs
- **Expect:** the stored `day_exercises.target_weight_kg` reads back as `42.50` and `target_rpe` as `8.5`

**TC-16:** The job never rethrows on the queue for a generation failure (AC #5)
- **Given:** the TC-7 setup (`CyclePlannerAgent::fake(fn () => throw ...)`) but dispatched through the container as a queued job in `sync` mode: `GenerateCycleJob::dispatch($routine)`
- **When:** the dispatch resolves
- **Expect:** no exception propagates to the caller; `assertDatabaseCount('failed_jobs', 0)`; `cycles.status = 'failed'`

### Planner service — `tests/Feature/Cycle/CyclePlannerServiceTest.php`

*(Under `tests/Feature/` — it needs factories + the container, and `RefreshDatabase` is wired only for the `Feature` suite in `tests/Pest.php`.)*

**TC-17:** `planFirstCycle()` maps a well-formed structured response into the DTO tree
- **Given:** `fakeCyclePlanner()`; a `Routine` with a related `User` + `AthleteProfile` (factories); `app(CyclePlannerService::class)`
- **When:** `->planFirstCycle($routine)`
- **Expect:** returns a `CyclePlanData` whose `splitRationale` matches, `days` is a 5-element collection of `CyclePlanDayData`, each `->exercises` a collection of `CyclePlanExerciseData` with typed `int $sets` / `int $repMin` / `int $repMax` / `?float $targetWeightKg` / `?float $targetRpe` / `int $restSeconds` / `string $rationale` / `string $name` / `?string $primaryMuscleGroup`

**TC-18:** `planFirstCycle()` throws `CycleGenerationException` on each malformed shape
- **Given:** `app(CyclePlannerService::class)` and a valid `Routine`; a dataset of faked payloads: 4 days; 6 days; a day with `exercises = []`; `rep_min > rep_max`; `sets = 0`; `target_weight_kg` missing/null; `focus_muscle_groups` containing an unknown value; a top-level key missing
- **When:** `->planFirstCycle($routine)` for each
- **Expect:** every case throws `App\Exceptions\Cycle\CycleGenerationException`; the message names the offending rule

**TC-19:** `planFirstCycle()` builds the prompt from every athlete-profile field + routine `goal`/`hint`
- **Given:** `fakeCyclePlanner()`; a `Routine` + profile with distinctive values as in TC-3
- **When:** `->planFirstCycle($routine)`
- **Expect:** `CyclePlannerAgent::assertPrompted(fn (string $p) => ...)` asserting `experience_level`, `days_per_week`, `session_minutes`, profile `notes`, routine `goal`, routine `hint` all appear; and that the prompt states the "exactly 5 days" and "kilograms" constraints

### Exercise catalogue service — `tests/Feature/Exercise/ExerciseCatalogServiceTest.php`

*(Under `tests/Feature/` — it reads and writes the `exercises` table via Eloquent, so it needs `RefreshDatabase`.)*

**TC-20:** `resolve()` normalises a name to a slug (lowercase, unaccented, hyphenated) and creates the row
- **Given:** an empty `exercises` table; `app(ExerciseCatalogService::class)`
- **When:** `->resolve('Press de Banca Inclinado', 'chest')`
- **Expect:** one `exercises` row with `slug = 'press-de-banca-inclinado'`, `name = 'Press de Banca Inclinado'`, `primary_muscle_group = 'chest'`, `created_by_ai = true`; the return value is that `Exercise`

**TC-21:** `resolve()` is case- / accent- / whitespace-insensitive on the slug key
- **Given:** a pre-existing `Exercise::factory()->create(['slug' => 'barbell-bench-press', 'name' => 'Barbell Bench Press'])`
- **When:** `->resolve('  BARBELL  Bench  Préss ', null)` (dataset also: `'barbell bench press'`, `'Barbell-Bench-Press'`)
- **Expect:** no new row; the existing `Exercise` is returned unchanged; `exercises` count stays 1

**TC-22:** `resolve()` with an unknown `primary_muscle_group` hint stores `null`, not the raw string
- **Given:** an empty table
- **When:** `->resolve('Sled Push', 'conditioning')` (not a `MuscleGroup` case)
- **Expect:** one row with `primary_muscle_group = null`; no exception

**TC-23:** `resolve()` rejects a blank name
- **Given:** an empty table
- **When:** `->resolve('   ', 'chest')` (dataset: `''`)
- **Expect:** throws `App\Exceptions\Cycle\CycleGenerationException` (or an `InvalidArgumentException` the planner service wraps) — the exact type asserted matches the implementation choice in §9; no row created

**TC-24:** `resolve()` logs a probable near-duplicate but still returns the new row
- **Given:** a pre-existing `Exercise::factory()->create(['name' => 'Bench Press', 'slug' => 'bench-press'])`; `Log::spy()`
- **When:** `->resolve('Barbell Bench Press', 'chest')` (different slug, similar name)
- **Expect:** a new row `slug = 'barbell-bench-press'` is created and returned; `Log::shouldHaveReceived('info')` once with a message referencing both slugs — the merge is manual, nothing is blocked

### Architecture — `tests/Feature/ArchTest.php` (added rules)

**TC-25:** New namespaces obey the pipeline conventions
- **Given:** the project code
- **When:** the arch assertions run
- **Expect:** `App\Actions\Cycle\CycleGenerateAction` is `final` with a `handle()` method (covered by the existing `App\Actions` rule); new rules: `App\Services` is `final`; `App\Ai\Agents` is `final`; the existing "no debug helpers" rule still passes

---

## 9. Technical Decisions

| Decision area | What was decided | Why |
|---|---|---|
| Ticket scope | Generation **engine only** — schema + models + enums + agent + two Services + Action + job body + AI fakes. **No HTTP route** of any kind. | AC #1 ("generation starts on its own") is already satisfied by the merged `RoutineCreateAction` dispatch. Reading (Order 80), status polling (Order 70), activation (Order 90) and cycle N+1 (Order 150) are their own backlog stories. Keeps this large PR reviewable. Confirmed with the product owner. |
| Where the `cycles` row is created | **Lazily, inside the job** (`CycleGenerateAction`), not eagerly in `RoutineCreateAction`. The Action `firstOrNew`s the `(routine_id, sequence_number = 1)` row, sets `generating`, saves, then calls the planner. | Confirmed with the product owner. Avoids editing already-merged code and its spec. Accepted trade-off: a brief window after `POST /api/v1/routines` returns during which no cycle row exists yet — irrelevant in v1 because no endpoint reads cycles here. |
| Failure-reason storage | Add **`failed_at`** (`timestamp` null) and **`failure_reason`** (`text` null) to `cycles`; update `data-model.md`. | AC #5 requires the failed cycle to show "un motivo". `data-model.md` §`cycles` as written has `generated_at` / `activated_at` / `completed_at` but no failure columns; `failed_at` mirrors that pattern. Confirmed with the product owner. |
| First-cycle `target_weight_kg` | The agent **always proposes a numeric** `target_weight_kg` (estimated from `experience_level` + `notes`); the planner service treats a missing/null weight on the first cycle as a malformed response (`CycleGenerationException`). The DB column stays `nullable` for later cycles / bodyweight moves. | Confirmed with the product owner — AC #3 lists "peso objetivo" as a per-exercise field the user sees. `data-model.md`'s "null en el primer ciclo" note is superseded for v1 first cycles. |
| AI model | `CyclePlannerAgent` carries `#[UseCheapestModel]` → `claude-haiku-4-5-*` on the `anthropic` provider (`config('ai.default')`). 120 s timeout via `#[Timeout(120)]`. No explicit `#[Provider]` / `#[Model]` — the provider stays env-configurable. | Confirmed with the product owner ("haiku"). Structured cycle planning is a well-bounded task; the cheapest model keeps queue cost/latency low and the job is async so latency does not block the user. |
| Job retry policy | `GenerateCycleJob` sets `public int $tries = 1`. `CycleGenerateAction` catches every `Throwable`, records the `failed` state, and returns — the job does **not** rethrow. | Product-context / data-model do not call for auto-retry. A single deterministic attempt; the `failed` cycle row is the single source of truth and keeps `failed_jobs` clean. The `laravel/ai` provider already does its own internal request handling. A user-facing retry (re-dispatch) is a later story and the Action is written to resume idempotently. |
| Idempotency / retry-readiness | `CycleGenerateAction` uses `firstOrNew` on `(routine_id, sequence_number = 1)`; on a row already `failed` or `generating` it clears `failed_at` / `failure_reason` / `generated_at` / `split_rationale` and deletes any existing `cycle_days` (cascade removes `day_exercises`) before re-planning. Never creates a second first cycle. | The `(routine_id, sequence_number)` unique index guarantees one row; a future retry story just re-dispatches the job. TC-12 / TC-13 lock this in. |
| Layering | `GenerateCycleJob::handle()` → `CycleGenerateAction::handle(Routine)`. The Action orchestrates: `CyclePlannerService` (AI) + `ExerciseCatalogService` (catalogue) + one `DB::transaction` for persistence + the failure catch. | `CLAUDE.md` "Jobs & AI": a job's `handle()` is an outside caller that calls an Action; the Action is the only layer that opens transactions and orchestrates Services. Each Service has one job (call the agent / resolve the catalogue) and never calls the other or the Action. |
| `CyclePlannerService` responsibility | Builds the prompt string from the athlete profile (all fields, verbatim) + routine `goal` + `hint`; invokes `CyclePlannerAgent::make()->prompt($prompt)`; **validates the returned shape** (exactly 5 days; each day 1–8 exercises; `rep_min ≤ rep_max`; `sets ≥ 1`; `rest_seconds ≥ 0`; `target_weight_kg` present & `≥ 0`; every `focus_muscle_groups` entry a `MuscleGroup` case); maps to the `CyclePlanData` DTO tree. Throws `CycleGenerationException` on any violation. | `CLAUDE.md` "Service": business knowledge (what a valid plan is) + one external API. Keeping validation here — not in the Action — means the Action deals only in valid DTOs. The structured-output JSON schema constrains a well-behaved provider; the explicit check defends against a bad provider **and** the test fakes. |
| DTO shape | `App\Data\Cycle\CyclePlanData { string $splitRationale; /** @var DataCollection<int, CyclePlanDayData> */ $days }`, `CyclePlanDayData { string $label; /** @var list<string> */ array $focusMuscleGroups; string $rationale; DataCollection<int, CyclePlanExerciseData> $exercises }`, `CyclePlanExerciseData { string $name; ?string $primaryMuscleGroup; int $sets; int $repMin; int $repMax; float $targetWeightKg; ?float $targetRpe; int $restSeconds; string $rationale }`. `readonly` promoted props. Built **explicitly** by `CyclePlannerService` from `$response->toArray()`, not via `CyclePlanData::from($json)`. | `CLAUDE.md` (typed `Data` objects across layers) + rule 6 (a class must make the system simpler): the DTO tree is the contract the persistence step consumes and gives PHPStan traction. Explicit construction keeps the AI's snake_case JSON out of the DTOs (no `#[MapInputName]` coupling; `config/data.php` has no global input mapper) and puts the "is this key present / valid" decision in one readable place. |
| `CyclePlannerAgent` API surface | `final class CyclePlannerAgent implements Agent, HasStructuredOutput { use Promptable; }`. `instructions(): string` — system prompt (trainer role, "exactly 5 training days", kilograms, the `MuscleGroup` vocabulary, "always set a starting target weight from experience level", required per-day / per-exercise fields). `schema(JsonSchema $schema): array` — the structured-output tree using `illuminate/json-schema` (`$schema->array()->min(5)->max(5)->items($schema->object([...]))`, `integer()->min(1)`, `number()->min(0)`, `->enum(MuscleGroup::values())`). No constructor. | `data-model.md` §5 ("SDK: `laravel/ai` con agentes de salida estructurada"). `laravel/ai` returns a `StructuredAgentResponse` (array-accessible / `->toArray()`) when the agent implements `HasStructuredOutput`. `Promptable` provides `::make()` / `->prompt()` / `::fake()` / `::assertPrompted()`. |
| `ExerciseCatalogService` | `resolve(string $name, ?string $muscleGroupHint): Exercise`. Slug = `Str::slug(Str::ascii(trim($name)))`. `Exercise::firstOrCreate(['slug' => $slug], ['name' => trim($name), 'primary_muscle_group' => MuscleGroup::tryFrom((string) $muscleGroupHint)?->value, 'created_by_ai' => true])`. Blank name → throw. After a create, a lightweight token-overlap check against existing names logs `Log::info("Possible duplicate exercise: '{$slug}' resembles '{$otherSlug}'")`. | `data-model.md` §`exercises` + §5 ("Nombres de ejercicios: IA libre, pero normalizados… si coincide lo reutiliza, si no lo inserta") and Decision #5 ("Solo log; sin tabla `exercise_aliases` en v1"). One responsibility, no alias table, no merge logic. |
| Blank-name handling | `ExerciseCatalogService::resolve()` throws `InvalidArgumentException` on a blank name; `CyclePlannerService` also rejects a blank `name` during shape validation with `CycleGenerationException` before persistence is attempted. In practice the planner check fires first (TC-9…TC-11); the catalogue guard is a backstop. | Defence in depth; the Action's `Throwable` catch converts either into a `failed` cycle + reason (TC-11). |
| `CycleGenerationException` type | `App\Exceptions\Cycle\CycleGenerationException extends \RuntimeException` — **not** `App\Exceptions\DomainException`. Plain message, no `errorCode` / `statusCode`. | `DomainException` exists to be rendered by `ApiExceptionRenderer` as a JSON `code` the client branches on. This exception never reaches HTTP (no endpoint); it only carries a human string into `cycles.failure_reason`. Making it a `DomainException` would imply an API contract that does not exist (`CLAUDE.md` rules 5–6). |
| Failure capture | `CycleGenerateAction::handle()` wraps *plan + persist* in `try { … } catch (\Throwable $e) { $cycle->forceFill(['status' => CycleStatus::Failed, 'failed_at' => now(), 'failure_reason' => Str::limit($e->getMessage(), 480)])->save(); report($e); return $cycle; }`. The `generating` row is created/saved **before** the `try`, so a failure always has a row to mark. | AC #5: routine survives, cycle shows `failed` + reason, no rethrow. `report($e)` still logs for observability without failing the job. `Str::limit` keeps `failure_reason` bounded. |
| Transaction boundary | The `generating` row upsert happens first, outside the transaction. Then `DB::transaction(fn () => …)` wraps: delete stale `cycle_days`, create the 5 `cycle_days`, resolve + create all `day_exercises`, and the cycle's move to `draft` (`status`, `split_rationale`, `generated_at`). A throw inside rolls all of it back, leaving the row `generating`; the outer catch then flips it to `failed`. | All-or-nothing prescription (TC-10 / TC-11): the user never sees a half-built draft. `ExerciseCatalogService` writes to `exercises` inside the same transaction — an exercise inserted then rolled back is acceptable (catalogue is append-only and idempotent by slug on the next run). |
| `focus_muscle_groups` column type | `$table->json('focus_muscle_groups')` + Eloquent `'array'` cast (or `AsCollection`), not `jsonb`. | Portable across PostgreSQL 17 and SQLite `:memory:`; the schema builder emits `json`. `data-model.md` says `jsonb` — documented deviation, behaviour is identical for our read/write pattern (whole-array replace). |
| Models & PHPDoc | `Exercise`, `Cycle`, `CycleDay`, `DayExercise` each `use HasFactory, HasPublicUuid;`, declare `#[Fillable([...])]`, a `casts()` method (enums, `focus_muscle_groups` → array, `*_at` → `immutable_datetime`, decimals → `decimal:2` / `decimal:1`), and typed relations. Full `@property` / `@method` PHPDoc via `php artisan ide-helper:models --write` then hand-checked, per `CLAUDE.md`. | `CLAUDE.md` Conventions ("Every model carries a complete PHPDoc block"; mass-assignment protection declared; backed enums for every status field). Mirrors `Routine` / `AthleteProfile`. |
| Relations | `Routine hasMany cycles()` (added to the existing model + PHPDoc); `Cycle belongsTo routine()`, `hasMany cycleDays()`; `CycleDay belongsTo cycle()`, `hasMany dayExercises()`; `DayExercise belongsTo cycleDay()`, `belongsTo exercise()`. `Exercise` gets no back-relation to `day_exercises` in v1 (not needed). | Only the relations the Action / tests traverse. `CLAUDE.md` rule 5 (no speculative generality). Strict mode (`shouldBeStrict`) means the Action must eager-load (`$routine->loadMissing('user.athleteProfile')`) before the planner reads them. |
| Factories | `ExerciseFactory` (`name` = 2 fake words, `slug` = `Str::slug(name)`, `created_by_ai` = true). `CycleFactory` (`routine_id` => `Routine::factory()`, `sequence_number` => 1, `status` => `CycleStatus::Draft`, `generated_at` => now()) + states `generating()`, `failed()` (sets `failed_at` + `failure_reason`), `active()`, `completed()`. `CycleDayFactory`, `DayExerciseFactory` for direct unit use. | Standard factory pattern (mirrors `RoutineFactory`'s `archived()` state). States cover every `CycleStatus` a later story will need. |
| Test strategy for the agent | `CyclePlannerAgent::fake([$structuredArray])` for the happy path; `CyclePlannerAgent::fake(fn () => throw new RuntimeException(...))` for the failure path; `CyclePlannerAgent::assertPrompted(fn (string $prompt) => …)` for AC #4. A `fakeCyclePlanner()` / `cyclePlanPayload()` helper pair in `tests/Pest.php`. The job/Action is invoked directly in tests, not via `queue:work`. | `CLAUDE.md` "Jobs & AI" ("Tests use fake agent responses — the suite never hits a real provider"). `laravel/ai`'s `FakeTextGateway` marshals an array response into a `StructuredAgentResponse` and a closure that throws to propagate the exception — exactly the two paths AC #3 / #5 need. |
| Migration / DB isolation | Four migrations, `exercises` → `cycles` → `cycle_days` → `day_exercises`. Runtime work happens on a `-T gym_trainer` clone `gym_trainer_generate_first_cycle`; `.env` `DB_DATABASE` repointed in the worktree, reverted on merge. Pest stays on SQLite `:memory:`. | `CLAUDE.md` "Workflows — database isolation": a schema-changing workflow must not touch the shared `gym_trainer`. |
| Docs | Update `docs/plans/data-model.md` §`cycles` (`failed_at`, `failure_reason`, `status=failed` note) and §Enums (`CycleStatus` / `MuscleGroup` now real). No change to `docs/product-context.md`. This spec lives at `docs/plans/generate-first-cycle-spec.md`. | Keep the data model doc the source of truth; `product-context.md` already describes the behaviour. |
| Git artifacts | Branch `worktree-generate-first-cycle` (the worktree's branch, matching the `worktree-*` precedent); English only; **no** `Co-Authored-By: Claude` / `Claude-Session:` commit trailers; single PR; PR description carries only the `🤖 Generated with Claude Code` footer. | Repo `CLAUDE.md` / `AGENTS.md` "Git" rules take precedence over the session attribution instruction, as every prior spec notes. |

---

## 10. Work Plan

Pipeline classes are built inner-most first (enums → migrations → models →
factories → DTOs → agent → Services → Action → job → tests). Each task's DoD is
the artifact existing, passing `vendor/bin/pint --dirty` + `vendor/bin/phpstan
analyse` (level 6), and — where the class carries logic — its focused test,
authored in the same task. All commands run through Docker (`docker compose exec
app …`) or the worktree toolchain per the `worktree-docker-tooling` memo.

| # | Task | Definition of Done |
|---|---|---|
| 1 | Clone the runtime DB: `docker compose exec pgsql createdb -U gym -T gym_trainer gym_trainer_generate_first_cycle`; set `DB_DATABASE=gym_trainer_generate_first_cycle` in this worktree's `.env`. | `php artisan db:show` targets the clone; `gym_trainer` untouched; the Pest suite still uses SQLite. |
| 2 | Create `app/Enums/Cycle/CycleStatus.php` (string-backed: `Generating`, `Draft`, `Active`, `Completed`, `Failed`) and `app/Enums/Shared/MuscleGroup.php` (string-backed: `Chest`, `Back`, `Quads`, `Hamstrings`, `Glutes`, `Shoulders`, `Biceps`, `Triceps`, `Calves`, `Core`) with a `values(): array` helper on `MuscleGroup`. | Files exist; `CycleStatus::from('failed')` / `MuscleGroup::tryFrom('chest')` work; `MuscleGroup::values()` returns the 10 strings; Pint + PHPStan clean. |
| 3 | Create the four migrations per §4.1 (`exercises`, `cycles`, `cycle_days`, `day_exercises`), anonymous classes, correct FK order, `json` for `focus_muscle_groups`, `exercise_id` non-cascading, the two unique composite indexes, `failed_at` + `failure_reason` on `cycles`. | `php artisan migrate` runs on the clone and on fresh SQLite; `php artisan db:table cycles` shows the columns + `cycles_routine_id_sequence_number_unique`; `db:table day_exercises` shows the `exercise_id` FK with no cascade. |
| 4 | Update `docs/plans/data-model.md` §`cycles` (add `failed_at` / `failure_reason` rows, annotate `status`) and §Enums (drop the "*(a validar)*" / "primera lista" caveats on `MuscleGroup`; both enums now shipped). | The doc lists both columns and the final enum values; diff limited to those sections. |
| 5 | Create `app/Models/Exercise.php`, `Cycle.php`, `CycleDay.php`, `DayExercise.php` per §9 "Models & PHPDoc": `HasPublicUuid`, `#[Fillable]`, `casts()`, typed relations. Add `cycles(): HasMany` + `@property-read` to `app/Models/Routine.php`. | Pint + PHPStan clean; `(new Cycle)->getCasts()` has the `status` enum + `focus_muscle_groups`/date casts; `(new Routine)->cycles()` is a `HasMany`; `(new CycleDay)->dayExercises()` is a `HasMany`. |
| 6 | Run `php artisan ide-helper:models --write` for the four new models + `Routine`; `vendor/bin/pint app/Models`; hand-check enum-cast `@property` lines, the `HasPublicUuid` `@method` entries, and appended/accessor attributes. | Every column / relation is in each PHPDoc block; diff limited to the five models; the CI "model PHPDoc up to date" check would pass. |
| 7 | Create `database/factories/ExerciseFactory.php`, `CycleFactory.php` (+ states `generating()`, `failed()`, `active()`, `completed()`), `CycleDayFactory.php`, `DayExerciseFactory.php` per §9 "Factories". | `Cycle::factory()->failed()->create()` persists a row with `status='failed'`, `failed_at`, `failure_reason`, a `uuid`; `Exercise::factory()->create()` has a unique `slug`; `Routine::factory()->has(Cycle::factory())->create()` works; Pint + PHPStan clean. |
| 8 | Create `app/Data/Cycle/CyclePlanData.php`, `CyclePlanDayData.php`, `CyclePlanExerciseData.php` (`spatie/laravel-data`, `readonly` promoted props, `DataCollection` children) per §9 "DTO shape". | `vendor/bin/phpstan` clean; a throwaway assertion constructs a `CyclePlanData` with nested collections; no `#[MapInputName]` needed (explicit construction). |
| 9 | Create `app/Exceptions/Cycle/CycleGenerationException.php` (`final extends \RuntimeException`). | File exists; `throw new CycleGenerationException('…')` type-checks; Pint + PHPStan clean. |
| 10 | Create `app/Ai/Agents/Cycle/CyclePlannerAgent.php`: `final`, `implements Agent, HasStructuredOutput`, `use Promptable`, `#[UseCheapestModel]`, `#[Timeout(120)]`; `instructions()` system prompt per §9; `schema(JsonSchema $schema)` returning the 5-day structured tree with bounds + `MuscleGroup` enum. | Pint + PHPStan clean; `CyclePlannerAgent::fake([...]); CyclePlannerAgent::make()->prompt('x')` returns a `StructuredAgentResponse` whose `->toArray()` is the fake payload. |
| 11 | Create `app/Services/Exercise/ExerciseCatalogService.php` (`final`, `resolve()` per §9). Write `tests/Feature/Exercise/ExerciseCatalogServiceTest.php` (TC-20…TC-24). | `vendor/bin/pest tests/Feature/Exercise/ExerciseCatalogServiceTest.php` green; Pint + PHPStan clean. |
| 12 | Create `app/Services/Cycle/CyclePlannerService.php` (`final`, ctor promotes nothing / no deps beyond the agent via `CyclePlannerAgent::make()`): `planFirstCycle(Routine): CyclePlanData` — `loadMissing('user.athleteProfile')`, build prompt, prompt agent, validate shape, map to DTOs, throw `CycleGenerationException` on any violation. Add `fakeCyclePlanner()` / `cyclePlanPayload()` to `tests/Pest.php`. Write `tests/Feature/Cycle/CyclePlannerServiceTest.php` (TC-17…TC-19). | `vendor/bin/pest tests/Feature/Cycle/CyclePlannerServiceTest.php` green (incl. the malformed-shape dataset); Pint + PHPStan clean. |
| 13 | Create `app/Actions/Cycle/CycleGenerateAction.php` (`final`, ctor promotes `CyclePlannerService` + `ExerciseCatalogService`): `handle(Routine): Cycle` — `firstOrNew` the `sequence_number=1` cycle, reset stale fields, save as `generating`; `try` plan + `DB::transaction` persist (5 `cycle_days` + `day_exercises` via the catalogue) + move to `draft`; `catch (\Throwable)` set `failed` + `failed_at` + `failure_reason` (`Str::limit(…, 480)`), `report($e)`, return `$cycle`. | `final` + `handle()`; PHPStan clean; unit-level assertions deferred to the feature test (TC-1…TC-16). |
| 14 | Edit `app/Jobs/Cycle/GenerateCycleJob.php`: `public int $tries = 1;`, `handle(CycleGenerateAction $action): void { $action->handle($this->routine); }`, drop the placeholder docblock (keep a one-line class docblock). | `php artisan queue:work --once` on a seeded routine produces a `draft` cycle; Pint + PHPStan clean. |
| 15 | Write `tests/Feature/Cycle/GenerateFirstCycleTest.php` covering TC-1…TC-16 (`beforeEach`: none special; each test calls `fakeCyclePlanner()` or a throwing fake, then invokes the job/Action directly). | `vendor/bin/pest tests/Feature/Cycle/GenerateFirstCycleTest.php` all green; every TC-1…TC-16 has a matching test. |
| 16 | Add the arch rules to `tests/Feature/ArchTest.php` (TC-25): `App\Services` final; `App\Ai\Agents` final. | `vendor/bin/pest tests/Feature/ArchTest.php` green; existing rules still pass. |
| 17 | Run `vendor/bin/pint --dirty`, then `vendor/bin/phpstan analyse`, then a final `php artisan ide-helper:models --write` + `vendor/bin/pint app/Models`. | Pint reports no diffs; PHPStan level 6 clean; model PHPDoc in sync with the migrations. |
| 18 | Run `docker compose exec app composer check` (Pint `--test` + PHPStan + full Pest, incl. the new Cycle / Exercise suites and the arch rules). | All three steps green; no regression in Auth / Profile / Routine suites. |
| 19 | Manual live check against `http://localhost:8000` with a real `ANTHROPIC_API_KEY` (optional, not gating): `GET /sanctum/csrf-cookie` → register → login → `PUT /api/v1/profile` → `POST /api/v1/routines` → `php artisan queue:work --once` → inspect `cycles` / `cycle_days` / `day_exercises` (a `draft` cycle, 5 days, prescriptions, rationales); then force a failure (bad key) and confirm `status='failed'` + `failure_reason`. | The queued job produces a coherent `draft` cycle from a real provider; the failure path records `failed` + a reason; routine unaffected. |
| 20 | On merge / branch drop: `docker compose exec pgsql dropdb -U gym --if-exists gym_trainer_generate_first_cycle`; revert `DB_DATABASE` in the worktree `.env`. | The clone is gone; `.env` restored to `gym_trainer`. |

*Process note: branch name, commit messages and PR text follow `CLAUDE.md` /
`AGENTS.md` — English only, no `Co-Authored-By: Claude` / `Claude-Session:`
trailers, `🤖 Generated with Claude Code` footer in the PR description only.*
