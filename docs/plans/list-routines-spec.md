# List routines — `GET /api/v1/routines` + `GET /api/v1/routines/{routine}`

> Derived from the Notion ticket "Listar mis rutinas" (Feature: Rutinas · MVP ·
> Must · Repo: API · Order 50) and the approved plan
> (`.claude/plans/tenemos-un-requerimiento-analizalo-cosmic-canyon.md`). Base
> contract: `docs/product-context.md` §2 / §3 / §6, `docs/plans/data-model.md`
> §`routines` + §Identificadores, `CLAUDE.md` "The pipeline",
> `docs/plans/create-routine-spec.md` (the `POST` sibling — its `routines`
> table, `Routine` model, `RoutineStatus` enum, `HasPublicUuid`,
> `RoutineResource`, `RoutinePolicy`, `RoutineFactory` are all consumed here),
> and `docs/plans/create-user-profile-spec.md` (its `ShowAthleteProfileController`
> is the read-pipeline reference: a trivial `GET` with no Form Request and no
> Action).

## 1. Context

**Kind:** Greenfield Feature

**Stack:** PHP 8.5 · Laravel 13 · PostgreSQL 17 (runtime) / SQLite `:memory:`
(tests) · Pest 4 (`pest-plugin-laravel`) · `laravel/sanctum` 4 (SPA cookie
mode) · `dedoc/scramble` 0.13 · Pint · Larastan level 6. Everything runs in
Docker.

**Problem statement:** After creating a routine (`POST /api/v1/routines`) a user
accumulates one `active` routine plus a history of `archived` ones, but the API
has no way to read any of them back. The onboarded user's next need is to *see*
their routines — which one is active, which are archived — to decide which
program to keep training. This ticket adds the **read side** of the routine
domain: a collection endpoint that lists the caller's routines (active first),
and a by-id detail endpoint that returns a single routine the caller owns and
`403` / `404` for anything else. No schema change, no new domain object — it
reuses everything the `POST` ticket built.

**In scope:**
- `GET /api/v1/routines` — list the authenticated user's routines, the single
  `active` one first, then the `archived` ones newest-first. Returns `200` with a
  `RoutineResource` collection (`{ "data": [] }` when the user has none). No
  pagination.
- `GET /api/v1/routines/{routine}` — return one routine the caller owns, by its
  public `uuid`, `active` **or** `archived`. `403` when the `uuid` belongs to
  another user, `404` when it matches no row (or is not a UUID).
- `App\Http\Controllers\Routine\ListRoutinesController` +
  `App\Http\Controllers\Routine\ShowRoutineController` — invokable, no Form
  Request, no Action (trivial reads; `CLAUDE.md` rules 5–6, mirroring
  `ShowAthleteProfileController`). Verb-first names (`CLAUDE.md` controller
  rule): `List` for the collection (plural — it returns many), `Show` for the
  item (matches the existing `ShowAthleteProfileController`).
- `App\Policies\RoutinePolicy::view(User, Routine): bool` — the real ownership
  gate for the `{routine}` id, wired as `->can('view', 'routine')` route
  middleware on the detail route.
- Two routes added to the existing `auth:sanctum` group in `routes/api.php`
  (`routines.list`, `routines.show`); the detail route constrained with
  `->whereUuid('routine')`.
- One assertion pair added to `tests/Feature/Auth/DocsSecurityTest.php` (both GET
  operations inherit the global `security`).
- Pest feature coverage of every acceptance criterion + focused `RoutinePolicy`
  unit coverage.

**Out of scope:**
- Any change to the `routines` table, the `Routine` model, `RoutineResource`,
  `RoutineStatus`, `RoutineData`, `RoutineFactory`, `HasPublicUuid`, or
  `RoutineCreateAction` — all consumed as-is.
- Pagination, filtering (`?status=`), sorting parameters, sparse fieldsets — the
  order is fixed server-side; a v1 user has a handful of routines.
- A slimmed-down list payload / a second Resource — every item is the full
  `RoutineResource`, identical to the `POST` response (`CLAUDE.md`: one Resource
  per entity).
- Renaming, editing, deleting, reactivating or cloning a routine (v1 routines are
  immutable — `docs/product-context.md` §6). No `PUT` / `PATCH` / `DELETE`.
- Listing a routine's cycles / sessions (`docs/product-context.md` §6 — separate
  domains, later tickets).
- Rate limiting on the routes (authenticated, low-abuse; matches the profile and
  `routines.store` routes).
- A `viewAny` Policy method / a Form Request whose only job is `authorize()` —
  the list has no foreign resource to gate, so there is nothing for a Policy to
  decide (matches the profile routes, whose no-op `AthleteProfilePolicy` was
  removed in `ff49e41`).
- The `gym-trainer-spa/` frontend (separate repository).

---

## 2. API Surface

### 2.1 REST

Both routes join the existing `Route::middleware('auth:sanctum')->group(...)` in
`routes/api.php`, under the global `apiPrefix: 'api/v1'`. They are subject to
`EnsureFrontendRequestsAreStateful` because the whole `api` group is stateful
(`$middleware->statefulApi()` in `bootstrap/app.php`); CSRF does not apply to
`GET` (a safe method).

| Method | Path | Auth | Request | Response | Status codes |
|---|---|---|---|---|---|
| GET | `/api/v1/routines` | `auth:sanctum` (session cookie, `web` guard) | — (no body, no query params) | `{ "data": [ RoutineResource, … ] }` — the caller's routines, `active` first then `archived` by `created_at` desc; `{ "data": [] }` when the user has none | `200` OK · `401` unauthenticated |
| GET | `/api/v1/routines/{routine}` | `auth:sanctum` + `RoutinePolicy::view` (via `->can('view', 'routine')`) | — | `{ "data": RoutineResource }` | `200` OK · `401` unauthenticated · `403` `AUTHORIZATION_EXCEPTION` (the `uuid` is a routine of another user) · `404` `NOT_FOUND_EXCEPTION` (the `uuid` matches no row, or the segment is not a UUID) |

`RoutineResource` (unchanged, `app/Http/Resources/Routine/RoutineResource.php`):

```json
{
  "id": "e6f8… (uuid v4)",
  "name": "Winter Volume",
  "goal": "hypertrophy",
  "hint": "PPL split, dumbbells only",
  "days_per_cycle": 5,
  "status": "active",
  "archived_at": null,
  "created_at": "2026-09-02T13:00:00+00:00",
  "updated_at": "2026-09-02T13:00:00+00:00"
}
```

Notes:
- `id` is the routine's **`uuid`** (public identifier, `docs/plans/data-model.md`
  §Identificadores), never the internal `bigint` PK. `user_id` is never exposed.
- `{routine}` resolves by `uuid` — `HasPublicUuid::getRouteKeyName()` returns
  `'uuid'`. The route is constrained with `->whereUuid('routine')`, so a
  non-UUID segment (`/api/v1/routines/not-a-uuid`) does not match the route and
  returns `404` `NOT_FOUND_EXCEPTION` (a `NotFoundHttpException`) — never a
  Postgres "invalid input syntax for type uuid" `500`.
- **List order:** `active` first, then `archived` by `created_at` desc —
  `->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")->orderByDesc('created_at')`.
  The `CASE` form parses on PostgreSQL 17 and SQLite `:memory:` and avoids
  relying on `NULL` ordering of `archived_at`, which differs between the two
  engines. There is at most one `active` routine per user (partial unique index),
  so element `0` is the active one whenever the user has one.
- The list includes **both** statuses (AC #2). It is **not paginated** — a bare
  `RoutineResource::collection($routines)` under the default `data` wrapper
  (never disabled in this project). An empty result is `200` `{ "data": [] }`,
  not `404`.
- The detail endpoint returns the caller's routine **regardless of status** — an
  `archived` routine is readable by its owner (`docs/product-context.md` §2: the
  archived routine "conserva todo su historial para consulta"). This is a
  deliberate, narrow extension of `docs/product-context.md` §6 ("endpoint de
  lectura de una rutina archived en detalle — fuera de la v1"), which predates
  this ticket; recorded in §9.
- **`403` vs `404`** (AC #3): route-model binding resolves `{routine}` before the
  `can` middleware runs. A `uuid` that matches no row throws
  `ModelNotFoundException` → `404` `NOT_FOUND_EXCEPTION`. A `uuid` that matches
  another user's routine resolves, then `RoutinePolicy::view` returns `false` →
  `AuthorizationException` → `403` `AUTHORIZATION_EXCEPTION`. Both satisfy AC #3's
  "403/404". The `403` body carries no routine fields.
- No onboarding guard (unlike `POST /api/v1/routines`). A user with no athlete
  profile has no routines and gets `{ "data": [] }` / `404`; there is nothing to
  guard.
- `401`: an unauthenticated request is stopped by `auth:sanctum` before any
  controller or `can` middleware runs — `AuthenticationException` →
  `{ "data": { "code": "AUTHENTICATION_EXCEPTION", "message": … } }`.
- All errors are rendered as JSON by `App\Exceptions\ApiExceptionRenderer`
  (already wired in `bootstrap/app.php`). No hand-built JSON. `419` does not
  apply (safe method, no CSRF).

### 2.2 CLI

Not applicable — no CLI commands.

### 2.3 Events

Not applicable — no events. Both endpoints are pure reads: no job, no event, no
model mutation.

---

## 3. UI

### 3.1 Pages

Not applicable — no pages affected. This is a JSON REST API; the routine-list /
routine-detail screens live in the `gym-trainer-spa/` repository, outside this
ticket.

### 3.2 Components

Not applicable — no components affected.

---

## 4. Database

Not applicable — no data or schema changes. The `routines` table, its partial
unique index, the `uuid` column and its unique index all ship with
`docs/plans/create-routine-spec.md` (migration
`2026_09_02_130000_create_routines_table.php`). This ticket only reads. No
migration, no seed, and therefore **no `gym_trainer` clone** — the
database-isolation procedure in `CLAUDE.md` applies only to branches that change
the schema or seed data. The Pest suite uses SQLite `:memory:` with
`RefreshDatabase` (already wired in `tests/Pest.php`).

---

## 5. Auth & Authorization

### 5.1 Authentication

**Method:** Session (cookie) via **Laravel Sanctum in SPA / stateful mode** — the
same mechanism as every other endpoint. Reuses the `web` session guard
(`config('sanctum.guard') === ['web']`); no `api` guard, no `config/auth.php`
change, no tokens.

- `auth:sanctum` on the route group authenticates the request from the session
  cookie set at registration / login. An unauthenticated request throws
  `AuthenticationException` → `401` JSON (via the handler already configured for
  `api/*`).
- CSRF: not applicable — both routes are `GET` (a safe method). No `419`.

### 5.2 Authorization

| Role | Permissions |
|---|---|
| Authenticated user | **List their own routines** — `GET /api/v1/routines`. No Policy: the controller queries `$request->user()->routines()`, so no other user's row is reachable and there is nothing to decide. Matches the profile routes. |
| Authenticated user | **Read their own routine by id** — `GET /api/v1/routines/{routine}`. `App\Policies\RoutinePolicy::view(User $user, Routine $routine): bool` returns `$routine->user_id === $user->id`, enforced by `->can('view', 'routine')` route middleware. Any other user's `uuid` → `403`. |

- `RoutinePolicy` is auto-discovered by Laravel 13 for `App\Models\Routine`
  (`App\Policies\{Model}Policy` convention) — no `AuthServiceProvider` /
  `Gate::policy()` wiring. It already holds `create()`; this ticket adds `view()`.
- `->can('view', 'routine')` runs **after** route-model binding, so a
  non-existent `uuid` is a `404` (binding miss) before authorization is even
  considered.
- No `viewAny` method. It would be a no-op (`return true`) — the list has no
  target resource and the profile routes set the precedent for skipping a no-op
  Policy entirely (`ff49e41` removed `AthleteProfilePolicy`).
- `RoutinePolicy::create` and the `StoreRoutineRequest` that calls it are
  unchanged.

---

## 6. Configuration

**Environment variables:** Not applicable — no new or changed keys. `phpunit.xml`
already carries everything the tests need (`DB_CONNECTION=sqlite`,
`DB_DATABASE=:memory:`, `SANCTUM_STATEFUL_DOMAINS=localhost`,
`APP_URL=http://localhost`); `RefreshDatabase` is already active for the
`Feature` suite.

**Config / project files modified:**

| File | Change |
|---|---|
| `routes/api.php` | Add `use` imports for `ListRoutinesController` and `ShowRoutineController`. Inside the existing `auth:sanctum` group, next to `routines.store`: `Route::get('routines', ListRoutinesController::class)->name('routines.list')` and `Route::get('routines/{routine}', ShowRoutineController::class)->whereUuid('routine')->can('view', 'routine')->name('routines.show')`. |
| `tests/Feature/Auth/DocsSecurityTest.php` | Add two assertions that `/api/v1/routines` `get` and `/api/v1/routines/{routine}` `get` have no per-operation `security` key (they inherit the document-root cookie scheme), in the existing `->and(...)` chain. |

No change to `bootstrap/app.php`, `config/auth.php`, `config/sanctum.php`,
`config/cors.php`, `config/scramble.php`, `bootstrap/providers.php`,
`phpunit.xml`, `composer.json`, `tests/Feature/ArchTest.php` (the existing
`arch('routine controllers are invokable')` rule already covers
`App\Http\Controllers\Routine\*`), or `docs/plans/data-model.md`.

---

## 7. Current vs New Behavior

| Behavior | Current | New |
|---|---|---|
| Reading routines | No read endpoint. A routine can be created (`POST /api/v1/routines`) but never fetched back. | `GET /api/v1/routines` lists the caller's routines; `GET /api/v1/routines/{routine}` returns one by `uuid`. |
| List ordering | N/A | The single `active` routine first, then `archived` by `created_at` desc — deterministic, server-side. |
| Archived routine history | Retained in the DB (`status` + `archived_at`), unreachable over the API. | Readable by its owner via `GET /api/v1/routines/{routine}` and listed by `GET /api/v1/routines`. |
| `RoutinePolicy` | `create()` only. | Adds `view(User, Routine)` — first ownership check keyed to `$routine->user_id`. Establishes the pattern for the by-id cycle / session endpoints. |
| Authenticated routes | `auth:sanctum` group holds `logout`, `user`, `GET`/`PUT profile`, `POST routines`. | Adds `GET routines` and `GET routines/{routine}` to the same group. |
| Route-model binding | Not used anywhere (no route has a `{param}` segment). | `GET /api/v1/routines/{routine}` is the first route-model-bound route; resolves by `uuid`, constrained with `->whereUuid`. |
| `->can()` route middleware | Not used (profile routes have no Policy; `routines.store` authorizes in the Form Request). | First use of `->can()` as route middleware. |
| Routine-read tests | None. | `tests/Feature/Routine/ListRoutinesTest.php`, `tests/Feature/Routine/ShowRoutineTest.php`, two `RoutinePolicyTest` cases, one `DocsSecurityTest` assertion pair. |

---

## 8. Test Cases

Executable with Pest 4 on SQLite `:memory:` (`RefreshDatabase`, already wired).
Each feature test's `beforeEach` sets
`$this->withHeader('Origin', config('app.url'))` and
`$this->user = User::factory()->create()`. No `Bus::fake()` (nothing is
dispatched). File-level regexes as in `StoreRoutineTest`:
`UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/'`,
`ISO_8601_REGEX = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/'`.
"An `active` routine" = `Routine::factory()->for($user)->create()`; "an
`archived` routine" = `Routine::factory()->for($user)->archived()->create()`
(two `active` routines for one user violate the partial unique index).

### `GET /api/v1/routines` — `tests/Feature/Routine/ListRoutinesTest.php`

**TC-1:** Lists the caller's routines, `active` first then `archived` newest-first (AC #2)
- **Given:** an authenticated user with one `active` routine and two `archived`
  routines created at distinct timestamps (e.g. `archived_at` / `created_at`
  spread with `CarbonImmutable` `travel`)
- **When:** `GET /api/v1/routines`
- **Expect:** `200`; `data` has exactly 3 items; `data.0.status === 'active'`;
  `data.1.status === 'archived'` and `data.2.status === 'archived'` with
  `data.1.created_at` >= `data.2.created_at` (newer first);
  `data.0.id` is the active routine's `uuid`

**TC-2:** Returns only the caller's routines (AC #1)
- **Given:** an authenticated user with one `active` routine; a second user with
  one `active` + one `archived` routine
- **When:** `GET /api/v1/routines` as the first user
- **Expect:** `200`; `data` has exactly 1 item; its `id` is the first user's
  routine `uuid`; none of the second user's routine `uuid`s appear in the
  response

**TC-3:** Empty list for a user with no routines
- **Given:** an authenticated user with no `routines` row
- **When:** `GET /api/v1/routines`
- **Expect:** `200`; `assertExactJson(['data' => []])`

**TC-4:** Each item is the full `RoutineResource`, `id` is the `uuid`, no `user_id` (AC #2)
- **Given:** an authenticated user with one `active` routine
- **When:** `GET /api/v1/routines`
- **Expect:** `200`;
  `assertJsonStructure(['data' => ['*' => ['id', 'name', 'goal', 'hint', 'days_per_cycle', 'status', 'archived_at', 'created_at', 'updated_at']]])`;
  `data.0.id` matches `UUID_REGEX` and is not `(string) $routine->id`;
  `assertJsonMissingPath('data.0.user_id')`

**TC-5:** Enums serialise as strings, dates as ISO-8601
- **Given:** an authenticated user with one `active` routine (`goal` = `strength`)
- **When:** `GET /api/v1/routines`
- **Expect:** `200`; `data.0.status` is the string `"active"`; `data.0.goal` is
  `"strength"`; `data.0.days_per_cycle` is `5`; `data.0.created_at` matches
  `ISO_8601_REGEX`; an `active` routine's `data.0.archived_at` is `null`

**TC-6:** Unauthenticated request → `401`
- **Given:** no `actingAs` (the `Origin` header is still set)
- **When:** `GET /api/v1/routines`
- **Expect:** `401`; `assertJsonPath('data.code', 'AUTHENTICATION_EXCEPTION')`

**TC-7:** Strict-mode render guard — the collection never triggers a lazy load
- **Given:** an authenticated user with one `active` and one `archived` routine
- **When:** `GET /api/v1/routines`
- **Expect:** `200` with no `500`; `assertJsonMissingPath('data.0.user')` and
  `assertJsonMissingPath('data.1.user')`

### `GET /api/v1/routines/{routine}` — `tests/Feature/Routine/ShowRoutineTest.php`

**TC-8:** Returns the caller's `active` routine by `uuid` (AC #2)
- **Given:** an authenticated user with one `active` routine
- **When:** `GET /api/v1/routines/{routine->uuid}`
- **Expect:** `200`; `data.id === $routine->uuid`; `data.status === 'active'`;
  `assertJsonStructure(['data' => ['id', 'name', 'goal', 'hint', 'days_per_cycle', 'status', 'archived_at', 'created_at', 'updated_at']])`;
  `assertJsonMissingPath('data.user_id')`

**TC-9:** Returns the caller's `archived` routine by `uuid` (history is readable)
- **Given:** an authenticated user with one `archived` routine
- **When:** `GET /api/v1/routines/{routine->uuid}`
- **Expect:** `200`; `data.id === $routine->uuid`; `data.status === 'archived'`;
  `data.archived_at` matches `ISO_8601_REGEX`

**TC-10:** Another user's routine `uuid` → `403` (AC #3)
- **Given:** an authenticated user; a second user with one `active` routine
- **When:** `GET /api/v1/routines/{otherRoutine->uuid}` as the first user
- **Expect:** `403`; `assertJsonPath('data.code', 'AUTHORIZATION_EXCEPTION')`;
  `assertJsonMissingPath('data.name')` (no routine fields leaked)

**TC-11:** Unknown `uuid` → `404` (AC #3)
- **Given:** an authenticated user
- **When:** `GET /api/v1/routines/{random valid uuid v4}`
- **Expect:** `404`; `assertJsonPath('data.code', 'NOT_FOUND_EXCEPTION')`

**TC-12:** Non-UUID path segment → `404` (route constraint, no Postgres cast error)
- **Given:** an authenticated user
- **When:** `GET /api/v1/routines/not-a-uuid`
- **Expect:** `404`; `assertJsonPath('data.code', 'NOT_FOUND_EXCEPTION')`

**TC-13:** Unauthenticated request → `401`
- **Given:** no `actingAs`; a routine that exists
- **When:** `GET /api/v1/routines/{routine->uuid}`
- **Expect:** `401`; `assertJsonPath('data.code', 'AUTHENTICATION_EXCEPTION')`

**TC-14:** Strict-mode render guard — the Resource never triggers a lazy load
- **Given:** an authenticated user with one `active` routine
- **When:** `GET /api/v1/routines/{routine->uuid}`
- **Expect:** `200` with no `500`; `assertJsonMissingPath('data.user')`

### Policy — `tests/Unit/Routine/RoutinePolicyTest.php` (extended)

**TC-15:** `RoutinePolicy::view` allows the owner
- **Given:** a persisted `User` and a `Routine` with `user_id` = that user's id
- **When:** `(new RoutinePolicy)->view($user, $routine)` is evaluated
- **Expect:** `true`

**TC-16:** `RoutinePolicy::view` denies a non-owner
- **Given:** a persisted `Routine` owned by user A; a different persisted `User` B
- **When:** `(new RoutinePolicy)->view($userB, $routine)` is evaluated
- **Expect:** `false`

### OpenAPI — `tests/Feature/Auth/DocsSecurityTest.php` (extended)

**TC-17:** The generated OpenAPI spec marks both GET routes secured
- **Given:** the app with `security_strategy` = `MiddlewareAuthSecurityStrategy`
  (already on `main`)
- **When:** the spec is generated in-process (`app(Generator::class)()`)
- **Expect:** `paths./api/v1/routines.get` and
  `paths./api/v1/routines/{routine}.get` have **no** per-operation `security`
  key (they inherit the document-root cookie `apiKey` scheme), added as
  `->and(...)` assertions alongside the existing `logout` / `user` / `profile` /
  `routines.store` ones

---

## 9. Technical Decisions

| Decision area | What was decided | Why |
|---|---|---|
| Endpoint scope | Ship **both** `GET /api/v1/routines` (list) and `GET /api/v1/routines/{routine}` (detail) in one PR. | AC #3 ("403/404 si intenta acceder por ID ajeno") describes a real by-id endpoint, not just the structural isolation of the list. "Qué falta" and product-context focus on the list, but the detail is the only place a `403`/`404` decision exists. Confirmed with the product owner. |
| HTTP verbs & statuses | `GET` collection → `200`; `GET` item → `200`, `403` (foreign), `404` (unknown / non-UUID), `401` (unauthenticated). | Standard REST reads. `403` vs `404` follows framework order: route-model binding (→ `404`) runs before `->can('view')` (→ `403`). |
| Pipeline shape | Invokable controller → `RoutineResource`. **No Form Request, no Action, no Service.** | Neither endpoint takes input or orchestrates anything. `ShowAthleteProfileController` (merged) is the precedent for a trivial `GET` in this codebase. A Form Request whose only method is `authorize()`, or an Action wrapping one query, is indirection only (`CLAUDE.md` rules 5–6). Conscious deviation from rule 2's "no shortcuts", same call the create-routine spec made for the Service layer. Confirmed with the product owner. |
| List authorization | **No Policy.** The controller queries `$request->user()->routines()`. | No foreign row is reachable, so there is nothing to authorize. A `viewAny` returning `true` unconditionally is a no-op — and the profile routes already set the precedent (`ff49e41` deleted the no-op `AthleteProfilePolicy`). Confirmed with the product owner. |
| Detail authorization | `RoutinePolicy::view(User, Routine): bool => $routine->user_id === $user->id`, enforced by `->can('view', 'routine')` **route middleware**, not a Form Request or an in-controller `Gate::authorize`. | It is a real ownership check (AC #3). Route middleware keeps the controller at ~2 lines (`CLAUDE.md` controller rule) and reads at the route table. Auto-discovered Policy; establishes the `view` pattern for the by-id cycle / session endpoints. |
| `viewAny` | Not added. | See "List authorization". |
| Route key & binding | `{routine}` binds by `uuid` (`HasPublicUuid::getRouteKeyName()` → `'uuid'`), route constrained with `->whereUuid('routine')`. | `docs/plans/data-model.md` §Identificadores — the `uuid` is the only identifier crossing the API boundary. `->whereUuid` makes a non-UUID segment a route miss (`404`) instead of reaching the query — on the Postgres `uuid` column an invalid string would otherwise raise a `QueryException` → `500`. SQLite stores `uuid` as `TEXT` so the constraint is what makes TC-12 engine-independent. |
| List ordering | `->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")->orderByDesc('created_at')`. | "activa primero" (ticket "Qué falta"), then a deterministic newest-first tie-break. `CASE WHEN` parses on PostgreSQL 17 and SQLite `:memory:`; ordering by `archived_at` nulls would behave differently across the two engines. |
| Pagination | None. Bare `RoutineResource::collection($routines)`. | A v1 user has a handful of routines (one `active`, a short archived history). Pagination is speculative generality (`CLAUDE.md` rule 5). |
| Response envelope | Default Laravel `data`-wrapping (never disabled in this project). List → `{ "data": [...] }`, item → `{ "data": {...} }`, empty → `{ "data": [] }`. | Matches every other endpoint and the error envelope; tests assert `data.*`. |
| Resource | Reuse `app/Http/Resources/Routine/RoutineResource.php` unchanged for both the collection items and the detail body. | `CLAUDE.md`: one Resource per entity. It is already flat, relation-free and strict-mode-safe (reads only own columns). "datos mínimos por rutina" is satisfied — it carries no nested relations. A slimmer second Resource would fork the OpenAPI shape for no gain. |
| Distinguishing the active routine | The `status` field (`"active"` / `"archived"`) plus the guaranteed ordering (active at index `0`). No extra `is_active` boolean. | `status` already conveys it unambiguously; a derived flag would duplicate state (`CLAUDE.md` rules 5–6). |
| Archived routine detail | The detail endpoint returns the caller's routine **regardless of status**. | A `{routine}` binding that rejected the owner's own `archived` routine contradicts `docs/product-context.md` §2 ("conserva todo su historial para consulta"). Deliberate, narrow extension of §6's "fuera de la v1" line, which predates this ticket. Confirmed with the product owner. |
| Onboarding guard | None on either read. | A profile-less user simply owns no routines. Unlike `POST`, a read has no account-state precondition. |
| Controller names | `ListRoutinesController` (collection, plural) and `ShowRoutineController` (item, singular) — route names `routines.list` / `routines.show`. | `CLAUDE.md` controller rule: "Verb-first name" (`Store…`, `Generate…`, `Close…`). `List` is the verb; `Index` is the Laravel router's action label, not a verb, so it is not used. `Show` mirrors the existing `ShowAthleteProfileController`; plural `Routines` for the collection matches "list routines" and the Notion title "Listar mis rutinas". |
| Controller return types | `ListRoutinesController::__invoke(Request): AnonymousResourceCollection`; `ShowRoutineController::__invoke(Routine): RoutineResource`. | Explicit return types (`CLAUDE.md` PHP style) and they let `dedoc/scramble` infer the list as an array of `RoutineResource` and the detail as a single one, with `{routine}` a `uuid` path param — no `#[...]` attribute needed. |
| Model strictness | Controllers select whole rows (`->get()` / the bound model); `RoutineResource` reads only own columns; nothing touches `$routine->user`. | `Model::shouldBeStrict(! isProduction())` makes a lazy relation load and a missing-attribute access throw outside production. TC-7 / TC-14 assert no lazy load. |
| Tests | SQLite `:memory:` + `RefreshDatabase` (already wired); no `Bus::fake()`; no `phpunit.xml` change; no `gym_trainer` clone (no migration). | Read-only feature tests. `RoutineFactory` + its `archived()` state cover every fixture; `CarbonImmutable` `travel` spreads `created_at` for the ordering assertion. |
| `ArchTest` | No change. | `arch('routine controllers are invokable')` already targets `App\Http\Controllers\Routine`; the two new controllers are covered automatically. |
| Scramble `security_strategy` | No `config/scramble.php` change — `MiddlewareAuthSecurityStrategy` (already on `main`) covers any `auth:sanctum` route. Only `DocsSecurityTest` assertions are added. | The new routes match that middleware, so they are documented as secured with no wiring. |
| Git artifacts | English only; **no** `Co-Authored-By: Claude` / `Claude-Session:` commit trailers; PR description carries the `🤖 Generated with Claude Code` footer only. One PR for the whole feature, on branch `worktree-list-routings`, spec commit first then implementation commits. | Repo `CLAUDE.md` / `AGENTS.md` rule; it takes precedence over the session's attribution instruction, as the register / profile / login / create-routine specs each note. |

---

## 10. Work Plan

Each task's Definition of Done is limited to the artifact existing, passing Pint +
PHPStan level 6, and — where the class carries logic — its focused test, authored
in the same task. Task 8 is the functional gate.

| # | Task | Definition of Done |
|---|---|---|
| 1 | Add `view(User $user, Routine $routine): bool` to `app/Policies/RoutinePolicy.php` (`return $routine->user_id === $user->id;`) with a short PHPDoc. Extend `tests/Unit/Routine/RoutinePolicyTest.php` with TC-15 + TC-16 | `vendor/bin/pest tests/Unit/Routine/RoutinePolicyTest.php` green; `Gate::forUser($owner)->allows('view', $routine)` is `true`, denies a stranger; Pint + PHPStan clean. |
| 2 | Create `app/Http/Controllers/Routine/ListRoutinesController.php` via `make:controller --invokable`, move into the domain folder + fix namespace: `final`, `__invoke(Request $request): AnonymousResourceCollection` → resolve `$user`, `$routines = $user->routines()->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")->orderByDesc('created_at')->get()`, `return RoutineResource::collection($routines)` | `final`, `__invoke` only; Pint + PHPStan clean (`/** @var User $user */` on `$request->user()`). |
| 3 | Create `app/Http/Controllers/Routine/ShowRoutineController.php` the same way: `final`, `__invoke(Routine $routine): RoutineResource` → `return RoutineResource::make($routine)` | `final`, `__invoke` only; Pint + PHPStan clean. |
| 4 | Edit `routes/api.php`: add the two `use` imports; in the `auth:sanctum` group add `Route::get('routines', ListRoutinesController::class)->name('routines.list')` and `Route::get('routines/{routine}', ShowRoutineController::class)->whereUuid('routine')->can('view', 'routine')->name('routines.show')` | `php artisan route:list` shows both routes with `auth:sanctum`; the detail route lists the `can:view,routine` middleware; PHPStan clean in `routes/`. |
| 5 | Write `tests/Feature/Routine/ListRoutinesTest.php` covering TC-1 … TC-7 (`beforeEach`: `Origin` header + `$this->user`) | `vendor/bin/pest tests/Feature/Routine/ListRoutinesTest.php` all green. |
| 6 | Write `tests/Feature/Routine/ShowRoutineTest.php` covering TC-8 … TC-14 | `vendor/bin/pest tests/Feature/Routine/ShowRoutineTest.php` all green. |
| 7 | Add the `/api/v1/routines` `get` and `/api/v1/routines/{routine}` `get` assertions to `tests/Feature/Auth/DocsSecurityTest.php` (TC-17) | `vendor/bin/pest tests/Feature/Auth/DocsSecurityTest.php` green. |
| 8 | Run `vendor/bin/pint --dirty`, then `vendor/bin/phpstan analyse`, then `composer check` (Pint `--test` + PHPStan level 6 + full Pest) | Pint reports no diffs; PHPStan level 6 clean; the full suite green with no regression in Auth / Profile / Routine. |
| 9 | Manual check with `curl` against `http://localhost:8000`: `GET /sanctum/csrf-cookie` → register + login → `PUT /api/v1/profile` → `POST /api/v1/routines` ×2 → `GET /api/v1/routines` (`200`, 2 items, `data[0].status = active`) → `GET /api/v1/routines/{active uuid}` (`200`) → `GET /api/v1/routines/{archived uuid}` (`200`) → second user → `GET /api/v1/routines/{first user's uuid}` (`403` `AUTHORIZATION_EXCEPTION`) → `GET /api/v1/routines/{random uuid}` (`404`) → `GET /api/v1/routines/not-a-uuid` (`404`) → drop session cookie → `GET /api/v1/routines` (`401`). Review `GET /docs/api` | The `curl` calls return the expected codes; both endpoints appear in Scramble, marked secured, with the response inferred from `RoutineResource` (array for the list, object for the detail) and `{routine}` shown as a `uuid` path param. |

*Process note: branch name, commit messages and PR text follow `CLAUDE.md` /
`AGENTS.md` — English only, no `Co-Authored-By: Claude` / `Claude-Session:`
trailers, `🤖 Generated with Claude Code` footer in the PR description only. One
PR for the whole feature; the spec lands first, implementation commits follow on
the same branch.*
