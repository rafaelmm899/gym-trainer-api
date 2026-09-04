# Ver las recomendaciones vigentes de mi rutina

## 1. Context

**Kind:** Brownfield Feature

**Stack:** PHP 8.5, Laravel 13, PostgreSQL 17, Pest 4.

**Problem statement:** The user has no way to see the per-exercise targets
the AI analyst left after their last completed session for each exercise
still trained in their routine (weight, sets/reps, action, explanation).
`exercise_recommendations` rows are written today (PR #21, "Recibir
recomendaciones al cerrar el día de entrenamiento") but nothing reads them
back. This adds the read endpoint (Notion Order 140, Feature "Recomendaciones
IA", Priority Must, MVP yes).

**In scope:**
- `GET /api/v1/routines/{routine}/recommendations` — lists the caller's
  routine's current recommendations, one per exercise still present in the
  routine's current cycle.
- A recommendation for an exercise no longer in the current cycle (dropped in
  a later cycle) is excluded — decided with the user 2026-09-04.
- Reusing `RoutinePolicy::view` (already gates `routines.show`) so only the
  routine's owner can list its recommendations.

**Out of scope:**
- `confidence` field — no column exists; dropped by product decision in PR
  #21 and reaffirmed in this ticket's discussion (2026-09-04): the free-text
  `explanation` already conveys how much evidence backed the suggestion.
- Excluding recommendations "already used to generate a cycle" — no
  consumer exists yet (`GenerateCycleJob::handle()` is an empty placeholder,
  Order 150 not implemented). Dropped from this ticket's scope; Order 150
  adds whatever field/logic it needs when built.
- Any change to how recommendations are written (`SessionAnalyzeAction`,
  `SessionAnalystService`, the AI agent) — read-only endpoint, no writes.
- Pagination — a routine's current cycle has a small, bounded number of
  exercises; not needed.

---

## 2. API Surface

### 2.1 REST

| Method | Path | Auth | Request | Response | Status codes |
|---|---|---|---|---|---|
| GET | `/api/v1/routines/{routine}/recommendations` | `auth:sanctum` + `RoutinePolicy::view` | — (route-model binding by `routine` uuid) | `ExerciseRecommendationResource` collection (`data: [...]`) | 200, 401, 403, 404 |

Response item shape (`ExerciseRecommendationResource`):

```json
{
  "id": "<recommendation-uuid>",
  "exercise": {
    "id": "<exercise-uuid>",
    "name": "Sentadilla",
    "slug": "sentadilla",
    "primary_muscle_group": "quads"
  },
  "target_weight_kg": 82.5,
  "target_sets": 4,
  "target_rep_min": 6,
  "target_rep_max": 8,
  "action": "advance_weight",
  "explanation": "..."
}
```

`action` is one of `RecommendationAction`'s cases: `advance_weight`, `hold`,
`add_reps`, `add_set`, `deload`, `technique_focus`.

### 2.2 CLI

Not applicable — no CLI commands.

### 2.3 Events

Not applicable — no events.

---

## 3. UI

### 3.1 Pages

Not applicable — no pages affected (API-only repo).

### 3.2 Components

Not applicable — no components affected.

---

## 4. Database

### 4.1 Schema changes

Not applicable — no schema changes. Reuses `exercise_recommendations`
(PR #21) and existing `cycles` / `cycle_days` / `day_exercises` tables to
compute the current cycle's exercise set; no new columns, tables, or model
relations are added.

### 4.2 Seeds

Not applicable — no seeds.

---

## 5. Auth & Authorization

### 5.1 Authentication

**Method:** Sanctum (`auth:sanctum`), same as every other `/api/v1` data
route.

### 5.2 Authorization

| Role | Permissions |
|---|---|
| Routine owner | Can list their own routine's current recommendations. |
| Any other authenticated user | Denied with 403 (`RoutinePolicy::view` returns `false` when `$routine->user_id !== $user->id`). |

No new Policy method — the route reuses `->can('view', 'routine')`, exactly
as `GET /api/v1/routines/{routine}` already does.

---

## 6. Configuration

Not applicable — no configuration changes.

---

## 7. Current vs New Behavior

Not applicable — this is a new read-only endpoint; it does not change the
behavior of any existing route or process (recommendation writing via
`SessionAnalyzeAction` is untouched).

---

## 8. Test Cases

**TC-1:** lists the current recommendations for the caller's routine
- **Given:** a routine owned by the caller with a current cycle whose day(s)
  include exercises A and B, and an `ExerciseRecommendation` row for each of
  A and B on that routine.
- **When:** `GET /api/v1/routines/{routine}/recommendations`.
- **Expect:** 200, `data` has 2 items, each with `id` (the recommendation's
  uuid), `exercise.id/name/slug/primary_muscle_group`, `target_weight_kg` as
  a float, `target_sets`, `target_rep_min`, `target_rep_max`, `action`
  (string enum value), `explanation`.

**TC-2:** excludes a recommendation for an exercise no longer in the current
cycle
- **Given:** a routine with a current cycle that no longer includes exercise
  C, but an `ExerciseRecommendation` row still exists for C (left over from
  an earlier cycle).
- **When:** `GET /api/v1/routines/{routine}/recommendations`.
- **Expect:** 200, the recommendation for C is not present in `data`.

**TC-3:** returns an empty list when the current cycle's exercises have no
recommendation yet
- **Given:** a routine with a current cycle whose exercises have never been
  analyzed (no `ExerciseRecommendation` rows).
- **When:** `GET /api/v1/routines/{routine}/recommendations`.
- **Expect:** 200, `data` is an empty array.

**TC-4:** orders recommendations alphabetically by exercise name
- **Given:** a routine with current-cycle recommendations for exercises
  named "Zancada" and "Sentadilla".
- **When:** `GET /api/v1/routines/{routine}/recommendations`.
- **Expect:** 200, `data` order is `["Sentadilla", "Zancada"]`.

**TC-5:** denies reading another user's routine recommendations
- **Given:** a routine owned by a different user.
- **When:** the caller requests `GET /api/v1/routines/{otherRoutine}/recommendations`.
- **Expect:** 403, `data.code` is `AUTHORIZATION_EXCEPTION`.

**TC-6:** returns 404 for an unknown routine uuid
- **Given:** no routine exists with the given uuid.
- **When:** `GET /api/v1/routines/{unknownUuid}/recommendations`.
- **Expect:** 404, `data.code` is `NOT_FOUND_EXCEPTION`.

**TC-7:** returns 404 for a non-uuid path segment
- **Given:** the `{routine}` segment is not a valid uuid.
- **When:** `GET /api/v1/routines/not-a-uuid/recommendations`.
- **Expect:** 404, `data.code` is `NOT_FOUND_EXCEPTION`.

**TC-8:** rejects an unauthenticated request
- **Given:** no authenticated user.
- **When:** `GET /api/v1/routines/{routine}/recommendations`.
- **Expect:** 401, `data.code` is `AUTHENTICATION_EXCEPTION`.

**TC-9:** renders the resource without triggering a lazy load
- **Given:** a routine with current-cycle recommendations.
- **When:** `GET /api/v1/routines/{routine}/recommendations`.
- **Expect:** 200, no `Illuminate\Database\LazyLoadingViolationException` is
  thrown (`Model::shouldBeStrict` is on outside production) — the `exercise`
  relation is eager-loaded by the Service.

---

## 9. Technical Decisions

| Decision area | What was decided | Why |
|---|---|---|
| `confidence` field | Not added. | No column exists; PR #21 dropped it on purpose, reaffirmed with the user in this ticket's discussion — `explanation` already conveys the AI's evidence level. |
| "Used to generate a cycle" filter | Dropped from this ticket. | No consumer exists (`GenerateCycleJob` is an empty placeholder, Order 150 not built); nothing could be excluded today. Order 150 adds the field/logic it needs. |
| Scope of "vigente" | A recommendation counts only if its exercise is in the routine's *current* cycle (`$routine->cycle`, the max-`sequence_number` one). | Decided with the user: a recommendation for an exercise dropped from the program in a later cycle should not still appear as "next time". |
| Layering | Controller → `RecommendationCatalogService` directly; no Form Request, no Action. | Matches the existing precedent for simple authenticated reads (`ShowRoutineController`, `ListRoutinesController`, `ListExercisesController` — none use a Form Request or an Action). A Service is used (not inline in the Controller) because the current-cycle filter is a business rule worth naming and unit-testing on its own, per `CLAUDE.md`'s Service definition. |
| Route & authorization | `->can('view', 'routine')` on the route, reusing `RoutinePolicy::view` — no new Policy method. | Identical ownership check already exists and is exercised by `routines.show`; adding a second method would duplicate it. |
| Exercise representation | Nested `ExerciseResource` (existing class) under `exercise`, via `whenLoaded('exercise')`. | Decided with the user: the client can identify/link the exercise by its uuid, not just its name. Reuses an existing Resource rather than duplicating exercise fields inline. |
| Ordering | Alphabetical by exercise name, ascending. | No ordering was specified by the ticket; matches the existing convention in `ListExercisesController` (`orderBy('name')`) for a simple, deterministic default. |
| Query shape | `DayExercise::whereHas('cycleDay', ...)` to collect the current cycle's exercise ids, then `ExerciseRecommendation::where('routine_id', $routine->id)->whereIn('exercise_id', ...)` with `exercise` eager-loaded — queried directly, no new `Routine` relation. | Avoids a hand-written join per `CLAUDE.md` conventions; two small Eloquent queries are simpler to read and test than a multi-table join. No `Routine::exerciseRecommendations()` relation is added since nothing besides this Service would use it — adding it would be unused indirection. |

---

## 10. Work Plan

| # | Task | Definition of Done |
|---|---|---|
| 1 | Create `app/Services/Recommendation/RecommendationCatalogService.php` with `listCurrentForRoutine(Routine $routine): Collection` — resolves the current cycle's exercise ids via `DayExercise::whereHas('cycleDay', fn ($q) => $q->where('cycle_id', $routine->cycle?->id))->pluck('exercise_id')`, then queries `ExerciseRecommendation::where('routine_id', $routine->id)->whereIn('exercise_id', $exerciseIds)`, ordered by exercise name, with `exercise` eager-loaded. | Method exists and is callable directly (no HTTP) so it can be unit-tested. |
| 2 | Write `tests/Unit/Recommendation/RecommendationCatalogServiceTest.php` covering the current-cycle filter in isolation: a recommendation for an exercise still in the current cycle is included, one for an exercise dropped in a later cycle is excluded, and a routine with no current cycle returns an empty collection. | `vendor/bin/pest --filter=RecommendationCatalogService` passes. |
| 3 | Create `app/Http/Resources/Recommendation/ExerciseRecommendationResource.php` — `id` (uuid), `exercise` (nested `ExerciseResource::make($this->whenLoaded('exercise'))`), `target_weight_kg` (cast to float), `target_sets`, `target_rep_min`, `target_rep_max`, `action` (`->value`), `explanation`. | Matches the JSON shape in §2.1; covered by TC-1. |
| 4 | Create `app/Http/Controllers/Recommendation/ListRoutineRecommendationsController.php` — invokable, `__invoke(Routine $routine, RecommendationCatalogService $service): AnonymousResourceCollection`, returns `ExerciseRecommendationResource::collection($service->listCurrentForRoutine($routine))`. | 3-line controller per `CLAUDE.md`; covered by TC-1–TC-9. |
| 5 | Add the route in `routes/api.php`: `Route::get('routines/{routine}/recommendations', ListRoutineRecommendationsController::class)->whereUuid('routine')->can('view', 'routine')->name('routines.recommendations.list');` placed next to the existing `routines.show` route, with a comment matching the file's existing style. | Route resolves; covered by all TCs. |
| 6 | Write `tests/Feature/Recommendation/ListRoutineRecommendationsTest.php` implementing TC-1 through TC-9, following the structure/style of `tests/Feature/Routine/ShowRoutineTest.php`. | `vendor/bin/pest --filter=ListRoutineRecommendations` passes. |
| 7 | Run the project's standard checks on touched files. | `vendor/bin/pint app/Services/Recommendation app/Http/Resources/Recommendation app/Http/Controllers/Recommendation routes/api.php tests/Unit/Recommendation tests/Feature/Recommendation --format agent`, `vendor/bin/phpstan analyse`, and `vendor/bin/pest --filter=Recommendation` all pass. |
