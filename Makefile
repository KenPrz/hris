# The runner surface for the containerized stack. `make help` lists everything.
DEV := docker compose -f compose.dev.yml
PROD := docker compose -f compose.prod.yml

# backup/restore/restore-drill target whichever stack COMPOSE points at
# (COMPOSE=prod switches to the prod stack; default is dev). Both stacks name the
# database, user, and role `hris`, so the same commands work against either.
COMPOSE_VAR := $(if $(filter prod,$(COMPOSE)),$(PROD),$(DEV))
# The compose project name, and therefore the named-volume prefix, of whichever stack
# COMPOSE selects — `make backup` needs it to reach the RustFS volume directly.
STACK := $(if $(filter prod,$(COMPOSE)),hris,hris-dev)

.DEFAULT_GOAL := help
.PHONY: help dev dev-down dev-key test test-backend test-web clean \
        build prod-up prod-down prod-logs backup restore restore-drill

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
	# DB_HOST and DB_PORT are the only two values that legitimately differ between
	# the two topologies, so they stay unforced in phpunit.xml and are overridden
	# here via `exec -e`, which always wins over the container's ambient environment
	# for the exec'd process.
	#
	# Every other testing value (APP_ENV, DB_DATABASE, HRIS_ORGANIZATION_NAME, etc.)
	# now carries force="true" in phpunit.xml, so it wins over whatever the api
	# container's own `environment:` block (compose.dev.yml) exports for the dev
	# server — no need to duplicate those values here as a second source of truth.
	# memory_limit: the api image ships PHP's stock 128M, but Pest's arch suite parses
	# every app/ docblock through phpstan/phpdoc-parser, which now exceeds 128M as the
	# codebase has grown (M5+). Raise it for the test run only. CI is unaffected —
	# shivammathur/setup-php defaults to -1 (unlimited).
	$(DEV) exec -T \
		-e DB_HOST=db \
		-e DB_PORT=5432 \
		--user hris api php -d memory_limit=512M ./vendor/bin/pest

test-web: ## Vitest + typecheck + build
	$(DEV) exec -T --user node web sh -c 'npm test && npm run typecheck && npm run build'

build: ## Build both production images
	docker build --target prod -t hris-api:latest backend
	docker build --target runner -t hris-web:latest frontend/web

prod-up: ## Start the production stack (needs .env — see .env.prod.example)
	$(PROD) up -d --build
	@echo "Serving https://$$(grep -E '^HRIS_DOMAIN=' .env 2>/dev/null | cut -d= -f2)"
	@echo "Empty database? Mint the first login:"
	@echo "  docker compose -f compose.prod.yml exec --user hris api php artisan hris:bootstrap-admin you@example.com"

prod-down: ## Stop the production stack (volumes survive)
	$(PROD) down

prod-logs: ## Tail production logs
	$(PROD) logs -f --tail=100

# pg_dump and the attachments tar both write to the HOST via shell stdout redirection,
# never through a bind mount — so the files land owned by whoever ran `make`, not root.
# (`docker compose exec` defaults to root; see CLAUDE.md.)
backup: ## Dump the db + tar the attachments -> backups/ (COMPOSE=prod for prod)
	@mkdir -p backups
	@set -e; TS=$$(date -u +%Y%m%dT%H%M%SZ); \
	$(COMPOSE_VAR) exec -T db pg_dump -U hris -d hris -Fc > backups/hris-$$TS.dump; \
	docker run --rm -v $(STACK)_rustfs_data:/data:ro alpine \
	  tar czf - -C /data . > backups/hris-attachments-$$TS.tgz; \
	ls -lh backups/hris-$$TS.dump backups/hris-attachments-$$TS.tgz

# --clean --if-exists rather than dropping the database: the api container holds open
# connections, and `drop database` fails while anything is attached.
restore: ## Restore FILE=backups/....dump into the running db (DESTRUCTIVE, asks first)
	@test -n "$(FILE)" || { echo "usage: make restore FILE=backups/hris-....dump"; exit 1; }
	@printf "Overwrite the live 'hris' database with $(FILE)? Type 'restore' to confirm: " \
		&& read a && [ "$$a" = "restore" ]
	$(COMPOSE_VAR) exec -T db pg_restore -U hris -d hris --clean --if-exists --no-owner --no-privileges < $(FILE)
	@echo "restored $(FILE)"

# A backup nobody has restored is a rumor. This restores the newest dump into a
# throwaway container — never the live stack — counts rows, and tears it down.
# The counted tables are the ones any real deployment has: `users` is asserted >= 1
# because a database with no users is not one anybody backed up on purpose, and it is
# the single row even a freshly bootstrapped production database is guaranteed to hold.
# The attachments tar has NO drill — a known gap, see the M9 spec.
restore-drill: ## Prove the newest backup restores: throwaway db, row counts, teardown
	@test -n "$$(ls backups/*.dump 2>/dev/null)" || { echo "no backups yet — run 'make backup'"; exit 1; }
	@set -e; \
	LATEST=$$(ls -t backups/*.dump | head -1); echo "drilling $$LATEST"; \
	docker rm -f hris-drill >/dev/null 2>&1 || true; \
	trap 'docker rm -f hris-drill >/dev/null 2>&1 || true' EXIT; \
	docker run -d --name hris-drill -e POSTGRES_PASSWORD=drill -e POSTGRES_DB=hris \
		postgres:18-alpine >/dev/null; \
	for i in $$(seq 1 60); do \
		docker exec hris-drill pg_isready -U postgres -d hris -q >/dev/null 2>&1 && break; sleep 1; done; \
	docker exec -i hris-drill pg_restore -U postgres -d hris --no-owner --no-privileges < $$LATEST; \
	docker exec -i hris-drill psql -U postgres -d hris -c \
		"SELECT 'users: '||count(*) FROM users \
		 UNION ALL SELECT 'employees: '||count(*) FROM employees \
		 UNION ALL SELECT 'offices: '||count(*) FROM offices \
		 UNION ALL SELECT 'attendance_logs: '||count(*) FROM attendance_logs"; \
	test "$$(docker exec -i hris-drill psql -U postgres -d hris -qtAc 'select count(*) from users')" -ge 1; \
	echo "restore drill PASSED — the backup is not a rumor"

clean: ## Stack down AND volumes destroyed — asks first
	@printf 'Destroy the database volume? [y/N] ' && read a && [ "$$a" = y ]
	$(DEV) down -v
