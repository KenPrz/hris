# HRIS

A Human Resource Information System for a single Philippine company operating across
several offices.

## Running it

```bash
cp -n .env.example .env        # first time only
make dev-key                   # first time only — paste the value into .env
make dev                       # db + api + web, hot reload
```

<http://127.0.0.1:5176> should say **System healthy** and print the Postgres version.
<http://127.0.0.1:8001/api/v1/health> is the API behind it. `make help` lists every
target; `make test` runs both suites in the containers.
