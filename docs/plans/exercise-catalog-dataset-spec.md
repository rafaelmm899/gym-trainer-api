# Exercise catalogue from exercises-dataset (no images)

> Derived from the Notion ticket "API: catálogo de ejercicios con datos de
> exercises-dataset (sin imágenes)" (Feature: Ciclos & generación IA · Should ·
> Repo: API · Order 960). The reconciliation of the existing production
> exercises is a **data migration** instead of the command the ticket describes
> (decided with the product owner), and the language of the instructions is
> negotiated with `Accept-Language`. Base contracts: `CLAUDE.md`,
> `docs/DER.md`, `docs/use-cases.md` (UC-21), `docs/operations.md`,
> `docs/plans/keep-cycle-exercises-spec.md` (cycle N+1 only progresses).

## 1. Context

**Kind:** Brownfield Feature

**Stack:** PHP 8.5 · Laravel 13 · PostgreSQL 17 (runtime) / SQLite `:memory:`
(tests) · Pest 4 · `laravel/ai` (structured-output agents) · `spatie/laravel-data`
(DTOs) · Pint · Larastan level 6. Everything runs in Docker.

**Problem statement:** The `exercises` table stores only `name`, `slug`,
`primary_muscle_group` and `created_by_ai`, and the AI invents the names
(`ExerciseCatalogService::resolve()` slugifies the AI's free text and inserts the
row). The product needs a closed catalogue where each exercise carries its
equipment, target muscle, secondary muscles and step-by-step instructions in
Spanish and English, taken from
[hasaneyldrm/exercises-dataset](https://github.com/hasaneyldrm/exercises-dataset)
pinned at commit `7455efae41b330c265e7cd4b78dfa848e7ce5ebd` (2026-07-16, 1,324
records). The dataset's code, structure, instructions and translations are MIT.
Its images and GIFs belong to Gym visual and are **not** imported, copied or
served by this work.

The 75 exercises already in production (823 logged sets, all created by the AI)
must keep their `id` and `uuid` and be reconciled with the dataset: 56 are
enriched in place, 11 duplicates are merged into another exercise, 6 have no
dataset equivalent and are written by hand, and 2 are left untouched. The
mapping was prepared from a copy of production taken on 2026-11-06 and approved
by the product owner. Eleven existing names contain a non-breaking hyphen
(U+2011), which `Str::ascii()` deletes, so their slug loses the hyphen
(`pullups`) and already produced one duplicate; the slug rule is fixed here.

**In scope:**
- Migration adding to `exercises`: `external_id` (unique), `equipment`,
  `target_muscle`, `secondary_muscles` (JSON), `instructions` (JSON per locale)
  and `media_url` (always null in this work).
- `MuscleGroup` gains `Forearms` and `Cardio` (12 cases) and a fixed mapping from
  the dataset's 19 `target` values; the planner uses all 12 groups.
- One slug rule (`Exercise::slugFor()`) that normalises Unicode hyphens before
  `Str::ascii()`, used by the catalogue service, the importer and the day
  XLSX import.
- Artisan command `exercises:import` (idempotent) that loads the 1,324 dataset
  records plus the 6 hand-written exercises from
  `database/catalog/manual_exercises.json`.
- A **data migration** that reconciles the existing production exercises from
  `database/catalog/exercise-reconciliation.json`: assigns `external_id`, merges
  the 11 duplicates (repointing `day_exercises`, `set_logs` and
  `exercise_recommendations`), and repairs slugs. All or nothing.
- `ExerciseCatalogService::resolve()` refuses to create an exercise without a
  valid muscle group (new `DomainException`).
- `CyclePlannerAgent` and `CyclePlannerService`: `primary_muscle_group` becomes
  required for AI-chosen exercises.
- `GET /api/v1/exercises` returns the new fields (without instructions); new
  `GET /api/v1/exercises/{exercise}` returns them plus the instructions in the
  language of the `Accept-Language` header (`en` or `es`, `en` by default).
- `THIRD_PARTY_NOTICES.md` with the dataset's MIT notice; runbook in
  `docs/operations.md`; `docs/DER.md` and `docs/use-cases.md` updated.
- Tests, including a fixture that reproduces the 75 production exercises.

**Out of scope:**
- Images, GIFs and `media_url` values (a separate story; the dataset media is
  © Gym visual and is never copied to the repo or a bucket).
- The planner choosing **only** from the catalogue (it still names exercises
  freely; a follow-up story).
- A user language setting; only the per-request `Accept-Language` header.
- Filtering the catalogue: all 1,324 records are imported, including stretches
  and yoga (owner decision 2026-11-15). No `plannable` flag.
- A reconciliation command or a `--dry-run` (replaced by the migration and a
  rehearsal on a copy of production, section 10).
- New equipment/muscle filters on `GET /exercises`, translated exercise names,
  exercise images, WODs, wger or any other source.
- Changing the name, slug or muscle group of an existing exercise through the
  importer (only the reconciliation migration touches slugs).

---

## 2. API Surface

### 2.1 REST

| Method | Path | Auth | Request | Response | Status codes |
|---|---|---|---|---|---|
| GET | `/api/v1/exercises` | `auth:sanctum`, no Policy (global read-only catalogue) | query `q`, `muscle_group` (unchanged) | `{ "data": [ExerciseResource] }` (max 50, by name) | 200, 401 |
| GET | `/api/v1/exercises/{exercise}` | `auth:sanctum`, no Policy | path `exercise` = exercise uuid; header `Accept-Language` | `{ "data": ExerciseDetailResource }` with `Vary: Accept-Language` | 200, 401, 404 |

`ExerciseResource` (also nested in recommendations and set logs, so it stays
small):

```json
{
  "id": "uuid",
  "name": "Barbell Bench Press",
  "slug": "barbell-bench-press",
  "primary_muscle_group": "chest",
  "equipment": "barbell",
  "target_muscle": "pectorals",
  "secondary_muscles": ["triceps", "shoulders"],
  "media_url": null
}
```

The prescribed exercises of a cycle day (`DayExerciseResource`, returned by
routines, cycles and sessions) also gain `exercise_id`, the catalogue uuid of the
exercise, so clients open `GET /exercises/{exercise}` and
`GET /exercises/{exercise}/history` by id instead of searching by name. Their own
`id` stays the uuid of the prescription. The change is additive.

`ExerciseDetailResource` adds `"instructions": ["step 1", "step 2", "..."]`: the
steps in the negotiated language. Language rule: the best match of
`Accept-Language` among `config('catalog.locales')` (`en`, `es`; a regional tag
such as `es-AR` matches `es`); no header or no match returns `en`; a row without
steps in the chosen language falls back to `en`; a row with no instructions
returns `[]`. Legacy exercises not yet enriched return `equipment: null`,
`target_muscle: null`, `secondary_muscles: []`, `instructions: []`. The internal
`id` and `created_by_ai` are never exposed.

### 2.2 CLI

| Command | Arguments | Flags | Description |
|---|---|---|---|
| `exercises:import` | — | `--path=` (local JSON file; default: download `config('catalog.dataset.url')`) | Loads the dataset records and the hand-written exercises. Idempotent. Prints created, updated, unchanged and rejected counts, the rejected rows with the reason, and every name whose encoding was repaired. Exit code 1 if any row was rejected or the source is unreadable; the valid rows are still applied |

The reconciliation of existing exercises is not a command: it is the data
migration `2026_11_23_120100_reconcile_existing_exercises` (section 4.1).

### 2.3 Events

Not applicable — no events.

---

## 3. UI

### 3.1 Pages

Not applicable — no pages affected (API repository; showing equipment and
instructions in the exercise detail is tracked in the SPA repository).

### 3.2 Components

Not applicable — no components affected.

---

## 4. Database

### 4.1 Schema changes

| Table | Action | Details |
|---|---|---|
| `exercises` | Add columns (migration `2026_11_23_120000_add_catalogue_columns_to_exercises_table`) | `external_id` string(8) nullable **unique** (dataset id such as `"0025"`; several NULLs allowed); `equipment` string nullable (raw dataset value, e.g. `barbell`); `target_muscle` string nullable (raw dataset `target`, e.g. `pectorals`); `secondary_muscles` json nullable (list of strings); `instructions` json nullable (`{"en": [steps], "es": [steps]}`); `media_url` string nullable. Every column is nullable, so the previous web revision keeps working while the migration runs ahead of the deploy. Slug stays unique |
| `exercises` | Data migration `2026_11_23_120100_reconcile_existing_exercises` | Reads `database/catalog/exercise-reconciliation.json` (75 entries: `id`, `name`, `action` = `enrich` / `merge` / `manual` / `untouched`, `external_id`, `merge_into_id`). Self-contained (query builder only, no models or services). In one transaction: (1) if no entry matches an existing row by `id` and `name` (empty or development database), skip steps 2 to 5; (2) if some match but any entry does not, throw and list them (rolls back); (3) for each `merge` pair, throw if a session has sets of both exercises, if a `(user, routine)` has a recommendation for both, or if a cycle day prescribes both; (4) repoint `day_exercises`, `set_logs` and `exercise_recommendations` of each merged exercise to its winner and delete it; (5) set `external_id` on the 56 `enrich` rows; (6) always, recompute the slug of every exercise whose name contains a Unicode hyphen (U+2010 to U+2015) with the new rule and throw if the recomputed slug is already taken. Other slugs are never recomputed, so a database where the importer already ran (slugs with an `-<external_id>` suffix) is not disturbed. `down()` is a documented no-op (the deleted rows cannot be restored; the runbook requires a backup) |

The model gets the new `#[Fillable]` fields, `secondary_muscles` and
`instructions` cast to `array`, the `@method` PHPDoc for `slugFor()`, and a
refreshed PHPDoc block (`ide-helper:models --write`). `ExerciseFactory` keeps
choosing a random `MuscleGroup`, which now includes the two new cases.

### 4.2 Seeds

Not applicable — no seeders. The catalogue is loaded by `exercises:import` from
the dataset and from `database/catalog/manual_exercises.json` (6 entries:
Barbell Hip Thrust, Cable Woodchopper, Bulgarian Split Squat, Plank, Face Pull,
Alternating Dumbbell Row), each with `slug`, `name`, `equipment`, `target`,
`secondary_muscles` and English/Spanish `instruction_steps`.

---

## 5. Auth & Authorization

### 5.1 Authentication

**Method:** Session (Sanctum SPA, cookie + CSRF), unchanged. The new route sits
in the existing `auth:sanctum` group. The import command and the migration run
outside HTTP, with no user.

### 5.2 Authorization

| Role | Permissions |
|---|---|
| Authenticated user | Read the catalogue (list and detail). The catalogue is global and owned by nobody, so there is no Policy, as for `exercises.list` today |
| Operator (deploy) | Runs migrations and `exercises:import` |

---

## 6. Configuration

New `config/catalog.php`:

| Key | Value | Purpose |
|---|---|---|
| `dataset.url` | `env('CATALOG_DATASET_URL', '<raw URL of data/exercises.json at commit 7455efa>')` | Where `exercises:import` downloads the dataset |
| `dataset.timeout` | `120` (seconds) | Download timeout (the file is about 17 MB) |
| `locales` | `['en', 'es']` | Instruction languages stored and negotiated; the first is the fallback |
| `name_fixes` | `['в°' => '°']` | Known encoding damage in dataset names |

**Environment variables:**
| Variable | Value / Source | Purpose |
|---|---|---|
| `CATALOG_DATASET_URL` | optional; default is the pinned commit URL | Override the dataset location (mirror, newer commit) |

No other configuration changes.

---

## 7. Current vs New Behavior

| Behavior | Current | New |
|---|---|---|
| Exercise data | name, slug, muscle group, `created_by_ai` | plus `external_id`, equipment, target muscle, secondary muscles, instructions in `en` and `es` |
| Catalogue content | the AI's invented names (75 in production) | 1,324 dataset exercises + 6 hand-written + the reconciled existing ones; the planner may still add AI names |
| `MuscleGroup` | 10 cases | 12 (`forearms`, `cardio` added); the planner prompt and schema list all 12 |
| Dataset `target` to group | none | fixed table: abs→core; pectorals, serratus anterior→chest; delts→shoulders; lats, upper back, traps, spine, levator scapulae→back; adductors, abductors→glutes; forearms→forearms; cardiovascular system→cardio; biceps, triceps, quads, hamstrings, glutes, calves→same name |
| Slug of `Pull‑Ups` (U+2011) | `pullups` | `pull-ups`; `Pull‑Ups` and `Pull-Ups` give the same slug everywhere (catalogue, importer, XLSX import) |
| `resolve()` for a new exercise with no valid muscle group | creates it with `null` | throws `ExerciseMuscleGroupRequiredException` (409) and inserts nothing; reusing an existing slug without a hint still works |
| First-cycle AI plan | `primary_muscle_group` nullable | required, one of the 12 groups; a missing or unknown value is a `CycleGenerationException` (502, nothing saved) |
| Cycle N+1 clones | group copied from the row | unchanged (a legacy row with a null group still clones) |
| Existing exercises in production | 75 rows, 11 duplicates, 11 broken slugs | 64 rows, duplicates merged, slugs repaired, history repointed, ids and uuids of winners kept |
| `GET /exercises` | `id`, `name`, `slug`, `primary_muscle_group` | plus `equipment`, `target_muscle`, `secondary_muscles`, `media_url` |
| `GET /exercises/{exercise}` | does not exist | detail with `instructions` in the `Accept-Language` language |
| Prescribed exercise payload | `id` (prescription), `name`, prescription | plus `exercise_id` (catalogue uuid); names are no longer needed to find the exercise |
| Day workbook (export / import) | One row per set; the import finds the exercise by the name in the `exercise` column | The export adds a hidden last column `exercise_id` (catalogue uuid). The import matches by it, and the id wins over the name; an id that is not prescribed on that day is rejected (`Unknown exercise for this day.`); a file without the column (exported before) still matches by name |
| Deploy | `migrate` | `migrate` (schema + reconciliation, automatic), then `docker/production/deploy.sh` runs `exercises:import` as a Cloud Run job step; a failed import never blocks the deploy, the script prints the command to rerun it |

---

## 8. Test Cases

*All test cases run with Pest 4 on SQLite `:memory:`, with no network. The
dataset tests use `tests/Fixtures/exercises-dataset-sample.json` (12 records:
plain rows, a 19-value `target` coverage set, a mojibake name, a duplicate name
pair, a row with an unknown `target`, a row without Spanish steps).*

**Muscle groups**

**TC-1:** the enum has 12 cases including `forearms` and `cardio`
- **Given:** `MuscleGroup::cases()`.
- **When:** listing values.
- **Expect:** 12 values; `forearms` and `cardio` present.

**TC-2:** all 19 dataset targets map to a group
- **Given:** the 19 `target` values of the dataset.
- **When:** `MuscleGroup::fromDatasetTarget($target)` for each.
- **Expect:** forearms→forearms, cardiovascular system→cardio, traps / lats / upper back / spine / levator scapulae→back, adductors / abductors→glutes, serratus anterior / pectorals→chest, abs→core, delts→shoulders, biceps, triceps, quads, hamstrings, glutes, calves→same name; none returns null.

**TC-3:** an unknown target returns null
- **Given:** `'xyz'`.
- **When:** `fromDatasetTarget('xyz')`.
- **Expect:** `null`.

**Slug rule**

**TC-4:** Unicode hyphens give the same slug
- **Given:** `Pull‑Ups` (U+2011) and `Pull-Ups`.
- **When:** `Exercise::slugFor()` on each.
- **Expect:** both `pull-ups`; also true for U+2010 to U+2015.

**TC-5:** `resolve()` reuses the row for either spelling
- **Given:** an exercise with slug `pull-ups`.
- **When:** `resolve('Pull‑Ups', 'back')`.
- **Expect:** the same row, no new insert.

**TC-6:** the day XLSX import matches names with a non-breaking hyphen
- **Given:** an active-cycle day prescribing `Pull-Ups`, and a sheet whose exercise cell is `Pull‑Ups`.
- **When:** importing the sheet.
- **Expect:** the row is matched and the set logged.

**resolve() hardening**

**TC-7:** creation without a valid group is refused
- **Given:** a new name with no hint, and a new name with hint `'xyz'`.
- **When:** `resolve()`.
- **Expect:** `ExerciseMuscleGroupRequiredException` (`EXERCISE_MUSCLE_GROUP_REQUIRED`, 409); no row inserted.

**TC-8:** an existing exercise is reused without a hint
- **Given:** an existing slug.
- **When:** `resolve($name, null)`.
- **Expect:** the existing row, no exception.

**Planner**

**TC-9:** the first-cycle schema requires the group
- **Given:** `CyclePlannerAgent::schema()`.
- **When:** reading `primary_muscle_group` of an exercise.
- **Expect:** required, not nullable, enum of the 12 values; `instructions()` lists the 12 groups and no longer says it may be null.

**TC-10:** a plan with a missing, null or unknown group is rejected
- **Given:** a fake agent response whose first exercise has no group, `null`, or `'xyz'`.
- **When:** creating a routine.
- **Expect:** `CycleGenerationException`, HTTP 502 `AI_GENERATION_FAILED`, no routine or exercise persisted.

**TC-11:** a plan using `cardio` or `forearms` is accepted
- **Given:** a valid fake plan with a day focused on `forearms` and an exercise in `cardio`.
- **When:** creating a routine.
- **Expect:** 201.

**TC-12:** cycle N+1 still clones a legacy row with no group
- **Given:** an active cycle whose exercise has `primary_muscle_group = null`.
- **When:** generating the next cycle.
- **Expect:** the cycle is cloned without error.

**Importer**

**TC-13:** a dataset record becomes an exercise
- **Given:** the fixture and an empty table.
- **When:** `exercises:import --path=<fixture>`.
- **Expect:** every valid record is created with `external_id`, name in title case (`Str::title`), slug, `equipment`, `target_muscle`, `secondary_muscles`, `primary_muscle_group` from the target, `instructions` containing only `en` and `es` step lists, `created_by_ai = false`, `media_url = null`.

**TC-14:** the import is idempotent
- **Given:** TC-13 already run.
- **When:** running it again.
- **Expect:** row count, ids and uuids unchanged; the report shows 0 created.

**TC-15:** an unknown target rejects only that row
- **Given:** the fixture row with an unknown `target`.
- **When:** importing.
- **Expect:** exit code 1; the row is listed with its id and target and not created; all other rows are created.

**TC-16:** names with the same text do not collide
- **Given:** two records with the same name and different ids.
- **When:** importing.
- **Expect:** two rows; the lower id keeps the base slug and the other gets `-<external_id>`; neither fails.

**TC-17:** a slug already used by another row gets a suffix
- **Given:** an existing non-dataset exercise whose slug equals a dataset record's slug.
- **When:** importing.
- **Expect:** the new row is created with the suffixed slug; the existing row is untouched.

**TC-18:** damaged names are repaired and reported
- **Given:** a record named `sled 45в° leg press`.
- **When:** importing.
- **Expect:** name `Sled 45° Leg Press`, slug `sled-45-leg-press`; the name appears in the report's repaired list.

**TC-19:** an existing row with the same `external_id` is refreshed, not renamed
- **Given:** an exercise with `external_id = "0025"`, a custom name, a non-null muscle group, and the dataset record `0025` with a different name and target.
- **When:** importing.
- **Expect:** equipment, target, secondary muscles and instructions are updated; `id`, `uuid`, `name`, `slug`, `primary_muscle_group` and `created_by_ai` are unchanged; a null `primary_muscle_group` is filled from the target.

**TC-20:** a row without Spanish steps keeps only English
- **Given:** a record without `es` steps.
- **When:** importing, then `GET /exercises/{id}` with `Accept-Language: es`.
- **Expect:** `instructions` has only `en` and the response returns the English steps.

**TC-21:** the source is validated
- **Given:** a path that does not exist, a file that is not a JSON array, and a record missing `instruction_steps`.
- **When:** importing.
- **Expect:** the first two exit 1 and write nothing; the third rejects only that record.

**TC-22:** the default source is downloaded
- **Given:** `Http::fake()` returning the fixture for `config('catalog.dataset.url')`, no `--path`.
- **When:** importing.
- **Expect:** the fixture records are created; no real network call.

**TC-23:** the hand-written exercises are created
- **Given:** an empty table.
- **When:** importing.
- **Expect:** 6 rows from `manual_exercises.json` with `external_id = null`, `created_by_ai = false`, group mapped from their target, English and Spanish steps.

**TC-24:** a hand-written exercise enriches an existing row by slug
- **Given:** an existing `Plank` row (slug `plank`, group `core`) with its history.
- **When:** importing.
- **Expect:** the row keeps `id`, `uuid`, name and group and gains equipment, target, secondary muscles and instructions; no second row.

**Reconciliation migration** (each test builds the 75 production rows from `exercise-reconciliation.json` with their ids and names, plus sets, prescriptions and recommendations, then runs the migration class)

**TC-25:** enrich rows get their `external_id`
- **Given:** the 75 rows.
- **When:** running the migration.
- **Expect:** the 56 `enrich` rows have the mapped `external_id`; the 6 `manual` and 2 `untouched` rows have none; ids, uuids, names and muscle groups unchanged.

**TC-26:** merges keep all history
- **Given:** the 75 rows with `day_exercises`, `set_logs` and `exercise_recommendations` on the 11 merged exercises.
- **When:** running the migration.
- **Expect:** 64 rows remain; the 11 merged rows are gone; every `day_exercises`, `set_logs` and `exercise_recommendations` row of a merged exercise now points to its winner; totals of those three tables are unchanged.

**TC-27:** slugs are repaired
- **Given:** the 11 rows with a U+2011 name and the slug without hyphen.
- **When:** running the migration.
- **Expect:** `Pull‑Ups` has `pull-ups`; `One‑Arm Dumbbell Row` (id 44) has `one-arm-dumbbell-row` after the duplicate (id 62) is merged away.

**TC-28:** a set collision aborts everything
- **Given:** a session with sets of a loser and of its winner.
- **When:** running the migration.
- **Expect:** it throws naming the pair; no row changed (rolled back).

**TC-29:** a recommendation collision aborts everything
- **Given:** a `(user, routine)` with a recommendation for the loser and for its winner.
- **When:** running the migration.
- **Expect:** it throws; nothing changed.

**TC-30:** a cycle day prescribing both exercises aborts everything
- **Given:** a cycle day with prescriptions of the loser and of the winner.
- **When:** running the migration.
- **Expect:** it throws; nothing changed.

**TC-31:** a partly matching database aborts
- **Given:** the 75 rows but one has a different name.
- **When:** running the migration.
- **Expect:** it throws listing the entry; nothing changed.

**TC-32:** a database that does not look like production is left alone
- **Given:** a database with unrelated exercises, one whose name has a non-breaking hyphen and a stale slug, and one with an importer slug suffix (`barbell-seated-calf-raise-1371`).
- **When:** running the migration.
- **Expect:** no merge, no `external_id`, no exception; only the hyphenated name gets its slug repaired (`pull-ups`); the other slugs are unchanged.

**TC-33:** manual and untouched rows keep their identity
- **Given:** the 75 rows, including the 6 `manual` and 2 `untouched` entries.
- **When:** running the migration.
- **Expect:** those 8 rows keep `id`, `uuid`, `name` and `primary_muscle_group`; only a U+2011 slug is repaired, and `external_id` stays null.

**TC-34:** a recomputed slug collision aborts
- **Given:** a row whose repaired slug equals the slug of another surviving row.
- **When:** running the migration.
- **Expect:** it throws; nothing changed.

**API**

**TC-35:** the list exposes the new fields without instructions
- **Given:** an enriched and a legacy exercise.
- **When:** `GET /api/v1/exercises`.
- **Expect:** `equipment`, `target_muscle`, `secondary_muscles`, `media_url` present; the legacy row has `null`, `null`, `[]`, `null`; no `instructions`, no internal `id`, no `created_by_ai`.

**TC-36:** the detail returns instructions in the requested language
- **Given:** an exercise with `en` and `es` steps.
- **When:** `GET /api/v1/exercises/{uuid}` with `Accept-Language: es`, `es-AR`, `en`, `fr`, and no header.
- **Expect:** `es` and `es-AR` return the Spanish steps; the other three return the English steps; `Vary: Accept-Language` is present.

**TC-37:** the detail falls back and degrades
- **Given:** an exercise with only `en` steps, and a legacy one with none.
- **When:** requesting with `Accept-Language: es`.
- **Expect:** English steps for the first; `instructions: []` for the second.

**TC-38:** detail access rules
- **Given:** no session, and an unknown uuid.
- **When:** `GET /api/v1/exercises/{uuid}`.
- **Expect:** 401 for the first, 404 `NOT_FOUND_EXCEPTION` for the second.

**TC-39:** nested exercises stay small
- **Given:** a routine recommendation and a set log with an exercise.
- **When:** reading them.
- **Expect:** the nested exercise has the `ExerciseResource` fields and no `instructions`.

**Licence**

**TC-40:** the third-party notice is present
- **Given:** the repository.
- **When:** reading `THIRD_PARTY_NOTICES.md`.
- **Expect:** it contains the dataset's MIT licence text, the copyright holder and a statement that the dataset media is not included.

---

## 9. Technical Decisions

| Decision area | What was decided | Why |
|---|---|---|
| Reconciliation of existing rows | A data migration reading a committed JSON mapping, instead of the command in the ticket | It runs by itself on deploy, so there is no CSV to deliver to Cloud Run; the mapping holds no user data |
| Safety of the migration | One transaction; abort if the database half-matches the mapping or any merge pair collides; no-op on databases that do not look like production | A migration has no dry-run; partial application is worse than failing the deploy |
| Rehearsal instead of dry-run | Run the migration on a clone of `gym_trainer_prod_copy` before deploying; take a backup first | Merges delete rows and `down()` cannot restore them. Evidence on the 2026-11-06 copy: 0 colliding sessions, 0 colliding recommendations, 0 cycle days with both exercises; 25 `day_exercises`, 1 set and 1 recommendation to repoint |
| Migration independence | Query builder only, no models, services or helpers from `app/` | A migration must keep working when app classes change |
| Order in production | Migrate (schema, reconciliation) first, then `exercises:import` | The migration assigns `external_id` to existing rows; the importer then refreshes them by `external_id` instead of creating duplicates |
| Importer key | `external_id` only; never adopts a row by slug | A slug match can disagree with the approved mapping (for example id 43 shares its slug with dataset 0334 but is merged into 4) |
| Importer updates | Refreshes equipment, target, secondary muscles and instructions; never changes `name`, `slug` or a non-null `primary_muscle_group` | History and user-visible names must not move; the dataset marks squats and deadlifts as `glutes`, which would change the recovery rule for 14 existing exercises |
| Dataset source | Download the pinned commit URL by default, `--path` to override | The 17 MB file is not committed; production jobs have no local file; pinning makes it reproducible |
| Slug collisions | Lower `external_id` keeps the base slug, others get `-<external_id>`; the slug is set on creation only | Six names repeat in the dataset; `slug` is unique and used to match XLSX rows |
| Names | `Str::title`; known encoding damage fixed via `config('catalog.name_fixes')`; other non-ASCII names are reported, not altered | Matches the 75 existing names (owner choice); the only damage found is `в°` in 4 names |
| Equipment, target, secondary muscles | Stored as the raw dataset strings; no `Equipment` enum | Nothing filters or reasons on them yet; avoids a 28-value enum |
| Instructions | Only `instruction_steps` for the configured locales, as lists | The ticket asks for step-by-step instructions in `es` and `en`; storing 10 languages is unused weight |
| Language negotiation | `Accept-Language` header (HTTP standard), `$request->getPreferredLanguage(config('catalog.locales'))`, fallback `en`, `Vary` header | Owner decision; no user locale exists yet |
| Detail vs list payload | `ExerciseResource` stays lean; `ExerciseDetailResource` adds instructions | `ExerciseResource` is nested in recommendations and set logs |
| Detail endpoint shape | `ShowExerciseController` with a plain Request, no Action, no Policy, like `ListExercisesController` | Read-only global catalogue; matches the sibling |
| `MuscleGroup` | 12 cases; `fromDatasetTarget()` on the enum; unknown target rejects the row | Owner decision 2026-11-15; a pure lookup needs no class |
| Slug helper | `Exercise::slugFor()` (static, documented with `@method`) | One rule shared by `ExerciseCatalogService`, `CycleDayImport`, `CycleDayImportService` and the importer. The reconciliation migration carries its own copy of the rule, because it must not depend on `app/` |
| Planner requirement | The group is required only for AI-chosen exercises (`exerciseId === null`); clones may carry a legacy null | Closes the invention gap without breaking cycle N+1 for old rows |
| Hand-written exercises | `database/catalog/manual_exercises.json`, applied by the importer by slug; 6 entries | No dataset equivalent; content approved by the owner |
| Layering | `ExerciseImportCommand` calls `ExerciseImportAction`; `ExerciseDatasetService` reads, validates and maps rows; the Action owns the transaction | CLAUDE.md: Actions orchestrate and open transactions; Services hold the rules |
| Media | `media_url` column exists and is exposed as `null`; nothing is copied | The images story only needs data |
| Licence | `THIRD_PARTY_NOTICES.md` with the MIT text | The MIT licence requires the notice in copies |
| Tests | No network; fixtures; tests that use random factory groups are looped before being called green | `ExerciseFactory` and `CycleDayFactory` pick enum cases at random and the enum grows to 12 |

---

## 10. Work Plan

| # | Task | Definition of Done |
|---|---|---|
| 1 | Schema migration, `Exercise` model (fillable, casts, PHPDoc, `slugFor()`), factory, `docs/DER.md` | Migration runs on SQLite and PostgreSQL; `ide-helper` produces no diff; Pint and PHPStan clean |
| 2 | `MuscleGroup` to 12 cases with `fromDatasetTarget()` | TC-1, TC-2, TC-3 pass; the suite is run several times to catch random factory failures |
| 3 | Use `slugFor()` in `ExerciseCatalogService`, `CycleDayImport` and `CycleDayImportService`; harden `resolve()` with `ExerciseMuscleGroupRequiredException`; update the existing catalogue tests | TC-4 to TC-8 pass; existing import/export tests still pass |
| 4 | Planner: required group in the schema, the instructions and `mapExercise()` | TC-9 to TC-12 pass |
| 5 | `config/catalog.php`, `ExerciseImportData`, `ExerciseDatasetService`, the 12-record fixture | Service unit tests cover mapping, validation and repair |
| 6 | `ExerciseImportAction` and `exercises:import` | TC-13 to TC-22 pass |
| 7 | `database/catalog/manual_exercises.json` (the 6 approved entries) and its import | TC-23, TC-24 pass |
| 8 | `database/catalog/exercise-reconciliation.json` (75 entries) and the data migration | TC-25 to TC-34 pass |
| 9 | `ExerciseResource` fields, `ExerciseDetailResource`, `ShowExerciseController`, route `exercises.show`, `Vary` header, Scramble description of the header | TC-35 to TC-39 pass; `GET /docs/api.json` lists the new endpoint and fields |
| 10 | `THIRD_PARTY_NOTICES.md`, runbook in `docs/operations.md` (backup, rehearsal, migrate, `exercises:import` job), `docs/use-cases.md` (UC-21 and detail) | TC-40 passes; docs describe the order and the rehearsal |
| 11 | Rehearsal on a clone of `gym_trainer_prod_copy`, then `composer check` | The clone ends with 64 exercises, 823 sets, 41 recommendations and 315 `day_exercises` intact, the 11 merges applied and `exercises:import` run on it; Pint, PHPStan and Pest pass, new tests looped |
