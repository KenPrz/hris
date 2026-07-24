# M4a — Holiday Calendars Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Per-office holiday calendars an HR admin can edit and clone year-to-year, with the first activity-log wiring, on a `MonthCalendar` generalized to serve any per-day content.

**Architecture:** A flat `holidays` table (one row per office/date). An `OfficeScope` boundary (the M4 analogue of `EmployeeScope`) gates every read/write, and out-of-scope offices/holidays 404 byte-identically to nonexistent ones (the enumeration-leak discipline the audit established). `MonthCalendar` becomes content-agnostic via a `renderDay` prop; attendance migrates onto it, holidays reuse it. `Holiday` uses spatie's `LogsActivity` (the first real use of the now-uuid-safe activity log).

**Tech Stack:** Laravel 13 · PHP 8.5 · PostgreSQL 18 · spatie/laravel-activitylog 4 · Next 16 / React 19 · Radix (Dialog, Select) · Pest 4 · Vitest

**Spec:** `docs/superpowers/specs/2026-07-24-m4a-holidays-design.md` — read it first.

## Global Constraints

- **PHP 8.5 / Laravel 13 / PG 18 / Next 16 / React 19 — pinned.** `declare(strict_types=1);` atop every PHP file in `app/`, `database/`, `tests/` (an arch test enforces this over all three now).
- **uuid PKs `default(DB::raw('uuidv7()'))`; uuid FKs; uuid models override `newUniqueId()` → `Str::uuid7()`.**
- **String columns + PHP backed enums + `CHECK` constraints.** The `holidays.day_type` CHECK lists the four **non-`Ordinary`** `DayType` values; a test asserts every CHECK value is a real `DayType` case.
- **Calendar dates on the wire are `YYYY-MM-DD` strings.** `holidays.date` is a plain `date` column (no `timestamptz`, no timezone).
- **Office scoping is the boundary.** Every holiday read/write goes through `OfficeScope::administeredBy(User): Builder` (sysadmin → all offices; HR admin → their `hrAdminOffices`; else empty `WHERE 1=0`).
- **Refusals: an out-of-scope or nonexistent office/holiday returns 404, byte-identical.** No unscoped `exists:offices,id` on `office_id` (it leaks via 400-vs-404); validate `office_id` against the user's administered offices, and follow `{holiday}` binding with an office-scope check that 404s uniformly. This is the pattern PR #10 established — do not reintroduce the leak.
- **One action = one route = one invokable controller = one FormRequest = one resource.** Actions own their transaction and never touch HTTP.
- **Success is `{data}`; errors `{error:{code,message,details}}`.** 404 → `not_found`, 400 → `validation_failed`.
- **Frontend:** no raw hex/type step outside `carbon.css`; every `font: var(--t-*)` needs its `--ls-*` companion; keys come from `lib/keys.ts`; `crypto` calls go through the existing helpers; `import type` under `verbatimModuleSyntax`.
- **Tests run against real PostgreSQL, never SQLite.** Office-scope leak tests assert byte-identical responses.
- **Commit messages carry NO attribution trailers** — no `Co-Authored-By`, `Generated with`, session URL.

## File structure

```
backend/
  database/migrations/2026_07_27_000001_create_holidays_table.php
  app/Domain/Holidays/                       (nothing new — day_type reuses App\Domain\Pay\DayType)
  app/Domain/Scope/OfficeScope.php
  app/Models/Holiday.php
  database/factories/HolidayFactory.php
  app/Actions/Holidays/{CreateHoliday,UpdateHoliday,DeleteHoliday,CloneHolidays}.php (+ Inputs)
  app/Http/Controllers/Office/Holidays/{List,Create,Update,Delete,Clone}Controller.php
  app/Http/Requests/{CreateHolidayRequest,UpdateHolidayRequest,CloneHolidaysRequest}.php
  app/Http/Resources/HolidayResource.php
  config/activitylog.php                      (published)
frontend/web/src/
  components/domain/MonthCalendar.tsx         (generalized: + renderDay, - days)
  components/domain/DayTypeTag.tsx
  components/ui/{Dialog,Select}.tsx
  lib/keys.ts                                 (+ holidays keys)
  lib/api.ts                                  (+ holiday wire types + calls)
  hooks/useHolidays.ts
  app/(app)/office/holidays/page.tsx
  components/{AppShell,SideNav}.tsx           (mobile collapse + Office group)
```

---

### Task 1: `holidays` table, `Holiday` model, factory

**Files:**
- Create: `backend/database/migrations/2026_07_27_000001_create_holidays_table.php`
- Create: `backend/app/Models/Holiday.php`, `backend/database/factories/HolidayFactory.php`
- Test: `backend/tests/Feature/Schema/HolidaySchemaTest.php`

**Interfaces:**
- Consumes: `App\Domain\Pay\DayType` (M1), `Office` (M2).
- Produces: `App\Models\Holiday` — `HasUuids`, `LogsActivity`; columns `id, office_id, date, day_type, name`; casts `day_type`→`DayType`, `date`→`'date'`; `belongsTo(Office)`. Migration table `holidays` with `unique(office_id, date)` and a `day_type` CHECK of the four non-Ordinary values.

- [ ] **Step 1: Write the failing schema test**

`HolidaySchemaTest.php` — assert a `Holiday` round-trips its `day_type` enum and `date`; the `unique(office_id, date)` rejects a second holiday on the same office/day (raw `DB::table` insert → `QueryException`); the `day_type` CHECK rejects `'ordinary'` and `'nonsense'` (raw insert → `QueryException`) but accepts `'regular_holiday'`; and the CHECK's value list, read live via `pg_get_constraintdef`, equals the four non-Ordinary `DayType` cases (`special_working`, `special_non_working`, `regular_holiday`, `double_regular_holiday`). Follow `RequestSchemaTest.php`'s `pg_get_constraintdef` idiom.

- [ ] **Step 2: RED** — `cd /home/haru/projects/hris/backend && ./vendor/bin/pest tests/Feature/Schema/HolidaySchemaTest.php` → fails (`Holiday` missing).

- [ ] **Step 3: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Per-office holiday calendar. A holiday maps a calendar date to a non-Ordinary DayType;
 * Ordinary is the absence of a row. See docs/02-data-model.md. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('office_id')->constrained()->cascadeOnDelete();
            $table->date('date');                 // a calendar date, no timezone
            $table->text('day_type');
            $table->text('name');
            $table->timestampsTz();

            $table->unique(['office_id', 'date']);
        });

        DB::statement("ALTER TABLE holidays ADD CONSTRAINT holidays_day_type_check CHECK (day_type IN ('special_working','special_non_working','regular_holiday','double_regular_holiday'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
```

- [ ] **Step 4: Write the model + factory**

`Holiday.php` — `HasFactory, HasUuids`, `Spatie\Activitylog\Traits\LogsActivity`; `$guarded = []`; `casts()` returns `['day_type' => DayType::class, 'date' => 'date']`; `newUniqueId()`/`uniqueIds()` uuid7; `office()` `belongsTo`; and the activity-log options:

```php
use Spatie\Activitylog\LogOptions;
// ...
public function getActivitylogOptions(): LogOptions
{
    return LogOptions::defaults()
        ->logOnly(['office_id', 'date', 'day_type', 'name'])
        ->logOnlyDirty()
        ->useLogName('holiday');
}
```

`HolidayFactory` — a `regular_holiday` on a random date for a new `Office`, with a name. Cast `day_type` via the enum.

- [ ] **Step 5: GREEN** — `./vendor/bin/pest tests/Feature/Schema/HolidaySchemaTest.php` (all pass), then `./vendor/bin/pest` (baseline unchanged) and `--testsuite=Arch` (strict_types over database/ now covers the new migration).

- [ ] **Step 6: Commit**

```bash
cd /home/haru/projects/hris
git add backend/database/migrations backend/app/Models/Holiday.php backend/database/factories/HolidayFactory.php backend/tests/Feature/Schema/HolidaySchemaTest.php
git commit -m "Holidays: the per-office holidays table and model

A flat holidays table (one row per office/date), day_type constrained to the
four non-Ordinary DayType values, and the Holiday model wired for activity
logging. Ordinary is the absence of a row."
```

---

### Task 2: `OfficeScope`

**Files:**
- Create: `backend/app/Domain/Scope/OfficeScope.php`
- Test: `backend/tests/Feature/Scope/OfficeScopeTest.php`

**Interfaces:**
- Consumes: `Office`, `User` (M2).
- Produces: `App\Domain\Scope\OfficeScope::administeredBy(User $user): Builder` — an `Office` query builder: system admin → all offices; else the user's `hrAdminOffices`; a user administering none gets an empty result (`WHERE 1=0`).

- [ ] **Step 1: Write the failing test**

`OfficeScopeTest.php` — a system admin's `administeredBy(...)->pluck('id')` contains every office; an HR admin over Manila sees Manila and NOT Cebu; a plain employee (no `hrAdminOffices`) sees zero offices (the query returns empty, not all — assert `->count()` is 0 with several offices present).

- [ ] **Step 2: RED → Step 3: implement (mirror `EmployeeScope::visibleTo`)**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Scope;

use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one definition of "which offices may this user administer" — the M4 config boundary,
 * mirroring EmployeeScope for employees. Returns a query constraint, so it composes into any
 * office query and the boundary lives in one place. See docs/05-rbac.md.
 */
final class OfficeScope
{
    /** @return Builder<Office> */
    public static function administeredBy(User $user): Builder
    {
        $query = Office::query();

        if ($user->is_system_admin) {
            return $query;
        }

        $officeIds = $user->hrAdminOffices()->pluck('offices.id')->all();

        // No HR offices → administers nothing. Force empty, never unconstrained.
        return $officeIds === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn('id', $officeIds);
    }
}
```

If an arch rule bars `Illuminate\Database` from `App\Domain`, add the narrow `->ignoring('App\Domain\Scope\OfficeScope')` mirroring the existing `EmployeeScope` carve-out.

- [ ] **Step 4: GREEN** — `./vendor/bin/pest tests/Feature/Scope/OfficeScopeTest.php` + `--testsuite=Arch`.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Domain/Scope/OfficeScope.php backend/tests/Feature/Scope/OfficeScopeTest.php
git commit -m "Scope: OfficeScope, the M4 config boundary

Which offices may this user administer — sysadmin all, HR admin their
offices, everyone else none. The office analogue of EmployeeScope; holidays
and (M4b) schedules gate on it."
```

---

### Task 3: List + create holidays (the scope-404 + activity pattern)

**Files:**
- Create: `backend/app/Actions/Holidays/CreateHoliday.php` (+ `CreateHolidayInput.php`)
- Create: `backend/app/Http/Requests/CreateHolidayRequest.php`
- Create: `backend/app/Http/Controllers/Office/Holidays/{List,Create}Controller.php`
- Create: `backend/app/Http/Resources/HolidayResource.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Office/HolidayReadWriteTest.php`

**Interfaces:**
- Consumes: `OfficeScope`, `Holiday`, `DayType`.
- Produces:
  - `HolidayResource` — `{ id, office_id, date (Y-m-d), day_type, name }`.
  - `GET /office/holidays?office={id}&year={YYYY}` — holidays for a scoped office/year, ordered by date. Out-of-scope/nonexistent office → 404.
  - `POST /office/holidays` → 201; body `{ office_id, date, day_type, name }`; `office_id` must be one the user administers (else the same 404); `date` a `Y-m-d` string; `day_type` in the four holiday values.
  - `CreateHoliday::execute(CreateHolidayInput): Holiday`.

- [ ] **Step 1: Write the failing test**

`HolidayReadWriteTest.php` covering:
- an HR admin over Manila creates a holiday for Manila → 201, row persisted, and an **activity-log row** exists with `causer_id` = the HR user and `subject_id` = the holiday's uuid (assert both — this proves the uuid morph);
- listing `?office={manila}&year=2026` returns only Manila's 2026 holidays, date-ordered;
- creating for an office the admin does NOT administer → **404 `not_found`**, and creating for a **fabricated** office uuid → **404 `not_found`**, and the two responses are **`->assertExactJson`-equal** (no existence oracle);
- listing an out-of-scope office and a fabricated office → both 404, equal;
- `day_type: 'ordinary'` → 400 `validation_failed`; missing `name` → 400.

Use `Sanctum::actingAs` an HR user with `hrAdminOffices()->attach($manila)`.

- [ ] **Step 2: RED → Step 3: implement**

`CreateHolidayRequest` — `authorize()` true; `rules()` are **shape only**: `office_id` required+uuid (NO `exists:` — an `exists` rule would 400 a fake office while a scope miss 404s, reintroducing the leak), `date` required+date, `day_type` required+`Rule::in(['special_working','special_non_working','regular_holiday','double_regular_holiday'])`, `name` required+string. **The office scope check is in the controller, producing a uniform 404** for both an out-of-scope and a nonexistent office (see next paragraph).

`ListController` / `CreateController` (invokable): resolve the office id from query/body; `$office = OfficeScope::administeredBy($request->user())->find($officeId)`; if `null` → `throw new NotFoundHttpException()`. List returns `HolidayResource::collection($office->holidays()->whereYear('date', $year)->orderBy('date')->get())`. Create calls `CreateHoliday::execute(...)` and returns `HolidayResource` at 201. (Add a `holidays()` `hasMany` to `Office`.)

`CreateHoliday::execute` — one `DB::transaction`: `Holiday::create([...])`. Activity logs automatically on `created` (spatie), causer = the authenticated user.

Routes — a new `Route::prefix('office')->group(...)` inside the `auth:sanctum` group:
```php
Route::prefix('office')->group(function (): void {
    Route::get('/holidays', Office\Holidays\ListController::class);
    Route::post('/holidays', Office\Holidays\CreateController::class);
});
```

- [ ] **Step 4: GREEN** — `./vendor/bin/pest tests/Feature/Office/HolidayReadWriteTest.php` + full suite + Arch. **The scope-404 controllers must satisfy any Office-controller arch rule; if none exists, none is required (holidays live under `Office/`, not `Employees/`/`Attendance/`).**

- [ ] **Step 5: Commit**

```bash
git commit -m "Holidays: list and create, office-scoped, 404 not 403

An out-of-scope or nonexistent office returns an identical 404 — no
enumeration oracle (the PR #10 discipline). Create logs an activity naming
the causer and the holiday, the first real use of the activity log."
```

---

### Task 4: Update + delete holidays

**Files:**
- Create: `backend/app/Actions/Holidays/{UpdateHoliday,DeleteHoliday}.php` (+ `UpdateHolidayInput.php`)
- Create: `backend/app/Http/Requests/UpdateHolidayRequest.php`
- Create: `backend/app/Http/Controllers/Office/Holidays/{Update,Delete}Controller.php`
- Modify: `backend/routes/api.php`
- Test: extend `backend/tests/Feature/Office/HolidayReadWriteTest.php`

**Interfaces:**
- Produces: `PATCH /office/holidays/{holiday}` → 200, body `{ day_type, name }`; `DELETE /office/holidays/{holiday}` → 204. Both scope the bound `{holiday}` by its office and 404 uniformly for a holiday whose office the user doesn't administer AND a nonexistent one.

- [ ] **Step 1: Write failing tests** — an HR admin edits a Manila holiday's `day_type`/`name` → 200, persisted, activity logged (an `updated` activity with the causer); deletes it → 204, gone, activity logged (`deleted`); editing/deleting a holiday belonging to an office the admin doesn't administer → **404**, byte-identical to editing/deleting a **fabricated** `{holiday}` uuid; edit with `day_type: 'ordinary'` → 400.

- [ ] **Step 2: RED → Step 3: implement.** Controllers take the route-bound `Holiday $holiday`; check `OfficeScope::administeredBy($request->user())->whereKey($holiday->office_id)->exists()` else `throw new NotFoundHttpException()` — so an out-of-scope holiday 404s the same as a nonexistent `{holiday}` (which route-binding already 404s). Update/Delete actions do the write in a transaction; spatie logs `updated`/`deleted`. Routes: `PATCH /office/holidays/{holiday}`, `DELETE /office/holidays/{holiday}`.

- [ ] **Step 4: GREEN** — the extended test + full suite.

- [ ] **Step 5: Commit**

```bash
git commit -m "Holidays: update and delete, same scoped 404, audited

Editing or deleting a holiday whose office you don't administer is a 404
identical to a nonexistent one; every mutation lands in the activity log."
```

---

### Task 5: Clone from the previous year

**Files:**
- Create: `backend/app/Actions/Holidays/CloneHolidays.php` (+ `CloneHolidaysInput.php`)
- Create: `backend/app/Http/Requests/CloneHolidaysRequest.php`
- Create: `backend/app/Http/Controllers/Office/Holidays/CloneController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Office/CloneHolidaysTest.php`

**Interfaces:**
- Produces: `POST /office/holidays/clone` → 201 (`HolidayResource` collection of the created rows); body `{ office_id, from_year, to_year }`; scoped like create. `CloneHolidays::execute(CloneHolidaysInput): Collection<Holiday>` — copies each source-year row to the same month/day of the target year, **skipping any target date already present**; re-runnable without duplicating; logs one summary activity.

- [ ] **Step 1: Write failing tests** — given three Manila 2025 holidays, cloning 2025→2026 creates three 2026 rows on the same month/day; a second clone creates **zero** (all skipped) and does not error; a target date already occupied is left as-is (not overwritten); cloning for an out-of-scope/fake office → **404**, byte-identical; a summary activity row is written with the causer.

- [ ] **Step 2: RED → Step 3: implement.** `CloneController` scopes the office (404 as above). `CloneHolidays::execute` in one transaction: read `office.holidays()->whereYear('date', $fromYear)->get()`; for each, compute the target date as the same month/day in `$toYear` (build `YYYY-MM-DD` from the parts — do **not** add 365 days); `insertOrIgnore`-style skip via a pre-check against existing `(office, date)`; create the new rows; write one `activity()->causedBy($user)->performedOn($office)->withProperties([...])->log('cloned N holidays from {from} to {to}')`. Return the created collection.

**Feb 29 note:** a source holiday on Feb 29 of a leap year cloning into a non-leap target has no Feb 29 — skip it (and note it in the summary), never silently shift to Mar 1.

- [ ] **Step 4: GREEN** — the clone test + full suite.

- [ ] **Step 5: Commit**

```bash
git commit -m "Holidays: clone-from-previous-year

Copies last year's holidays to the same month/day as an editable draft,
skipping dates already set and re-runnable without duplicating — the movable
holidays (Eid, long-weekend proclamations) are the admin's to adjust."
```

---

### Task 6: Generalize `MonthCalendar` (and migrate attendance)

**Files:**
- Modify: `frontend/web/src/components/domain/MonthCalendar.tsx`
- Modify: `frontend/web/src/components/domain/MonthCalendar.test.tsx`
- Modify: `frontend/web/src/app/(app)/me/attendance/page.tsx`
- Test: the existing attendance tests MUST stay green (proof it's behaviour-preserving)

**Interfaces:**
- Produces: `MonthCalendar({ month, timeZone, renderDay })` where `renderDay: (ctx: { date: string; isToday: boolean; inMonth: boolean }) => ReactNode`. It owns the grid, weekday headers, and uniform fixed-height cells; it calls `renderDay(ctx)` inside each real day cell (blanks render empty). The `days`/attendance-specific props are **removed**.
- Consumes: `DayCell` (unchanged) — the attendance page now passes it via `renderDay`.

- [ ] **Step 1: Update `MonthCalendar.test.tsx`** — the grid/columnheader/1st-in-correct-column assertions stay; replace the punch-rendering assertions with: a `renderDay` spy is called once per day of the month with the right `{date, isToday}` (assert `isToday` true only for `todayInZone`), and its output lands in the correct `gridcell`. Cross-zone/punch specifics move to (or stay in) `DayCell.test.tsx`, unchanged.

- [ ] **Step 2: RED** — `cd /home/haru/projects/hris/frontend/web && npm test src/components/domain/MonthCalendar.test.tsx` fails against the current `days` API.

- [ ] **Step 3: Refactor `MonthCalendar`** — drop `days`, add `renderDay`. Keep the CSS-grid shell (`role="grid"`, rows, gridcells at `CELL_HEIGHT`, `overflow: hidden`, ARIA). Inside each real-date gridcell, render `{renderDay({ date, isToday: date === today, inMonth: true })}` instead of `<DayCell ... />`. Blanks unchanged.

- [ ] **Step 4: Migrate the attendance page** — where it renders `<MonthCalendar month={...} days={...} timeZone={...} />`, change to:
```tsx
<MonthCalendar
  month={viewedMonth}
  timeZone={OFFICE_TIME_ZONE}
  renderDay={({ date, isToday, inMonth }) => (
    <DayCell date={date} punches={monthData[date] ?? []} timeZone={OFFICE_TIME_ZONE} isToday={isToday} inMonth={inMonth} />
  )}
/>
```

- [ ] **Step 5: GREEN** — `npm test` (all green, especially the attendance page + `DayCell` tests, which prove the migration is behaviour-preserving), `npm run typecheck && npm run lint && npm run build`.

- [ ] **Step 6: Commit**

```bash
git commit -m "Frontend: generalize MonthCalendar to a per-day render prop

The grid shell (columns, rows, uniform cells, ARIA) is now content-agnostic;
callers pass renderDay for what each cell holds. Attendance migrates onto it
unchanged — the reason the component exists is to be shared by holidays and
schedules next."
```

---

### Task 7: `DayTypeTag`, `Dialog`, `Select`

**Files:**
- Create: `frontend/web/src/components/domain/DayTypeTag.tsx`, `components/ui/Dialog.tsx`, `components/ui/Select.tsx`
- Modify: `frontend/web/package.json` (add Radix)
- Test: `frontend/web/src/components/domain/DayTypeTag.test.tsx`, `components/ui/Dialog.test.tsx`, `Select.test.tsx`

**Interfaces:**
- Produces:
  - `DayTypeTag({ dayType }: { dayType: 'special_working'|'special_non_working'|'regular_holiday'|'double_regular_holiday' })` — a monochrome Carbon `Tag` with a human label ("Regular holiday", "Special non-working", …).
  - `Dialog({ open, onClose, title, children })` — Radix-backed modal; Escape + overlay-click close; focus trapped; `role="dialog"` + `aria-labelledby`.
  - `Select({ id, label, value, onChange, options })` — Radix-backed listbox with a real label; keyboard-navigable.

- [ ] **Step 1: Install Radix** — `cd frontend/web && npm install @radix-ui/react-dialog@^1.1 @radix-ui/react-select@^2.1`. Pin the minor.

- [ ] **Step 2: Write failing tests** — `DayTypeTag` renders the human label for each of the four types. `Dialog` renders its title and children when `open`, renders nothing when closed, calls `onClose` on Escape. `Select` is reachable by its label, lists its options, and calls `onChange` with the chosen value.

- [ ] **Step 3: RED → Step 4: implement** — token-only styling (no raw hex; `--ls-*` companions). `DayTypeTag` maps the enum to a label + reuses the existing `Tag`. `Dialog`/`Select` wrap Radix, styled by carbon tokens, `--radius: 0px`.

- [ ] **Step 5: GREEN** — `npm test src/components && npm run typecheck && npm run lint`.

- [ ] **Step 6: Commit**

```bash
git commit -m "Frontend: DayTypeTag, Dialog, Select

The three deferred primitives, earning their place for the holiday screen:
a monochrome day-type tag, and Radix-backed Dialog and Select styled by the
Carbon tokens."
```

---

### Task 8: The `/office/holidays` screen

**Files:**
- Modify: `frontend/web/src/lib/keys.ts`, `frontend/web/src/lib/api.ts`
- Create: `frontend/web/src/hooks/useHolidays.ts`
- Create: `frontend/web/src/app/(app)/office/holidays/page.tsx`
- Test: `frontend/web/src/app/(app)/office/holidays/holidays.test.tsx`, `frontend/web/src/hooks/useHolidays.test.tsx`

**Interfaces:**
- Consumes: `MonthCalendar` (Task 6), `DayTypeTag`/`Dialog`/`Select` (Task 7), `useSession` (for `hr_offices`).
- Produces: `keys.holidays.forOfficeYear(officeId, year)`; `api.holidays.list/create/update/delete/clone`; `useHolidays(officeId, year)` + mutations; the screen.

- [ ] **Step 1: Extend `keys.ts` + `api.ts`** — `keys.holidays = { all: () => ['holidays'] as const, forOfficeYear: (o, y) => ['holidays', o, y] as const }`. Wire types `Holiday = { id; office_id; date; day_type; name }` and `api.holidays.{list(office,year), create(body), update(id,body), delete(id), clone(body)}`, verified against `HolidayResource`.

- [ ] **Step 2: Write failing tests** — `useHolidays` fetches `keys.holidays.forOfficeYear`; the page shows an office picker of the session's `hr_offices` (none if only one); a day with a holiday renders its name + `DayTypeTag`; clicking a day opens the add `Dialog`, submitting calls `api.holidays.create` and invalidates; "Clone from 2025" calls `api.holidays.clone` and invalidates; a month with no holidays renders an empty calendar (no dead-end). Mock `next/navigation` + `fetch` per the established patterns.

- [ ] **Step 3: RED → Step 4: implement** — the page: office picker (from `useSession().session.hr_offices` — the office ids; label them by id for now, a name lookup is deferred), month nav, `MonthCalendar` with a `renderDay` that shows the holiday for that date (name + `DayTypeTag`) and an add affordance, the add/edit `Dialog` (name `TextInput`, day-type `Select`, `date`), and the clone button. Mutations invalidate `keys.holidays.forOfficeYear`.

- [ ] **Step 5: GREEN** — `npm test && npm run typecheck && npm run lint && npm run build` (confirm `/office/holidays` in the route table).

- [ ] **Step 6: Commit**

```bash
git commit -m "Frontend: the /office/holidays calendar screen

An HR admin edits and clones an office's holidays on the generalized
MonthCalendar — click a day to add or edit in a dialog, clone last year's
set forward. Scoped to the offices the session says you administer."
```

---

### Task 9: Mobile-nav collapse + the Office group

**Files:**
- Modify: `frontend/web/src/components/SideNav.tsx` (Office group now has a route), `AppShell.tsx` (hamburger + responsive rail)
- Test: extend `SideNav.test.tsx`, `AppShell.test.tsx`

**Interfaces:**
- Produces: `ROUTES.office = [{ href: '/office/holidays', label: 'Holidays' }]`; below `md` the side-nav is an overlay toggled by a header hamburger; above `md`, the persistent rail.

- [ ] **Step 1: Write failing tests** — `navEntriesFor`/`SideNav`: an HR admin (`hr_offices` non-empty) now sees an **Office** group with **Holidays** (previously hidden — empty `ROUTES.office`); a plain employee still sees only **Me**. `AppShell`: a hamburger button (`aria-label`, `aria-expanded`) toggles the nav open/closed; it's present for the responsive/overlay behaviour.

- [ ] **Step 2: RED → Step 3: implement** — add the Office route to `ROUTES`. In `AppShell`, add a hamburger in the header that toggles a `navOpen` state; below `md` (Tailwind `md:` breakpoints) the `SideNav` renders as a fixed overlay when open and is hidden otherwise; at `md+` it's the persistent rail and the hamburger is hidden. Close the overlay on route change and on Escape (reuse the account-menu dismissal idiom).

- [ ] **Step 4: GREEN** — `npm test && npm run typecheck && npm run lint && npm run build`.

- [ ] **Step 5: Commit**

```bash
git commit -m "Frontend: populate the Office nav and collapse it on mobile

/office/holidays is the first Office-scope route, so the Office group now
renders for HR admins; below md the rail becomes a hamburger overlay — the
collapse deferred from M3.5, now that there is enough nav to warrant it."
```

---

### Task 10: Docs, activity-log config, e2e, and the gate

**Files:**
- Modify: `docs/02-data-model.md` (holidays table, OfficeScope), `docs/03-api.md` (the `/office/holidays` endpoints + codes), `docs/05-rbac.md` (OfficeScope + who edits holidays), `docs/06-roadmap.md` (M4a **Status: complete**, the M4 slice + RecomputeRange→M5 note), `docs/features.md`
- Create: `backend/config/activitylog.php` (publish), `scripts/e2e-holidays.sh`
- Modify: `backend/database/seeders/CompanySeeder.php` (seed a couple of Manila holidays so the screen isn't empty on `make dev`)

- [ ] **Step 1: Publish the activitylog config** — `php artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider" --tag="activitylog-config"` (so the log is configurable/documented), and confirm the suite still green.

- [ ] **Step 2: `e2e-holidays.sh`** against the live stack — log in as `hr.manila@hris.test`, create a Manila holiday, list it, confirm it's NOT on Cebu (a Cebu-only HR admin gets 404 touching it — byte-identical to a fake id), clone 2025→2026, and read the activity log to confirm the causer + subject. Mirror `scripts/e2e-adjustments.sh`. `bash -n` clean; run live if the stack is up.

- [ ] **Step 3: Docs** — verify every claim against code. `03-api.md`: the five endpoints, their `not_found`/`validation_failed` codes, and the byte-identical-404 note. `02-data-model.md`: the `holidays` table + `OfficeScope`. `05-rbac.md`: OfficeScope and holiday-edit authority. `06-roadmap.md`: an M4a **Status: complete** block with real counts and the M4-slice / RecomputeRange→M5 decisions. `features.md`: the user-facing holiday features.

- [ ] **Step 4: Run the full gate** — `cd backend && ./vendor/bin/pest && ./vendor/bin/pest --testsuite=Arch`; `cd frontend/web && npm run lint && npm test && npm run typecheck && npm run build`; `cd /home/haru/projects/hris && make test`. Report real counts.

- [ ] **Step 5: Commit** — `git commit -m "Holidays: docs, activity-log config, e2e, M4a status"`.

---

## Done When

An HR admin for Manila adds Ninoy Aquino Day (Aug 21) as a special-non-working holiday for the Manila office; it shows on Manila's `/office/holidays` calendar and not on Cebu's; a Cebu-only HR admin gets a 404 — not 403, byte-identical to a nonexistent one — touching a Manila holiday; "Clone from 2025" copies last year's Manila set into 2026 skipping dates already present; and the activity log names who added it and when, the subject landing in the uuid morph. No pay is computed — the holidays table is the input M5's engine will read. Full suite green; the attendance tests stay green through the `MonthCalendar` refactor.
