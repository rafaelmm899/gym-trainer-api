# Gym Trainer API — Agent Guidelines

`CLAUDE.md` and `AGENTS.md` are the same file — edit both together.
Product context: `docs/product-context.md`. This file is the code contract.

## Stack

PHP 8.5 · Laravel 13 · PostgreSQL 17 · Redis (cache + session) · queue driver
`database` · `laravel/ai` (structured-output agents) · `spatie/laravel-data`
(DTOs) · Pest 4 · Pint · Larastan level 6. Everything runs in Docker — see
[Commands](#commands).

## Golden rules

1. **REST API, JSON only.** No Blade, no `web.php` beyond a health check. Routes
   live in `routes/api.php` under `/api/v1`.
2. **Every endpoint is one pipeline, no shortcuts:**
   `Form Request → invokable Controller → Action → Service(s) → JSON Resource`.
3. **Always a JSON Resource — `response()->json(...)` is banned.** Every success
   body is an Eloquent API Resource / `ResourceCollection`. No exceptions: not a
   model, not an array, not a DTO, not a hand-built JSON response.
4. **Every data route:** `auth:sanctum` + a Policy. No user sees another's data.
5. **Simplicity first.** Reduce cognitive load; the best change often deletes
   code. Prefer the boring, obvious solution — no speculative generality, no
   "we might need it later".
6. **Every new class must make the system simpler** — clearer flow, less
   duplication. A class that only adds indirection is rejected. This does not
   loosen rule 2; it targets *extra* layers on top: single-implementation
   interfaces, base classes, wrappers, a readable Service split into five.
7. **Comments only for real cognitive load** — a non-obvious invariant, a
   workaround and its reason, a genuinely tricky algorithm. Otherwise none; the
   names are the documentation. When warranted: PHPDoc, not inline.

## The pipeline

### Form Request — `app/Http/Requests/{Domain}/{UseCase}Request.php`

- All input validation for the endpoint: `rules()`, `authorize()` (delegates to
  the Policy), `prepareForValidation()`.
- Shape and format only — presence, type, size, enum membership, `exists`,
  `unique`, regex.
- **No business rules.** "Can this user have a second active routine?", "is the
  cycle still a draft?" belong in a Service, not a validation rule.
- Hands the controller `validated()` or a `Data` object.

### Controller — `app/Http/Controllers/{Domain}/{UseCase}Controller.php`

- One class per endpoint, `__invoke()` only. Verb-first name: `StoreRoutineController`,
  `GenerateCycleController`, `LogSetController`, `CloseSessionController`.
- ~3 lines: take the request, call one Action, wrap the result in a Resource.
  Owns the HTTP status code and headers — nothing else.

```php
final class StoreRoutineController
{
    public function __invoke(StoreRoutineRequest $request, RoutineCreateAction $action): JsonResponse
    {
        $routine = $action->handle($request->user(), RoutineData::from($request->validated()));

        return RoutineResource::make($routine)->response()->setStatusCode(Response::HTTP_CREATED);
    }
}
```

For a plain 200 read, `return RoutineResource::make($routine);` is enough.

### Action — `app/Actions/{Domain}/{Domain}{Object}{Verb}Action.php`

- One use case. `final class`, entry method `handle()`, deps via constructor
  property promotion.
- The **only** layer that opens transactions, dispatches jobs / events /
  notifications, or orchestrates several Services or sub-Actions.
- Reads top-to-bottom as the story of the use case.
- Returns a domain object (model, Eloquent collection, `Data`) — never a
  Resource, a `Response`, or a JSON-shaped array.
- Name `[Domain][Object][Verb]Action`: `RoutineCreateAction`, `CycleGenerateAction`,
  `SessionCloseAction`.

```php
final class RoutineCreateAction
{
    public function __construct(private RoutineActivationService $activation) {}

    public function handle(User $user, RoutineData $data): Routine
    {
        return DB::transaction(function () use ($user, $data) {
            $this->activation->archiveCurrentActive($user);

            $routine = $user->routines()->create([
                'name' => $data->name,
                'goal' => $data->goal,
                'status' => RoutineStatus::Active,
            ]);

            GenerateCycleJob::dispatch($routine);

            return $routine;
        });
    }
}
```

### Service — `app/Services/{Domain}/{Domain}{Purpose}Service.php`

- Business knowledge: rules, calculations, invariants, transformations, one
  external API each. `final class`, constructor injection, may do I/O.
- Single responsibility. Called *into* for one piece of work — it **never** calls
  an Action, dispatches a job, or fires an event. If you need that, you are in
  the wrong layer.
- Business guard clauses live here:
  `throw_if($routine->isArchived(), new RoutineArchivedException());`
- Name `[Domain][Purpose]Service`, or a `...Validator` / `...Api` suffix:
  `RoutineActivationService`, `ProgressionSummaryService`, `ExerciseCatalogService`.

### JSON Resource — `app/Http/Resources/{Domain}/{Entity}Resource.php`

- The one place that shapes the client payload. One per entity; compose with
  `whenLoaded()`, nested resources, `ResourceCollection` for lists.
- Never `response()->json(...)` in a controller. A no-content action returns
  `response()->noContent()` (204). Errors are thrown as exceptions and rendered
  by the exception handler — never hand-built JSON.

## Layout — folders by domain

Domains: `Auth`, `Profile`, `Routine`, `Cycle`, `Session`, `Recommendation`,
`Exercise`. `CycleDay` + `DayExercise` sit under `Cycle`; `SetLog` under `Session`.

```
app/
  Actions/{Domain}/…Action.php
  Services/{Domain}/…Service.php
  Http/Controllers/{Domain}/…Controller.php
  Http/Requests/{Domain}/…Request.php
  Http/Resources/{Domain}/…Resource.php
  Data/{Domain}/…Data.php            spatie/laravel-data DTOs, typed input across layers
  Jobs/{Domain}/…Job.php
  Enums/{Domain}/…                    or Enums/Shared for cross-domain (Goal)
  Policies/…Policy.php
  Ai/Agents/{Domain}/…Agent.php       laravel/ai, each wrapped by a Service
  Models/…                            flat, singular: Routine, Cycle, SetLog
```

Tests mirror the tree: `tests/Feature/{Domain}/` (one file per endpoint),
`tests/Unit/{Domain}/` (Services and pure units).

## Conventions

- **Laravel-way helpers** before verbose forms: `abort_if`/`abort_unless`,
  `throw_if`/`throw_unless`, `collect()`, `str()`, `now()`/`today()`,
  `data_get()`, `blank()`/`filled()`, `tap()`, `to_route()`.
- Route-model binding + Policies over manual `findOrFail` + `if`.
- `DB::transaction(fn () => …)` closure form — in Actions only.
- Eloquent relations with `with()` / `load()`; no hand-written joins for simple
  reads.
- `config()`, never `env()`, outside `config/`.
- Writes take `validated()` / a `Data` object — never `$request->all()`.
- Backed **enums** for every status / goal / action field. No bare strings.
- Mass-assignment protection stays on — declare it (`#[Fillable(...)]` attribute
  or `$fillable`); never `Model::unguard()`.
- **Every model carries a complete PHPDoc block** above the class: `@property` /
  `@property-read` for every column, cast, accessor and relation (plus `*_count`
  and pivot accessors), and `@method` for scopes and custom static builders — it
  is the map for navigating the model. Refresh it with
  `php artisan ide-helper:models --write` (`barryvdh/laravel-ide-helper`), then
  check by hand that custom accessors, appended attributes and scopes the tool
  can't see are in the block too. Keep it in sync with the migration.
- **PHP style:** curly braces always; constructor property promotion; explicit
  return types and parameter type hints everywhere; `TitleCase` enum cases;
  array-shape types in PHPDoc.
- Confirm a package's installed version before using its API — `application-info`
  or `search-docs` (Boost MCP), or `composer show <pkg>`. Do not add dependencies
  without approval.
- Match the surrounding code — check sibling files for structure and naming.

### Global config — `AppServiceProvider::boot()`

- `Model::shouldBeStrict(! isProduction())` — N+1 (lazy load), silently
  discarded attributes, and missing-attribute access all throw outside prod.
- `Date::use(CarbonImmutable::class)` — dates never mutate in place.
- `DB::prohibitDestructiveCommands(isProduction())`.
- `URL::forceHttps(isProduction())`.

## Jobs & AI

- Cycle generation and session analysis run in queued Jobs (`app/Jobs/{Domain}`),
  dispatched from the Action. A Job's `handle()` is an outside caller like a
  controller — it calls an Action or a Service under the same rules.
- AI agents are wrapped by a Service. The progression summary they consume is
  built in plain PHP (`ProgressionSummaryService`), never by an agent.
- Tests use fake agent responses — the suite never hits a real provider.
- If session analysis fails, the session still completes; the recommendation
  appears on retry.

## API documentation

`dedoc/scramble` builds the OpenAPI 3.1 spec by static analysis — **no
annotations**. It reads Form Requests, JSON Resources, return types, backed
enums and route-model binding, so keeping the pipeline typed *is* the docs.

- UI `GET /docs/api` · spec `GET /docs/api.json`. Open in `local`; gated by the
  `viewApiDocs` Gate elsewhere.
- Everything under `/api` is picked up automatically — no per-route wiring.
- Only reach for a `#[...]` attribute or `Scramble::extendOpenApi()` when
  inference genuinely can't see a shape. That should be rare with this pipeline.
- `config/scramble.php`: `security_strategy` is off — switch on
  `MiddlewareAuthSecurityStrategy` (matched to `auth:sanctum`) once auth routes
  exist.

## Testing

- Pest. Create with `php artisan make:test --pest {Name}` (feature) or `--unit`.
  No suite directory in the name. Most tests are feature tests.
- Use factories and their states. Do not delete tests without approval.
- Run the narrowest set: a file path or `vendor/bin/pest --filter=…`.
- See the `testing-best-practices` skill for coverage, naming, and isolation.

## Commands

All through Docker:

```
docker compose exec app php artisan …            # or: make artisan c="…"
docker compose exec app vendor/bin/pint <path> --format agent
docker compose exec app vendor/bin/phpstan analyse
docker compose exec app vendor/bin/pest
docker compose exec app composer check           # pint --test + phpstan + pest
```

- After touching PHP: Pint, then PHPStan, then the narrowest Pest run.
- `pint --dirty` formats only the files changed since the last commit.
- Generate with `php artisan make:* --no-interaction`; if the generator can't
  take a path, move the file into its domain subfolder and fix the namespace.
- After a migration: `php artisan ide-helper:models --write`, then Pint the
  touched models and commit the refreshed PHPDoc blocks.

## Git

- Feature branch → PR. Never commit directly to `main`.
- **Commit messages carry no AI attribution.** Never add a
  `Co-Authored-By: Claude …` or `Claude-Session: …` trailer, and never push a
  commit that contains one. Subject + body only.

## Workflows — database isolation

Parallel work runs in Claude workflows / git worktrees against the one running
Docker stack. A workflow that changes the schema or seed data **must not** touch
the shared `gym_trainer` database — clone it first, inside the same `pgsql`
container, and work on the copy:

```
# once, at the start of the workflow (<slug> = sanitised branch name)
docker compose exec pgsql createdb -U gym -T gym_trainer gym_trainer_<slug>

# point the workflow at the copy — in .env
DB_DATABASE=gym_trainer_<slug>

# when the branch is merged or dropped
docker compose exec pgsql dropdb -U gym --if-exists gym_trainer_<slug>
```

- `createdb -T` (template copy) needs no live connections on `gym_trainer`; if it
  refuses, stop `queue` + `scheduler` for the copy, or
  `pg_dump gym_trainer | psql gym_trainer_<slug>`.
- `gym_trainer` is the only long-lived database; every `gym_trainer_*` is
  disposable. The Pest suite is unaffected — it uses SQLite `:memory:`.

## Boost MCP

The `laravel-boost` MCP server (`.mcp.json`) runs inside the `app` container, so
bring the stack up first (`make up`). Prefer its tools over guessing:

- **`search-docs`** — version-specific Laravel / package docs. Use it before
  relying on any framework or package API.
- **`database-schema`**, **`database-query`** (read-only), **`database-connections`**
  — inspect the live PostgreSQL schema instead of reading migrations one by one.
- **`application-info`** — installed package versions and app config at a glance.
- **`last-error`**, **`read-log-entries`**, **`browser-logs`** — debugging.
- **`tinker`** — run PHP in app context. Do not create models this way; use a
  test with a factory, or an existing Artisan command.

Durable rules still go in this file by hand — not `record-rule`.
