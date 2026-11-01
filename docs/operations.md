# Operations: environments, deploy, logs, troubleshooting

Two very different runtimes share one codebase:

| | Local / dev | Production |
|---|---|---|
| Runtime | `docker compose` (7 services) | Google Cloud Run, region `southamerica-east1` |
| Web | `nginx` → `app` (php-fpm) on :8000 | one container: supervisord runs nginx + php-fpm on :8080, also serves the SPA |
| Queue | long-lived `queue` container (`queue:work`) | short **Cloud Run job** `process-queue`, started about every 2 min |
| Scheduler | long-lived `scheduler` container (`schedule:work`) | Cloud Run job `retry-failed-analysis` (the one scheduled command) |
| DB / cache | `pgsql` 17 + `redis` 7 containers | managed equivalents configured through Cloud Run env (not stored in the repo) |
| Tests | SQLite `:memory:`, `QUEUE_CONNECTION=sync` | — |

> What the repo does **not** contain: the Cloud Run service/job definitions, the
> Cloud Scheduler triggers, and the production env vars/secrets. They live in the
> GCP project (`gym-trainer-api`). To see them:
> `gcloud run services describe gym-trainer-api --region southamerica-east1`
> and `gcloud run jobs describe process-queue --region southamerica-east1`.
> If you are the first new developer, ask the maintainer for GCP access.

## 1. Local development

Requirements: Docker + Compose only.

```bash
cp .env.example .env     # already points at the compose services
make build               # UID/GID are passed so bind-mounted files keep your ownership
make up                  # app, nginx, queue, scheduler, pgsql, redis
make artisan c="migrate"
```

API: <http://localhost:8000>. API docs (Scramble): `/docs/api` (UI) and
`/docs/api.json` (spec).

Set one AI provider in `.env`: `AI_PROVIDER` (`anthropic`, `openai`, `groq`, …),
`AI_PROVIDER_API_KEY`, optionally `AI_PROVIDER_MODEL` and `AI_PROVIDER_URL`.
Without a key the two AI flows (create routine, session analysis) fail; the rest
of the API works. The test suite never calls a provider (fake agents).

Daily commands (all run inside the `app` container):

| Task | Command |
|---|---|
| Shell | `make shell` |
| Artisan | `make artisan c="route:list"` |
| Tests (all / one) | `make test` / `docker compose exec app vendor/bin/pest tests/Feature/Session/LogSetTest.php` |
| Format | `docker compose exec app vendor/bin/pint --dirty --format agent` |
| Static analysis | `make stan` |
| Everything CI runs | `make check` (Pint + PHPStan + Pest) |
| Refresh model PHPDoc after a migration | `make artisan c="ide-helper:models --write"` then Pint `app/Models` |

After code changes that the long-lived workers have already loaded, restart them:
`make restart` (restarts `app`, `queue`, `scheduler`). **Forgetting this is the
#1 cause of "my job code change does nothing".**

### Working in parallel (git worktrees)
See `CLAUDE.md` → *Workflows — database isolation*: schema-changing work must
use a cloned database (`createdb -T gym_trainer gym_trainer_<slug>`), never the
shared `gym_trainer`.

## 2. CI

`.github/workflows/` runs on every PR and on pushes to `main`:

- **Pint** (`pint --test`)
- **PHPStan** (Larastan level 6)
- **Pest** on SQLite, then a **model PHPDoc freshness check**: it runs the
  migrations, regenerates the PHPDoc with `ide-helper` and fails if `app/Models`
  changes. If it fails: run the ide-helper command above, Pint `app/Models`,
  commit.

Workflow: feature branch → PR → CI green → merge. Never commit to `main`.

## 3. Deploying to production

Deploys are a **manual command run from a developer machine** — there is no
CD pipeline.

```bash
make deploy              # = docker/production/deploy.sh
SPA_ONLY=1 make deploy   # ship only a new SPA build (no job update, no migrations)
FORCE=1 make deploy      # skip the "on main & clean tree" safety check
```

Prerequisites: `gcloud` authenticated with access to project `gym-trainer-api`,
Docker, Node/npm, and the **SPA repo checked out next to this one**
(`../gym-trainer-spa`, override with `SPA_DIR`). Env overrides: `GCP_PROJECT`,
`GCP_REGION`, `IMAGE`, `SERVICE`.

What the script does, in order:

1. **Guards:** must be on `main` with a clean working tree (unless `FORCE=1`).
2. **Builds the SPA** (`VITE_API_URL=` empty → the SPA calls `/api/v1` on its own
   origin) and copies `dist` into `docker/production/spa-dist`.
3. **Builds & pushes the image** with Cloud Build (`cloudbuild.yaml`,
   `docker/production/Dockerfile`: stage 1 `composer install --no-dev` +
   optimized autoload; stage 2 `php:8.5-fpm` + nginx + supervisor + the SPA).
4. Resolves the **image digest** — everything is pinned to the digest, never the
   moving `:latest` tag, so a deploy is reproducible and revertible.
5. Updates both Cloud Run **jobs** (`process-queue`, `retry-failed-analysis`) to
   that digest.
6. Runs **migrations** by executing the `process-queue` job with
   `artisan migrate --force` and waiting for it. Migrations therefore run
   *before* the web service switches over: **write migrations that are backwards
   compatible with the previous release** (add nullable columns / backfill first,
   drop later).
7. **Deploys the web service** (`gcloud run deploy`).
8. Prints the command to look at recent errors (see §4).

How one image plays three roles: `entrypoint.sh` runs the arguments it is given
if there are any (a Cloud Run **job** with a command override: `queue:work`,
`artisan migrate`, one-off commands) and otherwise starts supervisord
(nginx + php-fpm = the **web service**).

Things that are deliberately different in production:

- **No `config:cache`** — `config/scramble.php` holds an object that can't be
  serialized by `config:cache`, so config is resolved per request. Don't add
  `config:cache` to the image without fixing that first.
- **No long-lived queue worker.** `process-queue` is a short job
  (`queue:work --stop-when-empty --max-time=120`, started roughly every 2
  minutes by Cloud Scheduler). Consequences: a queued job starts up to ~2 min
  late; delayed jobs wait for the next pass; a worker mid-job at deploy time
  finishes on the old code within its 120 s cap. This is why cycle generation
  takes ~5–10 min in production (`docs/plans/async-cycle-generation-spec.md`).
- **Request timeout:** nginx `fastcgi_read_timeout` is 130 s (the create-routine
  AI call can take up to ~60 s).
- **Upload limit:** `client_max_body_size 20M` (xlsx import).
- Routing in the container: `/api/*`, `/sanctum/*`, `/docs*`, `/up` → PHP;
  everything else → the SPA's `index.html`.

### Rolling back
Re-deploy the previous image digest:

```bash
gcloud artifacts docker images list us-central1-docker.pkg.dev/gym-trainer-api/gym-trainer/api --include-tags
gcloud run deploy gym-trainer-api --region southamerica-east1 --image <old-digest>
gcloud run jobs update process-queue        --region southamerica-east1 --image <old-digest>
gcloud run jobs update retry-failed-analysis --region southamerica-east1 --image <old-digest>
```

Migrations are **not** rolled back automatically. This is why they must be
backwards compatible (see step 6).

## 4. Logs

### Application logs (Laravel)
Configured in `config/logging.php`; channel chosen by `LOG_CHANNEL`
(default `stack`) → `LOG_STACK` (default `single`) with `LOG_LEVEL`.

**Local:** a file, `storage/logs/laravel.log` (bind-mounted, so it is on your host):

```bash
tail -f storage/logs/laravel.log
make logs                                  # all compose services (nginx, php, queue…)
docker compose logs -f queue               # only the queue worker
docker compose logs -f --tail=200 scheduler
```

Boost MCP tools `last-error` / `read-log-entries` read the same file.

**Production:** Cloud Run only collects what a process writes to
stdout/stderr. nginx and php-fpm already do (supervisord forwards them to
`/dev/stdout`/`/dev/stderr`), and so does the `stderr` Laravel channel. Check
which channel production uses with
`gcloud run services describe gym-trainer-api --region southamerica-east1 --format='value(spec.template.spec.containers[0].env)'`
(look for `LOG_CHANNEL`). If it is `single`/`daily`, application logs are
written inside the container's filesystem and are **lost** — set
`LOG_CHANNEL=stderr` to have them show up in Cloud Logging.

Read them (this is the command `deploy.sh` prints at the end):

```bash
# recent errors from the web service and the jobs
gcloud logging read 'resource.type="cloud_run_revision" AND severity>=ERROR' \
  --project gym-trainer-api --freshness=10m --limit=10

# the web service only
gcloud logging read 'resource.type="cloud_run_revision" AND resource.labels.service_name="gym-trainer-api"' \
  --project gym-trainer-api --freshness=30m --limit=50

# a job's executions (queue worker passes)
gcloud logging read 'resource.type="cloud_run_job" AND resource.labels.job_name="process-queue"' \
  --project gym-trainer-api --freshness=30m --limit=50
```

The GCP console equivalent is *Cloud Run → service/job → Logs*.

### What gets logged on purpose
- `Log::warning('Cycle progression AI failed; falling back…')` — a day fell back
  to session recommendations (`CycleDayProgressAction`).
- `Log::info('sessions:retry-failed-analysis: …', found/requeued)` — each
  scheduled retry run.
- `Log::info("Possible duplicate exercise: …")` — the catalogue created a name
  that resembles an existing one (`ExerciseCatalogService`).
- Unhandled exceptions go through Laravel's handler; `ApiExceptionRenderer` only
  shapes the response. In production the client sees `"Server error."`; the
  real message/trace is in the log (with `APP_DEBUG=true` it would be in the
  response — never enable that in production).

### AI traces
`laravel/ai` stores agent conversations in `agent_conversations` /
`agent_conversation_messages`; `cycles.conversation_id` and
`training_sessions.conversation_id` link a row to its conversation. To see
exactly what a model was asked and answered for a given cycle or session, query
those tables (via `psql`, or Boost `database-query` locally).

### Health
`GET /up` (Laravel's built-in health route) returns 200 when the app boots.

## 5. Troubleshooting

| Symptom | Likely cause | Check / fix |
|---|---|---|
| Every call is 401 right after login (SPA) | Origin not in `SANCTUM_STATEFUL_DOMAINS`, or no CSRF cookie fetched | `.env`; call `/sanctum/csrf-cookie` first |
| `419 CSRF_TOKEN_MISMATCH` | Missing `X-XSRF-TOKEN` header | SPA HTTP client config |
| `POST /routines` → `502 AI_GENERATION_FAILED` | Provider error/timeout, or the plan failed validation (wrong day count, exercises-per-day out of range…) | Log has the real reason (`CycleGenerationException` message); retry; on rate-limited tiers see the notes in `CyclePlannerAgent` |
| `POST /routines` → `409 PROFILE_INCOMPLETE` | No athlete profile | `PUT /profile` first |
| Cycle stuck in `generating` | Queue not running (local: `queue` container down / stale code; prod: `process-queue` not firing) or a delayed job waiting | `docker compose ps`, `docker compose logs queue`; prod: check the job's executions |
| Cycle ended `failed` | A day job crashed or finalize failed 3× | Logs around the job; `POST …/cycles` again deletes the failed cycle and retries |
| Session `analysis_state=failed` | Provider error 3× | Waits for the 08/14/20 h retry, or run `php artisan sessions:retry-failed-analysis` |
| `SESSION_IN_PROGRESS` and the user can't find the open session | A forgotten `in_progress` session | `DELETE /sessions/{id}` (UC-17) or list sessions with `status=in_progress` |
| Job code change has no effect (local) | Workers cache the old code | `make restart` |
| `LazyLoadingViolationException` / "attribute does not exist" in dev | `Model::shouldBeStrict` is on outside production | Eager-load the relation (`with()`/`load()`), declare the attribute |
| CI fails only on "Model PHPDoc is up to date" | Migration changed without refreshing PHPDoc | ide-helper + Pint `app/Models`, commit |
| Tests flaky on JSON number asserts | Random factory data / decimal formatting | Compare decimals loosely; loop new tests before calling them green |

## 6. Configuration cheat-sheet

| Variable | Purpose |
|---|---|
| `APP_ENV`, `APP_DEBUG` | `production` + `false` in prod; strict-model and destructive-command guards depend on `APP_ENV` |
| `DB_*` | PostgreSQL connection |
| `REDIS_*`, `CACHE_STORE`, `SESSION_DRIVER` | Redis for cache and sessions |
| `QUEUE_CONNECTION` | `database` (jobs table) in dev and prod; `sync` in tests |
| `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN` | SPA cookie auth |
| `AI_PROVIDER`, `AI_PROVIDER_API_KEY`, `AI_PROVIDER_MODEL`, `AI_PROVIDER_URL` | AI provider selection |
| `CYCLE_EXERCISES_PER_DAY_{BEGINNER,INTERMEDIATE,ADVANCED}` | `"min-max"` exercises per day (`config/training.php`) |
| `LOG_CHANNEL`, `LOG_STACK`, `LOG_LEVEL` | Logging (see §4) |
