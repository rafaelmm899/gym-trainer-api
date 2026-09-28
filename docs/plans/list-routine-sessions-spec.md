# List a routine's training sessions — `GET /api/v1/routines/{routine}/sessions`

> Base contract: `docs/product-context.md`, `docs/plans/data-model.md`
> §`training_sessions`, `CLAUDE.md` "The pipeline". Consumes what
> `docs/plans/create-training-session-spec.md` built (`TrainingSession` model,
> `TrainingSessionResource`, `Routine::trainingSessions()`) and mirrors
> `docs/plans/list-routines-spec.md` (read-pipeline reference) and
> `docs/plans/routine-recommendations-endpoint-spec.md` (a list nested under a
> routine, gated by `->can('view', 'routine')`).

## 1. Context

**Kind:** Brownfield Feature

**Stack:** PHP 8.5 · Laravel 13 · PostgreSQL 17 (runtime) / SQLite `:memory:`
(tests) · Pest 4 (`pest-plugin-laravel`) · `laravel/sanctum` 4 ·
`dedoc/scramble` · Pint · Larastan level 6. Everything runs in Docker.

**Problem statement:** A user can open, log and complete training sessions
(`POST /routines/{routine}/sessions`, `POST /sessions/{session}/sets`,
`POST /sessions/{session}/complete`), but there is no way to read them back.
The client needs the training history of one routine: which sessions were done,
which one is still in progress, how each one went. This ticket adds a read-only,
paginated collection endpoint scoped to a single routine, with an optional
`status` filter. No schema change: `training_sessions.routine_id` and
`Routine::trainingSessions()` already exist.

**In scope:**
- `GET /api/v1/routines/{routine}/sessions` — list every session of the routine
  (planned, i.e. with a `cycle_day`, and free, i.e. `cycle_day` null; both
  `in_progress` and `completed`), newest first by `started_at`.
- Pagination: `?page` (default 1) and `?per_page` (default 15, max 50) using
  Laravel's length-aware paginator (`paginate()`); the response carries the
  standard `links` and `meta` blocks next to `data`.
- Optional filter `?status=in_progress|completed` (`SessionStatus`); absent means
  all statuses.
- Item payload: the existing `TrainingSessionResource` fields, with `cycle_day`
  eager loaded (day summary only — no `exercises`, because `dayExercises` is not
  loaded).
- Works for `active` and `archived` routines alike; only the owner can read.
- `App\Http\Controllers\Session\ListTrainingSessionsController` (invokable) and
  `App\Http\Requests\Session\ListTrainingSessionsRequest` (validates the query
  string).
- One route added to the `auth:sanctum` group in `routes/api.php`
  (`routines.sessions.list`), constrained with `->whereUuid('routine')` and
  gated with `->can('view', 'routine')`.
- One assertion added to `tests/Feature/Auth/DocsSecurityTest.php`.
- Pest feature coverage of every rule below.

**Out of scope:**
- Any change to the `training_sessions` table, the `TrainingSession` model,
  `TrainingSessionResource`, `CycleDayResource`, `RoutinePolicy` or
  `TrainingSessionPolicy` — all consumed as-is.
- Set logs in the list (`sets`, `sets_count`) and a session detail endpoint
  (`GET /sessions/{session}`) — a later ticket.
- A cross-routine listing of all the user's sessions (`GET /sessions`).
- Filters other than `status` (date range, `analysis_state`, planned vs free),
  sort parameters, cursor pagination.
- A new index on `training_sessions`; revisit only if the query proves slow.
- The `gym-trainer-spa/` frontend (separate repository).

---

## 2. API Surface

### 2.1 REST

| Method | Path | Auth | Request | Response | Status codes |
|---|---|---|---|---|---|
| GET | `/api/v1/routines/{routine}/sessions` | `auth:sanctum` | Path: `routine` (public UUID). Query (all optional): `page` (integer ≥ 1), `per_page` (integer 1–50, default 15), `status` (`in_progress` \| `completed`) | `TrainingSessionResource` collection: `{ "data": [ {id, status, analysis_state, note, perceived_effort, started_at, completed_at, created_at, updated_at, cycle_day} ], "links": {…}, "meta": {current_page, per_page, total, last_page, …} }`. `data` is `[]` when nothing matches. `cycle_day` is `null` for free sessions; when present it has no `exercises` key. | 200, 401 `AUTHENTICATION_EXCEPTION`, 403 `AUTHORIZATION_EXCEPTION` (routine of another user), 404 `NOT_FOUND_EXCEPTION` (unknown or non-UUID routine), 422 `VALIDATION_EXCEPTION` (bad `page` / `per_page` / `status`, errors under `data.errors`) |

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

Not applicable — no data or schema changes. The query reads
`training_sessions` by the existing `routine_id` foreign key and orders by
`started_at`.

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
| Authenticated owner of the routine | List the sessions of that routine (active or archived), via `RoutinePolicy::view` wired as `->can('view', 'routine')` route middleware |
| Authenticated non-owner | `403` — cannot see another user's routine or sessions |
| Guest | `401` |

---

## 6. Configuration

Not applicable — no configuration changes. The pagination defaults (15 / max 50)
are fixed in the Form Request.

---

## 7. Current vs New Behavior

| Behavior | Current | New |
|---|---|---|
| Reading a routine's sessions | No endpoint; `GET routines/{routine}/sessions` returns 405 (only `POST` is registered on that path) | `GET` returns the paginated, optionally status-filtered history |
| OpenAPI spec | `/api/v1/routines/{routine}/sessions` documents only `post` | Also documents `get`, inheriting the global `security` scheme |

---

## 8. Test Cases

*File: `tests/Feature/Session/ListTrainingSessionsTest.php` (Pest). `beforeEach`
sets the `Origin` header and creates a user, like `ListRoutinesTest`.*

**TC-1:** lists the routine's sessions newest first
- **Given:** a routine with three sessions started at T-3d, T-2d and T-1d
- **When:** the owner calls `GET /api/v1/routines/{routine}/sessions`
- **Expect:** 200; `data` holds the three sessions ordered T-1d, T-2d, T-3d

**TC-2:** breaks `started_at` ties by newest id
- **Given:** two sessions of the routine with the same `started_at`
- **When:** the owner lists them
- **Expect:** the one created last comes first, and repeated calls return the same order

**TC-3:** includes planned and free sessions, in progress and completed
- **Given:** a planned completed session, a free completed session and a free in-progress session
- **When:** the owner lists without filters
- **Expect:** all three are returned; `cycle_day` is an object for the planned one and `null` for the free ones

**TC-4:** returns only the sessions of the requested routine
- **Given:** the user has routines A and B, each with sessions
- **When:** the owner lists routine A
- **Expect:** only A's sessions appear

**TC-5:** filters by `status=completed`
- **Given:** one completed and one in-progress session
- **When:** `GET …/sessions?status=completed`
- **Expect:** only the completed session; `meta.total` is 1

**TC-6:** filters by `status=in_progress`
- **Given:** one completed and one in-progress session
- **When:** `GET …/sessions?status=in_progress`
- **Expect:** only the in-progress session

**TC-7:** rejects an invalid status
- **Given:** an owned routine
- **When:** `GET …/sessions?status=archived`
- **Expect:** 422 `VALIDATION_EXCEPTION`, `assertJsonValidationErrors(['status'], 'data.errors')`

**TC-8:** paginates with the default page size
- **Given:** 20 sessions
- **When:** the owner lists without `per_page`
- **Expect:** 15 items in `data`; `meta.per_page` 15, `meta.total` 20, `meta.last_page` 2; `links.next` set

**TC-9:** honours `page` and `per_page`
- **Given:** 20 sessions
- **When:** `GET …/sessions?per_page=5&page=2`
- **Expect:** 5 items, the 6th to 10th newest; `meta.current_page` 2

**TC-10:** rejects out-of-range pagination input
- **Given:** an owned routine
- **When:** `per_page=0`, `per_page=51`, `per_page=abc`, `page=0` (one request each)
- **Expect:** 422 `VALIDATION_EXCEPTION` with the offending field under `data.errors`

**TC-11:** returns an empty page when there are no sessions
- **Given:** an owned routine without sessions
- **When:** the owner lists it
- **Expect:** 200; `data` is `[]`; `meta.total` is 0

**TC-12:** works for an archived routine
- **Given:** an archived routine with a completed session
- **When:** the owner lists it
- **Expect:** 200 with the session

**TC-13:** item structure and formats
- **Given:** a completed planned session
- **When:** the owner lists it
- **Expect:** each item has exactly `id, status, analysis_state, note, perceived_effort, started_at, completed_at, created_at, updated_at, cycle_day`; `id` is a UUID v4 (never the numeric id); dates are ISO-8601; `status` / `analysis_state` are strings; `cycle_day` has no `exercises` key; `user_id` and `routine_id` are absent

**TC-14:** does not lazy load
- **Given:** several planned sessions (strict models are on outside production)
- **When:** the owner lists them
- **Expect:** 200 and no `LazyLoadingViolationException` (`cycleDay` is eager loaded)

**TC-15:** requires authentication
- **Given:** no session cookie
- **When:** `GET …/sessions`
- **Expect:** 401, `data.code` = `AUTHENTICATION_EXCEPTION`

**TC-16:** forbids another user's routine
- **Given:** a routine owned by someone else, with sessions
- **When:** the user lists it
- **Expect:** 403, `data.code` = `AUTHORIZATION_EXCEPTION`; no session data in the body

**TC-17:** unknown or malformed routine id
- **Given:** a random UUID, and the string `not-a-uuid`
- **When:** the user lists each
- **Expect:** 404, `data.code` = `NOT_FOUND_EXCEPTION`

**TC-18:** OpenAPI document keeps the security scheme
- **Given:** `tests/Feature/Auth/DocsSecurityTest.php`
- **When:** the spec is generated
- **Expect:** `$spec['paths']['/api/v1/routines/{routine}/sessions']['get']` has no per-operation `security` override (inherits the global one)

---

## 9. Technical Decisions

| Decision area | What was decided | Why |
|---|---|---|
| Route shape | `GET routines/{routine}/sessions`, name `routines.sessions.list` | Sits beside `routines.sessions.store`; the resource is nested under the routine, as in `routines.recommendations.list` |
| Query source | `$routine->trainingSessions()` (`training_sessions.routine_id`) | Includes free sessions (`cycle_day_id` null), which a path through cycles / cycle days would miss |
| Pipeline | Form Request → invokable Controller → Resource; no Action, no Service | Trivial read: no transaction, job, event or business rule. Same reasoning as `ListRoutinesController`; a Service that only wraps one Eloquent query is the indirection `CLAUDE.md` rule 6 rejects |
| Ownership | `->can('view', 'routine')` route middleware (`RoutinePolicy::view`); `ListTrainingSessionsRequest::authorize()` returns true | Same as `routines.show` / `routines.recommendations.list`; nothing else to gate because every returned row belongs to that routine |
| Filter/pagination validation | Form Request rules: `page` `integer\|min:1`, `per_page` `integer\|between:1,50`, `status` `Rule::enum(SessionStatus::class)`, all `sometimes` | Shape validation belongs in the Form Request; invalid input becomes the standard 422 envelope |
| Pagination | `paginate($perPage)` (length-aware), default 15, max 50 | Chosen by the user. Sessions grow without bound over time. First paginated endpoint in the project, so the Resource collection's default `links` / `meta` is the contract |
| Ordering | `started_at` desc, then `id` desc | Deterministic pages when two sessions share a timestamp |
| Payload | Existing `TrainingSessionResource` with `cycleDay` eager loaded | One Resource per entity; avoids N+1 under `Model::shouldBeStrict`. `dayExercises` is not loaded, so `cycle_day` stays a light summary |
| Archived routines | Allowed | History must stay readable after a routine is archived; `RoutinePolicy::view` only checks ownership |
| Index | No new index | Filtering by `routine_id` uses the existing FK; volume per routine is small. Revisit with data |

---

## 10. Work Plan

| # | Task | Definition of Done |
|---|---|---|
| 1 | Create `ListTrainingSessionsRequest` in `app/Http/Requests/Session/` with the `page`, `per_page`, `status` rules | TC-7 and TC-10 pass |
| 2 | Create `ListTrainingSessionsController` in `app/Http/Controllers/Session/` (eager load `cycleDay`, optional status filter, order by `started_at` desc then `id` desc, `paginate`) returning `TrainingSessionResource::collection` | TC-1 to TC-6, TC-8, TC-9, TC-11 to TC-14 pass |
| 3 | Register `GET routines/{routine}/sessions` (`routines.sessions.list`, `whereUuid`, `can('view', 'routine')`) in `routes/api.php` | TC-15 to TC-17 pass; `php artisan route:list` shows the route |
| 4 | Add the `['get']` assertion for the path to `tests/Feature/Auth/DocsSecurityTest.php` | TC-18 passes |
| 5 | Write `tests/Feature/Session/ListTrainingSessionsTest.php` covering TC-1 to TC-17 | The file is green |
| 6 | Run Pint, PHPStan and the narrowest Pest runs (new file, `DocsSecurityTest`, session feature folder) | All clean |
