# Registro de usuario — `POST /api/v1/register`

> Deriva del ticket de Notion "Registrarse en la aplicación" (Feature: Auth &
> perfil · MVP · Must) y del plan aprobado. Contrato base: `docs/product-context.md`
> y `docs/plans/data-model.md`.

## 1. Context

**Kind:** Greenfield Feature

**Stack:** PHP 8.5 · Laravel 13 · PostgreSQL 17 (runtime) / SQLite `:memory:` (tests) ·
Pest 4 (`pest-plugin-laravel`) · `laravel/sanctum` 4 (nueva dependencia) ·
`spatie/laravel-data` 4.23 · Pint · Larastan level 6. Todo corre en Docker.

**Problem statement:** El proyecto no tiene autenticación ni endpoints de API. Se
necesita el primer endpoint: un visitante crea su cuenta con `name`, `email` y
`password`, y queda autenticado por sesión (cookie) en la misma respuesta, para
empezar a usar la aplicación con sus propios datos. Este ticket también levanta la
infraestructura de API + auth desde cero (routing `/api/v1`, Sanctum en modo SPA,
CORS con credenciales) sobre la que se construirán login, logout y perfil.

**In scope:**
- Endpoint público `POST /api/v1/register` siguiendo el pipeline obligatorio de
  `CLAUDE.md` (Form Request → Controller invokable → Action → JSON Resource).
- Instalación y wiring de `laravel/sanctum` en **modo SPA / cookie stateful**
  (sin tokens).
- Wiring del grupo de rutas `api` con prefijo `/api/v1` en `bootstrap/app.php`.
- `config/cors.php` con `supports_credentials`.
- Normalización de email (minúsculas + trim) antes de la comprobación de unicidad.
- Inicio de sesión por cookie tras el alta (`Auth::login` + regeneración de sesión).
- Rate limiting `throttle:6,1` en la ruta.
- Suite Pest de feature que cubre los 4 criterios de aceptación + casos negativos.

**Out of scope:**
- Login, logout, refresh de sesión, recuperación de contraseña — tickets aparte.
- Verificación de email (`email_verified_at` queda `null`; sin evento `Registered`).
- Autenticación por token / `HasApiTokens` / tabla `personal_access_tokens`.
- Perfil de atleta, rutinas y cualquier otra tabla de dominio: el usuario recién
  creado no tiene nada más que su fila en `users` (AC #4 se cumple por construcción).
- Endpoint `GET /api/v1/user` o `/profile`.
- Activar `security_strategy` en `config/scramble.php` (se difiere a cuando exista
  la primera ruta protegida).
- SPA `gym-trainer-spa/` (repositorio separado).
- Manejo transaccional de la carrera de email duplicado bajo concurrencia (ver §9).

---

## 2. API Surface

### 2.1 REST

| Method | Path | Auth | Request | Response | Status codes |
|---|---|---|---|---|---|
| POST | `/api/v1/register` | Ninguna (ruta pública). Sujeta a `EnsureFrontendRequestsAreStateful` + CSRF por venir en el grupo `api` stateful, y a `throttle:6,1`. | JSON: `name` (string, req, ≤255), `email` (string, req, email, ≤255, único), `password` (string, req, ≥8, `confirmed`), `password_confirmation` (string, req, == `password`) | `201`: `{ "data": { "name": string, "email": string, "created_at": string ISO-8601 } }`. La respuesta setea la cookie de sesión (`config('session.cookie')`) y refresca `XSRF-TOKEN`. | `201` alta correcta y sesión iniciada · `422` validación (email ya registrado → error en `email`; password sin confirmar o `<8` → error en `password`; `name`/`email`/`password` ausentes o con formato inválido) · `429` rate limit excedido · `419` petición stateful sin token CSRF válido (cliente no llamó antes a `GET /sanctum/csrf-cookie`) |

Notas:
- El body **no** expone `id`. `docs/plans/data-model.md` fija que `users` no lleva
  `uuid` en v1 y que el `bigint` PK nunca cruza la frontera de la API ("la API solo
  opera sobre el usuario autenticado, nunca por id").
- `GET /sanctum/csrf-cookie` lo registra `SanctumServiceProvider` en la raíz de la
  app (no bajo `/api/v1`). No se define a mano. Un cliente navegador real debe
  llamarlo antes del `POST`.
- Errores renderizados como JSON por el handler de excepciones (ya configurado en
  `bootstrap/app.php` para `api/*`). Sin JSON construido a mano.

### 2.2 CLI

Not applicable — no CLI commands.

### 2.3 Events

| Event name | Producer | Consumer | Payload | Trigger condition |
|---|---|---|---|---|
| `Illuminate\Auth\Events\Login` | `Auth::login($user)` dentro de `UserRegisterAction` | Ninguno (sin listeners del proyecto) | guard (`web`), `User`, `remember` (false) | Se dispara automáticamente al iniciar sesión tras el alta |

`Illuminate\Auth\Events\Registered` **no** se dispara: solo tiene efecto con
`User implements MustVerifyEmail` + listener, y la verificación de email está fuera
de alcance. Es un añadido de una línea en el Action si se introduce más adelante.

---

## 3. UI

### 3.1 Pages

Not applicable — no pages affected. Es una API REST JSON; el frontend vive en el
repositorio `gym-trainer-spa/`, fuera de este ticket.

### 3.2 Components

Not applicable — no components affected.

---

## 4. Database

Not applicable — no data or schema changes. El endpoint usa las tablas stock
`users`, `sessions` y `password_reset_tokens` (creadas por
`0001_01_01_000000_create_users_table.php`) tal cual. `php artisan install:api`
publica una migración `*_create_personal_access_tokens_table.php` que se **elimina
sin ejecutar** (modo SPA-only nunca emite tokens). Resultado: este feature añade
**cero migraciones**, por lo que no aplica el workflow de aislamiento de BD de
`CLAUDE.md` (clonar `gym_trainer`).

### 4.1 Schema changes

Not applicable — no schema changes.

### 4.2 Seeds

Not applicable — no seeds.

---

## 5. Auth & Authorization

### 5.1 Authentication

**Method:** Session (cookie) vía **Laravel Sanctum en modo SPA / stateful**.

Flujo:
1. `install:api --stateful` añade `$middleware->statefulApi()` en
   `bootstrap/app.php`, que antepone `EnsureFrontendRequestsAreStateful` al grupo
   `api`. Para una petición cuyo `Origin`/`Referer` esté en
   `config('sanctum.stateful')`, ese middleware inyecta el grupo `web`
   (`EncryptCookies`, `StartSession`, `ValidateCsrfToken`,
   `AddQueuedCookiesToResponse`).
2. `UserRegisterAction` crea el `User`, llama `Auth::login($user)` (guard por
   defecto `web`) y luego `request()->session()->regenerate()`.
3. `StartSession` / `AddQueuedCookiesToResponse` emiten la cookie de sesión y el
   `XSRF-TOKEN` refrescado en la respuesta `201` → el usuario queda autenticado
   para las siguientes peticiones sin llamada a `/login` (AC #1).

Decisiones de configuración:
- **Sin guard `api`** en `config/auth.php`. La auth SPA monta sobre el guard `web`
  de sesión existente. `config('sanctum.guard')` = `['web']` (default).
- **Sin `HasApiTokens`** en el modelo `User`.
- `config('sanctum.expiration')` = `null` (la vida la marca la sesión).
- CORS: `supports_credentials => true` y `allowed_origins` explícito (nunca `*`
  con credenciales) — ver §6.
- En el entorno de test, `ValidateCsrfToken` se auto-bypassa
  (`runningUnitTests()`), pero el dominio stateful debe estar configurado para que
  la sesión se inicialice (ver §6 y §8).

### 5.2 Authorization

Not applicable — no authorization changes. La ruta es pública: sin actor, sin
recurso, sin Policy. Es la **excepción explícita** a la regla 4 de `CLAUDE.md`
("every data route: `auth:sanctum` + a Policy") — se documenta como tal en
`routes/api.php`. `RegisterRequest::authorize()` devuelve `true`.

---

## 6. Configuration

**Environment variables** (`.env.example` + `.env` local):

| Variable | Value / Source | Purpose |
|---|---|---|
| `FRONTEND_URL` | `http://localhost:5173` | Origen permitido en CORS (`config/cors.php` → `allowed_origins`). |
| `SANCTUM_STATEFUL_DOMAINS` | `localhost:5173,localhost:8000,127.0.0.1:5173,127.0.0.1:8000` | Hosts cuyas peticiones se tratan como stateful (sesión + CSRF). |
| `SESSION_DOMAIN` | `localhost` (dev). Prod: dominio registrable real, p. ej. `.example.com` | Ámbito de la cookie de sesión. Hoy está en `null`. |
| `AUTH_GUARD` | **No se añade** (o comentado con valor `web`) | `Auth::login()` apunta a `config('auth.defaults.guard')`; ponerlo en `sanctum` rompería el login stateful en silencio. |

**Prod (documentar, no se toca en este ticket):** `SESSION_SECURE_COOKIE=true`,
HTTPS, `SESSION_SAME_SITE=lax` (revisar), `SESSION_DOMAIN` con el dominio real.

**Test env** (`phpunit.xml`):

| Variable | Value | Purpose |
|---|---|---|
| `SANCTUM_STATEFUL_DOMAINS` | `localhost` | Sin esto, `postJson` (sin `Origin`) no dispara el grupo `web`, `StartSession` no corre y `session()->regenerate()` en el Action lanza `RuntimeException: Session store not set on request`. |
| `APP_URL` | `http://localhost` | Determinismo: el header `Origin` que envían los tests y `SANCTUM_STATEFUL_DOMAINS` deben coincidir en host. |

**Archivos de configuración modificados:**

| Archivo | Cambio |
|---|---|
| `composer.json` / `composer.lock` | `require laravel/sanctum ^4`. |
| `bootstrap/app.php` | `withRouting(...)`: añadir `api: __DIR__.'/../routes/api.php'` y `apiPrefix: 'api/v1'`. `withMiddleware(...)`: `$middleware->statefulApi();`. `withExceptions(...)` sin cambios. |
| `routes/api.php` | Nuevo. Una ruta: `Route::post('register', RegisterController::class)->middleware('throttle:6,1')->name('auth.register');` con `use` de la clase (PHPStan analiza `routes/`). |
| `config/cors.php` | Publicar. `paths => ['api/*', 'sanctum/csrf-cookie']`; `allowed_methods => ['*']`; `allowed_origins => [env('FRONTEND_URL', 'http://localhost:5173')]`; `allowed_headers => ['*']`; `supports_credentials => true`. |
| `config/sanctum.php` | Publicar. Dejar visibles `stateful` (de `SANCTUM_STATEFUL_DOMAINS`), `guard => ['web']`, `expiration => null`. Resto stock. |
| `config/auth.php` | Sin cambios. |
| `config/scramble.php` | Sin cambios (`security_strategy => null`). |
| `tests/Pest.php` | Descomentar `->use(RefreshDatabase::class)` (scope `Feature`). |
| `phpunit.xml` | Añadir los dos `<env>` de la tabla de test. |
| `database/migrations/*_create_personal_access_tokens_table.php` | **Borrar** (publicada por `install:api`, no se ejecuta). |

---

## 7. Current vs New Behavior

| Behavior | Current | New |
|---|---|---|
| Alta de usuario | No existe. `users` solo se puebla por `DatabaseSeeder` / factory. | `POST /api/v1/register` crea la cuenta, inicia sesión por cookie y devuelve `201` con `{ data: { name, email, created_at } }`. |
| Routing de API | `bootstrap/app.php` solo registra `web`, `console`, `health`. No hay `routes/api.php` ni prefijo `/api`. | Grupo `api` registrado con prefijo `/api/v1` y middleware `statefulApi()`. |
| Autenticación | Ninguna. `config/auth.php` stock (guard `web` sin uso). Sanctum no instalado. | Sanctum SPA (cookie + CSRF) sobre el guard `web`. `GET /sanctum/csrf-cookie` disponible. |
| CORS | Sin `config/cors.php` (defaults del framework, sin credenciales). | `config/cors.php` publicado con `supports_credentials => true` y origen explícito. |
| Normalización de email | N/A | Email pasado a minúsculas + trim en `prepareForValidation()` antes del check `unique`; se persiste normalizado. |
| Rate limiting | El grupo `api` no existe, sin throttle. | `throttle:6,1` en la ruta de registro (`429` al exceder). |
| Tests que tocan BD | `RefreshDatabase` comentado en `tests/Pest.php`; solo `ExampleTest`. | `RefreshDatabase` activo para `Feature`; suite `tests/Feature/Auth/RegisterTest.php`. |

---

## 8. Test Cases

Ejecutables con Pest 4 sobre SQLite `:memory:` (`RefreshDatabase`). `beforeEach`
fija `$this->withHeader('Origin', config('app.url'))` para que la petición sea
stateful. Payload válido base: `name` = `"Ada Lovelace"`, `email` =
`"ada@example.com"`, `password` = `password_confirmation` = `"secret-password"`.

**TC-1:** Alta correcta crea el usuario y lo deja autenticado
- **Given:** ningún usuario con `email` `ada@example.com`
- **When:** `POST /api/v1/register` con el payload válido base
- **Expect:** `201`; `assertAuthenticated()` (guard `web`); `assertDatabaseHas('users', ['email' => 'ada@example.com', 'name' => 'Ada Lovelace'])`; `assertDatabaseCount('users', 1)`; `Hash::check('secret-password', User::first()->password)` es `true`; `assertJsonPath('data.email', 'ada@example.com')` y `assertJsonPath('data.name', 'Ada Lovelace')`

**TC-2:** La respuesta del alta setea la cookie de sesión
- **Given:** ningún usuario con ese email
- **When:** `POST /api/v1/register` con el payload válido base
- **Expect:** `201`; `assertCookie(config('session.cookie'))` (o cabecera `Set-Cookie` presente)

**TC-3:** El email se normaliza a minúsculas y se persiste normalizado
- **Given:** ningún usuario con ese email
- **When:** `POST /api/v1/register` con `email` = `"  Ada@Example.COM  "`
- **Expect:** `201`; `assertDatabaseHas('users', ['email' => 'ada@example.com'])`; `assertJsonPath('data.email', 'ada@example.com')`

**TC-4:** Email ya registrado → `422` con error en `email`
- **Given:** `User::factory()->create(['email' => 'taken@example.com'])`
- **When:** `POST /api/v1/register` con `email` = `"taken@example.com"` y el resto del payload válido
- **Expect:** `422`; `assertJsonValidationErrors('email')`; `assertDatabaseCount('users', 1)`; `assertGuest()`

**TC-5:** Email ya registrado con distinta capitalización → `422` con error en `email`
- **Given:** `User::factory()->create(['email' => 'taken@example.com'])`
- **When:** `POST /api/v1/register` con `email` = `"TAKEN@example.com"`
- **Expect:** `422`; `assertJsonValidationErrors('email')`; `assertDatabaseCount('users', 1)`

**TC-6:** Contraseña que no coincide con su confirmación → `422` en `password`
- **Given:** ningún usuario con ese email
- **When:** `POST /api/v1/register` con `password` = `"secret-password"` y `password_confirmation` = `"other-password"`
- **Expect:** `422`; `assertJsonValidationErrors('password')`; `assertDatabaseCount('users', 0)`; `assertGuest()`

**TC-7:** Contraseña más corta que el mínimo (8) → `422` en `password`
- **Given:** ningún usuario con ese email
- **When:** `POST /api/v1/register` con `password` = `password_confirmation` = `"short"`
- **Expect:** `422`; `assertJsonValidationErrors('password')`; `assertGuest()`

**TC-8:** Falta `name` → `422` en `name`
- **Given:** ningún usuario con ese email
- **When:** `POST /api/v1/register` sin `name`
- **Expect:** `422`; `assertJsonValidationErrors('name')`

**TC-9:** Email ausente o con formato inválido → `422` en `email` (dataset: `null`, `"not-an-email"`)
- **Given:** ningún usuario con ese email
- **When:** `POST /api/v1/register` con cada valor del dataset como `email`
- **Expect:** `422`; `assertJsonValidationErrors('email')`

**TC-10:** Falta `password` → `422` en `password`
- **Given:** ningún usuario con ese email
- **When:** `POST /api/v1/register` sin `password` ni `password_confirmation`
- **Expect:** `422`; `assertJsonValidationErrors('password')`

**TC-11:** La respuesta no expone el id interno
- **Given:** ningún usuario con ese email
- **When:** `POST /api/v1/register` con el payload válido base
- **Expect:** `201`; `assertJsonMissingPath('data.id')`; `assertJsonStructure(['data' => ['name', 'email', 'created_at']])`

**TC-12:** El usuario recién creado no tiene perfil de atleta ni rutinas
- **Given:** ningún usuario con ese email
- **When:** `POST /api/v1/register` con el payload válido base
- **Expect:** `201`; `assertDatabaseCount('users', 1)`; el body no incluye `profile` ni `routines`. Comentario en el test: AC #4 se cumple por construcción (el registro no crea nada salvo la fila `users`); este caso ganará aserciones reales (`$user->athleteProfile()->exists()` falso, `$user->routines()->count() === 0`) cuando existan los dominios Profile / Routine.

**TC-13:** Rate limiting en la ruta de registro
- **Given:** ningún usuario previo
- **When:** se envían 7 `POST /api/v1/register` seguidos desde la misma IP (emails distintos)
- **Expect:** las primeras 6 respuestas son `201`/`422` (no `429`); la 7.ª es `429`

**TC-14 (arch, opcional — fuera de los AC):** convenciones del pipeline
- **Given:** el código del proyecto
- **When:** se ejecutan aserciones de arquitectura Pest
- **Expect:** `App\Actions\*` es `final` y tiene método `handle`; `App\Http\Controllers\Auth\*` es invokable; `App\Http\Requests\*` extiende `FormRequest`; `dd`/`dump`/`ray` no se usan en `app/`

---

## 9. Technical Decisions

| Decision area | What was decided | Why |
|---|---|---|
| Path / versionado | `POST /api/v1/register` con prefijo global `apiPrefix: 'api/v1'` | `CLAUDE.md` regla 1 exige `/api/v1`. El ticket ("`POST /api/register`") se interpreta como "la ruta de registro", no literal. |
| Campo `name` | Requerido en el request (`string`, `max:255`) | La columna stock `users.name` es NOT NULL; requerirlo evita una migración y es lo habitual en una pantalla de registro. |
| Mecanismo de auth | `laravel/sanctum` en modo SPA / cookie stateful; sin tokens; sin `HasApiTokens` | `docs/product-context.md` y `docs/plans/data-model.md` fijan "Sanctum modo SPA (cookie + CSRF)". El AC #1 pide sesión por cookie. |
| Guard | Reusar el guard `web` de sesión; sin guard `api` en `config/auth.php` | La auth SPA de Sanctum monta sobre la sesión; `config('sanctum.guard')` = `['web']` por defecto. Añadir un guard sería indirección inútil (regla 6). |
| Capa de Service | **No** se crea Service; el Action llama `User::create()` directamente | No hay conocimiento de negocio: unicidad = regla `unique:` (Form Request), hash = cast `hashed` del modelo, "sin perfil/rutinas" = no hacer nada. `CLAUDE.md` regla 6 + ejemplo `RoutineCreateAction`. |
| Ubicación de `Auth::login()` + `session()->regenerate()` | En `UserRegisterAction`, tras `User::create()` | El Action es la única capa que dispara efectos/eventos (`Login`); "crear + autenticar" es un caso de uso indivisible. El controller conserva status 201 y shape (Resource). Usa el helper `request()`, no un `Request` inyectado. |
| Evento `Registered` | No se dispara en v1 | Sin `MustVerifyEmail` ni listener no hace nada; verificación de email fuera de alcance (regla 5). `Auth::login()` ya dispara `Login`. |
| Contenido del Resource | `UserResource` expone `name`, `email`, `created_at` (ISO-8601). **Sin `id`**. Ubicado en `app/Http/Resources/Auth/` | `docs/plans/data-model.md`: `users` no lleva `uuid` en v1 y el `bigint` PK no cruza la API. `created_at` como string explícito para que Scramble infiera `type: string`. |
| DTO | `App\Data\Auth\RegisterData` (`spatie/laravel-data`), readonly `name/email/password`, sin `password_confirmation`; `RegisterData::from($request->validated())` | Convención de `CLAUDE.md` (writes toman un `Data` object). `validation_strategy` = `OnlyRequests` → construir desde el array validado no revalida; el Form Request es la única autoridad de validación. |
| Regla de contraseña | `Password::min(8)` (solo longitud; sin complejidad, sin HIBP) ni configuración global | El AC #3 solo pide "longitud mínima"; 8 es el piso estándar de Laravel. Se puede endurecer en el ticket de login. |
| Normalización de email | `prepareForValidation()` baja a minúsculas + `trim` antes del check `unique`; se persiste normalizado | Evita cuentas duplicadas por capitalización; `Ada@X.com` colisiona con `ada@x.com` → `422`. |
| Rate limiting | `throttle:6,1` en la ruta | Endpoint público, no autenticado y de escritura: blanco de abuso. Valor alineado con el default de Laravel para rutas de auth. |
| Carrera de email duplicado | Solo validación (`unique:` + índice único). Se acepta que, bajo dos requests concurrentes con el mismo email, uno pueda devolver `500` (violación única 23505) en vez de `422`. Riesgo documentado. | En v1 (tráfico bajo) la colisión exacta es improbable; capturar la `QueryException` en el Action añade complejidad no justificada ahora. Se puede endurecer si el tráfico lo exige. |
| Migración `personal_access_tokens` | Borrar la publicada por `install:api` sin ejecutarla → cero migraciones | Modo SPA-only nunca emite tokens; Sanctum 4 no auto-carga migraciones del paquete. Evita el workflow de clonado de BD de `CLAUDE.md`. |
| CORS | `config/cors.php` publicado: `supports_credentials => true`, `allowed_origins` explícito (nunca `*`), `paths` con `api/*` y `sanctum/csrf-cookie` | Sin credenciales el navegador descarta el `Set-Cookie` del registro. El spec CORS prohíbe `*` con credenciales. |
| Scramble `security_strategy` | Sin cambios (`null`) | `register` es público; con la estrategia apagada ya se documenta como sin seguridad. Se activará (para cookie/apiKey, **no** bearer) cuando exista la primera ruta protegida. |
| Tests: sesión stateful | `SANCTUM_STATEFUL_DOMAINS=localhost` + `APP_URL=http://localhost` en `phpunit.xml`; header `Origin` en cada test | Sin dominio stateful el grupo `web` no se inyecta y `session()->regenerate()` en el Action revienta. Se ejercita el camino real del SPA. CSRF se auto-bypassa en test. |
| Tests: `RefreshDatabase` | Descomentar la línea global en `tests/Pest.php` (scope `Feature`) | Es el primer test que toca BD; todos los feature tests siguientes lo necesitan. |
| Atribución en commits | Seguir `CLAUDE.md`: **sin** trailers `Co-Authored-By: Claude` / `Claude-Session:` | `CLAUDE.md` es el contrato del repo y lo prohíbe explícitamente. No hay check de trailer en `.github/`, pero el contrato manda. (Fuera del alcance funcional; se anota para la fase de commit.) |

---

## 10. Work Plan

Las clases del pipeline se crean antes de cablear `routes/api.php` (que las
referencia). Los Test Cases se implementan y se ejecutan al final (tarea 15); las
DoD de las tareas 8–13 se limitan a que el artefacto exista y pase Pint + PHPStan.

| # | Task | Definition of Done |
|---|---|---|
| 1 | `composer require laravel/sanctum ^4` | `composer.lock` fija Sanctum 4.x; `composer install` en el contenedor sin errores. |
| 2 | `php artisan install:api --stateful --no-interaction` y verificar el scaffolding; si falta algo, editar `bootstrap/app.php` a mano | `bootstrap/app.php` tiene `api:` en `withRouting()` y `$middleware->statefulApi()` en `withMiddleware()`; existe `routes/api.php`; existe la migración `*_create_personal_access_tokens_table.php`. No se ejecutaron migraciones. |
| 3 | Borrar `database/migrations/*_create_personal_access_tokens_table.php` | El archivo no existe; `php artisan migrate --pretend` no lista esa tabla; el feature no añade migraciones. |
| 4 | Ajustar `bootstrap/app.php`: `apiPrefix: 'api/v1'` en `withRouting()` | `php artisan route:list` muestra las rutas del grupo `api` bajo el prefijo `api/v1`. |
| 5 | Publicar y editar `config/cors.php` (`supports_credentials => true`, `allowed_origins` de `FRONTEND_URL`, `paths` con `api/*` y `sanctum/csrf-cookie`, `allowed_methods`/`allowed_headers` `['*']`) | El archivo existe con esos valores; PHPStan level 6 limpio en `config/`. |
| 6 | Publicar `config/sanctum.php` y dejar `stateful` (de env), `guard => ['web']`, `expiration => null` | El archivo existe; `config('sanctum.guard')` === `['web']`. |
| 7 | Añadir `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN` a `.env.example` (y `.env` local) | `.env.example` contiene las tres claves con los valores de §6; `config('sanctum.stateful')` resuelve la lista esperada. |
| 8 | Crear `app/Http/Requests/Auth/RegisterRequest.php` (`authorize(): true`; `rules()` para `name` `['required','string','max:255']`, `email` `['required','string','email','max:255','unique:users,email']`, `password` `['required','confirmed', Password::min(8)]`; `prepareForValidation()` baja el email a minúsculas + `trim`) | El archivo existe; Pint + PHPStan level 6 limpios. |
| 9 | Crear `app/Data/Auth/RegisterData.php` (readonly `string $name/$email/$password`, sin `password_confirmation`) vía `make:data`, mover a `app/Data/Auth/`, corregir namespace | `RegisterData::from(['name'=>…,'email'=>…,'password'=>…])` construye el DTO; Pint + PHPStan limpios. |
| 10 | Crear `app/Http/Resources/Auth/UserResource.php` (`toArray()` → `name`, `email`, `created_at` como ISO-8601; sin `id`, sin relaciones) | El archivo existe; Pint + PHPStan limpios. |
| 11 | Crear `app/Actions/Auth/UserRegisterAction.php` (`final`, `handle(RegisterData $data): User` → `User::create([...])` → `Auth::login($user)` → `request()->session()->regenerate()` → `return $user`; sin `DB::transaction`, sin evento `Registered`) | El archivo existe; Pint + PHPStan limpios. |
| 12 | Crear `app/Http/Controllers/Auth/RegisterController.php` (`final`, `__invoke(RegisterRequest $request, UserRegisterAction $action): JsonResponse` → `RegisterData::from($request->validated())` → `$action->handle(...)` → `UserResource::make($user)->response()->setStatusCode(201)`) vía `make:controller --invokable`, mover y corregir namespace | El archivo existe; Pint + PHPStan limpios. |
| 13 | Escribir `routes/api.php`: `Route::post('register', RegisterController::class)->middleware('throttle:6,1')->name('auth.register')` con `use App\Http\Controllers\Auth\RegisterController;` | `php artisan route:list` muestra `POST api/v1/register` → `RegisterController` con middleware `throttle:6,1`; PHPStan limpio en `routes/`. |
| 14 | Descomentar `->use(RefreshDatabase::class)` en `tests/Pest.php`; añadir `SANCTUM_STATEFUL_DOMAINS=localhost` y `APP_URL=http://localhost` a `phpunit.xml` | `docker compose exec app vendor/bin/pest` arranca sin `RuntimeException` de BD ni `Session store not set on request`; `ExampleTest` sigue verde. |
| 15 | Escribir `tests/Feature/Auth/RegisterTest.php` con TC-1..TC-13 (`beforeEach` fija `withHeader('Origin', config('app.url'))`) | `docker compose exec app vendor/bin/pest tests/Feature/Auth/RegisterTest.php` todo verde. |
| 16 | (Opcional, fuera de los AC) Añadir el arch test TC-14 (`tests/Arch/PipelineTest.php` o fichero arch compartido) | El arch test pasa. |
| 17 | `docker compose exec app composer check` (Pint `--test` + PHPStan level 6 + Pest completo) | Los tres pasos verdes. |
| 18 | Verificación manual con `curl` contra `http://localhost:8000` (`GET /sanctum/csrf-cookie` → `POST /api/v1/register` `201` con `Set-Cookie` → email repetido `422` en `email` → password sin confirmar `422` en `password`); revisar `GET /docs/api` | Los `curl` devuelven los códigos esperados; el endpoint aparece en Scramble con el body inferido de `RegisterRequest` y la respuesta `201` de `UserResource`. |
