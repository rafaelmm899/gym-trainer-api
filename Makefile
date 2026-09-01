DC := docker compose
EXEC := $(DC) exec app

.PHONY: help build up down restart logs shell composer artisan migrate fresh test pint stan check

help: ## List targets
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

build: ## Build images
	UID=$$(id -u) GID=$$(id -g) $(DC) build

up: ## Start the stack
	UID=$$(id -u) GID=$$(id -g) $(DC) up -d

down: ## Stop the stack
	$(DC) down

restart: ## Restart php/queue/scheduler
	$(DC) restart app queue scheduler

logs: ## Tail logs
	$(DC) logs -f --tail=100

shell: ## Shell into the app container
	$(EXEC) bash

composer: ## Run composer, e.g. make composer c="require foo/bar"
	$(EXEC) composer $(c)

artisan: ## Run artisan, e.g. make artisan c="migrate --seed"
	$(EXEC) php artisan $(c)

migrate: ## Run migrations
	$(EXEC) php artisan migrate

fresh: ## Drop + re-run migrations
	$(EXEC) php artisan migrate:fresh

test: ## Run the Pest suite
	$(EXEC) ./vendor/bin/pest

pint: ## Format with Pint
	$(EXEC) ./vendor/bin/pint

stan: ## Run PHPStan / Larastan
	$(EXEC) ./vendor/bin/phpstan analyse --memory-limit=512M

check: ## Pint (check) + PHPStan + Pest
	$(EXEC) composer check
