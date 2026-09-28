# Delete a training session — `DELETE /api/v1/sessions/{session}`

> Derived from a direct request from the product owner in this session (no
> Notion ticket — this feature is not yet backlogged), refined through the
> `brainstorming` skill in the same conversation. Base contract:
> `docs/product-context.md` §2 ("Sesión"), `CLAUDE.md` "The pipeline" /
> "Layout" / "Conventions", `docs/plans/create-training-session-spec.md`
> (`TrainingSession` model, `SessionStatus` enum, `HasPublicUuid`,
> `TrainingSessionOpeningService`'s one-open-session-at-a-time rule),
> `docs/plans/store-set-logs-spec.md` (`set_logs.session_id`
> `cascadeOnDelete()`, the `App\Exceptions\Session\` `DomainException`
> subclasses), `docs/plans/complete-session-spec.md` (the shipped, direct
> precedent this builds on — `TrainingSessionPolicy`, `SessionAlreadyCompletedException`,
> the guard-Service pattern, the `sessions/{session}/...` route group), and
> `docs/plans/domain-exception-handling-spec.md` (the `DomainException` base
> and the `{ "data": { "code", "message" } }` error envelope).

## 1. Context

**Kind:** Brownfield Feature — the Session domain (open, log/update sets,
complete, AI analysis) already ships. This ticket adds the missing
**undo-a-mistake** slice: a user who opened a session by accident, or opened a
duplicate, or is simply stuck because `TrainingSessionOpeningService` refuses
a second concurrent `in_progress` session (`SessionInProgressException`), has
no way to get rid of the stray session and unblock themselves. This ticket
adds exactly one route, one Form Request, one Policy ability, one Action, one
Service — no schema change, no new exception, no new DTO.

**Product-owner decisions recorded from this session's brainstorming
conversation:**

- **Scope: only an `in_progress` session can be deleted.** The use case is
  correcting an error (an accidental or duplicate open session, or an
  unfinished one the user wants to discard), never rewriting closed history.
  A `completed` session — and anything the AI analysis derived from it — is
  permanent, exactly like an `archived` routine (`docs/product-context.md`
  §2: routines keep "todo su historial ... para consulta"). Deleting a
  `completed` session is out of scope entirely, not just gated — there is no
  "admin override" or force flag.
- **Hard delete, not soft delete.** No model in this codebase uses
  `SoftDeletes` today. Restricting deletion to `in_progress` sessions already
  guarantees nothing worth preserving is lost: an `in_progress` session can
  never be the `source_session_id` of an `ExerciseRecommendation` (only
  `SessionAnalyzeAction`, which runs after completion, sets that column), so
  a physical `DELETE` row never orphans a recommendation's traceability
  either.
- **Reuse the existing `SessionAlreadyCompletedException`** (409
  `SESSION_ALREADY_COMPLETED`, shipped in PR #19 / `store-set-logs-spec.md`,
  reused for the closing flow in PR #20 / `complete-session-spec.md`) for the
  "not `in_progress`" guard, instead of adding a new exception class.
  The underlying condition is identical to the one `SessionCloseAction`
  already reports under this code — the session is `completed` — and a
  second exception that means the same thing would be pure indirection
  (`CLAUDE.md` rule 6).

**In scope:**

- **`DELETE /api/v1/sessions/{session}`** — permanently delete an
  `in_progress` session the caller owns, and every `set_log` logged into it
  (cascade, already enforced at the database level). `204 No Content` on
  success, empty body.
- `App\Http\Requests\Session\DeleteTrainingSessionRequest` — no body fields;
  authorization only, via `TrainingSessionPolicy::delete`.
- `App\Http\Controllers\Session\DeleteTrainingSessionController` — invokable.
- `App\Actions\Session\TrainingSessionDeleteAction` — the only layer that
  opens the transaction.
- `App\Services\Session\SessionDeletionService` — the one business guard
  (must be `in_progress`).
- `App\Policies\TrainingSessionPolicy::delete(User, TrainingSession)` — new
  ability alongside the existing `create` / `complete`.
- One route added to the `auth:sanctum` group in `routes/api.php`
  (`sessions.destroy`), constrained with `->whereUuid('session')`.
- `tests/Feature/Auth/DocsSecurityTest.php` — assert the new route inherits
  the global `security` (no new `ArchTest` rule needed: `App\Http\Controllers\Session`
  is already covered by "session controllers are invokable"; `App\Actions`,
  `App\Services`, `App\Http\Requests` are covered by their own existing
  blanket rules).
- Pest feature + unit coverage of every acceptance criterion below.

**Out of scope:**

- **Deleting a `completed` session**, in any form — no force flag, no
  cascading "also delete its recommendations" behavior, no admin/support
  override. Always `409 SESSION_ALREADY_COMPLETED`.
- **Soft delete / `deleted_at` / a trash-and-restore flow.** Explicitly
  rejected — see "Product-owner decisions" above.
- **Any change to `ExerciseRecommendation` or `exercise_recommendations`.**
  Deleting an `in_progress` session cannot affect them (see "hard delete"
  decision above); this ticket touches nothing in the Recommendation domain.
- **Bulk delete / delete-by-routine / delete-on-routine-archive.** One
  session, one request, explicit user action only.
- **Any change to `TrainingSessionOpeningService`, `SessionCompletionService`,
  or `SessionAnalystService`.** This ticket only adds a new guard/Service; it
  does not touch the opening, completion, or analysis pipelines beyond the
  natural consequence that deleting the blocking `in_progress` session lets
  `TrainingSessionOpeningService::guard()` pass on the next open attempt
  (documented in §7, not implemented differently).
- The `gym-trainer-spa/` frontend (separate repository) — no screen ticket
  exists yet for this action.

---

## 2. API Surface

### 2.1 REST

The route joins the existing `Route::middleware('auth:sanctum')->group(...)`
in `routes/api.php`, under the global `apiPrefix: 'api/v1'`. It is stateful
(`$middleware->statefulApi()` in `bootstrap/app.php`) — subject to
`EnsureFrontendRequestsAreStateful` + CSRF. CSRF is auto-bypassed under
`php artisan test` (`ValidateCsrfToken::runningUnitTests()`).

| Method | Path | Auth | Request | Response | Status codes |
|---|---|---|---|---|---|
| DELETE | `/api/v1/sessions/{session}` | `auth:sanctum` (session cookie, `web` guard) + `TrainingSessionPolicy::delete` (via the Form Request) | — (no body) | — (empty body) | `204` No Content · `409` `SESSION_ALREADY_COMPLETED` · `401` unauthenticated · `403` `AUTHORIZATION_EXCEPTION` (`{session}` owned by another user) · `404` `NOT_FOUND_EXCEPTION` (`{session}` uuid unknown or not a uuid) · `419` stateful request without a valid CSRF token |

Notes:

- **`{session}` binding.** `->whereUuid('session')` — a non-uuid segment never
  matches → `404`. Implicit binding resolves `{session}` to `TrainingSession`
  by `uuid` (`HasPublicUuid::getRouteKeyName()`); an unknown uuid →
  `ModelNotFoundException` → `404` (`NOT_FOUND_EXCEPTION`). The Policy runs
  after binding.
- **No request body, no `Data` DTO.** There is nothing to validate or carry
  between layers, so `DeleteTrainingSessionRequest::rules()` returns `[]` and
  no `App\Data\Session\...Data` class is introduced — an empty DTO would be
  pure indirection (`CLAUDE.md` rule 6).
- **Cascade delete.** Every `set_logs` row for the session is removed by the
  database's own `cascadeOnDelete()` on `set_logs.session_id` (shipped in
  `store-set-logs-spec.md`, PR #19) — the Action issues one `$session->delete()`
  and nothing iterates `set_logs` in PHP. `exercise_id` is never touched
  (`restrictOnDelete()` on `set_logs.exercise_id` — the catalogue is
  permanent and this ticket never deletes an exercise).
- **The session's `cycle_day`, if any, is untouched.** `cycle_day_id` is a
  column on `training_sessions`, not the other way around; deleting a
  *planned* `in_progress` session removes only that session row (and its
  sets), never the `cycle_days` row it pointed at.
- **State guard, not authorization.** Whether the session is `in_progress` is
  a business rule (`SessionDeletionService` guard → `409`), not a Policy
  concern — an owned `completed` session still authorizes; it fails later, at
  the Service. Same split `complete-session-spec.md` already established.
- Errors are rendered as JSON by `App\Exceptions\ApiExceptionRenderer` (wired
  for `api/*` in `bootstrap/app.php`) as
  `{ "data": { "code": "...", "message": "..." } }`. No hand-built JSON, no
  exceptions to the envelope for this endpoint (it has no validation rules to
  produce a `data.errors` map).

### 2.2 CLI

Not applicable — no CLI commands.

### 2.3 Events

Not applicable — no jobs, no events. Deletion is synchronous and immediate;
nothing is dispatched.

---

## 3. UI

### 3.1 Pages

Not applicable — no pages affected. This is a JSON REST API; the
`gym-trainer-spa/` frontend is a separate, unspecced repository concern.

### 3.2 Components

Not applicable — no components affected.

---

## 4. Database

Not applicable — no schema or seed changes. The cascade this endpoint relies
on (`set_logs.session_id` → `cascadeOnDelete()`) already exists, shipped in
`database/migrations/2026_09_03_130000_create_set_logs_table.php`
(`store-set-logs-spec.md`, PR #19); this ticket adds no migration.

---

## 5. Auth & Authorization

### 5.1 Authentication

**Method:** Session (cookie) via **Laravel Sanctum in SPA / stateful mode** —
the same mechanism as every other endpoint. `auth:sanctum` on the route group
authenticates from the session cookie; unauthenticated → `AuthenticationException`
→ `401` JSON. `DELETE` is a stateful non-GET request → requires a valid
`XSRF-TOKEN` (`419` otherwise), auto-bypassed under `php artisan test`.

### 5.2 Authorization

**`TrainingSessionPolicy`**, already auto-discovered for `App\Models\
TrainingSession`. This ticket adds one ability alongside the existing
`create` / `complete`:

| Role | Permissions |
|---|---|
| Authenticated user | Delete a **training session they own** — `TrainingSessionPolicy::delete(User $user, TrainingSession $session): bool` returns `$session->user_id === $user->id`. No other actor, no other permission added by this ticket. |

- `DeleteTrainingSessionRequest::authorize()` →
  `$this->user()?->can('delete', $this->route('session')) ?? false`. Same
  instance-based `can()` shape as `CompleteTrainingSessionRequest::authorize()`
  — the session already exists at delete time, unlike `create`'s
  not-yet-created-resource shape. Foreign session → `AuthorizationException`
  → `403`.
- The session's `in_progress` / `completed` state is a **business rule**
  (Service guard → `409`), **not** authorization. An owned `completed`
  session still authorizes; it fails later, at the Service (§9).

---

## 6. Configuration

Not applicable — no environment variables, no config file changes.

**Config / non-source files modified:**

| File | Change |
|---|---|
| `routes/api.php` | Add a `use` import for `DeleteTrainingSessionController`; inside the existing `auth:sanctum` group, after the `sessions.complete` route, add `DELETE sessions/{session}` → `DeleteTrainingSessionController` (`sessions.destroy`, `->whereUuid('session')`). |
| `tests/Feature/Auth/DocsSecurityTest.php` | Add one `->and($spec['paths']['/api/v1/sessions/{session}']['delete'])->not->toHaveKey('security')` assertion. |

No change to `bootstrap/app.php`, `config/*`, `bootstrap/providers.php`,
`phpunit.xml`, `composer.json`, `tests/Feature/ArchTest.php` (every new class
in this ticket is already covered by an existing blanket rule — "actions are
final and expose handle()", "services are final", "session controllers are
invokable", "form requests extend FormRequest"), `docs/plans/data-model.md`
(no schema change), or `docs/product-context.md` (no new domain vocabulary —
§2's definition of "Sesión" does not need a deletion clause to stay accurate;
adding one would be documentation churn with no reader value, `CLAUDE.md`
rule 5).

---

## 7. Current vs New Behavior

| Behavior | Current | New |
|---|---|---|
| Deleting a session | Impossible — no `DELETE` route exists for `TrainingSession`, at all. | `DELETE /api/v1/sessions/{session}` permanently removes an `in_progress` session the caller owns, plus its `set_logs` (cascade). `204` on success. |
| A `completed` session | Immutable already (its sets can't be logged or changed — `SESSION_ALREADY_COMPLETED`). | Still immutable — now *also* undeletable, same `409 SESSION_ALREADY_COMPLETED`. No new terminal state is introduced. |
| Opening a new session while one is stuck `in_progress` | Always blocked by `TrainingSessionOpeningService::guard()` → `409 SESSION_IN_PROGRESS`, with no way to clear the stuck session short of completing it (which requires ≥ 1 logged set, `SESSION_HAS_NO_SETS` otherwise). | The user can now `DELETE` the stuck `in_progress` session first, then open a new one normally — this is the direct motivation for this ticket. `TrainingSessionOpeningService` itself is unchanged; the guard simply finds no `in_progress` row left to trip on. |
| `TrainingSessionPolicy` | `create(User, Routine)`, `complete(User, TrainingSession)`. | Adds `delete(User, TrainingSession)`. |
| Authenticated routes | `auth:sanctum` group holds auth, profile, routine, session-open, set-logging, and session-complete routes. | Adds `DELETE sessions/{session}`. |
| `set_logs` rows for a deleted session | N/A — a session was never deletable, so its sets were never removable except one-by-one (there is no `DELETE .../sets/{set}` endpoint either). | Removed as a side effect of `$session->delete()`, via the pre-existing `set_logs.session_id` `cascadeOnDelete()` foreign key. No application code iterates or deletes `set_logs` directly. |

---

## 8. Test Cases

Executable with Pest 4 on SQLite `:memory:` (`RefreshDatabase`, already
wired; `DB_FOREIGN_KEYS` defaults to `true` in `config/database.php`, so the
SQLite test connection enforces the same `cascadeOnDelete()` / `restrictOnDelete()`
foreign keys as the PostgreSQL runtime). Feature tests' `beforeEach` sets
`$this->withHeader('Origin', config('app.url'))`, then
`$this->user = User::factory()->create()`. Reuses `openFreeSession(User)` /
`openPlannedSession(User)` from `tests/Helpers.php`.

### DELETE `/api/v1/sessions/{session}` — `tests/Feature/Session/DeleteTrainingSessionTest.php`

**TC-1:** Deletes an `in_progress` free session — happy path
- **Given:** an authenticated user; `$session = openFreeSession($user)`
- **When:** `DELETE /api/v1/sessions/{$session->uuid}`
- **Expect:** `204`; empty response body; `assertDatabaseMissing('training_sessions', ['id' => $session->id])`

**TC-2:** Deletes an `in_progress` planned session without touching its `cycle_day`
- **Given:** an authenticated user; `$session = openPlannedSession($user)` (tied to a real `cycle_day`)
- **When:** `DELETE /api/v1/sessions/{$session->uuid}`
- **Expect:** `204`; `assertDatabaseMissing('training_sessions', ['id' => $session->id])`; `assertDatabaseHas('cycle_days', ['id' => $session->cycle_day_id])`

**TC-3:** Deleting a session cascades its `set_logs`, but never its exercises
- **Given:** an authenticated user; `$session = openFreeSession($user)`; `$exercise = Exercise::factory()->create()`; `$set = SetLog::factory()->for($session, 'session')->for($exercise)->create()`
- **When:** `DELETE /api/v1/sessions/{$session->uuid}`
- **Expect:** `204`; `assertDatabaseMissing('set_logs', ['id' => $set->id])`; `assertDatabaseHas('exercises', ['id' => $exercise->id])`

**TC-4:** Deleting the stuck `in_progress` session unblocks opening a new one (AC / motivating scenario)
- **Given:** an authenticated user with a routine; `$session = openFreeSession($user)` (so `TrainingSessionOpeningService` would reject a second open with `SESSION_IN_PROGRESS`)
- **When:** `DELETE /api/v1/sessions/{$session->uuid}` (expect `204`), then `POST /api/v1/routines/{$session->routine->uuid}/sessions` with `{}`
- **Expect:** the second request returns `201`, not `409 SESSION_IN_PROGRESS`

**TC-5:** An already-`completed` session cannot be deleted — `409 SESSION_ALREADY_COMPLETED`
- **Given:** an authenticated user; `$session = TrainingSession::factory()->for($user)->for(Routine::factory()->for($user))->completed()->create()`; one set logged against it
- **When:** `DELETE /api/v1/sessions/{$session->uuid}`
- **Expect:** `409`; `assertJsonPath('data.code', 'SESSION_ALREADY_COMPLETED')`; `assertDatabaseHas('training_sessions', ['id' => $session->id])`; the set log is still present

**TC-6:** `{session}` belongs to another user → `403`, session unchanged
- **Given:** `$other = User::factory()->create()`; `$otherSession = openFreeSession($other)`; `actingAs($this->user)`
- **When:** `DELETE /api/v1/sessions/{$otherSession->uuid}`
- **Expect:** `403`; `assertJsonPath('data.code', 'AUTHORIZATION_EXCEPTION')`; `assertDatabaseHas('training_sessions', ['id' => $otherSession->id])`

**TC-7:** Unknown / non-uuid `{session}` → `404` (dataset)
- **Given:** an authenticated user
- **When:** `DELETE /api/v1/sessions/{(string) Str::uuid()}` and `DELETE /api/v1/sessions/42`
- **Expect:** each `404`; for the uuid case `assertJsonPath('data.code', 'NOT_FOUND_EXCEPTION')`

**TC-8:** Unauthenticated → `401`
- **Given:** no `actingAs`; a session owned by another factory user
- **When:** `DELETE /api/v1/sessions/{$session->uuid}`
- **Expect:** `401`; `assertJsonPath('data.code', 'AUTHENTICATION_EXCEPTION')`; session unchanged

### Unit — `tests/Unit/Session/SessionDeletionServiceTest.php`

**TC-9:** `guard()` throws `SessionAlreadyCompletedException` for a `completed` session
- **Given:** a `TrainingSession` built in-memory (no DB) with `status = SessionStatus::Completed`
- **When:** `app(SessionDeletionService::class)->guard($session)`
- **Expect:** throws `SessionAlreadyCompletedException`

**TC-10:** `guard()` does not throw for an `in_progress` session
- **Given:** a `TrainingSession` built in-memory (no DB) with `status = SessionStatus::InProgress`
- **When:** `app(SessionDeletionService::class)->guard($session)`
- **Expect:** no exception thrown

---

## 9. Technical Decisions

| Decision area | What was decided | Why |
|---|---|---|
| Scope | Only an `in_progress` session can be deleted; a `completed` one never can, with no override. | Product-owner decision (this session's brainstorming): the use case is correcting mistakes, not rewriting closed history — a `completed` session is permanent, same guarantee `docs/product-context.md` §2 already gives an `archived` routine. |
| Delete strategy | Hard delete (`$session->delete()`), no `SoftDeletes`. | No model in this codebase uses `SoftDeletes`; restricting scope to `in_progress` already guarantees nothing worth preserving (no `ExerciseRecommendation` can reference an `in_progress` session — only `SessionAnalyzeAction`, post-completion, sets `source_session_id`), so a physical delete carries no hidden data loss. |
| Guard exception | Reuses the **existing** `SessionAlreadyCompletedException` (`409 SESSION_ALREADY_COMPLETED`) instead of a new exception. | Same underlying condition `SessionCloseAction` already reports under this exact code — the session is `completed`. A second exception meaning the same thing would be needless indirection (`CLAUDE.md` rule 6). Its message text ("Its sets can no longer be changed") stays accurate in spirit: a `completed` session's sets *and* the session row itself are both immutable for the same reason. |
| Guard placement | A new, single-guard `SessionDeletionService` (not an inline check on the Action). | Matches `SessionCompletionService`'s precedent exactly: business guard clauses live in a Service, never inline on an Action (`CLAUDE.md`: "The pipeline" → Service). One guard is enough to earn the class — mirrors `store-set-logs-spec.md`'s single-guard-per-Service cases, not `SessionCompletionService`'s two-guard one. |
| Request DTO | None. `DeleteTrainingSessionRequest::rules()` returns `[]`; `TrainingSessionDeleteAction::handle(TrainingSession $session): void` takes no `Data` parameter. | There is no input to type or carry between layers — introducing an empty `Data` class purely to match the general "writes take a `Data` object" convention would be indirection with no payload (`CLAUDE.md` rule 6). |
| Action return type | `void`, not the deleted `TrainingSession`. | The row no longer exists once `handle()` returns; returning a now-deleted model instance would invite a caller to treat it as still-live data. The controller needs nothing back — it returns `response()->noContent()` regardless. |
| Response shape | `204 No Content`, empty body — no `JsonResource`. | `CLAUDE.md` rule 3's explicit carve-out: "A no-content action returns `response()->noContent()` (204)." Matches the existing `LogoutController` precedent exactly. |
| Cascade mechanism | Relies entirely on the pre-existing `set_logs.session_id` `cascadeOnDelete()` foreign key; no PHP code deletes `set_logs` rows. | That FK already exists (`store-set-logs-spec.md`, PR #19) specifically so a session's sets cannot outlive it. Re-implementing the cascade in PHP would duplicate a guarantee the database already gives atomically, and risks drifting out of sync with it. |
| Route naming | `sessions.destroy`, the standard Laravel resourceful verb for a `DELETE`-on-resource route. | Distinct from this file's custom-action names (`sessions.complete`) — this endpoint deletes the resource itself, the textbook case the `.destroy` convention exists for. |
| Authorization vs business state | Policy checks ownership only; "not `in_progress`" is a `DomainException` thrown from the Service, not a Policy failure. | Same split `complete-session-spec.md` established: an owned-but-wrong-state resource is a `409` business conflict, never a `403`. |
| No routine-active re-check | Deleting does not re-verify the session's parent routine is still `active`. | Matches the existing precedent for every other session-mutation endpoint (`complete`, the set-logging endpoints) — none of them re-check routine state either. |
| `TrainingSessionOpeningService` interaction | Left entirely unchanged. Deleting the blocking `in_progress` session is sufficient by itself — its guard already only checks for an *existing* `in_progress` row (`§7`). | No code change needed to produce the desired unblocking effect; changing that Service for this ticket would be scope creep into a pipeline this ticket doesn't own. |
| No `docs/product-context.md` change | Not touched. | §2's "Sesión" definition doesn't gain new vocabulary from this ticket — it is still "un día de entrenamiento realmente ejecutado"; deletion is an operational affordance for correcting mistakes, not a new domain concept worth documenting there (`CLAUDE.md` rule 5). |
| Tests: DB only, no AI, no queue | SQLite `:memory:` + `RefreshDatabase` (already wired). No AI agent, no job, no `Bus::fake` needed anywhere in this ticket. | Nothing in this slice touches `laravel/ai` or `ShouldQueue`; keeping the test file free of unrelated fakes keeps it a direct precondition → request → assertion read. |
| Git artifacts | English only. **No AI attribution anywhere** — no `Co-Authored-By: Claude` / `Claude-Session:` commit trailers, no `🤖 Generated with Claude Code` (or any "generated by" / tool-credit) line in a commit message, PR title, PR description or review comment. | Repo `CLAUDE.md` / `AGENTS.md` "Git" rule; it takes precedence over any session-level attribution instruction. |

---

## 10. Work Plan

Pipeline classes are created before wiring `routes/api.php`. Each task's DoD
is the artifact existing, passing Pint + PHPStan level 6, and — where the
class carries logic — its focused test authored in the same task. Task 6 (the
endpoint feature test) is the functional gate. No schema change in this
ticket, so none of the `CLAUDE.md` "Workflows — database isolation" worktree
/ database-clone steps apply — `docker compose exec app` is used directly
throughout.

| # | Task | Definition of Done |
|---|---|---|
| 1 | Add `delete(User $user, TrainingSession $session): bool => $session->user_id === $user->id` to `app/Policies/TrainingSessionPolicy.php`, alongside the existing `create` / `complete`, with a doc-comment matching their style | Pint + PHPStan clean; method present, same signature/shape as `complete`. |
| 2 | Create `app/Services/Session/SessionDeletionService.php` (`final`): `guard(TrainingSession $session): void` — `throw_unless($session->status === SessionStatus::InProgress, new SessionAlreadyCompletedException)`. Write `tests/Unit/Session/SessionDeletionServiceTest.php` (TC-9, TC-10) | `vendor/bin/pest tests/Unit/Session/SessionDeletionServiceTest.php` green; Pint + PHPStan clean. |
| 3 | Create `app/Actions/Session/TrainingSessionDeleteAction.php` (`final`, constructor-injects `SessionDeletionService`): `handle(TrainingSession $session): void` — `DB::transaction` closure: `$this->deletion->guard($session)`; `$session->delete()` | `final` + `handle()`; Pint + PHPStan clean; covered indirectly by the feature tests in task 5. |
| 4 | Create `app/Http/Requests/Session/DeleteTrainingSessionRequest.php` (`make:request`, move to `app/Http/Requests/Session/`, fix namespace): `authorize()` → `$this->user()?->can('delete', $this->route('session')) ?? false`; `rules()` → `[]` | Pint + PHPStan clean; `(new DeleteTrainingSessionRequest)->rules() === []`. |
| 5 | Create `app/Http/Controllers/Session/DeleteTrainingSessionController.php` (`make:controller --invokable`, move + fix namespace): `__invoke(DeleteTrainingSessionRequest $request, TrainingSession $session, TrainingSessionDeleteAction $action): Response` → `$action->handle($session); return response()->noContent();`. Edit `routes/api.php`: add the `use` import; inside the `auth:sanctum` group, after `sessions.complete`, add `Route::delete('sessions/{session}', DeleteTrainingSessionController::class)->whereUuid('session')->name('sessions.destroy')`. Write `tests/Feature/Session/DeleteTrainingSessionTest.php` (TC-1…TC-8) | `final` class, `__invoke` only; `php artisan route:list` shows the new route under `auth:sanctum`; `vendor/bin/pest tests/Feature/Session/DeleteTrainingSessionTest.php` all green; every TC has a test; Pint + PHPStan clean. |
| 6 | Add the one `not->toHaveKey('security')` assertion for `/api/v1/sessions/{session}` (`delete`) to `tests/Feature/Auth/DocsSecurityTest.php` | `vendor/bin/pest tests/Feature/Auth/DocsSecurityTest.php` green. |
| 7 | `vendor/bin/pint --dirty`, then `vendor/bin/phpstan analyse` | Pint reports no diffs; PHPStan level 6 clean. |
| 8 | `composer check` (Pint `--test` + PHPStan level 6 + full Pest — the new Session tests, the Policy addition, the Service unit test, the `DocsSecurityTest` addition) | All three steps green; no regression in Auth / Profile / Routine / Cycle / Session / Recommendation suites. |
| 9 | Manual check with `curl` against the running app: register + login → `POST /api/v1/routines` → `POST /api/v1/routines/{uuid}/sessions` (`{}`, free session) → `DELETE /api/v1/sessions/{uuid}` (`204`) → `POST /api/v1/routines/{uuid}/sessions` again (`201`, proving the stuck-session block is gone) → open another session, complete it → `DELETE /api/v1/sessions/{uuid}` on the now-`completed` one (`409 SESSION_ALREADY_COMPLETED`). Review `GET /docs/api` | The `curl` calls return the expected codes; `DELETE /api/v1/sessions/{session}` appears in Scramble, marked secured, with no request body and a `204` response. |

*Process note: branch name, commit messages and PR text follow `CLAUDE.md` /
`AGENTS.md` — English only, and no AI attribution anywhere (no
`Co-Authored-By: Claude` / `Claude-Session:` trailers, no `🤖 Generated with
Claude Code` / "generated by" line in any commit, PR title, PR description or
comment).*
