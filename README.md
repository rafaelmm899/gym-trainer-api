# AI Gym Trainer — API

Backend Laravel del entrenador personal impulsado por IA. Contexto de producto en
[`docs/product-context.md`](docs/product-context.md).

## Stack

| Área      | Decisión |
|-----------|----------|
| Lenguaje  | PHP 8.5 |
| Framework | Laravel 13 (API REST JSON) |
| DB        | PostgreSQL 17 |
| Cache / Session | Redis 7 (`phpredis`) |
| Queue     | `database` (dev) — worker y scheduler como contenedores |
| IA        | `laravel/ai`, providers `anthropic` + `openai`, default por `AI_PROVIDER` |
| DTOs      | `spatie/laravel-data` |
| Calidad   | Pint · Larastan/PHPStan (nivel 6) · Pest 4 · Laravel Boost |

## Requisitos

Solo Docker + Docker Compose. Nada de PHP/Composer en el host.

## Puesta en marcha

```bash
cp .env.example .env          # ya viene apuntando a los servicios de compose
make build                    # o: docker compose build
make up                       # levanta app, nginx, queue, scheduler, pgsql, redis
make artisan c="migrate"      # o: docker compose exec app php artisan migrate
```

API en <http://localhost:8000>.

## Servicios (docker-compose)

| Servicio    | Descripción                              | Puerto host |
|-------------|------------------------------------------|-------------|
| `nginx`     | Reverse proxy → php-fpm                   | 8000 |
| `app`       | PHP 8.5 FPM (Laravel)                     | — |
| `queue`     | `php artisan queue:work`                  | — |
| `scheduler` | `php artisan schedule:work`               | — |
| `pgsql`     | PostgreSQL 17                             | 5432 |
| `redis`     | Redis 7                                   | — |

## Comandos

```bash
make shell                    # bash dentro del contenedor app
make test                     # Pest
make pint                     # formateo
make stan                     # análisis estático
make composer c="require ..." # composer dentro del contenedor
```

`composer check` (dentro del contenedor) corre Pint + PHPStan + Pest.

## IA

`config/ai.php` usa `AI_PROVIDER` (`anthropic` por defecto). Definí
`ANTHROPIC_API_KEY` / `OPENAI_API_KEY` en `.env`. Los tests usan respuestas
*fake*, nunca llaman a un proveedor real.

Guías de IA para el repo: `php artisan boost:install` (opcional, interactivo).
