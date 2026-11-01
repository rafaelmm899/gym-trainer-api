# Developer documentation

Start here. Read in this order for a first day on the project:

| # | Doc | Read it to learn |
|---|---|---|
| 1 | [`product-context.md`](product-context.md) | What the product is and why (Spanish, written before the English-only rule). |
| 2 | This page | The mental model and the map of the code. |
| 3 | [`DER.md`](DER.md) | The data model: every table, relation, state and invariant. |
| 4 | [`use-cases.md`](use-cases.md) | Every endpoint and background job: preconditions, behaviour, errors. |
| 5 | [`flows.md`](flows.md) | Sequence diagrams of the multi-step flows (AI planning, cycle rollover, sessions, analysis, import). |
| 6 | [`operations.md`](operations.md) | Run it locally, CI, deploy, read logs, troubleshoot. |
| — | `../CLAUDE.md` (= `AGENTS.md`) | **The code contract**: layering, naming, error envelope, testing, git rules. |
| — | [`plans/`](plans/) | One spec per shipped feature (the *why* behind a specific decision). |
| — | [`design/`](design/) | Forward-looking designs not built yet. |

## The product in one minute

An AI personal trainer. The athlete saves a profile; the AI plans a **routine**
whose unit is a weekly **cycle** of 5 **days**, each with prescribed
**exercises** (sets, reps, load, RPE, rest). The athlete logs every **set** in a
**session**. When a session closes, the AI leaves a **recommendation** per
exercise ("next time: +2.5 kg"). When the athlete asks for the next week, the
routine's exercises are carried over and the AI only re-prescribes load/volume
using what really happened.

```
profile ─► routine ─► cycle 1 ─► days ─► prescribed exercises
                        │                     ▲
                        ▼                     │ (soft link)
              next cycle (clone + AI progress)│
                                       session ─► set logs ─► AI analysis ─► recommendations ─┐
                                                                                              │
                                       ◄────────────────── used by the next cycle ────────────┘
```

Vocabulary (precise meanings in `DER.md`): *routine* (a program; one `active`
per user), *cycle* (a week), *cycle day*, *day exercise* (a **prescription**),
*session* (a workout actually done; may be *planned* against a day or *free*),
*set log* (what was done), *recommendation* (AI advice for next time).

## Architecture in one picture

```
HTTP                      app/                                   storage
────                      ────                                   ───────
routes/api.php  /api/v1
  │  auth:sanctum + Policy
  ▼
Http/Requests      validate shape, authorize via Policy
Http/Controllers   invokable, ~3 lines, owns the HTTP status
Actions/           ONE use case: transactions, jobs, orchestration ─────► Jobs/ (queue)
Services/          business rules, calculations, AI wrappers  ─┬─► Ai/Agents (laravel/ai)
                                                              └─► Models ─► PostgreSQL
Http/Resources     the only place the JSON payload is shaped
Exceptions/        DomainException subclasses → ApiExceptionRenderer → {data:{code,message}}
```

Rules you will trip over (full text in `CLAUDE.md`):

- Every success body is a **JSON Resource** (never `response()->json`); every
  error is `{ "data": { "code", "message" } }`.
- A **business rule violation = a `DomainException`** thrown from a **Service**.
  Authorization = a **Policy**. Input shape = a **Form Request**. Don't mix them.
- Only **Actions** open transactions / dispatch jobs. The AI is never called
  inside a transaction.
- Public ids are **uuids**; internal bigint ids never leave the server.
- **Enums** for every status/goal/action; no bare strings.
- Models are in strict mode outside production (lazy loading and missing
  attributes throw) — eager-load.

## Where do I find…?

| I want to… | Look at |
|---|---|
| Add an endpoint | A sibling in the same domain: `routes/api.php` → Request → Controller → Action → Resource. Add `tests/Feature/{Domain}/` |
| Change what the AI is told | `app/Services/Cycle/CyclePlannerService` (prompts + validation), `app/Ai/Agents/**` (instructions + JSON schema) |
| Change an AI output shape | The agent's `schema()` **and** the Service that maps/validates it |
| Add a business rule | A Service guard clause: `throw_if(..., new XException)`; add the exception under `app/Exceptions/{Domain}/` with its `errorCode` |
| Change a response payload | The Resource in `app/Http/Resources/{Domain}/` |
| Add a column | Migration → `make artisan c="ide-helper:models --write"` → Pint models → update `docs/DER.md` |
| See which job runs when | `flows.md` §3 and §5; jobs in `app/Jobs/` |
| Change the schedule | `routes/console.php` (and, in production, the Cloud Run job — see `operations.md`) |
| Understand a past decision | `docs/plans/<feature>-spec.md` |

## Testing in one paragraph

Pest, mostly feature tests, one file per endpoint under `tests/Feature/{Domain}/`
mirroring `app/`; Services and pure units in `tests/Unit/{Domain}/`. SQLite
`:memory:` and `QUEUE_CONNECTION=sync`, so a dispatched job runs inline.
**Agents are faked — the suite never reaches a provider.** Use factories and their
states; run the narrowest scope while developing (`vendor/bin/pest path/to/File.php`
or `--filter`), then `make check` before pushing.

## Keeping these docs true

These pages describe behaviour verified against the code on the date of
writing. When you change one of the following, update the matching doc **in the
same PR**:

| Change | Update |
|---|---|
| Migration / model | `DER.md` |
| Endpoint, validation rule, domain exception | `use-cases.md` (and its error table) |
| Job, agent, Action orchestration, state transition | `flows.md` |
| Docker, deploy script, scheduler, env var, log channel | `operations.md` |

English only for anything new under `docs/` (see `CLAUDE.md` → Git).
