# M4b — Shift Templates (design)

> The second slice of the configuration spine (M4a was holidays). Builds the **schedule
> layer**: the per-office data that resolves, for any `(employee, date)`, whether it is a
> rest day and how many minutes are scheduled. M5's compute engine is the first reader —
> **M4b computes no pay.** It fills the two inputs the M1 audit found built and waiting:
> `PayMultiplier`'s `isRestDay` and `OvertimeThreshold`'s scheduled minutes.

## Why this exists

`docs/00-overview.md:163`: *"Rest day — the employee's scheduled non-working day, resolved
from their schedule. Never a global Saturday/Sunday assumption; a night-shift or
compressed-week employee rests on other days, and premium pay turns on it."*

Today nothing in the system stores a schedule. `PayMultiplier::forWorkedTime(DayType,
bool $isRestDay, …)` takes rest-day status as a caller-supplied boolean, and
`OvertimeThreshold::split(worked, scheduled)` takes the scheduled day length — both are
pure, both are unit-tested (M1), and **both have no data source.** M4b is that source.

Two concrete shapes M4b must express:
- Mon–Fri 08:00–18:00, Sat/Sun rest.
- Mon–Sat 09:00–17:00, Sun rest.
- …and per-weekday variation, including a cross-midnight night shift (Tue 17:00→03:00).

## Decisions locked in brainstorming

1. **Weekly-pattern template.** A shift template *is* a named 7-day week; each weekday is
   either a working shift (hours + break) or an explicit rest day. Per-weekday hours may
   differ. Assign the whole week to a target.
2. **Resolution chain:** `override(this date)` → `employee assignment` →
   `department assignment` → `required office default template`. Every office has a default,
   so no employee is ever "unscheduled."
3. **`Weekday: int` enum, `Monday = 0`.** The backing value *is* the 0–6 index, lining up
   1:1 with the frontend's `weekdayIndex`. The one coded set stored as an int (not a
   string like `DayType`) because a weekday's identity genuinely is an ordinal.
4. **Rest is explicit**, not "absence of a row." A template has all 7 weekday rows; each
   carries an `is_rest` flag. A 7-day template is dense, fixed-domain data — unlike the
   sparse `holidays` calendar where "no row = Ordinary" is right — so explicitness prevents
   a half-filled template silently resolving as rest, and matches the override shape.
5. **Cross-midnight** rides the existing `WorkInterval` convention: `end_minute` may exceed
   1439. Tue 17:00→03:00 is `start=1020, end=1620`.

## Global constraints (inherited, non-negotiable)

- Laravel 13 / PHP 8.5 / PostgreSQL 18 backend; Next 16 / React 19 / TS / Tailwind v4 frontend.
- `declare(strict_types=1);` every PHP file in `app/` and `tests/`. Actions final, invokable
  controllers, actions own their transaction and never touch HTTP, Domain is HTTP-agnostic.
- **String columns + PHP backed enums + CHECK constraints; never native PG enums.** (Weekday
  is the deliberate int-backed exception, still a backed enum + CHECK.)
- **Integer minutes only** — never a float, in any layer.
- **Calendar dates on the wire are `YYYY-MM-DD` strings**, never `Date` objects.
- uuid v7 PKs (`uuidv7()`), uuid FKs, uuid morphs for activity_log.
- **404-not-403 enumeration discipline (M4a's spine, reused verbatim):** FormRequests are
  shape-only (`uuid`), never `exists:`; controllers resolve scope via `OfficeScope` and throw
  `NotFoundHttpException`, so an out-of-scope real id and a nonexistent one are byte-identical.
- Success `{data:…}` / error `{error:…}`, the closed envelope.
- Frontend: token-only styling (no raw hex outside `carbon.css`); every `font: var(--t-*)`
  has its `--ls-*` companion (except `--t-card-title`); `'use client'`; `import type`; no
  `enum`; dates are strings.
- Tests run against **real PostgreSQL, never SQLite.**
- Commit messages carry **no attribution trailers.**

---

## Section 1 — Data model

Four tables + one column, all per-office, all scoped by `OfficeScope`.

### `shift_templates`
A named reusable week.
- `id` uuid PK (`uuidv7()`), `office_id` uuid FK (cascade on delete), `name` text, timestamps.

### `shift_template_days`
The 7-day shape — **all seven weekday rows present** for a complete template.
- `id` uuid PK, `shift_template_id` uuid FK (cascade), `weekday` smallint (`Weekday` enum,
  `CHECK (weekday BETWEEN 0 AND 6)`), `is_rest` boolean, `start_minute` / `end_minute` /
  `break_minutes` smallint **nullable**, timestamps.
- `unique(shift_template_id, weekday)`.
- **CHECK — `is_rest` XOR hours:** a rest row has `is_rest = true` and all three minute
  columns null; a working row has `is_rest = false` and all three non-null.
- **CHECK on a working row:** `0 ≤ start_minute < 1440`, `start_minute < end_minute ≤
  start_minute + 1440`, `0 ≤ break_minutes < end_minute − start_minute`.
- The create/update action validates that **exactly the 7 distinct weekdays** are supplied,
  so completeness is guaranteed and the resolver never infers.
- Scheduled paid minutes = `(end_minute − start_minute) − break_minutes`.

### `schedule_assignments`
A template assigned to an employee **or** a department, effective-dated (latest-wins,
mirroring `employment_records`).
- `id` uuid PK, `shift_template_id` uuid FK, `employee_id` uuid FK **nullable**,
  `department_id` uuid FK **nullable**, `effective_from` date, `created_by` uuid FK
  (nullable), timestamps.
- **CHECK: exactly one of `employee_id`, `department_id` is non-null** (one table, real FK
  integrity, two levels).
- `unique(employee_id, effective_from)` and `unique(department_id, effective_from)` (partial,
  one target at a time) — a second assignment for the same target on the same effective date
  is a `422`, not a 500.
- No end date: an assignment is superseded by the next `effective_from` for that target. A
  future-dated assignment does not apply to earlier dates.

### `schedule_overrides`
A per-employee, per-date exception — the rest-day-swap tool.
- `id` uuid PK, `employee_id` uuid FK (cascade), `date` date, `is_rest` boolean,
  `start_minute` / `end_minute` / `break_minutes` smallint nullable, `note` text nullable,
  `created_by` uuid FK (nullable), timestamps.
- `unique(employee_id, date)`.
- Same `is_rest` XOR hours CHECK, and the same working-row minute CHECK, as a template day.
- "Work this Saturday, rest Monday instead" = two overrides.

### Office default
- New nullable column `offices.default_shift_template_id` uuid FK (nullOnDelete).
- Nullable in the DB (chicken-and-egg: a template needs its office to exist first), but the
  **resolver treats a missing default as a hard domain error** (`OfficeHasNoDefaultTemplate`),
  and the seeder always sets one — so in practice every office resolves.
- Setting the default requires both office and template in the caller's scope.

Every write (template, template-days, assignment, override, default) is activity-logged,
reusing M4a's `LogsActivity` wiring (uuid subject morph; a template logs itself, an
assignment/override logs itself, setting the default logs against the `Office`).

---

## Section 2 — The resolver

The point of M4b. `App\Domain\Schedule\ScheduleResolver` — a Domain query class (queries the
way `OfficeScope` / `EmployeeScope` do), the single interface M5 calls.

```
resolve(Employee $employee, string $date): ResolvedSchedule
```

Walks the chain:
1. `schedule_overrides` for `(employee, date)` → if present, use it (source `override`).
2. else the employee's `schedule_assignments` with the greatest `effective_from ≤ date`
   (source `employee`).
3. else the employee's **current department**'s assignment, greatest `effective_from ≤ date`
   (source `department`).
4. else the employee's **current office**'s `default_shift_template_id` (source
   `office_default`).

For a template hit (steps 2–4), it selects the weekday row via
`Weekday::from(weekdayIndex(date))`. For an override hit, it reads the override's own shape.
It returns an immutable value object:

```
ResolvedSchedule {
  bool           isRestDay
  ?int           startMinute        // null if rest
  ?int           endMinute          // may exceed 1439 (cross-midnight)
  ?int           breakMinutes       // null if rest
  int            scheduledMinutes    // (end − start) − break; 0 if rest
  ScheduleSource source              // enum: override | employee | department | office_default
}
```

- Pure read, **no transaction, no writes** — safe to call from a queued M5 job, a command, or
  an HTTP read.
- Throws `OfficeHasNoDefaultTemplate` only when step 4 finds no default (should never happen
  post-seed). An employee with no `current_office_id` cannot be resolved — a distinct domain
  error surfaced to HR, not a silent null.
- `scheduledMinutes` feeds `OvertimeThreshold`; `isRestDay` feeds `PayMultiplier`. `source`
  makes "why is this a rest day?" answerable in the UI and in tests.

`ScheduleSource` is a string-backed enum (`override`/`employee`/`department`/`office_default`).

---

## Section 3 — Endpoints

All `OfficeScope`-scoped, byte-identical 404, FormRequests shape-only. Assignments/overrides
target an employee or department; the controller resolves the target's office (via
`employee.current_office_id` / `department.office_id`) and checks `OfficeScope::administers`,
404-ing out-of-scope exactly like M4a.

### Templates
```
GET    /office/shift-templates?office=<uuid>        # list (name + a rest/working summary)
POST   /office/shift-templates                      # { office_id, name, days:[7] } → 201
GET    /office/shift-templates/{template}           # show, with the 7 days
PATCH  /office/shift-templates/{template}           # { name, days:[7] }
DELETE /office/shift-templates/{template}           # 204, or 422 template_in_use
```
`days` is exactly 7 entries: `{ weekday:0–6, is_rest:bool, start_minute?, end_minute?,
break_minutes? }`. Delete **refuses (`422 template_in_use`)** when the template is an office
default or has any assignment — a template in use is not silently removed.

### Assignments
```
GET    /office/schedule-assignments?office=<uuid>[&employee=<uuid>][&department=<uuid>]
POST   /office/schedule-assignments   # { shift_template_id, employee_id|department_id, effective_from } → 201
DELETE /office/schedule-assignments/{assignment}    # 204
```
Body carries **exactly one** of `employee_id` / `department_id` (400 otherwise). Duplicate
`(target, effective_from)` → `422 schedule_assignment_exists`.

### Overrides
```
GET    /office/schedule-overrides?office=<uuid>&employee=<uuid>&month=<YYYY-MM>
POST   /office/schedule-overrides   # { employee_id, date, is_rest|hours, note? } → 201
PATCH  /office/schedule-overrides/{override}   # { is_rest|hours, note? }
DELETE /office/schedule-overrides/{override}   # 204
```
Duplicate `(employee, date)` on POST → `422 schedule_override_exists`; editing is `PATCH`.

### Office default
```
PATCH  /office/default-template   # { office_id, template_id } — both in scope → 200 office
```
The template must belong to the office being defaulted (`template.office_id === office_id`),
so Cebu's template can never become Manila's default; a mismatch is a `404` on the template
(same discipline — the caller learns nothing about templates outside the office).

### Resolved read (the resolver over HTTP; also the screen's calendar source)
```
GET    /office/schedule/resolved?employee=<uuid>&month=<YYYY-MM>
  → { data: { "2026-08-01": { is_rest, start_minute, end_minute, break_minutes,
                              scheduled_minutes, source }, … } }   # every date in the month
  → 404 not_found                       # employee out of scope, or nonexistent
  → 422 office_has_no_default_template   # the office invariant is unmet
```
Scoped via the employee's office. This proves the resolver end-to-end and backs the screen's
calendar without the frontend re-implementing resolution.

---

## Section 4 — The screen (`/office/schedules`)

The third consumer of `MonthCalendar` (the reason it was generalized to `renderDay`). One
route, four regions, reusing M4a's `Dialog` / `Select` / `TextInput` / `DayTypeTag`-style
primitives:

1. **Templates** — a list of the office's templates; create/edit opens a Dialog holding a
   new **`WeekEditor`** component: seven rows (Mon–Sun), each a rest toggle plus
   start/end/break time inputs (disabled when rest). A minute↔`HH:MM` helper mirrors the
   existing `duration.ts` / `date.ts` style; end may pass midnight (a small "+1 day" hint).
2. **Office default** — a `Select` of the office's templates, wired to `PATCH
   /office/default-template`.
3. **Assignments** — assign a template to an employee or a department with an effective date
   (a Dialog: a target-type toggle, the target `Select`, a template `Select`, a date input).
   A list of current assignments with their effective dates; delete removes one.
4. **Resolved calendar** — pick an employee; a `MonthCalendar` whose `renderDay` shows each
   day's resolved state (rest, or the hours + a small `source` badge). Click a day to add or
   edit a per-date **override** (rest, or custom hours) in a Dialog. This is where a rest-day
   swap is performed and immediately visible.

Office picker driven by `session.hr_offices` (M4a's pattern). New frontend surface:
`keys.schedules`, `api.shiftTemplates` / `api.scheduleAssignments` / `api.scheduleOverrides` /
`api.resolvedSchedule` / `api.officeDefaultTemplate`; hooks `useShiftTemplates`,
`useScheduleAssignments`, `useScheduleOverrides`, `useResolvedMonth`, each with mutations that
invalidate by the relevant office/employee key. Nav: `ROUTES.office` gains
`{ href: '/office/schedules', label: 'Schedules' }` beside Holidays.

---

## Section 5 — Error handling

New `DomainException` subclasses (each one line in `docs/03-api.md`'s error table):
- `TemplateInUse` (422 `template_in_use`) — delete refused; details name whether it's the
  office default and/or the assignment count.
- `ScheduleAssignmentExists` (422 `schedule_assignment_exists`) — duplicate `(target,
  effective_from)`; details `{ target_type, target_id, effective_from }`. Mirrors
  `EmploymentRecordExists`.
- `ScheduleOverrideExists` (422 `schedule_override_exists`) — duplicate `(employee, date)`.
- `OfficeHasNoDefaultTemplate` (422 `office_has_no_default_template`) — the resolver
  invariant; details `{ office_id }`.
- `EmployeeHasNoOffice` (422 `employee_has_no_office`) — resolver called for an employee with
  no `current_office_id`.

Validation (400 `validation_failed`, via FormRequest + the DB CHECKs as backstop): the 7-day
completeness, `is_rest` XOR hours, the minute-range rules, exactly-one-of target, `date` /
`effective_from` shape, `month` shape. The unique-constraint races are caught and translated
to the `*_exists` domain errors the way `CreateHoliday` catches its unique violation.

---

## Section 6 — Testing

**Resolver (the crown jewel) — table-driven, real Postgres:**
- Chain precedence: override beats employee beats department beats office default (four
  cases, `source` asserted at each).
- Effective-dating: the greatest `effective_from ≤ date` wins; a future-dated assignment does
  not apply; a back-dated one does.
- Cross-midnight: Tue 17:00→03:00 → `endMinute = 1620`, `scheduledMinutes` correct.
- Compressed week: per-weekday differing hours resolve independently; a 4×10 day yields a
  600-minute scheduled day (so M5's overtime threshold is 10h, not 8h).
- Rest via a template `is_rest` row **and** rest via an override both yield `isRestDay:true,
  scheduledMinutes:0`.
- Office-default fallback when no assignment exists; `OfficeHasNoDefaultTemplate` when the
  office has none; `EmployeeHasNoOffice` when the employee has no office.

**Scoping / 404 (mirroring M4a):** templates, assignments, overrides, default-set, and the
resolved read all return byte-identical 404s for an out-of-scope vs fabricated id; FormRequests
carry no `exists:`.

**Guards:** delete-in-use → 422; duplicate assignment/override → 422; the exactly-one-of
target rule; the 7-day completeness rule.

**Frontend:** `WeekEditor` (rest toggle disables hours; minute↔HH:MM; a cross-midnight case),
the screen (template create/edit, assign, set default, the resolved calendar renders rest +
hours + source, click-a-day override flow), and each hook's fetch/invalidate. Component tests,
matching M4a's harness.

**`scripts/e2e-schedules.sh`** (mirroring `e2e-holidays.sh`), run live: create a template, set
it as the office default, assign a Mon–Fri 08:00–18:00 template to a seeded Manila employee,
`GET resolved` for a month and assert Sat/Sun rest + weekday hours + scheduled minutes, add a
template with a Tue night shift and assert `end_minute > 1439`, add an override that swaps a
Saturday to working and the next Monday to rest and assert both flip, confirm a Cebu-only HR
admin gets byte-identical 404s touching a Manila template, and read the activity log for the
causer + uuid subject.

**Seeder:** give the Manila and Cebu offices a default template and assign the seeded
employees, so `/office/schedules` and the resolved read are non-empty on `make dev`.

---

## Done when

An HR admin creates a Mon–Fri 08:00–18:00 (Sat/Sun rest) template for Manila, sets it as the
office default, and assigns it to Miguel effective a date; `GET
/office/schedule/resolved?employee=<Miguel>&month=2026-08` returns every August date with
Sat/Sun `is_rest:true, scheduled_minutes:0` and weekdays `08:00–18:00` at 540 scheduled
minutes (600 span − 60 break). A second template with Tue 17:00→03:00 resolves that Tuesday
to `end_minute:1620`. An override making one Saturday a working day and the following Monday a
rest day flips both in the resolved view, with `source:"override"`. A Cebu-only HR admin
touching a Manila template gets a `404` byte-identical to a fabricated id. The activity log
names who created the template and who assigned it, subjects landing in the uuid morph. **No
pay is computed** — `scheduled_minutes` and `is_rest` are produced for M5's engine to read.
`RecomputeRange` remains M5's. Full suite green (backend + arch + frontend), `e2e-schedules.sh`
passes live.
