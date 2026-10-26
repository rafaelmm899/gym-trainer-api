# Asynchronous generation of cycle N+1

## 1. Context

**Kind:** Brownfield Feature

**Stack:** PHP 8.5, Laravel 13 (queue driver `database`), `laravel/ai`, `spatie/laravel-data`, Pest 4

**Problem statement:** `POST /api/v1/routines/{routine}/cycles` used to generate cycle N+1 inside the request. The AI provider (Groq, free tier, about 8k tokens per minute) first rejected the single whole-week call (HTTP 400, invalid JSON) and, once the call was split per day, rate-limited the calls made a few seconds apart (HTTP 429). A pause long enough to respect the limit does not fit in an HTTP request (130 s in nginx and php-fpm). Generation therefore becomes asynchronous: the request only starts it, and one queued job per trained day progresses the cycle, a minute apart, with retries. A day whose AI calls keep failing falls back to the routine's session recommendations, so generation always finishes and the athlete is never stuck on the same cycle. Supersedes the synchronous flow of `generate-next-cycle-spec.md` and `keep-cycle-exercises-spec.md`; what a cycle contains (same days and exercises, progressed) is unchanged.

**In scope:**
- `POST /routines/{routine}/cycles` answers 202 with the new cycle `generating` and dispatches a job chain.
- One `CycleDayProgressionJob` per trained day (the second and later delayed 60 s), then one `CycleFinalizeJob`.
- Per-day retry (3 tries, 60 s backoff) and a per-day fallback on the last try.
- `cycle_days.progression_state` (`pending`, `ai`, `fallback`, `unchanged`), exposed on the cycle day resource.
- Cycle statuses `generating` and `failed` (already in `CycleStatus`) start being used.
- 409 `CYCLE_GENERATION_IN_PROGRESS`; repeating the request after a failure discards the failed attempt and starts over.
- The active cycle stays active, and trainable, while the next one is generated.

**Out of scope:**
- The SPA changes (202 handling, polling, progress, retry button): tracked in the Notion backlog ("SPA: generación del ciclo siguiente asíncrona").
- Changing how often the Cloud Run job `process-queue` runs.
- The first-cycle planner (`POST /routines`): it stays synchronous, one AI call, 502 on failure.
- Session analysis jobs stranded in `processing` when a worker is killed.
- A dedicated retry endpoint or a scheduled retry of failed cycles.

---

## 2. API Surface

### 2.1 REST

| Method | Path | Auth | Request | Response | Status codes |
|---|---|---|---|---|---|
| POST | `/api/v1/routines/{routine}/cycles` | `auth:sanctum` + `RoutinePolicy::generateCycle` | — | `{ "data": Cycle }` with `status: "generating"`, `sequence_number` = N+1, `split_rationale: null` and `days[]` each with `progression_state` (`pending` for trained days, `unchanged` for the rest) | 202; 401; 403; 404; 409 `ROUTINE_NOT_ACTIVE`; 409 `CYCLE_GENERATION_IN_PROGRESS`; 429 (`throttle:1,1`) |
| GET | `/api/v1/routines/{routine}` | `auth:sanctum` + `RoutinePolicy` | — | `data.cycle` is the routine's latest cycle (`generating`, `active` or `failed`, `days[].progression_state`); `split_rationale` is filled once it is `active` | unchanged |

Cycle day resource gains `progression_state`: `pending` | `ai` | `fallback` | `unchanged`.

### 2.2 CLI

Not applicable — no CLI commands.

### 2.3 Events

Not applicable — no events. The queued jobs are described in §9.

---

## 3. UI

### 3.1 Pages

Not applicable — no pages affected (this repository is the API; the SPA work is tracked in Notion).

### 3.2 Components

Not applicable — no components affected.

---

## 4. Database

### 4.1 Schema changes

| Table | Action | Details |
|---|---|---|
| `cycle_days` | Add column | `progression_state` string, not null, default `unchanged`. Values of `App\Enums\Cycle\DayProgressionState`: `pending`, `ai`, `fallback`, `unchanged`. Migration `2026_10_25_120000_add_progression_state_to_cycle_days_table`. |

No change to `cycles`: `status` already allows `generating` and `failed`, and `split_rationale`, `generated_at` and `activated_at` are nullable.

### 4.2 Seeds

Not applicable — no seeds.

---

## 5. Auth & Authorization

### 5.1 Authentication

**Method:** Session (Laravel Sanctum SPA mode, cookie + CSRF), unchanged.

### 5.2 Authorization

Not applicable — no authorization changes. `RoutinePolicy::generateCycle` still gates ownership only; the active-routine and in-progress rules are business guards in the Action (409).

---

## 6. Configuration

**Environment variables:**
| Variable | Value / Source | Purpose |
|---|---|---|
| `QUEUE_CONNECTION` | `database` (already set; `sync` under PHPUnit) | The jobs run on the existing queue. No new variable. |
| `DB_QUEUE_RETRY_AFTER` | default 90 | A job `$timeout` of 60 s stays below it, so a job is never reserved twice. |

Production runs the queue as the short Cloud Run job `process-queue` (`queue:work --stop-when-empty --max-time=120`, about every 2 minutes). A delayed job is invisible until it is due, so the real gap between two days is one pass of that job and a cycle takes roughly 5 to 10 minutes.

---

## 7. Current vs New Behavior

| Behavior | Current | New |
|---|---|---|
| `POST /routines/{routine}/cycles` response | 201 with the final active cycle, after up to a minute | 202 with the cycle `generating`; the cycle becomes `active` minutes later |
| AI calls | One per trained day, in the request, a few seconds apart | One per trained day, each in its own job, 60 s apart |
| AI failure on a day | Retried once in the request, then every remaining day skipped the AI | Retried by the queue (3 tries, 60 s backoff); on the last try only that day falls back and the next days still use the AI |
| New cycle status | Born `active` | Born `generating`; `active` when finalized; `failed` if a job fails for a non-AI reason |
| Rollover of the outgoing cycle | In the same transaction as the creation of N+1 | In the finalize job, after every day is done; the outgoing cycle stays `active` and trainable meanwhile |
| `split_rationale` | Written by the AI | Built in PHP when finalizing ("Progressed X of Y trained days with the AI; …"); null while generating |
| Request while already generating | Not possible (synchronous) | 409 `CYCLE_GENERATION_IN_PROGRESS` |
| Request after a failed attempt | Not applicable | The failed cycle is deleted and generation starts again |
| Cycle used to open a planned session | `Routine::cycle()` (latest) | `Routine::activeCycle()`, so a `generating` cycle does not hide the active one |
| `CycleProgressionAgent` timeout | 60 s | 30 s |

---

## 8. Test Cases

**TC-1:** Starting generation answers 202 and queues one job per trained day, then the finalize job
- **Given:** a routine whose active cycle has days 1 to 3 trained and days 4 and 5 untrained; `Bus` faked
- **When:** `POST /routines/{routine}/cycles`
- **Expect:** 202, `data.status = generating`, `data.sequence_number = 2`, `days.0.progression_state = pending`, `days.3.progression_state = unchanged`; `Bus::assertChained` with day jobs for orders 1, 2, 3 (delay `null`, 60, 60) followed by `CycleFinalizeJob`; the active cycle is still `active` and the new one `generating`

**TC-2:** Nothing trained queues only the finalize job
- **Given:** an active cycle with no completed session; `Bus` faked
- **When:** `POST /routines/{routine}/cycles`
- **Expect:** 202; the chain is only `CycleFinalizeJob`; no day is `pending`

**TC-3:** Cycle already generating is rejected
- **Given:** a request already started (a `generating` cycle N+1 exists)
- **When:** `POST /routines/{routine}/cycles` again (a minute later, past the throttle)
- **Expect:** 409 with `data.code = CYCLE_GENERATION_IN_PROGRESS`; no extra cycle is created

**TC-4:** Repeating the request after a failure discards the failed attempt
- **Given:** a `failed` cycle N+1 for the routine
- **When:** `POST /routines/{routine}/cycles`
- **Expect:** 202 with `status = generating`; the failed cycle no longer exists; the routine has two cycles

**TC-5:** The action creates the next cycle as a generating copy and leaves the outgoing one active
- **Given:** a routine with a trained 5-day active cycle (sequence 2); `Bus` faked
- **When:** `CycleGenerateAction::handle($routine)`
- **Expect:** a cycle with sequence 3, status `generating`, 5 days all `pending`, the same `exercise_id` list per day as the outgoing cycle; the outgoing cycle is `active`; a chain of 5 day jobs and the finalize job is dispatched

**TC-6:** An archived routine is rejected and nothing is written
- **Given:** an archived routine; `Bus` faked
- **When:** `CycleGenerateAction::handle($routine)`
- **Expect:** `RoutineNotActiveException`; no job dispatched; cycle count unchanged

**TC-7:** The action refuses while the next cycle is generating
- **Given:** a `generating` cycle with sequence 3 already exists; `Bus` faked
- **When:** `CycleGenerateAction::handle($routine)`
- **Expect:** `CycleGenerationInProgressException`; no job dispatched; cycle count unchanged

**TC-8:** A day job writes the AI progression and marks the day `ai`
- **Given:** a `generating` cycle with every day `pending`; a fake agent answering `sets = 5`, `target_weight_kg = 60.0` for slot 2.1
- **When:** `CycleDayProgressAction::handle($day2, false)`
- **Expect:** day 2 is `ai`; its first exercise has `sets = 5`, `target_weight_kg = 60.00` and the returned rationale; day 1 is still `pending`

**TC-9:** A day that is no longer pending is skipped
- **Given:** a day already marked `ai`
- **When:** `CycleDayProgressAction::handle($day, true)`
- **Expect:** the agent is never prompted

**TC-10:** An AI failure with attempts left is rethrown for the queue to retry
- **Given:** an agent that throws
- **When:** `CycleDayProgressAction::handle($day, false)`
- **Expect:** `CycleGenerationException`; the day stays `pending`

**TC-11:** On the last attempt the day falls back to the session recommendation
- **Given:** an agent that throws; an active recommendation for the day's first exercise (`62.5 kg`, 5 sets, 6-8 reps)
- **When:** `CycleDayProgressAction::handle($day, true)`
- **Expect:** the day is `fallback`; the first exercise has `sets = 5` and `target_weight_kg = 62.50`; the second keeps its cloned sets

**TC-12:** On the last attempt an unusable answer also falls back
- **Given:** an agent answering with a missing slot, a slot of another day, a repeated slot, zero sets, or an inverted rep range
- **When:** `CycleDayProgressAction::handle($day, true)`
- **Expect:** the day is `fallback`; its first exercise keeps the outgoing prescription

**TC-13:** A day job only asks about its own day
- **Given:** a `generating` cycle with every day trained
- **When:** `CycleDayProgressAction::handle($day3, false)`
- **Expect:** the prompt contains `- day 3, exercise 1` and not `- day 2, exercise 1`

**TC-14:** Finalizing activates the new cycle and rolls the outgoing one over
- **Given:** a `generating` cycle with every day `ai`; an active recommendation for a trained exercise
- **When:** `CycleFinalizeAction::handle($cycle)`
- **Expect:** the new cycle is `active` with `activated_at` set and `split_rationale = "Progressed 5 of 5 trained days with the AI; the rest used your session recommendations or kept last week's prescription."`; the outgoing cycle is `completed` with `completed_at`; the recommendation is `applied`

**TC-15:** The rationale counts the days that fell back
- **Given:** one day `ai` and four `fallback`
- **When:** `CycleFinalizeAction::handle($cycle)`
- **Expect:** `split_rationale` contains "Progressed 1 of 5 trained days with the AI"

**TC-16:** The rationale says nothing was trained when no day needed the AI
- **Given:** every day `unchanged`
- **When:** `CycleFinalizeAction::handle($cycle)`
- **Expect:** `split_rationale = "No exercise was trained last cycle; the prescription is unchanged."`

**TC-17:** An outgoing cycle with an untrained day rolls to `incomplete`
- **Given:** the outgoing cycle's last day has no completed session
- **When:** `CycleFinalizeAction::handle($cycle)`
- **Expect:** the outgoing cycle is `incomplete`

**TC-18:** Finalizing is a no-op unless the cycle is still generating
- **Given:** the new cycle is `failed`
- **When:** `CycleFinalizeAction::handle($cycle)`
- **Expect:** the new cycle stays `failed`; the outgoing cycle stays `active`

**TC-19:** The day job is configured to retry a minute later
- **Given:** a `CycleDayProgressionJob`
- **When:** its configuration is read
- **Expect:** `tries = 3`, `backoff() = [60, 60]`, `timeout < 90`

**TC-20:** Running a day job progresses its day
- **Given:** a `generating` cycle and a fake agent
- **When:** `CycleDayProgressionJob::dispatchSync($day4)`
- **Expect:** day 4 is `ai`

**TC-21:** A failed day job marks the cycle `failed`
- **Given:** a `generating` cycle
- **When:** `(new CycleDayProgressionJob($day))->failed(new RuntimeException)`
- **Expect:** the cycle is `failed`

**TC-22:** The finalize job finalizes, and marks the cycle `failed` when it fails
- **Given:** a `generating` cycle with every day `ai`
- **When:** `CycleFinalizeJob::dispatchSync($cycle)`, then `failed()` on a regenerated state
- **Expect:** the cycle is `active` after the first call and `failed` after `failed()`

**TC-23:** The whole chain runs to an active cycle through the endpoint on the sync queue
- **Given:** a routine with a trained 5-day active cycle and a fake agent; `QUEUE_CONNECTION=sync`
- **When:** `POST /routines/{routine}/cycles`
- **Expect:** 202; afterwards cycle 2 is `active` with 5 days `ai`; the outgoing cycle is `completed`; the agent was prompted for day 5

**TC-24:** The next cycle keeps exactly the same days and exercises
- **Given:** a trained active cycle
- **When:** `POST /routines/{routine}/cycles`
- **Expect:** each day of the new cycle keeps the label, focus, rationale and the same ordered `exercise_id` list as the outgoing cycle

**TC-25:** The AI progression is applied to the cloned exercises
- **Given:** a fake agent returning `sets = 4`, reps 6-8, `62.5 kg`, RPE 8, rest 150 for slot 1.1
- **When:** `POST /routines/{routine}/cycles`
- **Expect:** the first exercise of the first day of the new cycle has those values

**TC-26:** An untrained exercise is copied verbatim and never sent to the AI
- **Given:** a day with a trained exercise and an untrained one
- **When:** `POST /routines/{routine}/cycles`
- **Expect:** the untrained exercise keeps its prescription; the prompt does not contain its name

**TC-27:** An exercise on two days gets its own values per slot
- **Given:** the same exercise on days 1 and 5; fake answers with `50 kg` for 1.1 and `55 kg` for 5.1
- **When:** `POST /routines/{routine}/cycles`
- **Expect:** day 1 has `50` and day 5 has `55`; each day was prompted

**TC-28:** The new cycle clones the outgoing cycle as the user last edited it
- **Given:** an exercise replaced in the active cycle
- **When:** `POST /routines/{routine}/cycles`
- **Expect:** the new cycle contains the replacement exercise

**TC-29:** Rollover marks only the recommendations of trained exercises `applied`
- **Given:** active recommendations for a trained and an untrained exercise
- **When:** `POST /routines/{routine}/cycles` on the sync queue
- **Expect:** the trained exercise's recommendation is `applied`; the other stays `active`

**TC-30:** Ineligible requests are rejected before any AI call
- **Given:** an archived routine, or another user's routine, or an unknown or non-uuid routine, or no authentication
- **When:** `POST /routines/{routine}/cycles`
- **Expect:** 409 `ROUTINE_NOT_ACTIVE`, 403, 404 or 401 respectively; the agent is never prompted

**TC-31:** The endpoint is rate limited per user
- **Given:** a successful call
- **When:** a second call within a minute, and a call by another user
- **Expect:** the second call gets 429 and exposes uuids only; the other user's call is not throttled

**TC-32:** `performedSlots()` returns only trained slots keyed by day and exercise
- **Given:** a cycle where one exercise was not trained
- **When:** `CyclePlannerService::performedSlots($cycle, $summary)`
- **Expect:** its slot key is absent; the other keys (for example `1.1`, `2.2`, `5.3`) are present

**TC-33:** `cloneDays()` copies the outgoing days as prescribed
- **Given:** a 5-day cycle, one slot with null weight and RPE
- **When:** `CyclePlannerService::cloneDays($cycle)`
- **Expect:** same labels, exercise ids, sets, reps and weights; null weight and RPE stay null

**TC-34:** `progressDay()` maps the AI answer onto exactly the slots of that day
- **Given:** a fake agent answering every listed slot
- **When:** `CyclePlannerService::progressDay(...)` for day 2
- **Expect:** keys `2.1`, `2.2`, `2.3`; values as returned; the prompt lists only day 2 slots

**TC-35:** `progressDay()` rejects a malformed answer
- **Given:** a missing list, a non-object entry, a non-integer day, a non-numeric, null or negative weight, zero sets, inverted reps, RPE above 10, negative rest, a blank rationale, a missing, extra, other-day or repeated slot
- **When:** `CyclePlannerService::progressDay(...)`
- **Expect:** `CycleGenerationException`

**TC-36:** `progressDay()` wraps a provider exception
- **Given:** an agent that throws `RuntimeException('boom')`
- **When:** `CyclePlannerService::progressDay(...)`
- **Expect:** `CycleGenerationException` whose previous exception is the original

**TC-37:** `progressionsFromRecommendations()` applies the recommendation and copies the rest
- **Given:** an active recommendation for the first exercise of day 1
- **When:** `CyclePlannerService::progressionsFromRecommendations($slots, $recommendations)`
- **Expect:** slot `1.1` takes the recommendation's sets, reps, weight and explanation and keeps its rest; slot `1.2` equals the outgoing prescription

**TC-38:** The progression prompt lists each performed slot with its prescription, actuals and recommendation
- **Given:** an athlete profile with notes, a goal, a hint and an active recommendation
- **When:** `CyclePlannerService::progressDay(...)`
- **Expect:** the prompt contains the notes, goal, hint, `- day 1, exercise 1 — <name>: prescribed SxMIN-MAX`, the actual averages, `recommendation: advance_weight` and its explanation

**TC-39:** The day resource exposes `progression_state`
- **Given:** a session list that embeds a cycle day
- **When:** `GET /routines/{routine}/sessions`
- **Expect:** the embedded cycle day has the keys `id, order, label, focus_muscle_groups, rationale, progression_state, cycle`

**TC-40:** A planned session can be opened while the next cycle is generating
- **Given:** a routine with an `active` cycle N and a `generating` cycle N+1
- **When:** a session is opened planned against a day of cycle N (`TrainingSessionOpeningService::guard`)
- **Expect:** no exception; a day of the `generating` cycle is rejected with `CycleDayNotInActiveCycleException`

---

## 9. Technical Decisions

| Decision area | What was decided | Why |
|---|---|---|
| Granularity | One job per trained day, not per exercise | One call per exercise would be up to 40 calls and exceed the provider's 8k TPM; one call per day keeps each answer short enough to validate |
| Pause between days | `delay(60)` on the second and later day jobs of a `Bus::chain`, which the dispatcher honours when it pushes each chained job | The pause must outlast the provider's per-minute window and cannot live inside an HTTP request |
| Retry | `tries = 3`, `backoff = [60, 60]`, `timeout = 60` (below `retry_after = 90`) | A rate-limited call is retried after the window resets, and a job is never reserved twice |
| Last attempt | `CycleDayProgressAction` receives `isLastAttempt` (`attempts() >= tries`); an AI failure is rethrown until then, and on the last attempt the day falls back and the job succeeds | The chain must keep going: a failed job stops a chain, and generation must always finish |
| Fallback | The routine's active session recommendations over the cloned prescription, or the cloned prescription itself | Real evidence from the athlete's own sessions, no AI needed |
| Other failures | `failed()` marks the cycle `failed`; repeating the `POST` deletes it and starts over | No separate retry endpoint or scheduler; one entry point |
| State per day | `cycle_days.progression_state` enum, default `unchanged` | A client can show progress and which days used the AI, and the finalize job can build the rationale without extra tables |
| Cycle shadowing | `Routine::cycle()` (latest) is unchanged; the opening service reads `activeCycle()` | The SPA polls `GET /routines/{routine}` and needs to see `generating` and `failed`; sessions must keep working on the active cycle |
| Where things live | The Action creates the cycle, dispatches the chain and returns 202; day progress and finalize are Actions called by thin Jobs; the planner Service exposes `performedSlots`, `cloneDays`, `progressDay` and `progressionsFromRecommendations` | Follows the pipeline in `CLAUDE.md`: only Actions open transactions and dispatch jobs; Services never do |
| Dispatch | After the creation transaction | If the transaction rolls back no job exists; matches the session analysis convention |
| Conflict handling | A `generating` cycle makes the next request a 409 | Prevents two chains writing the same cycle |
| Response | 202 with the generating cycle, in memory as created | The client polls the routine; under the sync queue in tests the in-memory model is stale by design |
| SPA | Separate work, tracked in Notion | API and SPA are different repositories and are deployed together |

---

## 10. Work Plan

| # | Task | Definition of Done |
|---|---|---|
| 1 | Add `DayProgressionState`, the `progression_state` migration, the `CycleDay` cast and the resource field | Migration runs on the local Postgres; TC-39 passes |
| 2 | Add `CycleGenerationInProgressException` (409 `CYCLE_GENERATION_IN_PROGRESS`) | TC-3 and TC-7 pass |
| 3 | Split `CyclePlannerService` into `performedSlots`, `cloneDays`, `progressDay` and `progressionsFromRecommendations`; add `CycleDraftService::applyProgressions` | TC-32 to TC-38 pass |
| 4 | Add `CycleDayProgressAction` and `CycleDayProgressionJob` | TC-8 to TC-13, TC-19 to TC-21 pass |
| 5 | Add `CycleFinalizeAction` and `CycleFinalizeJob` | TC-14 to TC-18 and TC-22 pass |
| 6 | Rewrite `CycleGenerateAction` (guards, generating copy, chain dispatch) and answer 202 from `GenerateCycleController` | TC-1 to TC-7 and TC-23 to TC-31 pass |
| 7 | Switch `TrainingSessionOpeningService` to `Routine::activeCycle()` | TC-40 passes |
| 8 | Update `routes/api.php` comments, `docs/product-context.md` and this spec | Docs describe the 202 flow |
| 9 | Pint, PHPStan and the full Pest suite; loop the new tests three times | `composer check` is green |
| 10 | Deploy API and SPA together (`make deploy`) and trigger a generation on the stuck routine | The cycle reaches `active` within about ten minutes; Cloud Run logs show no day left `pending` |
