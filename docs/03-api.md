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

## Admin — the employee profiler (System Admin only) *(M8b)*

System Admin owns employee onboarding, the company-wide roster, name edits, and login
provisioning; there is no self-serve path. Each `FormRequest::authorize()` is the entire
boundary, and a non-admin actor gets `403 forbidden` — an actor check, not the
out-of-scope-subject case above. (The one exception is `POST …/{employee}/user`, which
`404`s a non-admin instead of `403` — see its note; its subject id is in the URL, so a
status split would leak which employee ids exist.)

Every response carries the employee's **name** as of M8b: the four columns
(`first_name`, `middle_name`, `last_name`, `name_suffix`) plus the composed read model
`full_name` (`02-data-model.md`). `employee_no` is the immutable identity.

```
GET /api/v1/admin/employees[?office=<uuid>]     # the company-wide roster
  → { data: [ { id, employee_no, first_name, middle_name, last_name, name_suffix,
                full_name, current_office_id, current_department_id, has_user }, … ] }
```

The profiler roster is global (every employee, any office), distinct from the scope-filtered
`GET /employees` above; `?office=<uuid>` narrows it to one office by the `current_office_id`
cache. `has_user` is `user_id !== null` — whether the person has a login yet.

```
GET /api/v1/admin/employees/{employee}          # one employee's profile
  → { data: { id, employee_no, first_name, middle_name, last_name, name_suffix, full_name,
              hired_at, has_user,
              current_employment: {           # null until the first employment record
                office_id, department_id, employment_type, designation, labor_type,
                is_art82_exempt, base_rate_cents, reports_to_id, effective_from } } }
```

`designation`/`labor_type` (M10a) join this block the same way they join the employment-change
response above — read from the same `EmploymentResolver`-resolved record, never the
denormalized cache. This endpoint predates and is distinct from M10a's own personnel-file
read (`GET /admin/employees/{employee}/profile`, under "Employee profiling," below) — this
one is the M8b onboarding/roster surface (identity plus current employment); that one is the
full contact/personal/dependents/identifications file.

`current_employment` is resolved through `EmploymentResolver` — the effective-dated record
whose `effective_from` is the latest on or before today (`02-data-model.md`), the same way
the pay engine reads it — never the denormalized cache. A brand-new employee with no
employment record yet gets `null`, not an error. "Today" here is the employee's current
office-local date (M10a follow-ups) — this endpoint carried the same UTC-`today()` bug the
M10a profile resources had, fixed the same way and at the same time; see `06-roadmap.md`'s
M10a section.

```
POST /api/v1/admin/employees          # onboard an employee (+ optional first employment)
  { "employee_no": "MNL-0007", "organization_id": "0199…", "hired_at": "2026-07-23",
    "first_name": "Juan", "middle_name": "Santos" | null,   # middle/suffix OPTIONAL
    "last_name": "Cruz", "name_suffix": "Jr." | null,
    "employment": {                    # OPTIONAL — omit to create a bare identity
      "effective_from": "2026-07-23", "office_id": "0199…", "department_id": "0199…",
      "reports_to_id": "0199…" | null, "employment_type": "regular",
      "is_art82_exempt": false, "base_rate_cents": 61000 } }
  → 201 { data: { id, employee_no, first_name, middle_name, last_name, name_suffix,
                  full_name, current_office_id, current_department_id,
                  current_reports_to_id, hired_at } }
```

`first_name` and `last_name` are required; `middle_name` and `name_suffix` are optional
(a null collapses cleanly in `full_name`). When `employment` is present, `CreateEmployee`
records it through `RecordEmploymentChange` in the same transaction, so the `current_*`
cache is populated on day one — the one legal way it is ever written (`02-data-model.md`).
Omit it and the employee exists with a null cache until the first employment change.

```
PATCH /api/v1/admin/employees/{employee}        # edit the name (identity fields only)
  { "first_name": "Juan", "middle_name": "Santos" | null,
    "last_name": "Delacruz", "name_suffix": "Jr." | null }
  → 200 { data: { id, employee_no, first_name, middle_name, last_name, name_suffix,
                  full_name, current_office_id, current_department_id,
                  current_reports_to_id, hired_at } }
```

PATCH edits only the name. There is **no `employee_no` field** on this surface —
`employee_no` is immutable, set once at creation and never editable; a correction to who a
person *works as* is an employment change, not a rename. The edit is stamped to
`activity_log` (`log_name 'employee'`, `02-data-model.md`).

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
    "is_art82_exempt": true, "base_rate_cents": 150000,
    "designation": "HR Supervisor" | null, "labor_type": "indirect" | null }   # both OPTIONAL — added M10a
  → 201 { data: { id, employee_id, effective_from, office_id, department_id,
                  reports_to_id, employment_type, is_art82_exempt, base_rate_cents,
                  designation, labor_type } }
```

Inserts one effective-dated `employment_records` row and advances the `current_*` cache
**only if** the new row is the latest effective date — a back-dated correction updates
history but leaves the cache on the genuinely-current record (`02-data-model.md`).
`designation`/`labor_type` (M10a) ride on this same endpoint rather than living on the
profile, because a promotion or a labour-type change is effective-dated exactly like every
other field here (`02-data-model.md`'s "Employee profiling" section has the full reasoning).
`labor_type`, when present, must be one of `App\Domain\Profile\LaborType`'s values
(`direct`/`indirect`).

```
  → 422 employment_record_exists   # a change already exists for this employee on that date
```

A second change on the same `effective_from` is a domain failure, not a silent second row:
`422 employment_record_exists`, with `employee_id` and `effective_from` in `details`
(`02-data-model.md`).

### HR-Admin access — grant / revoke office-admin *(M8c)*

```
POST /api/v1/admin/employees/{employee}/hr-offices    # set the offices this login administers
  { "office_ids": [ "<uuid>", … ] }                   # PRESENT (may be []); [] revokes entirely
  → 200 { data: { …EmployeeDetailResource…, hr_admin_office_ids: [ … ], roles: [ … ] } }
  → 400 validation_failed          # office_ids absent, or a member not a uuid
  → 403 forbidden                  # a non-admin actor
  → 422 employee_has_no_login      # employee has no user_id; details: { employee_id }
  → 422 invalid_reference          # an office_id that does not exist; details: { reference_type, reference_id }
```

HR-Admin access is **two coupled halves set in one write** (`SetHrAdminOffices`): the
`hr_admin_offices` pivot (the *offices* this login administers) and the Spatie `HR Admin`
**role** (the *verbs* it may perform). They are never set one without the other — a login
with offices but no role, or a role but no offices, is a bug this action exists to prevent
(`05-rbac.md`). `office_ids: []` **revokes HR-Admin entirely** — the pivot is cleared *and*
the role removed, never left dangling with an empty scope; `office_ids` is validated
`present` (not `required`) precisely so `[]` is a legitimate, meaningful payload rather than
a missing field.

The subject is a **login, not an employee record** — a login-less employee (no `user_id`)
is `422 employee_has_no_login`, because there is no `User` row to attach the pivot/role to.
`office_ids` is shape-only (`uuid`), so a well-formed-but-nonexistent office id is the
controller's own `422 invalid_reference` (the same M8a convention as the org tree below),
never a `404`. The response is the full `EmployeeDetailResource`, now carrying
`hr_admin_office_ids` and `roles` so a client sees the new state without a re-fetch; the
write is stamped to `activity_log` (`log_name 'default'`, `description hr_admin_offices_set`,
`properties.office_ids`), which the audit viewer below surfaces. `is_system_admin`-gated
like the rest of the `/admin` surface — a non-admin gets `403`, not `404` (nothing
office-scoped to hide). `scripts/e2e-admin-roles-audit.sh` proves the whole grant/revoke
cycle plus the two `422` guards against the live stack.

## Employee profiling (M10a)

The personnel file behind an employee record — contact and personal details, dependents,
and government/financial identification numbers with a scanned copy of each. HR Admins
configure it, an employee reads their own, and a manager sees a redacted view of a direct
report's. Nine routes, four `Action` classes — the scan stream and the catalog read are
controller-only reads with no action, the same shape `DownloadAttachmentController` (below,
under "Attendance adjustments and the requests spine") already uses for request attachments.

| Route | Actor | Notes |
| --- | --- | --- |
| `GET /me/profile` | self | full resource |
| `GET /profile/catalog` | any authenticated user | ungated reference data |
| `GET /admin/employees/{employee}/profile` | HR Admin, scoped | full resource |
| `GET /employees/{employee}/profile` | manager of `{employee}` | **redacted** resource |
| `PUT /admin/employees/{employee}/profile` | HR Admin, scoped | upsert |
| `PUT /admin/employees/{employee}/dependents` | HR Admin, scoped | replace-all |
| `POST /admin/employees/{employee}/identifications` | HR Admin, scoped | upsert, multipart |
| `DELETE /admin/employees/{employee}/identifications/{identification}` | HR Admin, scoped | — |
| `GET /employees/{employee}/identifications/{identification}/scan` | self or HR Admin, scoped | private stream |

**Writes are HR Admin only.** There is no self-service edit anywhere on this surface — an
employee cannot change even their own address. `05-rbac.md` has the full authorization
argument (`viewFullProfile`/`viewRedactedProfile`/`updateProfile`, and why an HR Admin
cannot edit their own PII either); this section covers the wire shapes.

### Reading a profile

```
GET /api/v1/me/profile                          # auth:sanctum — self
GET /api/v1/admin/employees/{employee}/profile   # auth:sanctum — HR Admin/System Admin, scoped
  → 200 { data: <full profile, below> }
  → 404 not_found   # /me/profile: caller has no linked employee record
                    # admin route: {employee}'s office isn't administered by the caller,
                    #   or {employee} doesn't exist — 404-not-403, as everywhere else
```

The full profile:

```json
{ "data": {
  "employee_id": "0199…", "employee_no": "MNL-0006", "full_name": "Carmen Lim",
  "details": { "salutation": "Ms.", "first_name": "Carmen", "middle_name": "Reyes",
               "last_name": "Lim", "name_suffix": null, "nickname": "Carms" },
  "contact": { "home_address": "123 Rizal St., Cebu City", "personal_email": "carmen@example.com",
               "phone": null, "fax": null, "mobile": "+639171234567",
               "emergency_contact": "Juan Lim (spouse) 09179876543" },
  "personal": { "gender": "female", "birth_date": "1990-04-12", "age": 36,
                "birthplace": "Cebu City", "marital_status": "married",
                "citizenship": "Filipino", "religion": "Roman Catholic", "blood_type": "O+" },
  "assignment": { "designation": "HR Supervisor", "business_unit": "People Ops",
                  "reports_to": "Grace Tan", "employment_status": "regular",
                  "location": "Cebu Branch", "region": "VII", "labor_type": "indirect",
                  "hired_at": "2021-03-01", "work_shift": "8:00 Am To 5:00 Pm - Rest Sat & Sun" },
  "dependents": [ { "id": "0199…", "name": "Juan Lim Jr.", "relationship": "child",
                    "relationship_label": "Child", "birth_date": "2015-06-01" } ],
  "identifications": [ { "id": "0199…", "category_code": "TIN", "category_name": "TIN",
                          "number": "123-456-789-000", "issued_on": "2015-01-10",
                          "expires_on": null, "notes": null, "has_scan": true } ]
} }
```

`age` is derived, never stored (`02-data-model.md`) — computed against the employee's
current office timezone. **`assignment` resolves "today" the same way (M10a follow-ups):**
both now go through `Carbon::now($employee->currentOffice?->timezone ?? 'Asia/Manila')`, so
they no longer disagree during the 00:00–08:00 Asia/Manila window a UTC-anchored `today()`
used to miss — see `06-roadmap.md`'s M10a section for the fix and the correction to its
original "deferred, needs its own piece of work" framing. `has_scan` is a
boolean, never a URL — the scan is only ever reachable through the stream route below.
`dependents[].relationship` and `identifications[].category_code` are the catalog `code`
(`"child"`, `"TIN"`), not the human-readable `description`/`name` — `GET /profile/catalog`
(below) is how a client turns one into the other. `dependents[].relationship_label` (M10a
Task 16) carries that description directly (`"Child"`), mirroring
`identifications[].category_name`, so the read view can display it without a second round
trip; `relationship` itself is unchanged and stays the code a write must match on.
`personal.gender`/`marital_status`/`blood_type` are likewise backed enum values
(`"male"`, `"single"`), not display text — unlike dependents/identifications, these three
have no per-company catalog row to hang a `_label` off of; they're a fixed, code-defined
set (`app/Domain/Profile/{Gender,MaritalStatus,BloodType}.php`), so the frontend maps them
to labels itself (`src/lib/profileOptions.ts`) instead of the API carrying a redundant
labelled field for each.

```
GET /api/v1/employees/{employee}/profile   # auth:sanctum — manager of {employee}, scoped
  → 200 { data: <redacted profile, below> }
  → 404 not_found   # {employee} is outside EmployeeScope, or doesn't exist
```

The redacted profile — deliberately a **different JSON shape**, not the full one with
fields omitted:

```json
{ "data": {
  "employee_id": "0199…", "employee_no": "MNL-0006", "full_name": "Carmen Lim",
  "contact": { "personal_email": "carmen@example.com", "phone": null, "mobile": "+639171234567" },
  "assignment": { "designation": "HR Supervisor", "business_unit": "People Ops",
                  "reports_to": "Grace Tan", "employment_status": "regular",
                  "location": "Cebu Branch", "region": "VII", "labor_type": "indirect",
                  "hired_at": "2021-03-01", "work_shift": "8:00 Am To 5:00 Pm - Rest Sat & Sun" }
} }
```

No `details`, no `personal`, no `dependents`, no `identifications`, no `home_address` — none
of those keys exist in this response at all, not even as `null`. `EmployeeProfileResource`
and `EmployeeProfileSummaryResource` are two separate resource classes for exactly this
reason: a field added to the full one can never leak into the redacted one by default
(`02-data-model.md`, `05-rbac.md`).

### The catalog

```
GET /api/v1/profile/catalog   # auth:sanctum — any authenticated user, ungated
  → 200 { data: { relationships: [ { id, code, description }, … ],
                  identification_categories: [ { id, code, name, description }, … ] } }
```

Static, company-wide reference data — the five relationship kinds and eight identification
categories `ProfileCatalogSeeder` seeds (`02-data-model.md`). Not office-scoped and not
admin-gated: the dependents and identification writes below take a `relationship_id`/
`category_id`, and without this route nothing tells a client what those ids mean — every
profile screen needs it, including a plain employee's own read-only `/me/profile` view.

### Writing the profile, dependents, and identifications

```
PUT /api/v1/admin/employees/{employee}/profile   # auth:sanctum — HR Admin/System Admin, scoped
  { "salutation": "Ms.", "nickname": "Carms", "home_address": "123 Rizal St., Cebu City",
    "personal_email": "carmen@example.com", "phone": null, "fax": null,
    "mobile": "+639171234567", "emergency_contact": "Juan Lim (spouse) 09179876543",
    "gender": "female", "birth_date": "1990-04-12", "birthplace": "Cebu City",
    "marital_status": "married", "citizenship": "Filipino", "religion": "Roman Catholic",
    "blood_type": "O+" }                          # every field OPTIONAL/nullable
  → 200 { data: <full profile, above> }
  → 400 validation_failed   # bad shape — an enum field outside its closed set
                            #   (gender/marital_status/blood_type), a bad date, etc.
  → 404 not_found           # updateProfile denied — out-of-scope HR Admin, OR the caller
                            #   IS {employee} themselves (self-edit is refused for
                            #   everyone, HR Admins included — see 05-rbac.md)
```

**A `PUT` replaces the whole profile — an omitted field becomes `null`, not "leave it
alone."** `UpsertEmployeeProfile` is a true upsert: the 1:1 row doesn't exist until someone
first fills the personnel file in (`CreateEmployee` never pre-creates an empty one), and a
second `PUT` on an existing profile is `updateOrCreate` against every column at once, so
"clear this employee's fax number" needs no endpoint of its own — omit `fax` from the
payload and it goes to `null`.

```
PUT /api/v1/admin/employees/{employee}/dependents   # auth:sanctum — HR Admin/System Admin, scoped
  { "dependents": [
      { "name": "Juan Lim Jr.", "relationship_id": "0199…", "birth_date": "2015-06-01" },
      { "name": "Ana Lim", "relationship_id": "0199…", "birth_date": null } ] }
                             # PRESENT, may be [] — up to 20 entries
  → 200 { data: <full profile, above> }
  → 400 validation_failed   # dependents missing/not an array, more than 20, a bad
                            #   relationship_id (not a uuid, or not in the catalog), or a
                            #   bad birth_date
  → 404 not_found           # same updateProfile rule as the profile write above
```

**`dependents` is replace-all, not per-row create/update/delete.** The whole list is sent
every time: `ReplaceEmployeeDependents` deletes every existing dependent for the employee —
row-by-row, not a bulk query-builder delete, deliberately, so `LogsActivity` still records
each removal (a bulk `delete()` fires no model events) — and creates the submitted list
fresh, inside one transaction. A zero-to-twenty-row list nothing else references doesn't
earn three routes (`POST`/`PATCH`/`DELETE`) and the client-side id bookkeeping they'd force;
`dependents: []` is a legitimate "this employee has no dependents" instruction (`present`,
not `required`, in the validation rule — `required` would reject `[]`).

```
POST /api/v1/admin/employees/{employee}/identifications   # auth:sanctum — HR Admin/System Admin, scoped
  multipart/form-data:
    category_id:  uuid, REQUIRED — one of GET /profile/catalog's identification_categories
    number:       string, REQUIRED
    issued_on:    YYYY-MM-DD, OPTIONAL
    expires_on:   YYYY-MM-DD, OPTIONAL — must be on/after issued_on
    notes:        string, OPTIONAL
    scan:         file, OPTIONAL — pdf/jpg/jpeg/png, ≤10MB
  → 200 { data: <full profile, above> }
  → 400 validation_failed   # bad shape — category_id not a real category, expires_on
                            #   before issued_on, scan wrong type/too large, etc.
  → 404 not_found           # same updateProfile rule as the profile write above
```

**It is a `POST`, not a `PUT`, even though it is an upsert on `(employee_id,
category_id)`.** `unique(employee_id, category_id)` (`02-data-model.md`) means one employee
has one TIN, one SSS number, and so on — a second submission for the same category updates
the existing row rather than creating a second one, ordinarily a `PUT`'s job. The route
stays `POST` because **PHP parses a multipart body only on `POST`** — a `PUT
multipart/form-data` arrives with an empty `$_FILES` and the uploaded scan vanishes with no
error, which is exactly why Laravel ships `_method` spoofing in the first place. `PUT
/profile` and `PUT /dependents` above stay `PUT` because they carry plain JSON and no file.
**Omitting `scan` leaves any existing scan alone** — it does not clear it; clearing one
means deleting the identification (below), because "I only came to fix a typo in the
number" must never silently destroy the evidence HR is expected to be able to produce.
Sending a new `scan` replaces the old one outright (`singleFile()` on the media collection).

```
DELETE /api/v1/admin/employees/{employee}/identifications/{identification}
  → 200 { data: <full profile, above> }
  → 404 not_found   # updateProfile denied for {employee}, OR {identification} does not
                    #   belong to {employee} — checked explicitly, so an HR Admin
                    #   authorized over one employee cannot delete another's identification
                    #   by pairing mismatched ids
```

Deleting the row deletes its `media` row and RustFS scan object through medialibrary's own
model `deleting` event — the normal Eloquent delete path, unlike the FK-cascade trap
`02-data-model.md` documents for a future employee-delete route.

```
GET /api/v1/employees/{employee}/identifications/{identification}/scan
  → 200 <file stream>
  → 404 not_found   # not viewFullProfile (self or the administering HR Admin) — including
                    #   a MANAGER, who is deliberately never handed identification ids at
                    #   all — {identification} doesn't belong to {employee}, or the row
                    #   carries no scan
```

Private and app-mediated, exactly like `GET /requests/{request}/attachment` — never a
public or presigned RustFS URL. Gated on `viewFullProfile`, not `viewRedactedProfile`: a
manager's redacted resource never returns an identification id in the first place, so a
manager reaching this route at all is a guess or an attack, not a legitimate click-through
(`05-rbac.md`).

Every write above logs to `activity_log` under `log_name 'employee_profile'`
(`EmployeeProfile`/`EmployeeDependent`/`EmployeeIdentification`'s `LogsActivity`) — **except
that `EmployeeIdentification`'s `number` is never logged**, only that a row changed and by
whom; see `02-data-model.md` for why.

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
own local calendar date, honestly, and turning punches into priced hours is the compute
engine's job (M5a, below). Flagged
punches appear here exactly as recorded. The employee-scoped variant reuses `EmployeeScope`
and the 404-not-403 rule unchanged — an HR admin sees their office's punches, a manager
their reports', and an out-of-scope subject `404`s.

**These two reads stay raw even after M3.6.** An approved `void`/`amend` never changes what
either endpoint returns — the annulled row is still a real, once-true punch, and this is the
ledger you'd show an inspector. The **effective ledger** (`02-data-model.md`) that excludes
an annulled punch is a query, not an endpoint — `App\Domain\Attendance\EffectivePunches`,
read by M5a's compute engine below, not by either endpoint here.

### Reading the computed month *(M5a)*

```
GET /api/v1/me/attendance/summary?month=YYYY-MM        # auth:sanctum — own computed days only
  → { data: [ { date, day_type, is_rest_day, scheduled_minutes, is_art82_exempt,
                worked_minutes, late_minutes, undertime_minutes, unpaid_overtime_minutes,
                status, is_incomplete, rule_version_id,
                lines: [ { kind, minutes, applied_bp }, … ] }, … ] }   # ordered by date
  → 400 validation_failed   # month missing, or not YYYY-MM
  → 422 not_an_employee     # caller has no linked employee record — same rule as /me/attendance
```

**Self-scoped only** — there is deliberately no `{employee}`-parameterized variant the way
`GET /employees/{employee}/attendance` has one: taking a target-employee id here would be
an enumeration hole, so this endpoint answers "my own computed month" and nothing else. One
entry per `daily_attendance_summaries` row the caller's own employee has for the requested
month (`02-data-model.md`), `lines` sorted deterministically by `kind` so two reads of the
same day never differ in ordering alone. A day the compute engine has not priced yet — no
punches at all, or a date the synchronous on-write trigger hasn't reached (`02-data-model.md`'s
"synchronous on-write trigger") — is simply absent from the array; this endpoint never
invents a zero-value row for a day nothing has computed.

**Every minute and basis-point value here is exactly what `02-data-model.md` says is
stored: integer minutes, integer basis points, never a peso.** `rule_version_id` is the
`pay_rules` version (`03-api.md`'s pay-rules section above) that priced the day's lines —
null on a day with no lines (no configured version yet, an incomplete day, an unworked rest
day, …), per the same rule the schema section states. `unpaid_overtime_minutes` (added M6c)
is the overtime worked beyond the day's approved cap — paid overtime shows as
`overtime_day`/`overtime_night` lines up to `min(actual, approved)`, and everything past
that is this count, not a line (see the Overtime section below, and `02-data-model.md`).

This is the **only** read M5a adds; there is no write endpoint here. `ComputeDailySummary`
runs exclusively from the synchronous on-punch trigger (`02-data-model.md`) — a manual,
on-demand recompute is M5b's `RecomputeRange`, not this milestone. `scripts/e2e-compute.sh`
proves an ordinary day pricing at the statutory floor, a special-non-working holiday
pricing at 130%, and an Art. 82-exempt employee's lines all pricing at 100% even on that
same holiday, against the live stack.

## Attendance adjustments and the requests spine *(M3.6, generalized M6a)*

An employee's own correction, on the shared `requests` spine (`02-data-model.md`):
`pending → approved | rejected | cancelled`, one detail table per request type (only
`attendance_adjustment` exists today), a manager or HR approves — never the requester
themself, however broad their own scope otherwise reaches.

**M6a generalized the read/decision surface off attendance specifically.** M3.6 shipped
everything under `/attendance/adjustments/*`; M6a moved the read, decide, and queue routes
to the type-agnostic `/requests/*` and `/team/approvals` / `/office/approvals` below, so a
new request type is served by the exact same routes with no new endpoints — **M6b-b landed
the first one, leave** (its own submission endpoint and detail shape are below, under
"Leave requests"), and overtime pre-authorization (M6c) reuses this same surface a second
time. **Submission stays type-specific** — there is still no generic `POST /requests`, only
a per-type submit route (`POST /attendance/adjustments` below, `POST /leave/requests`
below) — because what a submission needs to validate (an `operation`/`target_log_id`/
`direction` for a correction; a `leave_type_id`/date range/`day_part` for leave) is
irreducibly per-type; only the shape *after* a request exists is shared.

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

**M6a moved these three off `/attendance/adjustments/{request}/*` onto the generalized
`/requests/{request}/*`** — same URL, same three verbs, for **every** request type;
`{request}` is any request type's id (`attendance_adjustment` or, since M6b-b, `leave`).
The action dispatches its effect by `type` (`RequestEffectFactory` → `RequestEffect`), so
approving a leave (or, later, overtime) request runs through this exact same route with a
different effect resolved underneath, not a new endpoint. **M6b-b is the first type where
approve does not always mean "final"** — see "Leave requests," below, for the full two-hop
shape; what follows here is the single-hop case M6a shipped, still exactly how
`attendance_adjustment` behaves today.

```
POST /api/v1/requests/{request}/approve   # auth:sanctum
  → 200 { data: <request, state: "approved", decided_by, decided_at> }
  → 404 not_found              # out of the approver's scope, OR the approver IS the requester
  → 409 request_not_pending    # already approved/rejected/cancelled
  → 422 invalid_adjustment_target   # a void/amend target is missing, not the requester's,
                                     #   or already annulled by an earlier approval
                                     #   (attendance_adjustment's own effect failure — a
                                     #   different request type's effect fails with its own
                                     #   code, e.g. leave's 422 insufficient_leave_balance,
                                     #   below)
```

**On a two-hop type (`leave`) this same route can instead land on `state: "manager_approved"`**
— the manager's (hop-1) decision — with `decided_by`/`decided_at` still null and
`manager_decided_by`/`manager_decided_at` set instead; only the SECOND `approve` call (HR's
hop) reaches `state: "approved"` and stamps `decided_by`/`decided_at`. See "Leave requests,"
below, for exactly which actor may call this route at which hop.

```
POST /api/v1/requests/{request}/reject
  { "decision_note": string, REQUIRED }
  → 200 { data: <request, state: "rejected", decision_note> }
  → 404 not_found              # same authority rule as approve
  → 409 request_not_pending
  → 400 validation_failed      # decision_note missing or empty
```

Reject always lands on `rejected` regardless of which hop it came from — there is no
"partially rejected" state, and rejecting never dispatches a type's effect (an approved
punch is never recorded, a leave balance is never debited) at either hop.

```
POST /api/v1/requests/{request}/cancel     # requester only
  → 200 { data: <request, state: "cancelled"> }
  → 404 not_found              # the caller is not the requester (narrower than approve/reject)
  → 409 request_not_pending
```

**Cancel is withdrawable from `pending` OR `manager_approved`** (M6b-b broadened it from
M6a's `pending`-only) — a two-hop leave request awaiting HR's hop is never stuck just
because a manager already signed off on it; only `approved`/`rejected`/`cancelled` (already
terminal) refuse with `409`.

**Authority is "in-scope-minus-self": `RequestAuthority::canDecide`**, now state- and
type-aware since M6b-b. For a single-hop type (`attendance_adjustment`) it is unchanged
from M6a: the requester must be visible to the approver under `EmployeeScope::visibleTo()`
(self, direct reports, HR's office, or system-admin-all) **and** the approver must not be
the requester — a manager approves their report's request, an HR admin their office's, a
system admin anyone's. For a two-hop type (`leave`), which actor may act depends on the
CURRENT hop — the manager alone at `pending`, HR alone (and never the same actor who
decided hop 1) at `manager_approved` — the full routing is under "Leave requests," below,
and `05-rbac.md`. In every case the requester approving their own request — however broad
their scope otherwise is — is refused exactly like an out-of-scope stranger, `404`, never a
different status that would confirm "this is yours, you just can't approve it." **Cancel
has its own, narrower rule**: only the requester may cancel, so a manager or HR admin who
could approve the request may **not** cancel it on the requester's behalf, at either hop.

**Order of checks, and why it's fixed:** authority (`404`) → terminal (`409`) → the effect,
which can itself fail (`422`, rolling back the whole approval — the request stays at
whatever state it was in before the call). Reject's `decision_note` requiredness is checked
**last**, *inside* the action, deliberately after authority and terminal-ness — validating
it at the HTTP layer would let an out-of-scope prober distinguish "exists but hidden"
(`400` on an empty body) from "doesn't exist" (`404`), the exact existence leak the
404-not-403 rule exists to close. So an out-of-scope reject with an empty body is still
`404`, never `400`.

**Approval is transactional with its effect, and only on the hop that reaches `approved`.**
`RequestEffectFactory::for($request->type)` resolves the type's `RequestEffect`
(`AttendanceAdjustmentEffect`, delegating to `ApplyAttendanceAdjustment`; `LeaveEffect`
since M6b-b, below) and calls it inside the same lock, but only when the decision about to
be recorded is the FINAL one — for `attendance_adjustment` (single-hop) that is every
decision; for `leave` (two-hop) that is HR's hop alone, never the manager's. `add` →
`RecordPunch` (`source: adjustment`, `recorded_by` the approver); `void` → `RecordAnnulment`;
`amend` → both; leave's final approval → `LeaveEffect`'s ledger debit, below. The effect
call, plus the request's own state write, happen inside one `SELECT ... FOR UPDATE`-locked
transaction — if the effect throws, nothing commits, including the state transition. An
unmapped `type` reaching this factory is a programming error (a request type shipped with
no effect wired), never a silent no-op approve.

### List mine, the two approval queues, show, and the attachment

```
GET /api/v1/requests            # auth:sanctum — the caller's own, any state
  → { data: [ <request>, … ] }
  → 422 not_an_employee
```

**The single combined pending queue is gone — replaced by two scope-based views**, both
type-agnostic (a future leave or overtime request appears here automatically, with no
per-type wiring):

```
GET /api/v1/team/approvals     # auth:sanctum — the caller's DIRECT REPORTS' pending requests
GET /api/v1/office/approvals   # auth:sanctum — the caller's HR-administered offices' pending requests
  → { data: [ <request>, … ] }   # in-scope-minus-self, state=pending only
```

Each is a `Builder`-returning view (`App\Domain\Requests\ApprovalQueues`) over the same
pending set `RequestAuthority::canDecide` would accept one request at a time —
`directReportsOf` filters to `employee_id IN (current_reports_to_id = me)`, `hrOfficesOf` to
`employee_id IN (current_office_id IN my hr_admin_offices)` — both excluding the caller's
own requests, and both silently empty for an actor with no employee record (a
system-admin-only account has no org-chart position and administers no office, so gets **no
queue at all** — not an error, just two empty arrays). A manager with no HR office sees only
`/team/approvals` populated; an HR admin with no reports sees only `/office/approvals`; a
manager who is ALSO their office's HR admin sees the same pending request on both, since the
two are independent views over one set, not a partition of it.

```
GET /api/v1/requests/{request}            # auth:sanctum
  → 200 { data: <request> }
  → 404 not_found   # neither the requester nor an authorized approver

GET /api/v1/requests/{request}/attachment # auth:sanctum
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

**M6a shipped:** per-type effect dispatch, the two scoped queues above, the route
generalization onto `/requests/*`, and the full correction-filing vertical in the browser
(`/me/attendance`'s file-a-correction form, `/me/requests`, `/team/approvals`,
`/office/approvals`) — proven end to end by `scripts/e2e-requests.sh` against the live
stack. The state machine was still M3.6's single step, `pending → approved | rejected |
cancelled`, at that point — **M6b-b widened it to the two-hop `pending → [manager_approved
→] approved | rejected | cancelled` machine leave needs**, `manager_approved` reachable only
by a `requiresHrStep()` type. See "Leave requests," below, and `06-roadmap.md`.

## Office — holidays *(M4a)*

An office's holiday calendar: which calendar dates carry which `DayType`
(`02-data-model.md`) — `Ordinary` is the absence of a row. Every endpoint here is gated by
`App\Domain\Scope\OfficeScope` (`05-rbac.md`), not a permission check: a System Admin
administers every office, an HR Admin exactly the offices in their `hr_admin_offices`
pivot, anyone else none at all.

**The central design point: out-of-scope is `404`, not `403`, and it is byte-identical to a
nonexistent office or holiday.** Every `FormRequest` here validates `office_id`/`office` as
shape only (`uuid`) — **never** `exists:offices,id`. Adding that rule would let a fabricated
office id fail validation (`400`) while an out-of-scope *real* office failed the controller's
scope check (`404`) — two different codes that would hand a prober an oracle for "does this
office exist." Because both paths are refused the exact same way, from the exact same
`throw new NotFoundHttpException` with no per-request detail, a Manila HR admin and a
Cebu-only HR admin touching a Manila holiday get responses that are not just the same status
but the same bytes.

```
POST /api/v1/office/holidays          # auth:sanctum — HR Admin/System Admin, scoped
  { "office_id": "0199…", "date": "2026-08-21", "day_type": "special_non_working",
    "name": "Ninoy Aquino Day" }
  → 201 { data: { id, office_id, date, day_type, name } }
  → 400 validation_failed     # bad shape, or day_type outside the four non-Ordinary cases
  → 404 not_found             # office_id is out of the caller's scope, or doesn't exist
  → 422 holiday_exists        # that office already has a holiday on that date; details: { office_id, date }
```

`holiday_exists` is a clean domain refusal, not the raw `500` the `unique(office_id, date)`
constraint would otherwise surface — `CreateHoliday` locks the office row, re-checks under
that lock, and throws `HolidayExists` (mirroring `RecordEmploymentChange`'s
`employment_record_exists`), so two admins racing the same office-date get a `422`, never a
constraint violation. The scope `404` always runs first, so this is only ever reachable for
an office the caller administers — it leaks nothing about others. Moving a holiday to a
different date is a delete-and-recreate, so there is no such collision on update.

```
GET /api/v1/office/holidays?office=<uuid>&year=<int>   # auth:sanctum, scoped
  → { data: [ { id, office_id, date, day_type, name }, … ] }   # date-ordered
  → 400 validation_failed     # office not a uuid, or year outside 2000–2100
  → 404 not_found             # office is out of the caller's scope, or doesn't exist
```

```
POST /api/v1/office/holidays/clone     # auth:sanctum, scoped
  { "office_id": "0199…", "from_year": 2025, "to_year": 2026 }
  → 201 { data: [ { id, office_id, date, day_type, name }, … ] }   # only the rows actually created
  → 400 validation_failed
  → 404 not_found
```

Copies `from_year`'s holidays onto the same month/day of `to_year` — never a `+365`-day
shift, which would land on the wrong day across a leap year. Any target date **already
occupied is skipped**, not overwritten, so `data` in the response holds only the rows the
call actually created; cloning an identical range twice creates nothing the second time.
A Feb 29 source with no Feb 29 in the target year is skipped outright, never slid to Mar 1.

```
PATCH /api/v1/office/holidays/{holiday}   # auth:sanctum, scoped
  { "day_type": "special_non_working", "name": "Ninoy Aquino Day" }
  → 200 { data: { id, office_id, date, day_type, name } }
  → 400 validation_failed
  → 404 not_found     # {holiday}'s office is out of the caller's scope, or {holiday} doesn't exist
```

**There is no `date` in the update body.** A holiday's date is fixed at creation; moving it
to a different day is a delete-and-recreate, not an edit, so the request shape has nothing
to validate against `unique(office_id, date)` mid-update.

```
DELETE /api/v1/office/holidays/{holiday}   # auth:sanctum, scoped
  → 204 No Content
  → 404 not_found     # same scope rule as PATCH
```

Every write here — create, clone, update, delete — is logged by `Holiday`'s
`Spatie\Activitylog\Traits\LogsActivity` (log name `holiday`), causer resolved automatically
from the authenticated guard. Create/update/delete log the `Holiday` itself as the subject
(the uuid morph, `02-data-model.md`); clone logs a summary (`from_year`, `to_year`, `created`
count) against the `Office` as subject, since a clone spans many rows rather than one.
`scripts/e2e-holidays.sh` proves the whole surface — including the byte-identical 404 pair —
against the live stack.

## Office — schedules *(M4b)*

Shift templates, their assignment to an employee or department, per-date overrides, an
office-wide default, and the resolved read that walks all four for one employee. Every
endpoint here is gated by the same `App\Domain\Scope\OfficeScope` as the holidays above
(`05-rbac.md`) — the identical 404-not-403 discipline, applied to a second resource: every
`FormRequest` here validates an id as shape only (`uuid`), never `exists:`, so a fabricated
id and an out-of-scope real one are byte-identical `404`s. `scripts/e2e-schedules.sh` walks
the whole surface — including the byte-identical 404 pair — against the live stack.

### Shift templates

```
POST /api/v1/office/shift-templates   # auth:sanctum — HR Admin/System Admin, scoped
  { "office_id": "0199…", "name": "Standard Mon-Fri", "days": [
      { "weekday": 0, "is_rest": false, "start_minute": 480, "end_minute": 1080, "break_minutes": 60 },
      …,                                                    # exactly 7 entries, one per weekday 0-6
      { "weekday": 5, "is_rest": true } ] }
  → 201 { data: { id, office_id, name, days: [ { weekday, is_rest, start_minute, end_minute, break_minutes }, … ] } }
  → 400 validation_failed     # not exactly 7 days, is_rest carrying hours (or a working day missing them),
                              #   or an invalid minute range
  → 404 not_found             # office_id is out of the caller's scope, or doesn't exist
```

```
GET /api/v1/office/shift-templates?office=<uuid>       # list, scoped
  → { data: [ { id, office_id, name, days: […] }, … ] }
  → 400 validation_failed | 404 not_found

GET /api/v1/office/shift-templates/{template}          # show, scoped
  → { data: { id, office_id, name, days: […] } }
  → 404 not_found

PATCH /api/v1/office/shift-templates/{template}        # replaces the whole week
  { "name": "Standard Mon-Fri", "days": [ … 7 entries … ] }
  → 200 { data: { id, office_id, name, days: […] } }
  → 400 validation_failed | 404 not_found

DELETE /api/v1/office/shift-templates/{template}
  → 204 No Content
  → 404 not_found
  → 422 template_in_use       # still an office's default, or still pointed at by a schedule
                              #   assignment; details: { template_id }
```

`template_in_use` is a clean domain refusal, not the raw `500` a dangling foreign key would
otherwise risk — the scope `404` always runs first, so this is only ever reachable for a
template the caller administers, leaking nothing about other offices'.

### Schedule assignments — employee or department, effective-dated

```
POST /api/v1/office/schedule-assignments
  { "shift_template_id": "0199…", "employee_id": "0199…", "effective_from": "2026-08-01" }
  # or "department_id" instead of "employee_id" — exactly one of the two, never both, never neither
  → 201 { data: { id, shift_template_id, employee_id, department_id, effective_from } }
  → 400 validation_failed     # neither/both employee_id and department_id present, or bad shape
  → 404 not_found             # the target's office (employee's current office, or department's
                              #   office), or the template's office, is out of the caller's scope
  → 422 schedule_assignment_exists   # that target already has an assignment effective on that date;
                              #   details: { target_type, target_id, effective_from }
```

**An assignment's office is its target's office, not a column of its own** — an employee's
`current_office_id`, or a department's `office_id` — so a template from a different office
than the target 404s identically to a fabricated template id (no separate error code for
"wrong office").

```
GET /api/v1/office/schedule-assignments?office=<uuid>&employee=<uuid>&department=<uuid>
  # employee/department optionally narrow an already-scoped list
  → { data: [ { id, shift_template_id, employee_id, department_id, effective_from }, … ] }
  → 400 validation_failed | 404 not_found

DELETE /api/v1/office/schedule-assignments/{assignment}
  → 204 No Content
  → 404 not_found
```

### Schedule overrides — the per-date, per-employee exception

```
POST /api/v1/office/schedule-overrides
  { "employee_id": "0199…", "date": "2026-08-08", "is_rest": false,
    "start_minute": 480, "end_minute": 1080, "break_minutes": 60, "note": "Covering a shift" }
  → 201 { data: { id, employee_id, date, is_rest, start_minute, end_minute, break_minutes, note } }
  → 400 validation_failed     # is_rest carrying hours, a working override missing them, or a bad range
  → 404 not_found             # the employee's current office is out of the caller's scope
  → 422 schedule_override_exists   # that employee already has an override on that date;
                              #   details: { employee_id, date }
```

```
GET /api/v1/office/schedule-overrides?office=<uuid>&employee=<uuid>&month=<YYYY-MM>
  → { data: [ { id, employee_id, date, is_rest, … }, … ] }    # date-ordered
  → 400 validation_failed | 404 not_found

PATCH /api/v1/office/schedule-overrides/{override}    # no employee_id/date — fixed at creation
  { "is_rest": true }
  → 200 { data: { id, employee_id, date, is_rest, … } }
  → 400 validation_failed | 404 not_found

DELETE /api/v1/office/schedule-overrides/{override}
  → 204 No Content
  → 404 not_found
```

### Office default template

```
PATCH /api/v1/office/default-template
  { "office_id": "0199…", "template_id": "0199…" }
  → 200 { data: { id, default_shift_template_id } }   # id is the office's own id
  → 400 validation_failed
  → 404 not_found     # office_id is out of scope, or template_id isn't that same office's own template
```

There is no `GET` for an office's current default — this endpoint is write-and-echo-back
only. The frontend's `/office/schedules` screen names this gap explicitly: it can only show
a default it set *this session*, never one read back after a reload (see that screen's
file-level comment).

### The resolved read

```
GET /api/v1/office/schedule/resolved?employee=<uuid>&month=<YYYY-MM>
  → { data: { "2026-08-01": { is_rest, start_minute, end_minute, break_minutes,
                               scheduled_minutes, source }, … } }   # one entry per day of the month
  → 400 validation_failed     # employee not a uuid, or month outside YYYY-MM
  → 404 not_found             # the employee's current office is out of the caller's scope,
                              #   or the employee has no current office at all (never a 500
                              #   from a null-derived uuid)
  → 422 office_has_no_default_template   # resolution fell through to the office-default layer
                              #   and the office has none set; details: { office_id }
```

`ScheduleResolver` also defines an `employee_has_no_office` (422) domain exception, but this
endpoint can never surface it: the controller 404s a null `current_office_id` (the scope
check above) *before* the resolver runs, so an office-less employee is indistinguishable from
a fabricated one. `employee_has_no_office` is reserved for M5's direct, non-HTTP resolver
callers (a queued compute job resolving an employee who legitimately has no office yet).

`ScheduleResolver` runs once per date of the month (`02-data-model.md`): override → employee
assignment → department assignment → office default, first hit wins, `source` names which
layer answered. `scheduled_minutes` is always pre-computed — `0` for a rest day, `(end -
start) - break` otherwise, cross-midnight included — never re-derived by the client.

Every write here — template create/update/delete, assignment create/delete, override
create/update/delete — is logged by `ShiftTemplate`/`ScheduleAssignment`/`ScheduleOverride`'s
`Spatie\Activitylog\Traits\LogsActivity` (log names `shift_template`, `schedule_assignment`,
`schedule_override`), the model itself as the uuid-morph subject, causer resolved
automatically from the authenticated guard. `Office` has no `LogsActivity` trait, so setting
the default logs manually against the `Office`, the same way `CloneHolidays` does.

## Admin — pay rules *(M4c)*

Effective-dated versions of the company's premium-pay matrix — three scalar rates
(`overtime_ordinary_bp`, `overtime_premium_bp`, `night_diff_bp`) plus one
`worked_bp`/`worked_rest_bp`/`unworked_bp` row per `DayType` (`02-data-model.md`). Unlike
holidays and schedules (M4a/M4b), this is **not** `OfficeScope`-gated — a pay rule prices
every office the same way, so there is no office to scope by. Every endpoint here is
sysadmin-gated directly by its `FormRequest::authorize()` (`(bool)
$this->user()?->is_system_admin`), the codebase's usual System-Admin-only idiom
(`RecordEmploymentRequest` et al.), never `OfficeScope`.

**A non-admin gets `403 forbidden`, not the `404`-not-`403` discipline M4a/M4b use.** That
discipline exists to keep an out-of-scope *subject* from being confirmed to exist; pay
rules are a company singleton with nothing to enumerate — there is no per-office or
per-subject id a non-admin could be probing for. Refusing the actor outright with the
default `failedAuthorization()` is the correct shape here, the same way onboarding
(`POST /admin/employees` et al.) refuses a non-admin with `403`, not `404`.

```
POST /api/v1/admin/pay-rules      # auth:sanctum — System Admin only
  { "effective_from": "2027-01-01",
    "overtime_ordinary_bp": 12500, "overtime_premium_bp": 13000, "night_diff_bp": 11000,
    "day_rates": [
      { "day_type": "ordinary", "worked_bp": 10000, "worked_rest_bp": 13000, "unworked_bp": 0 },
      …                                                # exactly 5 entries, one per DayType, no dup, none missing
    ],
    "note": "2027 rates" }
  → 201 { data: { id, effective_from, overtime_ordinary_bp, overtime_premium_bp,
                   night_diff_bp, note, day_rates: [ { day_type, worked_bp, worked_rest_bp,
                   unworked_bp }, … ] } }
  → 400 validation_failed     # bad shape, or day_rates not exactly the five DayType values once each
  → 403 forbidden             # caller is not a System Admin
  → 409 pay_rule_exists       # a version already takes effect on that effective_from
  → 422 pay_rate_below_floor  # one or more cells fall below config('hris.pay_floors');
                              #   details: { violations: [ { multiplier, proposed_bp, floor_bp }, … ] }
```

`pay_rate_below_floor` is checked before the transaction ever opens — a pure, read-only
comparison against `config('hris.pay_floors')` — and reports **every** violating cell at
once, not one field at a time; `multiplier` is a dotted path
(`worked.regular_holiday.not_rest`, `unworked.double_regular_holiday`,
`overtime_premium`, …) naming exactly which cell failed. `pay_rule_exists` is a clean `409`
translated from the `unique(effective_from)` constraint violation itself — there is no
parent row to lock first (a pay rule is a company singleton, not a child of some office
row), so the insert is attempted and its unique-violation caught, which is race-safe as
well as covering the sequential-duplicate case.

```
GET /api/v1/admin/pay-rules       # auth:sanctum — System Admin only
  → { data: [ { id, effective_from, overtime_ordinary_bp, overtime_premium_bp,
                night_diff_bp, note, day_rates: […] }, … ] }   # effective_from descending
  → 403 forbidden

GET /api/v1/admin/pay-rules/{payRule}
  → { data: { id, effective_from, …, day_rates: […] } }
  → 403 forbidden
  → 404 not_found     # {payRule} does not exist — plain not-found here, not the
                      #   404-not-403 scope discipline (there is no scope to leak)

DELETE /api/v1/admin/pay-rules/{payRule}
  → 204 No Content
  → 403 forbidden
  → 404 not_found
```

**There is no `PATCH` route at all — versions are immutable by omission, not by a
guard.** A rate correction is always a new version, effective from a later date, read
alongside every earlier one; requesting `PATCH /admin/pay-rules/{payRule}` gets Laravel's
own `405 method_not_allowed` (`03-api.md`'s errors table below), because the route simply
does not exist for that verb, the same as any other undeclared method on a real path.

Every create/delete is logged by `PayRule`'s `Spatie\Activitylog\Traits\LogsActivity` (log
name `pay_rule`), the `PayRule` itself as the uuid-morph subject, causer resolved
automatically from the authenticated guard. `scripts/e2e-pay-rules.sh` proves the whole
surface — floor-valid create, the below-floor `422`, the duplicate `409`, the immutable
`405`, the non-admin `403`, and the activity-log row — against the live stack.

## Admin — the organization tree *(M8a)*

The company's shape — `organizations` → `offices` → `departments` (`02-data-model.md`) —
made admin-editable at runtime. Like pay rules (M4c) and unlike the office-scoped HR
surfaces (M4a/M4b/M7), this is **not** `OfficeScope`-gated: an organization is the parent
an office belongs to and an office cannot scope-check itself, so there is no office to gate
by. Every endpoint here is sysadmin-gated directly by its `FormRequest::authorize()`
(`(bool) $this->user()?->is_system_admin`), and **a non-admin gets `403 forbidden`, not the
`404`-not-`403` discipline** the HR endpoints use. That discipline exists to keep an
out-of-scope *subject* from being confirmed to exist; the org tree is global config with no
per-subject scope a non-admin could be probing, so refusing the actor outright is the
correct shape — the same `403` pay rules and onboarding return. This is the one deliberate
global-admin exception to 404-not-403 (`05-rbac.md`).

**Archive-never-delete.** There is **no `DELETE` route** on any tier. Retiring an office or
department stamps a nullable `archived_at`; the row, its departments, and every
`employment_record`/`current_*` snapshot pointing at it stay intact (`02-data-model.md`).
The list endpoints hide archived rows by default and reveal them with `?include_archived=1`.
Organizations have no archive (nothing sits above them to close). Every create/update/
archive/unarchive is logged by the models' `LogsActivity` (log names `organization`/`office`/
`department`, the row the uuid-morph subject, the acting admin the causer).

```
GET  /api/v1/admin/organizations          # auth:sanctum — System Admin only
  → { data: [ { id, name, legal_name, tin, timezone }, … ] }   # name ascending
  → 403 forbidden                          # caller is not a System Admin

POST /api/v1/admin/organizations
  { "name": "Acme, Inc.", "legal_name": "Acme Incorporated", "tin": "…", "timezone": "Asia/Manila" }
  → 201 { data: { id, name, legal_name, tin, timezone } }
  → 400 validation_failed                  # bad shape (name/timezone required; timezone must be a real tz)
  → 403 forbidden

PATCH /api/v1/admin/organizations/{organization}   # full object, not a partial patch
  { "name": …, "legal_name": …, "tin": …, "timezone": … }
  → { data: { … } }
  → 403 forbidden
  → 404 not_found                          # {organization} does not exist — plain not-found, no scope to leak
```

```
GET  /api/v1/admin/offices                 # auth:sanctum — System Admin only
  ?organization=<uuid>                     # optional: only offices under that org
  ?include_archived=1                      # optional: include archived offices (default hides them)
  → { data: [ { id, organization_id, name, code, timezone, region, geofence_lat, geofence_lng,
                geofence_radius_m, ip_allowlist, default_shift_template_id, archived_at }, … ] }
  → 403 forbidden

POST /api/v1/admin/offices
  { "organization_id": "<uuid>", "name": "Manila HQ", "code": "MNL", "timezone": "Asia/Manila",
    "region": "IV-A" | null,                 # OPTIONAL free text — added M10a
    "geofence_lat": null, "geofence_lng": null, "geofence_radius_m": null,
    "ip_allowlist": null, "default_shift_template_id": null }
  → 201 { data: { … , archived_at: null } }
  → 400 validation_failed
  → 403 forbidden
  → 422 duplicate_office_code              # offices.code is GLOBALLY unique; details: { code }

PATCH /api/v1/admin/offices/{office}       # full object, not a partial patch
  → { data: { … } }
  → 403 forbidden · 404 not_found · 422 duplicate_office_code

POST /api/v1/admin/offices/{office}/archive
  → { data: { … , archived_at: "…" } }
  → 403 forbidden · 404 not_found
  → 409 already_archived                   # details: { subject_type: "office", subject_id }

POST /api/v1/admin/offices/{office}/unarchive
  → { data: { … , archived_at: null } }
  → 403 forbidden · 404 not_found
  → 409 not_archived                       # details: { subject_type: "office", subject_id }
```

**`region` (M10a) is free text** — `'VII'`, `'IV-A'` — naming the office's PSA
administrative region. It is read, never written, by M10a's profile Assignment block
(above, under "Employee profiling"); nothing in the API validates it against a real list of
PH regions, the same "long tail, not a closed set" treatment `02-data-model.md`'s M10a
section gives citizenship/religion/birthplace.

```
GET  /api/v1/admin/departments             # auth:sanctum — System Admin only
  ?office=<uuid>                           # optional: only departments under that office
  ?include_archived=1                      # optional: include archived departments
  → { data: [ { id, office_id, name, code, archived_at }, … ] }
  → 403 forbidden

POST /api/v1/admin/departments
  { "office_id": "<uuid>", "name": "Operations", "code": "OPS" }
  → 201 { data: { id, office_id, name, code, archived_at: null } }
  → 400 validation_failed
  → 403 forbidden
  → 422 duplicate_department_code          # departments.code is unique WITHIN an office; details: { code }

PATCH /api/v1/admin/departments/{department}   # full object
  → { data: { … } }
  → 403 forbidden · 404 not_found · 422 duplicate_department_code

POST /api/v1/admin/departments/{department}/archive
  → { data: { … , archived_at: "…" } }
  → 403 forbidden · 404 not_found · 409 already_archived   # details: { subject_type: "department", subject_id }

POST /api/v1/admin/departments/{department}/unarchive
  → { data: { … , archived_at: null } }
  → 403 forbidden · 404 not_found · 409 not_archived       # details: { subject_type: "department", subject_id }
```

`duplicate_office_code` / `duplicate_department_code` are clean `422` translations of the
underlying unique constraint (global on `offices.code`, `(office_id, code)` on departments):
a read-only pre-check inside the transaction covers the sequential case and a caught
`UniqueConstraintViolationException` is the race-safe backstop, so the worst a client can
observe is the clean `422`, never a raw unique-violation `500`. `already_archived` /
`not_archived` (`409`) are the idempotency guards on the archive/unarchive toggles — the
same generic pair, distinguished only by `details.subject_type`, is reused across both tiers.
`scripts/e2e-admin-org.sh` proves the whole surface — create/list each tier, the duplicate
`422`, the `activity_log` writes, the archive/list-hide/`include_archived`/re-archive `409`/
unarchive cycle, and the non-admin `403` — against the live stack.

## Admin — the audit viewer *(M8c)*

A read-only, filterable, paginated window over the one Spatie `activity_log` every
`LogsActivity` model already writes to (offices, departments, organizations, employees) plus
the manual `hr_admin_offices_set` event above — the audit trail of who changed what, in one
place. `is_system_admin`-gated like the rest of the `/admin` surface: the log spans every
subject type company-wide, so there is nothing office-scoped to check, and a non-admin gets
`403`, not `404`.

```
GET /api/v1/admin/activity          # auth:sanctum — System Admin only
  ?log_name=<string>                # e.g. office · department · employee · default
  ?event=<string>                   # created · updated · deleted (blank for manual logs)
  ?subject_type=<fqcn>              # e.g. App\Models\Office
  ?causer_id=<uuid>                 # the acting user's id
  ?from=YYYY-MM-DD  &to=YYYY-MM-DD  # inclusive created_at date bounds
  ?page=<n>                         # 1-based; 50 rows per page, newest first
  → { data: [ { id, log_name, description, event, subject_type, subject_id,
                causer_id, properties, created_at }, … ],
      meta: { current_page, last_page, total, per_page } }
  → 403 forbidden                   # a non-admin actor
```

Every filter is optional and **AND-combined**; omit them all for the whole log. `from`/`to`
bound `created_at` by date (inclusive). The envelope is **hand-built, not Laravel's default
paginated-resource shape** — `{ data, meta }` with a terse four-field `meta`
(`current_page`, `last_page`, `total`, `per_page`), deliberately without the absolute
container URLs and HTML-entity `meta.links` array Laravel's default carries, which nothing
else in this API uses. Rows are ordered newest-first (`latest()`), 50 to a page.
`scripts/e2e-admin-roles-audit.sh` proves the viewer surfaces both the `hr_admin_offices_set`
event and the `log_name=office` trail, and the non-admin `403`, against the live stack.

## Admin — the document catalog *(M10b-a)*

The admin-editable catalog behind M10b's document module: categories (shelves) and document
kinds (what's on them), with `applies_to`/`is_required`/`validity_months` as behaviour on the
kind (`02-data-model.md`). Nine routes total, none of which write a file — `document_files`
exists and is empty after this milestone; upload/list/download/delete for both owner types,
plus the two compliance reads, are M10b-b.

```
GET    /api/v1/documents/catalog                          any authenticated user, ungated
GET    /api/v1/admin/document-categories                  \
POST   /api/v1/admin/document-categories                   |
PATCH  /api/v1/admin/document-categories/{category}        |  document.manage
DELETE /api/v1/admin/document-categories/{category}        |
GET    /api/v1/admin/documents                             |
POST   /api/v1/admin/documents                              |
PATCH  /api/v1/admin/documents/{document}                  |
DELETE /api/v1/admin/documents/{document}                 /
```

**`GET /documents/catalog` is ungated by design — any authenticated user, no permission
check, no scope.** It's static, company-wide reference data with nothing sensitive in it,
and every screen that will eventually file a document (M10b-b) needs it to turn a
`document_id`/`category_id` into a name — the same reasoning `GET /profile/catalog` (above)
already established for M10a's catalog. It is the **lightweight dropdown read**; the eight
`/admin/*` routes below are the **CRUD surface**, gated separately, and the two serve
different callers on purpose:

```
GET /api/v1/documents/catalog
  → 200 { data: {
      categories: [ { id, code, name, description }, … ],       # ordered by code
      documents:  [ { id, code, name, description, category_id,
                      applies_to, is_required, validity_months }, … ]   # ordered by code
    } }
```

`applies_to` is the `Documentable` enum's backed value — `"employee"` | `"office"` | `null`
(both) — never an object. `validity_months` is `null` for a kind that never expires.

**Catalog CRUD denials are `403`, not `404`** — the reverse of the 404-not-403 rule this
document uses everywhere else. That rule protects an **owner id in the URL** from
enumeration (an out-of-scope office, an out-of-scope employee): a category or a document
kind has no owner and nothing to enumerate, the same reasoning `/admin/pay-rules` and
`/admin/organizations` already established. Every `FormRequest` here authorizes with
`$this->user()?->can('manageCatalog', Document::class) === true`
(`App\Policies\DocumentPolicy::manageCatalog`, `05-rbac.md`), and none overrides
`failedAuthorization()` — the framework default `403 forbidden` is correct as-is.

```
GET /api/v1/admin/document-categories        # document.manage
  → 200 { data: [ { id, code, name, description }, … ] }   # ordered by code
  → 403 forbidden

POST /api/v1/admin/document-categories       # document.manage
  { "code": "PRE_EMPLOYMENT", "name": "Pre-employment", "description": "…" | null }
  → 201 { data: { id, code, name, description } }
  → 400 validation_failed   # duplicate code, or a required field missing
  → 403 forbidden

PATCH /api/v1/admin/document-categories/{category}   # document.manage — full-object
  { "code": "PRE_EMPLOYMENT", "name": "Pre-employment", "description": "…" | null }
  → 200 { data: { id, code, name, description } }
  → 400 validation_failed   # code collides with a DIFFERENT category (Rule::unique->ignore
                            #   lets a category keep its own code unchanged)
  → 403 forbidden

DELETE /api/v1/admin/document-categories/{category}   # document.manage
  → 200 { data: [ { id, code, name, description }, … ] }   # the REMAINING catalog
  → 403 forbidden
  → 409 document_catalog_in_use   # details: { subject_type: "document_category",
                                   #   subject_id, dependents }
```

**Delete returns the remaining list, not `204`** — on both the category and the document
kind routes below — so the client's cache updates in one round trip instead of a follow-up
`GET`. **A category or kind still referenced elsewhere refuses with `409
document_catalog_in_use`, never a cascade** — a category with documents under it, or a
document kind with filed files (M10b-b) — with the dependent count in `details` so the UI
can say "3 documents still use this category" instead of a bare failure
(`02-data-model.md`).

```
GET /api/v1/admin/documents        # document.manage
  → 200 { data: [ { id, code, name, description, category_id, applies_to, is_required,
                    validity_months }, … ] }   # ordered by code
  → 403 forbidden

POST /api/v1/admin/documents       # document.manage
  { "code": "NBI", "name": "NBI Clearance", "description": "…" | null,
    "category_id": "0199…",
    "applies_to": "employee" | "office" | null,    # null = both; OPTIONAL
    "is_required": true,                            # OPTIONAL, defaults false
    "validity_months": 6 | null }                   # OPTIONAL, null = never expires
  → 201 { data: { id, code, name, description, category_id, applies_to, is_required,
                  validity_months } }
  → 400 validation_failed   # duplicate code, category_id not a real category, applies_to
                            #   outside the enum's exact backed values (Rule::enum — casing
                            #   matters, "Employee" 400s), or validity_months < 1
  → 403 forbidden

PATCH /api/v1/admin/documents/{document}   # document.manage — full-object except is_required
  { "code": "NBI", "name": "NBI Clearance", "description": "…" | null,
    "category_id": "0199…", "applies_to": "employee" | "office" | null,
    "is_required": true,                # OPTIONAL — an omitted key means false, not "unchanged"
    "validity_months": 6 | null }
  → 200 { data: { id, code, name, description, category_id, applies_to, is_required,
                  validity_months } }
  → 400 validation_failed   # same set as create, plus a code collision with a different
                            #   document (Rule::unique->ignore lets a document keep its own)
  → 403 forbidden

DELETE /api/v1/admin/documents/{document}   # document.manage
  → 200 { data: [ { id, code, … }, … ] }   # the remaining catalog
  → 403 forbidden
  → 409 document_catalog_in_use   # details: { subject_type: "document", subject_id, dependents }
```

`category_id` on create/update validates `exists:document_categories,id` — unlike, say,
`office_id` in `CreateLeaveTypeRequest`, which deliberately omits `exists` so an
out-of-scope office 404s in the controller instead of 400ing at validation (an
enumeration oracle). That reasoning doesn't apply here: `document_categories` is itself
company-wide reference data readable by any authenticated user through `GET
/documents/catalog`, so there is nothing to enumerate by probing `category_id`.

**`GET /admin/documents/expiring` and `GET /admin/documents/missing` are M10b-b, and must
be registered in `routes/api.php` before any future parameterised `GET
/admin/documents/{document}`.** No such show route exists today — only `PATCH`/`DELETE`
take a `{document}` — so there is no collision yet, but whoever adds a show route later
must put the two literal-segment routes first, or Laravel's router would bind
`expiring`/`missing` as a `{document}` id and 404 on model resolution instead of ever
reaching the intended controller — the ordinary Laravel routing trap of a wildcard segment
shadowing a literal one that comes after it.

## Leave — foundation *(M6b-a)*

Leave-type config per office, the office-wide day-length divisor, HR manual grants, and a
derived balance read — the pieces a leave *request* will need without there being a leave
request yet. Everything a balance is built from lives in `leave_ledger`
(`02-data-model.md`): an append-only row per grant or deduction, integer minutes, never a
stored running total. **There was no self-service leave request in this milestone** — an
employee could not yet file for their own leave the way they can an attendance adjustment;
that landed in M6b-b, reusing the `requests` spine above and widening it to the two-hop
`pending → manager_approved → approved` machine leave needs — see "Leave requests," just
below.

### Leave-type config

Gated by the same `App\Domain\Scope\OfficeScope` as holidays and schedules (M4a/M4b,
above) — the identical 404-not-403 discipline: every `FormRequest` here validates an
office/leave-type id as shape only (`uuid`), never `exists:`, so a fabricated id and an
out-of-scope real one are byte-identical `404`s.

```
GET /api/v1/office/leave-types?office=<uuid>     # auth:sanctum — HR Admin/System Admin, scoped
  → { data: [ { id, office_id, name, code, is_paid, requires_attachment, deducts_balance,
                is_cash_convertible, max_carryover_minutes, is_active }, … ] }   # name-ordered
  → 400 validation_failed     # office not a uuid
  → 404 not_found             # office is out of the caller's scope, or doesn't exist
```

```
POST /api/v1/office/leave-types                  # auth:sanctum — HR Admin/System Admin, scoped
  { "office_id": "0199…", "name": "Vacation Leave", "code": "VL" | null,
    "is_paid": true, "requires_attachment": false, "deducts_balance": true,
    "is_cash_convertible": true, "max_carryover_minutes": 4800 | null,
    "is_active": true }                            # OPTIONAL — defaults true
  → 201 { data: { id, office_id, name, code, is_paid, requires_attachment, deducts_balance,
                  is_cash_convertible, max_carryover_minutes, is_active } }
  → 400 validation_failed     # bad shape
  → 404 not_found             # office_id is out of the caller's scope, or doesn't exist
```

```
PATCH /api/v1/office/leave-types/{leaveType}     # auth:sanctum — HR Admin/System Admin, scoped
  { "name": "Vacation Leave", "code": "VL" | null, "is_paid": true,
    "requires_attachment": false, "deducts_balance": true, "is_cash_convertible": true,
    "max_carryover_minutes": 4800 | null, "is_active": false }   # is_active OPTIONAL,
                                                                 #   defaults to the current value
  → 200 { data: { id, office_id, name, code, is_paid, requires_attachment, deducts_balance,
                  is_cash_convertible, max_carryover_minutes, is_active } }
  → 400 validation_failed
  → 404 not_found     # {leaveType}'s office is out of the caller's scope, or {leaveType} doesn't exist
```

**There is no `office_id` in the update body** — a type's office is fixed at creation, the
same way a holiday's `date` is — and **there is no `DELETE` route at all**: a type is
retired via `PATCH is_active: false`, never removed, so a historical grant against it never
points at a vanished row.

### The leave day

```
PATCH /api/v1/office/leave-day                    # auth:sanctum — HR Admin/System Admin, scoped
  { "office_id": "0199…", "minutes_per_leave_day": 480 }
  → 200 { data: { id, minutes_per_leave_day } }    # id is the office's own id
  → 400 validation_failed     # minutes_per_leave_day missing or < 1
  → 404 not_found             # office_id is out of the caller's scope, or doesn't exist
```

`minutes_per_leave_day` is the divisor `LeaveUnit::toMinutes` uses to turn a `'day'` or
`'half_shift'` grant amount into stored minutes (below) — write-and-echo-back only, like
`/office/default-template` (M4b): there is no `GET` for the office's current value.

### HR manual grants

```
POST /api/v1/leave/grants                         # auth:sanctum — HR Admin/System Admin, scoped
  { "employee_id": "0199…", "leave_type_id": "0199…", "amount": 5,
    "unit": "day" | "half_shift" | "hour" | "minute", "reason": "Approved by manager" }
  → 201 { data: { id, employee_id, leave_type_id, entry_type: "credit", minutes, reason,
                  source: "manual_grant", created_by, created_at } }
  → 400 validation_failed          # bad shape, amount not a positive integer, or unit outside the four values
  → 404 not_found                  # employee_id/leave_type_id out of the caller's scope, or don't exist
  → 422 leave_type_not_grantable    # leave_type_id has deducts_balance: false; details: { leave_type_id }
```

One `leave_ledger` credit row per call — grants are never edited, only ever re-credited
with a second row. **Scoped by `OfficeScope::administers` against the employee's current
office, not `EmployeeScope`** — deliberately narrower than the balance reads below: a
manager may *view* a direct report's balance, but only HR may credit one, so a manager
hitting this endpoint for their own report gets the same `404` as for a stranger.
`amount`/`unit` are converted to minutes by `LeaveUnit::toMinutes` against the office's
`minutes_per_leave_day` before the row is written — the client sends what an HR admin
typed, never pre-converted minutes. `leave_type_not_grantable` fires for an **event** type
(`deducts_balance: false` — Maternity, Paternity, and the like): an event type is recorded,
not banked, so there is no balance for a manual grant to credit; the scope `404` always
runs first, so this is only ever reachable for a type in an office the caller already
administers.

### Balances — derived, never stored

```
GET /api/v1/me/leave                              # auth:sanctum — the caller's own balances
  → { data: [ { leave_type: { id, office_id, name, code, is_paid, requires_attachment,
                               deducts_balance, is_cash_convertible, max_carryover_minutes,
                               is_active },
                balance_minutes, balance_readable: { days, hours, minutes } }, … ] }
  → 422 not_an_employee    # the caller has no linked employee record
```

```
GET /api/v1/employees/{employee}/leave            # auth:sanctum + EmployeeScope, scoped
  → { data: [ <same balance row shape as above>, … ] }
  → 404 not_found          # {employee} is out of the caller's scope, or doesn't exist
```

One row per **active, balance-deducting** leave type in the employee's current office
(name-ordered) — an inactive or event type never appears here at all, and a type with no
`leave_ledger` rows yet still appears with `balance_minutes: 0` (an absent ledger entry
means zero, never "the type doesn't exist"). `balance_minutes` is summed fresh from
`leave_ledger` on every call — there is no stored balance column to go stale.
`balance_readable` is `App\Domain\Leave\LeaveUnit::readable()`'s day/hour/minute
decomposition of that same total against the office's `minutes_per_leave_day`; a client
renders it directly and never re-derives it from `balance_minutes` itself. The
employee-scoped variant is a **broader** read than granting above — `EmployeeScope`, not
`OfficeScope::administers` — so a manager can see a direct report's balance even though
only HR may credit it; both routes share the 404-not-403 discipline, an out-of-scope
subject indistinguishable from a nonexistent one.

## Leave requests — filing and the two-hop approval machine *(M6b-b)*

An employee filing their own leave against a type from the catalog above, and the first
widening of the `requests` state machine since M6a: `pending → [manager_approved →]
approved | rejected | cancelled`, the intermediate hop reachable only by a
`RequestType::requiresHrStep()` type (`leave`, today). Decision, queue, and cancel all stay
on the exact `/requests/*` / `/team/approvals` / `/office/approvals` routes M6a shipped —
see "Attendance adjustments and the requests spine," above, for the shared mechanics; this
section covers what's new: submission, and the two-hop decision routing.

### Submit

```
POST /api/v1/leave/requests          # auth:sanctum — any employee, for their own record
  { "leave_type_id": "0199…", "start_date": "2026-09-28", "end_date": "2026-09-30",
    "day_part": "full" | "half", "note": "string, REQUIRED",
    "attachment": file, OPTIONAL — pdf/jpg/jpeg/png, ≤10MB }
  → 201 { data: <request, below> }
  → 400 validation_failed                    # bad shape, or end_date before start_date
  → 404 not_found                            # leave_type_id is out of the caller's own
                                              #   current office, or doesn't exist
  → 422 not_an_employee                      # the caller has no linked employee record
  → 422 leave_type_inactive                  # the type has been retired (is_active: false)
  → 422 leave_attachment_required            # the type requires_attachment and none was sent
  → 422 leave_request_has_no_working_days    # the [start_date, end_date] range spans zero
                                              #   scheduled working days (e.g. all rest days)
```

Not admin-gated (any signed-in employee files for themself, mirroring
`POST /attendance/adjustments`) and not behind the `idempotent` middleware — a considered
one-off filing, not a retryable network event. The `leave_type_id` is resolved **scoped to
the employee's own current office**, never a caller-supplied office, so a foreign-office
type 404s exactly like a nonexistent one (404-not-403) — `leave_type_inactive`/
`leave_attachment_required` are only ever reachable for a type the caller already has
visibility into, since the scope check runs first.

**`amount_minutes` (in the detail shape below) is never client-supplied.** It's derived
from `App\Domain\Leave\LeaveDays::scheduledWorkingDays()` — the scheduled working days the
`[start_date, end_date]` range actually spans, a rest day inside the range never counted —
times the office's `minutes_per_leave_day` (`day_part: "half"` halves the per-day rate). A
range with zero scheduled working days is refused outright
(`leave_request_has_no_working_days`) rather than filing a request that would debit
nothing.

**The initial state depends on whether the filer has a manager on file.** An employee with
a `current_reports_to_id` starts `pending` (hop 1, the manager's); an employee with none
(`current_reports_to_id` null) starts **`manager_approved`** — there is no hop-1 approver to
wait on, so the request begins already at HR's hop rather than sitting `pending` with no
one ever authorized to clear it.

The request/detail shape returned by submit, and by every read:

```json
{ "data": {
  "id": "0199…", "type": "leave", "state": "pending",
  "note": "Family trip", "employee_id": "0199…",
  "detail": { "leave_type_id": "0199…", "start_date": "2026-09-28",
              "end_date": "2026-09-30", "day_part": "full", "amount_minutes": 1440 },
  "decided_by": null, "decided_at": null, "decision_note": null,
  "has_attachment": false
} }
```

Same envelope shape as an `attendance_adjustment` request (above) — only `detail`'s inner
fields differ by type, per `RequestResource`'s type branch.

### The two-hop decision routing

Decisions run through the exact same `/requests/{request}/approve|reject|cancel` routes
M6a shipped (above) — no new endpoints — but **which actor may call `approve` depends on
the request's current hop**, per `App\Domain\Requests\RequestAuthority::canDecide`:

| State | Who may `approve` | What happens |
| --- | --- | --- |
| `pending` | The requester's manager, only | → `manager_approved`. `manager_decided_by`/`manager_decided_at` set; `decided_by`/`decided_at` stay null; **no effect fires — the ledger is untouched.** |
| `manager_approved` | An HR admin of the requester's office, only — and NEVER the same user who decided hop 1 | → `approved`. `decided_by`/`decided_at` set; **`LeaveEffect` fires now** — see below. |
| `approved`/`rejected`/`cancelled` | (terminal) | `409 request_not_pending` for anyone who had authority at some hop; `404` for a stranger — same existence-leak ordering as a single-hop request. |

`reject` is callable at either hop by whichever actor could `approve` at that hop, and
always lands on `rejected` with no effect ever dispatched, exactly like a single-hop
reject. `cancel` (requester-only) works from `pending` OR `manager_approved` — a leave
request awaiting HR is never stuck just because a manager already signed off on it.

**Queue placement moves with the hop**, per `App\Domain\Requests\ApprovalQueues` (unchanged
in shape from M6a): a `pending` leave request is on `/team/approvals` and NOT
`/office/approvals` (HR has no authority yet); once `manager_approved`, it drops off
`/team/approvals` and appears on `/office/approvals` (a single-hop type, by contrast,
appears on `/office/approvals` from the moment it's `pending`).

**`LeaveEffect` is `leave`'s `RequestEffect`, and it only ever runs on the final (HR) hop:**

```
POST /api/v1/requests/{request}/approve   # HR's hop on a leave request
  → 200 { data: <request, state: "approved", decided_by, decided_at> }
  → 422 insufficient_leave_balance   # would debit more than the employee's derived balance;
                                      #   details: { leave_type_id, requested_minutes,
                                      #              available_minutes }; the whole approval
                                      #   rolls back — the request stays manager_approved
```

For a `deducts_balance` type it debits `leave_ledger` exactly `detail.amount_minutes`
(`source: "leave_taken"`, `request_id` set), never before this hop and never for a rejected
or cancelled request at either hop. An **event** type (`deducts_balance: false`) never
touches the ledger at all — only its span gets recomputed. Either way, the approved date
range is recomputed after commit; once that queued recompute drains, each day in the span
that has no punches, isn't a rest day, and has scheduled minutes prices as a
`leave_with_pay` line at a flat 100% (`applied_bp: 10000`), `rule_version_id` staying null
on that day even if a `pay_rules` version is configured — it was never used to price the
line. See `02-data-model.md` for the full compute-integration detail.

`scripts/e2e-leave.sh` proves the whole chain live: HR grants a balance, the employee
files a 3-scheduled-working-day request, it shows up on the manager's queue and not HR's,
the manager's decision moves it to `manager_approved` with the balance and the raw
`leave_ledger` debit-row count completely unchanged and moves it onto HR's queue, HR's
final decision is what debits the ledger and triggers the recompute, and a second,
independent request that is instead rejected at HR's hop leaves the balance and the
debit-row count untouched.

## Overtime — pre-authorization *(M6c)*

Overtime is the third request type on the shared spine, after attendance adjustments and
leave. It is **single-hop** (`RequestType::Overtime->requiresHrStep()` is `false`), so it
routes exactly like a single-hop attendance adjustment, not like two-hop leave: a pending
request is on the manager's `/team/approvals` AND office HR's `/office/approvals` from the
moment it is filed, and the single approval lands it `approved`.

### Submit

```
POST /api/v1/overtime/requests    # auth:sanctum — any employee, for their own record
  body: { "date": "2026-11-09", "hours": 1, "note": "Covering the month-end close" }
  → 201 { data: <request, type: "overtime", state: "pending", detail: { date, minutes }> }
  → 400 validation_failed   # date missing/not a date, hours missing/≤0, or hours not a whole
                            #   number of minutes (e.g. 1.01h); note missing
  → 422 not_an_employee     # caller has no linked employee record
```

`hours` is client-facing quarter-hour granularity; the controller converts it to the integer
`minutes` the domain stores (`1` → `60`), and rejects any value that would not land on a
whole minute rather than silently rounding. The detail shape is `{ date, minutes }` — the
same envelope every request read returns, only `detail`'s inner fields differing by type per
`RequestResource`'s branch. Filing is deliberately not admin-gated: any employee files their
own, exactly like `POST /attendance/adjustments` and `POST /leave/requests`.

### Decision, and what approval does

Decisions run through the **same** `/requests/{request}/approve|reject|cancel`,
`/team/approvals`, `/office/approvals`, `/requests`, and `/requests/{request}` endpoints M6a
shipped — no new decision or list routes. Because overtime is single-hop, either the manager
(`/team`) or an HR admin of the requester's office (`/office`) may approve, and that one
approval is final:

```
POST /api/v1/requests/{request}/approve   # a single-hop overtime approval
  → 200 { data: <request, state: "approved", decided_by, decided_at> }
```

**`OvertimeEffect` writes nothing** — no ledger, no balance, no lock (unlike `LeaveEffect`).
The approved request plus its `overtime_details.minutes` IS the authorization; the effect
only enqueues a recompute of the authorized date after commit. Once that queued recompute
drains, the day re-prices under the cap: `overtime_day`/`overtime_night` lines totalling
`min(actual_overtime, approved_minutes)`, with anything worked beyond that in
`daily_attendance_summaries.unpaid_overtime_minutes` (the summary read above). A day with no
approved overtime pays zero and books its full overtime as unpaid — the strict model.

`scripts/e2e-leave-and-ot.sh` proves it live: a long day read before any request pays zero
overtime and books it all unpaid; a 1-hour pre-authorization appears at once on both the
manager's and HR's queues, and the manager's single approval re-prices the day to exactly
the approved cap with the excess dropping by that amount, no ledger touched; a second
identical day with no request pays zero overtime and books its full overtime as unpaid; and
`scripts/e2e-leave.sh` runs unchanged alongside it, proving the two paths coexist.

## Office — cutoffs *(M7a)*

Closing a semi-monthly cutoff period freezes its numbers: every in-period daily summary flips
to `locked` and further approvals onto those days are refused. All three routes are gated by
`OfficeScope` (the `cutoff.manage` verb + an `hr_admin_offices` row for the office); an
out-of-scope or nonexistent office is `404`, indistinguishable (the 404-not-403 rule).

### List

```
GET /api/v1/office/cutoffs?office={officeId}
  → 200 { data: [ <period>, … ] }
```

Returns the office's stored periods, oldest first. **The current still-running window is
synthesized** when no row exists for it yet (`id: null`, `state: "open"`) — a period row only
comes into being once the window is closed, so this keeps "now" from ever being a gap. A
period object:

```json
{ "id": "0199…",            // null for the synthesized current window
  "office_id": "0199…",
  "start_date": "2026-07-01",
  "end_date": "2026-07-15",
  "state": "open",           // "open" | "closed"
  "closed_by": "0199…",      // null while open
  "closed_at": "2026-07-15T09:00:00+00:00" }  // null while open
```

### Close

```
POST /api/v1/office/cutoffs/close
  { "office_id": "0199…", "period_start": "2026-07-01" }
  → 200 { data: <period, state: "closed"> }
```

`period_start` must be a window boundary — the **1st** or the **16th** (`CutoffCalendar`);
anything else is `422 invalid_cutoff_start`. The close runs a **strict exception gate** first:
if any in-period day is `is_incomplete`, or any non-terminal (`pending`/`manager_approved`)
request's effect maps onto an in-period date, it refuses with `422
cutoff_has_unresolved_exceptions` and `details` naming exactly what to resolve:

```json
{ "error": { "code": "cutoff_has_unresolved_exceptions",
  "details": { "incomplete_dates": ["2026-07-24"], "pending_request_ids": ["0199…"] } } }
```

Otherwise it flips every in-period `daily_attendance_summaries.status` to `locked` and the
period to `closed`, in one transaction. Closing an already-closed period is `409
cutoff_already_closed`.

**Once closed, `POST /requests/{request}/approve` refuses on the final hop** any approval
whose effect would change an in-period day, with `422 cutoff_locked` and `details.date` the
locked day — the request stays in its prior state (nothing half-applies). The remedy is to
reopen the period, never to force the approval through.

```json
{ "error": { "code": "cutoff_locked", "details": { "date": "2026-07-10" } } }
```

### Reopen

```
POST /api/v1/office/cutoffs/{period}/reopen
  { "reason": "Correcting a mis-keyed punch found after close." }
  → 200 { data: <period, state: "open"> }
```

The inverse of close: the period goes back to `open`, every in-period `locked` summary back to
`computed`, and the reopen is loudly audited (a `cutoff_reopened` activity-log entry carrying
the `reason`, which is **required** — a blank reason is `400 validation_failed`). Reopening a
period that is not `closed` is refused with `409 cutoff_not_closed`. `{period}` binds by id; a
period whose office the caller doesn't administer is `404`, the same as a nonexistent one.

`scripts/e2e-cutoffs.sh` proves the whole flow live: the exception gate refuses a window
holding an incomplete day; a clean window closes and freezes its summaries; re-close and a
non-boundary start are refused; a manager's approval onto a locked day is refused
`cutoff_locked`; and reopening restores the summaries and lets that same approval succeed —
with the raw `attendance_logs` byte-identical throughout.

## Office — payroll export *(M7b)*

```
GET /api/v1/office/cutoffs/{period}/export
  → 200 { data: { period, employees: [ <employee export>, … ] } }
```

The frozen numbers of a **closed** period, rolled up per employee into an earnings breakdown
in **integer minutes + basis points** — the hours the compute engine already priced, grouped
for payroll to multiply by a rate. It is deliberately **not pesos**: `base_rate_cents` and
`base_rate_segments` ride along as *reference only*, so gross-to-net lives downstream, not
here. Gated by `OfficeScope` exactly like the cutoff routes; `{period}` binds by id and a
period whose office the caller doesn't administer — or that doesn't exist — is `404` (the
404-not-403 rule), never a 403.

**Closed-only.** An export is defined only for a finalized period; exporting an `open` one
(including the synthesized current window, or a period just reopened) is refused `422
period_not_exportable` with `details.state`:

```json
{ "error": { "code": "period_not_exportable",
  "details": { "cutoff_period_id": "0199…", "state": "open" } } }
```

The response:

```json
{ "data": {
  "period": { "id": "0199…", "office_id": "0199…",
              "start_date": "2026-07-01", "end_date": "2026-07-15", "state": "closed" },
  "employees": [
    { "employee": { "id": "0199…", "employee_no": "MNL-0002", "base_rate_cents": 85000 },
      "base_rate_segments": [ { "effective_from": "2026-07-01", "base_rate_cents": 85000 } ],
      "totals": { "worked_minutes": 5940, "late_minutes": 0,
                  "undertime_minutes": 0, "unpaid_overtime_minutes": 0 },
      "lines": [
        { "kind": "regular_day", "applied_bp": 10000,
          "rule_version_id": "0199…", "minutes": 5400 },
        { "kind": "regular_day", "applied_bp": 13000,
          "rule_version_id": "0199…", "minutes": 540 } ],
      "has_incomplete_days": false } ] } }
```

Each `lines[]` entry is a `(kind, applied_bp, rule_version_id)` triple with its summed
`minutes` — `rule_version_id` is the parent day's version (a day's summary lines don't carry
one of their own), so a rate change mid-period splits into distinct lines. The export reads
its summaries by **period membership** (`office_id` + the period's date range), *not* by the
`status = 'locked'` label, so a leaked `computed` row or an incomplete day still appears;
`has_incomplete_days` flags an employee whose in-period window holds an `is_incomplete` day.
`base_rate_segments` lists the distinct effective employment records that priced the in-period
days, oldest first; `base_rate_cents` is the period-end effective rate. The totals
(`worked`/`late`/`undertime`/`unpaid_overtime`) are the summed day scalars.

Because a closed period's numbers are frozen, the export is **reproducible** — two calls
return a byte-identical `data` payload. `scripts/e2e-payroll-export.sh` proves this live: it
closes a clean window, exports it, and asserts every line and total **reconciles exactly**
against the employee's own `/me/attendance/summary` calendar summed over the in-period dates
(the line-for-line guarantee); that a re-export is byte-identical; and that reopening the
period makes the export refuse `422 period_not_exportable`.

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
| 409 | `pay_rule_exists` | Creating a pay-rule version whose `effective_from` matches one that already exists. |
| 409 | `cutoff_already_closed` | Closing a cutoff period that is already `closed` (M7a). |
| 409 | `cutoff_not_closed` | Reopening a cutoff period that is not `closed` (M7a). |
| 422 | `employee_already_has_login` | Provisioning a second login for an employee. |
| 422 | `employment_record_exists` | Recording a second employment change for the same employee on the same `effective_from`. |
| 422 | `not_an_employee` | A logged-in user with no linked employee record trying to self-punch, read their own attendance, submit/list their own adjustments, or read their own leave balances. |
| 422 | `cannot_punch_self` | An HR/admin using the manual entry endpoint to record their *own* punch (separation of duties). |
| 422 | `invalid_adjustment_target` | Approving a `void`/`amend` whose target punch is missing, belongs to someone else, or was already annulled by an earlier approval. |
| 422 | `leave_type_not_grantable` | Manually granting into a leave type whose `deducts_balance` is `false` — an event type banks no balance to credit (`details.leave_type_id`). |
| 422 | `leave_type_inactive` | Filing a leave request, or manually granting, against a retired (`is_active: false`) leave type (`details.leave_type_id`). |
| 422 | `leave_attachment_required` | Filing a leave request against a type with `requires_attachment: true` and no file attached (`details.leave_type_id`). |
| 422 | `leave_request_has_no_working_days` | Filing a leave request whose `[start_date, end_date]` range spans zero scheduled working days (e.g. it falls entirely on rest days). |
| 422 | `insufficient_leave_balance` | A leave request's final (HR) approval would debit more minutes than the employee's derived balance holds (`details.leave_type_id`/`requested_minutes`/`available_minutes`); the approval rolls back, the request stays `manager_approved`. |
| 422 | `pay_rate_below_floor` | Creating a pay-rule version with one or more cells below `config('hris.pay_floors')` (`details.violations` names every offending cell). |
| 422 | `invalid_cutoff_start` | Closing a cutoff with a `period_start` that is not a window boundary — the 1st or the 16th (M7a). |
| 422 | `cutoff_has_unresolved_exceptions` | Closing a cutoff while an in-period day is `is_incomplete` or a non-terminal request maps onto an in-period date (`details.incomplete_dates`/`pending_request_ids`) (M7a). |
| 422 | `cutoff_locked` | Approving a request whose effect would change a day in a `closed` cutoff period (`details.date`); reopen the period instead (M7a). |
| 422 | `period_not_exportable` | Exporting a cutoff period that is not `closed` — an export is defined only for a finalized period (`details.cutoff_period_id`/`state`) (M7b). |
| 429 | `too_many_requests` | Login rate limit (5/min per email+IP) exceeded. |
| 500 | `internal_error` | An uncaught bug (outside debug; in debug Laravel's own page surfaces). |

The `403`-vs-`404` split is load-bearing: an actor refusal is `forbidden` (you may not do
this kind of thing), an out-of-scope subject is `not_found` (this may as well not exist, for
you). See `05-rbac.md`.

## What is not here yet

**Cutoffs and locking shipped in M7a** (above, under "Office — cutoffs"): close, reopen, and
the `cutoff_locked` approval refusal. **Payroll export shipped in M7b** (above, under "Office
— payroll export"): the per-employee earnings breakdown of a closed period, reconciling
line-for-line against the calendar — **M7 (cutoffs and export) is now complete**. Holidays
(M4a), schedules (M4b), and pay rules (M4c) shipped the configuration
spine; M5a's compute engine (above) now reads all three to price a day — **M5a is
complete**. **M6b-a shipped the leave foundation** (leave-type config, the office leave
day, HR manual grants, and derived balance reads, all above under "Leave — foundation"),
and **M6b-b shipped the leave request itself and the two-hop approval machine** (above,
under "Leave requests") — **M6 (requests and approvals) is now complete**. Overtime
pre-authorization (M6c, next) reuses the exact same `requests` spine and the two-hop
machinery leave just proved out, plugging in as a new `RequestType` and `RequestEffect`
rather than a new endpoint shape.

The **device ingestion contract** for biometric hardware is exposed, not built: the punch
payload already accepts `source`, `device_id`, `geo_lat`/`geo_lng`, and an idempotency key —
the shape a device's middleware would POST — but device *authentication* (a device registry
+ per-device tokens) and *batch* ingestion defer with the hardware that needs them. Both live
punch paths are Sanctum-authed today; adding a device later is a new auth guard and a
`source: device` path into the same `RecordPunch`, no schema change and no new writer.
