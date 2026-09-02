# Athlete profile — `GET` / `PUT /api/v1/profile`

> Derived from the Notion ticket "Guardar el perfil de atleta" (Feature: Auth &
> perfil · MVP · Must · Repo: API) and the approved plan
> (`.claude/plans/tenemos-un-nuevo-requerimiento-ancient-lighthouse.md`). Base
> contract: `docs/product-context.md` §4 (onboarding), `docs/plans/data-model.md`
> §`athlete_profiles`, and `docs/plans/register-user-spec.md` (pipeline reference
> implementation).

## 1. Context

**Kind:** Greenfield Feature

**Stack:** PHP 8.5 · Laravel 13 · PostgreSQL 17 (runtime) / SQLite `:memory:` (tests) ·
Pest 4 (`pest-plugin-laravel`) · `laravel/sanctum` 4 (SPA cookie mode, already
installed) · `spatie/laravel-data` 4.23 · `dedoc/scramble` 0.13 · Pint ·
Larastan level 6. Everything runs in Docker.

**Problem statement:** The API has authentication (`POST /api/v1/register`) but no
authenticated endpoints and no domain data. The first thing a signed-in user does
is onboarding: filling in their **athlete profile** — experience level, weekly
availability, target session length, training goal and free-text notes. This
profile is fed verbatim into the AI routine/cycle planner, so it must exist before
any routine can be generated. This ticket adds the profile read/write endpoints
and, with them, stands up the first `auth:sanctum` + Policy route group that
Routines, Cycles, Sessions and Recommendations will all build on.

**In scope:**
- `PUT /api/v1/profile` — create-or-update the authenticated user's athlete
  profile (idempotent upsert; exactly one row per user).
- `GET /api/v1/profile` — return the authenticated user's profile, or an explicit
  "onboarding pending" state when none exists yet.
- The `athlete_profiles` table + `AthleteProfile` model + `User::athleteProfile()`
  relation + factory.
- The first two backed enums in the codebase: `App\Enums\Profile\ExperienceLevel`
  and `App\Enums\Shared\Goal`.
- The first `auth:sanctum` route group in `routes/api.php`.
- The first Policy (`AthleteProfilePolicy`), resolved by Laravel auto-discovery.
- Enabling `security_strategy` in `config/scramble.php` (apiKey / cookie scheme) —
  deferred by the register spec "until the first protected route exists".
- Pest feature + unit coverage of every acceptance criterion.

**Out of scope:**
- Partial updates (`PATCH` / send-only-changed-fields). `PUT` is a full replace;
  every save carries the complete representation.
- Any use of the profile downstream: routine defaults, cycle generation, AI
  prompts — separate tickets. Saving the profile has **no** side effects here.
- Deleting a profile (no endpoint, no soft delete — `docs/plans/data-model.md`).
- Rate limiting on the profile routes (authenticated, low-abuse; `register`'s
  `throttle:6,1` exists only because it is public).
- Any `users`-table change; the profile is a separate 1:1 table.
- Admin / support access to another user's profile.
- The `gym-trainer-spa/` frontend (separate repository).

---

## 2. API Surface

### 2.1 REST

Both routes sit in a new `Route::middleware('auth:sanctum')->group(...)` in
`routes/api.php`, under the global `apiPrefix: 'api/v1'`. They are also subject to
`EnsureFrontendRequestsAreStateful` + CSRF because the whole `api` group is
stateful (`$middleware->statefulApi()` in `bootstrap/app.php`).

| Method | Path | Auth | Request | Response | Status codes |
|---|---|---|---|---|---|
| GET | `/api/v1/profile` | `auth:sanctum` (session cookie, `web` guard) | — | `{ "data": { "onboarding_completed": bool, "profile": null \| { "experience_level": string, "days_per_week": int, "session_minutes": int, "goal": string, "notes": string\|null, "created_at": string ISO-8601, "updated_at": string ISO-8601 } } }` | `200` always (whether or not a profile exists) · `401` unauthenticated |
| PUT | `/api/v1/profile` | `auth:sanctum` (session cookie, `web` guard) | JSON: `experience_level` (string, required, one of `beginner`/`intermediate`/`advanced`), `days_per_week` (int, required, 1–7), `session_minutes` (int, required, 10–240), `goal` (string, required, one of `hypertrophy`/`strength`/`fat_loss`/`general_health`/`endurance`), `notes` (string, optional/nullable, ≤2000) | Same envelope as GET, with `onboarding_completed` always `true` and `profile` populated | `201` profile created (first save) · `200` profile updated (subsequent saves) · `422` validation (unknown enum value → error on that field; number out of range or non-integer → error on that field; missing required field → error on that field) · `401` unauthenticated · `419` stateful request without a valid CSRF token |

Notes:
- The body **does not** expose `id`. `docs/plans/data-model.md` classifies
  `athlete_profiles` as an internal table: no `uuid`, addressed only as "the
  current user's profile", never by id. Mirrors `UserResource` omitting `id`.
- GET and PUT return the **same envelope shape**, so the SPA has one contract.
  `onboarding_completed` is the machine-readable "onboarding pending" signal the
  ticket asks for (AC #5).
- `PUT` is idempotent: the resource lives at a fixed URL (no id segment), so the
  first save and every later edit target the same row. `201` vs `200` is decided
  by the persisted model's `wasRecentlyCreated`.
- `notes` normalisation: omitted, `null`, `""` and whitespace-only all persist as
  `null` (AC #3) — collapsed in `prepareForValidation()`.
- Errors are rendered as JSON by the exception handler already configured in
  `bootstrap/app.php` for `api/*`. No hand-built JSON. `401` comes from the
  `auth:sanctum` middleware's `AuthenticationException`.
- `GET /sanctum/csrf-cookie` (registered by Sanctum at the app root) is unchanged;
  a browser client calls it before the `PUT`.

### 2.2 CLI

Not applicable — no CLI commands.

### 2.3 Events

Not applicable — no events. `updateOrCreate` fires Eloquent's model events
(`saving`/`saved`/`created`/`updated`) but the project registers no listeners for
`AthleteProfile`, and none are added here.

---

## 3. UI

### 3.1 Pages

Not applicable — no pages affected. This is a JSON REST API; the onboarding screen
lives in the `gym-trainer-spa/` repository, outside this ticket.

### 3.2 Components

Not applicable — no components affected.

---

## 4. Database

### 4.1 Schema changes

| Table | Action | Details |
|---|---|---|
| `athlete_profiles` | Create | `id` bigint PK · `user_id` bigint, FK → `users.id`, **`unique`**, `cascadeOnDelete` · `experience_level` string (stores the `ExperienceLevel` backed value) · `days_per_week` `unsignedSmallInteger` · `session_minutes` `unsignedSmallInteger` · `goal` string (stores the `Goal` backed value) · `notes` text nullable · `created_at` / `updated_at` timestamps |

- Migration file: `database/migrations/<timestamp>_create_athlete_profiles_table.php`,
  anonymous class `return new class extends Migration`.
- `user_id` `unique()` enforces the 1:1 invariant at the DB level — the backstop
  behind `updateOrCreate` for AC #4.
- `cascadeOnDelete` (`->constrained()->cascadeOnDelete()`): a profile is
  meaningless without its user; matches the data-model FK convention.
- Enum columns are **plain `string`**, not native Postgres `enum` — portable
  across the Postgres runtime and the SQLite `:memory:` test DB. Membership is
  guarded by the backed-enum cast on the model plus `Rule::enum` in the Form
  Request. No `CHECK` constraint.
- No `uuid`, no soft deletes (`docs/plans/data-model.md`).
- **Database isolation (`CLAUDE.md` → "Workflows — database isolation"):** this
  branch adds a migration, so the shared `gym_trainer` database must not be
  migrated directly. Before running `migrate`:
  `docker compose exec pgsql createdb -U gym -T gym_trainer gym_trainer_create_user_profile`,
  then set `DB_DATABASE=gym_trainer_create_user_profile` in this worktree's
  `.env`. Drop the clone (`dropdb --if-exists`) and revert `.env` on merge. The
  Pest suite is unaffected — it uses SQLite `:memory:`.

### 4.2 Seeds

Not applicable — no seeds.

---

## 5. Auth & Authorization

### 5.1 Authentication

**Method:** Session (cookie) via **Laravel Sanctum in SPA / stateful mode** — the
same mechanism established by the register ticket. Reuses the `web` session guard
(`config('sanctum.guard') === ['web']`); no `api` guard, no `config/auth.php`
change, no tokens.

- `auth:sanctum` middleware on the new route group authenticates the request from
  the session cookie set at registration/login. An unauthenticated request throws
  `AuthenticationException` → `401` JSON (via the handler already configured for
  `api/*`).
- CSRF: `PUT` is a stateful non-GET request, so it requires a valid `XSRF-TOKEN`
  (`419` otherwise). Auto-bypassed in the test environment
  (`ValidateCsrfToken::runningUnitTests()`).

### 5.2 Authorization

`CLAUDE.md` rule 4: every data route carries `auth:sanctum` + a Policy. This
ticket introduces the first Policy.

| Role | Permissions |
|---|---|
| Authenticated user | `view` / `update` **their own** athlete profile (`profile.user_id === user.id`). `create` a profile for themselves (always allowed). No user can read or write another user's profile. |

- **`AthleteProfilePolicy`** (`app/Policies/AthleteProfilePolicy.php`, `final`):
  - `view(User $user, AthleteProfile $profile): bool` → `$profile->user_id === $user->id`
  - `create(User $user): bool` → `true`
  - `update(User $user, AthleteProfile $profile): bool` → `$profile->user_id === $user->id`
- **Registration:** Laravel 13 auto-discovery resolves
  `App\Policies\AthleteProfilePolicy` for `App\Models\AthleteProfile` by naming
  convention. No `Gate::policy()` call, no `AuthServiceProvider`.
- **Delegation:** each Form Request's `authorize()` calls
  `$user->can('view'|'update', $profile)` against the fetched instance, or
  `$user->can('create', AthleteProfile::class)` when no row exists yet. A `null`
  user short-circuits to `false` (defence in depth behind `auth:sanctum`).
- **Real isolation** comes from query-scoping every read and write through
  `$request->user()->athleteProfile()` — no code path accepts a profile id, so
  cross-user access is structurally impossible. The Policy is the formal rule-4
  gate and future-proofing.

---

## 6. Configuration

**Environment variables:**

| Variable | Value / Source | Purpose |
|---|---|---|
| `DB_DATABASE` | `gym_trainer_create_user_profile` (this worktree's `.env` only, during development) | Points the worktree at a clone of `gym_trainer` so the new migration never runs against the shared database. Reverted on merge. |

No new keys in `.env.example`. `phpunit.xml` already carries everything the tests
need (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `SANCTUM_STATEFUL_DOMAINS=localhost`,
`APP_URL=http://localhost`), and `RefreshDatabase` is already active for the
`Feature` suite in `tests/Pest.php`.

**Config files modified:**

| File | Change |
|---|---|
| `routes/api.php` | Add `use` imports for the two Profile controllers and a `Route::middleware('auth:sanctum')->group(...)` with `GET profile` → `ShowAthleteProfileController` (`profile.show`) and `PUT profile` → `UpdateAthleteProfileController` (`profile.update`). The public `register` route and its comment are untouched. |
| `config/scramble.php` | Set `security_strategy` from `null` to `MiddlewareAuthSecurityStrategy` with a `SecurityScheme::apiKey('cookie', config('session.cookie'))` scheme (cookie, **not** bearer — this API is Sanctum SPA). Verify the helper signature against the installed `dedoc/scramble ^0.13.42` first; if it differs, fall back to `Scramble::extendOpenApi()` in `AppServiceProvider::boot()`. |

No change to `config/auth.php`, `config/sanctum.php`, `config/cors.php`,
`bootstrap/app.php`, `bootstrap/providers.php`, `phpunit.xml`, `composer.json`.

---

## 7. Current vs New Behavior

| Behavior | Current | New |
|---|---|---|
| Athlete profile | No table, no model, no endpoint. A registered user has only its `users` row. | `athlete_profiles` table (1:1 with `users`); `PUT /api/v1/profile` creates/updates it, `GET /api/v1/profile` reads it. |
| Onboarding state | Not represented anywhere. | `GET /api/v1/profile` returns `onboarding_completed: false` + `profile: null` until the first `PUT`, then `true` + the data. |
| Authenticated routes | None. `routes/api.php` has one public route (`register`); `auth:sanctum` is unused. | First `auth:sanctum` group in `routes/api.php`. `auth:sanctum` resolves the `web` session guard (SPA). |
| Authorization | None. No `app/Policies/`, no `AuthServiceProvider`. `RegisterRequest::authorize()` returns `true` (documented public exception). | First Policy (`AthleteProfilePolicy`), auto-discovered. Profile Form Requests delegate `authorize()` to it. |
| Enums | `app/Enums/` does not exist. | `App\Enums\Profile\ExperienceLevel` and `App\Enums\Shared\Goal` — first backed enums, matching `docs/plans/data-model.md` §Enums. |
| `User` model | No relations. | `athleteProfile(): HasOne` relation + `@property-read` PHPDoc line. |
| OpenAPI auth docs | `config/scramble.php` `security_strategy => null`; every route documented as unsecured. | `MiddlewareAuthSecurityStrategy` (apiKey/cookie): `/api/v1/profile` documented as secured, `/api/v1/register` explicitly `security: []`. |
| Profile-touching tests | None. | `tests/Feature/Profile/` (two endpoint files + the action test), `tests/Unit/Profile/` (DTO + Policy), one added `tests/Feature/ArchTest.php` rule. |

---

## 8. Test Cases

Executable with Pest 4 on SQLite `:memory:` (`RefreshDatabase`, already wired).
Every feature test's `beforeEach` sets
`$this->withHeader('Origin', config('app.url'))`; authenticated cases add
`$this->actingAs($user)`. Base valid payload:
`experience_level` = `"intermediate"`, `days_per_week` = `4`,
`session_minutes` = `60`, `goal` = `"hypertrophy"`, `notes` = `"bad left knee"`.

### PUT `/api/v1/profile` — `tests/Feature/Profile/UpdateAthleteProfileTest.php`

**TC-1:** First save creates the profile
- **Given:** an authenticated user with no `athlete_profiles` row
- **When:** `PUT /api/v1/profile` with the base valid payload
- **Expect:** `201`; `assertJsonPath('data.onboarding_completed', true)`; `data.profile.*` echo the input (`experience_level` = `"intermediate"`, `days_per_week` = `4`, `session_minutes` = `60`, `goal` = `"hypertrophy"`, `notes` = `"bad left knee"`); `assertDatabaseHas('athlete_profiles', ['user_id' => $user->id, 'experience_level' => 'intermediate', 'goal' => 'hypertrophy', 'days_per_week' => 4, 'session_minutes' => 60])`; `assertDatabaseCount('athlete_profiles', 1)`

**TC-2:** Second save updates the same row, never creates a second (AC #4)
- **Given:** `AthleteProfile::factory()->for($user)->create(['days_per_week' => 3])`; capture its `id`
- **When:** `PUT /api/v1/profile` with the base payload but `days_per_week` = `6`
- **Expect:** `200`; `assertJsonPath('data.onboarding_completed', true)`; `assertDatabaseCount('athlete_profiles', 1)`; the row's `id` is unchanged; `days_per_week` = `6` persisted

**TC-3:** Each required field is required
- **Given:** an authenticated user
- **When:** `PUT /api/v1/profile` with the base payload minus one field (dataset: `experience_level`, `days_per_week`, `session_minutes`, `goal`)
- **Expect:** `422`; `assertJsonValidationErrors($missingField)`; `assertDatabaseCount('athlete_profiles', 0)`

**TC-4:** `experience_level` outside the allowed set → `422` on that field (AC #2)
- **Given:** an authenticated user
- **When:** `PUT /api/v1/profile` with `experience_level` = `"expert"`
- **Expect:** `422`; `assertJsonValidationErrors('experience_level')`; `assertDatabaseCount('athlete_profiles', 0)`

**TC-5:** `goal` outside the allowed set → `422` on that field (AC #2)
- **Given:** an authenticated user
- **When:** `PUT /api/v1/profile` with `goal` = `"powerlifting"`
- **Expect:** `422`; `assertJsonValidationErrors('goal')`

**TC-6:** Every valid `experience_level` value is accepted
- **Given:** an authenticated user with no profile
- **When:** `PUT /api/v1/profile` with `experience_level` = each of `beginner` / `intermediate` / `advanced` (dataset), rest of the payload valid
- **Expect:** `201`; `assertJsonPath('data.profile.experience_level', $value)`

**TC-7:** Every valid `goal` value is accepted
- **Given:** an authenticated user with no profile
- **When:** `PUT /api/v1/profile` with `goal` = each of `hypertrophy` / `strength` / `fat_loss` / `general_health` / `endurance` (dataset)
- **Expect:** `201`; `assertJsonPath('data.profile.goal', $value)`

**TC-8:** `days_per_week` out of range or non-integer → `422` (AC #2)
- **Given:** an authenticated user
- **When:** `PUT /api/v1/profile` with `days_per_week` = each of `0`, `8`, `-1`, `3.5`, `"abc"` (dataset)
- **Expect:** `422`; `assertJsonValidationErrors('days_per_week')`; `assertDatabaseCount('athlete_profiles', 0)`

**TC-9:** `session_minutes` out of range or non-integer → `422` (AC #2)
- **Given:** an authenticated user
- **When:** `PUT /api/v1/profile` with `session_minutes` = each of `9`, `241`, `0`, `"x"` (dataset)
- **Expect:** `422`; `assertJsonValidationErrors('session_minutes')`

**TC-10:** `notes` omitted → saved as `null` (AC #3)
- **Given:** an authenticated user with no profile
- **When:** `PUT /api/v1/profile` with the base payload minus `notes`
- **Expect:** `201`; `assertJsonPath('data.profile.notes', null)`; `assertDatabaseHas('athlete_profiles', ['user_id' => $user->id, 'notes' => null])`

**TC-11:** `notes` empty or whitespace-only → saved as `null` (AC #3)
- **Given:** an authenticated user with no profile
- **When:** `PUT /api/v1/profile` with `notes` = `""`, then `notes` = `"   "` (dataset)
- **Expect:** `201`; `assertJsonPath('data.profile.notes', null)`; `assertDatabaseHas('athlete_profiles', ['notes' => null])`

**TC-12:** `notes` length boundary
- **Given:** an authenticated user
- **When:** `PUT /api/v1/profile` with `notes` = a 2000-char string, then a 2001-char string
- **Expect:** the 2000-char save is `201`/`200`; the 2001-char save is `422` with `assertJsonValidationErrors('notes')`

**TC-13:** Unauthenticated request → `401`
- **Given:** no `actingAs` (the `Origin` header is still set)
- **When:** `PUT /api/v1/profile` with the base valid payload
- **Expect:** `401`; `assertDatabaseCount('athlete_profiles', 0)`

**TC-14:** Cross-user isolation — a save never touches another user's row
- **Given:** `$other = User::factory()->has(AthleteProfile::factory())->create()`; `actingAs($this->user)` (a different user)
- **When:** `PUT /api/v1/profile` with the base valid payload
- **Expect:** `201`; `assertDatabaseCount('athlete_profiles', 2)`; `$other`'s row is unchanged; the new row's `user_id === $this->user->id`

**TC-15:** The response never exposes the internal `id`
- **Given:** an authenticated user
- **When:** `PUT /api/v1/profile` with the base valid payload
- **Expect:** `assertJsonMissingPath('data.profile.id')`; `assertJsonStructure(['data' => ['onboarding_completed', 'profile' => ['experience_level', 'days_per_week', 'session_minutes', 'goal', 'notes', 'created_at', 'updated_at']]])`

**TC-16:** Enums serialise as strings, dates as ISO-8601
- **Given:** an authenticated user
- **When:** `PUT /api/v1/profile` with the base valid payload
- **Expect:** `data.profile.experience_level` is the string `"intermediate"` (not an object); `data.profile.goal` is `"hypertrophy"`; `data.profile.created_at` matches an ISO-8601 regex

**TC-17:** Strict-mode render guard — the Resource never triggers a lazy load
- **Given:** an authenticated user
- **When:** `PUT /api/v1/profile` then `GET /api/v1/profile`
- **Expect:** both responses are `2xx` with no `500`; `data.profile` has no `user` key

### GET `/api/v1/profile` — `tests/Feature/Profile/ShowAthleteProfileTest.php`

**TC-18:** Returns the saved profile
- **Given:** `AthleteProfile::factory()->for($user)->create(['experience_level' => ExperienceLevel::Advanced, 'days_per_week' => 5, 'session_minutes' => 75, 'goal' => Goal::Strength, 'notes' => 'prefers barbell'])`
- **When:** `GET /api/v1/profile` as `$user`
- **Expect:** `200`; `assertJsonPath('data.onboarding_completed', true)`; `data.profile` equals `{ experience_level: 'advanced', days_per_week: 5, session_minutes: 75, goal: 'strength', notes: 'prefers barbell', created_at: <iso>, updated_at: <iso> }`

**TC-19:** No profile → onboarding pending (AC #5)
- **Given:** an authenticated user with no `athlete_profiles` row
- **When:** `GET /api/v1/profile`
- **Expect:** `200`; `assertJsonPath('data.onboarding_completed', false)`; `assertJsonPath('data.profile', null)`

**TC-20:** Unauthenticated request → `401`
- **Given:** no `actingAs`
- **When:** `GET /api/v1/profile`
- **Expect:** `401`

**TC-21:** Cross-user isolation — a user only ever sees their own profile
- **Given:** `$other` has a profile with distinct values; `$this->user` has their own profile
- **When:** `GET /api/v1/profile` as `$this->user`
- **Expect:** `200`; the returned values are `$this->user`'s, never `$other`'s

**TC-22:** The response never exposes the internal `id`
- **Given:** a user with a profile
- **When:** `GET /api/v1/profile`
- **Expect:** `assertJsonMissingPath('data.profile.id')`; `assertJsonStructure(['data' => ['onboarding_completed', 'profile' => ['experience_level', 'days_per_week', 'session_minutes', 'goal', 'notes', 'created_at', 'updated_at']]])`

### Action — `tests/Feature/Profile/AthleteProfileUpdateActionTest.php`

**TC-23:** `handle()` upserts exactly one row and maps the DTO
- **Given:** a `User` with no profile and an `AthleteProfileData` built from valid input (`experienceLevel: ExperienceLevel::Beginner`, `daysPerWeek: 3`, `sessionMinutes: 45`, `goal: Goal::FatLoss`, `notes: null`)
- **When:** `app(AthleteProfileUpdateAction::class)->handle($user, $data)` is called, then called again with a changed `daysPerWeek`
- **Expect:** after the first call `assertDatabaseCount('athlete_profiles', 1)` with `experience_level = 'beginner'` and `days_per_week = 3`, and the return value's `wasRecentlyCreated` is `true`; after the second call the count is still `1`, `days_per_week` is updated, and `wasRecentlyCreated` is `false`

### DTO — `tests/Unit/Profile/AthleteProfileDataTest.php`

**TC-24:** `AthleteProfileData::from()` maps snake_case input and casts enums
- **Given:** the array `['experience_level' => 'beginner', 'days_per_week' => 3, 'session_minutes' => 45, 'goal' => 'strength']`
- **When:** `AthleteProfileData::from($array)` is built
- **Expect:** `experienceLevel === ExperienceLevel::Beginner`; `goal === Goal::Strength`; `daysPerWeek === 3`; `sessionMinutes === 45`; `notes === null`

### Policy — `tests/Unit/Profile/AthleteProfilePolicyTest.php`

**TC-25:** Ownership checks
- **Given:** `$owner` with an `AthleteProfile`, and `$stranger` (another user)
- **When:** the policy abilities are evaluated
- **Expect:** `view($owner, $profile)` and `update($owner, $profile)` are `true`; `view($stranger, $profile)` and `update($stranger, $profile)` are `false`; `create($stranger)` is `true`

### Architecture — `tests/Feature/ArchTest.php` (added rule)

**TC-26:** Profile controllers are invokable
- **Given:** the project code
- **When:** the Pest architecture assertions run
- **Expect:** `App\Http\Controllers\Profile` is invokable (new `arch(...)` line); the existing rules (`App\Actions\*` final + `handle()`, `App\Http\Requests\*` extends `FormRequest`, no debug helpers) still pass

---

## 9. Technical Decisions

| Decision area | What was decided | Why |
|---|---|---|
| HTTP verb | `PUT /api/v1/profile` for the write (not `POST` + `PATCH`) | AC #4 is upsert-on-a-singleton: the resource is addressed with no id, its URL never changes between first save and later edits. `PUT` is the idempotent "make the resource at this URL equal this representation" verb. `POST`+`PATCH` would push the create-vs-update decision onto the client. |
| Update granularity | Full replace — all fields required on every `PUT` (`notes` optional) | Keeps one contract for onboarding and editing; the body is always the complete profile. Confirmed with the product owner over "partial edits once created". |
| Status code | `201` on first create, `200` on update, from `wasRecentlyCreated` | Lets the SPA distinguish first onboarding from a later edit (analytics/routing) without a second request. Confirmed with the product owner over "always 200". |
| GET when no profile | `200` with `{ data: { onboarding_completed: false, profile: null } }` | AC #5 asks for an explicit "onboarding pending" **signal**, not an error. A `200` + boolean is the most direct encoding; `404` would make a normal onboarding state look like a failure and muddy real 404s. Confirmed with the product owner. |
| Response envelope | `AthleteProfileStatusResource` wrapping `{ onboarding_completed, profile }`, used by **both** endpoints | GET and PUT share one shape. Honours `CLAUDE.md` rule 3 (always a real `JsonResource`). `profile` nests `AthleteProfileResource`. |
| Public field name | `experience_level` in request and response (not `level`) | Consistency with the DB column and every other name in `docs/plans/data-model.md`; request and response round-trip the same key. Confirmed with the product owner. |
| Validation bounds | `days_per_week` `between:1,7`; `session_minutes` `between:10,240`; `notes` `max:2000` | A week has 7 days; `0` yields no plan. A 10-minute floor / 4-hour ceiling catches fat-finger input. `notes` bound protects the AI-prompt budget (the column itself is `text`). The enum sets come straight from the ticket. Confirmed with the product owner. |
| `notes` empty handling | Omitted / `null` / `""` / whitespace-only all persist as `null`, normalised in `prepareForValidation()` | AC #3. "Empty" and "not filled in" become one state; the AI planner never receives an empty string. Confirmed with the product owner. |
| Service layer | **None** — the Action calls `$user->athleteProfile()->updateOrCreate()` directly | No business knowledge: enum membership + bounds are shape (Form Request); "one per user" is the `hasOne` relation + `updateOrCreate` + the DB `unique`. A Service would be indirection only (`CLAUDE.md` rules 5–6). Mirrors the register spec. |
| GET Action | **None** — the controller does one scoped relation query + Resource | Zero business knowledge, no transaction, no side effect. An Action there would be pure indirection (`CLAUDE.md` rule 6). Register has an Action only for its `Auth::login` side effect. |
| Upsert mechanism | `$user->athleteProfile()->updateOrCreate([], [...])` on the `hasOne` relation | The relation scopes the lookup and the insert to `user_id = $user->id`, so the empty match array is correct. This is what guarantees AC #4; the DB `unique(user_id)` is the backstop. No `DB::transaction` (single statement). |
| Enum storage | Backed **string** enums; DB columns are `string`, not native Postgres `enum`; no `CHECK` | Portable across the Postgres runtime and the SQLite test DB. Cast + `Rule::enum` enforce membership. Matches `docs/plans/data-model.md` §Enums (`TitleCase` cases, DB stores the value). |
| Enum locations | `App\Enums\Profile\ExperienceLevel`, `App\Enums\Shared\Goal` | `Goal` is cross-domain (routines carry their own `goal`) → `Shared`; `ExperienceLevel` is profile-only. Exactly as planned in `docs/plans/data-model.md`. |
| `id` in the body | Not exposed on either endpoint | `athlete_profiles` is an internal table (no `uuid`, addressed as "the current user's profile"). Consistent with `UserResource`. |
| DTO | `App\Data\Profile\AthleteProfileData` (`spatie/laravel-data`), `readonly` promoted props, `#[MapInputName(SnakeCaseMapper::class)]` | `CLAUDE.md` convention (writes take a `Data` object). Global name mapping is off in `config/data.php`, so the class maps `snake_case` input to camelCase props itself. `validation_strategy = OnlyRequests` → `::from($request->validated())` does not re-validate; the Form Request is the single authority. spatie casts the enum strings via the global `BackedEnum` cast. |
| Authorization enforcement | `AthleteProfilePolicy` (auto-discovered) for the rule-4 gate; real isolation by query-scoping to `$request->user()->athleteProfile()` | No route accepts a profile id, so cross-user access is structurally impossible. The Policy formalises the rule and is delegated to from each Form Request's `authorize()`. |
| `AuthServiceProvider` | Not created | Laravel 13 resolves `App\Policies\{Model}Policy` by convention with zero wiring. A provider would only be needed for a non-conventional name. |
| Route grouping | One `Route::middleware('auth:sanctum')->group(...)` for both routes | Two routes share the middleware and the domain will grow (routines, cycles, sessions). Keeps `routes/api.php` in its existing plain-`Route::` style; `use` imports at the top for PHPStan. |
| Rate limiting | None on the profile routes | Authenticated, low-abuse. `register`'s `throttle:6,1` exists because it is public and unauthenticated. |
| Scramble `security_strategy` | Enable now: `MiddlewareAuthSecurityStrategy` with an **apiKey / cookie** scheme (`config('session.cookie')`), not bearer | The register spec deferred this "until the first protected route exists" — this is that route. The API is Sanctum SPA (session cookie + CSRF); the default bearer scheme would misrepresent it. `/api/v1/register` becomes `security: []`. |
| Model strictness | The relation is always fetched via an explicit query (`->first()` / `->updateOrCreate()`); the Resource reads only own columns | `Model::shouldBeStrict(!isProduction())` makes a lazy relation load throw. Nothing in the pipeline touches `$user->athleteProfile` as a lazy property or `$profile->user`. |
| Tests: DB | SQLite `:memory:` + `RefreshDatabase` (already wired); no `phpunit.xml` change | This is not the first DB-touching suite (register established it). The runtime Postgres clone is only for the dev migration. |
| Git artifacts | English only; **no** `Co-Authored-By: Claude` / `Claude-Session:` commit trailers; PR description with **no** "Generated with Claude Code" footer | Repo `CLAUDE.md` / `AGENTS.md` rule, confirmed to take precedence over the session's attribution instruction. |

---

## 10. Work Plan

Pipeline classes are created before wiring `routes/api.php` (which references
them). Tasks whose artifact is a single pipeline class are not independently
shippable — tasks 18–19 (feature suites) are the functional gate; each earlier
task's DoD is limited to the artifact existing, passing Pint + PHPStan level 6,
and (where it carries logic) a focused unit assertion.

| # | Task | Definition of Done |
|---|---|---|
| 1 | Clone the runtime DB for isolation: `docker compose exec pgsql createdb -U gym -T gym_trainer gym_trainer_create_user_profile`; set `DB_DATABASE=gym_trainer_create_user_profile` in this worktree's `.env` | `docker compose exec app php artisan db:show` targets the clone; `gym_trainer` is untouched; the Pest suite still uses SQLite. |
| 2 | Create `app/Enums/Shared/Goal.php` (string-backed, 5 `TitleCase` cases) and `app/Enums/Profile/ExperienceLevel.php` (3 cases) | Both files exist; `Goal::from('fat_loss') === Goal::FatLoss`; Pint + PHPStan level 6 clean. |
| 3 | Create the migration `<ts>_create_athlete_profiles_table.php` per §4.1 (anonymous class; `user_id` unique + `constrained()->cascadeOnDelete()`; enum columns as `string`; `notes` nullable text) | `docker compose exec app php artisan migrate` runs on the clone and on a fresh SQLite; `php artisan db:table athlete_profiles` shows the `unique` index on `user_id` and the FK. |
| 4 | Create `app/Models/AthleteProfile.php`: `#[Fillable([...6 columns])]`, `casts()` (`experience_level` / `goal` enum casts, two `integer` casts), `user(): BelongsTo`; add `athleteProfile(): HasOne` to `app/Models/User.php` | Pint + PHPStan clean; in a scratch test `AthleteProfile::factory()->for(User::factory())->create()` persists one row and `$user->athleteProfile()->exists()` is `false` for a fresh user. |
| 5 | Create `database/factories/AthleteProfileFactory.php` (§Model → Factory): valid random enum via `->randomElement(...::cases())`, `days_per_week` 2–6, `session_minutes` ∈ `[30,45,60,75,90]`, `notes => fake()->optional()->sentence()` | `AthleteProfile::factory()->create()` and `User::factory()->has(AthleteProfile::factory())->create()` both work; Pint + PHPStan clean. |
| 6 | Run `docker compose exec app php artisan ide-helper:models --write` for `AthleteProfile` + `User`; Pint the two models; hand-check the enum-cast `@property` lines | The PHPDoc blocks list every column/relation; `composer check`'s PHPStan step sees the new `@property` / `@method`; the diff is limited to the two models. |
| 7 | Create `app/Data/Profile/AthleteProfileData.php` via `make:data`, move into `app/Data/Profile/`, fix the namespace; `#[MapInputName(SnakeCaseMapper::class)]`; `readonly` promoted props `ExperienceLevel $experienceLevel`, `int $daysPerWeek`, `int $sessionMinutes`, `Goal $goal`, `?string $notes = null` | `AthleteProfileData::from(['experience_level' => 'beginner', 'days_per_week' => 3, 'session_minutes' => 45, 'goal' => 'strength'])` builds with enums cast and `notes` `null` (TC-24); Pint + PHPStan clean. |
| 8 | Create `app/Policies/AthleteProfilePolicy.php` (`final`, `view` / `create` / `update` per §5.2) | `Gate::getPolicyFor(AthleteProfile::class)` resolves it via auto-discovery; TC-25 passes; Pint + PHPStan clean. |
| 9 | Create `app/Http/Requests/Profile/UpdateAthleteProfileRequest.php`: `authorize()` delegating to the Policy (`create` when no row, `update` against the instance, `false` for a `null` user); `rules()` per §2.1; `prepareForValidation()` mapping blank `notes` → `null` | File exists; Pint + PHPStan clean; a unit assertion that `experience_level = 'expert'` fails and a whitespace-only `notes` becomes `null`. |
| 10 | Create `app/Http/Requests/Profile/ShowAthleteProfileRequest.php`: `rules(): []`; `authorize()` — `null` user → `false`, else `view` (or `true` when no row) | File exists; Pint + PHPStan clean. |
| 11 | Create `app/Http/Resources/Profile/AthleteProfileResource.php` (`@mixin AthleteProfile`): `experience_level` / `goal` as `->value`, the two ints, `notes`, `created_at` / `updated_at` as `?->toIso8601String()`; **no `id`** | File exists; Pint + PHPStan clean; no `id` key in `toArray()`. |
| 12 | Create `app/Http/Resources/Profile/AthleteProfileStatusResource.php`: constructor `?AthleteProfile $profile`; `toArray()` → `{ onboarding_completed: $profile !== null, profile: $profile ? (new AthleteProfileResource($profile))->toArray($request) : null }` | File exists; Pint + PHPStan clean; `AthleteProfileStatusResource::make(null)` renders `{ onboarding_completed: false, profile: null }`. |
| 13 | Create `app/Actions/Profile/AthleteProfileUpdateAction.php` (`final`, `handle(User, AthleteProfileData): AthleteProfile` → `$user->athleteProfile()->updateOrCreate([], [...])`) | `final` + `handle()`; TC-23 passes (create then update keeps count 1, `wasRecentlyCreated` flips); Pint + PHPStan clean. |
| 14 | Create `app/Http/Controllers/Profile/UpdateAthleteProfileController.php` via `make:controller --invokable`, move + fix namespace: build the DTO, call the Action, return `AthleteProfileStatusResource::make($profile)->response()->setStatusCode($profile->wasRecentlyCreated ? 201 : 200)` | `final`, `__invoke` only; Pint + PHPStan clean. |
| 15 | Create `app/Http/Controllers/Profile/ShowAthleteProfileController.php` (`final`, `__invoke` → `AthleteProfileStatusResource::make($request->user()->athleteProfile()->first())`) | `final`, `__invoke` only; Pint + PHPStan clean. |
| 16 | Edit `routes/api.php`: add `use` imports for both controllers and a `Route::middleware('auth:sanctum')->group(...)` with `GET profile` → `profile.show` and `PUT profile` → `profile.update` | `docker compose exec app php artisan route:list` shows `GET|PUT api/v1/profile` with `auth:sanctum` middleware; PHPStan clean in `routes/`. |
| 17 | Edit `config/scramble.php`: set `security_strategy` to `MiddlewareAuthSecurityStrategy` with a `SecurityScheme::apiKey('cookie', config('session.cookie'))` scheme; verify the helper signature against `composer show dedoc/scramble` first | `GET /docs/api.json` marks `/api/v1/profile` with `security` and `/api/v1/register` with `security: []`; the doc build logs no errors. |
| 18 | Write `tests/Feature/Profile/UpdateAthleteProfileTest.php` covering TC-1 … TC-17 (`beforeEach` sets the `Origin` header) | `docker compose exec app vendor/bin/pest tests/Feature/Profile/UpdateAthleteProfileTest.php` all green; every TC-1…TC-17 has a corresponding test. |
| 19 | Write `tests/Feature/Profile/ShowAthleteProfileTest.php` covering TC-18 … TC-22 | `docker compose exec app vendor/bin/pest tests/Feature/Profile/ShowAthleteProfileTest.php` all green. |
| 20 | Write `tests/Feature/Profile/AthleteProfileUpdateActionTest.php` (TC-23) and `tests/Unit/Profile/AthleteProfileDataTest.php` (TC-24) + `tests/Unit/Profile/AthleteProfilePolicyTest.php` (TC-25) | The three files run green in isolation. |
| 21 | Add the `arch('profile controllers are invokable')->expect('App\Http\Controllers\Profile')->toBeInvokable()` line to `tests/Feature/ArchTest.php` (TC-26) | `docker compose exec app vendor/bin/pest tests/Feature/ArchTest.php` green. |
| 22 | Run `docker compose exec app vendor/bin/pint --dirty`, then `vendor/bin/phpstan analyse`, then a final `php artisan ide-helper:models --write` + Pint on the models | Pint reports no diffs; PHPStan level 6 clean; the model PHPDoc is in sync with the migration. |
| 23 | Run `docker compose exec app composer check` (Pint `--test` + PHPStan level 6 + full Pest) | All three steps green. |
| 24 | Manual check with `curl` against `http://localhost:8000`: `GET /sanctum/csrf-cookie` → `PUT /api/v1/profile` (`201`, `onboarding_completed:true`) → `PUT` again (`200`) → `GET` (`200`) → invalid `experience_level` (`422` on the field) → no session (`401`); review `GET /docs/api` | The `curl` calls return the expected codes; the endpoints appear in Scramble with the request inferred from `UpdateAthleteProfileRequest` and the response from `AthleteProfileStatusResource`, marked secured. |
| 25 | On merge / branch drop: `docker compose exec pgsql dropdb -U gym --if-exists gym_trainer_create_user_profile`; revert `DB_DATABASE` in `.env` | The clone is gone; `.env` is restored to `gym_trainer`. |

*Process note: branch name, commit messages and PR text follow `CLAUDE.md` /
`AGENTS.md` — English only, no `Co-Authored-By: Claude` / `Claude-Session:`
trailers, no "Generated with Claude Code" footer.*
