# Export a training day to a spreadsheet — `GET /api/v1/routines/{routine}/cycle-days/{day}/export`

> Derived from the Notion User Story "Exportar el día de entrenamiento a CSV"
> (Feature: Export / Import · MVP · Must · Repo: API · Order 152,
> `https://app.notion.com/p/3d45cf08db2d813d8e20d4ed5f30a797`) and the planning
> conversation with the product owner (this session). Base contract:
> `docs/product-context.md` §2 / §4 (steps 4 & 6) / §6,
> `CLAUDE.md` "The pipeline" / "Layout — folders by domain" / "Errors — one
> envelope" / "Conventions",
> `docs/plans/routine-recommendations-endpoint-spec.md` (the trivial
> authenticated-read reference — `RoutinePolicy::view` reuse,
> `RecommendationCatalogService`),
> `docs/plans/create-training-session-spec.md` /
> `docs/plans/store-set-logs-spec.md` (the active-cycle guard shape,
> `TrainingSessionOpeningService`, `DomainException` subclasses),
> `docs/plans/domain-exception-handling-spec.md` (`DomainException` + the
> `{ "data": { "code", "message" } }` envelope rendered by
> `ApiExceptionRenderer`).
>
> **Format decision (this session):** the story says "CSV". A CSV cannot hold
> both a raw `#` comment prelude *and* comma-safe data rows in one file
> (a spreadsheet writer quotes any cell with a comma). The download is therefore
> an **`.xlsx` workbook**, built with **`maatwebsite/excel`** — the `#` lines
> become plain single-column cells and the data columns get native types.

## 1. Context

**Kind:** Brownfield Feature

**Stack:** PHP 8.5 · Laravel 13 · PostgreSQL 17 (runtime) / SQLite `:memory:`
(tests) · Pest 4 · `laravel/sanctum` 4 · `spatie/laravel-data` 4 ·
`dedoc/scramble` 0.13 · **`maatwebsite/excel` 4** (new) · Pint · Larastan
level 6. Everything runs in Docker.

**Problem statement:** A user running the loop **without the SPA** has no way to
take their training day offline. Today they would read the prescription and the
live recommendations out of several JSON endpoints and hand-build a sheet. This
adds one endpoint that streams a ready-to-fill **`.xlsx`** for one chosen day of
the routine's **active** cycle: a `#` metadata prelude (day, split rationale,
one line per exercise with its rationale and current recommendation), then a
header row, then one row per prescribed set — the prescription and current
recommendation filled in, the actuals columns (`weight_kg,reps,rpe,note`) left
blank. The filled sheet is the input to the companion import story (Order 154,
**not** in this PR). It is the API-side stand-in for the SPA "registrar sesión"
screen (Order 230).

**In scope:**

- **`GET /api/v1/routines/{routine}/cycle-days/{day}/export`** — streams an
  `.xlsx` download (HTTP `200`) for one day of the caller's routine's **active**
  cycle. `{routine}` and `{day}` are both bound by `uuid`.
- Adding **`maatwebsite/excel`** (Laravel Excel) as a dependency — approved by
  the product owner this session.
- **`App\Exports\Cycle\CycleDayExport`** — a `Maatwebsite\Excel\Concerns\FromArray`
  export that, in its constructor, runs the business guards, resolves the current
  recommendations (via `RecommendationCatalogService`) and assembles every row.
  It also exposes the download `filename`. **No separate Service** — the export
  class *is* the unit of this feature (decided with the user this session).
- The workbook layout (§2.1.1): a `#` prelude, the header row, one row per
  prescribed set. `set_number` runs continuously per `exercise_id` across the
  day. An exercise with no active recommendation still appears (its
  `recommended_*` cells blank).
- The `#` text is **Spanish**, from a new `lang/es/export.php` (locale pinned to
  `es`). Column headers and `recommended_action` values stay English (the
  machine contract shared with the importer).
- Reusing `RoutinePolicy::view` via `->can('view', 'routine')` (a routine the
  caller does not own → `403`).
- Two new `App\Exceptions\Cycle\` `DomainException` subclasses (`422`).
- Broadening the `CLAUDE.md` / `AGENTS.md` golden-rule-3 carve-out to name
  `Excel::download(...)` as a sanctioned file-download success body.
- Documenting the spreadsheet response for Scramble (it infers
  `application/json` by default).

**Out of scope:**

- **The import endpoint** `POST .../import` (Order 154) — a separate story/PR.
  This ticket only defines the column contract; it makes no writes. (Whether
  import consumes `.xlsx` or CSV is Order 154's call.)
- **Free / off-plan sessions** — the export is always a day of the active cycle.
- **Exporting the whole cycle** — one day per request.
- **CSV / other formats.** The download is `.xlsx` only; the `Accept` header is
  ignored (no content negotiation, no `406`).
- **Styling the workbook** — no column widths, freeze panes, bold header, cell
  formats. A plain single sheet. A later story can prettify.
- **Multi-locale output.** v1 is Spanish-only; the locale is pinned, no
  `?locale=`.
- **Pagination / caching headers / throttling** — a day is small and bounded.
- Any change to how cycles, days, prescriptions or recommendations are written.
- The `gym-trainer-spa/` frontend.

---

## 2. API Surface

### 2.1 REST

The route joins the existing `Route::middleware('auth:sanctum')->group(...)` in
`routes/api.php`, under `apiPrefix: 'api/v1'`. It is a `GET`, so no CSRF token.

| Method | Path | Auth | Request | Response | Status codes |
|---|---|---|---|---|---|
| GET | `/api/v1/routines/{routine}/cycle-days/{day}/export` | `auth:sanctum` + `RoutinePolicy::view` (via `->can('view', 'routine')`) | — · path: `{routine}` = `routines.uuid`, `{day}` = `cycle_days.uuid`. No body, no query string. | `.xlsx` binary download (see §2.1.1). `Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`. `Content-Disposition: attachment; filename=<name>.xlsx` (see §2.1.2). | `200` · `401` unauthenticated · `403` `AUTHORIZATION_EXCEPTION` (`{routine}` owned by another user) · `404` `NOT_FOUND_EXCEPTION` (`{routine}` / `{day}` uuid unknown or segment not a uuid) · `422` `CYCLE_DAY_NOT_IN_ACTIVE_CYCLE` · `422` `ROUTINE_HAS_NO_ACTIVE_CYCLE` |

Notes:

- **`{routine}` binding & authorization.** `->whereUuid('routine')` — a non-uuid
  segment never matches → `404`. Implicit binding resolves `{routine}` by
  `uuid`; unknown uuid → `404`. `->can('view', 'routine')` runs after binding: a
  foreign routine → `403` (`RoutinePolicy::view` returns `false`). This
  **overrides the story's literal "404"** — every routine-scoped endpoint in the
  API already answers `403` here.
- **`{day}` binding.** `->whereUuid('day')` — non-uuid → `404`; unknown uuid →
  `404`. Not scoped to `{routine}` (`Routine` has no `days` relation). A `{day}`
  uuid that is a real `CycleDay` of another routine or a stale cycle resolves,
  then `CycleDayExport`'s guard rejects it with `422
  CYCLE_DAY_NOT_IN_ACTIVE_CYCLE`. This **overrides the story's `order` (1..N)**
  path segment, for consistency with `POST .../sessions`.
- **Guard order.** `CycleDayExport::__construct()` checks *"the routine has an
  active cycle"* before *"this day is in it"*, mirroring
  `TrainingSessionOpeningService`. A routine whose highest-`sequence_number`
  cycle is `generating` / `failed` / `completed` / `incomplete` (or an
  `archived` routine) → `422 ROUTINE_HAS_NO_ACTIVE_CYCLE`.
- **"The active cycle"** = `$routine->cycle()->first()` (highest
  `sequence_number`) whose `status` is `CycleStatus::Active` — the exact check
  from `TrainingSessionOpeningService::guard()`.
- **Errors stay JSON.** The guards throw from the export's constructor —
  *before* `Excel::download()` is called — so `ApiExceptionRenderer` renders the
  usual `{ "data": { "code", "message" } }` envelope with no partial file. The
  two new `DomainException` subclasses flow through the renderer's existing
  `DomainException` arm (`errorCode()` / `statusCode()`), no wiring.
- **`Content-Type`.** `Excel::download()` serves the file via
  `response()->download()`, which sets the xlsx mime from the file extension:
  `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`.
- **Pipeline.** No Form Request, no Action, **no Service**. The invokable
  controller (`ExportCycleDayController`) is ~3 lines: build `new
  CycleDayExport($routine, $day, $recommendations)` (the `RecommendationCatalogService`
  is injected into the controller and passed through) and hand it to
  `Excel::download($sheet, $sheet->filename)`. The guards, recommendation lookup,
  `#`-line assembly, row building and filename slugging all live in the export
  class, which is unit-tested directly.

**Workbook shape** (§2.1.1)

One sheet. `CycleDayExport` implements **`WithHeadings`** (multi-row: every
element of `headings()` is itself an array, so Laravel Excel writes each as a
row) and **`FromArray`**. Laravel Excel writes the headings block first, then the
array rows — so the sheet is:

1. the `#` comment prelude (headings), one single-cell row per `#` line;
2. the column header row (the last headings row);
3. one `FromArray` row per prescribed set.

**1. Comment prelude** — one `headings()` row per `#` line, a single string cell
in column A (`['# rutina: …']`). Not a CSV comment syntax — just text rows a
reader (a test here, the Order-154 importer later) skips until it reaches the
header row. Interpolated rationales / explanations have their whitespace
collapsed to single spaces (`preg_replace('/\s+/', ' ', trim($v))`) so each
stays one line; a trailing `.` is trimmed from `<dayExercise.rationale>` (the
template already supplies the sentence-ending period). Strings come from
`lang/es/export.php`: the template is fetched with `trans('export.…', [], 'es')`
(raw `:token` template back), then filled with `strtr` (simultaneous — a value
containing `:token` is never re-expanded; string-cast).

```
# rutina: <routine.name> | ciclo <cycle.sequence_number> | dia <cycleDay.order> (<cycleDay.label>) | foco: <focus_muscle_groups joined by ", ">
# racional del split: <cycle.split_rationale>
# <exercise.name> — prescripcion: <prescription>. Racional: <dayExercise.rationale>. Recomendacion: <action> — <explanation>
```

- Line 1 (`rutina: …`) always present; `foco:` is followed by the empty string
  when `focus_muscle_groups` is `[]`.
- Line 2 (`racional del split: …`) only when `cycle.split_rationale` is
  non-blank.
- One exercise line per `day_exercise`, ordered by `day_exercise.order` asc.
  - `<prescription>` assembled in PHP: `"<sets>x<reps>"`, then `" @ <w>kg"` only
    if `target_weight_kg` is not null, then `" RPE<r>"` only if `target_rpe` is
    not null, then `", descanso <rest_seconds>s"` (the word `descanso` from
    `lang/es/export.php`). `<reps>` is `rep_min` when `rep_min === rep_max`,
    else `"<rep_min>-<rep_max>"`. `<w>` / `<r>` use the `numberText()` helper —
    `rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.')` — so
    `"100.00" → "100"`, `"102.50" → "102.5"` (deterministic, independent of
    PHP's `precision`).
  - The `Recomendacion: <action> — <explanation>` segment is appended **only
    when** an active `ExerciseRecommendation` exists. `<action>` is the raw
    backed enum value (`advance_weight`, …) — the same token as the
    `recommended_action` column, not a translated label.

**2. Header row** — the final `headings()` row, exactly:

```
exercise · set_number · prescribed_weight_kg · prescribed_reps · prescribed_rpe · rest_seconds · recommended_weight_kg · recommended_action · weight_kg · reps · rpe · note
```

**3. Data rows** — `array()`. Walk `day_exercises` by `order` asc; for each, emit
`sets` rows. Native cell types (the point of `.xlsx` over CSV); `null` cells stay
blank (`Maatwebsite\Excel\Concerns\WithStrictNullComparison`), so the user types
into truly empty cells.

| Column | Value / type |
|---|---|
| `exercise` | `exercise.name` (string; a comma in the name is now harmless) |
| `set_number` | int, running 1-based index **per `exercise_id` across the whole day** — so the same exercise prescribed in two `day_exercises` continues the count (`1..4` then `5..7`), keeping `exercise` + `set_number` a unique row key for the import round-trip |
| `prescribed_weight_kg` | `(float) target_weight_kg`, or `null` |
| `prescribed_reps` | `rep_min` (int) when `rep_min === rep_max`, else `"<rep_min>-<rep_max>"` (string) |
| `prescribed_rpe` | `(float) target_rpe`, or `null` |
| `rest_seconds` | int |
| `recommended_weight_kg` | `(float)` of the active recommendation's `target_weight_kg`, or `null` |
| `recommended_action` | active recommendation's `action->value` (string), or `null` |
| `weight_kg` / `reps` / `rpe` / `note` | `null` (user fills) |

A day with **zero** `day_exercises` still exports: the prelude (line 1, and
line 2 if a split rationale exists) and the header row, no data rows.

**Filename** (§2.1.2)

`Content-Disposition: attachment; filename=<routine-slug>-<ciclo>-<seq>-<dia>-<order>-<label-slug>.xlsx`

- `<routine-slug>` = `Str::slug(Str::ascii($routine->name))`; `<label-slug>` =
  `Str::slug(Str::ascii($cycleDay->label))`. Empty slug → `rutina` for the
  routine, `sin-nombre` for the label (distinct tokens, so no adjacent
  `dia-<order>-dia`).
- `<ciclo>` / `<dia>` are the literal words from `lang/es/export.php`
  (`export.filename.cycle` / `export.filename.day`). `<seq>` =
  `cycle.sequence_number`, `<order>` = `cycleDay.order`.
- ASCII-only by construction → Symfony emits a plain `filename=…` (it requires
  ASCII download filenames).
- Example: `volumen-invierno-ciclo-3-dia-3-piernas.xlsx`.

### 2.2 CLI

Not applicable — no CLI commands.

### 2.3 Events

Not applicable — no events and no jobs.

---

## 3. UI

### 3.1 Pages

Not applicable — no pages affected (API-only repo).

### 3.2 Components

Not applicable — no components affected.

---

## 4. Database

### 4.1 Schema changes

Not applicable — no schema changes. Reads existing `routines`, `cycles`,
`cycle_days`, `day_exercises`, `exercises`, `exercise_recommendations`. No new
columns, tables, indexes or model relations.

**No database isolation needed** — no migration, no seed change; the shared
`gym_trainer` schema is untouched. The Pest suite runs on SQLite `:memory:`.

**Doc update:** none. `docs/plans/data-model.md` is unchanged.

### 4.2 Seeds

Not applicable — tests build the graph with factories.

---

## 5. Auth & Authorization

### 5.1 Authentication

**Method:** Session cookie via Laravel Sanctum (SPA / stateful mode), same as
every `/api/v1` route. `auth:sanctum` on the group; unauthenticated → `401` JSON
envelope. `GET`, so no CSRF token.

### 5.2 Authorization

| Role | Permissions |
|---|---|
| Routine owner | Can export any day of their routine's **active** cycle. |
| Any other authenticated user | `403` `AUTHORIZATION_EXCEPTION` (`RoutinePolicy::view` → `false`). |
| Unauthenticated | `401` `AUTHENTICATION_EXCEPTION`. |

No new Policy method — the route reuses `->can('view', 'routine')`, exactly as
`routines.show` and `routines/{routine}/recommendations`. Ownership of `{day}`
is a business-rule check (`422`) in the export class, not a Policy concern:
cross-user `{day}` uuids are unguessable v4 uuids.

---

## 6. Configuration

No environment variables and no `config/*` changes.

**New dependency** — `maatwebsite/excel` `^4.0` (`composer require
maatwebsite/excel`). Auto-discovered (facade `Maatwebsite\Excel\Facades\Excel`,
concerns under `Maatwebsite\Excel\Concerns\*`). No `config/excel.php` publish —
the `.xlsx` writer defaults are used as-is. Pulls in `phpoffice/phpspreadsheet`.

**New translation file** — `lang/es/export.php` (creates the `lang/` directory).
Short-key array consumed by `CycleDayExport` via `trans('export.…', [], 'es')` —
locale **pinned to `es`** (not `APP_LOCALE`, which is `en`) because v1 output is
Spanish-only. Keys:

```php
return [
    'filename' => ['cycle' => 'ciclo', 'day' => 'dia'],
    'comment' => [
        'day' => 'rutina: :routine | ciclo :cycle | dia :day (:label) | foco: :focus',
        'split_rationale' => 'racional del split: :rationale',
        'exercise' => ':exercise — prescripcion: :prescription. Racional: :rationale.',
        'exercise_recommendation' => 'Recomendacion: :action — :explanation',
    ],
    'prescription' => ['rest' => 'descanso'],
];
```

The Service composes the rest fragment as `", {$rest} {$seconds}s"` →
`", descanso 180s"`; `exercise_recommendation` is appended to the exercise line
with a single separating space added in code.

---

## 7. Current vs New Behavior

| Behavior | Current | New |
|---|---|---|
| Getting a training day offline | Not possible — the user reads several JSON endpoints and hand-builds a sheet. | `GET .../cycle-days/{day}/export` streams one ready-to-fill `.xlsx`. |
| Success response body convention | Every `200`/`201` body is a JSON Resource under `data`; `response()->…` for a success body is banned (`CLAUDE.md` golden rules 2 & 3). | Unchanged for every existing endpoint. A **file-download** success body — `Excel::download(...)` / `response()->download(...)` / `response()->streamDownload(...)` — is the one sanctioned exception, documented by a broadened line in `CLAUDE.md` **and** `AGENTS.md`. Error bodies on the route are still the JSON envelope. |
| "Day not in the active cycle" error | `App\Exceptions\Session\CycleDayNotInActiveCycleException` → `409` (session-open flow). | A **separate** `App\Exceptions\Cycle\CycleDayNotInActiveCycleException` → `422` for this endpoint. Same rule, different domain and status; the Session one is untouched (per the "folders by domain" convention). |
| Scramble output for the route | n/a (route does not exist). | The operation documents a `200` with content type `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` (Scramble would otherwise infer `application/json`), via an operation transformer registered in `AppServiceProvider`. |

---

## 8. Test Cases

*All executable with `vendor/bin/pest`. Feature tests in
`tests/Feature/Cycle/ExportCycleDayTest.php`, unit tests in
`tests/Unit/Cycle/CycleDayExportTest.php`. Factories + states only; no real AI,
no network. `Excel::fake()` (from `Maatwebsite\Excel\Facades\Excel`) is used per
feature test that inspects the downloaded export; the shared `tests/Helpers.php`
gains `exportCommentLines(array $headingRows)` — the `#` lines from a
`CycleDayExport::headings()` result. Data rows are read straight from
`CycleDayExport::array()`.*

**Feature — happy path**

**TC-1:** downloads the day as an `.xlsx` scoped to that day
- **Given:** the caller owns a routine with an `active` cycle (`sequence_number`
  3) with two days — day `order` 1 ("Empuje") with a "Press banca"
  `day_exercise` that has its own `active` recommendation (`explanation`
  "solo dia 1"), and day `order` 3 ("Piernas", `focus_muscle_groups`
  `["quads","glutes"]`) with two `day_exercises` (`sets` 4 and 3).
- **When:** `Excel::fake()`, then
  `GET /api/v1/routines/{routine}/cycle-days/{day order 3 uuid}/export`.
- **Expect:** `200`; `Excel::assertDownloaded('volumen-invierno-ciclo-3-dia-3-piernas.xlsx', …)`
  where the callback asserts: comment row 0 is
  `# rutina: Volumen invierno | ciclo 3 | dia 3 (Piernas) | foco: quads, glutes`;
  the exact header row is present; 7 data rows; `set_number` column is
  `[1,2,3,4,1,2,3]`; every `weight_kg/reps/rpe/note` cell is `null`; and neither
  `Press banca` nor `solo dia 1` appears anywhere (day 1 does not leak).

**TC-2:** an active recommendation fills `recommended_*` and the `#` line
- **Given:** one `day_exercise` for "Sentadilla"; an `active`
  `ExerciseRecommendation` (`target_weight_kg` 102.5, `action` `advance_weight`,
  `explanation` "Subiste las 4x5 a RPE 7").
- **When:** `Excel::fake()`, export the day.
- **Expect:** every data row has `recommended_weight_kg` `102.5` (float) and
  `recommended_action` `advance_weight`; a comment line contains
  `Recomendacion: advance_weight — Subiste las 4x5 a RPE 7`.

**Feature — authorization & errors**

**TC-3:** `{day}` of another routine → `422` `CYCLE_DAY_NOT_IN_ACTIVE_CYCLE`.

**TC-4:** `{day}` of a non-active cycle of the same routine (a `completed` `seq`
1 alongside an `active` `seq` 2) → `422` `CYCLE_DAY_NOT_IN_ACTIVE_CYCLE`.

**TC-5:** the routine's current cycle is `generating` → `422`
`ROUTINE_HAS_NO_ACTIVE_CYCLE`.

**TC-6:** an `archived` routine (last cycle `completed`) → `422`
`ROUTINE_HAS_NO_ACTIVE_CYCLE`.

**TC-7:** a routine owned by another user → `403` `AUTHORIZATION_EXCEPTION`.

**TC-8:** unknown `{routine}` uuid → `404` `NOT_FOUND_EXCEPTION`.

**TC-9:** unknown `{day}` uuid → `404` `NOT_FOUND_EXCEPTION`.

**TC-10:** a non-uuid path segment → `404` `NOT_FOUND_EXCEPTION`.

**TC-11:** unauthenticated → `401` `AUTHENTICATION_EXCEPTION`.

**TC-12:** rendering does not trip strict-mode lazy loading
- **Given:** a day with three exercises and one `active` recommendation.
- **When:** `Excel::fake()`, export the day.
- **Expect:** `200`, no `LazyLoadingViolationException`; `Excel::assertDownloaded`
  with the expected filename.

**TC-13:** streams a genuine, non-empty `.xlsx`
- **Given:** a routine with an active cycle and a day with one `day_exercise`
  (`sets` 2). **No** `Excel::fake()`.
- **When:** export the day.
- **Expect:** `200`; `assertDownload(<expected filename>)`; `Content-Type` is the
  spreadsheet mime; the streamed body's first two bytes are `PK` (a valid zip /
  `.xlsx` archive).

*(The route inheriting the document-root security scheme is asserted in
`tests/Feature/Auth/DocsSecurityTest.php`, extended with the new path.)*

A companion feature test asserts the Scramble output: the generated OpenAPI
operation for `…/export` `get.responses.200.content` has the spreadsheet mime
key and no `application/json` key.

**Unit — `CycleDayExport`**

*Each builds the graph with factories, then `new CycleDayExport($routine, $day,
app(RecommendationCatalogService::class))` and inspects `->headings()` /
`->array()` / `->filename`.*

**TC-14:** the last row of `->headings()` is the exact 12-name column header.

**TC-15:** `sets` data rows per `day_exercise`, `set_number` `1,2,3` (ints).

**TC-16:** `prescribed_reps` is the int `5` when `rep_min === rep_max === 5`, the
string `"8-12"` when `rep_min` 8 / `rep_max` 12; the two `#` prescription lines
show `x5` and `x8-12`.

**TC-17:** `target_weight_kg` / `target_rpe` `null` → those cells are `null`; the
`#` fragment shows `descanso 90s` and contains neither `@` nor `RPE`.

**TC-18:** an `active` recommendation fills `recommended_weight_kg` (`80.0`) and
`recommended_action` (`hold`); an `applied` one or none leaves both `null`.

**TC-19:** `split_rationale = "Empuje\nprimero"` → a comment line
`# racional del split: Empuje primero`; `split_rationale = null` → no such line.

**TC-20:** newlines in `rationale` / `explanation` are collapsed — the
`# Sentadilla …` line contains `Racional: line one line two` and `— a b`, no
embedded `\n`.

**TC-21:** `filename` — routine `"Volumen Invierno ñ"`, cycle `seq` 2, day
`label "!!!"` (no slug chars), `order` 4 → `volumen-invierno-n-ciclo-2-dia-4-sin-nombre.xlsx`.

**TC-22:** constructing with a routine whose current cycle is not `active` throws
`RoutineHasNoActiveCycleException` (`statusCode() === 422`,
`errorCode() === 'ROUTINE_HAS_NO_ACTIVE_CYCLE'`).

**TC-23:** constructing with a `{day}` not in the active cycle throws
`CycleDayNotInActiveCycleException` (`statusCode() === 422`,
`errorCode() === 'CYCLE_DAY_NOT_IN_ACTIVE_CYCLE'`).

**TC-24:** the same exercise in two `day_exercises` (`sets` 4 then 3,
`target_weight_kg` 100 then 80) → 7 rows, all `exercise` `Sentadilla`,
`set_number` `[1..7]`, `prescribed_weight_kg` `[100,100,100,100,80,80,80]`
(floats).

**TC-25:** rows are ordered by `day_exercise.order` (a day with exercise "B" at
`order` 2 and "A" at `order` 1 → data rows `["A","B"]`).

**TC-26:** the workbook is scoped to the requested day — a cycle with two days,
export day 2, and day 1's exercise name appears in neither the comment lines nor
the data rows.

**TC-27:** a day with zero `day_exercises` → `->array()` is empty; the first
`->headings()` comment line starts `# rutina:`.

---

## 9. Technical Decisions

| Decision area | What was decided | Why |
|---|---|---|
| Output format | `.xlsx`, not CSV. | The story says CSV, but a CSV cannot carry a raw `#` comment prelude *and* comma-safe data rows through one writer (a spreadsheet writer quotes any cell containing a comma, and the `#` lines contain commas). Decided with the user this session: switch to `.xlsx`, where a cell can hold a comma freely. |
| Library | `maatwebsite/excel` `^4.0` (Laravel Excel). | The user asked for it explicitly (dependency approved). Gives declarative concerns (`FromArray`, `WithHeadings`, `WithStrictNullComparison`) and `Excel::download()` / `Excel::fake()` testing helpers instead of hand-rolling a spreadsheet. `^3.1` is not an option — it caps at Laravel 11; this app is on Laravel 13. |
| Top matter via `WithHeadings` | `headings()` returns the `#` prelude lines (each `['# …']`) **plus** the column header row as its last element — a multi-row headings block (every element an array). `array()` returns only the data rows. | Requested by the user ("usa los métodos WithHeadings"). Laravel Excel writes the headings block before the `FromArray` rows, which is exactly the prelude → header → data order we want, and keeps `array()` purely the prescribed-set rows. |
| No Service | `App\Exports\Cycle\CycleDayExport` owns the guards, recommendation lookup, headings + row assembly and filename; the controller injects `RecommendationCatalogService` and passes it in. | Decided with the user this session ("ya no necesitaríamos el service"). A Laravel Excel export idiomatically gathers its own data; a separate Service on top would be indirection (`CLAUDE.md` rule 6). The guards run in the constructor, so an invalid day fails before `Excel::download()` — the JSON error path is unchanged. |
| Guards throw from a constructor | `CycleDayExport::__construct()` runs `throw_if` / `throw_unless`. | The export is a single-use, per-request value object; building it *is* "handle this request". Throwing here keeps the error before any bytes are written and keeps the controller at ~3 lines. |
| `{day}` identifier | `cycle_days.uuid` + route-model binding (not `order` 1..N). | Consistency with `POST .../sessions`. Resolved with the user. |
| Foreign routine | `403` via the existing `->can('view', 'routine')` (not `404`). | Consistency with `routines.show` / `routines/{routine}/recommendations`. Resolved with the user. |
| Invalid `{day}` / no active cycle | `422` via two new `App\Exceptions\Cycle\` `DomainException` subclasses (`protected int $statusCode = 422`). | The story specifies `422`. It is a cross-entity business check → a guard throwing a `DomainException`. `422` over the default `409` because a stale/foreign `{day}` uuid is an unprocessable parameter, not a state conflict. |
| Distinct from `App\Exceptions\Session\CycleDayNotInActiveCycleException` | A new class, same name, under `App\Exceptions\Cycle\`. | That one is `409` for the session-open flow; the codebase keeps exceptions per-domain and already has two `RoutineNotActiveException` classes. |
| Recommendations source | Reuse `RecommendationCatalogService::listCurrentForRoutine($routine)`, `->keyBy('exercise_id')`. | Already returns the `active` recommendations for the current cycle's exercises with `exercise` eager-loaded; the export only reads the ones matching this day. A Service calling / an export using another read-only Service is within `CLAUDE.md`'s Service rules (which forbid Action calls, jobs, events). |
| "Active cycle" resolution | `$routine->cycle()->first()` (highest `sequence_number`, explicit query) **and** `status === CycleStatus::Active`; active-cycle check first. | Byte-for-byte the rule and order from `TrainingSessionOpeningService::guard()`. The explicit `->cycle()->first()` (not the dynamic `->cycle` property) avoids any `preventLazyLoading` question. |
| `#` block language & mechanism | Spanish, from `lang/es/export.php`, `trans(..., [], 'es')` + `strtr`. | User asked for Spanish **and** Laravel localization (not literals). `strtr` avoids re-expanding a value that contains `:token`. Locale pinned because v1 is single-locale and `APP_LOCALE` is `en`. |
| Cell types | Native — `int` for `set_number` / `rest_seconds`, `float` for weights / rpe, `string` for names / `recommended_action` / a `prescribed_reps` range, `null` for every blank (with `WithStrictNullComparison`). | The point of `.xlsx` over CSV: the user gets real numbers to sum/chart, and truly empty cells to type into. `numberText()` (deterministic decimal trim) is kept only for the `#` prelude text. |
| `#` prelude is positional | `#` rows only ever form the contiguous block before the header; a reader stops treating `#` specially after the header. Data cells may begin with `#`. | Keeps the prelude concept for the Order-154 importer without a fragile global `#`-filter. |
| Scramble | An operation transformer registered via `Scramble::configure()->withOperationTransformers(...)` in `AppServiceProvider`, scoped to route name `routines.cycle-days.export`, rewrites the `200` response to the spreadsheet mime. | Scramble infers `application/json` from the `BinaryFileResponse` return type. Verified by a feature test on the generated spec, not by the mechanism. |
| Filename slug fallback | Empty `Str::slug` → `rutina` (routine) / `sin-nombre` (label). | Avoids `--ciclo-3-dia-3-.xlsx`; distinct tokens avoid an adjacent `dia-<order>-dia`. |
| No workbook styling / no throttle / no cache headers | None added. | Plain sheet, cheap bounded read; a later story can prettify. |

---

## 10. Work Plan

| # | Task | Definition of Done |
|---|---|---|
| 1 | `composer require maatwebsite/excel` (approved). Commit the `composer.json` / `composer.lock` change. | `maatwebsite/excel ^4.0` in `composer.json`; `Maatwebsite\Excel\Facades\Excel` resolves. |
| 2 | Broaden the golden-rule-3 carve-out in **`CLAUDE.md`** and identically in **`AGENTS.md`**: a file-download endpoint returns a binary/stream download — `Excel::download(...)`, `response()->download(...)`, `response()->streamDownload(...)` — not a JSON Resource; errors on that route are still the JSON envelope. | Both files carry the same sentence. |
| 3 | Create `lang/es/export.php` with the keys in §6. | `trans('export.comment.day', [], 'es')` returns the Spanish template. |
| 4 | Create `app/Exceptions/Cycle/RoutineHasNoActiveCycleException.php` — `final`, extends `DomainException`, `$errorCode = 'ROUTINE_HAS_NO_ACTIVE_CYCLE'`, `$statusCode = Response::HTTP_UNPROCESSABLE_ENTITY`, default message. | `->statusCode() === 422`, `->errorCode() === 'ROUTINE_HAS_NO_ACTIVE_CYCLE'` (locked by TC-22 in Task 8). |
| 5 | Create `app/Exceptions/Cycle/CycleDayNotInActiveCycleException.php` — `final`, extends `DomainException`, `$errorCode = 'CYCLE_DAY_NOT_IN_ACTIVE_CYCLE'`, `$statusCode = 422`, default message. PHPDoc cross-references the Session-domain `409` sibling. | `->statusCode() === 422` (locked by TC-23 in Task 8). |
| 6 | Create `app/Exports/Cycle/CycleDayExport.php` — `final`, implements `FromArray` + `WithHeadings` + `WithStrictNullComparison`. Constructor `(Routine $routine, CycleDay $day, RecommendationCatalogService $recommendations)`: (a) `$cycle = $routine->cycle()->first()`; `throw_if($cycle === null \|\| $cycle->status !== CycleStatus::Active, new RoutineHasNoActiveCycleException)`; (b) `throw_unless($day->cycle_id === $cycle->id, new CycleDayNotInActiveCycleException)`; (c) `$day->loadMissing('dayExercises.exercise')`; (d) `$recs = $recommendations->listCurrentForRoutine($routine)->keyBy('exercise_id')`; (e) store `public readonly string $filename` (slugged per §2.1.2, `.xlsx`), `private readonly array $headingRows` (`[['# …'], …, HEADER]`) and `private readonly array $dataRows` (per §2.1.1 — `set_number` per `exercise_id`, decimals as `?float`, blanks as `null`, trailing `.` trimmed from the rationale, `#` text via `strtr` on `trans(..., 'es')`). `headings()` returns `$this->headingRows`; `array()` returns `$this->dataRows`. A one-line comment notes the pinned `es` locale. | `vendor/bin/phpstan analyse` clean; behaviour locked by TC-14–TC-27 in Task 8. |
| 7 | Create `app/Http/Controllers/Cycle/ExportCycleDayController.php` — invokable, `__invoke(Routine $routine, CycleDay $day, RecommendationCatalogService $recommendations): BinaryFileResponse` → `$sheet = new CycleDayExport($routine, $day, $recommendations); return Excel::download($sheet, $sheet->filename);`. Register the route in `routes/api.php` inside the `auth:sanctum` group after `routines.recommendations.list`: `->whereUuid('routine')->whereUuid('day')->can('view', 'routine')->name('routines.cycle-days.export')`, with a short comment. Add the controller import alphabetically. | `arch('cycle controllers are invokable')` passes; `php artisan route:list` shows `routines.cycle-days.export` (GET, `auth:sanctum`, `can:view,routine`). |
| 8 | Add `AppServiceProvider::configureApiDocs()` (called from `boot()`): `Scramble::configure()->withOperationTransformers(...)` — for route name `routines.cycle-days.export`, set `$operation->responses` to a single `200` `Response` whose content type is `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`. | The Scramble companion feature test passes: `…/export` `get.responses.200.content` has the spreadsheet mime, no `application/json`. |
| 9 | Extend `tests/Feature/Auth/DocsSecurityTest.php`: assert `$spec['paths']['/api/v1/routines/{routine}/cycle-days/{day}/export']['get']` has no `security` key. | `vendor/bin/pest --filter=DocsSecurity` passes. |
| 10 | Add `exportCommentLines(array $headingRows)` to `tests/Helpers.php` — the `#` lines from a `CycleDayExport::headings()` result. | Usable from feature and unit tests. |
| 11 | Write `tests/Unit/Cycle/CycleDayExportTest.php` — TC-14 through TC-27, each its own `it()`. | `vendor/bin/pest tests/Unit/Cycle/CycleDayExportTest.php` green. |
| 12 | Write `tests/Feature/Cycle/ExportCycleDayTest.php` — TC-1 through TC-13 plus the Scramble companion, each its own `it()`, following `tests/Feature/Cycle/GenerateCycleTest.php` / `ListRoutineRecommendationsTest.php`. `Excel::fake()` per test that inspects the export; TC-13 stays real. | `vendor/bin/pest tests/Feature/Cycle/ExportCycleDayTest.php` green. |
| 13 | Run the project checks: `vendor/bin/pint app tests routes/api.php lang --format agent`, then `vendor/bin/phpstan analyse`, then `vendor/bin/pest --filter=Cycle` plus `--filter=DocsSecurity`, then the full `vendor/bin/pest`. | Pint clean, PHPStan level 6 clean, all tests green. No migration → no `ide-helper:models`, no DB clone. |

---
