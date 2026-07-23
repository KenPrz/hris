# API

REST/JSON under `/api/v1`. Laravel 13 + Sanctum. Every endpoint is one route → one
invokable controller → one action → one resource, so `routes/api.php` and `app/Actions/`
stay diffable against each other — an endpoint with no action, or an action with no
endpoint, is a visible bug (`04-backend-conventions.md`).

## Ground rules

- **Success is always `{"data": …}`. Errors are always `{"error": {code, message,
  details}}`.** One shape everywhere, so the client has one success path and one error
  path; no component reads an HTTP status by hand (`01-architecture.md`).
- Money is **integer centavos**: `{"base_rate_cents": 61000}`. Never a string, never a
  float, never pre-formatted. The `_cents` suffix is mandatory on every monetary field.
- Calendar dates are `YYYY-MM-DD` **strings**: `{"effective_from": "2026-07-23"}`. A date
  is a day, not an instant; a browser in another timezone must not be able to disagree
  about which day (`01-architecture.md`).
- Timestamps are ISO-8601 with offset. IDs are UUIDv7 strings.
- **The client never sends derived state.** It sends intent; the server computes and
  returns the record.

## Auth

Sanctum token auth, email and password. One tier — every HRIS user authenticates
identically, and every admin is also an employee, so there is no separate back-office login
the way POS has one (`../pos/docs/03-api.md`). No device, no PIN.

```
POST /api/v1/login                    # unauthenticated; rate-limited
  { "email": "hr.manila@hris.test", "password": "…" }
  → { data: { token, user: { id, email, name } } }
```

`token` is a Sanctum personal access token; every subsequent request carries it as
`Authorization: Bearer <token>`.

**Login is enumeration-safe and timing-normalized.** A wrong email and a wrong password
both answer `401 invalid_credentials` with the same message — the response never reveals
whether an email exists. The controller always runs a bcrypt verify, against a fixed dummy
hash when the email is unknown, so an unknown email and a wrong password take the same wall
time; without that, login latency would leak which emails are real. There is exactly one
failure branch: `$user === null || ! $passwordOk`.

**Login is rate-limited: 5 attempts per minute, keyed on `email + IP`.** The sixth attempt
is `429 too_many_requests`, rendered through the same envelope as everything else. The
keyspace for a password is large, but a rate limit on the credential endpoint is
load-bearing against credential-stuffing regardless.

```
POST /api/v1/logout                   # auth:sanctum
  → 204 No Content
```

Logout is a **real revocation**: it deletes the current access token
(`currentAccessToken()->delete()`), so the presented bearer stops working immediately — not
a client-side forget. A test asserts the token is unusable afterward.

```
GET /api/v1/me                        # auth:sanctum
  → { data: <session envelope, below> }
```

### The `/me` session envelope

The single source of scope truth the frontend reads — navigation, route guards, and a
`<Can>`-style component all consume this, and nothing else calls a permissions endpoint.
Assembled by `App\Actions\Auth\BuildSession`:

```json
{ "data": {
  "user":     { "id": "0199…", "email": "hr.manila@hris.test", "name": "Carmen Lim" },
  "employee": { "id": "0199…", "employee_no": "MNL-0006",
                "current_office_id": "0199…", "current_department_id": "0199…" },
  "is_system_admin": false,
  "has_reports":     false,
  "hr_offices":      ["0199…"],
  "permissions":     ["employee.manage", "employee.pii.edit", "leave.approve",
                      "schedule.manage", "holiday.manage", "cutoff.manage"]
} }
```

- `employee` is **`null`** for a user with no linked employee record (a login that exists
  without an `employees.user_id` pointing back — rare, but the shape allows it).
- `is_system_admin` is cast from the column, not passed through: a freshly-inserted
  Postgres row does not return column defaults into the in-memory model, so the cast
  guarantees `false` rather than `null` for a just-created admin.
- `has_reports` is whether anyone's `current_reports_to_id` points at this user's employee —
  i.e. "is this person a manager," derived from the org chart, never a stored flag.
- `hr_offices` is the office ids from `hr_admin_offices` for this user.
- `permissions` is the flat list of spatie permission names the user holds (empty for a
  plain employee; a system admin holds none *explicitly* — the bypass is `Gate::before`,
  not a grant, so this list can be empty even for someone who passes every check).

`has_reports` and `hr_offices` map 1:1 onto the four navigation segments — `me` / `team` /
`office` / `admin` — which are the four scopes (`05-rbac.md`), so navigation and policy can
be diffed against each other.

## Employees

```
GET /api/v1/employees                 # auth:sanctum — index, scoped
  → { data: [ { id, employee_no, current_office_id, current_department_id,
                current_reports_to_id, hired_at }, … ] }
```

The index is constrained by `EmployeeScope::visibleTo($user)` (`05-rbac.md`) and ordered by
`employee_no`. No employee outside the actor's scope is ever loaded, so there is nothing to
refuse per-row — the list simply contains what the actor may see.

```
GET /api/v1/employees/{employee}      # auth:sanctum — show
  → { data: { id, employee_no, current_office_id, current_department_id,
              current_reports_to_id, hired_at } }
  → 404 not_found   # when the subject is out of the actor's scope
```

**Out-of-scope is `404`, not `403`.** A denied view is indistinguishable from a nonexistent
id — "this exists but isn't yours" leaks the org chart, and for salary and disciplinary
records that leak is the disclosure (`05-rbac.md`).

## Admin — onboarding (System Admin only)

System Admin owns employee creation and login provisioning in M2; there is no self-serve
path. Each `FormRequest::authorize()` is the entire boundary, and a non-admin actor gets
`403 forbidden` — an actor check, not the out-of-scope-subject case above.

```
POST /api/v1/admin/employees          # create employee (+ optional first employment)
  { "employee_no": "MNL-0007", "organization_id": "0199…", "hired_at": "2026-07-23",
    "employment": {                    # OPTIONAL — omit to create a bare identity
      "effective_from": "2026-07-23", "office_id": "0199…", "department_id": "0199…",
      "reports_to_id": "0199…" | null, "employment_type": "regular",
      "is_art82_exempt": false, "base_rate_cents": 61000 } }
  → 201 { data: { id, employee_no, current_office_id, current_department_id,
                  current_reports_to_id, hired_at } }
```

When `employment` is present, `CreateEmployee` records it through `RecordEmploymentChange`
in the same transaction, so the `current_*` cache is populated on day one — the one legal
way it is ever written (`02-data-model.md`). Omit it and the employee exists with a null
cache until the first employment change.

```
POST /api/v1/admin/employees/{employee}/user      # provision a login
  { "name": "Grace Tan", "email": "grace@hris.test", "password": "…" }   # name REQUIRED
  → 201 { data: { id, name, email } }
  → 422 employee_already_has_login   # the employee already has a user_id
```

`name` is required — the login carries its own display name. Provisioning a login for an
employee who already has one is a domain failure, not a silent overwrite:
`422 employee_already_has_login`, with the `employee_id` in `details`.

```
POST /api/v1/admin/employees/{employee}/employment   # record an employment change
  { "effective_from": "2026-08-01", "office_id": "0199…", "department_id": "0199…",
    "reports_to_id": "0199…" | null, "employment_type": "regular",
    "is_art82_exempt": true, "base_rate_cents": 150000 }
  → 201 { data: { id, employee_id, effective_from, office_id, department_id,
                  reports_to_id, employment_type, is_art82_exempt, base_rate_cents } }
```

Inserts one effective-dated `employment_records` row and advances the `current_*` cache
**only if** the new row is the latest effective date — a back-dated correction updates
history but leaves the cache on the genuinely-current record (`02-data-model.md`).

```
  → 422 employment_record_exists   # a change already exists for this employee on that date
```

A second change on the same `effective_from` is a domain failure, not a silent second row:
`422 employment_record_exists`, with `employee_id` and `effective_from` in `details`
(`02-data-model.md`).

## Errors

One envelope (`01-architecture.md`), closed rather than enumerated — every HTTP exception
and, outside debug, every uncaught throwable comes back in this shape:

```json
{ "error": {
  "code": "employee_already_has_login",
  "message": "This employee already has a login.",
  "details": { "employee_id": "0199…" }
} }
```

`code` is stable forever once shipped; clients branch on it. `message` is for humans and
may change freely; `details` is always a JSON object (`{}` when empty), never an array.

| HTTP | `code` | When |
| --- | --- | --- |
| 400 | `validation_failed` | Well-formed but invalid input (`details.fields` carries the per-field errors). 400, not 422 — 422 is reserved for structurally-fine, semantically-rejected requests. |
| 401 | `unauthenticated` | No/invalid bearer token on an authed route. |
| 401 | `invalid_credentials` | Wrong email **or** wrong password on login — deliberately indistinguishable. |
| 403 | `forbidden` | An unauthorized **actor** (a non-admin hitting an admin route). |
| 404 | `not_found` | A missing resource **or** an out-of-scope **subject** (the 404-not-403 rule). |
| 405 | `method_not_allowed` | Wrong HTTP verb on a real route. |
| 422 | `employee_already_has_login` | Provisioning a second login for an employee. |
| 422 | `employment_record_exists` | Recording a second employment change for the same employee on the same `effective_from`. |
| 429 | `too_many_requests` | Login rate limit (5/min per email+IP) exceeded. |
| 500 | `internal_error` | An uncaught bug (outside debug; in debug Laravel's own page surfaces). |

The `403`-vs-`404` split is load-bearing: an actor refusal is `forbidden` (you may not do
this kind of thing), an out-of-scope subject is `not_found` (this may as well not exist, for
you). See `05-rbac.md`.

## What is not here yet

Attendance, schedules, holidays, leave, cutoffs, and payroll export are their own
milestones (`06-roadmap.md`); their endpoints land with them. The device ingestion contract
for biometric hardware is specified from M3 in this document when the punch endpoint lands,
so the deferred hardware integration needs a driver, not a redesign.
