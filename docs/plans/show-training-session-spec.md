# Show a training session — `GET /api/v1/sessions/{session}`

> Base contract: `docs/product-context.md`, `docs/plans/data-model.md`
> §`training_sessions`, `CLAUDE.md` "The pipeline". Consumes what
> `docs/plans/create-training-session-spec.md`, `docs/plans/store-set-logs-spec.md`
> and `docs/plans/session-analysis-spec.md` built (`TrainingSession`, `SetLog`,
> `ExerciseRecommendation` models and their Resources) and completes the read side
> started by `docs/plans/list-routine-sessions-spec.md`, which deferred the detail
> to "a later ticket".

## 1. Context

**Kind:** Brownfield Feature

**Stack:** PHP 8.5 · Laravel 13 · PostgreSQL 17 (runtime) / SQLite `:memory:`
(tests) · Pest 4 (`pest-plugin-laravel`) · `laravel/sanctum` 4 ·
`dedoc/scramble` · Pint · Larastan level 6. Everything runs in Docker.

**Problem statement:** The client can list a routine's sessions, but the list is
a summary: it carries no set logs and no recommendations. To show "how did this
workout go" — every set actually performed next to what the day prescribed, and
what the analysis recommended afterwards — it needs one session in full. This
ticket adds a read-only detail endpoint. No schema change: every table and
foreign key involved already exists. The existing `TrainingSessionResource` is
reused and gains two optional, `whenLoaded` keys, so the list payload does not
change.

**In scope:**
- `GET /api/v1/sessions/{session}` — return one training session by public UUID,
  whatever its `status` (`in_progress` or `completed`), `analysis_state` or the
  status of its routine (`active` or `archived`), planned or free.
- Payload: the existing `TrainingSessionResource` fields plus:
  - `cycle_day` with its `exercises` (the prescription: sets, rep range, target
    weight / RPE, rest, rationale, ordered by `order`); `null` for a free session.
  - `sets` — every `SetLog` of the session as `SetLogResource` (each with its
    `exercise`), a flat list ordered by `exercise_id` then `set_number`; `[]` when
    none were logged yet.
  - `recommendations` — every `ExerciseRecommendation` whose `source_session_id`
    is this session, as `ExerciseRecommendationResource` (each with its
    `exercise`), whatever its `status` (`active` or `applied`), ordered by `id`;
    `[]` when the session has not been analysed (in progress, `pending`,
    `failed`) or the analysis produced none.
- `TrainingSessionResource`: add `sets` and `recommendations` behind
  `whenLoaded`.
- `TrainingSession::recommendations()`: a new `hasMany` relation over
  `exercise_recommendations.source_session_id`, with the model PHPDoc block
  (`@property-read Collection<int, ExerciseRecommendation> $recommendations`,
  `@property-read int|null $recommendations_count`) refreshed.
- `TrainingSessionPolicy::view` (owner only).
- `App\Http\Controllers\Session\ShowTrainingSessionController` (invokable) and
  `App\Http\Requests\Session\ShowTrainingSessionRequest` (`authorize()` delegates
  to the Policy).
- One route added to the `auth:sanctum` group in `routes/api.php`
  (`sessions.show`), constrained with `->whereUuid('session')`.
- One assertion added to `tests/Feature/Auth/DocsSecurityTest.php`.
- Pest coverage of every rule below.

**Out of scope:**
- Any schema change, and any change to `SetLogResource`,
  `ExerciseRecommendationResource`, `CycleDayResource`, `DayExerciseResource`,
  `SetLogPolicy` or the list endpoint (`ListTrainingSessionsController`), whose
  payload must stay byte-for-byte identical.
- Data of the routine (the session exposes no `routine` key; the client already
  knows the routine it navigated from).
- Grouping the sets per exercise (`exercises: [{exercise, sets}]`) — the client
  groups the flat list.
- Aggregates (`sets_count`, total volume, personal records) and pagination of
  the sets.
- Filtering the sets or recommendations (by exercise, by recommendation status).
- Editing or deleting a session (no `PUT` / `DELETE` in this ticket).
- A cross-routine listing of all the user's sessions (`GET /sessions`).
- The `gym-trainer-spa/` frontend (separate repository).

---

## 2. API Surface

### 2.1 REST

| Method | Path | Auth | Request | Response | Status codes |
|---|---|---|---|---|---|
| GET | `/api/v1/sessions/{session}` | `auth:sanctum` | Path: `session` (public UUID). No query string, no body | `TrainingSessionResource`: `{ "data": {id, status, analysis_state, note, perceived_effort, started_at, completed_at, created_at, updated_at, cycle_day, sets, recommendations} }`. `cycle_day` is `null` for a free session, otherwise `{id, order, label, focus_muscle_groups, rationale, exercises: [{id, order, name, sets, rep_min, rep_max, target_weight_kg, target_rpe, rest_seconds, rationale}]}`. `sets` is `[{id, exercise, set_number, weight_kg, reps, rpe, note, created_at, updated_at}]`. `recommendations` is `[{id, exercise, target_weight_kg, target_sets, target_rep_min, target_rep_max, action, explanation}]`. `sets` and `recommendations` are `[]` when empty, never absent | 200, 401 `AUTHENTICATION_EXCEPTION`, 403 `AUTHORIZATION_EXCEPTION` (session of another user), 404 `NOT_FOUND_EXCEPTION` (unknown or non-UUID session) |

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

Not applicable — no data or schema changes. The endpoint reads
`training_sessions`, `cycle_days`, `day_exercises`, `set_logs`,
`exercise_recommendations` and `exercises` through relations and columns that
already exist (`set_logs.session_id`, `exercise_recommendations.source_session_id`).

### 4.1 Schema changes

Not applicable — no schema changes.

### 4.2 Seeds

Not applicable — no seeds.

---

## 5. Auth & Authorization

### 5.1 Authentication

**Method:** Sanctum SPA cookie session (`auth:sanctum`), like every route in the
`/api/v1` group.

### 5.2 Authorization

| Role | Permissions |
|---|---|
| Authenticated owner of the session (`training_sessions.user_id` = user) | Read it, in any status, under an active or archived routine, via `TrainingSessionPolicy::view` |
| Authenticated non-owner | `403` — cannot read another user's session, sets or recommendations |
| Guest | `401` |

`ShowTrainingSessionRequest::authorize()` calls
`$user->can('view', $this->route('session'))`, like `CompleteTrainingSessionRequest`.
It runs after route-model binding: an unknown `{session}` is a 404 before the
Policy, a foreign one a 403 in it. The sets and recommendations need no extra
check because they are loaded through the authorised session.

---

## 6. Configuration

Not applicable — no configuration changes.

---

## 7. Current vs New Behavior

| Behavior | Current | New |
|---|---|---|
| Reading one session | No endpoint; `GET sessions/{session}` returns 404 (no route matches that path; only the `sets` and `complete` sub-paths are registered) | `GET` returns the session with its prescription, sets and recommendations |
| `TrainingSessionResource` | `cycle_day` is the only relation exposed | Also exposes `sets` and `recommendations`, only when loaded; the list, create and complete responses do not load them and stay unchanged |
| `TrainingSession` model | Relations `user`, `routine`, `cycleDay`, `sets` | Adds `recommendations` |
| `TrainingSessionPolicy` | `create`, `complete` | Adds `view` |
| OpenAPI spec | `/api/v1/sessions/{session}` is absent | Documents `get`, inheriting the global `security` scheme |

---

## 8. Test Cases

*Files: `tests/Feature/Session/ShowTrainingSessionTest.php` (Pest; `beforeEach`
sets the `Origin` header and creates a user, like `ListTrainingSessionsTest`) and
`tests/Unit/Session/TrainingSessionPolicyTest.php` (extended).*

**TC-1:** shows a completed planned session in full
- **Given:** a completed session of a routine with an active cycle, opened on a cycle day that prescribes two exercises, with logged sets and one recommendation whose `source_session_id` is the session
- **When:** the owner calls `GET /api/v1/sessions/{session}`
- **Expect:** 200; `data.id` is the session uuid; `data.status` is `completed`; `data.cycle_day.exercises` has two items; `data.sets` and `data.recommendations` are populated

**TC-2:** shows an in-progress session
- **Given:** an in-progress session with one logged set and no analysis
- **When:** the owner shows it
- **Expect:** 200; `status` is `in_progress`; `completed_at` is `null`; `sets` has one item; `recommendations` is `[]`

**TC-3:** shows a free session
- **Given:** a session with `cycle_day_id` null and logged sets
- **When:** the owner shows it
- **Expect:** 200; `data.cycle_day` is `null`; `data.sets` is populated

**TC-4:** returns empty arrays, not missing keys
- **Given:** an in-progress session with no sets and no recommendations
- **When:** the owner shows it
- **Expect:** 200; `data.sets` and `data.recommendations` exist and equal `[]`

**TC-5:** orders the sets by exercise then set number
- **Given:** sets created out of order: exercise B set 1, exercise A set 2, exercise A set 1, where A's `exercise_id` is lower than B's
- **When:** the owner shows the session
- **Expect:** `data.sets` order is A#1, A#2, B#1

**TC-6:** each set carries its exercise and formatted numbers
- **Given:** a set with `weight_kg` 82.5, `reps` 8, `rpe` 8.5
- **When:** the owner shows the session
- **Expect:** the item has exactly `id, exercise, set_number, weight_kg, reps, rpe, note, created_at, updated_at`; `exercise` is an object with a name; `weight_kg` and `rpe` are JSON numbers; `id` is a UUID v4; a set without RPE has `rpe` null

**TC-7:** returns only this session's sets
- **Given:** two sessions of the same routine, each with sets
- **When:** the owner shows the first
- **Expect:** only the first session's sets appear

**TC-8:** returns every recommendation sourced from the session, whatever its status
- **Given:** one `active` and one `applied` recommendation with `source_session_id` = the session, and one recommendation sourced from another session
- **When:** the owner shows the session
- **Expect:** `data.recommendations` holds exactly the two of this session, ordered by id; the other session's one is absent

**TC-9:** recommendation item structure
- **Given:** a recommendation of the session
- **When:** the owner shows it
- **Expect:** the item has exactly `id, exercise, target_weight_kg, target_sets, target_rep_min, target_rep_max, action, explanation`; `exercise` is an object; `action` is a string; `id` is a UUID v4

**TC-10:** prescription is included and ordered
- **Given:** a planned session whose day has three `day_exercises` with `order` 1, 2, 3 created out of order
- **When:** the owner shows it
- **Expect:** `data.cycle_day.exercises` has three items, each with a `name`, ordered by `order`

**TC-11:** session payload structure and formats
- **Given:** a completed planned session
- **When:** the owner shows it
- **Expect:** `data` has exactly `id, status, analysis_state, note, perceived_effort, started_at, completed_at, created_at, updated_at, cycle_day, sets, recommendations`; `id` is a UUID v4 (never the numeric id); dates are ISO-8601; `user_id`, `routine_id`, `cycle_day_id` and `conversation_id` are absent

**TC-12:** works for an archived routine
- **Given:** a completed session whose routine is archived
- **When:** the owner shows it
- **Expect:** 200 with the session

**TC-13:** does not lazy load
- **Given:** a planned session with several sets, day exercises and recommendations (strict models are on outside production)
- **When:** the owner shows it
- **Expect:** 200 and no `LazyLoadingViolationException` (`cycleDay.dayExercises.exercise`, `sets.exercise` and `recommendations.exercise` are eager loaded)

**TC-14:** the list payload does not change
- **Given:** a session with sets and recommendations
- **When:** the owner calls `GET /api/v1/routines/{routine}/sessions`
- **Expect:** each item has no `sets` and no `recommendations` key (the existing `ListTrainingSessionsTest` structure test, TC-13 there, stays green)

**TC-15:** requires authentication
- **Given:** no session cookie
- **When:** `GET /api/v1/sessions/{session}`
- **Expect:** 401, `data.code` = `AUTHENTICATION_EXCEPTION`

**TC-16:** forbids another user's session
- **Given:** a session owned by someone else, with sets and recommendations
- **When:** the user shows it
- **Expect:** 403, `data.code` = `AUTHORIZATION_EXCEPTION`; no session data in the body

**TC-17:** unknown or malformed session id
- **Given:** a random UUID, and the string `not-a-uuid`
- **When:** the user shows each
- **Expect:** 404, `data.code` = `NOT_FOUND_EXCEPTION`

**TC-18:** `TrainingSessionPolicy::view`
- **Given:** a session and its owner, and another user
- **When:** `view` is evaluated for each
- **Expect:** true for the owner, false for the other user

**TC-19:** OpenAPI document keeps the security scheme
- **Given:** `tests/Feature/Auth/DocsSecurityTest.php`
- **When:** the spec is generated
- **Expect:** `$spec['paths']['/api/v1/sessions/{session}']['get']` has no per-operation `security` override (inherits the global one)

---

## 9. Technical Decisions

| Decision area | What was decided | Why |
|---|---|---|
| Route shape | `GET sessions/{session}`, name `sessions.show`, `whereUuid('session')` | The set and complete routes already hang from `/sessions/{session}`; the session is identified by its own uuid, so nesting it under a routine adds nothing. Chosen by the user |
| Pipeline | Form Request → invokable Controller → Resource; no Action, no Service | Plain read: no transaction, job, event or business rule. Same reasoning as `ListTrainingSessionsController`; a Service that only wraps eager loads is the indirection `CLAUDE.md` rule 6 rejects |
| Ownership | `TrainingSessionPolicy::view` (owner = `user_id`), called from `ShowTrainingSessionRequest::authorize()` | Same pattern as `CompleteTrainingSessionRequest`; the Policy documents it and is unit-tested. Route-level `->can()` is not used because the sibling `sessions/*` routes gate through the Form Request |
| Resource | Reuse `TrainingSessionResource`; add `sets` and `recommendations` with `whenLoaded` | One Resource per entity (`CLAUDE.md`). Only the detail controller loads the relations, so list / create / complete payloads are unchanged. Chosen by the user |
| Eager loading | `cycleDay.dayExercises.exercise`, `sets.exercise` (ordered), `recommendations.exercise` | Avoids N+1 under `Model::shouldBeStrict`; `DayExerciseResource` reads `$this->exercise->name`, so the nested `exercise` must be loaded |
| Sets shape and order | Flat list of `SetLogResource`, ordered by `exercise_id` then `set_number`, applied in the eager-load constraint | Reuses the existing Resource, no new structure; the client groups. Ordering makes the output deterministic. Chosen by the user |
| Recommendations | All rows with `source_session_id` = the session, regardless of `status`; ordered by `id`; new `TrainingSession::recommendations()` `hasMany` | Traceability: the detail shows what this session's analysis produced even after a cycle rollover marked it `applied`. Chosen by the user |
| Session/routine state | Any session status and any routine status | History must stay readable, as in the list; the Policy only checks ownership. Chosen by the user |
| Prescription | `cycle_day.exercises` included via `CycleDayResource` as-is; `null` for free sessions | Lets the client compare planned vs performed without a second call. Chosen by the user |
| Model PHPDoc | Add `recommendations` / `recommendations_count` to the `TrainingSession` block, via `ide-helper:models --write` plus a manual check | `CLAUDE.md`: every model carries a complete PHPDoc; a new relation is not caught by the tool's hand-written parts |
| Migration / index | None | `set_logs.session_id` and `exercise_recommendations.source_session_id` are foreign keys, already indexed by them; one session has a handful of rows |

---

## 10. Work Plan

| # | Task | Definition of Done |
|---|---|---|
| 1 | Add `TrainingSession::recommendations()` (`hasMany(ExerciseRecommendation::class, 'source_session_id')`) and refresh the model PHPDoc (`ide-helper:models --write`, manual check) | The relation resolves in a test; PHPStan clean |
| 2 | Add `TrainingSessionPolicy::view` (owner only) with its PHPDoc | TC-18 passes |
| 3 | Add `sets` and `recommendations` (`whenLoaded`) to `TrainingSessionResource` | TC-14 passes; existing list, create and complete tests stay green |
| 4 | Create `ShowTrainingSessionRequest` in `app/Http/Requests/Session/` (`authorize()` → `view`, empty `rules()`) | TC-16 passes |
| 5 | Create `ShowTrainingSessionController` in `app/Http/Controllers/Session/` (load the relations, sets ordered) returning `TrainingSessionResource::make` | TC-1 to TC-13 pass |
| 6 | Register `GET sessions/{session}` (`sessions.show`, `whereUuid`) in `routes/api.php` | TC-15 and TC-17 pass; `php artisan route:list` shows the route |
| 7 | Add the `['get']` assertion for the path to `tests/Feature/Auth/DocsSecurityTest.php` | TC-19 passes |
| 8 | Write `ShowTrainingSessionTest.php` (TC-1 to TC-17) and extend `TrainingSessionPolicyTest.php` (TC-18) | Both files are green |
| 9 | Run Pint, PHPStan and the narrowest Pest runs (new file, policy test, `DocsSecurityTest`, session feature folder) | All clean |
