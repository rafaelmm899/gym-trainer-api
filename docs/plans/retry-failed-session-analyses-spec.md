# Automatically retry failed session AI analyses

> Derived from the Notion ticket "Reintentar automáticamente los análisis de
> sesión fallidos" (Feature: Recomendaciones IA · MVP · Must · Repo: API ·
> Order 132, `https://app.notion.com/p/3dd5cf08db2d8130aa87cf2d45b911d1`),
> whose "Qué falta" field already records the product owner's decision: a
> scheduled Artisan command, not an on-demand endpoint. Base contract:
> `docs/product-context.md` §7 ("Si el análisis de una sesión falla, la sesión
> igual queda completada; la recomendación simplemente no aparece hasta el
> reintento" — this ticket is that reintento), `docs/plans/session-analysis-spec.md`
> (the ticket this one continues — `SessionAnalysisJob`, `AnalysisState`,
> `SessionAnalysisException`), and `CLAUDE.md` "The pipeline" / "Layout".

## 1. Context

**Kind:** Brownfield Feature — `SessionAnalysisJob` already retries a
transient AI-provider failure on its own (`$tries = 3`,
`backoff() = [30, 120]`); once those three attempts are exhausted,
`failed()` sets `TrainingSession.analysis_state = failed` and nothing in the
codebase ever looks at that session again. This ticket adds the missing
system-level recovery: a command, run a few times a day by the scheduler,
that re-queues `SessionAnalysisJob` for every session stuck in `failed`.

**Stack:** PHP 8.5 · Laravel 13 · PostgreSQL 17 (runtime) / SQLite `:memory:`
(tests) · Pest 4 (`pest-plugin-laravel`, `RefreshDatabase` wired) · Pint ·
Larastan level 6. No AI call, no HTTP, no new dependency — this ticket only
adds a Console Command, an Action, and a schedule registration. Everything
runs in Docker; this branch works directly in the main checkout (not a
parallel worktree), and makes no schema change, so `CLAUDE.md`'s workflow
database-isolation step does not apply.

**Problem statement:** A `failed` `analysis_state` is currently a dead end —
no automatic or manual mechanism ever retries it, contradicting
`docs/product-context.md` §7. The `scheduler` service in `docker-compose.yml`
(`php artisan schedule:work`) already runs, but `routes/console.php` has
nothing registered, so it is permanently idle. This ticket gives it a job:
find every `TrainingSession` with `analysis_state = failed` and re-dispatch
`SessionAnalysisJob` for it, 2-3 times a day, system-wide (not scoped to a
user or routine — this is an operational/system command).

**Product-owner decision (already recorded on the ticket, not reopened
here):** no on-demand retry endpoint. Recovery is queue-native and scheduled
only. No cap on the number of retries, no user-facing notification when a
retry finally succeeds or fails again — see "Out of scope".

**In scope:**

- **`App\Actions\Session\SessionAnalysisRetryAction::handle(): Collection<int, TrainingSession>`**
  — finds every `TrainingSession` with `analysis_state = failed`, calls
  `SessionAnalysisJob::dispatch($session)` for each, and returns the
  collection it acted on.
- **`App\Console\Commands\Session\RetryFailedSessionAnalysesCommand`**
  (signature `sessions:retry-failed-analysis`) — calls the Action, then logs
  a one-line operational summary (count found / re-queued).
- **`routes/console.php`** — `Schedule::command(RetryFailedSessionAnalysesCommand::class)`
  registered at `0 8,14,20 * * *` (app timezone), `->withoutOverlapping()`.
- **`tests/Feature/Session/RetryFailedSessionAnalysesCommandTest.php`** —
  covers the dispatch behavior (per AC) and the schedule registration itself
  (cron expression, `withoutOverlapping`).

**Out of scope:**

- **A manual/on-demand retry endpoint** (`POST .../retry-analysis` or
  similar). Explicitly rejected by the product owner on the ticket — recovery
  is queue-native and scheduled only.
- **A retry-count cap or dead-letter handling.** A session whose failure is
  not transient (malformed data, a broken prompt) is re-queued on every
  scheduled run, indefinitely. Accepted as a known limitation on the ticket;
  a future story can add a counter if it becomes a real problem.
- **User-facing notification** when a retried analysis finally succeeds or
  fails again.
- **Any change to `SessionAnalysisJob`, `SessionAnalyzeAction`, or
  `SessionAnalystService`.** This ticket only re-queues the existing job; it
  does not touch how the analysis itself runs, retries within a single
  dispatch, or fails.
- **Any change to `docker-compose.yml`.** The `scheduler` service already
  exists and already runs `php artisan schedule:work`; it just had nothing
  registered to run.
- The `gym-trainer-spa/` frontend (separate repository) — nothing here is
  client-facing.

---

## 2. API Surface

### 2.1 REST

Not applicable — no REST endpoint. Explicitly rejected by the product owner
(see §1).

### 2.2 CLI

| Command | Arguments | Flags | Description |
|---|---|---|---|
| `sessions:retry-failed-analysis` | — | — | Re-dispatches `SessionAnalysisJob` for every `TrainingSession` with `analysis_state = failed`. Runs on the schedule (§6); can also be run manually with `php artisan sessions:retry-failed-analysis` for an out-of-band retry. |

### 2.3 Events

No domain events. One existing queued job dispatch gains a second producer:

| Event name | Producer | Consumer | Payload | Trigger condition |
|---|---|---|---|---|
| `SessionAnalysisJob` dispatch (re-queue) | `App\Actions\Session\SessionAnalysisRetryAction` | `App\Jobs\Session\SessionAnalysisJob` (unchanged) | The `TrainingSession` currently in `analysis_state = failed` | Every run of `sessions:retry-failed-analysis`, once per matching session |

---

## 3. UI

### 3.1 Pages

Not applicable — no pages affected. This is a JSON REST API; nothing in this
ticket is client-facing.

### 3.2 Components

Not applicable — no components affected.

---

## 4. Database

Not applicable — no schema or seed changes. `analysis_state` and its `failed`
value already exist (`docs/plans/session-analysis-spec.md`); this ticket only
reads and reacts to them.

### 4.1 Schema changes

Not applicable — no data or schema changes.

### 4.2 Seeds

Not applicable — no seeds.

---

## 5. Auth & Authorization

### 5.1 Authentication

Not applicable — no HTTP surface. The command runs as a system process under
the `scheduler` container, with no authenticated actor.

### 5.2 Authorization

Not applicable — no authorization changes. The command is system-wide by
design (the ticket's own AC: "no filtra por usuario/rutina"), so there is no
Policy to write — there is no per-request actor to authorize against.

---

## 6. Configuration

Not applicable — no environment variables added or changed. `config('app.timezone')`
already exists (`config/app.php`, currently `UTC`); the schedule entry reads
it, it does not add it.

**Config / non-source files modified:**

| File | Change |
|---|---|
| `routes/console.php` | Add `Schedule::command(RetryFailedSessionAnalysesCommand::class)->cron('0 8,14,20 * * *')->timezone(config('app.timezone'))->withoutOverlapping();`, alongside the existing `inspire` command (untouched). |

No change to `docker-compose.yml` (the `scheduler` service already exists and
already runs `php artisan schedule:work` — see §1), `bootstrap/app.php`
(`app/Console/Commands` is auto-discovered by the framework once the
directory exists — no explicit registration needed), `phpunit.xml`,
`composer.json`, or `tests/Feature/ArchTest.php` (no new arch rule needed;
nothing in that file constrains `App\Console\Commands`).

---

## 7. Current vs New Behavior

| Behavior | Current | New |
|---|---|---|
| A session stuck at `analysis_state = failed` | Permanent — nothing ever looks at it again after `SessionAnalysisJob::failed()` runs. | Picked up by `sessions:retry-failed-analysis` on its next scheduled run (worst case ~6h later, given three runs/day) and re-dispatched, with a fresh `$tries`/`backoff()`. |
| `routes/console.php` | Only the default `inspire` command. | Also registers `sessions:retry-failed-analysis` on the schedule. |
| `scheduler` Docker service | Runs `php artisan schedule:work` with nothing to do. | Runs the same command, now dispatching `sessions:retry-failed-analysis` three times a day. |
| Sessions in `pending`, `processing`, or `done` | Untouched by anything retry-related. | Still untouched — the command's query is scoped to `analysis_state = failed` only (AC). |

---

## 8. Test Cases

Executable with Pest 4 on SQLite `:memory:` (`RefreshDatabase`, already
wired). All in `tests/Feature/Session/RetryFailedSessionAnalysesCommandTest.php`
— per this repo's convention, a Console Command is its own entry point (like
a Controller, but invoked via CLI instead of HTTP), so it gets a Feature test
running it end to end with `$this->artisan(...)`, exactly as an HTTP entry
point gets a Feature test running it end to end with a request.

**TC-1:** Dispatches `SessionAnalysisJob` for every session in `analysis_state = failed`
- **Given:** `Bus::fake([SessionAnalysisJob::class])`; three `TrainingSession` rows with `analysis_state = AnalysisState::Failed` (different users/routines — the command is system-wide, not scoped, per AC)
- **When:** `$this->artisan('sessions:retry-failed-analysis')->assertSuccessful()`
- **Expect:** `Bus::assertDispatchedTimes(SessionAnalysisJob::class, 3)`; `Bus::assertDispatched(SessionAnalysisJob::class, fn (SessionAnalysisJob $job) => $job->session->is($session))` for each of the three sessions

**TC-2:** Does not dispatch for sessions in `pending`, `processing`, or `done` (AC)
- **Given:** `Bus::fake([SessionAnalysisJob::class])`; one `TrainingSession` in each of `AnalysisState::Pending`, `AnalysisState::Processing`, `AnalysisState::Done`; none in `Failed`
- **When:** `$this->artisan('sessions:retry-failed-analysis')->assertSuccessful()`
- **Expect:** `Bus::assertNotDispatched(SessionAnalysisJob::class)`

**TC-3:** A mixed set only re-dispatches the `failed` ones (AC)
- **Given:** `Bus::fake([SessionAnalysisJob::class])`; two `failed` sessions and one each of `pending` / `processing` / `done`
- **When:** `$this->artisan('sessions:retry-failed-analysis')->assertSuccessful()`
- **Expect:** `Bus::assertDispatchedTimes(SessionAnalysisJob::class, 2)`; the two dispatched jobs' `session` match the two `failed` sessions and no other

**TC-4:** No failed sessions — the command still runs cleanly
- **Given:** `Bus::fake([SessionAnalysisJob::class])`; no `TrainingSession` rows at all
- **When:** `$this->artisan('sessions:retry-failed-analysis')->assertSuccessful()`
- **Expect:** `Bus::assertNotDispatched(SessionAnalysisJob::class)`; no exception

**TC-5:** `SessionAnalysisRetryAction::handle()` returns the exact sessions it acted on
- **Given:** `Bus::fake([SessionAnalysisJob::class])`; two `failed` sessions, one `done` session
- **When:** `app(SessionAnalysisRetryAction::class)->handle()`
- **Expect:** returns a `Collection` of exactly the two `failed` sessions (by `id`), in any order

**TC-6:** The command is registered on the schedule with the agreed cadence and overlap protection (AC)
- **Given:** the application is booted (`routes/console.php` loaded)
- **When:** resolving `Illuminate\Console\Scheduling\Schedule` from the container and finding the event whose `command` string contains `sessions:retry-failed-analysis`
- **Expect:** the event's `expression === '0 8,14,20 * * *'` and `withoutOverlapping === true`

---

## 9. Technical Decisions

| Decision area | What was decided | Why |
|---|---|---|
| Business logic lives in an Action, not the Command | `App\Actions\Session\SessionAnalysisRetryAction::handle()` does the query + dispatch loop; `RetryFailedSessionAnalysesCommand::handle()` only calls it and logs the result. | `CLAUDE.md` "Jobs & AI": "A Job's `handle()` is an outside caller like a controller — it calls an Action or a Service under the same rules." A Console Command that triggers a queue dispatch is the same shape of outside caller; keeping the query + dispatch out of the command keeps it consistent with every other entry point in this codebase and testable in isolation (TC-5). |
| Where the Action lives | `App\Actions\Session`, named `SessionAnalysisRetryAction` (`[Domain][Object][Verb]Action`: Session + Analysis + Retry). | Matches the sibling `SessionAnalyzeAction` it re-queues work for, and the domain folder layout in `CLAUDE.md` (`CycleDay`/`DayExercise` under `Cycle`, `SetLog` under `Session` — this stays with `Session` for the same reason: it operates on `TrainingSession.analysis_state`). |
| Where the Command lives | `App\Console\Commands\Session\RetryFailedSessionAnalysesCommand`, `final class`. | Mirrors the `Http/Controllers/{Domain}`, `Actions/{Domain}` domain-folder convention for the first Console Command in this codebase; `final class` matches every other single-purpose class here (Actions, Services, Controllers are all `final`). |
| Action return value | `Collection<int, TrainingSession>` — the sessions it dispatched for, not a bare count. | Lets the Command log a count without a second query, and lets TC-5 assert on *which* sessions were acted on, not just how many — a stronger, still-simple test. |
| Command signature | `sessions:retry-failed-analysis` | Confirmed with the user; it is also the exact name already written into the Notion ticket's "Qué falta" field. |
| Schedule cadence | `->cron('0 8,14,20 * * *')` — 08:00, 14:00, 20:00 — `->timezone(config('app.timezone'))`, `->withoutOverlapping()` (default 1440-minute lock, unchanged). | Confirmed with the user; matches the example cron already written into the ticket. Three evenly-spread runs a day means a `failed` session waits at most ~6h for its next automatic retry. `withoutOverlapping()`'s default lock (24h) is far longer than any run of this command could plausibly take, so no override is needed. |
| Log shape | One `Log::info()` call per run, with `found` and `requeued` as separate structured-context keys even though they are always equal in this design (every found session is unconditionally re-dispatched — dispatch under the `database` queue driver only inserts a `jobs` row, it does not itself fail in a way this command would catch). | The ticket's AC literally asks for "cuántas encontró / reencoló"; logging both keys satisfies that wording exactly and gives a future reader an anchor if the two ever diverge (e.g. a future partial-failure path). Matches the existing `Log::info(...)` precedent in `ExerciseCatalogService` — no new logging channel or helper. |
| `withoutOverlapping()` over `onOneServer()` | Only `withoutOverlapping()` is used. `onOneServer()` (which needs a cache-based mutex shared across machines) is not added. | This app runs a single `scheduler` container (`docker-compose.yml`); there is no multi-server deployment in scope to protect against. `withoutOverlapping()` alone is enough to satisfy the AC ("que dos corridas no procesen la misma sesión a la vez"). |
| Test file location | `tests/Feature/Session/RetryFailedSessionAnalysesCommandTest.php`, not `tests/Unit/`. | Confirmed with the user. A Console Command is its own entry point, run end-to-end via `$this->artisan(...)` against a real (SQLite) database — the CLI analogue of a Controller/HTTP Feature test, consistent with `CLAUDE.md`'s "one file per endpoint" framing for `tests/Feature`. |
| No new `ArchTest.php` rule | Not added. | The existing `arch('actions are final and expose handle()')` rule already covers `SessionAnalysisRetryAction` (it lives under `App\Actions`). Nothing in `CLAUDE.md` mandates a Console-Command-specific arch rule, and adding one for a single command would be speculative generality (`CLAUDE.md` rule 5). |
| Git artifacts | English only. No AI attribution anywhere. | `CLAUDE.md` / `AGENTS.md` "Git" rule. |

---

## 10. Work Plan

This branch works directly in the main checkout (`git worktree list` shows a
single entry) — no throwaway-container tooling or database cloning is needed;
`docker compose exec app ...` works as documented in `CLAUDE.md` "Commands".

| # | Task | Definition of Done |
|---|---|---|
| 1 | Create `app/Actions/Session/SessionAnalysisRetryAction.php`: `handle(): Collection` queries `TrainingSession::query()->where('analysis_state', AnalysisState::Failed)->get()`, dispatches `SessionAnalysisJob::dispatch($session)` for each, returns the collection | Pint + PHPStan clean. |
| 2 | Create `app/Console/Commands/Session/RetryFailedSessionAnalysesCommand.php` (`final class extends Command`, `protected $signature = 'sessions:retry-failed-analysis'`, `protected $description`, `handle(SessionAnalysisRetryAction $action): int` calls the Action, logs the summary per §9, returns `self::SUCCESS`) | `php artisan list` shows the command; Pint + PHPStan clean. |
| 3 | Register the schedule in `routes/console.php` per §6, importing `RetryFailedSessionAnalysesCommand` and `Illuminate\Support\Facades\Schedule` | `php artisan schedule:list` shows `sessions:retry-failed-analysis` at `0 8,14,20 * * *`. |
| 4 | Write `tests/Feature/Session/RetryFailedSessionAnalysesCommandTest.php` (TC-1…TC-6 per §8) | `vendor/bin/pest tests/Feature/Session/RetryFailedSessionAnalysesCommandTest.php` green. |
| 5 | `vendor/bin/pint --dirty`, then `vendor/bin/phpstan analyse` | Pint reports no diffs; PHPStan level 6 clean. |
| 6 | `composer check` (Pint `--test` + PHPStan level 6 + full Pest suite) | All green; no regression in any other domain's suite. |
| 7 | Manual check: `docker compose exec app php artisan tinker` — create a `TrainingSession` factory row with `analysis_state = failed`, run `php artisan sessions:retry-failed-analysis`, confirm the job was queued (`QUEUE_CONNECTION=sync` locally runs it inline) and the log line appears in `storage/logs/laravel.log` | The session's `analysis_state` moves off `failed` (to `processing`/`done`, or back to `failed` if the AI call itself is unreachable locally — either way, proof the job ran); the log line shows the expected count. |

*Process note: branch name, commit messages and PR text follow `CLAUDE.md` /
`AGENTS.md` — English only, and no AI attribution anywhere.*
