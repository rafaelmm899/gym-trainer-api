# Export a training day to a spreadsheet — `GET /api/v1/routines/{routine}/cycle-days/{day}/export`

> Derived from the Notion User Story "Exportar el día de entrenamiento a CSV"
> (Feature: Export / Import · MVP · Must · Repo: API · Order 152,
> `https://app.notion.com/p/3d45cf08db2d813d8e20d4ed5f30a797`) and the planning
> conversation with the product owner (this session). Base contract:
> `docs/product-context.md` §2 / §4 (steps 4 & 6) / §6,
> `CLAUDE.md` "The pipeline" / "Layout — folders by domain" / "Errors — one
> envelope" / "Conventions",
> `docs/plans/routine-recommendations-endpoint-spec.md` (the `RoutinePolicy::view`
> reuse reference),
> `docs/plans/create-training-session-spec.md` /
> `docs/plans/store-set-logs-spec.md` (the active-cycle guard shape,
> `TrainingSessionOpeningService`, `DomainException` subclasses),
> `docs/plans/domain-exception-handling-spec.md` (`DomainException` + the
> `{ "data": { "code", "message" } }` envelope rendered by
> `ApiExceptionRenderer`).
>
> **Format decisions (this session):** the story says "CSV" with a `#` comment
> prelude carrying the day's metadata and rationales. As built:
> 1. The download is an **`.xlsx` workbook**, built with **`maatwebsite/excel`**.
> 2. **No prelude / no metadata block.** A flat, fillable sheet: a header row,
>    then one row per prescribed set. The day's identity is the filename; the
>    split / exercise / recommendation rationales stay in the JSON endpoints
>    (`GET /api/v1/routines/{routine}` carries `split_rationale`;
>    `GET /api/v1/routines/{routine}/recommendations` carries each
>    recommendation's `explanation`). PO-approved.

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
the routine's **active** cycle: a header row, then one row per prescribed set —
the prescription and the current recommendation on the left, the actuals columns
(`weight_kg,reps,rpe,note`) blank for the user to fill. The filled sheet is the
input to the companion import story (Order 154, **not** in this PR).

**In scope:**

- **`GET /api/v1/routines/{routine}/cycle-days/{day}/export`** — streams an
  `.xlsx` download (HTTP `200`) for one day of the caller's routine's **active**
  cycle. `{routine}` and `{day}` are both bound by `uuid`.
- Adding **`maatwebsite/excel`** (Laravel Excel) as a dependency — approved by
  the product owner this session.
- **Two new `Routine` relations** (model-only, no schema change): `activeCycle`
  (`HasOne<Cycle>` where `status = active`) and `activeExerciseRecommendations`
  (`HasMany<ExerciseRecommendation>` where `status = active`).
- **`App\Services\Cycle\CycleDayExportService`** — the pipeline step: guard the
  active cycle and that the day is in it, load the day's prescriptions, build the
  filename, and hand a `CycleDayExport` the day-exercises + the routine's active
  recommendations keyed by `exercise_id`.
- **`App\Exports\Cycle\CycleDayExport`** — a pure Laravel Excel adapter
  (`FromCollection` + `WithHeadings` + `WithMapping` + `WithStrictNullComparison`):
  `headings()` is the fixed column list; `map(DayExercise)` turns one prescription
  into one row per prescribed set, casting decimals and formatting the reps
  label. No guards, no queries.
- The 12-column layout (§2.1.1). `set_number` runs `1..sets` **per prescription**.
  An exercise with no active recommendation still appears (its `recommended_*`
  cells blank).
- Reusing `RoutinePolicy::view` via `->can('view', 'routine')` (a routine the
  caller does not own → `403`).
- Two new `App\Exceptions\Cycle\` `DomainException` subclasses (`422`).
- Broadening the `CLAUDE.md` / `AGENTS.md` golden-rule-3 carve-out to name
  `Excel::download(...)` as a sanctioned file-download success body.
- Documenting the spreadsheet response for Scramble.

**Out of scope:**

- **Any day-metadata block in the file**, and the split / exercise /
  recommendation rationales. PO-approved deviation from the story's `#` example.
- **The import endpoint** `POST .../import` (Order 154) — a separate story/PR.
- **Free / off-plan sessions**; **exporting the whole cycle**; **CSV / other
  formats** (the `Accept` header is ignored, no `406`).
- **Workbook styling** — no widths, freeze panes, bold header, cell formats.
- **Pagination / caching headers / throttling.**
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
  foreign routine → `403`. Overrides the story's literal "404".
- **`{day}` binding.** `->whereUuid('day')` — non-uuid → `404`; unknown uuid →
  `404`. Not scoped to `{routine}`. A `{day}` uuid that is a real `CycleDay` of
  another routine or a stale cycle resolves, then the Service's guard rejects it
  with `422 CYCLE_DAY_NOT_IN_ACTIVE_CYCLE`. Overrides the story's `order` (1..N)
  segment, for consistency with `POST .../sessions`.
- **Guard order.** `CycleDayExportService::handle()` checks *"the routine has an
  active cycle"* (`$routine->activeCycle !== null`) before *"this day is in it"*
  (`$day->cycle_id === $routine->activeCycle->id`), mirroring
  `TrainingSessionOpeningService`. A routine whose current cycle is
  `generating` / `failed` / `completed` / `incomplete` (or an `archived`
  routine) → `422 ROUTINE_HAS_NO_ACTIVE_CYCLE`.
- **`Routine::activeCycle`** = `hasOne(Cycle::class)->where('status', CycleStatus::Active)`.
  A routine has exactly one active cycle at a time (product invariant).
- **Errors stay JSON.** The guards throw from `CycleDayExportService::handle()` —
  *before* `Excel::download()` is called — so `ApiExceptionRenderer` renders the
  usual `{ "data": { "code", "message" } }` envelope with no partial file. The
  two new `DomainException` subclasses flow through the renderer's existing
  `DomainException` arm (`errorCode()` / `statusCode()`), no wiring.
- **`Content-Type`.** `Excel::download()` serves the file via
  `response()->download()`, which sets the xlsx mime from the file extension.
- **Pipeline.** Controller → `CycleDayExportService` → `CycleDayExport`. No Form
  Request, no Action. The invokable controller is ~3 lines: `$sheet =
  $service->handle($routine, $day); return Excel::download($sheet, $sheet->filename);`.
  The guards, the filename and the recommendation gathering live in the Service;
  the row shaping lives in the export's `map()`.

**Workbook shape** (§2.1.1)

One sheet, no metadata rows.

**Header row** (`CycleDayExport::headings()`), exactly:

```
exercise · set_number · prescribed_weight_kg · prescribed_reps · prescribed_rpe · rest_seconds · recommended_weight_kg · recommended_action · weight_kg · reps · rpe · note
```

**Data rows** — `CycleDayExport::map(DayExercise $de)` returns `$de->sets` rows.
Native cell types; `null` cells stay blank (`WithStrictNullComparison`).

| Column | Value / type |
|---|---|
| `exercise` | `$de->exercise->name` (string) |
| `set_number` | int, `1..$de->sets` — **per prescription**. If the same exercise is prescribed twice in one day, its second `DayExercise` restarts at 1; the Order-154 importer renumbers per exercise anyway. |
| `prescribed_weight_kg` | `(float) $de->target_weight_kg`, or `null` |
| `prescribed_reps` | `$de->rep_min` (int) when `rep_min === rep_max`, else `"<rep_min>-<rep_max>"` (string) |
| `prescribed_rpe` | `(float) $de->target_rpe`, or `null` |
| `rest_seconds` | `$de->rest_seconds` (int) |
| `recommended_weight_kg` | `(float)` of the active recommendation's `target_weight_kg` for `$de->exercise_id`, or `null` |
| `recommended_action` | that recommendation's `action->value` (string), or `null` |
| `weight_kg` / `reps` / `rpe` / `note` | `null` (user fills) |

A day with **zero** `day_exercises` still exports: the header row, no data rows.
A prescription with `sets < 1` contributes no rows.

**Filename** (§2.1.2) — built by the Service.

`Content-Disposition: attachment; filename=<routine-slug>-ciclo-<seq>-dia-<order>-<label-slug>.xlsx`

- `sprintf('%s-ciclo-%d-dia-%d-%s.xlsx', slug($routine->name) ?: 'rutina', $cycle->sequence_number, $day->order, slug($cycleDay->label) ?: 'sin-nombre')`.
- `slug($v)` = `Str::slug(Str::ascii($v))`. Distinct fallbacks (`rutina` /
  `sin-nombre`) so an empty slug never reads as a bug.
- ASCII-only by construction → Symfony emits a plain `filename=…`.
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

Not applicable — no schema changes. The two new `Routine` relations
(`activeCycle`, `activeExerciseRecommendations`) are model-only; they read the
existing `status` columns on `cycles` / `exercise_recommendations`. The endpoint
reads `routines`, `cycles`, `cycle_days`, `day_exercises`, `exercises`,
`exercise_recommendations`. No new columns, tables, indexes.

**No database isolation needed** — no migration, no seed change.

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
is a business-rule check (`422`) in the Service, not a Policy concern.

---

## 6. Configuration

No environment variables and no `config/*` changes.

**New dependency** — `maatwebsite/excel` `^4.0` (`composer require
maatwebsite/excel`). Auto-discovered (facade `Maatwebsite\Excel\Facades\Excel`,
concerns under `Maatwebsite\Excel\Concerns\*`). No `config/excel.php` publish —
the `.xlsx` writer defaults are used as-is. Pulls in `phpoffice/phpspreadsheet`.
`^3.1` is not an option — it caps at Laravel 11; this app is on Laravel 13.

No translation file — the workbook carries no Spanish prose, only the fixed
English column headers and the filename tokens (`ciclo`, `dia`) inline.

---

## 7. Current vs New Behavior

| Behavior | Current | New |
|---|---|---|
| Getting a training day offline | Not possible — the user reads several JSON endpoints and hand-builds a sheet. | `GET .../cycle-days/{day}/export` streams one flat, ready-to-fill `.xlsx`. |
| Success response body convention | Every `200`/`201` body is a JSON Resource under `data`; `response()->…` for a success body is banned (`CLAUDE.md` golden rules 2 & 3). | Unchanged for every existing endpoint. A **file-download** success body — `Excel::download(...)` / `response()->download(...)` / `response()->streamDownload(...)` — is the one sanctioned exception, documented by a broadened line in `CLAUDE.md` **and** `AGENTS.md`. Error bodies on the route are still the JSON envelope. |
| "Day not in the active cycle" error | `App\Exceptions\Session\CycleDayNotInActiveCycleException` → `409` (session-open flow). | A **separate** `App\Exceptions\Cycle\CycleDayNotInActiveCycleException` → `422` for this endpoint. |
| `Routine` model | Has `cycle` (max `sequence_number`). | Adds `activeCycle` and `activeExerciseRecommendations` — both scoped to `status = active`. `activeCycle` also lets `TrainingSessionOpeningService` drop its hand-rolled check later. |
| Scramble output for the route | n/a (route does not exist). | The operation documents a `200` with content type `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` (Scramble would otherwise infer `application/json`), via an operation transformer registered in `AppServiceProvider`. |

---

## 8. Test Cases

*All executable with `vendor/bin/pest`. `Excel::fake()` (from
`Maatwebsite\Excel\Facades\Excel`) per feature test that inspects the downloaded
export.*

**Service — `tests/Unit/Cycle/CycleDayExportServiceTest.php`**

**TC-1:** `handle()` returns a `CycleDayExport` whose `collection()` is the day's
`day_exercises`.

**TC-2:** `filename` — routine `"Volumen Invierno ñ"`, cycle `seq` 2, day
`label "Piernas"` `order` 4 → `volumen-invierno-n-ciclo-2-dia-4-piernas.xlsx`.

**TC-3:** an all-punctuation name → the `rutina` / `sin-nombre` fallbacks.

**TC-4:** a routine whose current cycle is not `active` → throws
`RoutineHasNoActiveCycleException` (`statusCode() === 422`,
`errorCode() === 'ROUTINE_HAS_NO_ACTIVE_CYCLE'`).

**TC-5:** a `{day}` from a `completed` cycle of the same routine (with an
`active` cycle alongside) → throws `CycleDayNotInActiveCycleException`
(`statusCode() === 422`, `errorCode() === 'CYCLE_DAY_NOT_IN_ACTIVE_CYCLE'`).

**Export — `tests/Unit/Cycle/CycleDayExportTest.php`**

*Built directly: `new CycleDayExport($filename, $dayExercises, $recommendations)`;
`map($dayExercise)` called per prescription.*

**TC-6:** `headings()` is exactly the 12-name column header.

**TC-7:** `map()` on a prescription with `sets` 3 → 3 rows, `set_number`
`1,2,3` (ints), the exercise name in column 0, the four actuals cells `null`.

**TC-8:** `map()` casts `target_weight_kg` `"100.00"` → `100.0` and
`target_rpe` `"8.0"` → `8.0`.

**TC-9:** `prescribed_reps` is the int `5` when `rep_min === rep_max === 5`, the
string `"8-12"` when `rep_min` 8 / `rep_max` 12.

**TC-10:** `target_weight_kg` / `target_rpe` `null` → those cells are `null`.

**TC-11:** a matching active recommendation fills `recommended_weight_kg`
(`102.5`) and `recommended_action` (`advance_weight`); none → both `null`.

**TC-12:** `map()` on a prescription with `sets` 0 → `[]`.

**Feature — `tests/Feature/Cycle/ExportCycleDayTest.php`**

**TC-13:** downloads the day as an `.xlsx` scoped to that day
- **Given:** the caller owns a routine with an `active` cycle (`seq` 3) with two
  days — day `order` 1 ("Empuje", exercise "Press banca"), and day `order` 3
  ("Piernas", exercises "Sentadilla" `sets` 4 and "Zancada" `sets` 3).
- **When:** `Excel::fake()`, then `GET .../cycle-days/{day order 3 uuid}/export`.
- **Expect:** `200`;
  `Excel::assertDownloaded('volumen-invierno-ciclo-3-dia-3-piernas.xlsx', …)` —
  callback flattens `collection()` through `map()` and asserts `headings()` is
  the 12-name row; 7 data rows; `exercise` column `["Sentadilla"×4, "Zancada"×3]`;
  `set_number` column `[1,2,3,4,1,2,3]`; every actuals cell `null`; `Press banca`
  (day 1) in no row.

**TC-14:** a matching `active` recommendation → every data row's
`recommended_weight_kg` is `102.5` and `recommended_action` is `advance_weight`.

**TC-15:** an `applied` recommendation is ignored — `recommended_*` cells `null`.

**TC-16:** `{day}` of another routine → `422` `CYCLE_DAY_NOT_IN_ACTIVE_CYCLE`.

**TC-17:** `{day}` of a non-active cycle of the same routine → `422`
`CYCLE_DAY_NOT_IN_ACTIVE_CYCLE`.

**TC-18:** current cycle `generating` → `422` `ROUTINE_HAS_NO_ACTIVE_CYCLE`.

**TC-19:** `archived` routine → `422` `ROUTINE_HAS_NO_ACTIVE_CYCLE`.

**TC-20:** another user's routine → `403` `AUTHORIZATION_EXCEPTION`.

**TC-21:** unknown `{routine}` uuid → `404` `NOT_FOUND_EXCEPTION`.

**TC-22:** unknown `{day}` uuid → `404` `NOT_FOUND_EXCEPTION`.

**TC-23:** a non-uuid path segment → `404` `NOT_FOUND_EXCEPTION`.

**TC-24:** unauthenticated → `401` `AUTHENTICATION_EXCEPTION`.

**TC-25:** streams a genuine `.xlsx` — **no** `Excel::fake()`; a day with one
`day_exercise` (`sets` 2) → `200`; `assertDownload(<filename>)`; `Content-Type`
is the spreadsheet mime; the streamed body's first two bytes are `PK`.

**TC-26:** the generated OpenAPI operation for `…/export`
`get.responses.200.content` has the spreadsheet mime key and no
`application/json` key.

*(The route inheriting the document-root security scheme is asserted in
`tests/Feature/Auth/DocsSecurityTest.php`, extended with the new path.)*

---

## 9. Technical Decisions

| Decision area | What was decided | Why |
|---|---|---|
| Output format | `.xlsx`, not CSV. | A CSV cannot carry a `#` prelude *and* comma-safe rows through one writer. Switched with the user. |
| No metadata block | Flat sheet: header + set rows. Day identity in the filename; rationales stay in the JSON endpoints. | Decided with the user ("un excel simple que el usuario pueda cargar"). The `#` prelude was a CSV-era comment marker that dragged in translation templates, `strtr` and sentence assembly for no functional gain. PO-approved deviation from the story. |
| Library | `maatwebsite/excel` `^4.0`. | User asked for it (approved). `^3.1` caps at Laravel 11; this app is on 13. |
| Model the chain | Add `Routine::activeCycle()` (`HasOne<Cycle>` where `status = active`) and `Routine::activeExerciseRecommendations()` (`HasMany` where `status = active`). | Decided with the user — the guards and the recommendation lookup were hand-assembled navigation. `activeCycle` also replaces the check `TrainingSessionOpeningService` hand-rolls. A `DayExercise → recommendation` relation is not modelable (the match is `(routine_id, exercise_id)` and `routine_id` is three hops away; composite-key relations aren't first-class in Eloquent), so `map()` looks the recommendation up in the keyed collection the Service passes it. |
| Service + Export split | `CycleDayExportService` = guards + gather + filename → returns a `CycleDayExport`. `CycleDayExport` = a pure Laravel Excel adapter (`FromCollection` + `WithHeadings` + `WithMapping` + `WithStrictNullComparison`). | Decided with the user. The export must *only* export; guards and queries are domain logic → the Service (`CLAUDE.md`: business guards live in a Service; controller stays ~3 lines). Row *shaping* (`map()`) is presentation and belongs in the export, like a JSON Resource's `toArray()`. |
| `set_number` per prescription | `map()` numbers `1..$de->sets` for each `DayExercise`, statelessly. The same exercise twice in a day restarts at 1. | The natural `WithMapping` shape (no cross-item state). The duplicate-exercise-in-a-day case is rare, and the Order-154 importer renumbers per exercise regardless. |
| Cell shaping in `map()` | `(float)` cast of the `decimal:*` strings, the `"8-12"` reps label, `null` for blanks. | Turning a record into cells *is* the export's job. Keeps the Service to guards + gather + filename. |
| `{day}` identifier | `cycle_days.uuid` + route-model binding (not `order` 1..N). | Consistency with `POST .../sessions`. Resolved with the user. |
| Foreign routine | `403` via the existing `->can('view', 'routine')` (not `404`). | Consistency with the sibling routine-scoped endpoints. Resolved with the user. |
| Invalid `{day}` / no active cycle | `422` via two new `App\Exceptions\Cycle\` `DomainException` subclasses (`protected int $statusCode = 422`). | The story specifies `422`. A cross-entity business check → a guard throwing a `DomainException`. `422` over the default `409` because a stale/foreign `{day}` uuid is an unprocessable parameter. |
| Distinct from `App\Exceptions\Session\CycleDayNotInActiveCycleException` | A new class, same name, under `App\Exceptions\Cycle\`. | That one is `409` for the session-open flow; the codebase keeps exceptions per-domain. |
| Scramble | An operation transformer registered via `Scramble::configure()->withOperationTransformers(...)` in `AppServiceProvider`, scoped to route name `routines.cycle-days.export`, rewrites the `200` response to the spreadsheet mime. | Scramble infers `application/json` from the `BinaryFileResponse` return type. Verified by a feature test on the generated spec. |
| No workbook styling / no throttle / no cache headers | None added. | Plain sheet, cheap bounded read. |

---

## 10. Work Plan

| # | Task | Definition of Done |
|---|---|---|
| 1 | `composer require maatwebsite/excel` (approved). Commit `composer.json` / `composer.lock`. | `maatwebsite/excel ^4.0` in `composer.json`; the facade resolves. |
| 2 | Broaden the golden-rule-3 carve-out in **`CLAUDE.md`** and identically in **`AGENTS.md`**: a file-download endpoint returns a binary/stream download — `Excel::download(...)`, `response()->download(...)`, `response()->streamDownload(...)` — not a JSON Resource; errors on that route are still the JSON envelope. | Both files carry the same sentence. |
| 3 | Add to `app/Models/Routine.php`: `activeCycle(): HasOne` → `hasOne(Cycle::class)->where('status', CycleStatus::Active)`; `activeExerciseRecommendations(): HasMany` → `hasMany(ExerciseRecommendation::class)->where('status', RecommendationStatus::Active)`. Refresh the PHPDoc block (`ide-helper:models "App\Models\Routine" --write`, then check the `@property-read` lines by hand). | `$routine->activeCycle` / `$routine->activeExerciseRecommendations` resolve; `phpstan` clean. |
| 4 | Create `app/Exceptions/Cycle/RoutineHasNoActiveCycleException.php` — `final`, extends `DomainException`, `$errorCode = 'ROUTINE_HAS_NO_ACTIVE_CYCLE'`, `$statusCode = Response::HTTP_UNPROCESSABLE_ENTITY`, default message. | `->statusCode() === 422`, `->errorCode() === 'ROUTINE_HAS_NO_ACTIVE_CYCLE'` (locked by TC-4). |
| 5 | Create `app/Exceptions/Cycle/CycleDayNotInActiveCycleException.php` — `final`, extends `DomainException`, `$errorCode = 'CYCLE_DAY_NOT_IN_ACTIVE_CYCLE'`, `$statusCode = 422`, default message. PHPDoc cross-references the Session-domain `409` sibling. | `->statusCode() === 422` (locked by TC-5). |
| 6 | Create `app/Services/Cycle/CycleDayExportService.php` — `final`. `handle(Routine $routine, CycleDay $day): CycleDayExport`: `$routine->loadMissing(['activeCycle', 'activeExerciseRecommendations'])`; `throw_if($routine->activeCycle === null, new RoutineHasNoActiveCycleException)`; `throw_unless($day->cycle_id === $routine->activeCycle->id, new CycleDayNotInActiveCycleException)`; `$day->loadMissing('dayExercises.exercise')`; `return new CycleDayExport($this->filename(…), $day->dayExercises, $routine->activeExerciseRecommendations->keyBy('exercise_id'))`. Private `filename()` (`sprintf`) + `slug()`. | `phpstan` clean; behaviour locked by TC-1–TC-5. |
| 7 | Create `app/Exports/Cycle/CycleDayExport.php` — `final`, implements `FromCollection` + `WithHeadings` + `WithMapping` + `WithStrictNullComparison`. Constructor `(public readonly string $filename, private readonly Collection $dayExercises, private readonly Collection $recommendations)`. `collection()` returns `$this->dayExercises`; `headings()` returns the 12-name array inline; `map(DayExercise $row)` returns `range(1, $row->sets)` mapped to the 12-cell rows (decimals via a private `number(): ?float`, reps label inline, actuals `null`), or `[]` when `$row->sets < 1`. No guards, no queries. | `phpstan` clean; behaviour locked by TC-6–TC-12. |
| 8 | Write `tests/Unit/Cycle/CycleDayExportServiceTest.php` (TC-1–TC-5) and `tests/Unit/Cycle/CycleDayExportTest.php` (TC-6–TC-12), each case its own `it()`. | Both files green. |
| 9 | Create `app/Http/Controllers/Cycle/ExportCycleDayController.php` — invokable, `__invoke(Routine $routine, CycleDay $day, CycleDayExportService $service): BinaryFileResponse` → `$sheet = $service->handle($routine, $day); return Excel::download($sheet, $sheet->filename);`. Register the route in `routes/api.php` inside the `auth:sanctum` group after `routines.recommendations.list`: `->whereUuid('routine')->whereUuid('day')->can('view', 'routine')->name('routines.cycle-days.export')`, with a short comment; import the controller alphabetically. | `arch('cycle controllers are invokable')` passes; `php artisan route:list` shows `routines.cycle-days.export`. |
| 10 | Add `AppServiceProvider::configureApiDocs()` (called from `boot()`): `Scramble::configure()->withOperationTransformers(...)` — for route name `routines.cycle-days.export`, set `$operation->responses` to a single `200` `Response` whose content type is the spreadsheet mime. | TC-26 passes. |
| 11 | Extend `tests/Feature/Auth/DocsSecurityTest.php`: assert `$spec['paths']['/api/v1/routines/{routine}/cycle-days/{day}/export']['get']` has no `security` key. | `vendor/bin/pest --filter=DocsSecurity` passes. |
| 12 | Write `tests/Feature/Cycle/ExportCycleDayTest.php` — TC-13 through TC-26, each its own `it()`. A `renderedRows(CycleDayExport)` helper flattens `collection()` through `map()`. `Excel::fake()` per test that inspects the export; TC-25 stays real. | `vendor/bin/pest tests/Feature/Cycle/ExportCycleDayTest.php` green. |
| 13 | Run the project checks: `vendor/bin/pint app tests routes/api.php --format agent`, then `vendor/bin/phpstan analyse`, then `vendor/bin/pest --filter=Cycle` plus `--filter=DocsSecurity`, then the full `vendor/bin/pest`. | Pint clean, PHPStan level 6 clean, all tests green. No migration → no DB clone. |

---
