# Export a training day to CSV — `GET /api/v1/routines/{routine}/cycle-days/{day}/export`

> Derived from the Notion User Story "Exportar el día de entrenamiento a CSV"
> (Feature: Export / Import · MVP · Must · Repo: API · Order 152,
> `https://app.notion.com/p/3d45cf08db2d813d8e20d4ed5f30a797`) and the planning
> conversation with the product owner (this session). Base contract:
> `docs/product-context.md` §2 (Terminología — *Día del ciclo*, *Ejercicio del
> día*, *Recomendación de ejercicio*) / §4 (steps 4 & 6) / §6 (Alcance),
> `CLAUDE.md` "The pipeline" / "Layout — folders by domain" / "Errors — one
> envelope" / "Conventions",
> `docs/plans/routine-recommendations-endpoint-spec.md` (the trivial
> authenticated-read reference this builds on — `RoutinePolicy::view` reuse, the
> Controller → Service shape with no Form Request and no Action,
> `RecommendationCatalogService`),
> `docs/plans/create-training-session-spec.md` /
> `docs/plans/store-set-logs-spec.md` (the Session-domain active-cycle guard
> shape — `TrainingSessionOpeningService`, the `DomainException` subclasses),
> `docs/plans/domain-exception-handling-spec.md` (the `DomainException` base and
> the `{ "data": { "code", "message" } }` envelope rendered by
> `ApiExceptionRenderer`).

## 1. Context

**Kind:** Brownfield Feature

**Stack:** PHP 8.5 · Laravel 13 · PostgreSQL 17 (runtime) / SQLite `:memory:`
(tests) · Pest 4 (`pest-plugin-laravel`, `RefreshDatabase` wired for the
`Feature` suite) · `laravel/sanctum` 4 (SPA cookie mode) · `spatie/laravel-data`
4 · `dedoc/scramble` 0.13 · Pint · Larastan level 6. Everything runs in Docker.

**Problem statement:** A user running the loop **without the SPA** has no way to
take their training day offline. Today they would have to read the prescription
and the live recommendations out of several JSON endpoints
(`GET /api/v1/routines/{routine}` for the cycle/day/prescription,
`GET /api/v1/routines/{routine}/recommendations` for the targets) and hand-build
a sheet to record their sets. This ticket adds a single endpoint that streams
one **CSV** for one chosen day of the routine's active cycle: a `#` comment
block with the day's metadata and rationale, then one row per prescribed set of
each exercise, with the prescription and the current recommendation filled in and
the actuals columns (`weight_kg,reps,rpe,note`) left blank for the user to fill.
The filled CSV is the input to the companion import story (Order 154, **not** in
this PR). It is the API-side stand-in for the SPA "registrar sesión" screen
(Order 230) while the SPA is not being built.

**In scope:**

- **`GET /api/v1/routines/{routine}/cycle-days/{day}/export`** — streams
  `text/csv` (HTTP `200`) as a browser download for one day of the caller's
  routine's **active** cycle. `{routine}` and `{day}` are both bound by `uuid`.
- The CSV format from the story: a leading block of `#` comment lines (day
  metadata, split rationale, one line per exercise with its rationale and, when
  present, its active recommendation), then the fixed header row, then **one data
  row per prescribed set** of every exercise of the day.
- An exercise with **no** active recommendation still appears; its
  `recommended_*` cells are empty.
- The `#` comment block is written in **Spanish**, sourced from a new
  `lang/es/export.php` translation file (not hard-coded literals), matching the
  Spanish-facing product. The CSV **column headers** and the `recommended_action`
  values stay in English (the machine contract shared with the importer).
- Reusing `RoutinePolicy::view` (already gates `routines.show` and
  `routines/{routine}/recommendations`) via `->can('view', 'routine')` route
  middleware — a routine the caller does not own is `403`.
- Reusing `RecommendationCatalogService::listCurrentForRoutine()` (PR #23) for
  the active recommendations, keyed by `exercise_id`.
- Two new `App\Exceptions\Cycle\` `DomainException` subclasses with
  `statusCode = 422` for an out-of-scope `{day}` and for a routine with no active
  cycle.
- One new invokable controller and one new Service. No new `Data` class — the
  Service returns an `array{filename: string, contents: string}` (array-shape
  PHPDoc, per `CLAUDE.md` conventions; the value never leaves the process as
  JSON).
- A one-line carve-out added to **both** `CLAUDE.md` and `AGENTS.md`: a
  file-download success body (e.g. this CSV) is the one sanctioned exception to
  "every success body is a JSON Resource / `response()->…` is banned".
- Documenting the `text/csv` response explicitly for Scramble (it infers
  `application/json` by default).

**Out of scope:**

- **The import endpoint** `POST /api/v1/routines/{routine}/cycle-days/{day}/import`
  (Order 154) — a separate story and PR. This ticket only defines the column
  contract that import will consume; it makes no writes.
- **Free / off-plan sessions.** The export is always a day of the active cycle
  (`cycle_days.uuid`); there is no export for an ad-hoc session.
- **Exporting the whole cycle** (all 5 days in one file) — one day per request.
- **Other formats** (JSON, YAML, XLSX). `text/csv`, UTF-8, comma-separated only.
- **Content negotiation.** The endpoint always returns `text/csv`; the `Accept`
  header is ignored (no `406`). Matches the rest of the API, which does no
  negotiation.
- **A UTF-8 BOM.** The file is plain UTF-8 with no BOM so the round-trip with our
  own importer is clean; Excel users opening a file with non-ASCII exercise names
  directly may see mojibake. Acceptable for v1; noted.
- **Multi-locale output.** v1 is Spanish-only (`docs/product-context.md` §6). The
  Service pins the `es` locale for the `#` block; there is no `?locale=` and no
  reliance on `APP_LOCALE` (which is `en`).
- **Pagination / caching headers / throttling.** A day has a small, bounded
  number of exercises; the read is cheap. No `ETag`, no `Cache-Control`, no
  rate-limit middleware (matches `routines/{routine}/recommendations`).
- Any change to how cycles, days, prescriptions or recommendations are written.
  Read-only endpoint.
- The `gym-trainer-spa/` frontend (separate repository).

---

## 2. API Surface

### 2.1 REST

The route joins the existing `Route::middleware('auth:sanctum')->group(...)` in
`routes/api.php`, under the global `apiPrefix: 'api/v1'`. It is a `GET`, so no
CSRF token is required.

| Method | Path | Auth | Request | Response | Status codes |
|---|---|---|---|---|---|
| GET | `/api/v1/routines/{routine}/cycle-days/{day}/export` | `auth:sanctum` + `RoutinePolicy::view` (via `->can('view', 'routine')`) | — · path: `{routine}` = `routines.uuid`, `{day}` = `cycle_days.uuid`. No body, no query string. | `text/csv` stream (see §2.1.1). `Content-Type: text/csv; charset=UTF-8`. `Content-Disposition: attachment; filename="<name>"` (see §2.1.2). | `200` · `401` unauthenticated · `403` `AUTHORIZATION_EXCEPTION` (`{routine}` owned by another user) · `404` `NOT_FOUND_EXCEPTION` (`{routine}` / `{day}` uuid unknown or segment not a uuid) · `422` `CYCLE_DAY_NOT_IN_ACTIVE_CYCLE` · `422` `ROUTINE_HAS_NO_ACTIVE_CYCLE` |

> **`Content-Type` value.** The controller sets `['Content-Type' => 'text/csv']`,
> but Symfony's `Response::prepare()` appends `; charset=<charset>` to any
> `text/*` type that has no charset, so the header actually emitted — and what
> tests must assert — is `text/csv; charset=UTF-8`.

Notes:

- **`{routine}` binding & authorization.** `->whereUuid('routine')` — a non-uuid
  segment never matches → `404`. Implicit binding resolves `{routine}` to
  `Routine` by `uuid` (`HasPublicUuid::getRouteKeyName()`); an unknown uuid →
  `ModelNotFoundException` → `404`. `->can('view', 'routine')` runs after binding:
  a routine owned by another user → `AuthorizationException` → `403`
  (`RoutinePolicy::view` returns `false` when `$routine->user_id !== $user->id`).
  This deliberately **overrides the story's literal "404 si el caller no es dueño
  de la rutina"** — the whole API already answers `403` here (`routines.show`,
  `routines/{routine}/recommendations`) and consistency wins; decided with the
  user this session.
- **`{day}` binding.** `->whereUuid('day')` — non-uuid → `404`. Implicit binding
  resolves `{day}` to `CycleDay` by `uuid`; unknown uuid → `404`. Binding is
  **not** scoped to `{routine}` (`Routine` has no `days` / `cycleDays` relation,
  so `->scopeBindings()` cannot apply here). A `{day}` uuid that is a real
  `CycleDay` but belongs to another routine, or to a non-active cycle of this
  routine, resolves successfully and is then rejected by the Service guard with
  `422 CYCLE_DAY_NOT_IN_ACTIVE_CYCLE` — this **overrides the story's `order`
  (1..N) path segment** in favour of a uuid, for consistency with
  `POST /api/v1/routines/{routine}/sessions` (which takes the day as
  `cycle_days.uuid`); decided with the user this session.
- **`{day}` guard order.** The Service checks *"the routine has an active cycle"*
  **before** *"this day is in it"*, mirroring `TrainingSessionOpeningService`.
  So a routine whose current cycle is `generating` / `failed` / `completed` /
  `incomplete` (or an `archived` routine, whose last cycle is
  `completed` / `incomplete`) answers `422 ROUTINE_HAS_NO_ACTIVE_CYCLE`, never
  the more confusing "day not in cycle".
- **What "the active cycle" means.** `$routine->cycle` — the `HasOne` of the
  max-`sequence_number` cycle — **and** its `status` must be
  `CycleStatus::Active`. Identical to the check in
  `TrainingSessionOpeningService::guard()`. In v1 a routine has exactly one cycle
  and it is born `active`, so the guard only bites for an `archived` routine or
  during the brief Order-150 N+1 generation window.
- **Errors stay JSON.** Although the success body is `text/csv`, every error is
  rendered by `App\Exceptions\ApiExceptionRenderer` (wired for `api/*` in
  `bootstrap/app.php`) as `{ "data": { "code": "...", "message": "..." } }`. The
  two new `DomainException` subclasses flow through the renderer's existing
  `DomainException` arm (`$e->errorCode()` / `$e->statusCode()`), no wiring, no
  `data.errors` key (that is `VALIDATION_EXCEPTION`-only).
- **No Form Request, no Action.** A `GET` with no input and no
  transaction / job / event — the same trivial-read pipeline as
  `ListRoutineRecommendationsController` (Controller → Service directly). The
  Service exists (not inline in the controller) because the guards, the CSV
  assembly and the filename slugging are business logic worth naming and
  unit-testing on their own.

**CSV body** (§2.1.1)

Line endings `\n`; encoding UTF-8, no BOM. The body has three parts, in order.

**The `#` prelude is positional, not a global comment syntax.** `#` lines appear
**only** as the contiguous block before the header row. A consumer (a test here,
the Order-154 importer later) skips leading `#` lines until it reaches the header
row, then stops treating `#` specially — a data cell is allowed to begin with `#`
(e.g. an exercise the AI named `#2 Press`), and such a row must not be dropped.

**1. Comment block** — one or more lines each starting with `# `. Free text, not
CSV records (never passed through `fputcsv`). Newlines/tabs inside any
interpolated rationale or explanation are collapsed to single spaces
(`preg_replace('/\s+/', ' ', trim($v))`) so every `#` line stays one line.
Strings come from `lang/es/export.php` (§6): the line template is fetched with
`trans('export.…', [], 'es')` (empty replacement array, so the raw `:token`
template comes back), then filled with
`strtr($template, [':routine' => (string) …, …])` — simultaneous replacement, so
a value that itself contains `:token` is never re-expanded; values are
string-cast.

```
# rutina: <routine.name> | ciclo <cycle.sequence_number> | dia <cycleDay.order> (<cycleDay.label>) | foco: <focus_muscle_groups joined by ", ">
# racional del split: <cycle.split_rationale>
# <exercise.name> — prescripcion: <prescription>. Racional: <dayExercise.rationale>. Recomendacion: <action> — <explanation>
```

- Line 1 (`rutina: …`) is always emitted. `foco:` is followed by the empty
  string when `focus_muscle_groups` is `[]`.
- Line 2 (`racional del split: …`) is emitted **only when**
  `cycle.split_rationale` is non-blank; otherwise the line is omitted entirely.
- One exercise line per `day_exercise`, ordered by `day_exercise.order` asc.
  - `<prescription>` is assembled in PHP: `"<sets>x<reps>"`, then `" @ <w>kg"`
    only if `target_weight_kg` is not null, then `" RPE<r>"` only if `target_rpe`
    is not null, then `", descanso <rest_seconds>s"` (the word `descanso` from
    `lang/es/export.php`). `<reps>` is `rep_min` when `rep_min === rep_max`,
    otherwise `"<rep_min>-<rep_max>"`. `<w>` / `<r>` are formatted with the
    number helper below.
  - The `Recomendacion: <action> — <explanation>` segment is appended **only
    when** an active `ExerciseRecommendation` exists for that exercise.
    `<action>` is the raw backed enum value (`advance_weight`, …) — the same
    token as the `recommended_action` column and the importer's contract, not a
    translated label. `<explanation>` is the recommendation's `explanation`,
    whitespace-collapsed.

**2. Header row** — exactly, verbatim:

```
exercise,set_number,prescribed_weight_kg,prescribed_reps,prescribed_rpe,rest_seconds,recommended_weight_kg,recommended_action,weight_kg,reps,rpe,note
```

**3. Data rows** — walk the `day_exercises` in `order` asc; for each, emit `sets`
rows. Every row for one `day_exercise` carries that prescription's values (and,
if the exercise has an active recommendation, that recommendation's values).
Written with `fputcsv($h, $row, escape: '')` (RFC-4180 quoting, no legacy
backslash escaping).

`set_number` is assigned **continuously per distinct `exercise_id` across the
whole day**, not reset per `day_exercise`. In the normal case (one prescription
per exercise) this is identical to `1..sets`. If a day prescribes the same
exercise in two `day_exercises` (allowed by the schema — see §9 "Duplicate
exercise in a day"), the second block continues the numbering (`1..4` then
`5..7`), so `exercise` + `set_number` stays a unique row key for the import
round-trip.

| Column | Value |
|---|---|
| `exercise` | `exercise.name` (quoted by `fputcsv` if it contains `,` `"` or newline) |
| `set_number` | running 1-based index of the set within its `exercise_id` for the day (see above) |
| `prescribed_weight_kg` | `num(target_weight_kg)`; empty string if null |
| `prescribed_reps` | `rep_min` if `rep_min === rep_max`, else `"<rep_min>-<rep_max>"` |
| `prescribed_rpe` | `num(target_rpe)`; empty string if null |
| `rest_seconds` | `rest_seconds` (integer) |
| `recommended_weight_kg` | `num(target_weight_kg)` of the active recommendation; empty string if no active recommendation |
| `recommended_action` | active recommendation's `action->value`; empty string if none |
| `weight_kg` | empty string (user fills) |
| `reps` | empty string (user fills) |
| `rpe` | empty string (user fills) |
| `note` | empty string (user fills) |

**`num($v)` — the decimal helper.** `target_weight_kg` / `target_rpe` are
`decimal:2` / `decimal:1` casts, i.e. strings like `"100.00"` / `"8.0"`.
`num($v)` = `rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.')` —
deterministic (independent of PHP's `precision` ini), trailing zeros trimmed:
`"100.00" → "100"`, `"102.50" → "102.5"`, `"8.0" → "8"`.

A day with **zero** `day_exercises` yields a valid `200`: the comment block
(line 1, and line 2 if a split rationale exists) followed by the header row and
no data rows.

Worked example (routine "Volumen invierno", cycle 3, day 3 "Piernas", one
exercise "Sentadilla" prescribed `4x5 @ 100kg RPE8` rest 180s, with an
`advance_weight` recommendation at 102.5kg):

```
# rutina: Volumen invierno | ciclo 3 | dia 3 (Piernas) | foco: quads, glutes, hamstrings
# racional del split: Prioriza cuádriceps tras la semana de empuje.
# Sentadilla — prescripcion: 4x5 @ 100kg RPE8, descanso 180s. Racional: Base de fuerza del día. Recomendacion: advance_weight — Subiste las 4x5 a RPE 7, hay margen.
exercise,set_number,prescribed_weight_kg,prescribed_reps,prescribed_rpe,rest_seconds,recommended_weight_kg,recommended_action,weight_kg,reps,rpe,note
Sentadilla,1,100,5,8,180,102.5,advance_weight,,,,
Sentadilla,2,100,5,8,180,102.5,advance_weight,,,,
Sentadilla,3,100,5,8,180,102.5,advance_weight,,,,
Sentadilla,4,100,5,8,180,102.5,advance_weight,,,,
```

**Filename** (§2.1.2)

`Content-Disposition: attachment; filename="<routine-slug>-<ciclo>-<seq>-<dia>-<order>-<label-slug>.csv"`

- `<routine-slug>` = `Str::slug(Str::ascii($routine->name))`; `<label-slug>` =
  `Str::slug(Str::ascii($cycleDay->label))`. If a slug comes out empty
  (the value is all non-latin), fall back to `rutina` for the routine and
  `sin-nombre` for the label — distinct tokens so the filename never renders an
  adjacent `dia-<order>-dia`.
- `<ciclo>` and `<dia>` are the literal words from `lang/es/export.php`
  (`export.filename.cycle` = `ciclo`, `export.filename.day` = `dia`).
- `<seq>` = `cycle.sequence_number`, `<order>` = `cycleDay.order` (integers).
- The result is ASCII-only by construction, so `response()->streamDownload()`
  emits a plain `filename="…"` with no `filename*=UTF-8''…` companion (Symfony
  HttpFoundation requires ASCII download filenames).
- Example: `volumen-invierno-ciclo-3-dia-3-piernas.csv`.

### 2.2 CLI

Not applicable — no CLI commands.

### 2.3 Events

Not applicable — no events and no jobs. The endpoint reads and streams; nothing
is persisted or dispatched.

---

## 3. UI

### 3.1 Pages

Not applicable — no pages affected (API-only repo). The download replaces the
not-yet-built SPA "registrar sesión" screen (Order 230) as a stopgap.

### 3.2 Components

Not applicable — no components affected.

---

## 4. Database

### 4.1 Schema changes

Not applicable — no schema changes. The endpoint reads existing `routines`,
`cycles`, `cycle_days`, `day_exercises`, `exercises` and `exercise_recommendations`
rows only. No new columns, tables, indexes or model relations.

**No database isolation needed** (`CLAUDE.md` → "Workflows — database
isolation"): this branch adds no migration and no seed change, so it does not
touch the shared `gym_trainer` schema. The Pest suite runs on SQLite `:memory:`.

**Doc update:** none. `docs/plans/data-model.md` is unchanged.

### 4.2 Seeds

Not applicable — no seeds. Tests build the graph with
`Routine` / `Cycle` / `CycleDay` / `DayExercise` / `Exercise` /
`ExerciseRecommendation` factories and their states.

---

## 5. Auth & Authorization

### 5.1 Authentication

**Method:** Session (cookie) via Laravel Sanctum in SPA / stateful mode —
identical to every other `/api/v1` data route. `auth:sanctum` on the group
authenticates from the session cookie; unauthenticated → `AuthenticationException`
→ `401` JSON envelope. `GET`, so no CSRF token.

### 5.2 Authorization

| Role | Permissions |
|---|---|
| Routine owner | Can export any day of their routine's **active** cycle. |
| Any other authenticated user | `403` `AUTHORIZATION_EXCEPTION` — `RoutinePolicy::view` returns `false` when `$routine->user_id !== $user->id`. |
| Unauthenticated | `401` `AUTHENTICATION_EXCEPTION`. |

No new Policy method — the route reuses `->can('view', 'routine')`, exactly as
`routines.show` and `routines/{routine}/recommendations` already do. Ownership of
`{day}` is **not** a Policy concern: a day the caller could not otherwise see is
rejected as a business-rule violation (`422 CYCLE_DAY_NOT_IN_ACTIVE_CYCLE`) by the
Service, because for the caller's *own* routine the only reachable days are those
of its active cycle anyway, and cross-user `{day}` uuids are unguessable v4
uuids.

---

## 6. Configuration

No environment variables and no `config/*` changes.

**New translation file** — `lang/es/export.php` (the `lang/` directory does not
exist yet; this creates it). Short-key array consumed by
`CycleDayCsvExportService` via `trans('export.…', [], locale: 'es')` — the locale
is **pinned to `es`**, not read from `APP_LOCALE` (which is `en`), because v1
output is Spanish-only. Keys:

```php
<?php

return [
    'filename' => [
        'cycle' => 'ciclo',
        'day' => 'dia',
    ],
    'comment' => [
        'day' => 'rutina: :routine | ciclo :cycle | dia :day (:label) | foco: :focus',
        'split_rationale' => 'racional del split: :rationale',
        'exercise' => ':exercise — prescripcion: :prescription. Racional: :rationale.',
        'exercise_recommendation' => 'Recomendacion: :action — :explanation',
    ],
    'prescription' => [
        'rest' => 'descanso',
    ],
];
```

The `prescription.rest` value is the bare translatable word; the Service
composes the fragment as `", {$rest} {$seconds}s"` → `", descanso 180s"`. The
`comment.exercise_recommendation` string is appended to the exercise line with a
single separating space (added by the Service, not baked into the value).

---

## 7. Current vs New Behavior

| Behavior | Current | New |
|---|---|---|
| Getting a training day offline | Not possible — the user reads several JSON endpoints and hand-builds a sheet. | `GET /api/v1/routines/{routine}/cycle-days/{day}/export` streams one ready-to-fill `text/csv`. |
| Success response body convention | Every endpoint's `200`/`201` body is a JSON Resource under `data`; `response()->…` for a success body is banned (`CLAUDE.md` golden rules 2 & 3). | Unchanged for every existing endpoint. A **file-download** success body (this CSV, via `response()->streamDownload()` with an explicit `Content-Type`) is added as the one sanctioned exception — documented by a new line in `CLAUDE.md` **and** `AGENTS.md`. Error bodies on the route are still the JSON envelope. |
| "Day not in the active cycle" error | `App\Exceptions\Session\CycleDayNotInActiveCycleException` → `409` (session-open flow). | A **separate** `App\Exceptions\Cycle\CycleDayNotInActiveCycleException` → `422` for this endpoint. Same rule, different domain and status; the Session one is untouched (per the codebase's "folders by domain" exception convention). |
| Scramble output for the route | n/a (route does not exist). | The operation documents a `200` with content type `text/csv` (Scramble would otherwise infer `application/json` from the absence of a JSON return type). |

---

## 8. Test Cases

*All executable with `vendor/bin/pest`. Feature tests in
`tests/Feature/Cycle/ExportCycleDayTest.php`, unit tests in
`tests/Unit/Cycle/CycleDayCsvExportServiceTest.php`. Factories + states only; no
real AI, no network.*

**Feature — happy path & format**

**TC-1:** exports a valid day as a CSV attachment, scoped to that day only
- **Given:** a user owns a routine with an `active` cycle (`sequence_number` 3)
  with **two** days — day `order` 1 (`label` "Empuje") with a `day_exercise` for
  "Press banca" that has its own `active` `ExerciseRecommendation`
  (`explanation` "solo dia 1"), and day `order` 3 (`label` "Piernas",
  `focus_muscle_groups` `["quads","glutes"]`) with two `day_exercises`
  (`sets` 4 and 3).
- **When:** `GET /api/v1/routines/{routine}/cycle-days/{day order 3 uuid}/export`
  as that user.
- **Expect:** `200`; header `Content-Type` is `text/csv; charset=UTF-8`; header
  `Content-Disposition` contains
  `attachment; filename="<routine-slug>-ciclo-3-dia-3-piernas.csv"`; body
  contains the `# rutina: … | ciclo 3 | dia 3 (Piernas) | foco: quads, glutes`
  line, the exact header row, `4 + 3 = 7` data rows, `set_number` running `1..4`
  then `1..3`, and every `weight_kg,reps,rpe,note` cell empty; the body contains
  **no** occurrence of `Press banca` or `solo dia 1` (day 1 does not leak).

**TC-2:** an exercise with an active recommendation fills the `recommended_*` cells and the `#` line
- **Given:** one `day_exercise` for exercise "Sentadilla"; an `active`
  `ExerciseRecommendation` for `(user, routine, Sentadilla)` with
  `target_weight_kg` 102.5, `action` `advance_weight`, `explanation` "Subiste…".
- **When:** export that day.
- **Expect:** every "Sentadilla" data row has `recommended_weight_kg` `102.5` and
  `recommended_action` `advance_weight`; the `# Sentadilla — …` line ends with
  `Recomendacion: advance_weight — Subiste…`.

**TC-3:** an exercise with no recommendation still appears with empty `recommended_*`
- **Given:** a `day_exercise` for an exercise that has no `ExerciseRecommendation`
  row.
- **When:** export the day.
- **Expect:** the exercise's data rows are present; `recommended_weight_kg` and
  `recommended_action` are empty; its `#` line has no `Recomendacion:` segment.

**TC-4:** an `applied` (non-active) recommendation is ignored
- **Given:** the only `ExerciseRecommendation` for the exercise has
  `status = applied`.
- **When:** export the day.
- **Expect:** treated as "no recommendation" — empty `recommended_*` cells, no
  `Recomendacion:` segment (`RecommendationCatalogService` filters to `active`).

**TC-5:** `prescribed_reps` is a range only when `rep_min !== rep_max`
- **Given:** exercise A with `rep_min = rep_max = 5`; exercise B with
  `rep_min = 6`, `rep_max = 12`.
- **When:** export the day.
- **Expect:** A's rows show `prescribed_reps` `5`; B's rows show `6-12`; the `#`
  lines show `…x5…` and `…x6-12…`.

**TC-6:** null `target_weight_kg` / `target_rpe` produce empty cells and a trimmed `#` fragment
- **Given:** a `day_exercise` with `target_weight_kg = null` and
  `target_rpe = null`, `rest_seconds = 90`.
- **When:** export the day.
- **Expect:** `prescribed_weight_kg` and `prescribed_rpe` cells empty;
  `rest_seconds` = `90`; the `#` line reads `prescripcion: <sets>x<reps>, descanso 90s`
  (no `@ …kg`, no `RPE…`).

**TC-7:** a null `split_rationale` omits the whole line
- **Given:** the active cycle has `split_rationale = null`.
- **When:** export a day.
- **Expect:** `200`; the body has **no** line starting with
  `# racional del split:`; line 1 and the exercise lines are intact.

**TC-8:** multi-line rationale / explanation are collapsed to one line
- **Given:** a `day_exercise.rationale` and a recommendation `explanation` that
  each contain `"\n"`.
- **When:** export the day.
- **Expect:** the corresponding `#` line contains no embedded newline; the
  whitespace runs are single spaces; the header row is still the first
  non-`#` line and parses to 12 fields.

**TC-9:** rows are ordered by `day_exercise.order` then `set_number`
- **Given:** two `day_exercises` for two **different** exercises — exercise "B" at
  `order` 2, exercise "A" at `order` 1.
- **When:** export the day.
- **Expect:** all "A" rows precede all "B" rows; within each, `set_number` is
  ascending and contiguous from 1.

**TC-10:** a field needing CSV quoting keeps the row at 12 fields
- **Given:** an exercise named `Press, inclinado "30°"`.
- **When:** export the day and parse the data rows with `str_getcsv`.
- **Expect:** each parsed row has exactly 12 elements and element 0 is
  `Press, inclinado "30°"`.

**TC-11:** the `#` block text is Spanish and locale-independent
- **Given:** `app()->setLocale('en')` for the request; a valid day.
- **When:** export the day.
- **Expect:** the body still contains `rutina:`, `foco:`, `prescripcion:`,
  `descanso`, and (with a split rationale) `racional del split:` — the Service
  pins `es`.

**TC-12:** a day with zero exercises still exports
- **Given:** a `cycle_day` of the active cycle with no `day_exercises`.
- **When:** export it.
- **Expect:** `200`; body is the `# rutina: …` line (+ split-rationale line if
  any) then the header row, and zero data rows.

**TC-13:** the same exercise prescribed twice in one day gets continuous `set_number`
- **Given:** the active cycle's day has two `day_exercises` for the **same**
  catalogue exercise "Sentadilla" — `order` 1 (`sets` 4, `target_weight_kg` 100)
  and `order` 2 (`sets` 3, `target_weight_kg` 80).
- **When:** export the day and parse the data rows with `str_getcsv`.
- **Expect:** 7 rows, every `exercise` cell is `Sentadilla`, `set_number` runs
  `1..7` with no repeat; rows 1–4 have `prescribed_weight_kg` `100`, rows 5–7
  have `80`.

**Feature — authorization & errors**

**TC-14:** a `{day}` of another routine is rejected
- **Given:** caller owns routine R1 (active cycle); day D belongs to routine R2
  (any owner).
- **When:** `GET /api/v1/routines/{R1}/cycle-days/{D}/export`.
- **Expect:** `422`, `data.code` = `CYCLE_DAY_NOT_IN_ACTIVE_CYCLE`.

**TC-15:** a `{day}` of a non-active cycle of the same routine is rejected
- **Given:** caller's routine has a `completed` cycle `seq` 1 and an `active`
  cycle `seq` 2; day D belongs to `seq` 1.
- **When:** export D.
- **Expect:** `422`, `data.code` = `CYCLE_DAY_NOT_IN_ACTIVE_CYCLE`.

**TC-16:** a routine whose current cycle is not active is rejected
- **Given:** caller's routine has a single cycle with `status = generating`
  (via `Cycle::factory()->generating()`), containing a day D.
- **When:** export D.
- **Expect:** `422`, `data.code` = `ROUTINE_HAS_NO_ACTIVE_CYCLE`.

**TC-17:** an archived routine is rejected with `ROUTINE_HAS_NO_ACTIVE_CYCLE`
- **Given:** caller's routine is `archived`; its last cycle is `completed` with a
  day D.
- **When:** export D.
- **Expect:** `422`, `data.code` = `ROUTINE_HAS_NO_ACTIVE_CYCLE` (the "no active
  cycle" guard is checked first).

**TC-18:** another user's routine → 403
- **Given:** routine owned by a different user, with an active cycle and a day D.
- **When:** the caller exports `/routines/{thatRoutine}/cycle-days/{D}/export`.
- **Expect:** `403`, `data.code` = `AUTHORIZATION_EXCEPTION`.

**TC-19:** unknown `{routine}` uuid → 404
- **When:** export with a random uuid for `{routine}`.
- **Expect:** `404`, `data.code` = `NOT_FOUND_EXCEPTION`.

**TC-20:** unknown `{day}` uuid → 404
- **Given:** caller's routine with an active cycle.
- **When:** export with a random uuid for `{day}`.
- **Expect:** `404`, `data.code` = `NOT_FOUND_EXCEPTION`.

**TC-21:** a non-uuid path segment → 404
- **When:** `GET /api/v1/routines/not-a-uuid/cycle-days/also-bad/export`.
- **Expect:** `404`, `data.code` = `NOT_FOUND_EXCEPTION`.

**TC-22:** unauthenticated → 401
- **Given:** no authenticated user.
- **When:** export any day.
- **Expect:** `401`, `data.code` = `AUTHENTICATION_EXCEPTION`.

**TC-23:** rendering does not trip strict-mode lazy loading
- **Given:** a day with several exercises and a mix of recommendations.
- **When:** export the day.
- **Expect:** `200`, no `Illuminate\Database\LazyLoadingViolationException`
  (`Model::shouldBeStrict` is on outside production) — the Service eager-loads
  `dayExercises.exercise` and gets the recommendations (with `exercise`
  eager-loaded) from `RecommendationCatalogService`, keyed by `exercise_id`.

**Feature — docs**

**TC-24:** Scramble documents the CSV response
- **When:** the OpenAPI document is generated (`app(Dedoc\Scramble\Generator::class)()`).
- **Expect:** `paths['/api/v1/routines/{routine}/cycle-days/{day}/export']['get']
  ['responses']['200']['content']` has a `text/csv` key and no
  `application/json` key.

**TC-25:** the route inherits the root security scheme (extend `DocsSecurityTest`)
- **When:** the OpenAPI document is generated.
- **Expect:** `paths['/api/v1/routines/{routine}/cycle-days/{day}/export']['get']`
  has no `security` key (it inherits the document-root scheme), asserted
  alongside the existing routes in `tests/Feature/Auth/DocsSecurityTest.php`.

**Unit — `CycleDayCsvExportService`**

*Each calls `app(CycleDayCsvExportService::class)->handle($routine, $day)` after
building the graph with factories; `contents` is split on `\n` and the leading
`#` lines dropped before parsing data rows with `str_getcsv`.*

**TC-26:** builds the exact header row
- **Given:** a routine with an `active` cycle whose day has one `day_exercise`.
- **When:** `handle()`, then take the first line of `contents` that does not
  start with `#`.
- **Expect:** that line equals, byte for byte, the header string in §2.1.1
  (`exercise,set_number,prescribed_weight_kg,…,note`).

**TC-27:** emits one row per prescribed set
- **Given:** the day has one `day_exercise` for exercise "X" with `sets = 3`.
- **When:** `handle()`, parse data rows.
- **Expect:** exactly 3 rows, all `exercise` = `X`, `set_number` = `1`, `2`, `3`.

**TC-28:** `prescribed_reps` — single value vs. range
- **Given:** two `day_exercises` — A with `rep_min = rep_max = 5`, B with
  `rep_min = 8`, `rep_max = 12`.
- **When:** `handle()`, parse data rows.
- **Expect:** every A row's `prescribed_reps` cell is `5`; every B row's is
  `8-12`; the two `#` exercise lines contain `x5` and `x8-12` respectively.

**TC-29:** null `target_weight_kg` / `target_rpe`
- **Given:** one `day_exercise` with `target_weight_kg = null`,
  `target_rpe = null`, `rest_seconds = 90`.
- **When:** `handle()`.
- **Expect:** every data row's `prescribed_weight_kg` and `prescribed_rpe` cells
  are the empty string; the exercise's `#` line contains `descanso 90s` and
  contains neither `@` nor `RPE`.

**TC-30:** recommendation cells reflect only `active` rows
- **Given:** three `day_exercises` — A's exercise has an `active`
  `ExerciseRecommendation` (`target_weight_kg` 80, `action` `hold`); B's has an
  `applied` one; C's has none.
- **When:** `handle()`, parse data rows.
- **Expect:** A's rows have `recommended_weight_kg` `80` and `recommended_action`
  `hold`; B's and C's rows have both cells empty.

**TC-31:** `split_rationale` present vs. null
- **Given:** run once with the active cycle's `split_rationale = "Empuje\nprimero"`,
  once with `split_rationale = null`.
- **When:** `handle()` each time.
- **Expect:** first `contents` contains the line
  `# racional del split: Empuje primero`; second `contents` has no line starting
  `# racional del split:`.

**TC-32:** newlines in rationale / explanation are collapsed
- **Given:** a `day_exercise` with `rationale = "line one\nline two"` whose
  exercise has an `active` recommendation with `explanation = "a\n\nb"`.
- **When:** `handle()`.
- **Expect:** the exercise's `#` line contains `Racional: line one line two` and
  `— a b`, and the line has no embedded `\n`.

**TC-33:** filename slugging and fallbacks
- **Given:** routine `name = "Volumen Invierno ñ"`, `cycle.sequence_number = 2`;
  day `label = "重い日"` (no latin characters), `order = 4`.
- **When:** `handle()['filename']`.
- **Expect:** equals `volumen-invierno-n-ciclo-2-dia-4-sin-nombre.csv` — the
  routine name is `Str::ascii`-folded then slugged, the empty label slug falls
  back to `sin-nombre`.

**TC-34:** guard — routine has no active cycle
- **Given:** a routine whose highest-`sequence_number` cycle has
  `status = generating` (`Cycle::factory()->generating()`), with a day D under it.
- **When:** `handle($routine, $D)`.
- **Expect:** throws `App\Exceptions\Cycle\RoutineHasNoActiveCycleException`;
  the caught instance's `statusCode() === 422` and
  `errorCode() === 'ROUTINE_HAS_NO_ACTIVE_CYCLE'`.

**TC-35:** guard — day not in the active cycle
- **Given:** a routine with an `active` cycle `seq` 2 and a `completed` cycle
  `seq` 1; day D belongs to `seq` 1.
- **When:** `handle($routine, $D)`.
- **Expect:** throws `App\Exceptions\Cycle\CycleDayNotInActiveCycleException`;
  `statusCode() === 422`, `errorCode() === 'CYCLE_DAY_NOT_IN_ACTIVE_CYCLE'`.

**TC-36:** return shape
- **Given:** a routine with an `active` cycle whose day has one `day_exercise`.
- **When:** `$result = handle($routine, $day)`.
- **Expect:** `$result` has exactly the keys `filename` and `contents` (both
  strings); `$result['filename']` matches
  `/^[a-z0-9-]+-ciclo-\d+-dia-\d+-[a-z0-9-]+\.csv$/`; `$result['contents']`
  starts with `# rutina:` and contains the header row.

---

## 9. Technical Decisions

| Decision area | What was decided | Why |
|---|---|---|
| Success body vs. JSON-Resource rule | The CSV is streamed with `response()->streamDownload(fn () => print $csv['contents'], $csv['filename'], ['Content-Type' => 'text/csv'])` from the controller — no JSON Resource. A one-line carve-out is added to **both** `CLAUDE.md` and `AGENTS.md`. | Golden rules 2 & 3 forbid a non-Resource success body; a file download genuinely cannot be one. Decided with the user this session: document the exception rather than contort the payload into `{ data: { csv: "…" } }`. Errors on the route stay the JSON envelope. |
| `Content-Type` header value | Controller passes `text/csv`; the header actually emitted (and asserted by TC-1) is `text/csv; charset=UTF-8`. | Symfony `Response::prepare()` appends `; charset=<charset>` to any `text/*` type lacking one. Pinning the real value keeps the TC-1 assertion correct. |
| Duplicate exercise in a day | `set_number` is assigned continuously per `exercise_id` across the day's `day_exercises`, not reset per prescription. A day is *expected* to prescribe each exercise once. | The schema allows a repeat (`day_exercises` has `unique(cycle_day_id, order)` only) and `CycleDraftService::persistDay()` does not dedupe; downstream (`ProgressionSummaryService`) already collapses by `exercise_id`. Continuous numbering keeps `exercise` + `set_number` a unique row key for the Order-154 import round-trip. |
| Decimal formatting (`num()`) | `num($v)` = `rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.')` for `prescribed_weight_kg`, `prescribed_rpe`, `recommended_weight_kg`. | `(string)(float)` depends on PHP's `precision` ini and can emit artefacts; the source columns are `decimal:2` / `decimal:1` strings, so a string-level trim is deterministic. |
| Service return type | `array{filename: string, contents: string}` (array-shape PHPDoc), not a new `Data` class. | Two internal strings that never leave the process as JSON. A dedicated class would be indirection with no payoff (`CLAUDE.md` rule 6); array-shape PHPDoc is an explicit `CLAUDE.md` convention. |
| Service calling a Service | `CycleDayCsvExportService` constructor-injects and calls `RecommendationCatalogService`. | `CLAUDE.md`'s Service rules forbid a Service calling an **Action**, dispatching a job, or firing an event — not composing another read-only Service. Reusing it beats duplicating its cycle-scoped query (rule 6). |
| `{day}` identifier | `cycle_days.uuid` with route-model binding (not the story's `order` 1..N). | Consistency with `POST /api/v1/routines/{routine}/sessions`, which already takes the day as `cycle_days.uuid`. The story flagged this for spec-review; resolved with the user. |
| Foreign routine status | `403` via the existing `->can('view', 'routine')` (not the story's `404`). | `routines.show` and `routines/{routine}/recommendations` already answer `403` here; a bespoke `404` for one endpoint would be an inconsistency. Resolved with the user. |
| Out-of-scope `{day}` / no active cycle status | `422` via two new `App\Exceptions\Cycle\` `DomainException` subclasses with `protected int $statusCode = 422`. Codes `CYCLE_DAY_NOT_IN_ACTIVE_CYCLE` and `ROUTINE_HAS_NO_ACTIVE_CYCLE`. | The story specifies `422`. "Is this day part of the active cycle?" is a cross-entity business check → a Service guard throwing a `DomainException`, per `CLAUDE.md` (validation is shape-only). `422` is chosen over the `DomainException` default `409` because the endpoint treats a stale/foreign `{day}` uuid as an unprocessable *parameter*, not a state conflict. |
| Not reusing `App\Exceptions\Session\CycleDayNotInActiveCycleException` | A **new**, separate class under `App\Exceptions\Cycle\` with the same name. | That class is `409` and documents the session-open flow; the codebase keeps exceptions in per-domain folders and already has two `RoutineNotActiveException` classes (Cycle + Session) for exactly this reason. Reusing or moving it would couple two flows and change the session endpoint's contract. |
| Layering | Controller → `CycleDayCsvExportService` directly. No Form Request, no Action. | Matches `ListRoutineRecommendationsController` — a trivial authenticated read with no transaction / job / event. A Service (not inline code) holds the guards, CSV assembly and filename slugging so they are named and unit-testable. |
| Recommendations source | Reuse `RecommendationCatalogService::listCurrentForRoutine($routine)`, `->keyBy('exercise_id')` for per-exercise lookup. | It already returns exactly the `active` recommendations for exercises in the routine's current cycle, `exercise` eager-loaded. The export only reads the ones matching this day's exercises; extra entries are simply not looked up. No new query logic. |
| "Active cycle" resolution | `$routine->cycle` (max `sequence_number` `HasOne`) **and** `status === CycleStatus::Active`; the active-cycle check runs before the day-membership check. | Byte-for-byte the same rule and order as `TrainingSessionOpeningService::guard()`, so the two endpoints agree on what "the active cycle" is during the Order-150 N+1 window. |
| `#` block language & mechanism | Spanish, from a new `lang/es/export.php`, pulled with `trans('export.…', [], 'es')` and interpolated with `strtr` on the template. The locale is pinned to `es`. | The user asked for Spanish (matching the story's example and the Spanish-facing product) **and** for it to go through Laravel localization rather than hard-coded literals. `strtr` (simultaneous replace) avoids re-expanding a value that contains `:token`. Pinning `es` because `APP_LOCALE` is `en` and v1 is single-locale. |
| `recommended_action` / `#` action token | Raw backed enum value (`advance_weight`), not translated. | It is the machine contract the importer (Order 154) matches on; the `#` line mirrors the column deliberately. |
| CSV column headers | English, fixed, verbatim from the story. | Shared machine contract with the importer; not user prose. |
| CSV writer | `fputcsv($h, $row, escape: '')` for the header + data rows; raw `fwrite` / `echo` for `#` lines. | `escape: ''` gives RFC-4180 quoting without PHP's legacy backslash-escaping quirk (and sidesteps the PHP 8.4 default-`$escape` deprecation). `#` lines are not CSV records and must not be quoted. |
| Encoding / EOL / BOM | UTF-8, `\n`, no BOM. | The importer expects plain UTF-8 comma CSV; a BOM would corrupt the first header cell for naive parsers. Excel-direct-open of non-ASCII names is a known, accepted v1 tradeoff. |
| Scramble | Document the `200` `text/csv` response via whatever hook the installed `dedoc/scramble` 0.13.x exposes — a custom operation/response transformer registered in a service provider, or a response attribute/PHPDoc tag if that version supports one. Verified by TC-24, not by the mechanism. | Scramble infers `application/json` from the lack of a JSON return type; the spec pins the observable outcome and lets implementation pick the hook (confirm the API with `search-docs` / `application-info` before coding). |
| Filename slug fallback | Empty `Str::slug` → `rutina` for the routine, `sin-nombre` for the label. | A value with no latin characters would otherwise produce `--ciclo-3-dia-3-.csv`; distinct tokens also avoid an adjacent `dia-<order>-dia`. |
| No throttle / cache headers | None added. | Cheap bounded read; matches `routines/{routine}/recommendations`. |

---

## 10. Work Plan

| # | Task | Definition of Done |
|---|---|---|
| 1 | Add one line to **`CLAUDE.md`** and the identical line to **`AGENTS.md`** (English), as a sub-point under golden rule 3 / the "JSON Resource" section: a file-download success body (e.g. a CSV export) is returned with `response()->streamDownload(...)` / `response()->download(...)` and an explicit `Content-Type`, is the **only** sanctioned use of `response()->…` for a success body, and does not use a JSON Resource; errors on such routes are still the JSON envelope. | Both files carry the same sentence; `git diff` touches only these two lines. |
| 2 | Create `lang/es/export.php` with the keys in §6 (`filename.cycle`, `filename.day`, `comment.day`, `comment.split_rationale`, `comment.exercise`, `comment.exercise_recommendation`, `prescription.rest`). | `trans('export.comment.day', [], 'es')` returns the Spanish template string. |
| 3 | Create `app/Exceptions/Cycle/RoutineHasNoActiveCycleException.php` — `final`, extends `App\Exceptions\DomainException`, `protected string $errorCode = 'ROUTINE_HAS_NO_ACTIVE_CYCLE'`, `protected int $statusCode = Response::HTTP_UNPROCESSABLE_ENTITY`, constructor sets a default message ("This routine has no active cycle to export."). | Class exists; `(new …)->statusCode() === 422` and `->errorCode() === 'ROUTINE_HAS_NO_ACTIVE_CYCLE'` (a one-off `expect()` here, then locked by TC-34 in Task 10). |
| 4 | Create `app/Exceptions/Cycle/CycleDayNotInActiveCycleException.php` — `final`, extends `DomainException`, `$errorCode = 'CYCLE_DAY_NOT_IN_ACTIVE_CYCLE'`, `$statusCode = Response::HTTP_UNPROCESSABLE_ENTITY`, default message ("That day does not belong to this routine's active cycle."). PHPDoc cross-references `App\Exceptions\Session\CycleDayNotInActiveCycleException` (409, session-open flow) and states why this is a distinct Cycle-domain class. | Class exists; `->statusCode() === 422`, `->errorCode() === 'CYCLE_DAY_NOT_IN_ACTIVE_CYCLE'` (locked by TC-35 in Task 10). |
| 5 | Create `app/Services/Cycle/CycleDayCsvExportService.php` — `final`, constructor-injects `RecommendationCatalogService`. `handle(Routine $routine, CycleDay $day): array` returning `array{filename: string, contents: string}` (array-shape PHPDoc): (a) `$cycle = $routine->cycle`; `throw_if($cycle === null \|\| $cycle->status !== CycleStatus::Active, new RoutineHasNoActiveCycleException)`; (b) `throw_unless($day->cycle_id === $cycle->id, new CycleDayNotInActiveCycleException)`; (c) `$day->loadMissing('dayExercises.exercise')`; (d) `$recs = $this->catalog->listCurrentForRoutine($routine)->keyBy('exercise_id')`; (e) build the CSV string per §2.1.1 — comment block via `strtr` on `trans('export.*', [], 'es')` with whitespace-collapsed rationales/explanations and the `#`-prelude rule; header + data rows via `fputcsv($h, $row, escape: '')` on an `fopen('php://temp', 'r+')` handle then `rewind` + `stream_get_contents`; `set_number` counted per `exercise_id` across the day; decimals via the `num()` helper (§2.1.1); (f) build the filename per §2.1.2 (`Str::slug(Str::ascii(...))`, `rutina` / `sin-nombre` fallbacks, `ciclo` / `dia` words from `lang/es/export.php`); (g) `return ['filename' => …, 'contents' => …]`. A one-line comment explains the pinned `es` locale. | Method + `num()` helper implemented; `vendor/bin/phpstan analyse` clean for the file. Behaviour is locked by TC-26–TC-36 in Task 10. |
| 6 | Create `app/Http/Controllers/Cycle/ExportCycleDayController.php` — invokable, `__invoke(Routine $routine, CycleDay $day, CycleDayCsvExportService $export): StreamedResponse` → `$csv = $export->handle($routine, $day); return response()->streamDownload(fn () => print($csv['contents']), $csv['filename'], ['Content-Type' => 'text/csv']);`. | ~3 lines; `arch('cycle controllers are invokable')` still passes. HTTP behaviour is locked by the feature suite in Task 11. |
| 7 | Register the route in `routes/api.php` inside the `auth:sanctum` group, immediately after `routines.recommendations.list`: `Route::get('routines/{routine}/cycle-days/{day}/export', ExportCycleDayController::class)->whereUuid('routine')->whereUuid('day')->can('view', 'routine')->name('routines.cycle-days.export');` with a short comment in the file's existing style. Add the `use App\Http\Controllers\Cycle\ExportCycleDayController;` import in alphabetical order. | `php artisan route:list` lists `routines.cycle-days.export` with method `GET` and middleware `auth:sanctum`, `can:view,routine`. |
| 8 | Document the `text/csv` `200` response for Scramble — register a minimal operation transformer (or use a response attribute if the installed `dedoc/scramble` 0.13.x exposes one) so the export operation advertises `text/csv` and drops the inferred `application/json`. Keep it scoped to this one route. | TC-24 passes: the generated spec's `…/export` `get.responses.200.content` has `text/csv` and no `application/json`. |
| 9 | Extend `tests/Feature/Auth/DocsSecurityTest.php`: add `->and($spec['paths']['/api/v1/routines/{routine}/cycle-days/{day}/export']['get'])->not->toHaveKey('security')` to the existing chain. | TC-25; `vendor/bin/pest --filter=DocsSecurity` passes. |
| 10 | Write `tests/Unit/Cycle/CycleDayCsvExportServiceTest.php` — every TC from TC-26 through TC-36 as its own `it()`, calling the Service directly with factory-built graphs. | `vendor/bin/pest tests/Unit/Cycle/CycleDayCsvExportServiceTest.php` green; each of TC-26–TC-36 present. |
| 11 | Write `tests/Feature/Cycle/ExportCycleDayTest.php` — every TC from TC-1 through TC-25 as its own `it()`, following the structure of `tests/Feature/Cycle/GenerateCycleTest.php` and `tests/Feature/Recommendation/ListRoutineRecommendationsTest.php`. Parse CSV bodies with `str_getcsv` where field counts matter; read the streamed body with `$response->streamedContent()`. | `vendor/bin/pest tests/Feature/Cycle/ExportCycleDayTest.php` green; each of TC-1–TC-25 present. |
| 12 | Run the project checks on touched paths: `vendor/bin/pint app/Exceptions/Cycle app/Services/Cycle app/Http/Controllers/Cycle routes/api.php lang tests/Unit/Cycle tests/Feature/Cycle tests/Feature/Auth/DocsSecurityTest.php --format agent`, then `vendor/bin/phpstan analyse`, then `vendor/bin/pest --filter=Cycle` plus `--filter=DocsSecurity`. | Pint clean, PHPStan level 6 clean, all listed tests green. No migration → no `ide-helper:models`, no DB clone. |

---
