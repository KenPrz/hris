# M6b-a — Leave foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up the leave foundation — per-office leave types, an append-only minutes ledger, manual HR grants, and derived balances — with no taking, approval, accrual, or compute integration (those are M6b-b/later).

**Architecture:** Mirror the M4 per-office config idiom exactly: string+CHECK columns, uuid v7 PKs, `OfficeScope` 404-not-403 for HR-scoped config, action classes (final, own transaction, Input DTO, no HTTP). Leave amounts live in the ledger as integer minutes; a per-office nominal `minutes_per_leave_day` converts readable units; balances are always summed from the ledger, never stored.

**Tech Stack:** Laravel 13 / PHP 8.5 / PostgreSQL 18 (Pest, real Postgres) · Next 16 / React 19 / TS / `@tanstack/react-query` / Carbon tokens (Vitest).

## Global Constraints

- `declare(strict_types=1);` at the top of every PHP file in `app/` and `tests/` (arch-enforced).
- uuid v7 PKs: `$table->uuid('id')->primary()->default(DB::raw('uuidv7()'))`; models use `HasUuids` + `newUniqueId(): string { return Str::uuid7()->toString(); }` + `uniqueIds(): array { return ['id']; }`.
- **String column + PHP backed enum + `CHECK` constraint — never a Postgres native enum.** A schema test pins each CHECK list to `Enum::cases()`. Add any new enum to the Arch ignore-list if the "domain value objects are final" rule flags it (an enum isn't a class).
- **All leave amounts are integer minutes.** Never a float. The readable unit (day / half-shift / hour / minute) is a presentation concern converted at the HTTP edge.
- **The `leave_ledger` is append-only** — no `updated_at`, no update, no delete. A correction is a compensating row.
- **Balances are derived, never stored** — always `SUM(credit) − SUM(debit)` from the ledger.
- One action = one route = one controller = one final Action class; actions take an Input DTO / domain args, return a domain object, never touch HTTP; serialization is the controller's job.
- **404-not-403 for office/employee-scoped resources** (an out-of-scope office/employee and a nonexistent one are indistinguishable). FormRequests validate ids as **shape only** (`uuid`), never `exists:` — the scope check is the controller's job via `OfficeScope`/`EmployeeScope`.
- Domain layer is framework-agnostic EXCEPT the ORM/Eloquent is allowed (precedent: `EmployeeScope`, `OfficeScope`, `RequestAuthority`).
- Success `{"data":...}` / error `{"error":...}` envelope. Tests run against real PostgreSQL, never SQLite.
- Frontend: query keys from `src/lib/keys.ts`, requests through `src/lib/api.ts`; components use only `carbon.css` `var(--*)` tokens; `erasableSyntaxOnly` (no constructor parameter properties).
- Commit messages carry NO attribution trailers (no `Co-Authored-By`, `Generated with`, session URL). Message body only.

---

## File Structure

**Backend — new**
- `database/migrations/…_add_minutes_per_leave_day_to_offices.php`, `…_create_leave_types_table.php`, `…_create_leave_ledger_table.php`
- `app/Models/{LeaveType,LeaveLedger}.php`
- `database/factories/{LeaveTypeFactory,LeaveLedgerFactory}.php`
- `app/Domain/Leave/{LeaveUnit,LeaveBalances}.php`
- `app/Actions/Offices/SetOfficeLeaveDay.php` (+ `Input`)
- `app/Actions/Leave/{CreateLeaveType,UpdateLeaveType,GrantLeave}.php` (+ their `Input` DTOs)
- `app/Http/Controllers/Office/LeaveTypes/{ListController,CreateController,UpdateController}.php`
- `app/Http/Controllers/Office/SetLeaveDayController.php`
- `app/Http/Controllers/Leave/{GrantController,ListMyLeaveController,ListEmployeeLeaveController}.php`
- `app/Http/Requests/{ListLeaveTypesRequest,CreateLeaveTypeRequest,UpdateLeaveTypeRequest,SetLeaveDayRequest,GrantLeaveRequest}.php`
- `app/Http/Resources/{LeaveTypeResource,LeaveBalanceResource}.php`
- `tests/Feature/Schema/{LeaveTypeSchemaTest,LeaveLedgerSchemaTest}.php`, `tests/Feature/Leave/**`

**Backend — modify**
- `app/Models/Office.php` (fillable/cast + `leaveTypes()` relation), `routes/api.php`, `database/seeders/{RbacSeeder,CompanySeeder}.php`

**Frontend — new**
- `src/hooks/{useLeaveTypes,useMyLeave,useEmployeeLeave,useGrantLeave,useSetLeaveDay,useSaveLeaveType}.ts` (+ tests)
- `src/components/domain/LeaveDuration.tsx` (readable minutes↔days, mirrors backend `LeaveUnit`) (+ test)
- `src/app/(app)/office/leave-types/page.tsx`, `src/app/(app)/me/leave/page.tsx`

**Frontend — modify**
- `src/lib/{api.ts,keys.ts}`, `src/components/SideNav.tsx` (+ `SideNav.test.tsx`)

**Docs / scripts**
- `scripts/e2e-leave-foundation.sh` (new); `docs/02-data-model.md`, `docs/05-rbac.md`, `docs/06-roadmap.md`, `docs/features.md` (modify)

---

## Task 1: `offices.minutes_per_leave_day` + `SetOfficeLeaveDay`

**Files:**
- Create: migration `…_add_minutes_per_leave_day_to_offices.php`, `app/Actions/Offices/SetOfficeLeaveDay.php` (+ `SetOfficeLeaveDayInput.php`), `app/Http/Controllers/Office/SetLeaveDayController.php`, `app/Http/Requests/SetLeaveDayRequest.php`
- Modify: `app/Models/Office.php` (add `minutes_per_leave_day` to `$fillable` + `casts` integer), `routes/api.php`
- Test: `tests/Feature/Leave/SetOfficeLeaveDayTest.php`

**Interfaces:**
- Produces: `SetOfficeLeaveDay::execute(SetOfficeLeaveDayInput{officeId: string, minutesPerLeaveDay: int}): Office`; route `PATCH /office/leave-day` (body `{office_id, minutes_per_leave_day}`).

- [ ] **Step 1: Migration**

Mirror the M4b `default_shift_template_id` add (`Schema::table('offices', …)`):

```php
Schema::table('offices', function (Blueprint $table): void {
    $table->smallInteger('minutes_per_leave_day')->default(480)->after('timezone');
});
// down(): $table->dropColumn('minutes_per_leave_day');
```

- [ ] **Step 2: Write the failing test**

`tests/Feature/Leave/SetOfficeLeaveDayTest.php`: an HR admin over the office sets it to 450 → office row updated; a non-administered office → 404; `minutes_per_leave_day` < 1 → 422. Use the auth idiom from `tests/Feature/Office/**` holiday tests (`actingAs`, `patchJson('/api/v1/office/leave-day', [...])`). Seed HR via `hrAdminOffices()->attach`.

- [ ] **Step 3: Run it — FAIL** (route missing). Run: `docker compose -f compose.dev.yml exec -T -e DB_DATABASE=hris_test --user hris api php -d memory_limit=512M ./vendor/bin/pest tests/Feature/Leave/SetOfficeLeaveDayTest.php`

- [ ] **Step 4: Action + Input**

`SetOfficeLeaveDayInput` (readonly DTO: `officeId`, `minutesPerLeaveDay`). `SetOfficeLeaveDay::execute` wraps in `DB::transaction`, `Office::query()->lockForUpdate()->findOrFail($in->officeId)`, `->update(['minutes_per_leave_day' => $in->minutesPerLeaveDay])`, returns the office. (Office already `LogsActivity`? if not, this is a plain update — no activity requirement stated for this field.)

- [ ] **Step 5: Request + Controller + route**

`SetLeaveDayRequest`: `authorize(): true`; rules `office_id => ['required','uuid']`, `minutes_per_leave_day => ['required','integer','min:1']`. `SetLeaveDayController` (mirror `Office/Holidays/CreateController`): resolve `OfficeScope::administered($request->user(), office_id) ?? throw new NotFoundHttpException`, call the action, return `OfficeResource` or a small `{data:{id, minutes_per_leave_day}}`. Route (authed group): `Route::patch('/office/leave-day', SetLeaveDayController::class);`

- [ ] **Step 6: Run the test — PASS.** Then arch: `… pest tests/Arch`.

- [ ] **Step 7: Commit**

```bash
git add backend/database/migrations backend/app/Actions/Offices backend/app/Http/Controllers/Office/SetLeaveDayController.php backend/app/Http/Requests/SetLeaveDayRequest.php backend/app/Models/Office.php backend/routes/api.php backend/tests/Feature/Leave/SetOfficeLeaveDayTest.php
git commit -m "Leave: per-office minutes_per_leave_day + SetOfficeLeaveDay"
```

---

## Task 2: `leave_types` table + model + factory + schema test

**Files:**
- Create: migration `…_create_leave_types_table.php`, `app/Models/LeaveType.php`, `database/factories/LeaveTypeFactory.php`, `tests/Feature/Schema/LeaveTypeSchemaTest.php`
- Modify: `app/Models/Office.php` (add `leaveTypes(): HasMany`)

**Interfaces:**
- Produces: `LeaveType` model with columns `id, office_id, name, code (nullable), is_paid, requires_attachment, deducts_balance, is_cash_convertible, max_carryover_minutes (nullable int), is_active`. `deducts_balance` is the balance-vs-event axis (true = holds a balance you grant into; false = event entitlement, no balance, used in M6b-b).

- [ ] **Step 1: Migration**

```php
Schema::create('leave_types', function (Blueprint $table): void {
    $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
    $table->foreignUuid('office_id')->constrained()->cascadeOnDelete();
    $table->text('name');
    $table->text('code')->nullable();               // slug for a seeded statutory type; null for ad-hoc
    $table->boolean('is_paid')->default(true);
    $table->boolean('requires_attachment')->default(false);
    $table->boolean('deducts_balance')->default(true);   // false = event entitlement (Maternity etc.)
    $table->boolean('is_cash_convertible')->default(false);
    $table->integer('max_carryover_minutes')->nullable(); // null = unlimited; the year-end job that uses it is deferred
    $table->boolean('is_active')->default(true);
    $table->timestampsTz();
    $table->unique(['office_id', 'code']);            // one 'sil' per office; multiple null codes allowed (Postgres treats NULLs distinct)
});
DB::statement('ALTER TABLE leave_types ADD CONSTRAINT leave_types_max_carryover_nonneg_check CHECK (max_carryover_minutes IS NULL OR max_carryover_minutes >= 0)');
```

- [ ] **Step 2: Write the failing schema test**

`LeaveTypeSchemaTest.php` (mirror `HolidaySchemaTest`): round-trips the flags + `max_carryover_minutes`; the `(office_id, code)` unique rejects a second `sil` in one office but allows two null-code types; the non-negative CHECK rejects `max_carryover_minutes = -1` via a raw `DB::table` insert.

- [ ] **Step 3: Run — FAIL.** `… pest tests/Feature/Schema/LeaveTypeSchemaTest.php`

- [ ] **Step 4: Model + factory + relation**

`LeaveType` (`HasUuids`, `LogsActivity` useLogName `leave_type`, `newUniqueId`/`uniqueIds`): `$fillable` the columns above; casts the four bools to `boolean`, `max_carryover_minutes` to `integer`; `office(): BelongsTo`. `LeaveTypeFactory`: `office_id => Office::factory()`, `name => 'Vacation Leave'`, `code => null`, sensible flag defaults. Add `Office::leaveTypes(): HasMany`.

- [ ] **Step 5: Run — PASS.** Then `… pest tests/Arch` (no native enum introduced; model is final).

- [ ] **Step 6: Commit** `git commit -m "Leave: leave_types per-office config table + model"`

---

## Task 3: `leave_ledger` (append-only) + `LeaveLedger` model + `LeaveBalances` + `LeaveUnit`

**Files:**
- Create: migration `…_create_leave_ledger_table.php`, `app/Models/LeaveLedger.php`, `database/factories/LeaveLedgerFactory.php`, `app/Domain/Leave/LeaveBalances.php`, `app/Domain/Leave/LeaveUnit.php`, `tests/Feature/Schema/LeaveLedgerSchemaTest.php`, `tests/Feature/Leave/LeaveBalancesTest.php`, `tests/Unit/Leave/LeaveUnitTest.php`

**Interfaces:**
- Produces:
  - `LeaveLedger` columns `id, employee_id, leave_type_id, entry_type ('credit'|'debit'), minutes (>0), reason, source ('manual_grant'), request_id (nullable), created_by, created_at`. **No `updated_at`.**
  - `LeaveBalances::forEmployee(Employee $e): array<string,int>` — `leave_type_id => net minutes` (`SUM(credit) − SUM(debit)`).
  - `LeaveUnit::toMinutes(int $amount, string $unit, int $minutesPerLeaveDay): int` — `unit ∈ {day, half_shift, hour, minute}`; `day→amount*mpld`, `half_shift→amount*intdiv(mpld,2)`, `hour→amount*60`, `minute→amount`. Throws on an unknown unit / non-positive amount.
  - `LeaveUnit::readable(int $minutes, int $minutesPerLeaveDay): array{days:int,hours:int,minutes:int}` — decompose for display.

- [ ] **Step 1: Migration** (append-only: `created_at` only, no `timestampsTz()`)

```php
Schema::create('leave_ledger', function (Blueprint $table): void {
    $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
    $table->foreignUuid('employee_id')->constrained();
    $table->foreignUuid('leave_type_id')->constrained();
    $table->text('entry_type');
    $table->integer('minutes');
    $table->text('reason');
    $table->text('source');
    $table->foreignUuid('request_id')->nullable()->constrained('requests')->nullOnDelete();
    $table->foreignUuid('created_by')->constrained('users');
    $table->timestampTz('created_at');   // no updated_at — append-only
    $table->index(['employee_id', 'leave_type_id']);
});
DB::statement("ALTER TABLE leave_ledger ADD CONSTRAINT leave_ledger_entry_type_check CHECK (entry_type IN ('credit','debit'))");
DB::statement("ALTER TABLE leave_ledger ADD CONSTRAINT leave_ledger_source_check CHECK (source IN ('manual_grant'))");
DB::statement('ALTER TABLE leave_ledger ADD CONSTRAINT leave_ledger_minutes_pos_check CHECK (minutes > 0)');
```

- [ ] **Step 2: Failing tests**

`LeaveLedgerSchemaTest`: CHECK rejects `entry_type='foo'`, `source='foo'`, `minutes=0` (raw `DB::table` inserts, cf. `AdjustmentSchemaTest`); round-trips a credit row. `LeaveBalancesTest`: three credits + one debit for an employee/type sum to the net minutes; a second type is independent; an employee with no rows → balance 0 / absent. `LeaveUnitTest`: `toMinutes(5,'day',480)===2400`, `toMinutes(1,'half_shift',480)===240`, `toMinutes(3,'hour',*)===180`, `toMinutes(90,'minute',*)===90`, unknown unit throws, `readable(2400,480)===['days'=>5,'hours'=>0,'minutes'=>0]`, `readable(555,480)===['days'=>1,'hours'=>1,'minutes'=>15]`.

- [ ] **Step 3: Run — FAIL.**

- [ ] **Step 4: Model + factory + domain services**

`LeaveLedger` (`HasUuids`; `const UPDATED_AT = null` and `$timestamps` handling so Eloquent only manages `created_at` — set `public $timestamps = true;` with `const UPDATED_AT = null;`, or `const CREATED_AT = 'created_at'; const UPDATED_AT = null;`): `$fillable` the columns; casts `minutes` integer; `employee()/leaveType()/createdBy()` relations. `LeaveLedgerFactory`. `LeaveBalances::forEmployee` — one grouped query: `LeaveLedger::where('employee_id',$e->id)->selectRaw("leave_type_id, SUM(CASE WHEN entry_type='credit' THEN minutes ELSE -minutes END) AS net")->groupBy('leave_type_id')->pluck('net','leave_type_id')->map(fn($v)=>(int)$v)->all()`. `LeaveUnit` — pure static helpers (Domain, no framework).

- [ ] **Step 5: Run — PASS.** Then `… pest tests/Arch` (add `LeaveUnit`/`LeaveBalances` to the framework-agnostic ignore-list only if they import Eloquent — `LeaveBalances` does; `LeaveUnit` doesn't).

- [ ] **Step 6: Commit** `git commit -m "Leave: append-only leave_ledger + derived LeaveBalances + LeaveUnit conversion"`

---

## Task 4: Leave-type config actions + controllers + routes

**Files:**
- Create: `app/Actions/Leave/{CreateLeaveType,UpdateLeaveType}.php` (+ Inputs), `app/Http/Controllers/Office/LeaveTypes/{ListController,CreateController,UpdateController}.php`, `app/Http/Requests/{ListLeaveTypesRequest,CreateLeaveTypeRequest,UpdateLeaveTypeRequest}.php`, `app/Http/Resources/LeaveTypeResource.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Leave/LeaveTypeConfigTest.php`

**Interfaces:**
- Consumes: `LeaveType`, `OfficeScope`.
- Produces: `CreateLeaveType::execute(CreateLeaveTypeInput): LeaveType`; `UpdateLeaveType::execute(LeaveType, UpdateLeaveTypeInput): LeaveType`; routes `GET /office/leave-types?office={id}`, `POST /office/leave-types`, `PATCH /office/leave-types/{leaveType}`. **No delete** — retire via `is_active=false` in an update.

- [ ] **Step 1: Failing test**

`LeaveTypeConfigTest`: HR lists their office's types (scoped — a Cebu HR admin can't see Manila's, 404 on a foreign office param); create a type with flags → row created, `office_id` from scope; update flips `is_active`/flags; a non-administered office on create → 404; `max_carryover_minutes: -1` → 422. Mirror `tests/Feature/Office/**` holiday config tests.

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Actions**

`CreateLeaveType` (final, `DB::transaction`, trusts a controller-supplied `officeId`): `LeaveType::create([...])` — the office-scope check is the controller's job (mirror `CreateHoliday`). `UpdateLeaveType`: update the route-bound type's fillable fields. Both rely on `LogsActivity` for the audit.

- [ ] **Step 4: Requests + Controllers + Resource + routes**

FormRequests `authorize(): true`; rules — `CreateLeaveTypeRequest`: `office_id=>['required','uuid']` (shape only), `name=>['required','string']`, `code=>['nullable','string']`, the four bools `['required','boolean']` (or `sometimes`), `max_carryover_minutes=>['nullable','integer','min:0']`. `UpdateLeaveTypeRequest`: same minus `office_id` (fixed by the route-bound type). `ListLeaveTypesRequest`: `office=>['required','uuid']`.
Controllers mirror `Office/Holidays/{List,Create,Update}Controller`: List/Create resolve `OfficeScope::administered(user, office_id) ?? 404`; Update resolves the route-bound `{leaveType}`, checks `OfficeScope::administers(user, $leaveType->office_id)` else 404. `LeaveTypeResource` serializes id/office_id/name/code/flags/max_carryover_minutes/is_active. Routes in the authed group.

- [ ] **Step 5: Run — PASS.** Then `… pest tests/Feature/Leave tests/Arch`.

- [ ] **Step 6: Commit** `git commit -m "Leave: leave-type config (list/create/update, office-scoped, no delete)"`

---

## Task 5: `GrantLeave` + `POST /leave/grants`

**Files:**
- Create: `app/Actions/Leave/GrantLeave.php` (+ `GrantLeaveInput`), `app/Http/Controllers/Leave/GrantController.php`, `app/Http/Requests/GrantLeaveRequest.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Leave/GrantLeaveTest.php`

**Interfaces:**
- Consumes: `LeaveLedger`, `LeaveType`, `LeaveUnit`, `OfficeScope`, `Employee`.
- Produces: `GrantLeave::execute(GrantLeaveInput{employeeId, leaveTypeId, minutes, reason, actorId}): LeaveLedger` — writes ONE `credit` row (`source: manual_grant`). Route `POST /leave/grants` (body `{employee_id, leave_type_id, amount, unit, reason}`).

- [ ] **Step 1: Failing test**

`GrantLeaveTest`: HR grants an employee in their office "5 days" (`{amount:5, unit:'day'}`) → one `leave_ledger` row `entry_type=credit`, `minutes=2400` (via the office's `minutes_per_leave_day=480`), `reason` + `created_by` set, `source='manual_grant'`; `/me/leave`-style balance reflects +2400 (assert via `LeaveBalances::forEmployee`). Guards: granting to an employee in a non-administered office → 404; granting a `deducts_balance=false` (event) type → 422; empty reason → 422; `amount ≤ 0` → 422. Assert the ledger stays append-only (a re-grant adds a second row, never edits the first).

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Action**

```php
final class GrantLeave {
    public function execute(GrantLeaveInput $in): LeaveLedger {
        return DB::transaction(fn () => LeaveLedger::query()->create([
            'employee_id' => $in->employeeId,
            'leave_type_id' => $in->leaveTypeId,
            'entry_type' => 'credit',
            'minutes' => $in->minutes,
            'reason' => $in->reason,
            'source' => 'manual_grant',
            'created_by' => $in->actorId,
        ]));
    }
}
```

- [ ] **Step 4: Request + Controller + route**

`GrantLeaveRequest`: `authorize():true`; rules `employee_id`/`leave_type_id` `['required','uuid']` (shape only), `amount=>['required','integer','min:1']`, `unit=>['required', Rule::in(['day','half_shift','hour','minute'])]`, `reason=>['required','string']`.
`GrantController`: (1) resolve the `Employee` by id (plain `Employee::find`), 404 if null; (2) `OfficeScope::administers($user, $employee->current_office_id)` else 404 — grants are HR-over-the-office only (not `EmployeeScope`, which would also let a manager grant to reports); (3) resolve the `LeaveType` by id scoped to that same office, 404 if not; (4) 422 (a domain `LeaveTypeNotGrantable`) if `! $leaveType->deducts_balance`; (5) `$minutes = LeaveUnit::toMinutes($amount, $unit, $office->minutes_per_leave_day)`; (6) call `GrantLeave::execute(...)`; return the created ledger row (a small resource or `{data:{id, minutes, ...}}`), 201.

- [ ] **Step 5: Run — PASS.** Then `… pest tests/Feature/Leave tests/Arch`.

- [ ] **Step 6: Commit** `git commit -m "Leave: HR manual grant — one logged credit row, minutes via office leave-day"`

---

## Task 6: Balance reads — `/me/leave` + `/employees/{employee}/leave`

**Files:**
- Create: `app/Http/Controllers/Leave/{ListMyLeaveController,ListEmployeeLeaveController}.php`, `app/Http/Resources/LeaveBalanceResource.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Leave/LeaveBalanceReadTest.php`

**Interfaces:**
- Consumes: `LeaveBalances`, `LeaveUnit`, `LeaveType`, `EmployeeScope`.
- Produces: `GET /me/leave` (caller's own balances) and `GET /employees/{employee}/leave` (an overseen employee, `EmployeeScope`, 404-not-403). Each returns, per active `deducts_balance` type in the employee's office: `{leave_type: {...}, balance_minutes: int, balance_readable: {days,hours,minutes}}`.

- [ ] **Step 1: Failing test**

`LeaveBalanceReadTest`: after two grants to an employee, `GET /me/leave` (as that employee) lists their types with `balance_minutes` = the derived sum and a readable decomposition; a type with no rows shows 0; `GET /employees/{id}/leave` works for the employee's manager and their office HR, and 404s for an unrelated user (EmployeeScope). Balances are recomputed (grant again → number changes with no migration/stored field).

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Controllers + Resource + routes**

`ListMyLeaveController`: `$employee = $request->user()->employee ?? throw NotAnEmployee`; build the response from `LeaveType::where('office_id',$employee->current_office_id)->where('deducts_balance',true)->where('is_active',true)->get()` joined with `LeaveBalances::forEmployee($employee)` (absent type ⇒ 0), each decomposed via `LeaveUnit::readable(minutes, office.minutes_per_leave_day)`. `ListEmployeeLeaveController`: resolve the route-bound `{employee}` through `EmployeeScope::visibleTo($user)->whereKey($employee->id)->exists()` else 404, then the same body. `LeaveBalanceResource` shapes one row. Routes in the authed group (register `/me/leave` before any `/me/{param}` if applicable — there is none, so ordering is fine).

- [ ] **Step 4: Run — PASS.** Then `… pest tests/Feature/Leave tests/Arch`.

- [ ] **Step 5: Commit** `git commit -m "Leave: derived balance reads for self and overseen employees"`

---

## Task 7: RBAC `leave.manage` + seed the statutory set

**Files:**
- Modify: `database/seeders/RbacSeeder.php` (add `leave.manage` to `HR_PERMISSIONS`), `database/seeders/CompanySeeder.php` (seed leave types per office)
- Test: `tests/Feature/Leave/LeaveSeedTest.php`

**Interfaces:**
- Produces: `leave.manage` seeded to the `HR Admin` role; each seeded office gets the statutory leave-type set + company VL/SL.

- [ ] **Step 1: Failing test**

`LeaveSeedTest` (`seed(DatabaseSeeder::class)` or the specific seeders): after seeding, the `HR Admin` role has `leave.manage`; Manila and Cebu each have leave types including `code='sil'` (`deducts_balance=true`, `is_cash_convertible=true`), `maternity`/`paternity`/`solo_parent`/`vawc`/`magna_carta` (`deducts_balance=false`), and company `VL`/`SL` (`deducts_balance=true`). Every office's `minutes_per_leave_day` defaults to 480.

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: RBAC**

In `RbacSeeder`, add `'leave.manage'` to the `HR_PERMISSIONS` const array (the seeder already `findOrCreate`s each and syncs to `HR Admin`). Note in a comment: enforcement is via `OfficeScope` (the same as `holiday.manage`/`schedule.manage`), so this widens the catalog, not a new code gate.

- [ ] **Step 4: Seed leave types**

In `CompanySeeder`, add a `seedLeaveTypes(Office $office)` helper called for Manila and Cebu, creating the rows via `LeaveType::create` (direct model write, like the seeded holidays): SIL (`code:'sil'`, paid, deducts_balance, cash_convertible, `max_carryover_minutes: null`); the five event types (`deducts_balance:false`, paid); company `Vacation Leave` (`code:'vl'`) and `Sick Leave` (`code:'sl'`) (deducts_balance, paid). Grant NOTHING (accrual is deferred; balances start empty) — the seed is config only, matching how holidays seed config without computing anything.

- [ ] **Step 5: Run — PASS.** Then confirm `make test-backend`-style full run is green.

- [ ] **Step 6: Commit** `git commit -m "Leave: leave.manage permission + seed the PH statutory set + VL/SL per office"`

---

## Task 8: Frontend data layer — types, client, keys, hooks

**Files:**
- Modify: `frontend/web/src/lib/api.ts`, `frontend/web/src/lib/keys.ts`
- Create: `src/hooks/{useLeaveTypes,useSaveLeaveType,useSetLeaveDay,useMyLeave,useEmployeeLeave,useGrantLeave}.ts` (+ `.test.tsx` each)

**Interfaces:**
- Produces (verified against `LeaveTypeResource`/`LeaveBalanceResource`):

```ts
export type LeaveUnitName = 'day' | 'half_shift' | 'hour' | 'minute'
export type LeaveType = {
  id: string; office_id: string; name: string; code: string | null
  is_paid: boolean; requires_attachment: boolean; deducts_balance: boolean
  is_cash_convertible: boolean; max_carryover_minutes: number | null; is_active: boolean
}
export type LeaveTypeInput = Omit<LeaveType, 'id' | 'office_id'> & { office_id?: string }
export type LeaveBalance = {
  leave_type: LeaveType
  balance_minutes: number
  balance_readable: { days: number; hours: number; minutes: number }
}
export type LeaveGrantInput = { employee_id: string; leave_type_id: string; amount: number; unit: LeaveUnitName; reason: string }
```
- `api.leave`: `types(office)`, `createType(body)`, `updateType(id, body)`, `setLeaveDay(office_id, minutes_per_leave_day)`, `myBalances()`, `employeeBalances(employeeId)`, `grant(body)`. All JSON through the envelope client.
- `keys.leave`: `types(officeId)`, `myBalances()`, `employeeBalances(employeeId)`.

- [ ] **Step 1** Add `keys.leave` to `keys.ts`.
- [ ] **Step 2** Add the wire types + `api.leave.*` to `api.ts` (JSON bodies, `Content-Type: application/json`).
- [ ] **Step 3** Write each hook + its `.test.tsx` (mirror `useHolidays`/`useShiftTemplates` query+mutation hooks and their QueryClientProvider test wrapper): queries `useLeaveTypes(office)`, `useMyLeave()`, `useEmployeeLeave(id)`; mutations `useSaveLeaveType` (create/update → invalidate `keys.leave.types(office)`), `useSetLeaveDay`, `useGrantLeave` (→ invalidate the affected employee's balance key + `keys.leave.myBalances()`). Each test mocks `@/lib/api` and asserts the right call + invalidation.
- [ ] **Step 4** `… npm test -- src/hooks/useLeave src/hooks/useMyLeave src/hooks/useGrantLeave …` PASS; `… npm run typecheck` clean.
- [ ] **Step 5: Commit** `git commit -m "Leave (web): wire types, client, keys, and hooks"`

---

## Task 9: Frontend screens — `/office/leave-types`, `/me/leave`, grant, nav

**Files:**
- Create: `src/components/domain/LeaveDuration.tsx` (+ test), `src/app/(app)/office/leave-types/page.tsx`, `src/app/(app)/me/leave/page.tsx`
- Modify: `src/components/SideNav.tsx` (+ `SideNav.test.tsx`)

**Interfaces:**
- `<LeaveDuration minutes readable />` — renders `{days,hours,minutes}` as "5 days" / "1 day 1 hr 15 min" from the readable decomposition (mirrors backend `LeaveUnit::readable`; do not recompute from minutes if the backend already sent `readable`).

- [ ] **Step 1** `LeaveDuration.test.tsx`: `{days:5,hours:0,minutes:0}` → "5 days"; `{days:1,hours:1,minutes:15}` → "1 day 1 hr 15 min"; `{days:0,hours:0,minutes:0}` → "0 days". Build it, tokens-only.
- [ ] **Step 2** `/office/leave-types` (HR): list the office's types (needs an office context — reuse how `/office/holidays`/`/office/schedules` pick the office, e.g. the session's first HR office or an office picker; match those pages exactly), each showing its flags; a create/edit form (name, code, the flag toggles, max-carryover); a "Leave day (minutes)" setter → `useSetLeaveDay`. Loading `Skeleton`, empty `EmptyState`. Component/page test with mocked hooks.
- [ ] **Step 3** `/me/leave` (everyone): the caller's balances — one row per type via `<LeaveDuration>`, with the type's flags (paid, cash-convertible). Loading/empty states. Test.
- [ ] **Step 4** HR grant form: from the leave-types area (or a `/office/leave` grant panel), employee + type + amount + unit + reason → `useGrantLeave`; on success invalidate + toast. Test the unit select + required reason + submit-disabled-until-valid.
- [ ] **Step 5** Nav (`SideNav.tsx` + test): `ROUTES.me` gains `{href:'/me/leave', label:'Leave'}`; `ROUTES.office` gains `{href:'/office/leave-types', label:'Leave types'}`. Assert in `SideNav.test.tsx` (every user's `me` has `/me/leave`; an `hr_offices` user's `office` has `/office/leave-types`).
- [ ] **Step 6** `… npm test && npm run typecheck && npm run build` all green.
- [ ] **Step 7: Commit** `git commit -m "Leave (web): leave-types config, my-leave balances, HR grant, nav"`

---

## Task 10: e2e + docs

**Files:**
- Create: `scripts/e2e-leave-foundation.sh`
- Modify: `docs/02-data-model.md`, `docs/05-rbac.md`, `docs/06-roadmap.md`, `docs/features.md`

- [ ] **Step 1** `scripts/e2e-leave-foundation.sh` (mirror `scripts/e2e-recompute.sh` auth/jq idiom, `set -euo pipefail`, non-zero on any failed assertion): log in as `hr.manila`; `POST /office/leave-types` a "Vacation Leave" type (capture id); `PATCH /office/leave-day` set 480; `POST /leave/grants` `{employee: MNL-0002, type, amount:5, unit:'day', reason:'...'}`; assert exactly one `leave_ledger` credit row of 2400 minutes exists (via a follow-up read); log in as `employee.manila`; `GET /me/leave` → the VL balance is `balance_minutes: 2400`, `balance_readable.days: 5`. RUN IT LIVE against the dev stack, exit 0, paste output into the report.
- [ ] **Step 2** Docs: `02-data-model.md` — the `leave_types` + `leave_ledger` schema + the derived-balance/append-only rules + `offices.minutes_per_leave_day`. `05-rbac.md` — `leave.manage` now enforced (via OfficeScope) for leave config/grant. `06-roadmap.md` — M6b-a done, M6b-b (requests + two-hop machine) next. `features.md` — a "Leave — setup and balances (M6b-a)" section (per-office types, HR grants logged to the ledger, balances derived; taking leave + approval deferred).
- [ ] **Step 3** Full gate: backend `… pest`, web `npm test && typecheck && build`; both green.
- [ ] **Step 4: Commit** `git commit -m "Leave: e2e-leave-foundation.sh + docs; M6b-a complete"`

---

## Self-review — spec coverage

- `minutes_per_leave_day` → T1. `leave_types` (per-office, flags, `deducts_balance` axis, `code`, no delete) → T2 (schema/model) + T4 (config CRUD). `leave_ledger` append-only + derived `LeaveBalances` + `LeaveUnit` conversion → T3. Manual `GrantLeave` (one credit row, HR-scope + `deducts_balance` guards, minutes via office leave-day, logged) → T5. Balance reads (self + overseen, EmployeeScope, 404-not-403) → T6. `leave.manage` + seed statutory set/VL-SL per office → T7. Frontend (`/office/leave-types`, `/me/leave`, grant, nav, readable decomposition) → T8 (data) + T9 (screens). e2e + docs → T10.
- Balances derived-never-stored (T3/T6), ledger append-only (T3), 404-not-403 (T4/T5/T6), envelope, integer minutes — all honored. Deferred items (taking, two-hop, accrual, carryover, cash-out, compute) have **no task**, as intended.
- RBAC note: the spec said "gated by `leave.manage` + OfficeScope"; the plan enforces via **OfficeScope** (the codebase's actual config boundary — `holiday.manage` etc. are catalogued, not code-checked) and catalogues `leave.manage` (T7). Consistent with every existing config resource; flagged here so the reviewer sees the intentional alignment.
