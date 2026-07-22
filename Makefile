# The runner surface for the containerized stack. `make help` lists everything.
DEV := docker compose -f compose.dev.yml

.DEFAULT_GOAL := help
.PHONY: help dev dev-down dev-key test test-backend test-web clean

help: ## List every target
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) \
		| awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

dev: ## Bring the dev stack up (db + api + web, hot reload)
	$(DEV) up -d --wait db
	$(DEV) up -d api web
	@echo "API  http://127.0.0.1:$${HRIS_DEV_API_PORT:-8001}/api/v1/health"
	@echo "Web  http://127.0.0.1:$${HRIS_DEV_WEB_PORT:-5176}"
	@echo "First boot installs vendor/ and node_modules into fresh volumes — give it a few minutes."

dev-down: ## Stop the dev stack (volumes survive)
	$(DEV) down

dev-key: ## Mint an APP_KEY; paste the value into .env as HRIS_DEV_APP_KEY
	@echo "base64:$$(head -c 32 /dev/urandom | base64)"

test: test-backend test-web ## Run both suites in containers

test-backend: ## Pest, against the compose Postgres
	@$(DEV) exec -T db psql -U hris -d hris -qc "create database hris_test owner hris" 2>/dev/null || true
	# backend/phpunit.xml hardcodes DB_HOST=127.0.0.1 / DB_PORT=5433 for the native
	# path (./vendor/bin/pest run straight from backend/, no Docker involved). Inside
	# the api container, 127.0.0.1 is the api container itself; Postgres is at `db`.
	#
	# PHPUnit's <env> directives only apply when `force="true"` OR the variable is
	# still unset — none of ours set force, so any value already present in the
	# process environment silently wins over phpunit.xml. The api container's own
	# `environment:` block (compose.dev.yml) already exports DB_HOST=db and
	# DB_PORT=5432, so those two happen to fall through correctly on their own.
	# DB_DATABASE and APP_ENV do NOT fall through correctly, though: the api
	# container's ambient env carries the dev-server's DB_DATABASE=hris and
	# APP_ENV=local (so hot-reloading `artisan` picks them up), which — by that same
	# force=false rule — would silently beat phpunit.xml's testing-specific
	# DB_DATABASE=hris_test and APP_ENV=testing. Left alone, the suite would run
	# against the *dev* database in dev mode, not the test database in testing mode
	# (tests/Feature/System/ConfigurationTest.php catches this: it asserts
	# HRIS_ORGANIZATION_NAME is the testing value).
	#
	# Fix: pass the testing values explicitly via `exec -e`, which always wins over
	# the container's ambient environment for the exec'd process. phpunit.xml is left
	# untouched, so the native path (no ambient env at all, getenv() all false) keeps
	# using its own literals unchanged.
	$(DEV) exec -T \
		-e APP_ENV=testing \
		-e DB_HOST=db \
		-e DB_PORT=5432 \
		-e DB_DATABASE=hris_test \
		-e HRIS_ORGANIZATION_NAME="Test Company Inc." \
		--user hris api ./vendor/bin/pest

test-web: ## Vitest + typecheck + build
	$(DEV) exec -T --user node web sh -c 'npm test && npm run typecheck && npm run build'

clean: ## Stack down AND volumes destroyed — asks first
	@printf 'Destroy the database volume? [y/N] ' && read a && [ "$$a" = y ]
	$(DEV) down -v
