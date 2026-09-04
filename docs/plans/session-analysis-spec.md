# Analyze a completed session and produce exercise recommendations

> Derived from the Notion ticket "Recibir recomendaciones al cerrar el día de
> entrenamiento" (Feature: Recomendaciones IA · MVP · Must · Repo: API ·
> Order 130, `https://app.notion.com/p/3ce5cf08db2d8156923fd3cb7f68c340`) and
> the planning conversation with the product owner (this session), which
> revised the ticket's acceptance criteria and the `exercise_recommendations`
> shape documented in `docs/plans/data-model.md` — see §9 "Deviations from
> `data-model.md`". Base contract: `docs/product-context.md` §2 / §4 (step 5) /
> §5, `docs/plans/data-model.md` §`exercise_recommendations` / §`set_logs` /
> §`day_exercises` / §Identificadores / §Enums, `docs/plans/complete-session-spec.md`
> (the shipped ticket this one continues — `SessionAnalysisJob` placeholder,
> `TrainingSession.analysis_state`), `docs/plans/generate-first-cycle-spec.md`
> (the agent + Service pattern this ticket replicates — `CyclePlannerAgent` /
> `CyclePlannerService` / `CycleGenerationException` / `fakeCyclePlanner()`),
> `docs/plans/domain-exception-handling-spec.md`, and `CLAUDE.md` "The
> pipeline" / "Jobs & AI" / "Layout".

## 1. Context

**Kind:** Brownfield Feature — `SessionAnalysisJob` (dispatched by
`SessionCloseAction` on every `POST /api/v1/sessions/{session}/complete`,
shipped in PR #20) is an empty placeholder; `TrainingSession.analysis_state`
sits at `pending` forever. This ticket gives the job a real body: an AI agent
analyzes the sets just logged and writes one recommendation per exercise
trained that day.

**Stack:** PHP 8.5 · Laravel 13 · PostgreSQL 17 (runtime) / SQLite `:memory:`
(tests) · Pest 4 (`pest-plugin-laravel`, `RefreshDatabase` wired) ·
`laravel/ai` 0.6.8 (structured-output agents) · `spatie/laravel-data` 4 ·
`QUEUE_CONNECTION=sync` in tests · Pint · Larastan level 6. Everything runs in
Docker.

**Problem statement:** A user logs sets and closes a session, but nothing ever
tells them what to do differently next time. `docs/product-context.md` §4
step 5: on close, the AI must look at what was actually done that day and
leave, per exercise trained, a concrete next-time target (weight, sets, reps),
an `action`, and an explanation — asynchronously, without blocking the close,
and without losing the session if the analysis fails.

**Product-owner decisions recorded from this session's planning conversation**
(these **replace** what `docs/plans/data-model.md` and the original Notion AC
say — see §9 for the full list and rationale):

- **No confidence level.** The original AC ("...una acción, un nivel de
  confianza y una explicación") and `data-model.md`'s `RecommendationConfidence`
  enum are dropped. A recommendation is action + numeric target + explanation,
  nothing else. The Notion ticket's AC was edited during this session's
  planning conversation to drop the confidence clause.
- **No recommendation history.** `data-model.md` documented `status`
  (`active` / `superseded` / `applied`) with a partial unique index. Rejected:
  this ticket's AC only requires that a new recommendation *replace* the old
  one, which a plain `updateOrCreate` on a normal unique key does without any
  status machinery. `exercise_recommendations` has **exactly one row** per
  `(user_id, routine_id, exercise_id)`, overwritten on every analysis. If the
  future cycle-N+1 ticket (Order 150) needs to distinguish "used in the last
  rollover" from "not yet used", it adds that column itself.
- **Recommendation scope confirmed as documented:** `(user, routine, exercise)`
  — a new routine starts with no recommendation for an exercise even if the
  user trained it for months in a previous, now-archived routine
  (`docs/product-context.md` §2: "cada rutina lleva sus propias
  recomendaciones").
- **Analysis context is single-session, routine-scoped, no cross-session
  progression summary.** Per exercise trained in the session being closed, the
  agent is given: the *existing* `exercise_recommendations` row for
  `(user, routine, exercise)` if one exists (the target the user was training
  towards), else the `day_exercises` prescription for that exercise on the
  session's `cycle_day` if there is one, else nothing (first time, free
  session) — plus the sets actually logged in *this* session. It never looks at
  other sessions or other routines. The multi-session "resumen de progresión"
  in `docs/product-context.md` §5 / §7 belongs to the separate, later,
  cycle-N+1 ticket (Order 150) and is not built here.
- **Free (off-plan) sessions are analyzed too.** `cycle_day_id === null` only
  means there is no prescription baseline to fall back on; it does not exempt
  the session from analysis.
- **Retry is queue-native only.** `SessionAnalysisJob` gets `$tries` /
  `backoff()`; there is no retry endpoint or manual action. Once retries are
  exhausted, `analysis_state` becomes `failed` via the job's `failed()` hook;
  recovery from there is `php artisan queue:retry` (or a future ticket), never
  a new client-facing action.
- **Analysis failure must never undo the session close, nor fail the HTTP
  response.** In production (`QUEUE_CONNECTION=database`) this is true by
  construction and needs no defensive code: `SessionAnalysisJob::dispatch($session)`
  only inserts a `jobs` row and returns immediately — `handle()` runs later, in
  a fully separate `queue:work` process, with no way to affect the request that
  closed the session. `SessionCloseAction` is **not modified** by this ticket;
  it keeps dispatching as the last line inside its `DB::transaction`, exactly
  as it already did.
  A design detour is worth recording: `phpunit.xml` sets `QUEUE_CONNECTION=sync`
  for tests, under which `dispatch()` runs the job **inline** — not actually
  asynchronous at all, despite `ShouldQueue`. An early version of this ticket
  changed `SessionCloseAction` (moving the dispatch after its transaction,
  wrapped in a `try`/`catch`, briefly making the job `ShouldQueueAfterCommit`)
  specifically to make a *sync-driver-only* failure mode behave under tests.
  That is exactly backwards: a test-environment quirk was being fixed by adding
  permanent complexity to production code. The corrected approach instead keeps
  `SessionCloseAction` untouched and separates the two things tests were
  conflating — see §9 "Test strategy: don't run the job through HTTP" and §8.

**In scope:**

- **`exercise_recommendations` table** — one row per `(user_id, routine_id,
  exercise_id)`, per §9's revised shape (no `status`, no `confidence`).
- **`App\Enums\Recommendation\RecommendationAction`** — the only new enum:
  `advance_weight` / `hold` / `add_reps` / `add_set` / `deload` /
  `technique_focus`.
- **`App\Models\ExerciseRecommendation`** + factory.
- **`App\Ai\Agents\Recommendation\SessionAnalystAgent`** — a `laravel/ai`
  structured-output agent, one call per session covering every exercise
  trained that day (never one call per exercise —
  `docs/product-context.md` §5).
- **`App\Services\Recommendation\SessionAnalystService::analyze(TrainingSession): array<ExerciseRecommendationData>`**
  — gathers each trained exercise's baseline + actual sets, builds the prompt,
  invokes the agent, validates and maps the response. Throws
  `App\Exceptions\Recommendation\SessionAnalysisException` on any failure.
- **`App\Exceptions\Recommendation\SessionAnalysisException extends DomainException`**
  — never rendered over HTTP in this ticket (nothing here has an HTTP caller);
  kept in the `DomainException` shape purely for consistency with
  `CycleGenerationException`'s "Service throws a typed exception on AI
  failure" pattern, and so a future read endpoint can reuse `errorCode()` /
  `statusCode()` without a rewrite.
- **`App\Actions\Session\SessionAnalyzeAction::handle(TrainingSession): void`**
  — calls the Service, then in one transaction upserts every
  `ExerciseRecommendation` row and moves `analysis_state` to `done`.
- **`App\Jobs\Session\SessionAnalysisJob`** — real `handle(SessionAnalyzeAction)`
  (was an empty placeholder): sets `analysis_state = processing`, delegates to
  the Action. `$tries = 3`, `backoff()` returns `[30, 120]`, `failed(Throwable)`
  sets `analysis_state = failed`. Stays `ShouldQueue` (unchanged).
  `App\Actions\Session\SessionCloseAction` is **not modified** — see §1 / §9.
- **`App\Data\Recommendation\ExerciseRecommendationData`** — the Service's
  per-exercise output DTO.
- Test AI-fake helpers in `tests/Helpers.php`
  (`fakeSessionAnalyst()` / `sessionAnalysisPayload()`), mirroring
  `fakeCyclePlanner()` / `cyclePlanPayload()`.
- **`docs/plans/data-model.md`** — `exercise_recommendations` section, the
  Enums table, and the "Decisiones tomadas" table rewritten per §9.
- **Three existing `tests/Feature/Session/CompleteTrainingSessionTest.php`
  cases gain `Bus::fake([SessionAnalysisJob::class])`** — see §9 "Test
  strategy". They complete a session successfully without previously faking
  the bus, which was harmless while the job was an empty placeholder; now that
  it does real work, leaving them unfaked would make this endpoint's own test
  file depend on a real (or fakeable-but-unfaked) AI call.
- Pest unit coverage of `SessionAnalysisJob` (calling `handle()` / `failed()`
  directly, not through HTTP) for every acceptance criterion.

**Out of scope:**

- **`GET /api/v1/routines/{routine}/recommendations`** — "Ver las
  recomendaciones vigentes de mi rutina" (Order 140), a separate, already-
  groomed ticket. No `ExerciseRecommendationResource`, no Policy, no route is
  added here.
- **SPA screens** — "Pantalla: analizando tu día" (Order 250) and "Pantalla:
  panel de recomendaciones pendientes" (Order 350) live in `gym-trainer-spa/`.
- **The cycle-N+1 progression summary, `RecommendationStatus`, and the
  `applied` transition** — Order 150. Nothing in this ticket writes or reads
  a `status` column; there isn't one.
- **A retry endpoint or manual re-analysis action.** Retry is queue-native
  only (`$tries` / `backoff()`); see "Product-owner decisions" above.
- **Cross-routine or cross-session progression.** The agent only ever sees the
  session being closed plus the current single "next-time target" for each
  exercise in the current routine.
- **Exercise-name normalization / catalog resolution.** Unlike
  `CyclePlannerAgent` (which invents free-text exercise names later resolved
  by `ExerciseCatalogService`), every exercise here is already a catalogued
  `Exercise` row — it came from an existing `SetLog.exercise_id`. The agent is
  never asked to name or identify an exercise.
- **`RecommendationConfidence`.** Dropped entirely — see "Product-owner
  decisions".
- Rate limiting — not a client-facing endpoint.
- The `gym-trainer-spa/` frontend (separate repository).

---

## 2. API Surface

### 2.1 REST

Not applicable — no new or changed REST endpoint. `SessionAnalysisJob` runs
asynchronously off the existing `POST /api/v1/sessions/{session}/complete`
(unchanged request/response contract, `complete-session-spec.md` §2.1).

### 2.2 CLI

Not applicable — no CLI commands.

### 2.3 Events

No domain events. One existing queued job dispatch gains a real consumer:

| Event name | Producer | Consumer | Payload | Trigger condition |
|---|---|---|---|---|
| `SessionAnalysisJob` dispatch (unchanged dispatch site) | `App\Actions\Session\SessionCloseAction` | `App\Jobs\Session\SessionAnalysisJob` (queue `database`; real `handle()`) | The completed `TrainingSession` (public property `session`) | Every successful `POST /api/v1/sessions/{session}/complete`, as the last line inside the transaction that closes the session (unchanged from `complete-session-spec.md`) |

---

## 3. UI

### 3.1 Pages

Not applicable — no pages affected. This is a JSON REST API; the "analizando
tu día" screen (Order 250) lives in `gym-trainer-spa/`.

### 3.2 Components

Not applicable — no components affected.

---

## 4. Database

### 4.1 Schema changes

| Table | Action | Details |
|---|---|---|
| `exercise_recommendations` | Create | `id` bigint PK · `uuid` uuid **`unique`** · `user_id` bigint FK → `users.id`, `constrained()->cascadeOnDelete()` · `routine_id` bigint FK → `routines.id`, `constrained()->cascadeOnDelete()` · `exercise_id` bigint FK → `exercises.id`, `constrained()->restrictOnDelete()` (the catalogue is permanent — matches `set_logs.exercise_id`) · `source_session_id` bigint FK → `training_sessions.id`, **nullable**, `constrained('training_sessions')->nullOnDelete()` (traceability only) · `target_weight_kg` `decimal(6,2)` — **not nullable** (always a complete, usable next-time target, unlike `day_exercises.target_weight_kg`) · `target_sets` `unsignedSmallInteger` · `target_rep_min` `unsignedSmallInteger` · `target_rep_max` `unsignedSmallInteger` · `action` `string` (a `RecommendationAction` value) · `explanation` `text` · `created_at` / `updated_at`. **Unique** `(user_id, routine_id, exercise_id)` — plain, not partial. |

Notes:

- New migration file:
  `database/migrations/2026_09_04_130000_create_exercise_recommendations_table.php`,
  anonymous class. Sorts after
  `2026_09_04_120000_add_note_and_perceived_effort_to_training_sessions_table.php`.
- `target_rep_min` / `target_rep_max` (a range) matches `day_exercises`'
  prescription shape and `data-model.md` decision #3 ("Reps (prescripción y
  recomendación): Rango rep_min/rep_max") — the one part of the original
  documented shape this ticket keeps unchanged.
- No `status`, no `confidence` column — see §9 "Deviations from
  `data-model.md`".
- `down()`: `Schema::dropIfExists('exercise_recommendations')`.
- **Doc update:** `docs/plans/data-model.md` §`exercise_recommendations`
  rewritten (drop `status` / `confidence` rows and their "Reglas"; keep
  `source_session_id`); §Enums drops `RecommendationStatus` /
  `RecommendationConfidence`; "Decisiones tomadas" row #4 (`confidence`)
  removed; the "Sin tabla: resumen de progresión" paragraph's
  `confidence: low` mention is dropped (the `hold` fallback there now reads
  "mismos peso/series/reps" only).
- **Database isolation (`CLAUDE.md`):** this branch adds a migration:
  `docker compose exec pgsql createdb -U gym -T gym_trainer gym_trainer_ia_recommendations`,
  set `DB_DATABASE=gym_trainer_ia_recommendations` in this worktree's `.env`;
  drop the clone and revert `.env` on merge. The Pest suite uses SQLite
  `:memory:`, unaffected.

### 4.2 Seeds

Not applicable — no seeds.

---

## 5. Auth & Authorization

### 5.1 Authentication

Not applicable — no HTTP surface. The job runs off an already-authenticated,
already-authorized `complete` request; nothing in this ticket introduces a new
actor or a new auth check.

### 5.2 Authorization

Not applicable — no authorization changes. No new Policy, no new ability. The
job operates on the `TrainingSession` (and its `user_id` / `routine_id`) that
`SessionCloseAction` already resolved and authorized.

---

## 6. Configuration

**Environment variables:**

| Variable | Value / Source | Purpose |
|---|---|---|
| `DB_DATABASE` | `gym_trainer_ia_recommendations` (this worktree's `.env` only, during development) | Points the worktree at a clone of `gym_trainer` so the new migration never runs against the shared database. Reverted on merge. |
| `AI_PROVIDER` / `AI_PROVIDER_API_KEY` / `AI_PROVIDER_MODEL` | Already in `.env.example` (unchanged) | `SessionAnalystAgent` resolves the same `config('ai.default')` provider and text model as `CyclePlannerAgent`. Needed only for a live manual check; Pest fakes the agent. |

No new keys added to `.env.example`. `phpunit.xml` already sets
`QUEUE_CONNECTION=sync`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`.

**Config / non-source files modified:**

| File | Change |
|---|---|
| `docs/plans/data-model.md` | §`exercise_recommendations`, §Enums, "Decisiones tomadas" row #4, "Sin tabla: resumen de progresión" — per §4.1 and §9. |
| `tests/Helpers.php` | Add `sessionAnalysisPayload(array $overrides = [])` + `fakeSessionAnalyst(?Closure $responder = null)`. |

No change to `routes/api.php`, `config/ai.php`, `config/queue.php`,
`bootstrap/app.php`, `phpunit.xml`, `composer.json`,
`tests/Feature/ArchTest.php` (the existing `App\Actions` / `App\Services` /
`App\Ai\Agents` wildcard rules already cover this ticket's new classes).

---

## 7. Current vs New Behavior

| Behavior | Current | New |
|---|---|---|
| `SessionAnalysisJob` | `implements ShouldQueue`; empty `handle()`. `analysis_state` stays `pending` forever. | Still `implements ShouldQueue`; real `handle(SessionAnalyzeAction)` moves `pending → processing → done`; `$tries = 3`, `backoff() = [30, 120]`; `failed()` moves to `failed` once retries are exhausted. |
| `SessionCloseAction` | Dispatches `SessionAnalysisJob::dispatch($session)` as the last line inside its `DB::transaction` closure. | Unchanged — see §1 / §9. |
| Exercise recommendations | Do not exist — no table, no model. | `exercise_recommendations`: one row per `(user_id, routine_id, exercise_id)`, upserted by every analysis. |
| AI usage | One agent (`CyclePlannerAgent`, cycle planning only). | Adds `SessionAnalystAgent` — one structured-output call per completed session, covering every exercise trained that day. |
| `tests/Feature/Session/CompleteTrainingSessionTest.php` | 3 of 13 cases complete a session without faking the bus (harmless — the placeholder `handle()` could never throw). | Those 3 gain `Bus::fake([SessionAnalysisJob::class])` — see §9. |
| Testing `SessionAnalysisJob`'s own logic | No test exercised it (it was empty). | `tests/Unit/Session/SessionAnalysisJobTest.php` calls `handle()` / `failed()` directly — not through HTTP or a real dispatch. |
| `docs/plans/data-model.md` §`exercise_recommendations` | Documents `status` (`active`/`superseded`/`applied`, partial unique index) and `confidence` (`low`/`medium`/`high`). | Both dropped; a plain unique `(user_id, routine_id, exercise_id)` replaces the partial index. |

---

## 8. Test Cases

Executable with Pest 4 on SQLite `:memory:` (`RefreshDatabase`, already
wired).

**Test strategy — the job is tested directly, not through HTTP.** Under
`QUEUE_CONNECTION=sync` (`phpunit.xml`), `SessionAnalysisJob::dispatch($session)`
runs the job **inline** — a `sync`-driver quirk, not real asynchrony (see §1 /
§9). Rather than exploit that quirk to exercise the job through
`POST .../complete` (which an early version of this ticket did, and which led
to modifying `SessionCloseAction` just to keep that path safe under `sync`),
every acceptance criterion below is tested by calling
`(new SessionAnalysisJob($session))->handle(app(SessionAnalyzeAction::class))`
/ `->failed($exception)` **directly** — exactly how a real queue worker invokes
them, with no dependency on which queue driver happens to be configured. This
keeps `SessionCloseAction` completely out of the picture: it is proven to
dispatch the job by the **existing**, unchanged `Bus::fake()` assertions in
`tests/Feature/Session/CompleteTrainingSessionTest.php` (three of which now
also fake the bus for a case that used to complete for real — see §9).

New helpers added to `tests/Helpers.php` (registered via
`composer.json` `autoload-dev.files`, already listing this file):

```php
function sessionAnalysisPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'recommendations' => [
            [
                'target_weight_kg' => 22.5,
                'target_sets' => 4,
                'target_rep_min' => 8,
                'target_rep_max' => 10,
                'action' => 'advance_weight',
                'explanation' => 'Completed every set at the top of the rep range — add load next time.',
            ],
        ],
    ], $overrides);
}

function fakeSessionAnalyst(?Closure $responder = null): void
{
    // The fake closure receives the rendered prompt (Laravel\Ai\Gateway\FakeTextGateway
    // passes it as the first argument) — count how many exercises the prompt
    // lists and return exactly that many recommendations, in order, so a test
    // that logs sets for more than one exercise doesn't need a bespoke fake.
    App\Ai\Agents\Recommendation\SessionAnalystAgent::fake($responder ?? function (string $prompt): array {
        $count = max(1, substr_count($prompt, 'Exercise:'));

        return ['recommendations' => array_fill(0, $count, sessionAnalysisPayload()['recommendations'][0])];
    });
}
```

### `App\Jobs\Session\SessionAnalysisJob` — `tests/Unit/Session/SessionAnalysisJobTest.php`

Calls `(new SessionAnalysisJob($session))->handle(app(SessionAnalyzeAction::class))`
directly (a helper `runAnalysisJob(TrainingSession $session): void` wraps this
one line) — the same call shape a real queue worker uses, without HTTP or a
real dispatch. Needs the app + a real DB (`RefreshDatabase`), like
`SessionCompletionServiceTest`.

**TC-1:** Analyzing a session with one trained exercise produces one recommendation and moves `analysis_state` to `done` (AC1, AC2)
- **Given:** `fakeSessionAnalyst()`; an authenticated user; `$session = openFreeSession($user)`; one `SetLog` logged for `$exercise = Exercise::factory()->create()`
- **When:** `runAnalysisJob($session)`
- **Expect:** `ExerciseRecommendation::query()->count() === 1`; the row has `user_id = $session->user_id`, `routine_id = $session->routine_id`, `exercise_id = $exercise->id`, `source_session_id = $session->id`; reloading `$session`, `analysis_state === AnalysisState::Done`

**TC-2:** Every field of the recommendation matches the agent's structured output (AC2)
- **Given:** `SessionAnalystAgent::fake(fn () => ['recommendations' => [['target_weight_kg' => 30.0, 'target_sets' => 5, 'target_rep_min' => 6, 'target_rep_max' => 8, 'action' => 'add_set', 'explanation' => 'Solid session — add a set.']]])`; a session with one set for one exercise
- **When:** `runAnalysisJob($session)`
- **Expect:** the `exercise_recommendations` row has `target_weight_kg == 30.0`, `target_sets === 5`, `target_rep_min === 6`, `target_rep_max === 8`, `action === 'add_set'`, `explanation === 'Solid session — add a set.'`

**TC-3:** Multiple exercises trained in one session each get their own recommendation (product-context §5: one call per session, not per exercise)
- **Given:** `fakeSessionAnalyst()`; a session with sets logged against three distinct exercises
- **When:** `runAnalysisJob($session)`
- **Expect:** `ExerciseRecommendation::query()->count() === 3`, one row per exercise; `SessionAnalystAgent::assertPrompted(fn ($prompt) => substr_count($prompt->prompt, 'Exercise:') === 3)` (one call's prompt named all three — not one call per exercise; `laravel/ai` 0.6.8 has no call-count assertion, so the three-row count above plus this content check is the proof)

**TC-4:** A new analysis replaces the previous recommendation for the same exercise in the same routine (AC3)
- **Given:** an existing `ExerciseRecommendation` row for `(user, routine, exercise)` with `target_weight_kg = 20.0`; `SessionAnalystAgent::fake(fn () => ['recommendations' => [['target_weight_kg' => 22.5, 'target_sets' => 4, 'target_rep_min' => 8, 'target_rep_max' => 10, 'action' => 'advance_weight', 'explanation' => '...']]])`; a new session for the same user/routine with a set for that same exercise
- **When:** `runAnalysisJob($session)`
- **Expect:** `ExerciseRecommendation::query()->count() === 1` (no new row — the existing one was updated); reloading it, `target_weight_kg == 22.5`

**TC-5:** The agent receives the previous recommendation as the comparison baseline, not the original cycle prescription (the curl-de-bíceps example from planning)
- **Given:** a planned session (`openPlannedSession`) whose `cycle_day` prescribes an exercise at `sets=4, rep_min=10, rep_max=10, target_weight_kg=20.0`; an existing `ExerciseRecommendation` for that `(user, routine, exercise)` already at `target_weight_kg=22.5, target_rep_min=12, target_rep_max=12` (i.e. last time's suggested step-up); the user logs sets at `20kg × 11 reps`; `fakeSessionAnalyst()`
- **When:** `runAnalysisJob($session)`
- **Expect:** `SessionAnalystAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, '22.50kg') && str_contains($prompt->prompt, '12-12 reps') && ! str_contains($prompt->prompt, 'original prescription'))` — the *previous recommendation's* target, not the original `day_exercise` prescription (`20.0` / `10`)

**TC-6:** No previous recommendation and no `cycle_day` (free session, first time) — the agent decides from today's sets alone
- **Given:** `fakeSessionAnalyst()`; `openFreeSession($user)` with sets logged for an exercise never trained before in this routine
- **When:** `runAnalysisJob($session)`
- **Expect:** exactly one `exercise_recommendations` row created; `SessionAnalystAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'No baseline'))`

**TC-7:** A different routine's recommendation for the same exercise is untouched (routine scope, AC3 / product-context §2)
- **Given:** the user has an `ExerciseRecommendation` for `exercise` under `$routineA`; `fakeSessionAnalyst()`; a session under a **different** routine `$routineB` with a set logged for the same `exercise`
- **When:** `runAnalysisJob($sessionUnderRoutineB)`
- **Expect:** `ExerciseRecommendation::query()->count() === 2`; the `$routineA` row is unchanged; a new row exists for `$routineB`

**TC-8:** An agent failure propagates out of `handle()` as `SessionAnalysisException`, leaving `analysis_state` at `processing` (AC4)
- **Given:** `SessionAnalystAgent::fake(fn () => throw new RuntimeException('provider unavailable'))`; a session with one set
- **When:** `runAnalysisJob($session)`
- **Expect:** throws `SessionAnalysisException`; reloading the session, `analysis_state === AnalysisState::Processing` (`handle()` itself never sets `failed` — only the job's `failed()` hook does that, tested separately in TC-10); no `exercise_recommendations` row

**TC-9:** A malformed structured response propagates the same way (AC4)
- **Given:** `SessionAnalystAgent::fake(fn () => ['recommendations' => []])` (wrong count — zero recommendations for one trained exercise); a session with one set
- **When:** `runAnalysisJob($session)`
- **Expect:** throws `SessionAnalysisException`; no `exercise_recommendations` row

**TC-10:** `failed()` moves `analysis_state` to `failed` for any `Throwable`, not just `Exception` (AC4)
- **Given:** two persisted sessions
- **When:** `(new SessionAnalysisJob($session))->failed(new SessionAnalysisException)` on the first; `(new SessionAnalysisJob($otherSession))->failed(new TypeError('unexpected shape from provider'))` on the second — `TypeError extends Error`, not `Exception`, proving `failed(Throwable $exception)`'s type hint is not narrowed
- **Expect:** both sessions reload with `analysis_state === AnalysisState::Failed`

### `App\Services\Recommendation\SessionAnalystService` — `tests/Unit/Recommendation/SessionAnalystServiceTest.php`

These five call `SessionAnalystService::analyze()` directly, isolating the
mapping/validation logic from the job entirely — same tier and rationale as
the `SessionAnalysisJob` tests above, one level further down the call stack.

**TC-11:** `analyze()` throws `SessionAnalysisException` when the agent call itself fails
- **Given:** `SessionAnalystAgent::fake(fn () => throw new \RuntimeException('boom'))`; a persisted session with one set
- **When:** `app(SessionAnalystService::class)->analyze($session)`
- **Expect:** throws `SessionAnalysisException`

**TC-12:** `analyze()` throws `SessionAnalysisException` when the response count does not match the number of exercises trained
- **Given:** `SessionAnalystAgent::fake(fn () => ['recommendations' => []])`; a session with sets for one exercise
- **When:** `app(SessionAnalystService::class)->analyze($session)`
- **Expect:** throws `SessionAnalysisException`

**TC-13:** `analyze()` throws `SessionAnalysisException` when a recommendation entry has `target_rep_min > target_rep_max`
- **Given:** `SessionAnalystAgent::fake(fn () => ['recommendations' => [['target_weight_kg' => 20.0, 'target_sets' => 3, 'target_rep_min' => 12, 'target_rep_max' => 8, 'action' => 'hold', 'explanation' => 'x']]])`; a session with one set
- **When:** `app(SessionAnalystService::class)->analyze($session)`
- **Expect:** throws `SessionAnalysisException`

**TC-14:** `analyze()` throws `SessionAnalysisException` for an unknown `action` value
- **Given:** `SessionAnalystAgent::fake(fn () => ['recommendations' => [['target_weight_kg' => 20.0, 'target_sets' => 3, 'target_rep_min' => 8, 'target_rep_max' => 10, 'action' => 'not_a_real_action', 'explanation' => 'x']]])`; a session with one set
- **When:** `app(SessionAnalystService::class)->analyze($session)`
- **Expect:** throws `SessionAnalysisException`

**TC-15:** `analyze()` returns one `ExerciseRecommendationData` per distinct exercise, `exerciseId` taken from the session's own sets (never trusted from the AI response)
- **Given:** `fakeSessionAnalyst()`; a session with two sets logged against the *same* exercise plus one set against a second exercise
- **When:** `app(SessionAnalystService::class)->analyze($session)`
- **Expect:** returns exactly 2 `ExerciseRecommendationData` entries, one per distinct `exercise_id`

---

## 9. Technical Decisions

| Decision area | What was decided | Why |
|---|---|---|
| Deviations from `data-model.md` | `exercise_recommendations` drops `status` (`RecommendationStatus`) and `confidence` (`RecommendationConfidence`) from the documented shape; keeps `target_rep_min` / `target_rep_max` as a range. Plain unique `(user_id, routine_id, exercise_id)` replaces the documented partial unique index. | Product-owner decisions this session (§1). `data-model.md`'s own header calls its table "Decisiones tomadas (por defecto, **ajustables**)" — this ticket is the adjustment, same precedent as `generate-first-cycle-spec.md`'s documented `jsonb`→`json` deviation. `docs/plans/data-model.md` is updated in this ticket's own scope (§4.1, §6), not left inconsistent. |
| `SessionCloseAction` is not modified | Dispatch stays exactly as `complete-session-spec.md` shipped it: `SessionAnalysisJob::dispatch($session)` as the last line inside `SessionCloseAction`'s `DB::transaction`. `SessionAnalysisJob` stays plain `ShouldQueue`. | AC4 ("the session closes even if analysis fails") is true by construction in production: `QUEUE_CONNECTION=database` means `dispatch()` only inserts a `jobs` row and returns — `handle()` runs later, in a separate `queue:work` process, with no way to affect the request that closed the session. An earlier version of this ticket modified `SessionCloseAction` (moving the dispatch after its transaction, wrapping it in `try`/`catch`, briefly making the job `ShouldQueueAfterCommit`) to work around a failure mode that only exists under `QUEUE_CONNECTION=sync` — the driver `phpunit.xml` configures for tests, under which `dispatch()` runs the job **inline** (traced through `Illuminate\Queue\SyncQueue::push()`; confirmed by forcing a real, unfaked agent failure end to end through `POST .../complete` and watching the exception reach the HTTP response). That fix worked, but it added permanent production-code complexity to solve a test-environment-only quirk — backwards. The corrected approach leaves `SessionCloseAction` untouched and instead tests `SessionAnalysisJob` by calling `handle()`/`failed()` directly (§8), which needs no queue driver, real or `sync`, at all. |
| Test strategy: don't run the job through HTTP | `tests/Unit/Session/SessionAnalysisJobTest.php` calls `(new SessionAnalysisJob($session))->handle(app(SessionAnalyzeAction::class))` / `->failed($exception)` directly, never through `POST .../complete`. Three cases in `tests/Feature/Session/CompleteTrainingSessionTest.php` that used to complete a session for real (harmless when the job was an empty placeholder) now add `Bus::fake([SessionAnalysisJob::class])`. | Testing the job through a real HTTP dispatch only works by exploiting the `sync` driver's inline-execution quirk, which is what motivated modifying `SessionCloseAction` in the first place (see the row above). Calling `handle()`/`failed()` directly exercises the exact same code a real queue worker runs, is queue-driver-agnostic, and keeps "does completing a session dispatch the job" (already covered by `Bus::assertDispatched` in `CompleteTrainingSessionTest.php`) cleanly separate from "does the job's own logic work" (this ticket's new coverage). |
| Job retry | `public int $tries = 3;` `public function backoff(): array { return [30, 120]; }` | First job in this codebase with real, failable work (`GenerateCycleJob` and the pre-this-ticket `SessionAnalysisJob` were empty stubs). Three attempts with a short-then-longer backoff absorbs a transient provider hiccup (timeout, rate limit) without hammering the API. `handle()` itself never sets `failed` (TC-8/TC-9) — a real queue worker retries `handle()` up to `$tries` times per `backoff()` and only calls `failed()` once they're exhausted; `failed()` (TC-10) is the sole place `analysis_state` becomes `failed`. This retry loop is a real queue worker's job, not this ticket's code, so it is not itself under test — only that `handle()` propagates a failure (leaving `processing` for a worker to retry against) and that `failed()` correctly lands on `failed`. |
| `SessionAnalysisException` extends `DomainException` despite never being rendered over HTTP | Kept in the exact shape of `CycleGenerationException` (`errorCode()`, `statusCode()`, `Throwable $previous`). | Consistency with the one other "Service wraps an AI agent, throws a typed exception on failure" precedent in this codebase; zero extra cost, and the "Ver las recomendaciones vigentes" (Order 140) ticket or any future synchronous re-analysis endpoint can reuse it without a rewrite. `statusCode()` is simply unused while nothing renders it. |
| Where the Action lives | `App\Actions\Session\SessionAnalyzeAction`, not `App\Actions\Recommendation\...`. | Mirrors `RoutineCreateAction` living in `Routine` (not `Cycle`) despite writing `Cycle`-domain rows: the Action's identity follows the use case it orchestrates ("analyze a session"), not every table it touches. |
| Where the AI Service/Agent live | `App\Services\Recommendation\SessionAnalystService` + `App\Ai\Agents\Recommendation\SessionAnalystAgent`, under `Recommendation`, not `Session`. | The Service's whole job is producing and validating `ExerciseRecommendation` data — that's `Recommendation`-domain business knowledge, independent of which Action calls it (mirrors `CyclePlannerService` sitting in `Cycle`, called from `RoutineCreateAction` in `Routine`). |
| No separate "persist" Service | `SessionAnalyzeAction` performs the `updateOrCreate` upsert loop directly inside its own `DB::transaction`, with no `ExerciseRecommendationPersistService`. | Unlike `CycleDraftService` (writes a `Cycle` + 5 `CycleDay`s + their `DayExercise`s — a real nested tree), this write is one flat `updateOrCreate` per exercise with no children. A dedicated class here would only add indirection (`CLAUDE.md` rule 6); `SessionCloseAction`'s own direct `$session->update(...)` for a flat write is the closer precedent. |
| Prior-recommendation vs. day-prescription baseline | `SessionAnalystService` prefers the exercise's existing `ExerciseRecommendation` row (this routine) as the comparison baseline; falls back to the session's `day_exercises` prescription only when no recommendation exists yet; falls back to "no baseline" for a free session with neither. | Confirmed with the product owner via a concrete example (curl-de-bíceps: prescribed 10 reps, user does 10, gets recommended 12 for next time; next session the user does 11 — the agent must compare against the *recommended* 12, not the original prescription's 10, to correctly conclude `hold`). |
| Matching the AI response back to exercises | The prompt lists the session's distinct trained exercises in a fixed order (by first `set_number` appearance); the schema requires the same count of `recommendations`, matched back to `exercise_id` **by position**, never by an AI-supplied identifier. | Mirrors `CyclePlannerAgent`'s day/exercise-count-and-order matching. Every exercise here is already a resolved catalogue row (via `SetLog.exercise_id`) before the prompt is built, so there is no name-matching step (unlike `CyclePlannerAgent`, which invents free-text names `ExerciseCatalogService` must resolve afterwards) — position is a reliable, simpler correlation key than asking the model to echo back an ID. |
| Numeric target fields are never nullable | `target_weight_kg`, `target_sets`, `target_rep_min`, `target_rep_max` are all required in the schema, the DTO, and the DB column. | `docs/product-context.md` §4 step 5 / the AC: the point of the recommendation is "a qué peso, series y reps encarar el próximo entrenamiento" — always a complete, usable prescription, regardless of `action`. For `technique_focus` or `hold`, the agent repeats the unchanged prior/baseline numbers rather than omitting them; `action` and `explanation` carry the qualitative nuance, not field nullability. |
| Extra context passed to the agent | The session's own `note` / `perceived_effort`, and each `SetLog.note`, are included in the prompt alongside weight/reps/RPE. | Already-collected, low-cost signal; withholding it would contradict `docs/product-context.md` §5 ("100% IA... decide libremente"). |
| Agent tuning | `#[Timeout(30)]`, `#[MaxTokens(2000)]` on `SessionAnalystAgent` (vs. `CyclePlannerAgent`'s `Timeout(60)` / `MaxTokens(7000)`). | A session-close analysis is far smaller than a 5-day plan — the AC itself says "puede tardar unos segundos". Even a busy 8-exercise session's worth of recommendations comfortably fits well under 2000 completion tokens, leaving headroom without inflating the worst-case latency budget the way the planner's 60 s does. |
| `SessionAnalysisJobTest` file location | `tests/Unit/Session/SessionAnalysisJobTest.php`, not `tests/Feature/`. | It never touches HTTP, routing, or auth — it constructs a `TrainingSession` via factories/helpers and calls `handle()`/`failed()` directly. It does need a real DB (`RefreshDatabase`), the same precedent `SessionCompletionServiceTest` already sets for a DB-touching Unit test. |
| Git artifacts | English only. No AI attribution anywhere. | `CLAUDE.md` / `AGENTS.md` "Git" rule. |

---

## 10. Work Plan

Per the worktree-tooling caveat (see `complete-session-spec.md` §4.1, unchanged
here): the `app` Docker service bind-mounts the **main checkout**, not this
worktree. Run `artisan` / `composer` / `pint` / `phpstan` via a throwaway
container mounting this worktree's path after copying `.env` and `vendor/` in
from the main checkout; `pest` needs none of this (SQLite `:memory:`).

| # | Task | Definition of Done |
|---|---|---|
| 1 | Prepare the worktree: copy `.env` / `vendor/` from the main checkout; clone the runtime DB (`createdb -T gym_trainer gym_trainer_ia_recommendations`); set `DB_DATABASE` in this worktree's `.env` | `artisan` runs via the throwaway container; `gym_trainer` untouched; Pest still uses SQLite. |
| 2 | Create `app/Enums/Recommendation/RecommendationAction.php` (`advance_weight`, `hold`, `add_reps`, `add_set`, `deload`, `technique_focus`; a `values(): array` static, matching `MuscleGroup`) | Pint + PHPStan clean. |
| 3 | Create `database/migrations/2026_09_04_130000_create_exercise_recommendations_table.php` per §4.1 | `php artisan migrate` runs on the clone and on a fresh SQLite; `php artisan db:table exercise_recommendations` shows the expected columns and the plain unique index. |
| 4 | Create `app/Models/ExerciseRecommendation.php` (`HasFactory`, `HasPublicUuid`, `#[Fillable]` for all writable columns, casts per §4.1, `user()` / `routine()` / `exercise()` / `sourceSession()` `BelongsTo`, full PHPDoc) + `database/factories/ExerciseRecommendationFactory.php` | `php artisan ide-helper:models --write` then hand-check; `ExerciseRecommendation::factory()->create()` persists a valid row; Pint + PHPStan clean. |
| 5 | Create `app/Exceptions/Recommendation/SessionAnalysisException.php` (`final extends DomainException`, `errorCode = 'SESSION_ANALYSIS_FAILED'`, default `statusCode` `409` from the base) | Pint + PHPStan clean. |
| 6 | Create `app/Data/Recommendation/ExerciseRecommendationData.php` (`exerciseId`, `targetWeightKg`, `targetSets`, `targetRepMin`, `targetRepMax`, `action`, `explanation`) | Pint + PHPStan clean. |
| 7 | Create `app/Ai/Agents/Recommendation/SessionAnalystAgent.php` (`Agent`, `HasStructuredOutput`, `Promptable`, `#[Timeout(30)]`, `#[MaxTokens(2000)]`; `schema()` per §9's "Matching the AI response back to exercises" row) | Pint + PHPStan clean. |
| 8 | Add `sessionAnalysisPayload()` / `fakeSessionAnalyst()` to `tests/Helpers.php` per §8 (needed by both this domain's Feature and Unit tests, added first so nothing downstream forward-references it); create `app/Services/Recommendation/SessionAnalystService.php`: `analyze(TrainingSession): array` — loads `sets.exercise` + `cycleDay.dayExercises`, resolves each distinct trained exercise's baseline (existing recommendation → day-exercise prescription → none), builds the prompt, invokes the agent, validates (count match, `rep_min <= rep_max`, known `action`, non-negative numerics) and maps to `ExerciseRecommendationData[]`, throwing `SessionAnalysisException` on any failure | Write `tests/Unit/Recommendation/SessionAnalystServiceTest.php` (TC-11…TC-15) — green. Pint + PHPStan clean. |
| 9 | Create `app/Actions/Session/SessionAnalyzeAction.php`: `handle(TrainingSession): void` — calls the Service, then `DB::transaction` upserting each `ExerciseRecommendation` (`updateOrCreate` on `user_id`/`routine_id`/`exercise_id`) and setting `analysis_state = done` | Pint + PHPStan clean; covered by task 11's feature tests. |
| 10 | Update `app/Jobs/Session/SessionAnalysisJob.php` (stays `ShouldQueue`): `public int $tries = 3;`; `backoff(): array` returns `[30, 120]`; `handle(SessionAnalyzeAction $action)` sets `analysis_state = processing` then calls `$action->handle($this->session)`; `failed(Throwable $exception): void` sets `analysis_state = failed`. **`app/Actions/Session/SessionCloseAction.php` is not touched** — see §9 | Pint + PHPStan clean; covered by task 11; `git diff` shows no changes to `SessionCloseAction.php`. |
| 11 | Write `tests/Unit/Session/SessionAnalysisJobTest.php` (TC-1…TC-10 per §8, calling `handle()`/`failed()` directly), using the helpers added in task 8; add `Bus::fake([SessionAnalysisJob::class])` to the three `tests/Feature/Session/CompleteTrainingSessionTest.php` cases identified in §9 that complete a session for real (renaming the one whose description no longer fits) | `vendor/bin/pest tests/Unit/Session/SessionAnalysisJobTest.php tests/Unit/Recommendation/SessionAnalystServiceTest.php tests/Feature/Session/CompleteTrainingSessionTest.php` all green. |
| 12 | Update `docs/plans/data-model.md` per §4.1 / §6: rewrite §`exercise_recommendations`, §Enums, "Decisiones tomadas" row #4, and the `confidence: low` mention under "Sin tabla: resumen de progresión" | The file reads coherently; no other section changed; no remaining reference to `RecommendationStatus` / `RecommendationConfidence`. |
| 13 | `vendor/bin/pint --dirty`, then `vendor/bin/phpstan analyse`, then `php artisan ide-helper:models --write` + `vendor/bin/pint app/Models` | Pint reports no diffs; PHPStan level 6 clean; `ExerciseRecommendation` PHPDoc in sync. |
| 14 | `composer check` (Pint `--test` + PHPStan level 6 + full Pest suite) | All green; no regression in any other domain's suite. |
| 15 | Manual check with `curl` against the worktree app pointed at the clone: register + login → create profile + routine → open a session → log a set → `POST .../complete` (`200`, `analysis_state: "pending"` in the immediate response, since the job runs after commit) → `GET`-equivalent check via `tinker` or a direct DB read of `exercise_recommendations` and the session's `analysis_state` (now `done`) | The row exists with sane AI-generated values; `analysis_state` reads `done` after the request completes. |
| 16 | On merge / branch drop: `dropdb --if-exists gym_trainer_ia_recommendations`; revert `DB_DATABASE` in the worktree `.env` | The clone is gone; `.env` restored to `gym_trainer`. |

*Process note: branch name, commit messages and PR text follow `CLAUDE.md` /
`AGENTS.md` — English only, and no AI attribution anywhere.*
