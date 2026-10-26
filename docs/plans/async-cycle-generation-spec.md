# Async cycle N+1 generation — spec

Supersedes the synchronous flow of `generate-next-cycle-spec.md` and
`keep-cycle-exercises-spec.md` for `POST /api/v1/routines/{routine}/cycles`. The
cycle contents are unchanged (same days and exercises, progressed); only *how*
and *when* they are produced changes.

## Why

The AI provider (Groq free tier, ~8k tokens per minute) rejected the single
whole-week call (400 invalid JSON) and, once split per day, rate-limited the
back-to-back calls (429). A pause of a minute between days does not fit in an
HTTP request (130 s nginx / php-fpm limit), so each day is a queued job.

## Flow

1. `POST /routines/{routine}/cycles` (`GenerateCycleController` → `CycleGenerateAction`).
   - 409 `ROUTINE_NOT_ACTIVE` if the routine is archived.
   - 409 `CYCLE_GENERATION_IN_PROGRESS` if its next cycle is already `generating`.
   - A previous attempt left `failed` is deleted and redone.
2. In one transaction it creates cycle N+1 as `generating`, a copy of the active
   cycle. Each day gets `progression_state`: `pending` if something was trained
   that day, `unchanged` otherwise.
3. After the transaction it dispatches a chain: one `CycleDayProgressionJob` per
   `pending` day (the 2nd and later with `delay(60)`), then `CycleFinalizeJob`.
   Response: **202**, `CycleResource` with `status: "generating"`.
4. `CycleDayProgressionJob` → `CycleDayProgressAction`: one AI call for that
   day's trained slots, written over the day's exercises, state `ai`.
   - `tries = 3`, `backoff = [60, 60]`, `timeout = 60`.
   - On the last attempt a failing AI does not fail the job: the day takes the
     routine's active session recommendations (or last week's prescription),
     state `fallback`, and the chain goes on. Generation always finishes.
   - Any other exception fails the job; `failed()` marks the cycle `failed`
     and the chain stops.
5. `CycleFinalizeJob` → `CycleFinalizeAction`, one transaction: cycle N+1 →
   `active` (with a `split_rationale` built in PHP), the outgoing cycle →
   `completed` / `incomplete`, trained exercises' recommendations → `applied`.
6. Retrying a failed or stuck attempt is repeating the `POST`.

The outgoing cycle stays `active`, and trainable, while N+1 is generating
(`TrainingSessionOpeningService` reads `Routine::activeCycle()`).

## Contract

- `data.status`: `generating` | `active` | `failed` (the latest cycle, also on `GET /routines/{routine}`).
- `data.days[].progression_state`: `pending` | `ai` | `fallback` | `unchanged`.
- Errors: `CYCLE_GENERATION_IN_PROGRESS` (409), `ROUTINE_NOT_ACTIVE` (409), throttle `1/min` (429).

## Operations

Production runs the queue as the short Cloud Run job `process-queue`
(`queue:work --stop-when-empty`, about every 2 minutes). A delayed job is
invisible until it is due, so the real gap between days is one pass of that job
(about 2 minutes) and a cycle takes roughly 10 minutes.
