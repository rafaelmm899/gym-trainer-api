# Keep the routine's exercises across cycles (cycle N+1 only progresses)

> Derived from the Notion ticket "Generar el ciclo siguiente bajo demanda"
> (Feature: Ciclos & generación IA · MVP · Must · Repo: API · Order 150) — the
> "BUG DE DISEÑO" note added 2026-09-29. Base contract:
> `docs/plans/generate-next-cycle-spec.md` (the endpoint and rollover this
> ticket corrects), `docs/plans/generate-first-cycle-spec.md` (the first cycle,
> untouched), `docs/plans/import-training-day-xlsx-spec.md` (users may edit a
> day's exercises), `docs/product-context.md`, `CLAUDE.md` "The pipeline".

## 1. Context

**Kind:** Refactor (a behavior correction to a shipped feature)

**Stack:** PHP 8.5 · Laravel 13 · PostgreSQL 17 (runtime) / SQLite `:memory:`
(tests) · Pest 4 · `laravel/ai` (structured-output agents) · `spatie/laravel-data`
(DTOs) · Pint · Larastan level 6. Everything runs in Docker.

**Problem statement:** Within one routine, the set of exercises must stay the
same from one cycle to the next; only load, sets, reps, RPE and rest progress.
Exercises change only when the user creates a **new routine**. Today
`POST /api/v1/routines/{routine}/cycles` breaks this: `CyclePlannerService::planNextCycle()`
sends the AI the athlete profile, goal/hint, active recommendations and the
progression summary, but **never the outgoing cycle's exercises or days**, and
`CyclePlannerAgent::instructions()` never asks to keep them. The agent
therefore rebuilds the whole 5-day split from scratch each time (confirmed on
real data: the same routine went from "Upper A/B" in cycle 1 to
"Chest/Shoulders/Triceps" in cycle 2, with different exercises). Any exercise
without a recommendation or progression entry (never trained) has no
representation in the prompt and changes freely.

The fix moves the guarantee out of the prompt and into code. The **server
clones** the outgoing cycle's days and exercises into cycle N+1 (same
`exercise_id`, same order, same day label / focus / rationale). A **new,
narrower AI agent** only *evaluates* each exercise's real performance and
recommends its progression (weight, sets, rep range, RPE, rest). The AI can no
longer choose, add, drop or reorder exercises, so the bug cannot reappear
through a model mistake.

**In scope:**
- `CyclePlannerService::planNextCycle()` rewritten: it receives the outgoing
  cycle, sends the AI one line per **performed** exercise slot (current
  prescription + real performance + active recommendation), and builds cycle
  N+1's `CyclePlanData` by cloning the outgoing structure and applying the AI's
  progression on top.
- New agent `App\Ai\Agents\Cycle\CycleProgressionAgent` (structured output):
  a flat list of per-slot progressions plus a short cycle rationale. It
  replaces `CyclePlannerAgent` on the N+1 path.
- `CyclePlannerAgent` becomes first-cycle-only again: the "continuation"
  wording in `instructions()` and its docblock is removed.
- Exercises **not performed** in the outgoing cycle are copied verbatim by PHP
  and are not sent to the AI.
- Strict validation of the AI response against the outgoing structure (same
  slots, no missing / extra / duplicated slot, per-field bounds); any violation
  is `CycleGenerationException` (`502`), nothing persisted.
- `CyclePlanExerciseData` gains an optional `exerciseId`, and
  `CycleDraftService::persistDay()` uses it when present, so a cloned exercise
  keeps its exact catalogue row instead of round-tripping through a name → slug
  lookup.
- `CycleGenerateAction` passes the outgoing cycle (with `cycleDays.dayExercises.exercise`
  loaded) to the planner.
- Tests: rewrite the N+1 planner / prompt tests, add structure-preservation
  tests at service and endpoint level, add a `fakeCycleProgression()` test
  helper.

**Out of scope:**
- Changing exercises inside an existing routine, in any form: no swap on
  injury notes, no swap on `technique_focus` / `deload` recommendations. To get
  different exercises the user edits a day (XLSX import) or creates a new
  routine. Confirmed with the product owner.
- The first cycle (`planFirstCycle()`, `CyclePlannerAgent` schema, the
  5-day / exercises-per-day rules, recovery rules): untouched.
- Caps on progression size (e.g. max +10 % weight per cycle) in code. The
  AI decides; the prompt only gives guidance. Revisit if the model proves
  erratic.
- Retroactively repairing routines whose cycle 2+ already changed
  exercises: cycle N+1 is derived from whatever the active cycle contains today.
- Any endpoint, request or response shape change; no migration; no config or
  env change. The rollover (`completed` / `incomplete`), recommendation
  `applied` marking, rate limit and `409` / `502` behavior are unchanged.
- The SPA (`gym-trainer-spa/`, separate repository).
- Updating the Notion ticket and `docs/product-context.md`.

---

## 2. API Surface

### 2.1 REST

`POST /api/v1/routines/{routine}/cycles` keeps its contract exactly as
specified in `docs/plans/generate-next-cycle-spec.md` §2.1. Only the *content*
of the returned cycle's `days` changes: it now has the same days and exercises
as the previous cycle.

| Method | Path | Auth | Request | Response | Status codes |
|---|---|---|---|---|---|
| POST | `/api/v1/routines/{routine}/cycles` | `auth:sanctum` + `RoutinePolicy::generateCycle` + `throttle:1,1` (unchanged) | — (no body, unchanged) | Unchanged shape (`CycleResource`, fully nested). New guarantee: `data.days[i]` has the same `label`, `focus_muscle_groups`, `rationale` and the same exercises, in the same order, as the outgoing cycle's day `i`; only `sets`, `rep_min`, `rep_max`, `target_weight_kg`, `target_rpe`, `rest_seconds` and `rationale` per exercise may differ, and `data.split_rationale` is regenerated. | Unchanged: `201` · `409 ROUTINE_NOT_ACTIVE` · `502 AI_GENERATION_FAILED` (now also when the AI response does not match the outgoing structure) · `403` · `404` · `429` · `401` · `419` |

### 2.2 CLI

Not applicable — no CLI commands.

### 2.3 Events

Not applicable — no events. `laravel/ai` emits its own internal framework
events when the agent runs (unchanged); no listeners registered.

---

## 3. UI

### 3.1 Pages

Not applicable — no pages affected.

### 3.2 Components

Not applicable — no components affected.

---

## 4. Database

Not applicable — no data or schema changes. Cycle N+1 is written through the
existing `cycles` / `cycle_days` / `day_exercises` tables via the existing
`CycleDraftService::persistDays()`.

---

## 5. Auth & Authorization

Not applicable — no auth or authorization changes. Same `auth:sanctum` +
`RoutinePolicy::generateCycle` gate as today.

---

## 6. Configuration

Not applicable — no configuration changes. The new agent runs on the same
`config('ai.default')` provider / text model as `CyclePlannerAgent`;
`config('training.cycle.exercises_per_day.*')` is no longer read on the N+1 path
(the outgoing cycle defines the exercise count per day).

---

## 7. Current vs New Behavior

| Behavior | Current | New |
|---|---|---|
| Exercises in cycle N+1 | Chosen by the AI from scratch each time; can differ from cycle N. | Cloned by PHP from the outgoing cycle: same `exercise_id`s, same days, same order. |
| Day structure in cycle N+1 (`label`, `focus_muscle_groups`, `day_rationale`) | Regenerated by the AI. | Copied from the outgoing cycle. |
| What the AI decides for N+1 | The whole 5-day plan. | Only, per performed exercise: `sets`, `rep_min`, `rep_max`, `target_weight_kg`, `target_rpe`, `rest_seconds`, a progression `rationale`; plus one short cycle-level `split_rationale`. |
| Exercise not performed in the outgoing cycle | Prompt says "keep the current target" (a request to the AI, not enforced). | Copied verbatim by PHP (all prescription fields and `rationale`), never sent to the AI. Enforced by code. |
| Planner agent on the N+1 path | `CyclePlannerAgent` (full plan schema, exercises-per-day range, recovery rules). | `CycleProgressionAgent` (progression-only schema). `CyclePlannerAgent` is first-cycle only. |
| Prompt content for N+1 | Profile, goal/hint, active recommendations, progression summary; no exercises. | Profile, goal/hint, and per performed slot: current prescription, real performance, trend/plateau signal, active recommendation (when any). |
| AI response that misses, adds or duplicates an exercise slot | Not applicable (free plan). | `CycleGenerationException` → `502 AI_GENERATION_FAILED`, nothing persisted. |
| Tokens per N+1 generation | ~6k completion tokens (full 5-day plan with rationale per exercise). | Substantially fewer (only the progression fields), easing the Groq free-tier 8k TPM limit. |
| `CyclePlanExerciseData` | `name`, `primaryMuscleGroup`, … resolved to a catalogue row by name. | Optional `exerciseId`; when set, `persistDay()` uses it directly. |

---

## 8. Test Cases

Executable with Pest 4 on SQLite `:memory:` (`RefreshDatabase`). Tests use the
existing `trainingRoutineWithCycle()` helper and a new
`fakeCycleProgression()` helper in `tests/Helpers.php` that fakes
`CycleProgressionAgent` with a payload covering every slot of a given outgoing
cycle (overridable per test). "Slot" = one `day_exercises` row, addressed as
`(day_order, exercise_order)`.

### `POST /api/v1/routines/{routine}/cycles` — extends `tests/Feature/Cycle/GenerateCycleTest.php`

**TC-1:** Cycle N+1 keeps the exact same exercises, days and order (the bug)
- **Given:** `$this->user` with `trainingRoutineWithCycle($this->user)` (5 days × 3 exercises), all days trained with sets; `fakeCycleProgression()`
- **When:** `POST /api/v1/routines/{routine}/cycles`
- **Expect:** `201`; for every day `i` and position `j`, the new cycle's `exercise_id` equals the outgoing cycle's; `label`, `focus_muscle_groups`, `rationale` of each day equal the outgoing day's; counts of days (5) and exercises per day are equal

**TC-2:** The AI's progression is applied to the cloned slots
- **Given:** as TC-1; `fakeCycleProgression()` overriding slot `(1,1)` → `sets: 4`, `rep_min: 6`, `rep_max: 8`, `target_weight_kg: 62.5`, `target_rpe: 8.0`, `rest_seconds: 150`, `rationale: 'Top of range on every set — add load.'`
- **When:** `POST /api/v1/routines/{routine}/cycles`
- **Expect:** `201`; the new cycle's slot `(1,1)` carries exactly those values (same `exercise_id`); `data.split_rationale` equals the faked cycle rationale

**TC-3:** An exercise not performed in the outgoing cycle is copied verbatim and not sent to the AI
- **Given:** as TC-1 but exercise X (slot `(2,1)`) has zero logged sets; `fakeCycleProgression()` (payload without slot `(2,1)`)
- **When:** `POST /api/v1/routines/{routine}/cycles`
- **Expect:** `201`; slot `(2,1)` has identical `sets`, `rep_min`, `rep_max`, `target_weight_kg`, `target_rpe`, `rest_seconds`, `rationale` to the outgoing cycle's; `CycleProgressionAgent::assertPrompted(fn (string $p) => ! str_contains($p, $exerciseX->name))`

**TC-4:** A week with zero performed exercises does not call the AI and clones the whole cycle
- **Given:** `trainingRoutineWithCycle($this->user)` with no sessions; `fakeCycleProgression()`
- **When:** `POST /api/v1/routines/{routine}/cycles`
- **Expect:** `201`; `CycleProgressionAgent::assertNeverPrompted()`; the new cycle equals the outgoing one field by field (except ids / `sequence_number`); `data.split_rationale` is non-empty; the outgoing cycle rolls to `incomplete`

**TC-5:** The prompt lists each performed slot with its prescription, real performance and active recommendation
- **Given:** `trainingRoutineWithCycle($this->user)`; one trained exercise with an `active` recommendation (`action: advance_weight`, distinctive `explanation`); a profile with distinctive `notes`; a routine with distinctive `goal` / `hint`; `fakeCycleProgression()`
- **When:** `POST /api/v1/routines/{routine}/cycles`
- **Expect:** `201`; `CycleProgressionAgent::assertPrompted(...)` contains the profile notes, `goal->value`, `hint`, the exercise name, its prescribed `sets`x`rep_min`-`rep_max` and weight, the actual average weight/reps, `advance_weight` and the explanation

**TC-6:** The prompt tells the AI it must not change exercises and defines the response contract
- **Given:** as TC-5
- **When:** `POST /api/v1/routines/{routine}/cycles`
- **Expect:** `CycleProgressionAgent::assertPrompted(...)` contains the slot references (`day 1, exercise 1` style) and the literal instruction `Return exactly one progression per listed slot`; the agent's `instructions()` contain `Never add, remove, replace or reorder exercises` (asserted by calling `(new CycleProgressionAgent)->instructions()`)

**TC-7:** A response missing a performed slot → `502`, nothing persisted
- **Given:** `trainingRoutineWithCycle($this->user)` all trained; `fakeCycleProgression()` with one slot removed
- **When:** `POST /api/v1/routines/{routine}/cycles`
- **Expect:** `502`, `data.code` = `"AI_GENERATION_FAILED"`; `assertDatabaseCount('cycles', 1)`; the outgoing cycle is still `active`; no recommendation changed

**TC-8:** Extra, unknown or duplicated slot → `502`, nothing persisted (dataset)
- **Given:** dataset — (a) an extra slot `(1,9)` not in the outgoing cycle, (b) slot `(1,1)` twice, (c) `day` 6
- **When:** `POST /api/v1/routines/{routine}/cycles`
- **Expect:** each case `502 AI_GENERATION_FAILED`; `assertDatabaseCount('cycles', 1)`

**TC-9:** An out-of-bounds progression value → `502`, nothing persisted (dataset)
- **Given:** dataset on one slot — `sets: 0`; `rep_min: 10, rep_max: 8`; `target_weight_kg: -5`; `target_rpe: 11`; `rest_seconds: -1`; blank `rationale`
- **When:** `POST /api/v1/routines/{routine}/cycles`
- **Expect:** each case `502 AI_GENERATION_FAILED`; `assertDatabaseCount('cycles', 1)`

**TC-10:** Provider failure → `502`, nothing persisted, outgoing cycle untouched
- **Given:** `trainingRoutineWithCycle($this->user)` all trained; `CycleProgressionAgent::fake(fn () => throw new RuntimeException('provider unavailable'))`
- **When:** `POST /api/v1/routines/{routine}/cycles`
- **Expect:** `502 AI_GENERATION_FAILED`; `assertDatabaseCount('cycles', 1)`; outgoing cycle still `active`

**TC-11:** The same exercise on two days keeps both slots and progresses each independently
- **Given:** an active cycle where exercise E appears on day 1 (slot `(1,1)`) and day 5 (slot `(5,2)`), both trained; `fakeCycleProgression()` giving different weights per slot
- **When:** `POST /api/v1/routines/{routine}/cycles`
- **Expect:** `201`; E is present on both days of the new cycle, each with its own faked weight

**TC-12:** A user-edited outgoing cycle (exercises changed through import) is what gets cloned
- **Given:** an active cycle whose day 1 exercises were replaced by a different catalogue exercise (as after `import-training-day-xlsx`); all trained; `fakeCycleProgression()`
- **When:** `POST /api/v1/routines/{routine}/cycles`
- **Expect:** `201`; day 1 of the new cycle has the replaced exercise, not the original one

**TC-13:** Unchanged behavior — rollover, recommendations, guards (regression)
- **Given:** the existing scenarios in `GenerateCycleTest.php` (rollover `completed` / `incomplete`, `applied` marking, `409`, `403`, `404`, `401`, `429`, uuid-only ids)
- **When:** the existing tests run, with `fakeCyclePlanner()` replaced by `fakeCycleProgression()`
- **Expect:** all pass with their current assertions

### `CycleGenerateAction` — extends `tests/Feature/Cycle/CycleGenerateActionTest.php`

**TC-14:** `handle()` returns a cycle whose exercises match the outgoing cycle's
- **Given:** a routine with an active cycle `sequence_number = 2`, all 5 days trained; `fakeCycleProgression()`
- **When:** `app(CycleGenerateAction::class)->handle($routine)`
- **Expect:** a `Cycle` with `sequence_number = 3`, `cycleDays.dayExercises.exercise` already loaded (no lazy load under `Model::shouldBeStrict()`), exercise ids equal the outgoing cycle's per day

**TC-15:** `handle()` throws `CycleGenerationException` and writes nothing when the response does not match the structure
- **Given:** an active cycle; `fakeCycleProgression()` with a slot removed
- **When:** `handle($routine)`
- **Expect:** throws `CycleGenerationException` (`->statusCode() === 502`); `assertDatabaseCount('cycles', 1)`; the active cycle is unchanged

### `CyclePlannerService::planNextCycle()` — extends `tests/Feature/Cycle/CyclePlannerServiceTest.php`

*(Replaces the existing TC-29 / TC-30 style tests that assumed the full-plan shape.)*

**TC-16:** `planNextCycle()` returns a `CyclePlanData` with the outgoing structure and the AI's values
- **Given:** an outgoing cycle loaded with `cycleDays.dayExercises.exercise`; a progression summary marking every exercise performed; `fakeCycleProgression()`
- **When:** `app(CyclePlannerService::class)->planNextCycle($profile, $goal, $hint, $outgoingCycle, collect(), $summary)`
- **Expect:** 5 days; each day's `label` / `focusMuscleGroups` / `rationale` equal the outgoing day's; each exercise's `exerciseId` equals the outgoing `exercise_id`; the numeric fields equal the faked values

**TC-17:** Malformed progression responses are rejected (dataset)
- **Given:** dataset — missing `progressions`, a progression that is not an object, non-integer `day` / `exercise`, non-numeric `target_weight_kg`, missing `split_rationale`
- **When:** `->planNextCycle(...)`
- **Expect:** every case throws `CycleGenerationException`

**TC-18:** Slots the AI is not asked about are copied verbatim
- **Given:** a summary where one exercise has `performed: false`; `fakeCycleProgression()` without that slot
- **When:** `->planNextCycle(...)`
- **Expect:** that slot's `CyclePlanExerciseData` equals the outgoing prescription field by field (including `rationale`)

**TC-19:** `planNextCycle()` wraps a provider exception in `CycleGenerationException`
- **Given:** `CycleProgressionAgent::fake(fn () => throw new RuntimeException('boom'))`
- **When:** `->planNextCycle(...)`
- **Expect:** throws `CycleGenerationException` with the original exception as `previous`

**TC-20:** `planFirstCycle()` is unaffected (regression)
- **Given:** the existing first-cycle tests in `CyclePlannerServiceTest.php`
- **When:** they run
- **Expect:** all pass unmodified; `(new CyclePlannerAgent)->instructions()` no longer mentions continuing an existing routine

### `CycleDraftService::persistDays()` — extends `tests/Feature/Cycle/CycleDraftServiceTest.php`

**TC-21:** A `CyclePlanExerciseData` with `exerciseId` is persisted against that exact exercise
- **Given:** a persisted `Cycle`; a `CyclePlanData` whose exercise has `exerciseId` = an existing exercise whose `slug` does **not** equal `Str::slug($name)` and a different `name`
- **When:** `app(CycleDraftService::class)->persistDays($cycle, $plan)`
- **Expect:** the `day_exercises` row has `exercise_id` = that id; no new `exercises` row was created

**TC-22:** Without `exerciseId` the name-based catalogue resolution is unchanged (regression)
- **Given:** the existing first-cycle persist tests
- **When:** they run
- **Expect:** all pass unmodified

### Architecture — `tests/Feature/ArchTest.php`

**TC-23:** The new agent obeys the existing conventions
- **Given:** `App\Ai\Agents\Cycle\CycleProgressionAgent`
- **When:** `vendor/bin/pest tests/Feature/ArchTest.php` runs
- **Expect:** passes the existing `App\Ai\Agents` rules (final, implements `Agent` and `HasStructuredOutput`)

---

## 9. Technical Decisions

| Decision area | What was decided | Why |
|---|---|---|
| Where the guarantee lives | In code, not in the prompt. PHP clones the outgoing cycle's days and exercises; the AI only returns progression values. | Confirmed with the product owner. A prompt-only fix ("please keep the exercises") can silently regress with any model or provider; the source ticket's root cause was precisely that the AI was free to redo the plan. Cloning also makes the "never trained exercise changes freely" case impossible. |
| Source of truth for "the routine's exercises" | The outgoing (active) cycle's `day_exercises` in the database, loaded as `cycleDays.dayExercises.exercise`. | A user can edit a day's exercises (XLSX import); the DB reflects that, the original AI plan does not. The action already holds the outgoing cycle (`ensureRoutineActive()` returns it). |
| Fields the AI may change | `sets`, `rep_min`, `rep_max`, `target_weight_kg`, `target_rpe`, `rest_seconds`, per-exercise `rationale`, and one cycle-level `split_rationale`. Confirmed with the product owner. | Matches the recommendation actions (`advance_weight`, `add_reps`, `add_set`, `deload`, `hold`, `technique_focus`) plus intensity / rest. |
| Fields PHP always copies | Day `label`, `focus_muscle_groups`, `rationale` (`day_rationale`), day order, exercise order, `exercise_id`. `split_rationale` is regenerated by the AI as a short summary of the cycle's progression. | The split is not re-decided, so its explanation stays valid; `split_rationale` describes the week, and the week's numbers changed. Default accepted by the product owner. |
| Exceptions to "same exercises" | None. No swap on injury notes, on `technique_focus`, or on `deload`. | Confirmed with the product owner: only a new routine (or an explicit user edit) changes exercises. |
| New agent vs. reuse `CyclePlannerAgent` | New `CycleProgressionAgent`; `CyclePlannerAgent` reverts to first-cycle only. | The two jobs now have different output schemas (full plan vs. flat list of progressions) and different instructions (5-day split, exercises-per-day, recovery rules vs. "never change exercises"). The earlier decision to share one agent held only while the schema was identical (`generate-next-cycle-spec.md` §9); it no longer is. One agent with two schemas is not possible. |
| Service placement | `planNextCycle()` stays in `CyclePlannerService`, with a new signature taking the outgoing `Cycle`. No new Service class. | It reuses the service's existing helpers (`requireString`, `requireInt`, `optionalRpe`, exception mapping, `promptAgent`-style error wrapping) and is still "wrap an agent, validate its output, return `CyclePlanData`". A separate class would only add indirection (`CLAUDE.md` rule 6). |
| Slot addressing | Each `day_exercises` row is a slot `(day, exercise)` = (`cycle_days.order`, `day_exercises.order`), both 1-based. The prompt lists slots with these references; the AI returns `progressions[]` with `day` and `exercise` integers. | The same exercise can appear on two days (real data), so exercise name or id is ambiguous; ordinals are unambiguous, short, and cheap to validate as a set. |
| Response validation | The set of `(day, exercise)` pairs in the response must equal exactly the set of slots asked about: no missing, no extra, no duplicates. Each value is bounded (`sets` ≥ 1; `rep_min`, `rep_max` ≥ 1 and `rep_min` ≤ `rep_max`; `target_weight_kg` ≥ 0; `target_rpe` null or 0–10; `rest_seconds` ≥ 0; non-blank `rationale`). Any violation → `CycleGenerationException`. | Same all-or-nothing, "unusable plan → 502, nothing persisted" contract as today. Reuses the existing bounds. |
| Unperformed exercises | Not sent to the AI; copied verbatim by PHP (all fields, including `rationale`). "Performed" is the existing `ExerciseProgressionData::performed` from `ProgressionSummaryService`. If no exercise was performed, the AI is not called at all and `split_rationale` is a fixed sentence ("No exercise was trained last cycle; the prescription is unchanged."). | Makes the existing "no data — keep the current target" rule a guarantee instead of a request, saves tokens, and avoids an AI call with nothing to evaluate. Keeps the `applied` rollover semantics unchanged (untrained exercises keep their `active` recommendation). |
| Recommendations for performed exercises | Still sent in the prompt (one line per slot, with `action` and `explanation`) and still marked `applied` after the rollover by the existing logic. | Unchanged from `generate-next-cycle-spec.md`. The AI now "evaluates and recommends an advance" using the recommendation plus real performance, as the source ticket asks. |
| No progression caps | No code-level limit on how much weight / sets / reps can grow per cycle. The instructions say to progress conservatively (small, safe steps; deload on a `down` trend or plateau with high RPE). | Confirmed default; the recommendation actions already carry the AI's judgment from the session analysis, and hard caps are speculative. Bounds validation still rejects impossible values. |
| Agent limits | `#[Timeout(60)]` as today; `#[MaxTokens(4000)]`, well under the planner's 7000, since only ~5×N short progressions are returned. Strict-mode schema, every property `required` (nullable when optional), no array-length keywords — the same constraints and reasons documented on `CyclePlannerAgent::schema()`. | The output is much smaller than a full plan; a tighter cap keeps prompt + cap inside Groq's free-tier 8k TPM budget. |
| `CyclePlanExerciseData::exerciseId` | New optional `?int $exerciseId = null`. `CycleDraftService::persistDay()` uses `$exercise->exerciseId ?? $this->catalog->resolve(...)->id`. | The catalogue resolves by `slug` derived from the name; a row whose slug was set another way (or a future rename) would silently yield a different exercise. Passing the id makes "same exercise" a guarantee. One optional field on an existing DTO is simpler than a second persistence path. |
| Reuse of `persistDays()` | The cloned + progressed result is a `CyclePlanData`, persisted through the unchanged `CycleDraftService::persistDays()` inside the existing `CycleGenerateAction` transaction. | No second write path; the Action's flow (guard → plan outside the transaction → transaction) is unchanged. |
| First cycle | Untouched. | The first cycle is the one moment the AI legitimately picks exercises. |
| Existing bad cycles | Not repaired. | Cycle N+1 derives from the active cycle as it is; a user who dislikes the current exercises can edit or create a new routine. |
| Git artifacts | Branch `feature/keep-cycle-exercises`; English only; no AI-attribution trailers or footers in commits or the PR; single PR, spec-first (this document first, implementation added to the same PR only after the spec is approved). | Repo `CLAUDE.md` / `AGENTS.md` "Git" rules take precedence over any session-level attribution reminder. |

---

## 10. Work Plan

Inner-most first (DTO → agent → service → action → tests). Each task's DoD is
the artifact existing plus Pint (by path) → PHPStan (level 6) clean and, where
it carries logic, its focused test authored in the same task.

| # | Task | Definition of Done |
|---|---|---|
| 1 | Add optional `exerciseId` to `App\Data\Cycle\CyclePlanExerciseData`; update `CycleDraftService::persistDay()` to use it when set. Extend `tests/Feature/Cycle/CycleDraftServiceTest.php` (TC-21, TC-22). | `vendor/bin/pest tests/Feature/Cycle/CycleDraftServiceTest.php` green (old + new); Pint + PHPStan clean. |
| 2 | Create `App\Ai\Agents\Cycle\CycleProgressionAgent` (`Agent`, `HasStructuredOutput`, `#[Timeout(60)]`, `#[MaxTokens(4000)]`): instructions ("evaluate performance, recommend progression, never add / remove / replace / reorder exercises, return exactly one progression per listed slot, kilograms, conservative steps"), strict schema (`split_rationale`, `progressions[]` of `day`, `exercise`, `sets`, `rep_min`, `rep_max`, `target_weight_kg`, `target_rpe` nullable, `rest_seconds`, `rationale`). Add `fakeCycleProgression()` and a payload helper to `tests/Helpers.php`. | Class loads; `fakeCycleProgression()` returns a schema-valid payload for a given cycle; ArchTest rules (TC-23) green; Pint + PHPStan clean. |
| 3 | Reword `CyclePlannerAgent::instructions()` and its docblock to first-cycle only (remove the "continuation" / "Active recommendations" / "Progression summary" wording). | `vendor/bin/pest tests/Feature/Cycle/CyclePlannerServiceTest.php` first-cycle tests green unmodified (TC-20); Pint + PHPStan clean. |
| 4 | Rewrite `CyclePlannerService::planNextCycle()` to `(AthleteProfile, Goal, ?string, Cycle $outgoing, Collection $recommendations, array $progressionSummary): CyclePlanData` per §9: clone structure, prompt only performed slots, call `CycleProgressionAgent`, validate the slot set and bounds, merge progression, copy unperformed slots verbatim, skip the AI call when nothing was performed. Remove `buildNextCyclePrompt()` and any code it made dead. Rewrite the N+1 tests in `CyclePlannerServiceTest.php` (TC-16 … TC-19). | `vendor/bin/pest tests/Feature/Cycle/CyclePlannerServiceTest.php` green; Pint + PHPStan clean; no dead private methods. |
| 5 | Update `CycleGenerateAction` to eager-load `cycleDays.dayExercises.exercise` on the outgoing cycle and pass it to `planNextCycle()`. Update `tests/Feature/Cycle/CycleGenerateActionTest.php` (TC-14, TC-15). | `vendor/bin/pest tests/Feature/Cycle/CycleGenerateActionTest.php` green; no lazy-load exception under `Model::shouldBeStrict()`; Pint + PHPStan clean. |
| 6 | Update `tests/Feature/Cycle/GenerateCycleTest.php`: switch to `fakeCycleProgression()`; add TC-1 … TC-12; keep the regression scenarios (TC-13). | `vendor/bin/pest tests/Feature/Cycle/GenerateCycleTest.php` green. |
| 7 | Search for stale references (`grep -rn "buildNextCyclePrompt\|planNextCycle" app tests`) and update the docblocks of `CyclePlannerService`, `CyclePlannerAgent`, `CycleDraftService` and `ProgressionSummaryService` that describe the old N+1 flow. | No stale reference; docblocks describe the new flow. |
| 8 | `vendor/bin/pint` (by path for touched files), `vendor/bin/phpstan analyse`, then `composer check` (Pint `--test` + PHPStan + full Pest, every suite). | All three green; no regression anywhere (rollover, recommendations, first cycle, routine creation). |
| 9 | Manual live check against `http://localhost:8000` with a real `AI_PROVIDER_API_KEY`: create a routine, complete a few days with sets, `POST /api/v1/routines/{routine}/cycles`, and compare cycle 1 vs 2: same exercises and days in the same order; progression visible on the trained exercises; the untrained ones identical; check the response time and, on Groq, that it stays within the token budget. Review `GET /docs/api` for the unchanged route. | Same exercise list per day in both cycles; trained exercises show a coherent progression with a rationale; `409` / `502` / `429` behavior unchanged. |
| 10 | Post-merge follow-ups, outside this PR: mark the Notion ticket's design bug as fixed, and update `docs/product-context.md` and `generate-next-cycle-spec.md` §9 ("`CyclePlannerService::planNextCycle()`", "one Agent") to point at this spec. | Noted in the PR description as follow-ups. |

*Process note: branch name, commit messages and PR text follow `CLAUDE.md` /
`AGENTS.md` — English only, no AI attribution of any kind. Per the user's
request for this ticket, the PR carries **only this spec** until it is reviewed
and confirmed (possibly after revision rounds); the implementation (tasks 1-9)
is then added to the same PR.*
