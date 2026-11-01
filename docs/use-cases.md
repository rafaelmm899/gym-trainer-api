# Use cases

One entry per endpoint. All routes live in `routes/api.php` under `/api/v1`, all
bodies are JSON wrapped in a top-level `data` key, and every error is
`{ "data": { "code": "...", "message": "..." } }` (see `CLAUDE.md` → Errors).
Every route except `register`/`login` requires `auth:sanctum`.

Reading guide: each use case lists **who/what it needs** (preconditions),
**what happens** (the Action's story), **what it returns**, and the **business
errors** it can raise. For the step-by-step sequence of the multi-step ones,
follow the link to `docs/flows.md`.

Business-rule violations are `DomainException`s thrown from Services
(409 unless noted). Authorization failures are 403, unknown uuids 404, shape
errors 422 `VALIDATION_EXCEPTION`.

## Index

| # | Use case | Endpoint | Action |
|---|---|---|---|
| UC-01 | Register | `POST /register` | `UserRegisterAction` |
| UC-02 | Log in | `POST /login` | `UserLoginAction` |
| UC-03 | Log out | `POST /logout` | `UserLogoutAction` |
| UC-04 | Who am I | `GET /user` | — (Resource only) |
| UC-05 | Show athlete profile | `GET /profile` | — |
| UC-06 | Save athlete profile | `PUT /profile` | `AthleteProfileUpdateAction` |
| UC-07 | Create routine (+ first cycle) | `POST /routines` | `RoutineCreateAction` |
| UC-08 | List my routines | `GET /routines` | — |
| UC-09 | Show a routine | `GET /routines/{routine}` | — |
| UC-10 | Generate next cycle | `POST /routines/{routine}/cycles` | `CycleGenerateAction` |
| UC-11 | Open a training session | `POST /routines/{routine}/sessions` | `TrainingSessionCreateAction` |
| UC-12 | List a routine's sessions | `GET /routines/{routine}/sessions` | `TrainingSessionListAction` |
| UC-13 | Show a session | `GET /sessions/{session}` | `TrainingSessionShowAction` |
| UC-14 | Log a set | `POST /sessions/{session}/sets` | `SetLogCreateAction` |
| UC-15 | Correct a set | `PUT /sessions/{session}/sets/{set}` | `SetLogUpdateAction` |
| UC-16 | Complete a session | `POST /sessions/{session}/complete` | `SessionCloseAction` |
| UC-17 | Delete an open session | `DELETE /sessions/{session}` | `TrainingSessionDeleteAction` |
| UC-18 | List routine recommendations | `GET /routines/{routine}/recommendations` | `RoutineRecommendationsListAction` |
| UC-19 | Export a day to xlsx | `GET /routines/{routine}/cycle-days/{day}/export` | — (Service) |
| UC-20 | Import a filled day | `POST /routines/{routine}/cycle-days/{day}/import` | `TrainingSessionImportAction` |
| UC-21 | Search the exercise catalogue | `GET /exercises` | — |
| UC-22 | Exercise history | `GET /exercises/{exercise}/history` | `ExerciseHistoryListAction` |
| UC-23 | Analyse a completed session (system) | queued job | `SessionAnalyzeAction` |
| UC-24 | Progress one day of the next cycle (system) | queued job | `CycleDayProgressAction` |
| UC-25 | Finalize the next cycle (system) | queued job | `CycleFinalizeAction` |
| UC-26 | Retry failed analyses (system) | scheduled command | `SessionAnalysisRetryAction` |

---

## Account

### UC-01 Register — `POST /register`
- **Needs:** nothing (public). Throttled `6/min`.
- **Does:** creates the user, logs them in (session cookie) and regenerates the session.
- **Returns:** `201` the user.
- **Errors:** `VALIDATION_EXCEPTION` (e.g. email taken).

### UC-02 Log in — `POST /login`
- **Needs:** public, throttled `6/min`. SPA must have fetched `/sanctum/csrf-cookie` first.
- **Does:** `Auth::attempt`, regenerates the session.
- **Errors:** `AUTHENTICATION_EXCEPTION` (401) on bad credentials.

### UC-03 Log out — `POST /logout`
Logs out the `web` guard, invalidates the session, rotates the CSRF token. `204`.

### UC-04 Who am I — `GET /user`
Returns the authenticated user. This is how the SPA checks "am I still logged in".

### UC-05 / UC-06 Athlete profile — `GET|PUT /profile`
- **What it is:** the onboarding data the AI uses to plan: `experience_level`,
  `days_per_week` (1–7), `session_minutes` (10–240), `goal`, free-text `notes`
  (≤ 2000 chars; injuries, equipment, preferences).
- **PUT** is an upsert (`updateOrCreate`) — there is only ever one profile.
  Returns `201` the first time (profile created) and `200` afterwards.
- **`GET`** returns the profile status resource, which also tells the client
  whether onboarding is complete.
- **Why it matters:** UC-07 refuses to run without a profile.

---

## Routines & cycles

### UC-07 Create routine (+ first cycle) — `POST /routines`
- **Needs:** a saved athlete profile.
- **Input:** `name`, `goal`, optional `hint` ("PPL", "full body at home with dumbbells").
- **Does** (details: `flows.md` §Create routine): asks the AI to plan the first
  5-day cycle **synchronously**, then, in one transaction, archives the user's
  current active routine, creates the new routine as `active` and persists the
  cycle (`active`), its days and exercise prescriptions.
- **All-or-nothing:** if the AI fails, *nothing* is written — the previous active
  routine is untouched. The client just re-sends the POST.
- **Returns:** `201` routine with its first cycle fully loaded.
- **Errors:** `PROFILE_INCOMPLETE` (409); `AI_GENERATION_FAILED` (502).
- **Heads-up:** this request can take up to ~60 s (agent timeout) and is the
  most failure-prone endpoint on small provider tiers.

### UC-08 / UC-09 List & show routines — `GET /routines`, `GET /routines/{routine}`
List is scoped to the caller, active first, then newest. Show is gated by
`RoutinePolicy::view` (403 for someone else's routine) and returns the routine
with its latest cycle. Archived routines are readable forever.

### UC-10 Generate next cycle — `POST /routines/{routine}/cycles`
- **Needs:** routine owned by the caller (Policy), routine `active`, no cycle
  already `generating`. Throttled `1/min` to stop double taps.
- **Does:** creates cycle N+1 in status `generating` as a **clone** of the active
  cycle, marks the days that were trained as `pending`, and queues a chain of
  jobs: one `CycleDayProgressionJob` per trained day (60 s apart, to respect
  provider rate limits) then one `CycleFinalizeJob`. Responds immediately.
- **Returns:** `202` the new cycle (`generating`). The client **polls the
  routine** until the cycle becomes `active` (or `failed`).
- **Errors:** `ROUTINE_NOT_ACTIVE`, `CYCLE_GENERATION_IN_PROGRESS` (409).
- **Key design point:** the AI does *not* choose exercises for cycle N+1. The
  exercises are carried over; the AI only re-prescribes sets/reps/load/RPE/rest
  for exercises the athlete actually performed. See `flows.md` §Cycle generation.

---

## Training sessions

### UC-11 Open a session — `POST /routines/{routine}/sessions`
- **Input:** optional `day` (cycle_day uuid). Omit it for a **free session**.
- **Rules (checked in this order, `TrainingSessionOpeningService`):**
  routine must be active (`ROUTINE_NOT_ACTIVE`) → `day` must belong to the
  routine's *active* cycle (`CYCLE_DAY_NOT_IN_ACTIVE_CYCLE`, 422) → the user must
  have no other `in_progress` session (`SESSION_IN_PROGRESS`).
- **Returns:** `201` the session, with, for a planned day, the prescriptions and
  `previous` (what the user did last time on each exercise).

### UC-12 List sessions — `GET /routines/{routine}/sessions`
Paginated, newest first; filters `cycle_day` (uuid) and `status`. Each item
carries a computed `summary` (see `DER.md` → Derived data).

### UC-13 Show a session — `GET /sessions/{session}`
Full detail: day prescription (with `previous`), logged sets (ordered by exercise
then set number), the recommendations this session produced (with trend), and the
summary. Readable in any status, including under an archived routine.

### UC-14 Log a set — `POST /sessions/{session}/sets`
- **Input:** exactly one of `day_exercise_id` (set belongs to a prescription) or
  `exercise_id` (off-plan set) — never both; `set_number`, `weight_kg`
  (0–1000, ≤2 decimals), `reps` (1–100), optional `rpe` (0–10 in 0.5 steps), `note`.
- **Rules:** session must be open (`SESSION_ALREADY_COMPLETED`); a
  `day_exercise_id` must belong to the session's own day
  (`DAY_EXERCISE_NOT_IN_SESSION`); `set_number` must be exactly
  *existing sets of that exercise + 1* (`NON_CONTIGUOUS_SET_NUMBER`).
- **Returns:** `201` the set.

### UC-15 Correct a set — `PUT /sessions/{session}/sets/{set}`
Edits weight/reps/rpe/note of an existing set (not `set_number`, not the
exercise). `{set}` is scope-bound to `{session}`. Only while the session is open.

### UC-16 Complete a session — `POST /sessions/{session}/complete`
- **Input:** optional `note`, optional `perceived_effort` (1–5).
- **Rules:** not already completed (`SESSION_ALREADY_COMPLETED`, 409); at least
  one set logged (`SESSION_HAS_NO_SETS`, 422).
- **Does:** marks it `completed`, stamps `completed_at`, and **queues
  `SessionAnalysisJob`** (UC-23). The response does not wait for the AI.
- **Returns:** the session; `analysis_state` is `pending` here and moves on
  asynchronously. The client reads recommendations later via UC-13/UC-18.

### UC-17 Delete an open session — `DELETE /sessions/{session}`
Exists to fix a mistake or unblock the "one open session at a time" rule. Only
`in_progress` sessions; a completed one is immutable (`SESSION_ALREADY_COMPLETED`).
Cascades to its sets. `204`.

---

## Recommendations & history

### UC-18 List routine recommendations — `GET /routines/{routine}/recommendations`
The *current* targets for the routine's active cycle (one per exercise), each with
a small trend (last points of the exercise's history). Source: UC-23.

### UC-21 Search the catalogue — `GET /exercises`
Global, read-only. Query: `q` (name contains, case-insensitive), `muscle_group`.
Capped at 50 results, ordered by name. Used by the client to pick an exercise for
an off-plan set.

### UC-22 Exercise history — `GET /exercises/{exercise}/history`
Paginated list of the caller's *completed* sessions that include the exercise,
newest first, each with volume, top set and `volume_change_pct` vs. the session
before it. Optional `routine` filter. Always scoped to the caller's own data even
though the exercise is global.

---

## Spreadsheet round-trip

### UC-19 Export a day — `GET /routines/{routine}/cycle-days/{day}/export`
Downloads an `.xlsx` for one day of the **active** cycle: the prescription and
current recommendations per exercise, with blank `weight_kg`/`reps`/`rpe`/`note`
columns to fill in offline (e.g. at a gym without signal). This is the **only**
endpoint whose success body is a file, not a JSON Resource.
Errors: `ROUTINE_HAS_NO_ACTIVE_CYCLE`, `CYCLE_DAY_NOT_IN_ACTIVE_CYCLE` (422).

### UC-20 Import a filled day — `POST /routines/{routine}/cycle-days/{day}/import`
Upload the filled workbook. In **one atomic request** it opens a session for that
day, logs every filled row as a set, and completes it (so UC-23 is queued too).
Rows without both weight and reps are skipped; set numbers are assigned by row
order per exercise; exercises are matched to the day's prescriptions by the
catalogue **slug** of the name in the sheet.
Errors: `ROUTINE_HAS_NO_ACTIVE_CYCLE`, `CYCLE_DAY_NOT_IN_ACTIVE_CYCLE` (422),
`CYCLE_DAY_ALREADY_COMPLETED` (409), and per-row `VALIDATION_EXCEPTION` with keys
like `row_5.weight_kg` (a corrupt file folds into `file`).

---

## System use cases (no endpoint)

### UC-23 Analyse a completed session — `SessionAnalysisJob`
Triggered by UC-16/UC-20/UC-26. Builds one prompt for the whole session (per
exercise: baseline target + sets actually logged), asks `SessionAnalystAgent`, and
upserts one `exercise_recommendations` row per exercise. 3 tries, backoff 30 s/120 s.
`analysis_state`: `pending → processing → done`, or `failed` after the last try.
**A failed analysis never affects the session** — it stays `completed` and the
recommendation simply appears on retry (UC-26).

### UC-24 Progress one day — `CycleDayProgressionJob`
One per trained day of a `generating` cycle. Asks `CycleProgressionAgent` for new
sets/reps/load/RPE/rest for that day's performed exercises. 3 tries; on the **last**
failed attempt it falls back to the session recommendations (day →
`fallback`) instead of failing, so a provider outage can't block the rollover.

### UC-25 Finalize the cycle — `CycleFinalizeJob`
Last link of the chain. Activates the new cycle, sets the outgoing one to
`completed` (all 5 days trained) or `incomplete`, writes the `split_rationale`
summary ("progressed X of Y days with the AI…") and marks the consumed
recommendations `applied`.

### UC-26 Retry failed analyses — `sessions:retry-failed-analysis`
Scheduled at 08:00, 14:00 and 20:00 (`routes/console.php`,
`withoutOverlapping`). Re-queues `SessionAnalysisJob` for every session whose
`analysis_state` is `failed`. Run by the `scheduler` container.

---

## Error code reference

| Code | HTTP | Raised by |
|---|---|---|
| `VALIDATION_EXCEPTION` | 422 | Form Requests; import row errors |
| `AUTHENTICATION_EXCEPTION` | 401 | bad login / no session |
| `AUTHORIZATION_EXCEPTION` | 403 | Policy denied (someone else's resource) |
| `NOT_FOUND_EXCEPTION` | 404 | unknown uuid |
| `RATE_LIMIT_EXCEPTION` | 429 | throttle |
| `PROFILE_INCOMPLETE` | 409 | UC-07 without a profile |
| `ROUTINE_NOT_ACTIVE` | 409 | UC-10, UC-11 on an archived routine |
| `CYCLE_GENERATION_IN_PROGRESS` | 409 | UC-10 while one is `generating` |
| `ROUTINE_HAS_NO_ACTIVE_CYCLE` | 422 | UC-19, UC-20 |
| `CYCLE_DAY_NOT_IN_ACTIVE_CYCLE` | 422 | UC-11, UC-19, UC-20 |
| `CYCLE_DAY_ALREADY_COMPLETED` | 409 | UC-20 |
| `SESSION_IN_PROGRESS` | 409 | UC-11 |
| `SESSION_ALREADY_COMPLETED` | 409 | UC-14, UC-15, UC-16, UC-17 |
| `SESSION_HAS_NO_SETS` | 422 | UC-16 |
| `NON_CONTIGUOUS_SET_NUMBER` | 409 | UC-14 |
| `DAY_EXERCISE_NOT_IN_SESSION` | 409 | UC-14 |
| `AI_GENERATION_FAILED` | 502 | UC-07 when the planner fails |
| `SESSION_ANALYSIS_FAILED` | — | UC-23 internal (never reaches a client) |
| `DOMAIN_EXCEPTION` / `SERVER_EXCEPTION` | 409 / 500 | fallbacks |

> Codes/HTTP statuses above come from the exception classes in
> `app/Exceptions/`. If you add one, add it here.
