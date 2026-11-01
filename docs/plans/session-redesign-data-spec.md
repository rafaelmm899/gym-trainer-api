# Session redesign data: last performance, exercise history, trend and volume

> Base contract: `docs/product-context.md`, `docs/plans/data-model.md`
> §`training_sessions`, §`set_logs`, `CLAUDE.md` "The pipeline". Extends what
> `docs/plans/show-training-session-spec.md`, `docs/plans/create-training-session-spec.md`,
> `docs/plans/store-set-logs-spec.md`, `docs/plans/list-routine-sessions-spec.md`,
> `docs/plans/list-session-cycle-number-spec.md` and
> `docs/plans/routine-recommendations-endpoint-spec.md` built. Notion user story:
> "API: datos de sesión para el rediseño (última vez, tendencia y volumen)" (Order 910).

## 1. Context

**Kind:** Brownfield Feature

**Stack:** PHP 8.5 · Laravel 13 · PostgreSQL 17 (runtime) / SQLite `:memory:`
(tests) · Pest 4 (`pest-plugin-laravel`) · `laravel/sanctum` 4 ·
`spatie/laravel-data` · `dedoc/scramble` · Pint · Larastan level 6. Everything
runs in Docker.

**Problem statement:** The SPA's session redesign (load screen, post-session
recommendations, history by week, per-exercise history with dates) needs data
the API does not expose: what the athlete did last time on each exercise, how an
exercise trended over its last sessions, the volume of a session and how it
changed, and which prescribed exercise (`day_exercise`) each logged set belongs
to. Today the SPA would need N+1 requests for the first three, and it rebuilds
the fourth by exercise *name*, which breaks when a day repeats an exercise. This
ticket adds all of it as additive fields plus one new read endpoint. The only
schema change is a nullable `set_logs.day_exercise_id`; the API currently
resolves it when a set is logged and then discards it.

**In scope:**
- `previous` on every prescribed exercise of `cycle_day.exercises[]` in
  `POST /routines/{routine}/sessions` (201) and `GET /sessions/{session}`:
  `null` or `{session_id, started_at, cycle_sequence_number, sets: [{set_number, weight_kg, reps, rpe}]}`.
- `GET /api/v1/exercises/{exercise}/history`: paginated, newest first, completed
  sessions of the authenticated user only, optional `routine` filter, with
  per-item `top_weight_kg`, `volume_kg` and `volume_change_pct` (correct across
  page boundaries).
- `day_exercise_id` (nullable) on `SetLogResource`, persisted in a new nullable
  `set_logs.day_exercise_id` column, with a backfill of existing rows that is safe
  when unambiguous.
- `history` on `ExerciseRecommendationResource`: up to the last 4 completed
  sessions of the exercise, oldest to newest, ending at the recommendation's
  source session.
- `summary` on `TrainingSessionResource` (list, detail, create, complete, import):
  `exercises_count`, `sets_count`, `total_volume_kg`, `duration_seconds`,
  `volume_change_pct` against the same plan day of the immediately previous cycle.
- No N+1: `summary` on the list, `previous` on the detail and `history` on the
  recommendation lists cost a constant number of queries, proven by tests.
- One shared code path for `previous`, the history endpoint and the trend.
- Pest coverage of every rule below; the new fields are documented through the
  typed pipeline (Scramble), no annotations.

**Out of scope:**
- Full per-exercise chart endpoints (separate story, "Progreso & gráficos").
- Changes to the AI analysis or to how recommendations are generated.
- Rest timer (uses the existing `rest_seconds`).
- A client-selectable comparison base for `volume_change_pct` (query parameter or
  stored preference): the base is fixed to the immediately previous cycle.
- `previous` for free sessions: a free session has no prescribed exercises to
  carry it, so the SPA reads it as the first item of
  `GET /exercises/{exercise}/history?per_page=1`.
- `previous` on `POST .../sessions/{session}/complete` and on the day-workbook
  import response: only create and detail carry it (they feed the load screen).
- `previous` or `history` inside `CycleResource` / `GET` cycle payloads: unchanged.
- Changing `day_exercise_id` of an existing set (`PUT .../sets/{set}` keeps
  accepting weight, reps, RPE and note only).
- Inferring `day_exercise_id` for new sets: it is exactly what the client sends
  (`null` when the client sends `exercise_id`).
- Filtering the history by date or by cycle, and any history for an exercise the
  user never trained other than the empty list.
- The `gym-trainer-spa/` frontend (separate repository).

---

## 2. API Surface

### 2.1 REST

| Method | Path | Auth | Request | Response | Status codes |
|---|---|---|---|---|---|
| GET | `/api/v1/exercises/{exercise}/history` | `auth:sanctum` | Query, all optional: `page` (int ≥ 1), `per_page` (int 1–50, default 10), `routine` (uuid) | `data`: list of `{session_id, started_at, cycle_sequence_number, cycle_day_label, top_weight_kg, volume_kg, volume_change_pct, sets: [{set_number, weight_kg, reps, rpe, note}]}` newest first, plus the paginator `links` / `meta` of the sessions list. `cycle_sequence_number` and `cycle_day_label` are `null` for free sessions. `data: []` when the user never trained the exercise | 200, 401, 404 `NOT_FOUND_EXCEPTION` (unknown exercise), 422 `VALIDATION_EXCEPTION` (errors under `data.errors`) |
| POST | `/api/v1/routines/{routine}/sessions` | `auth:sanctum` | Unchanged | `data.cycle_day.exercises[]` gain `previous`; `data` gains `summary` (zeros while empty) | Unchanged: 201, 401, 403, 404, 409, 422 |
| GET | `/api/v1/sessions/{session}` | `auth:sanctum` | Unchanged | `data.cycle_day.exercises[]` gain `previous`; `data.sets[]` gain `day_exercise_id`; `data.recommendations[]` gain `history`; `data` gains `summary` | Unchanged: 200, 401, 403, 404 |
| GET | `/api/v1/routines/{routine}/sessions` | `auth:sanctum` | Unchanged | Each item gains `summary` | Unchanged: 200, 401, 403, 404, 422 |
| POST | `/api/v1/sessions/{session}/complete` | `auth:sanctum` | Unchanged | `data` gains `summary` with `duration_seconds` set | Unchanged |
| POST | `/api/v1/routines/{routine}/cycle-days/{day}/import` | `auth:sanctum` | Unchanged | `data` gains `summary` (the session is created completed) | Unchanged |
| POST | `/api/v1/sessions/{session}/sets` | `auth:sanctum` | Unchanged | `data` gains `day_exercise_id` (uuid of the prescription the client declared, else `null`) | Unchanged |
| PUT | `/api/v1/sessions/{session}/sets/{set}` | `auth:sanctum` | Unchanged | `data` gains `day_exercise_id` (stored value, never changed by the update) | Unchanged |
| GET | `/api/v1/routines/{routine}/recommendations` | `auth:sanctum` | Unchanged | Each item gains `history` | Unchanged |

Field definitions (all weights in kg as JSON numbers, `started_at` ISO 8601 as
elsewhere in the API, ids are public UUIDs):

- `previous` — `null` when the user never completed a session with that exercise
  before the current session's `started_at`; otherwise the **completed** session
  of the same user with the greatest `started_at` (then greatest `id`) that has at
  least one set of that `exercise_id` and `started_at` strictly before the current
  session's `started_at`. Any routine, planned or free. `sets` are that exercise's
  sets ordered by `set_number`. `cycle_sequence_number` is the owning cycle's
  `sequence_number`, `null` when the previous session was free.
- `volume_kg` (history item) / `total_volume_kg` (summary) — sum of
  `weight_kg × reps` over the sets in scope (that exercise in that session / all
  sets of the session), rounded to 2 decimals.
- `top_weight_kg` — maximum `weight_kg` of the exercise in that session.
- `top_set_reps` (trend point) — `reps` of the top-weight set; on equal weight the
  set with more reps wins, then the lower `set_number`.
- `volume_change_pct` (history item) — `(volume − previousVolume) / previousVolume × 100`
  rounded to 1 decimal, where `previousVolume` is the `volume_kg` of the completed
  session of the same user immediately before it for the same exercise, from any
  routine or free, **ignoring the `routine` filter and the page window**. `null`
  for the oldest session or when `previousVolume` is 0.
- `history` (recommendation) — `[{session_id, started_at, top_weight_kg, top_set_reps, volume_kg}]`,
  at most 4, oldest to newest: completed sessions of the recommendation's user
  with that exercise, any routine, with `started_at` at or before the
  recommendation's source session's `started_at` (the source session is the last
  point). When `source_session_id` is `null`, the 4 most recent completed sessions.
  Fewer than 4 → fewer items; none → `[]`.
- `summary` — always present, never `null`:
  - `exercises_count`: distinct `exercise_id` with at least one set (off-plan ones
    included, prescribed-but-not-done excluded).
  - `sets_count`: number of sets.
  - `total_volume_kg`: as above.
  - `duration_seconds`: `completed_at − started_at` in whole seconds; `null` while
    `in_progress`.
  - `volume_change_pct`: against the most recent **completed** session of the same
    routine whose `cycle_day` has the same `order` in the cycle with
    `sequence_number − 1`; same rounding as above. `null` when the session is
    `in_progress`, free, in cycle 1, when that previous cycle has no completed
    session on that day, or when the baseline volume is 0. A partial volume is
    never compared.
- `day_exercise_id` — public UUID of the `day_exercise` the set was logged
  against; `null` for sets of free sessions, off-plan sets (logged with
  `exercise_id`) and rows the backfill could not assign.

### 2.2 CLI

Not applicable — no CLI commands.

### 2.3 Events

Not applicable — no events.

---

## 3. UI

### 3.1 Pages

Not applicable — this repository is a JSON-only API; the SPA lives in a separate repository.

### 3.2 Components

Not applicable — this repository is a JSON-only API; the SPA lives in a separate repository.

---

## 4. Database

### 4.1 Schema changes

| Table | Action | Details |
|---|---|---|
| `set_logs` | Add column | `day_exercise_id` `foreignId` nullable → `day_exercises.id`, `nullOnDelete()`. No index: the column is only read through eager loading by primary key, and PROD holds 703 sets across 34 sessions. `down()` drops the foreign key and the column |
| `set_logs` | Data backfill (separate migration, run after the column exists) | Sets `day_exercise_id` where `training_sessions.cycle_day_id` is not null **and** that day has exactly one `day_exercises` row with the set's `exercise_id`. Everything else stays `null`: free sessions, off-plan sets, and days that repeat an exercise (ambiguous). Single portable `UPDATE` with a correlated subquery that runs on PostgreSQL and SQLite. Not reversible (`down()` is a no-op) |

Verified on PROD (Neon `production` branch, read-only, 2026-11-01): 703 sets,
34 sessions, 1 free session, 0 days that repeat an exercise, 0 sets on repeated
exercises, 64 sets of planned sessions whose exercise is not in that day's
prescription (stay `null`). So today the backfill is unambiguous: after it,
`day_exercise_id IS NULL` must equal the sets of the free session plus those 64.

Model/factory impact: `SetLog` gains `day_exercise_id` in `#[Fillable]`, a
`dayExercise(): BelongsTo` relation and the PHPDoc refresh
(`php artisan ide-helper:models --write`, then Pint); `SetLogFactory` leaves it
`null` by default.

### 4.2 Seeds

Not applicable — no seeds.

---

## 5. Auth & Authorization

### 5.1 Authentication

**Method:** Laravel Sanctum bearer token, unchanged. The new endpoint is inside
the existing `auth:sanctum` group in `routes/api.php`.

### 5.2 Authorization

| Role | Permissions |
|---|---|
| Authenticated user | Reads only their own performance data. `GET /exercises/{exercise}/history`, `previous`, `history` and `summary` are computed from the caller's own `training_sessions` / `set_logs` (`user_id = auth user`), never from another user's. Every existing session, set and recommendation route keeps its current Policy gate unchanged |

No new Policy. `{exercise}` is the global catalogue (owned by no one, as in
`ListExercisesController`), and the history is isolated by always scoping the
query to the authenticated user in the Service. An unknown or foreign `routine`
filter value is not an error: it matches nothing, so the list is `[]` (same
convention as the `cycle_day` filter in `list-session-cycle-number-spec.md`).

---

## 6. Configuration

Not applicable — no configuration changes.

---

## 7. Current vs New Behavior

| Behavior | Current | New |
|---|---|---|
| Last performance of an exercise | Not exposed; the SPA cannot show it without N+1 | `previous` on each prescribed exercise in create and detail responses |
| Per-exercise history | Not exposed | `GET /exercises/{exercise}/history`, paginated, with volume and volume change |
| Set ↔ prescription link | Resolved when the set is logged, then discarded; SPA matches by exercise name | Stored in `set_logs.day_exercise_id` and returned by `SetLogResource` |
| Recommendation trend | `ExerciseRecommendationResource` has no past data | `history` with up to 4 points ending at the source session |
| Session volume | Not exposed; list has no sets | `summary` on every session payload, constant query cost |
| Layering of session list / detail and the routine recommendations list | Controllers query directly | Same controllers delegate to new Actions, because enriching the payload needs a Service and only an Action may call one |
| `training_sessions` / `set_logs` / recommendation existing keys | Present | Unchanged; all additions are new keys, so the current SPA keeps working |

---

## 8. Test Cases

*All test cases run with Pest on the SQLite `:memory:` suite; no manual steps.*

**TC-1:** `previous` is `null` for an exercise never trained
- **Given:** A user with an open planned session whose day prescribes exercise A and no earlier completed session containing A
- **When:** `GET /sessions/{session}`
- **Expect:** `data.cycle_day.exercises[0].previous` is `null`

**TC-2:** `previous` is the most recent completed session with that exercise, any routine
- **Given:** Completed sessions S1 (older, routine R1, exercise A) and S2 (newer, routine R2, free, exercise A); current session is a planned session of R1 started later
- **When:** `GET /sessions/{session}`
- **Expect:** `previous.session_id` is S2, `cycle_sequence_number` is `null`, `sets` are S2's sets ordered by `set_number`, each with `set_number`, `weight_kg`, `reps`, `rpe`

**TC-3:** `previous` of a planned previous session carries its cycle number
- **Given:** The latest earlier completed session with the exercise belongs to a day of the cycle with `sequence_number` 2
- **When:** `GET /sessions/{session}`
- **Expect:** `previous.cycle_sequence_number` is 2 and `previous.started_at` is that session's ISO 8601 `started_at`

**TC-4:** `previous` ignores in-progress sessions and sessions not strictly earlier
- **Given:** An in-progress session and a completed session with `started_at` equal to the current one, both containing the exercise
- **When:** `GET /sessions/{session}`
- **Expect:** Neither is returned as `previous`; the strictly earlier completed session (or `null`) is

**TC-5:** Viewing a completed session returns the session before it, not the latest
- **Given:** Completed sessions S1 < S2 < S3 with the exercise
- **When:** `GET /sessions/{S2}`
- **Expect:** `previous.session_id` is S1

**TC-6:** A session without sets of the exercise is not a `previous`
- **Given:** The latest earlier completed session has no set of exercise A (only B), an older one has A
- **When:** `GET /sessions/{session}`
- **Expect:** `previous` is the older session

**TC-7:** Another user's sessions never appear in `previous`
- **Given:** Another user has a newer completed session with the same exercise
- **When:** `GET /sessions/{session}` as the first user
- **Expect:** `previous` is the first user's own session or `null`

**TC-8:** `POST /routines/{routine}/sessions` returns `previous`
- **Given:** An active routine with a day whose exercise has a completed earlier session
- **When:** `POST /routines/{routine}/sessions` with that `day`
- **Expect:** 201 with `data.cycle_day.exercises[].previous` populated per TC-2

**TC-9:** Two prescriptions of the same exercise in a day share the same `previous`
- **Given:** A day with two `day_exercises` for exercise A and an earlier completed session with A
- **When:** `GET /sessions/{session}`
- **Expect:** Both exercises carry the same `previous`

**TC-10:** Detail query count does not grow with the number of exercises
- **Given:** Two sessions, one whose day prescribes 1 exercise and one that prescribes 6, all with history
- **When:** `GET /sessions/{session}` for each, counting queries
- **Expect:** Both responses run the same number of queries

**TC-11:** Cycle payloads are untouched
- **Given:** A routine with a cycle
- **When:** `GET` the routine's cycle
- **Expect:** `days[].exercises[]` has no `previous` key

**TC-12:** History requires authentication
- **Given:** No token
- **When:** `GET /exercises/{exercise}/history`
- **Expect:** 401 with `data.code` `AUTHENTICATION_EXCEPTION`

**TC-13:** History of an unknown exercise is a 404
- **Given:** An authenticated user
- **When:** `GET /exercises/{random-uuid}/history`
- **Expect:** 404 with `data.code` `NOT_FOUND_EXCEPTION`

**TC-14:** An exercise without history returns an empty list
- **Given:** An existing exercise the user never trained
- **When:** `GET /exercises/{exercise}/history`
- **Expect:** 200, `data` is `[]`

**TC-15:** History is newest first, completed only, own only
- **Given:** Three completed sessions of the user, one in-progress session and one completed session of another user, all with the exercise
- **When:** `GET /exercises/{exercise}/history`
- **Expect:** Exactly the three completed own sessions, ordered by `started_at` descending (then `id` descending)

**TC-16:** History item shape and values
- **Given:** A completed planned session with exercise sets (60×8, 62.5×6 with a note, 62.5×5) plus sets of another exercise in the same session
- **When:** `GET /exercises/{exercise}/history`
- **Expect:** `top_weight_kg` 62.5, `volume_kg` 1167.5 (only this exercise's sets), `sets` with `set_number`, `weight_kg`, `reps`, `rpe`, `note`, `cycle_sequence_number` and `cycle_day_label` from the cycle day

**TC-17:** Free session item has no cycle data
- **Given:** A completed free session with the exercise
- **When:** `GET /exercises/{exercise}/history`
- **Expect:** `cycle_sequence_number` and `cycle_day_label` are `null`

**TC-18:** `volume_change_pct` against the previous session of any routine or free
- **Given:** Volumes 1000 (oldest, routine R1), 1100 (free), 990 (routine R2, newest)
- **When:** `GET /exercises/{exercise}/history`
- **Expect:** Newest `-10.0`, middle `10.0`, oldest `null`

**TC-19:** `volume_change_pct` is `null` when the previous volume is 0 and rounds to 1 decimal
- **Given:** A previous session with volume 0 (all sets weight 0) and another pair 300 → 310 (3.333…%)
- **When:** `GET /exercises/{exercise}/history`
- **Expect:** `null` for the item after the 0-volume session; `3.3` for the second pair

**TC-20:** `volume_change_pct` is correct across the page boundary
- **Given:** Five completed sessions with distinct volumes
- **When:** `GET /exercises/{exercise}/history?per_page=2&page=2`, then `page=3`
- **Expect:** The last item of page 2 has its percentage computed against the first item of page 3; the only `null` is the oldest session overall (page 3); `meta` reports the 5 total

**TC-21:** `routine` filter limits the list but not the comparison base
- **Given:** Sessions alternating between routines R1 and R2 for the exercise
- **When:** `GET /exercises/{exercise}/history?routine={R1}` and again without it
- **Expect:** Only R1 items listed; each R1 item's `volume_change_pct` equals the value it has in the unfiltered response

**TC-22:** Unknown or foreign `routine` filter returns an empty list; malformed is a 422
- **Given:** A well-formed routine uuid of another user (or non-existent); then `routine=not-a-uuid`
- **When:** `GET /exercises/{exercise}/history?routine=…`
- **Expect:** 200 with `data: []` for the first; 422 `VALIDATION_EXCEPTION` with `data.errors.routine` for the second

**TC-23:** Pagination parameters are validated and defaulted
- **Given:** 12 completed sessions
- **When:** `per_page` is 0, 51, `abc`, or omitted; `page` is 0
- **Expect:** 422 with `data.errors` for the invalid ones; omitted `per_page` returns 10 items with `meta.per_page` 10

**TC-24:** Logging a set against a prescription stores `day_exercise_id`
- **Given:** An open planned session and a `day_exercise` of its day
- **When:** `POST /sessions/{session}/sets` with `day_exercise_id`
- **Expect:** 201, `data.day_exercise_id` is that prescription's uuid and the row stores its id

**TC-25:** Logging an off-plan set leaves `day_exercise_id` null
- **Given:** An open session
- **When:** `POST /sessions/{session}/sets` with `exercise_id` only
- **Expect:** 201, `data.day_exercise_id` is `null`

**TC-26:** Updating a set keeps its `day_exercise_id`
- **Given:** A set stored with a `day_exercise_id`
- **When:** `PUT /sessions/{session}/sets/{set}` with new weight and reps
- **Expect:** 200, `data.day_exercise_id` unchanged

**TC-27:** Session detail sets carry `day_exercise_id` without N+1
- **Given:** A session whose day repeats the same exercise in two prescriptions, a set logged against each
- **When:** `GET /sessions/{session}`
- **Expect:** Each set reports the prescription it was logged against; query count equals that of a session with fewer sets

**TC-28:** Workbook import stores `day_exercise_id`
- **Given:** A valid day workbook
- **When:** `POST /routines/{routine}/cycle-days/{day}/import`
- **Expect:** Every created set has the `day_exercise_id` of its row's prescription

**TC-29:** Backfill assigns only unambiguous sets
- **Given:** Sets with `day_exercise_id` null: one in a planned session with a unique matching prescription, one in a day that repeats the exercise, one off-plan in a planned session, one in a free session
- **When:** The test requires the backfill migration file and calls its `up()` (the column already exists from the schema migration, so the test only exercises the `UPDATE`)
- **Expect:** Only the first is assigned to the matching `day_exercises.id`; the other three stay `null`

**TC-30:** Recommendation `history` ends at the source session and is oldest to newest
- **Given:** Five completed sessions with the exercise and a recommendation whose `source_session_id` is the fourth
- **When:** `GET /sessions/{session}` (the source session) and `GET /routines/{routine}/recommendations`
- **Expect:** `history` has 4 points: sessions 1–4 in chronological order; session 5 is absent

**TC-31:** `history` point values and tie-breaking
- **Given:** A recommendation whose source session has sets 57.5×8, 60×5, 60×7, 60×7 of the exercise (two equal top sets)
- **When:** `GET /routines/{routine}/recommendations`
- **Expect:** `top_weight_kg` 60, `top_set_reps` 7, `volume_kg` computed over all that exercise's sets; with equal reps the lower `set_number` decides

**TC-32:** `history` spans the user's routines and never another user's
- **Given:** A recommendation, plus earlier completed sessions with the exercise in another routine of the same user and in another user's account
- **When:** `GET /routines/{routine}/recommendations`
- **Expect:** Includes the user's other-routine session; excludes the other user's

**TC-33:** `history` with fewer than 4 sessions, and with `source_session_id` null
- **Given:** A recommendation with 2 eligible sessions; another (different exercise) with `source_session_id` null and 6 completed sessions
- **When:** `GET /routines/{routine}/recommendations`
- **Expect:** 2 items; the 4 most recent in chronological order

**TC-34:** Recommendation lists do not run N+1 for `history`
- **Given:** A routine with 2 active recommendations, then one with 6
- **When:** `GET /routines/{routine}/recommendations`, counting queries
- **Expect:** Same query count in both

**TC-35:** `summary` of a completed session
- **Given:** A completed session of 3 exercises (one off-plan), 9 sets, started and completed 3,605 seconds apart
- **When:** `GET /sessions/{session}`
- **Expect:** `exercises_count` 3, `sets_count` 9, `total_volume_kg` the 2-decimal sum of `weight_kg × reps`, `duration_seconds` 3605

**TC-36:** `summary` of an in-progress session is partial with no comparison
- **Given:** An open planned session with 2 sets logged and a previous-cycle baseline available
- **When:** `GET /sessions/{session}`
- **Expect:** Partial counts and volume, `duration_seconds` `null`, `volume_change_pct` `null`

**TC-37:** `summary` of an empty session
- **Given:** A freshly created session with no sets
- **When:** `POST /routines/{routine}/sessions`
- **Expect:** 201, `summary` is `{exercises_count: 0, sets_count: 0, total_volume_kg: 0, duration_seconds: null, volume_change_pct: null}`

**TC-38:** `summary.volume_change_pct` compares with the same day of the immediately previous cycle
- **Given:** Cycle 2 day `order` 2 session volume 1100 and a completed cycle 1 day `order` 2 session volume 1000; another cycle 1 day `order` 1 session with a different volume
- **When:** `GET /sessions/{session}` for the cycle 2 session
- **Expect:** `volume_change_pct` `10.0`

**TC-39:** `summary.volume_change_pct` takes the most recent baseline when the day was done twice
- **Given:** Two completed cycle 1 sessions on the same day `order`
- **When:** `GET /sessions/{session}`
- **Expect:** The later one is the baseline

**TC-40:** `summary.volume_change_pct` is `null` when there is nothing valid to compare
- **Given:** (a) a cycle 1 session; (b) a cycle 3 session whose cycle 2 has no completed session on that day while cycle 1 does; (c) a free session; (d) a baseline of volume 0; (e) a baseline of another routine
- **When:** `GET /sessions/{session}` for each
- **Expect:** `volume_change_pct` is `null` in all five

**TC-41:** `summary` on the list costs a constant number of queries
- **Given:** One routine with 3 completed sessions, then another with 15, all with sets and baselines
- **When:** `GET /routines/{routine}/sessions` (default and `per_page=15`), counting queries
- **Expect:** Same query count regardless of the number of sessions; each item has a correct `summary`

**TC-42:** `summary` is present on complete and import
- **Given:** An open session with sets; a valid day workbook
- **When:** `POST /sessions/{session}/complete`; `POST .../import`
- **Expect:** Both responses include `summary` with `duration_seconds` not `null`

**TC-43:** Pre-existing keys of the session payloads are unchanged
- **Given:** A completed planned session with 2 sets and 1 recommendation
- **When:** `GET /sessions/{session}` and `GET /routines/{routine}/sessions`
- **Expect:** Every pre-existing key is still present with the same type: session `id`, `status`, `analysis_state`, `note`, `perceived_effort`, `started_at`, `completed_at`, `created_at`, `updated_at`, `cycle_day`, `sets`, `recommendations` (detail only); each set `id`, `exercise`, `set_number`, `weight_kg`, `reps`, `rpe`, `note`, `created_at`, `updated_at`; each recommendation `id`, `exercise`, `target_weight_kg`, `target_sets`, `target_rep_min`, `target_rep_max`, `action`, `explanation`

**TC-44:** `ExerciseHistoryService` previous selection (unit)
- **Given:** For one user: completed sessions S1 < S2 < S3 containing exercise A, an in-progress session containing A, a completed session containing only exercise B, and a completed session of another user containing A; a current session started after S3
- **When:** The test calls the service's previous lookup for the current session and exercise A
- **Expect:** The result is S3 with its sets ordered by `set_number`; for the current session replaced by S2 the result is S1

**TC-45:** `ExerciseHistoryService` history page and boundary percentage (unit)
- **Given:** Five completed sessions with distinct volumes of exercise A and a sixth of another user
- **When:** The test requests page 2 of size 2 and then page 3
- **Expect:** Page 2 items are the 3rd and 4th newest, the 4th's `volume_change_pct` is computed against the 5th, page 3 holds only the oldest with `null`; the other user's session never appears

**TC-46:** `ExerciseHistoryService` trend points (unit)
- **Given:** Six completed sessions with exercise A; the fourth is the source session; the top-weight tie has different reps in one session
- **When:** The test requests the trend for the source session, and for a `null` source
- **Expect:** For the fourth: 4 points sessions 1–4 oldest first, with the tie resolved to the set with more reps; for `null`: the 4 most recent in chronological order

**TC-47:** Recommendation `history` ignores sessions that are not completed
- **Given:** A recommendation whose exercise also appears in an in-progress session of the same user that started before the source session
- **When:** `GET /routines/{routine}/recommendations`
- **Expect:** The in-progress session is not among the `history` points

**TC-48:** History orders sessions with equal `started_at` by `id` descending
- **Given:** Two completed sessions with the exercise and identical `started_at`
- **When:** `GET /exercises/{exercise}/history`
- **Expect:** The session with the higher `id` comes first, and the second one's `volume_change_pct` is computed against the other

**TC-49:** `previous` is present and `null` on create for a never-trained exercise
- **Given:** An active routine with a day whose exercises were never trained
- **When:** `POST /routines/{routine}/sessions` with that `day`
- **Expect:** 201 and every `data.cycle_day.exercises[]` item has the key `previous` with value `null`

**TC-50:** `SessionSummaryService` resolves a page in constant queries (unit)
- **Given:** Sessions of mixed kinds: completed planned with a cycle-1 baseline, in-progress, free, and one whose baseline has volume 0
- **When:** The test calls the service with 2 sessions, then with all of them, counting queries
- **Expect:** Both calls run the same number of queries and each session gets its expected counts, volume and `volume_change_pct`

---

## 9. Technical Decisions

| Decision area | What was decided | Why |
|---|---|---|
| Shared computation | One `ExerciseHistoryService` (`app/Services/Exercise/`) serves `previous`, the history endpoint and the recommendation trend, and returns one `ExercisePerformanceData` (`app/Data/Exercise/`) per exercise-session | The ticket asks the three to share one query path; avoids three diverging definitions of "volume" and "top set" |
| Session summary | `SessionSummaryService` (`app/Services/Session/`) resolves a whole page of sessions in a fixed number of aggregate queries (sets grouped by session, then the baseline sessions) and hands a `Data` object per session to the Resource | Chosen over per-row SQL subqueries (hard to keep portable between SQLite and PostgreSQL, and show/create would need another path) and over stored columns (backfill, drift when a set is edited) |
| Layering | New `TrainingSessionListAction`, `TrainingSessionShowAction` and `RoutineRecommendationsListAction` take over the queries now in `ListTrainingSessionsController`, `ShowTrainingSessionController` and `ListRoutineRecommendationsController`; the existing `TrainingSessionCreateAction` and `SessionCloseAction` call the Services directly | Services may only be called by Actions (CLAUDE.md pipeline); import and complete need no change of their own because they go through those two Actions |
| New endpoint pipeline | `ListExerciseHistoryRequest` → `ListExerciseHistoryController` → `ExerciseHistoryListAction` → `ExerciseHistoryService` → `ExerciseHistoryItemResource` collection | Same pipeline as every endpoint; the paginator `links` / `meta` come from `paginate()->withQueryString()` like the sessions list |
| Page-boundary percentage | The service takes the page of items, then fetches only the one session immediately older than the last item to complete its percentage | Correct across pages with one extra query, without loading the whole history |
| Payload mechanics | The Services return their results as `Data` objects; `previous`, `history` and `summary` are set on the models (`setRelation` / attributes) before the Resource runs, and resources read them. Missing data throws under `Model::shouldBeStrict` outside production instead of silently omitting a key | Keeps Resources pure and makes a forgotten code path fail loudly in tests |
| Nested shapes | `previous` and the trend points are shaped inline inside `DayExerciseResource` and `ExerciseRecommendationResource` with `collect()->map()` (Scramble infers item types from it, not from `array_map`; precedent: `cycle` in `CycleDayResource`); only the history item has its own Resource | No resource that only adds indirection (golden rule 6) |
| `previous` availability | Only create and detail populate it. `DayExerciseResource` emits the key whenever it has been populated, **including with `null`** (never trained), and omits it only when no code path populated it (`CycleResource` payloads) | Avoids N+1 in cycle payloads and keeps the change additive; TC-1, TC-11 and TC-49 pin both cases |
| File locations | Actions: `app/Actions/Session/TrainingSessionListAction.php`, `TrainingSessionShowAction.php`, `app/Actions/Recommendation/RoutineRecommendationsListAction.php`, `app/Actions/Exercise/ExerciseHistoryListAction.php`. Services: `app/Services/Exercise/ExerciseHistoryService.php`, `app/Services/Session/SessionSummaryService.php`. Data: `app/Data/Exercise/ExercisePerformanceData.php`, `app/Data/Session/SessionSummaryData.php`. HTTP: `app/Http/Requests/Exercise/ListExerciseHistoryRequest.php`, `app/Http/Controllers/Exercise/ListExerciseHistoryController.php`, `app/Http/Resources/Exercise/ExerciseHistoryItemResource.php`. Tests: `tests/Feature/Exercise/ListExerciseHistoryTest.php`, `tests/Unit/Exercise/ExerciseHistoryServiceTest.php`, `tests/Unit/Session/SessionSummaryServiceTest.php`, plus additions to the existing Session, Recommendation and set-log feature tests | Folders by domain as in CLAUDE.md "Layout" |
| `day_exercise_id` storage | Nullable FK `set_logs.day_exercise_id` with `nullOnDelete`; stored exactly as the client declared it; `SetLogCreateAction` and `SetLogUpdateAction` return the set with `dayExercise` loaded, and the detail loads `sets.dayExercise` | Strict mode forbids lazy loading; the update never changes the link |
| Backfill | Separate data migration after the schema migration; unambiguous matches only | Splits schema from data so the backfill is testable (TC-29); verified on PROD that nothing is ambiguous today |
| Rounding | `volume_change_pct` 1 decimal, volumes 2 decimals, as JSON numbers; `top_set_reps` tie-break: more reps, then lower `set_number` | Agreed with the product owner; deterministic and reproducible in tests |
| Comparison base of `summary.volume_change_pct` | Fixed: immediately previous cycle, same day `order`, most recent completed session | Agreed with the product owner; no query parameter or stored preference |
| History comparison base | `volume_change_pct` ignores the `routine` filter and the page window | Agreed with the product owner; a session's number is identical with or without the filter |
| Authorization of the history | No Policy; the Service always filters by the authenticated user's id; unknown or foreign `routine` yields an empty list | The exercise is global catalogue data; user isolation is by query and covered by TC-15, TC-22 and TC-32 |
| Indexes | None added | PROD has 703 sets and 34 sessions; revisit if volumes grow |
| Documentation | Scramble infers the new fields from Resources, the Form Request and return types; no annotations | CLAUDE.md "API documentation" |

---

## 10. Work Plan

| # | Task | Definition of Done |
|---|---|---|
| 1 | Create the `set_logs.day_exercise_id` migration, add the `dayExercise` relation and fillable to `SetLog`, update `SetLogFactory`, refresh the model PHPDoc | `php artisan migrate` and rollback run clean; `ide-helper:models --write` + Pint applied; model PHPDoc lists the column and relation |
| 2 | Create the backfill data migration | TC-29 passes; on a PROD-shaped copy `day_exercise_id IS NULL` equals free-session sets plus the 64 off-plan sets |
| 3 | Persist `day_exercise_id` in `SetLogCreateAction`, load `dayExercise` in create/update, add `day_exercise_id` to `SetLogResource`, and add `sets.dayExercise` to the eager load of the existing `ShowTrainingSessionController` (one line, so the detail keeps working under strict mode; Task 6 later moves it into the Show Action) | TC-24, TC-25, TC-26, TC-27, TC-28 pass; the existing `ShowTrainingSessionTest` still passes |
| 4 | Create `ExercisePerformanceData` and `ExerciseHistoryService` (previous per exercise, history page with boundary-correct percentage, trend points) | `tests/Unit/Exercise/ExerciseHistoryServiceTest.php` written and TC-44, TC-45, TC-46 pass |
| 5 | Create `ListExerciseHistoryRequest`, `ListExerciseHistoryController`, `ExerciseHistoryListAction`, `ExerciseHistoryItemResource` and the route `GET /exercises/{exercise}/history` | `tests/Feature/Exercise/ListExerciseHistoryTest.php` written; TC-12 to TC-23 and TC-48 pass; the endpoint appears in the Scramble spec |
| 6 | Create `TrainingSessionShowAction` (taking over the detail's eager loads) and populate `previous` in it and in `TrainingSessionCreateAction`; `DayExerciseResource` emits `previous` whenever populated, including `null` | TC-1 to TC-11 and TC-49 pass; TC-27 still passes after the move |
| 7 | Create `SessionSummaryData` and `SessionSummaryService` | `tests/Unit/Session/SessionSummaryServiceTest.php` written and TC-50 passes |
| 8 | Add `summary` to `TrainingSessionResource`, create `TrainingSessionListAction`, and wire the list, detail, create and close paths to the service | TC-35 to TC-42 pass (TC-41 proves the constant query count) and TC-43 passes |
| 9 | Add `history` to `ExerciseRecommendationResource`, create `RoutineRecommendationsListAction`, and attach the trend in the detail and in the routine recommendations list | TC-30 to TC-34 and TC-47 pass |
| 10 | Run the whole quality gate | `vendor/bin/pint --test`, `vendor/bin/phpstan analyse` (level 6) and the full `vendor/bin/pest` suite pass with the only edits to pre-existing tests being the exact-key assertions in `ShowTrainingSessionTest` extended with the new keys (`day_exercise_id`, `summary`, `history`); each new test file passes 10 consecutive runs (a shell loop of `vendor/bin/pest <file>`); Scramble docs show every new field |
