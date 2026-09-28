# Expose the cycle number on session responses — `cycle_day.cycle.sequence_number`

> Base contract: `docs/product-context.md`, `docs/plans/data-model.md`
> §`cycles`, §`training_sessions`, `CLAUDE.md` "The pipeline". Extends what
> `docs/plans/list-routine-sessions-spec.md`, `docs/plans/show-training-session-spec.md`,
> `docs/plans/create-training-session-spec.md` and `docs/plans/complete-session-spec.md`
> built. Notion user story: "Listar el historial de sesiones" (Order 330).

## 1. Context

**Kind:** Brownfield Feature

**Stack:** PHP 8.5 · Laravel 13 · PostgreSQL 17 (runtime) / SQLite `:memory:`
(tests) · Pest 4 (`pest-plugin-laravel`) · `laravel/sanctum` 4 ·
`dedoc/scramble` · Pint · Larastan level 6. Everything runs in Docker.

**Problem statement:** The session history already exists
(`GET /routines/{routine}/sessions` and `GET /sessions/{session}`), but no
session payload says which week (cycle) it belongs to. The embedded `cycle_day`
carries `label`, `order` and `focus_muscle_groups`, yet its `cycle` relation is
never loaded, so the SPA "Sesiones" screen cannot show a "Semana N" label
without guessing from dates. This ticket exposes the owning cycle's
`sequence_number` inside `cycle_day`. No schema change: `cycle_days.cycle_id`
and `cycles.sequence_number` already exist, and so does `CycleDay::cycle()`.

**In scope:**
- `CycleDayResource`: a new `cycle` key behind `whenLoaded('cycle')`, shaped
  `{ id, sequence_number }` (`id` is the cycle's public UUID).
- Eager loading `cycleDay.cycle` in every endpoint that returns a
  `TrainingSessionResource` with a `cycle_day`:
  - `GET /api/v1/routines/{routine}/sessions` (`ListTrainingSessionsController`)
  - `GET /api/v1/sessions/{session}` (`ShowTrainingSessionController`)
  - `POST /api/v1/routines/{routine}/sessions` (`TrainingSessionCreateAction`)
  - `POST /api/v1/sessions/{session}/complete` (`SessionCloseAction`)
  - `POST` import of a day workbook (`ImportCycleDayController`), which goes
    through the two actions above and needs no change of its own.
- Planned sessions of any cycle (first, later, archived-routine) report the
  `sequence_number` of the cycle their day belongs to.
- Free sessions keep `cycle_day: null`, so there is no cycle to report.
- Pest coverage of every rule below.

**Out of scope:**
- Any schema change, new endpoint, route, Policy or Form Request.
- `sets` / `sets_count` in the list: the detail endpoint already returns the
  sets, and the list stays a summary.
- Other cycle fields on the session (`status`, `split_rationale`,
  `generated_at`): only `id` and `sequence_number`.
- A flat `cycle_sequence_number` key or a `cycle` key at session level.
- Changing `CycleResource` (`GET` cycle payloads): its `days` never load
  `cycle`, so they are unchanged.
- Filtering or grouping sessions by cycle.
- The `gym-trainer-spa/` frontend (separate repository).

---

## 2. API Surface

### 2.1 REST

| Method | Path | Auth | Request | Response | Status codes |
|---|---|---|---|---|---|
| GET | `/api/v1/routines/{routine}/sessions` | `auth:sanctum` | Unchanged | Each item's `cycle_day` gains `cycle: {id, sequence_number}`; `cycle_day` stays `null` for free sessions. Everything else unchanged | Unchanged: 200, 401, 403, 404, 422 |
| GET | `/api/v1/sessions/{session}` | `auth:sanctum` | Unchanged | `data.cycle_day` gains `cycle: {id, sequence_number}` next to its `exercises` | Unchanged: 200, 401, 403, 404 |
| POST | `/api/v1/routines/{routine}/sessions` | `auth:sanctum` | Unchanged | `data.cycle_day` gains `cycle: {id, sequence_number}` (planned session) | Unchanged |
| POST | `/api/v1/sessions/{session}/complete` | `auth:sanctum` | Unchanged | `data.cycle_day` gains `cycle: {id, sequence_number}` (planned session) | Unchanged |

`cycle_day` shape after the change:
`{id, order, label, focus_muscle_groups, rationale, cycle: {id, sequence_number}}`,
plus `exercises` where the endpoint already loaded it. `sequence_number` is a
JSON integer (1 for the first cycle, 2 for the next, …).

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

Not applicable — no data or schema changes. The change reads `cycle_days.cycle_id`
and `cycles.sequence_number` through the existing `CycleDay::cycle()` relation.

### 4.1 Schema changes

Not applicable — no schema changes.

### 4.2 Seeds

Not applicable — no seeds.

---

## 5. Auth & Authorization

Not applicable — no auth or authorization changes. Every route keeps its current
`auth:sanctum` guard and Policy; the cycle is loaded through the already
authorised session, so it exposes nothing of another user's.

### 5.1 Authentication

**Method:** Sanctum SPA cookie session (`auth:sanctum`), unchanged.

### 5.2 Authorization

Not applicable — no authorization changes.

---

## 6. Configuration

Not applicable — no configuration changes.

---

## 7. Current vs New Behavior

| Behavior | Current | New |
|---|---|---|
| `cycle_day` in a session payload | `{id, order, label, focus_muscle_groups, rationale[, exercises]}`; the cycle is not reachable | Also carries `cycle: {id, sequence_number}` whenever `cycle` is loaded, which every session endpoint now does |
| `CycleDayResource` | Exposes the day and, if loaded, its exercises | Also exposes `cycle` when loaded; `CycleResource` → `days` does not load it, so cycle payloads are unchanged |
| Session list query | `with('cycleDay')` | `with('cycleDay.cycle')`: one extra query per page, no N+1 |
| Free session | `cycle_day: null` | Unchanged |
| OpenAPI spec | `cycle_day` schema has no `cycle` | Scramble infers the optional `cycle` object from the Resource |

---

## 8. Test Cases

*Files: `tests/Feature/Session/ListTrainingSessionsTest.php`,
`ShowTrainingSessionTest.php`, `StoreTrainingSessionTest.php` (create) and
`CompleteTrainingSessionTest.php` (Pest; each file's existing `beforeEach` and
helpers are reused).*

**TC-1:** the list reports the cycle number of each planned session
- **Given:** a routine with cycle 1 and cycle 2, and one completed session on a day of each
- **When:** the owner calls `GET /api/v1/routines/{routine}/sessions`
- **Expect:** 200; the item on the cycle-1 day has `cycle_day.cycle.sequence_number` = 1, the other = 2; each `cycle_day.cycle.id` is the cycle's UUID (never the numeric id)

**TC-2:** the list keeps free sessions without a cycle
- **Given:** a free session (`cycle_day_id` null) and a planned one
- **When:** the owner lists the sessions
- **Expect:** the free item has `cycle_day` = `null`; the planned item has `cycle_day.cycle.sequence_number`

**TC-3:** the list's `cycle_day` shape
- **Given:** a planned session
- **When:** the owner lists the sessions
- **Expect:** `cycle_day` has exactly `id, order, label, focus_muscle_groups, rationale, cycle`; `cycle` has exactly `id, sequence_number`; `sequence_number` is an integer; there is still no `cycle_day.exercises`

**TC-4:** the list does not lazy load nor add a query per row
- **Given:** a page of sessions spread over several days of two cycles (strict models are on outside production)
- **When:** the owner lists them, counting queries for 3 and for 10 sessions
- **Expect:** 200, no `LazyLoadingViolationException`, and the same number of queries for both sizes

**TC-5:** the detail reports the cycle number next to the prescription
- **Given:** a completed planned session whose day belongs to cycle 2
- **When:** the owner calls `GET /api/v1/sessions/{session}`
- **Expect:** 200; `data.cycle_day.cycle.sequence_number` = 2 and `data.cycle_day.exercises` is still populated

**TC-6:** the detail of a free session
- **Given:** a session with `cycle_day_id` null
- **When:** the owner shows it
- **Expect:** 200; `data.cycle_day` is `null`

**TC-7:** creating a planned session reports the cycle number
- **Given:** a routine whose active cycle has `sequence_number` 1
- **When:** the owner calls `POST /api/v1/routines/{routine}/sessions` with a `day`
- **Expect:** 201; `data.cycle_day.cycle.sequence_number` = 1

**TC-8:** creating a free session is unchanged
- **Given:** a routine
- **When:** the owner calls `POST /api/v1/routines/{routine}/sessions` without `day`
- **Expect:** 201; `data.cycle_day` is `null`

**TC-9:** completing a planned session reports the cycle number
- **Given:** an in-progress planned session on a day of cycle 2
- **When:** the owner calls `POST /api/v1/sessions/{session}/complete`
- **Expect:** 200; `data.cycle_day.cycle.sequence_number` = 2

**TC-10:** cycle payloads do not change
- **Given:** the existing cycle endpoints and `CycleResource` tests
- **When:** the suite runs
- **Expect:** `days[*]` in a `CycleResource` payload has no `cycle` key (the existing structure tests stay green)

**TC-11:** the other user's data stays isolated
- **Given:** a session owned by someone else on a cycle of theirs
- **When:** the user calls the list of their own routine, and the detail of that session
- **Expect:** the list holds none of it; the detail returns 403 `AUTHORIZATION_EXCEPTION` with no `cycle` data in the body

---

## 9. Technical Decisions

| Decision area | What was decided | Why |
|---|---|---|
| Payload shape | `cycle_day.cycle = {id, sequence_number}` via `CycleDayResource`, behind `whenLoaded('cycle')`. Chosen by the user | The number belongs to the day's cycle, so it sits next to the day the payload already embeds. A flat `cycle_sequence_number` or a session-level `cycle` would mix cycle data into the session Resource |
| Inline array vs new Resource | The `cycle` object is built inline in `CycleDayResource` | Two keys, one use. A `CycleSummaryResource` would be an extra class that only adds indirection (`CLAUDE.md` rule 6); reusing `CycleResource` would expose fields the client does not need |
| Endpoints | List, detail, create and complete. Chosen by the user | One session shape across endpoints; the SPA can render the label from any of them. The import endpoint reuses the create and close actions, so it inherits the change |
| Free sessions | `cycle_day` stays `null`; no cycle key | Coherent with today's contract; the SPA shows "Libre" instead of "Semana N". Chosen by the user |
| Eager loading | `cycleDay.cycle` in the list controller and both actions; `cycleDay.cycle` next to `dayExercises.exercise` in the detail | `Model::shouldBeStrict` throws on lazy loads; `whenLoaded` alone would silently drop the key if a path forgot to load it, so the tests check the value on each endpoint |
| Sets in the list | Out of scope. Chosen by the user | The acceptance criterion "includes the sets" is met by the detail endpoint; keeping the list light avoids loading every set per page |
| Pipeline | Unchanged: no new controller, request, action or service | Only a Resource key and eager loads change |
| Migration / index | None | `cycle_days.cycle_id` is a foreign key already used by the relation; the extra query is one `whereIn` per page |

---

## 10. Work Plan

| # | Task | Definition of Done |
|---|---|---|
| 1 | Add `cycle` (`whenLoaded`, `{id: uuid, sequence_number}`) to `CycleDayResource` | TC-10 passes; existing cycle structure tests stay green |
| 2 | Load `cycleDay.cycle` in `ListTrainingSessionsController` and `ShowTrainingSessionController` (next to `dayExercises.exercise`) | TC-1 to TC-6 and TC-11 pass |
| 3 | Load `cycleDay.cycle` in `TrainingSessionCreateAction` and `SessionCloseAction` | TC-7 to TC-9 pass; import feature and unit tests stay green |
| 4 | Add the tests to the four Feature files | All new cases green |
| 5 | Run Pint, PHPStan and the narrowest Pest runs (the four session files, `ImportCycleDayTest`, cycle tests, `DocsSecurityTest`) | All clean; `composer check` passes |
