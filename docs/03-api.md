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

## Attendance *(M3)*

The append-only punch ledger (`02-data-model.md`): every punch is a new row, nothing is ever
edited or deleted, and a correction is a new (manual) row. There is deliberately **no**
`PATCH`/`PUT`/`DELETE` under `attendance` — `AppendOnlyTest` asserts it.

### Self-service punch

```
POST /api/v1/attendance/punch         # auth:sanctum; Idempotency-Key REQUIRED
  Idempotency-Key: <client-generated string>
  { "direction": "in" | "out" }
  → 201 { data: { id, employee_id, office_id, punched_at, direction, source,
                  verification, flag_reason } }
```

A signed-in employee clocks in or out. **The server sets `punched_at`** — always its own
clock, never a client-supplied time, so no one can backdate their own punch — and stamps
`source: web` and `recorded_by` = the caller's user id. The office it belongs to is snapshot
from the employee's `current_office_id` at this instant (`02-data-model.md`).

**The `Idempotency-Key` header is required**, folded into validation so a missing key is the
ordinary `400 validation_failed`, not a silent bypass. A retry with the same key replays the
stored response and writes **no second row**; the key and the row commit in one transaction.
A user with no linked employee record cannot punch — `422 not_an_employee`.

**Verification flags, it never rejects.** The punch IP is checked against the snapshot
office's `ip_allowlist`; pass → `verification: verified`, fail → `verification: flagged`,
`flag_reason: ip_not_allowlisted`. The geofence check is wired identically but fires only
when `geo_lat`/`geo_lng` are present. **Either way the punch lands `201`** — the Labor Code
cares that time was worked, not which network recorded it; a flag is metadata for HR to
review, not a gate at the door. Promoting a flag to a hard block per office is a later change
the `ip_allowlist` column already accommodates.

### Manual HR entry — HR only, never self

```
POST /api/v1/admin/attendance/punch   # auth:sanctum + HR/admin actor; NOT idempotent
  { "employee_id": "0199…", "direction": "in" | "out",
    "punched_at": "2026-03-01T17:30:00+08:00" }   # supplied time REQUIRED
  → 201 { data: { …, source: "manual", … } }
```

HR (or a system admin) records a punch **on an employee's behalf** — the path for
login-less punch-only workers and for backfilling a gap when a device was down. It is the
one path that accepts an explicit `punched_at`, because that is its whole purpose; the row
carries `source: manual` and `recorded_by` = the HR user.

This is **strictly an HR/admin tool, and never for your own record** — separation of duties,
you do not enter your own time. Three boundaries, each a distinct status:

```
  → 403 forbidden          # a plain employee or manager: they may not manually punch at all
  → 422 cannot_punch_self  # an HR/admin targeting their OWN employee record
  → 404 not_found          # target employee is outside the actor's scope (an HR admin can
                           #   only backfill within the offices they administer; a system
                           #   admin can target anyone) — the 404-not-403 subject rule
```

`403` is an *actor* refusal (a non-HR caller — leaks no specific employee), while `404` is
an out-of-scope *subject* (indistinguishable from nonexistent, per the M2 rule in
`05-rbac.md`). The endpoint is not behind `idempotent`: an HR correction is a considered
one-off, not a retryable network event.

> **Note — self-corrections don't go through this endpoint.** An employee fixing their
> *own* missed punch never calls the manual route above — that flows through the
> attendance **adjustment request** below (a note, an optional attachment, approved by the
> employee's `reports_to` or HR).

### Reading a month of punches

```
GET /api/v1/me/attendance?month=YYYY-MM               # auth:sanctum — own punches
GET /api/v1/employees/{employee}/attendance?month=    # auth:sanctum + EmployeeScope
  → { data: { "2026-03-02": [ { id, employee_id, office_id, punched_at, direction,
                                source, verification, flag_reason }, … ], … } }
```

Returns the raw `attendance_logs` rows **grouped by office-local calendar date** — each
punch's `punched_at` (UTC) converted to *its snapshot office's* timezone, then bucketed by
that local date; keys are `YYYY-MM-DD` strings, punches within a day ordered by time.
`month` defaults to the current month if omitted.

This is the **raw** ledger: because `direction` is explicit, the view labels "in / out" with
**no pairing and no business-day logic** — a night shift's out-punch at 06:00 appears on its
own local calendar date, honestly, and turning punches into paid hours is M5's job. Flagged
punches appear here exactly as recorded. The employee-scoped variant reuses `EmployeeScope`
and the 404-not-403 rule unchanged — an HR admin sees their office's punches, a manager
their reports', and an out-of-scope subject `404`s.

**These two reads stay raw even after M3.6.** An approved `void`/`amend` never changes what
either endpoint returns — the annulled row is still a real, once-true punch, and this is the
ledger you'd show an inspector. The **effective ledger** (`02-data-model.md`) that excludes
an annulled punch is a query, not an endpoint, until M5's compute engine is the thing that
needs to read it.

## Attendance adjustments *(M3.6)*

An employee's own correction, on the shared `requests` spine (`02-data-model.md`):
`pending → approved | rejected | cancelled`, one detail table per request type (only
`attendance_adjustment` exists today), a manager or HR approves — never the requester
themself, however broad their own scope otherwise reaches.

### Submit

```
POST /api/v1/attendance/adjustments   # auth:sanctum — any employee, for their own record
  multipart/form-data:
    operation:       "add" | "void" | "amend"
    note:             string, REQUIRED
    target_log_id:    uuid   — REQUIRED for void/amend (the punch being corrected)
    direction:        "in" | "out" — REQUIRED for add/amend
    punched_at:       ISO-8601, offset-bearing — REQUIRED for add/amend
    attachment:       file, OPTIONAL — pdf/jpg/jpeg/png, ≤10MB
  → 201 { data: <request, below> }
  → 400 validation_failed         # missing note/operation, or a required_if field missing
  → 422 not_an_employee           # the caller has no linked employee record
```

Deliberately **not** admin-gated (any signed-in employee may file for their own attendance)
and **not** behind the `idempotent` middleware — a considered one-off submission, not a
retryable network event, unlike self-service punch. Multipart because of the optional file;
a submission with no `attachment` field is a plain JSON-shaped form post. `punched_at` is
normalized to a true UTC instant before the write (the same `->utc()` fix M3's `RecordPunch`
needed) — submitting `2026-07-01T08:00:00+08:00` stores the `00:00Z` instant it means, not a
silently-corrupted local-time-as-UTC read. Whether the `void`/`amend` target is actually
valid (belongs to the requester, still exists, isn't already annulled) is **not** checked at
submission — only at approval, under the request's row lock (`02-data-model.md`).

The request/detail shape returned by submit, and by every read below:

```json
{ "data": {
  "id": "0199…", "type": "attendance_adjustment", "state": "pending",
  "note": "Forgot to clock in", "employee_id": "0199…",
  "detail": { "operation": "add", "target_log_id": null, "direction": "in",
              "punched_at": "2026-07-20T08:00:00+00:00" },
  "decided_by": null, "decided_at": null, "decision_note": null,
  "has_attachment": true
} }
```

`has_attachment` is a boolean, never the file itself or a URL to it — the file is only ever
reachable through the scoped download endpoint below.

### Approve / reject / cancel

```
POST /api/v1/attendance/adjustments/{request}/approve   # auth:sanctum
  → 200 { data: <request, state: "approved", decided_by, decided_at> }
  → 404 not_found              # out of the approver's scope, OR the approver IS the requester
  → 409 request_not_pending    # already approved/rejected/cancelled
  → 422 invalid_adjustment_target   # a void/amend target is missing, not the requester's,
                                     #   or already annulled by an earlier approval
```

```
POST /api/v1/attendance/adjustments/{request}/reject
  { "decision_note": string, REQUIRED }
  → 200 { data: <request, state: "rejected", decision_note> }
  → 404 not_found              # same authority rule as approve
  → 409 request_not_pending
  → 400 validation_failed      # decision_note missing or empty
```

```
POST /api/v1/attendance/adjustments/{request}/cancel     # requester only
  → 200 { data: <request, state: "cancelled"> }
  → 404 not_found              # the caller is not the requester (narrower than approve/reject)
  → 409 request_not_pending
```

**Authority is "in-scope-minus-self": `RequestAuthority::canDecide`** — the requester must
be visible to the approver under `EmployeeScope::visibleTo()` (self, direct reports, HR's
office, or system-admin-all) **and** the approver must not be the requester. A manager
approves their report's request, an HR admin their office's, a system admin anyone's; the
requester approving their own — however broad their scope otherwise is — is refused exactly
like an out-of-scope stranger, `404`, never a different status that would confirm "this is
yours, you just can't approve it." **Cancel has its own, narrower rule**: only the requester
may cancel, so a manager or HR admin who could approve the request may **not** cancel it on
the requester's behalf.

**Order of checks, and why it's fixed:** authority (`404`) → pending (`409`) → the effect,
which can itself fail (`422 invalid_adjustment_target`, rolling back the whole approval —
the request stays pending). Reject's `decision_note` requiredness is checked **last**,
*inside* the action, deliberately after authority and pending — validating it at the HTTP
layer would let an out-of-scope prober distinguish "exists but hidden" (`400` on an empty
body) from "doesn't exist" (`404`), the exact existence leak the 404-not-403 rule exists to
close. So an out-of-scope reject with an empty body is still `404`, never `400`.

**Approval is transactional with its effect.** `add` → `RecordPunch` (`source: adjustment`,
`recorded_by` the approver); `void` → `RecordAnnulment`; `amend` → both. All three, plus the
request's own state write, happen inside one `SELECT ... FOR UPDATE`-locked transaction — if
the effect throws, nothing commits, including the state transition.

### List mine, the approval queue, show, and the attachment

```
GET /api/v1/attendance/adjustments            # auth:sanctum — the caller's own, any state
  → { data: [ <request>, … ] }
  → 422 not_an_employee

GET /api/v1/attendance/adjustments/pending    # auth:sanctum — the approval queue
  → { data: [ <request>, … ] }   # in-scope-minus-self, state=pending only
```

The pending queue is exactly the set `RequestAuthority::canDecide` would accept one request
at a time: every pending request whose requester is visible to the caller under
`EmployeeScope`, excluding the caller's own. (Registered *before* the `{request}` show route
below — otherwise `/pending` would bind as a `{request}` uuid lookup and 404 via route-model
binding instead of ever reaching this controller.)

```
GET /api/v1/attendance/adjustments/{request}            # auth:sanctum
  → 200 { data: <request> }
  → 404 not_found   # neither the requester nor an authorized approver

GET /api/v1/attendance/adjustments/{request}/attachment # auth:sanctum
  → 200 <file stream>
  → 404 not_found   # unauthorized viewer, OR the request has no attachment at all
```

Both share one visibility rule: the requester themself, or anyone for whom
`RequestAuthority::canDecide` is true — the same in-scope-minus-self test approval uses, so
an approver can review the whole request (including the attachment) before deciding, and
everyone else gets the identical `404` whether the request doesn't exist, belongs to a
stranger, or exists but has no file. The attachment is a **private, app-mediated stream**
(`Media::toResponse()`), never a public or presigned RustFS URL — RustFS itself is only
reachable from inside the container network (`02-data-model.md`).

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
| 409 | `idempotency_key_reused` | An `Idempotency-Key` replayed with a *different* body, or by a *different* user than minted it (the hash folds in the acting user). |
| 409 | `request_not_pending` | Approving, rejecting, or cancelling a request that is already `approved`/`rejected`/`cancelled`. |
| 422 | `employee_already_has_login` | Provisioning a second login for an employee. |
| 422 | `employment_record_exists` | Recording a second employment change for the same employee on the same `effective_from`. |
| 422 | `not_an_employee` | A logged-in user with no linked employee record trying to self-punch, read their own attendance, or submit/list their own adjustments. |
| 422 | `cannot_punch_self` | An HR/admin using the manual entry endpoint to record their *own* punch (separation of duties). |
| 422 | `invalid_adjustment_target` | Approving a `void`/`amend` whose target punch is missing, belongs to someone else, or was already annulled by an earlier approval. |
| 429 | `too_many_requests` | Login rate limit (5/min per email+IP) exceeded. |
| 500 | `internal_error` | An uncaught bug (outside debug; in debug Laravel's own page surfaces). |

The `403`-vs-`404` split is load-bearing: an actor refusal is `forbidden` (you may not do
this kind of thing), an out-of-scope subject is `not_found` (this may as well not exist, for
you). See `05-rbac.md`.

## What is not here yet

Schedules, holidays, leave, cutoffs, and payroll export are their own milestones
(`06-roadmap.md`); their endpoints land with them. Leave and overtime requests reuse the
`requests` spine M3.6 built above, but their own detail tables and endpoints are not built
yet.

The **device ingestion contract** for biometric hardware is exposed, not built: the punch
payload already accepts `source`, `device_id`, `geo_lat`/`geo_lng`, and an idempotency key —
the shape a device's middleware would POST — but device *authentication* (a device registry
+ per-device tokens) and *batch* ingestion defer with the hardware that needs them. Both live
punch paths are Sanctum-authed today; adding a device later is a new auth guard and a
`source: device` path into the same `RecordPunch`, no schema change and no new writer.
