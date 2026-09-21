# Import a filled training day from a spreadsheet — `POST /api/v1/routines/{routine}/cycle-days/{day}/import`

> Derived from the Notion User Story "Importar los avances del día desde CSV"
> (Feature: Export / Import · MVP · Must · Repo: API · Order 154,
> `https://app.notion.com/p/3d45cf08db2d818c9764e0889d70d611`) and the planning
> conversation with the product owner (this session). Base contract:
> `docs/product-context.md` §2 / §4 (steps 4 & 6) / §6,
> `CLAUDE.md` "The pipeline" / "Layout — folders by domain" / "Errors — one
> envelope" / "Conventions",
> `docs/plans/export-training-day-csv-spec.md` (the sibling story this shares
> the column contract and the `Routine::activeCycle` relation with),
> `docs/plans/create-training-session-spec.md` / `docs/plans/store-set-logs-spec.md`
> / `docs/plans/complete-session-spec.md` (`TrainingSessionCreateAction`,
> `SetLogCreateAction`, `SessionCloseAction` — reused unchanged),
> `docs/plans/domain-exception-handling-spec.md` (`DomainException` + the
> `{ "data": { "code", "message" } }` envelope rendered by
> `ApiExceptionRenderer`).
>
> **Format decision (this session):** the story says "CSV" — multipart `file`,
> comma + UTF-8, `#` comment lines ignored. It was written 2026-09-07; the
> sibling export story (Order 152) was reworked 2026-09-09, **after** this
> story's text was agreed, to an `.xlsx` workbook with no `#` prelude. This
> import must consume exactly what the export now produces, so the round trip
> (download the day, fill it, re-upload the same file) works. Decided with the
> user: **the accepted format is `.xlsx` only.** The story's literal "CSV" /
> "`#` line" / "CSV dialect" language is stale and does not apply.

## 1. Context

**Kind:** Brownfield Feature

**Stack:** PHP 8.5 · Laravel 13 · PostgreSQL 17 (runtime) / SQLite `:memory:`
(tests) · Pest 4 · `laravel/sanctum` 4 · `spatie/laravel-data` 4 ·
`maatwebsite/excel` 4 (already a dependency, read side used for the first
time) · Pint · Larastan level 6. Everything runs in Docker.

**Problem statement:** A user running the loop **without the SPA** downloads a
day via `GET .../cycle-days/{day}/export`, fills in `weight_kg` / `reps` /
`rpe` / `note` for the sets they did, and today has no way to get that back
into the system except by hand-calling `POST .../sessions`, then
`POST /sessions/{session}/sets` once per set, then
`POST /sessions/{session}/complete`. This adds one endpoint that takes the
filled `.xlsx` and does all three in one atomic request: open the session for
that day, log every filled set with a contiguous per-exercise `set_number`,
complete the session, and let the existing `SessionAnalysisJob` run as it does
today.

**In scope:**

- **`POST /api/v1/routines/{routine}/cycle-days/{day}/import`** — `multipart/form-data`,
  field `file`, an `.xlsx` matching the export's column layout. `201` with a
  `TrainingSessionResource` (`status: completed`, `analysis_state: pending`,
  its sets logged). `{routine}` and `{day}` are both bound by `uuid`, exactly
  as the export route.
- **`App\Imports\Cycle\CycleDayImport`** — a pure Laravel Excel read adapter
  (`WithHeadingRow`), the mirror of `CycleDayExport`: declares the header row,
  nothing else.
- **`App\Services\Cycle\CycleDayImportService`** — guards the routine/day/day-state
  invariants, reads the workbook, matches each filled row's `exercise` cell to
  one of `{day}`'s `day_exercises` by slug, validates `weight_kg` / `reps` /
  `rpe`, and returns a `Collection<int, LogSetData>` with `set_number`
  recomputed contiguously per exercise from row order — ready to hand straight
  to the existing `SetLogCreateAction`.
- **`App\Exceptions\Session\CycleDayAlreadyCompletedException`** (new, `409`) —
  the one invariant this story adds that no existing guard covers: a
  `completed` `TrainingSession` already exists for this `cycle_day` in the
  active cycle.
- **`App\Actions\Session\TrainingSessionImportAction`** — orchestrates the
  above: validate/parse (outside any transaction), then inside one
  `DB::transaction`, reuse `TrainingSessionCreateAction`, loop
  `SetLogCreateAction` per row, then `SessionCloseAction` (which dispatches
  `SessionAnalysisJob`, unchanged).
- **`App\Http\Requests\Cycle\ImportCycleDayRequest`** — shape-only: `file` is
  present, a file, `.xlsx`. Authorization reuses `TrainingSessionPolicy::create`
  (the same ability `StoreTrainingSessionRequest` already uses to open a
  session for a routine) — no new Policy method.
- **`App\Http\Controllers\Cycle\ImportCycleDayController`** — invokable, ~3
  lines, mirrors `ExportCycleDayController`'s placement.

**Out of scope:**

- **Free / off-plan sessions.** The imported session always has a `{day}`.
- **Importing multiple days in one request.** One file, one day, one request.
- **Re-importing / updating an already-completed day.** That is exactly the
  `409 CYCLE_DAY_ALREADY_COMPLETED` case — there is no update path.
- **Any file format other than `.xlsx`** — no CSV, no CSV-dialect handling, no
  `#` comment-line convention (moot now that the format is xlsx).
- **Workbook styling / formula evaluation** beyond what `maatwebsite/excel`
  reads by default.
- **Changing `TrainingSessionOpeningService`** or the behavior of
  `POST /routines/{routine}/sessions` — the new "day already completed" guard
  is specific to this endpoint, not a change to how sessions are opened in
  general.
- The `gym-trainer-spa/` frontend.

---

## 2. API Surface

### 2.1 REST

The route joins the existing `Route::middleware('auth:sanctum')->group(...)` in
`routes/api.php`, immediately after `routines.cycle-days.export`.

| Method | Path | Auth | Request | Response | Status codes |
|---|---|---|---|---|---|
| POST | `/api/v1/routines/{routine}/cycle-days/{day}/import` | `auth:sanctum` + `TrainingSessionPolicy::create` (via `->can('create', [TrainingSession::class, $routine])`) | `multipart/form-data`, field `file`: required, a file, `.xlsx`. Path: `{routine}` = `routines.uuid`, `{day}` = `cycle_days.uuid`. | `TrainingSessionResource` (`id`, `status: "completed"`, `analysis_state: "pending"`, `note: null`, `perceived_effort: null`, `started_at`, `completed_at`, `created_at`, `updated_at`, `cycle_day`). | `201` · `401` unauthenticated · `403` `AUTHORIZATION_EXCEPTION` (`{routine}` owned by another user) · `404` `NOT_FOUND_EXCEPTION` (`{routine}` / `{day}` uuid unknown or segment not a uuid) · `409` `CYCLE_DAY_ALREADY_COMPLETED` · `409` `SESSION_IN_PROGRESS` (inherited — see notes) · `422` `VALIDATION_EXCEPTION` (missing/non-xlsx file, unparseable file, invalid/unmatched row data, `SESSION_HAS_NO_SETS`) · `422` `CYCLE_DAY_NOT_IN_ACTIVE_CYCLE` · `422` `ROUTINE_HAS_NO_ACTIVE_CYCLE` |

Notes:

- **`{routine}` / `{day}` binding & authorization.** `->whereUuid('routine')->whereUuid('day')` — a non-uuid segment or unknown uuid → `404`, exactly as the export route. `->can('create', [TrainingSession::class, 'routine'])` runs after binding: a foreign routine → `403`. This reuses the *existing* `TrainingSessionPolicy::create` ability (already used by `POST .../sessions`) rather than adding a new `importDay` ability the story left open — importing a day is, at its core, opening a session for that routine.
- **Guard order**, all inside `CycleDayImportService::handle()`, cheapest/cross-entity checks before the file is ever parsed:
  1. `$routine->activeCycle === null` → `422 ROUTINE_HAS_NO_ACTIVE_CYCLE` (same `App\Exceptions\Cycle\` class the export uses — `{day}` arrives as a route parameter here too, so the same 422-for-a-route-param convention applies, not the session-open flow's 409 sibling).
  2. `$day->cycle_id !== $routine->activeCycle->id` → `422 CYCLE_DAY_NOT_IN_ACTIVE_CYCLE` (same reuse).
  3. A `completed` `TrainingSession` already exists for `$day->id` → `409 CYCLE_DAY_ALREADY_COMPLETED` (new, Session domain — distinct from the Cycle-domain 422s above because this is a state conflict on data already written, not a bad path parameter).
  4. Only then is the uploaded file read and its rows validated.
- **Row matching.** A row's `exercise` cell is normalized with `Str::slug(Str::ascii($cell))` and matched against `$dayExercise->exercise->slug` (the catalog's own normalized column — no need to recompute the comparison side) for one of `{day}`'s `day_exercises`. An exercise that exists in the global catalog but is **not prescribed for this day** is still unmatched → row-level `422`. If the same exercise is prescribed twice on the day (two `day_exercises` rows), the first one (by `order`) is used — both point to the same `exercise_id`, so which one is logged against is immaterial to `SetLogCreateAction`'s own invariants.
- **"Filled" row.** A row counts as filled only if **both** `weight_kg` and `reps` cells are non-blank. Anything else — a fully blank row, a row with only one of the two — is silently skipped, never a validation error. An exercise with zero filled rows is simply not logged.
- **Row validation** (filled rows only): `weight_kg` numeric `> 0`; `reps` integer `> 0`; `rpe` optional, numeric `0`–`10`; `note` optional, any string (blank → `null`); `exercise` must match as above. **All** filled rows are validated before anything is persisted — a request with three bad rows reports all three in one `422`, not one-at-a-time.
- **`set_number`.** Recomputed by `CycleDayImportService`, **not** read from the sheet's own `set_number` column (that column is per-*prescription*, `1..sets`, and is not what `SetLogCreateAction` expects). The service assigns a running counter per exercise from the order filled rows appear in the sheet: the first filled row for an exercise is `1`, the second `2`, and so on — matching `SetLogCreateAction`'s own "next contiguous number" invariant by construction, so `NonContiguousSetNumberException` can never fire from this path.
- **Row-level error shape.** Per-row problems are reported through the *existing* `VALIDATION_EXCEPTION` envelope (`data.errors`), not a new error shape: `CycleDayImportService` builds a message bag keyed `row_<n>.<field>` (e.g. `row_3.weight_kg`, `row_5.exercise`) — `<n>` is the row's position in the spreadsheet as the user would see it in Excel (data rows start at `2`, since row `1` is the header) — and throws `ValidationException::withMessages($errors)`. This keeps `data.errors` to the one shape `CLAUDE.md` documents (`{field: [msg]}`), with the row folded into the field key, instead of inventing a second "errors" format. A file that isn't a parseable `.xlsx` (caught around the `Excel::toCollection()` call — `Maatwebsite\Excel\Exceptions\UnreadableFileException` or similar) throws the same way under the key `file`.
- **Nothing persisted on `422`/`409`.** `CycleDayImportService::handle()` does all reading and validation *before* `TrainingSessionImportAction` opens its `DB::transaction` — no session, no set, nothing is ever written unless the whole file validates.
- **Empty-file edge case.** A workbook with **zero** filled rows anywhere is not a special case in this endpoint: the session opens with no sets, and `SessionCloseAction`'s own existing guard (`SessionCompletionService::guard()`) throws the existing `SessionHasNoSetsException` (`422`) when it tries to close a session with no sets logged — which rolls back the whole transaction. No new guard needed for this case.
- **Inherited `409 SESSION_IN_PROGRESS`.** `TrainingSessionCreateAction` (reused unchanged) still runs its own `TrainingSessionOpeningService::guard()`, which throws `SessionInProgressException` if the user already has an unrelated `in_progress` session open. This is existing, inherited behavior — the story does not change it, and it fires *after* the three guards above and *after* the file has already validated, so it is the last thing that can reject the request.
- **Pipeline.** `ImportCycleDayRequest` (shape only: file present/type) → `ImportCycleDayController` (~3 lines) → `TrainingSessionImportAction` → `TrainingSessionResource`.

### 2.2 CLI

Not applicable — no CLI commands.

### 2.3 Events

Not applicable — no new events or jobs. `SessionAnalysisJob` is dispatched by the
reused, unchanged `SessionCloseAction`.

---

## 3. UI

### 3.1 Pages

Not applicable — no pages affected (API-only repo).

### 3.2 Components

Not applicable — no components affected.

---

## 4. Database

### 4.1 Schema changes

Not applicable — no schema changes. The endpoint reads `routines`, `cycles`,
`cycle_days`, `day_exercises`, `exercises`, `training_sessions`, and writes
`training_sessions` / `set_logs` through the existing, unchanged Actions.
`Routine::activeCycle` (added for the export story) is reused, not modified.

**No database isolation needed** — no migration, no seed change.

**Doc update:** none. `docs/plans/data-model.md` is unchanged.

### 4.2 Seeds

Not applicable — tests build the graph with factories.

---

## 5. Auth & Authorization

### 5.1 Authentication

**Method:** Session cookie via Laravel Sanctum (SPA / stateful mode), same as
every `/api/v1` route. `auth:sanctum` on the group; unauthenticated → `401`
JSON envelope. `POST` with a session cookie, so the usual CSRF token applies
(same as every other stateful `POST` in this API).

### 5.2 Authorization

| Role | Permissions |
|---|---|
| Routine owner | Can import a filled day into any `cycle_day` of their routine's active cycle, subject to the business guards in §2.1. |
| Any other authenticated user | `403` `AUTHORIZATION_EXCEPTION` (`TrainingSessionPolicy::create` → `false`). |
| Unauthenticated | `401` `AUTHENTICATION_EXCEPTION`. |

No new Policy method. The route reuses `TrainingSessionPolicy::create`
(`$routine->user_id === $user->id`), exactly as `POST .../sessions`. Whether
the routine is active, the day is in the active cycle, or the day was already
completed are business-rule checks (`422`/`409`) in `CycleDayImportService`,
not Policy concerns.

---

## 6. Configuration

No environment variables and no `config/*` changes. No new dependency —
`maatwebsite/excel` `^4.0` is already installed for the export story; this is
its first use on the *read* side (`Excel::toCollection()`), which ships in the
same package.

---

## 7. Current vs New Behavior

| Behavior | Current | New |
|---|---|---|
| Logging a filled offline day | Not possible in one call — the user (or a script) must call `POST .../sessions`, then `POST /sessions/{session}/sets` once per set, then `POST /sessions/{session}/complete`, tracking `set_number` itself. | `POST .../cycle-days/{day}/import` with the filled `.xlsx` does all three atomically in one request; `set_number` is computed for the caller. |
| Re-training an already-completed day | No guard exists anywhere that prevents opening a new session for a `cycle_day` that already has a `completed` session — a second `POST .../sessions` with the same `day` would simply succeed. | **Only for this endpoint:** a new guard (`App\Exceptions\Session\CycleDayAlreadyCompletedException`, `409 CYCLE_DAY_ALREADY_COMPLETED`) rejects an import for a `cycle_day` that already has a `completed` session in the active cycle. `POST .../sessions` is unchanged — this is not a new invariant on session-opening in general. |
| `maatwebsite/excel` usage | Write-only (`Excel::download()` for the export). | Also read (`Excel::toCollection()`), via a new, minimal `App\Imports\Cycle\CycleDayImport` adapter. |
| `data.errors` shape | Populated only by Form Request validation failures (`{field: [msg]}`). | Also populated by `CycleDayImportService` for row-content problems, using the same shape with row-scoped keys (`row_<n>.<field>`) — no new error envelope format. |

---

## 8. Test Cases

*All executable with `vendor/bin/pest`. `Queue::fake()` per feature test that
asserts `SessionAnalysisJob` dispatch; `Excel::fake()` is not usable here since
these tests need a real, readable upload — a small `.xlsx` is built in a
`beforeEach`/helper with a real `CycleDayExport`-shaped writer (or
`Maatwebsite\Excel\Facades\Excel::store()`/`raw()` against a `CycleDayImport`
of controlled data) and attached via `UploadedFile::fake()->createWithContent()`
or Laravel's `Http::fake()`-style file helpers — whichever the implementation
lands on, tests always exercise the real reader, never a faked one.*

**Service — `tests/Unit/Cycle/CycleDayImportServiceTest.php`**

**TC-1:** a routine whose current cycle is not `active` → throws
`RoutineHasNoActiveCycleException` (`statusCode() === 422`,
`errorCode() === 'ROUTINE_HAS_NO_ACTIVE_CYCLE'`).

**TC-2:** a `{day}` from a non-active cycle of the same routine (an `active`
cycle exists alongside) → throws `CycleDayNotInActiveCycleException`
(`422`, `CYCLE_DAY_NOT_IN_ACTIVE_CYCLE`).

**TC-3:** a `{day}` that already has a `completed` `TrainingSession` in the
active cycle → throws `CycleDayAlreadyCompletedException` (`409`,
`CYCLE_DAY_ALREADY_COMPLETED`).

**TC-4:** a valid workbook with one exercise, three filled rows → returns a
`Collection` of 3 `LogSetData`, `set_number` `[1, 2, 3]`, correct
`day_exercise_id`.

**TC-5:** two exercises, some filled rows interleaved in sheet order → each
exercise's `set_number` sequence restarts at `1` and stays contiguous,
independent of the other exercise's rows.

**TC-6:** a row missing `weight_kg` (blank) is skipped — no error, not present
in the returned collection.

**TC-7:** a row missing `reps` (blank) is skipped the same way.

**TC-8:** an exercise cell that doesn't match any of `{day}`'s
`day_exercises` (including a name that exists in the global catalog under a
different day) → `ValidationException` with an error under `row_<n>.exercise`.

**TC-9:** `weight_kg` `0` or negative, or non-numeric → `ValidationException`,
`row_<n>.weight_kg`.

**TC-10:** `reps` `0`, negative, or non-integer → `ValidationException`,
`row_<n>.reps`.

**TC-11:** `rpe` `10.5` (out of `0`–`10`) → `ValidationException`,
`row_<n>.rpe`. `rpe` blank is valid (optional).

**TC-12:** two separately-bad rows in one file → one `ValidationException`
whose `errors()` contains both `row_<n1>.*` and `row_<n2>.*` keys — proves
rows are validated in bulk, not fail-fast.

**TC-13:** the exercise match is accent/case-insensitive (`"Sentadilla"` cell
matches a `day_exercise` named `"sentadilla"` or `"SENTADILLA"`) via
`Str::slug(Str::ascii(...))`.

**TC-14:** a corrupt / non-spreadsheet file content → `ValidationException`,
key `file`.

**TC-15:** the row number in an error key is the spreadsheet row (header is
row `1`; the first data row is row `2`) — a bad second data row reports
`row_3.*`.

**Action — `tests/Unit/Session/TrainingSessionImportActionTest.php`**

**TC-16:** a fully valid import creates one `TrainingSession`
(`status: completed`), one `SetLog` per filled row with the recomputed
`set_number`, and dispatches `SessionAnalysisJob` — assert via `Queue::fake()`.

**TC-17:** any exception thrown by `CycleDayImportService` propagates
unchanged (guard/validation exceptions are not caught/wrapped) and no
`TrainingSession` or `SetLog` row exists afterwards.

**Feature — `tests/Feature/Cycle/ImportCycleDayTest.php`**

**TC-18:** imports a fully filled day
- **Given:** the caller owns a routine with an `active` cycle and one day with
  two prescribed exercises.
- **When:** `POST .../cycle-days/{day}/import` with a real `.xlsx` filling
  every prescribed set for both exercises.
- **Expect:** `201`; `data.status === 'completed'`,
  `data.analysis_state === 'pending'`; a `TrainingSession` row exists for that
  `cycle_day_id` with the expected `SetLog` count and contiguous
  `set_number` per exercise; `SessionAnalysisJob` dispatched
  (`Queue::fake()`).

**TC-19:** partial day — only some rows filled
- **Given:** a day with two prescribed exercises.
- **When:** the uploaded workbook fills every set for one exercise and none
  for the other.
- **Expect:** `201`; sets exist only for the filled exercise; the other
  exercise has no `SetLog` rows and no error.

**TC-20:** rejects a `{day}` that already has a `completed` session
- **Given:** a prior `completed` `TrainingSession` for `{day}` in the active
  cycle.
- **When:** `POST .../import` with any valid file.
- **Expect:** `409`; `data.code === 'CYCLE_DAY_ALREADY_COMPLETED'`; no second
  `TrainingSession` created.

**TC-21:** rejects an unknown exercise name
- **When:** a row's `exercise` cell doesn't match any `day_exercise` of `{day}`.
- **Expect:** `422`; `data.code === 'VALIDATION_EXCEPTION'`;
  `data.errors` has a `row_<n>.exercise` key; **no** `TrainingSession` or
  `SetLog` row exists (nothing persisted).

**TC-22:** rejects invalid row data
- **When:** one row has `weight_kg = -5` and another has `reps = 0`.
- **Expect:** `422`; `data.errors` has both `row_<n1>.weight_kg` and
  `row_<n2>.reps`; nothing persisted.

**TC-23:** rejects a missing `file` field
- **When:** `POST .../import` with no `file` in the multipart body.
- **Expect:** `422`; `data.code === 'VALIDATION_EXCEPTION'`;
  `data.errors.file` present.

**TC-24:** rejects a non-`.xlsx` upload
- **When:** the `file` field is a `.txt` or `.pdf`.
- **Expect:** `422`; `data.errors.file` present.

**TC-25:** rejects a workbook with zero filled rows
- **When:** every row is blank in `weight_kg`/`reps`.
- **Expect:** `422`; `data.code === 'SESSION_HAS_NO_SETS'`; no
  `TrainingSession` row persists (the transaction rolls back).

**TC-26:** rejects a routine whose current cycle is not active
- **Expect:** `422`; `data.code === 'ROUTINE_HAS_NO_ACTIVE_CYCLE'`.

**TC-27:** rejects a `{day}` from a non-active cycle of the same routine
- **Expect:** `422`; `data.code === 'CYCLE_DAY_NOT_IN_ACTIVE_CYCLE'`.

**TC-28:** returns `403` for a routine owned by another user.

**TC-29:** returns `404` for an unknown `{routine}` uuid.

**TC-30:** returns `404` for an unknown `{day}` uuid.

**TC-31:** returns `404` for a non-uuid path segment.

**TC-32:** rejects an unauthenticated request (`401`).

**TC-33:** an in-progress session elsewhere still blocks the import
- **Given:** the caller has an unrelated `in_progress` `TrainingSession`
  (different `cycle_day`).
- **When:** `POST .../import` with a valid file for another day.
- **Expect:** `409`; `data.code === 'SESSION_IN_PROGRESS'` (inherited from
  `TrainingSessionCreateAction`); nothing persisted for the import's day.

---

## 9. Technical Decisions

| Decision area | What was decided | Why |
|---|---|---|
| Accepted format | `.xlsx` only — not CSV as the Notion story literally says. | The story text predates the export's 2026-09-09 rework to `.xlsx`. Decided with the user: the import must consume exactly what the export produces, so the download-fill-upload round trip works with one file format. |
| Authorization | Reuse `TrainingSessionPolicy::create` (`->can('create', [TrainingSession::class, 'routine'])`). No new `importDay` ability. | The story explicitly left this open ("a definir en spec"). Importing a day *is* opening a session for that routine — the existing ability already expresses exactly that ownership check; a second ability would be indirection with no second use (`CLAUDE.md` golden rule 6). |
| `{day}` guard exceptions | Reuse `App\Exceptions\Cycle\RoutineHasNoActiveCycleException` / `CycleDayNotInActiveCycleException` (both `422`) — the same classes the export uses — rather than the Session-domain `409` siblings `TrainingSessionOpeningService` throws. | `{day}` arrives as a **route parameter** in this endpoint, exactly as in the export; the codebase's own convention (documented on both exception classes) is route-param `{day}` → `422` (Cycle domain), body-field `day` → `409` (Session domain). Matches the story's stated `422` for "rutina no activa" / "`{day}` inválido". |
| New "day already completed" guard | A new `App\Exceptions\Session\CycleDayAlreadyCompletedException` (`409`, default `DomainException` status), thrown from `CycleDayImportService`, checked before the file is parsed. Scoped to this endpoint only — `TrainingSessionOpeningService` (shared with `POST .../sessions`) is not touched. | This is a genuinely new invariant the story adds ("Rechaza con 409 si el `cycle_day` ya tiene una sesión `completed`") that no existing guard covers, and the story frames it as specific to the import flow, not a change to how sessions are opened in general. |
| Row-level error shape | Reuse the existing `VALIDATION_EXCEPTION` / `data.errors` envelope, with message-bag keys `row_<n>.<field>` (`<n>` = the spreadsheet row number the user would see, header = row 1), thrown via `ValidationException::withMessages(...)` directly from the Service. | `CLAUDE.md` reserves `data.errors` for the `VALIDATION_EXCEPTION` code specifically because it is the one place a `{field: [msg]}` map belongs; a row is not a form field in the traditional sense, but folding it into a dotted key (`row_3.weight_kg`) stays inside that one contract instead of inventing a second "errors" shape on a `DomainException`. |
| `set_number` source | Recomputed by `CycleDayImportService` as a running per-exercise counter over the filled rows in sheet order — the sheet's own `set_number` column (per-*prescription*, `1..sets`) is read-only context and ignored on import. | `SetLogCreateAction` requires the next contiguous number *per exercise, per session* — a fresh session's numbering has nothing to do with how many sets were prescribed. Computing it this way means `NonContiguousSetNumberException` can never fire from this path. |
| Bulk row validation | All filled rows are validated before anything is persisted; every invalid row is reported in one `ValidationException`, not just the first. | Matches "422 ... con detalle por fila" (plural) in the acceptance criteria, and standard bulk-import UX — one round trip to see every problem, not one row at a time. |
| Zero-filled-row workbook | No new guard. `SessionCloseAction`'s existing `SessionHasNoSetsException` (`422`) fires naturally when the action tries to close a session with nothing logged, rolling back the whole transaction. | Reuses an existing, already-tested invariant instead of duplicating it — an empty import is exactly "a session with no sets," which the system already refuses to complete. |
| Exercise match scope | Matched against `{day}`'s own `day_exercises` (via the `Exercise.slug` column), never the global catalog, and never creates a new `Exercise`. | The story is explicit: "el `exercise` debe corresponder a un `day_exercise` de ese `cycle_day`." An exercise that exists elsewhere in the catalog but isn't prescribed on this day is still an error — the import logs against the plan, it doesn't extend it. |
| Duplicate exercise prescribed twice on one day | The first `day_exercise` (by `order`) is used to resolve `exercise_id`; not treated as an error. | `SetLogCreateAction`'s own invariants key off `exercise_id`, not `day_exercise_id`, so which of the two identical-exercise prescriptions is referenced is immaterial. Rare edge case, not worth a new error path. |
| Where guards vs. writes live | `CycleDayImportService::handle()` does **all** reading, guarding and validating — no writes, no transaction. `TrainingSessionImportAction` opens its `DB::transaction` only around the three reused Actions (`TrainingSessionCreateAction`, `SetLogCreateAction` × N, `SessionCloseAction`). | Keeps a possibly-slow file read/validation pass outside any open DB transaction (`CLAUDE.md`: "the only layer that opens transactions ... is Actions"; a Service must not hold a transaction open for I/O that isn't a write). |
| Service/Action domain placement | `CycleDayImportService` lives in `Services/Cycle/` (mirrors `CycleDayExportService`'s placement and role: guard + gather, one cohesive job) even though it also queries `training_sessions` for the new guard. `TrainingSessionImportAction` lives in `Actions/Session/` (it creates/logs/completes a `TrainingSession`). `ImportCycleDayController` / `ImportCycleDayRequest` live under `Cycle` (route symmetry with the export endpoint). | `TrainingSessionOpeningService` (Session domain) already queries `Routine`/`Cycle`/`CycleDay` tables in one cohesive guard method — this codebase already accepts a Service crossing table boundaries when the guard is conceptually one job. Controller/Request domain follows the route's own domain, as `ExportCycleDayController` already establishes. |
| `App\Imports\Cycle\CycleDayImport` | A near-empty adapter: `implements Import, WithHeadingRow` only. All row interpretation lives in the Service. | Mirrors `CycleDayExport`'s "pure adapter, no guards, no queries" role on the write side. |
| No Scramble changes | None needed. | Unlike the export (a binary download needing a response-type override), this endpoint's response is a normal `TrainingSessionResource` — Scramble infers the `201` JSON shape and the `multipart/form-data` request body from the Form Request's `file` rule automatically, same as every other endpoint. |

---

## 10. Work Plan

| # | Task | Definition of Done |
|---|---|---|
| 1 | Create `app/Exceptions/Session/CycleDayAlreadyCompletedException.php` — `final`, extends `DomainException`, `$errorCode = 'CYCLE_DAY_ALREADY_COMPLETED'` (default `409` status, no override), default message. | `->statusCode() === 409`, `->errorCode() === 'CYCLE_DAY_ALREADY_COMPLETED'` (locked by TC-3). |
| 2 | Create `app/Imports/Cycle/CycleDayImport.php` — `final class CycleDayImport implements Import, WithHeadingRow {}`. No methods beyond what the interfaces require. | `phpstan` clean; `Excel::toCollection(new CycleDayImport, $file)` resolves headings by name. |
| 3 | Create `app/Services/Cycle/CycleDayImportService.php` — `final`. `handle(Routine $routine, CycleDay $day, UploadedFile $file): Collection<int, LogSetData>`: guards (§2.1 order 1–3) using `$routine->loadMissing('activeCycle')` / `$day->loadMissing('dayExercises.exercise')`; reads the file via `Excel::toCollection(new CycleDayImport, $file)->first()` inside a try/catch that rethrows as `ValidationException::withMessages(['file' => [...]])`; builds a slug-keyed map of the day's `day_exercises`; iterates rows (skip unfilled, validate filled — collecting `row_<n>.<field>` errors into one array), throws `ValidationException::withMessages($errors)` if any; otherwise returns the built `Collection<LogSetData>` with per-exercise contiguous `set_number`. | `phpstan` clean; behavior locked by TC-1–TC-15. |
| 4 | Write `tests/Unit/Cycle/CycleDayImportServiceTest.php` (TC-1–TC-15), each case its own `it()`; a small helper builds a real, readable `.xlsx` `UploadedFile` from a row array. | File green in isolation. |
| 5 | Create `app/Actions/Session/TrainingSessionImportAction.php` — `final`, constructor-promotes `CycleDayImportService`, `TrainingSessionCreateAction`, `SetLogCreateAction`, `SessionCloseAction`. `handle(User $user, Routine $routine, CycleDay $day, UploadedFile $file): TrainingSession`: `$rows = $this->import->handle($routine, $day, $file);` outside any transaction, then `DB::transaction(function () use (...) { open the session via TrainingSessionCreateAction with CreateTrainingSessionData::from(['day' => $day->uuid]); foreach ($rows as $row) log it via SetLogCreateAction; close via SessionCloseAction with CompleteSessionData::from([]); return the closed session; })`. | `phpstan` clean; behavior locked by TC-16–TC-17. |
| 6 | Write `tests/Unit/Session/TrainingSessionImportActionTest.php` (TC-16–TC-17). | File green. |
| 7 | Create `app/Http/Requests/Cycle/ImportCycleDayRequest.php` — `authorize()`: `$user !== null && $user->can('create', [TrainingSession::class, $this->route('routine')])`; `rules()`: `['file' => ['required', 'file', 'mimes:xlsx']]`. | Matches `StoreTrainingSessionRequest`'s authorization pattern; `phpstan` clean. |
| 8 | Create `app/Http/Controllers/Cycle/ImportCycleDayController.php` — invokable, `__invoke(ImportCycleDayRequest $request, Routine $routine, CycleDay $day, TrainingSessionImportAction $action): JsonResponse` → resolves `$user = $request->user()`, calls `$action->handle($user, $routine, $day, $request->file('file'))`, returns `TrainingSessionResource::make($session)->response()->setStatusCode(Response::HTTP_CREATED)` (mirrors `StoreTrainingSessionController` exactly). | `arch('cycle controllers are invokable')` passes. |
| 9 | Register the route in `routes/api.php`, immediately after `routines.cycle-days.export`: `Route::post('routines/{routine}/cycle-days/{day}/import', ImportCycleDayController::class)->whereUuid('routine')->whereUuid('day')->name('routines.cycle-days.import');` with a short comment; import the controller alphabetically. | `php artisan route:list` shows `routines.cycle-days.import`. |
| 10 | Write `tests/Feature/Cycle/ImportCycleDayTest.php` — TC-18 through TC-33, each its own `it()`, reusing the `.xlsx`-building helper from step 4 (moved to a shared test support location if warranted). `Queue::fake()` per test asserting `SessionAnalysisJob`. | `vendor/bin/pest tests/Feature/Cycle/ImportCycleDayTest.php` green. |
| 11 | Run the project checks: `vendor/bin/pint app tests routes/api.php --format agent`, then `vendor/bin/phpstan analyse`, then `vendor/bin/pest --filter=Import` plus `--filter=Cycle` plus `--filter=Session`, then the full `vendor/bin/pest`. | Pint clean, PHPStan level 6 clean, all tests green. No migration → no DB clone. |

---
