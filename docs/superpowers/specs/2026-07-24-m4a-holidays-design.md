# M4a — Holiday Calendars — Design

Date: 2026-07-24

The first slice of M4 (the configuration spine). Holidays are the simplest of the three
config domains, so they land first and establish the patterns the others copy: per-office
config, an office-scoping boundary, the config-screen shape on a generalized calendar, and
the first real use of the activity log.

## Where this fits

M4 as the roadmap wrote it — holidays, schedules, `pay_rules`, `RecomputeRange`, three
screens — is three independent config subsystems plus shared infrastructure, larger than
any single milestone shipped so far. It is sliced into **M4a Holidays**, **M4b Schedules**,
**M4c Pay rules**, each its own spec → plan → PR. **`RecomputeRange` moves to M5**, where the
compute engine lives: the invariant it protects ("config changes never silently mutate
computed history") has nothing to compute against until M5 exists, so a queue that recomputes
nothing would be speculative. M4's job is to store config that is effective-dated and
audited; M5 owns "what changed, recompute it." This also dissolves the roadmap's stale M4
done-when, which referenced pay multipliers flipping — pure M5.

M4a consumes what M1–M3.6 built (`DayType`, offices, `hr_admin_offices`, the activity-log
table with its now-uuid `subject_id` morph) and produces the `holidays` table M5's engine
will read. It computes no pay.

## Decisions settled in brainstorming

| Decision | Choice | Why |
| --- | --- | --- |
| M4 shape | Slice into M4a/b/c; defer `RecomputeRange` to M5 | Keep each milestone a tight review cycle; nothing to recompute pre-M5 |
| Holiday model | Per-office flat list of `(date, day_type, name)` rows | Matches the roadmap's "per office / Manila only"; clone handles repetition; simplest lookup for the engine |
| Calendar reuse | Generalize `MonthCalendar` to a per-day render function | The "three screens, one component" reason it exists; attendance and holidays share the grid |
| Scope | HR admin over their offices; system admin any | Config is office-owned; the M4 analogue of `EmployeeScope` |
| Refusals | 404, byte-identical to nonexistent, for out-of-scope | The enumeration-leak lessons the audit just fixed |

## 1. Data model

**One table, `holidays`:**

```
holidays
  id           uuid pk (uuidv7)
  office_id    uuid → offices          -- the granularity; each office owns its rows
  date         date                    -- a calendar date (not timestamptz)
  day_type     text                    -- CHECK IN ('special_working','special_non_working',
                                        --           'regular_holiday','double_regular_holiday')
  name         text                    -- "Ninoy Aquino Day", "Araw ng Kagitingan / Good Friday"
  created_at, updated_at
  unique (office_id, date)             -- at most one holiday per office per day
```

- **`day_type` excludes `Ordinary`.** A holiday is by definition non-ordinary; Ordinary is
  the absence of a row. The CHECK enforces the four holiday types, and the value list is
  a subset of `DayType`'s cases (a test asserts every CHECK value is a real `DayType` case).
- **`DoubleRegularHoliday`** is a single row an admin sets when two regular holidays
  coincide; `unique(office, date)` means the coincidence is expressed as the type, not two
  rows.
- **`date` is a plain `date` column**, not `timestamptz` — a holiday is a calendar date in
  the office's own reckoning, with no instant or timezone, consistent with the wire rule
  that calendar dates are `YYYY-MM-DD` strings.
- **`Holiday` model** — `HasUuids` + uuid7 override; casts `day_type` → `DayType`, `date` →
  `date`; `belongsTo(Office)`; uses spatie's `LogsActivity` (see §2).

## 2. Backend

**`OfficeScope` — the M4 analogue of `EmployeeScope`.** A query/boundary answering "which
offices may this user administer": a system admin → all offices; an HR admin → their
`hrAdminOffices`; anyone else → none (an empty guard, `WHERE 1=0`, not "all"). This is the
shared boundary for holidays now and schedules in M4b. `pay_rules` is system-admin-only, so
it doesn't use it.

**Endpoints — one action class each (one action = one route = one invokable controller =
one FormRequest = one resource), all under `/office`:**

```
GET    /office/holidays?office={id}&year={YYYY}   list an office's holidays for a year
POST   /office/holidays                            create {office_id, date, day_type, name}
PATCH  /office/holidays/{holiday}                  edit {day_type, name}
DELETE /office/holidays/{holiday}                  delete
POST   /office/holidays/clone                      {office_id, from_year, to_year}
```

**Refusals follow the audit's enumeration-leak fixes.** An office or holiday the user does
not administer returns **404**, byte-identical to a nonexistent one — no existence oracle.
Concretely: **no unscoped `exists:offices,id`** on `office_id` (it would leak via 400-vs-404);
instead `office_id` is validated against the user's administered offices (or checked in the
action, 404 uniformly), and `{holiday}` route-binding is followed by an office-scope check
that 404s a holiday whose office the user doesn't administer the same as a missing one.

**Activity log — the first real use.** `Holiday` uses spatie's `LogsActivity`; create, edit,
delete, and clone each write an activity row with the **causer** (the acting user) and the
holiday as **subject**. This is what the audit's `subject_id`→uuid fix unblocked; a test
asserts the causer id and the uuid subject id land correctly.

**Clone** is its own action: it reads year *N−1*'s rows for the scoped office and writes each
to the same month/day in year *N*, **skipping any target date already present** (non-
destructive, re-runnable), and logs one activity summarizing the clone. It does not guess
where movable holidays (Eid, long-weekend proclamations) landed — the admin adjusts those.
Returns the created set.

The **"apply to all my offices"** convenience stays a frontend affordance — the screen
iterates the single-office create across the admin's offices — rather than a bulk endpoint,
keeping each action single-purpose. A bulk endpoint is a fast-follow only if the round-trips
bite.

## 3. The screen

**Generalize `MonthCalendar`.** Today it renders attendance punches. It becomes content-
agnostic: it owns the grid, weekday headers, and the uniform fixed-height cells, and takes a
**per-day render function** (`renderDay: (date, ...) => ReactNode`) for the cell content.
Attendance migrates to pass its punch renderer — the current `DayCell` becomes one such
renderer, unchanged in behaviour — and holidays pass a holiday renderer. This is a clean
extract (grid shell vs. cell content), and the existing attendance tests must stay green as
proof it is behaviour-preserving.

**`/office/holidays`** (under the Office scope; `navEntriesFor` shows it only to users with
`hr_offices.length > 0` or system admins):

- An **office picker** across the admin's administered offices (a single-office admin sees
  none — just their office); month navigation, with the year derived from the viewed month.
- Each day cell shows the holiday **name** + a **`DayTypeTag`** — the deferred domain
  component, earning its place: a monochrome Carbon tag for the day type, reused later by
  schedules and M5's attendance view.
- **Click a day → add/edit** in a small **`Dialog`**: a name field, a day-type **`Select`**,
  the date. Click an existing holiday to edit or delete. `Dialog` and `Select` are the two
  remaining deferred UI primitives, Radix-backed for accessibility, earning their place here.
- A **"Clone from {last year}"** action calling the clone endpoint for the viewed office/year.
- Data layer: `useHolidays(office, year)` and create/update/delete/clone mutations, keyed
  through `lib/keys.ts` and invalidating the office/year query on write.

**Mobile-nav collapse lands here.** `/office/holidays` is the first Office-scope route, so the
sidebar now has two populated groups. Below `md`, the Carbon UI-shell pattern: a header
hamburger toggles the side-nav as an overlay; above `md`, the persistent rail as today. This
is the collapse deferred from M3.5, now that there is enough nav to warrant it.

## 4. Testing & done-when

Real Postgres, never SQLite. Office-scope leak tests assert byte-identical responses.

- **`OfficeScope`** — sysadmin → all, HR admin → their offices, plain employee → none (empty
  guard produces `WHERE 1=0`, not everything).
- **CRUD scoping** — an out-of-scope office/holiday and a nonexistent one return byte-
  identical 404s (the audit's leak-closure assertion, reused).
- **Schema** — the `day_type` CHECK rejects `ordinary` and any non-holiday value, proven by a
  raw insert; `unique(office, date)` rejects a second holiday on the same day; the CHECK value
  list matches the holiday subset of `DayType`.
- **Clone** — copies same month/day into the target year, skips dates already present, and is
  re-runnable without duplicating.
- **Activity log** — create/edit/delete/clone each write an activity row with the correct
  causer and the holiday's uuid as subject (proving the morph end to end).
- **Frontend** — the `MonthCalendar` generalization leaves the existing attendance tests
  green; holiday cells render name + `DayTypeTag`; the add/edit dialog and clone action drive
  the mutations and invalidate; the Office nav group appears for an HR admin and the hamburger
  toggles the rail below `md`.

**Done when:** an HR admin for Manila adds Ninoy Aquino Day (Aug 21) as a special-non-working
holiday for the Manila office; it shows on Manila's `/office/holidays` calendar and not on
Cebu's; a Cebu-only HR admin gets a 404 — not 403, and identical to a nonexistent one —
touching a Manila holiday; "Clone from 2025" copies last year's Manila set into 2026 as an
editable draft, skipping dates already present; and the activity log names who added it and
when, the subject landing correctly in the uuid morph. **No pay is computed** — the holidays
table is simply the input M5's engine will read.

## Non-goals for M4a

Any compute or `RecomputeRange` (M5). Schedules and pay-rules (M4b/M4c). A two-tier national
calendar. A bulk apply-to-all endpoint (the frontend iterates). An employee-facing read-only
holiday view (add under `/me` later if wanted).

## Open question

None blocking. One to note for M4b: `OfficeScope` and the office-scoped 404 pattern established
here are the boundary schedules reuse — M4b should consume them, not reinvent a parallel one.
