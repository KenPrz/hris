# M6b-b — Leave requests + two-hop machine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Evolve the single-step request spine into a two-hop manager→HR machine, add leave as a request type that debits the ledger on final approval, and make the compute engine price an approved leave day as `leave_with_pay`.

**Architecture:** The spine (`RequestState`/`RequestType`/`RequestAuthority`/`ApprovalQueues`/`ApproveRequest`/`RequestEffect`) gains state-awareness while staying type-agnostic; leave plugs in as a new `RequestType`, a 1:1 `leave_details` table, and a `LeaveEffect` that debits `leave_ledger` (M6b-a) on the final hop only. Compute reads approved leave through a pure `LeaveDays` fact on `DailyComputationInput` and recomputes affected days on approval via M5b's `RecomputeRange`.

**Tech Stack:** Laravel 13 / PHP 8.5 / PostgreSQL 18 (Pest, real Postgres) · Next 16 / React 19 / TS / `@tanstack/react-query` / Carbon tokens (Vitest).

## Global Constraints

- `declare(strict_types=1);` atop every PHP file in `app/`/`tests/` (arch-enforced).
- uuid v7 PKs (`DB::raw('uuidv7()')` default + `HasUuids` + `newUniqueId()`/`uniqueIds()`); **string column + PHP enum + `CHECK`, never a native enum** (schema test pins each CHECK to `Enum::cases()`; add a new enum to the Arch "domain value objects are final" ignore-list if flagged).
- Integer minutes only, never a float. Calendar dates are `YYYY-MM-DD` strings.
- **The `leave_ledger` is append-only** (no updates/deletes; M6b-a). **No debit is written before the request reaches `approved`** (the final hop). Balances are derived, never stored.
- One action = one route = one final Action class; Input DTO / domain args; never touches HTTP. Controllers final + invokable.
- Domain framework-agnostic EXCEPT the ORM/Eloquent is allowed (precedent `EmployeeScope`/`RequestAuthority`/`LeaveBalances`); `Bus`/facades stay in Actions (precedent `RecomputeRange`).
- Envelope: success `{"data":...}` / error `{"error":...}`. **404-not-403** for out-of-scope office/employee/request/leave-type (FormRequests validate ids shape-only, never `exists:`; scope check in the controller/action). **FormRequest validation → HTTP 400 `validation_failed`; thrown domain exceptions → 422; unauthorized hop / out-of-scope → 404; second decision on a terminal request → 409.**
- Tests run against real PostgreSQL, never SQLite.
- Frontend: keys from `keys.ts`, requests through `api.ts`; components use only `carbon.css` `var(--*)` tokens; `erasableSyntaxOnly`.
- Commit messages carry NO attribution trailers (message body only).

Test run commands (stack is up):
- Backend: `docker compose -f compose.dev.yml exec -T -e DB_DATABASE=hris_test --user hris api php -d memory_limit=512M ./vendor/bin/pest <path>`
- Web: `docker compose -f compose.dev.yml exec -T --user node web sh -c 'npm test -- <pat> && npm run typecheck'`

---

## File Structure

**Backend — new:** migrations (`…_add_two_hop_to_requests.php`, `…_create_leave_details_table.php`, `…_widen_leave_ledger_source.php`, `…_add_leave_with_pay_summary_kind.php`, `…_add_leave_recompute_trigger.php`); `app/Models/LeaveDetail.php` + factory; `app/Domain/Leave/LeaveDays.php`; `app/Actions/Leave/SubmitLeaveRequest.php` (+ Input) + `app/Http/Controllers/Leave/SubmitLeaveRequestController.php` + `app/Http/Requests/SubmitLeaveRequestRequest.php`; `app/Actions/Requests/Effects/LeaveEffect.php`; `app/Exceptions/Domain/{InsufficientLeaveBalance,LeaveTypeInactive}.php`.
**Backend — modify:** `RequestState.php`, `RequestType.php`, `RequestAuthority.php`, `ApprovalQueues.php`, `ApproveRequest.php`, `RejectRequest.php`, `RequestEffectFactory.php`, `RequestResource.php`, `Request.php` (relation); `RecomputeTrigger.php`; `SummaryLineKind.php`, `DailyComputation.php`, `DailyComputationInput.php`, `ComputeDailySummary.php`; `GrantLeave.php`/`GrantController.php` (is_active); `routes/api.php`; `tests/Arch/ConventionsTest.php` (enum ignore-lists).
**Frontend — new:** `src/hooks/useSubmitLeaveRequest.ts` (+test); `src/app/(app)/me/leave/request/*` or a form component `src/components/domain/LeaveRequestForm.tsx` (+test).
**Frontend — modify:** `src/lib/api.ts` (`RequestType`/`RequestState`/`RequestDetail` union + `api.leave.submitRequest`), `keys.ts`; `src/components/domain/RequestCard.tsx`; the request/attendance pages for the `manager_approved` state.
**Docs/scripts:** `scripts/e2e-leave.sh`; `docs/02-data-model.md`, `03-api.md`, `05-rbac.md`, `06-roadmap.md`, `features.md`.

---

## Task 1: Two-hop `requests` schema + `RequestState`

**Files:** Create migration `…_add_two_hop_to_requests.php`; Modify `app/Domain/Requests/RequestState.php`; Test `tests/Feature/Schema/RequestSchemaTest.php` (extend).

**Interfaces produced:** `RequestState::ManagerApproved = 'manager_approved'`; `requests.state` CHECK = 5 values; `requests.manager_decided_by` (nullable FK users), `manager_decided_at` (nullable timestamptz).

- [ ] **Step 1: Migration** — widen the CHECK (drop + re-add) and add the columns:

```php
public function up(): void {
    Schema::table('requests', function (Blueprint $t): void {
        $t->foreignUuid('manager_decided_by')->nullable()->after('decided_at')->constrained('users')->nullOnDelete();
        $t->timestampTz('manager_decided_at')->nullable()->after('manager_decided_by');
    });
    DB::statement('ALTER TABLE requests DROP CONSTRAINT requests_state_check');
    DB::statement("ALTER TABLE requests ADD CONSTRAINT requests_state_check CHECK (state IN ('pending','manager_approved','approved','rejected','cancelled'))");
}
public function down(): void {
    DB::statement('ALTER TABLE requests DROP CONSTRAINT requests_state_check');
    DB::statement("ALTER TABLE requests ADD CONSTRAINT requests_state_check CHECK (state IN ('pending','approved','rejected','cancelled'))");
    Schema::table('requests', fn (Blueprint $t) => $t->dropColumn(['manager_decided_by','manager_decided_at']));
}
```

- [ ] **Step 2: Failing schema test** — a raw `DB::table('requests')->insert([... 'state' => 'manager_approved' ...])` succeeds (was rejected before); `'state' => 'bogus'` still throws `QueryException`. Add `manager_decided_by`/`manager_decided_at` round-trip.
- [ ] **Step 3: Run — FAIL.**
- [ ] **Step 4: Add the enum case** — in `RequestState.php` add `case ManagerApproved = 'manager_approved';` and update the docblock ("pending → [manager_approved →] approved | rejected | cancelled"). Add `manager_decided_by`/`manager_decided_at` to `Request` `$fillable`/casts (`manager_decided_at` datetime).
- [ ] **Step 5: Run — PASS**, then `… pest tests/Arch tests/Feature/Requests` (existing single-step tests must stay green — the new state is additive).
- [ ] **Step 6: Commit** `git commit -m "Requests: two-hop state (manager_approved) + manager-decision columns"`

---

## Task 2: `RequestType::Leave` + `requiresHrStep()`

**Files:** Create migration `…_add_leave_request_type.php`; Modify `app/Domain/Requests/RequestType.php`; Test extend `RequestSchemaTest`.

**Interfaces produced:** `RequestType::Leave = 'leave'`; `RequestType::requiresHrStep(): bool` (`AttendanceAdjustment => false`, `Leave => true`); `requests.type` CHECK = `('attendance_adjustment','leave')`.

- [ ] **Step 1: Migration** — drop + re-add `requests_type_check` with the two values (down reverts to one).
- [ ] **Step 2: Failing test** — raw insert `type='leave'` succeeds; `type='bogus'` throws.
- [ ] **Step 3: Run — FAIL.**
- [ ] **Step 4: Enum** — add `case Leave = 'leave';` and:

```php
public function requiresHrStep(): bool {
    return match ($this) {
        self::AttendanceAdjustment => false,
        self::Leave => true,
    };
}
```
Add a focused unit test asserting both values.
- [ ] **Step 5: Run — PASS**, `… pest tests/Arch`.
- [ ] **Step 6: Commit** `git commit -m "Requests: add the leave request type + requiresHrStep()"`

---

## Task 3: State-aware `RequestAuthority`

**Files:** Modify `app/Domain/Requests/RequestAuthority.php`; Test `tests/Feature/Requests/RequestAuthorityTest.php` (new — the per-hop matrix).

**Interfaces produced:** `RequestAuthority::canDecide(User $approver, Request $request): bool` — now state+type aware. Helpers `isManagerOf(User,Request): bool`, `isHrOf(User,Request): bool`.

**Consumes:** `RequestState`, `RequestType::requiresHrStep()`, `EmployeeScope`, `hrAdminOffices`.

- [ ] **Step 1: Failing test** — a matrix over `(state, type, approver-relationship)`:
  - single-hop (`attendance_adjustment`) `pending`: manager-of ✓, HR-of ✓, self ✗, stranger ✗.
  - two-hop (`leave`) `pending`: manager-of ✓, HR-of ✗ (not their turn), self ✗.
  - two-hop `manager_approved`: HR-of ✓ **unless** the approver is `manager_decided_by` (✗), manager-of-only ✗ (not their turn), self ✗.
  - any terminal state (`approved`/`rejected`/`cancelled`): everyone ✗ (nothing to decide).
  Seed employees with `current_reports_to_id` / `hrAdminOffices` via the `tests/Feature/Requests/support.php` helpers (extend them).

- [ ] **Step 2: Run — FAIL.**
- [ ] **Step 3: Implement**:

```php
final class RequestAuthority
{
    public static function isManagerOf(User $approver, Request $request): bool {
        $mgrEmployeeId = $approver->employee?->id;
        return $mgrEmployeeId !== null && $request->employee->current_reports_to_id === $mgrEmployeeId;
    }

    public static function isHrOf(User $approver, Request $request): bool {
        $officeId = $request->employee->current_office_id;
        return $officeId !== null && $approver->hrAdminOffices()->whereKey($officeId)->exists();
    }

    /** May $approver act on $request's CURRENT hop? */
    public static function canDecide(User $approver, Request $request): bool {
        // Never self.
        if ($approver->employee?->id === $request->employee_id) {
            return false;
        }
        $twoHop = $request->type->requiresHrStep();
        return match ($request->state) {
            RequestState::Pending => $twoHop
                ? self::isManagerOf($approver, $request)                       // hop 1: manager only
                : (self::isManagerOf($approver, $request) || self::isHrOf($approver, $request)),
            RequestState::ManagerApproved => self::isHrOf($approver, $request)  // hop 2: HR only,
                && $approver->id !== $request->manager_decided_by,             // and not the hop-1 approver
            default => false,                                                   // terminal states
        };
    }
}
```
Note the old `EmployeeScope::visibleTo`-based single boolean is replaced; the `isManagerOf || isHrOf` union reproduces the old single-hop behavior (`visibleTo` = self ∪ reports ∪ HR-offices ∪ sysadmin — but sysadmin is intentionally NOT an approver, per M6a's queue decision, so the explicit manager/HR union is correct and narrower). Confirm the existing M6a decision tests still pass (a sysadmin was already excluded from the queues; if any test asserted a sysadmin *could* `canDecide`, that was the leak M6a's queues already closed — reconcile with the reviewer).

- [ ] **Step 4: Run — PASS**, then `… pest tests/Feature/Requests tests/Feature/Attendance` (the M6a attendance decision tests must stay green: single-hop pending is still manager-or-HR).
- [ ] **Step 5: Commit** `git commit -m "Requests: state-aware per-hop authority (manager hop 1, HR hop 2)"`

---

## Task 4: Hop-aware `ApprovalQueues`

**Files:** Modify `app/Domain/Requests/ApprovalQueues.php`; Test `tests/Feature/Requests/ApprovalQueuesTest.php` (extend).

**Interfaces produced:** `directReportsOf(User): Builder` (unchanged predicate: `state=pending` from direct reports); `hrOfficesOf(User): Builder` — now `(state=pending AND type single-hop) OR state=manager_approved`.

- [ ] **Step 1: Failing test** — add cases: a two-hop (`leave`) `pending` appears in the requester's manager `/team` queue but NOT in the office HR `/office` queue; a `leave` `manager_approved` appears in `/office` but NOT `/team`; a single-hop `attendance_adjustment` `pending` appears in BOTH (unchanged); never self; the bare-actor/no-employee case still empty (M6b-a guard preserved).
- [ ] **Step 2: Run — FAIL.**
- [ ] **Step 3: Implement** — `directReportsOf` keeps its `pending()` base filtered to `state=pending`. Change `hrOfficesOf` to filter:

```php
// single-hop request types (those with requiresHrStep()===false). Kept explicit and in sync
// with RequestType::requiresHrStep() — as new single-hop types are added, list them here.
$singleHopTypes = [RequestType::AttendanceAdjustment->value];

return Request::query()
    ->whereIn('employee_id', $memberIds)
    ->where('employee_id', '!=', $user->employee?->id)
    ->where(function (Builder $q) use ($singleHopTypes): void {
        $q->where(fn (Builder $s) => $s->where('state', RequestState::Pending)->whereIn('type', $singleHopTypes))
          ->orWhere('state', RequestState::ManagerApproved);
    })
    ->latest();
```
(`directReportsOf` stays `state=pending` from reports — both types; that's the manager's hop for two-hop and the manager option for single-hop.) Note: the `$singleHopTypes` array mirrors `requiresHrStep()`; a task-6+ reviewer should confirm it stays consistent.

- [ ] **Step 4: Run — PASS**, `… pest tests/Feature/Requests`.
- [ ] **Step 5: Commit** `git commit -m "Requests: hop-aware /office queue (single-hop pending or manager_approved)"`

---

## Task 5: Deferred effect in `ApproveRequest` + `RejectRequest` hop-gate

**Files:** Modify `app/Actions/Requests/ApproveRequest.php`, `RejectRequest.php`; Test `tests/Feature/Requests/TwoHopApprovalTest.php` (new).

**Interfaces produced:** `ApproveRequest::execute(Request, User): Request` — advances one hop; fires the effect **only** on the transition to `approved`. `RejectRequest` uses `canDecide` (either hop's approver may reject).

**Consumes:** `RequestAuthority::canDecide` (Task 3), `RequestType::requiresHrStep()`, `RequestEffectFactory`, `RequestState`.

- [ ] **Step 1: Failing test** — using the existing `attendance_adjustment` effect as the observable:
  - single-hop approve: `pending → approved`, effect fired once, `decided_by` set.
  - two-hop (use a `leave` request with a stub/real leave detail — or, to keep this task effect-agnostic, assert against a **spy**/the state only): manager approve `pending → manager_approved`, `manager_decided_by`/`manager_decided_at` set, `decided_by` still null, **effect NOT fired**; then HR approve `manager_approved → approved`, `decided_by` set, effect fired exactly once.
  - reject at hop 1 (manager) `pending → rejected`; reject at hop 2 (HR) `manager_approved → rejected`; both require a decision note; a non-authorized-for-this-hop actor → 404; a second decision on a terminal → 409.
  (Task 8 provides the real `LeaveEffect`; here, if no leave effect exists yet, drive the two-hop assertions with a `leave`-typed request whose effect you register as a test double via the container, OR land Task 8 first and reference it. Recommended: keep this task's two-hop assertions on STATE + `manager_decided_*` + "no ledger row yet", and let Task 8's test assert the debit. Coordinate with the controller: Task 8 registers `LeaveEffect`; until then the factory throws for `leave`, so this task's two-hop path must use a request type whose effect is side-effect-free at the final hop — use `attendance_adjustment` configured... it's single-hop. So: land Task 8's `LeaveEffect` BEFORE this task's two-hop test, or register a no-op test effect. The controller for this plan lands Task 5 AFTER Task 8's effect exists — see execution note.)

  **Execution note:** implement Task 8 (`LeaveEffect` + `RequestEffectFactory` arm) and Task 6 (`leave_details`) BEFORE this task's two-hop test can exercise a real `leave` request end-to-end. If executing in order, write Task 5's SINGLE-hop assertions + the state-machine transition logic first (provable with `attendance_adjustment`), and add the two-hop effect-deferral assertions once Task 8 lands (a follow-up step in Task 8). The transition logic itself (below) is what Task 5 delivers.

- [ ] **Step 2: Run — FAIL.**
- [ ] **Step 3: Implement the transition** — replace `ApproveRequest`'s single state write with:

```php
// inside the transaction, after lock + canDecide(404) + not-terminal(409):
$twoHop = $locked->type->requiresHrStep();
$isFinalHop = ! $twoHop || $locked->state === RequestState::ManagerApproved;

if ($isFinalHop) {
    // fire the effect ONLY on the transition into approved
    $this->effects->for($locked->type)->applyOnApproval($locked, $approver->id);
    $locked->update([
        'state' => RequestState::Approved,
        'decided_by' => $approver->id,
        'decided_at' => now(),
    ]);
} else {
    // hop 1 (manager) of a two-hop request: advance, NO effect, record the manager decision
    $locked->update([
        'state' => RequestState::ManagerApproved,
        'manager_decided_by' => $approver->id,
        'manager_decided_at' => now(),
    ]);
}
```
Replace the M6a "not pending → 409" guard with "state is terminal (`approved`/`rejected`/`cancelled`) → 409" so a `manager_approved` request is still actionable (a `RequestNotActionable`/reuse `RequestNotPending` renamed — keep the existing exception, broaden its meaning + message). `RejectRequest`: swap its `canDecide` gate to the new signature and broaden its "pending" guard to "non-terminal" the same way (reject from `pending` OR `manager_approved`).

- [ ] **Step 4: Run — PASS**, `… pest tests/Feature/Requests tests/Feature/Attendance`.
- [ ] **Step 5: Commit** `git commit -m "Requests: two-hop approval — advance per hop, effect only on final approval"`

---

## Task 6: `leave_details` + model + `RequestResource` branch

**Files:** Create migration `…_create_leave_details_table.php`, `app/Models/LeaveDetail.php`, `database/factories/LeaveDetailFactory.php`, `tests/Feature/Schema/LeaveDetailSchemaTest.php`; Modify `app/Models/Request.php` (`leaveDetail()`), `app/Http/Resources/RequestResource.php`.

**Interfaces produced:** `leave_details(request_id PK, leave_type_id, start_date, end_date, day_part, amount_minutes)`; `Request::leaveDetail(): HasOne`; `RequestResource` serializes the leave detail when `type === Leave`.

- [ ] **Step 1: Migration** — mirror `attendance_adjustment_details`:

```php
Schema::create('leave_details', function (Blueprint $t): void {
    $t->uuid('request_id')->primary();
    $t->foreign('request_id')->references('id')->on('requests')->cascadeOnDelete();
    $t->foreignUuid('leave_type_id')->constrained();
    $t->date('start_date');
    $t->date('end_date');
    $t->text('day_part');
    $t->integer('amount_minutes');
});
DB::statement("ALTER TABLE leave_details ADD CONSTRAINT leave_details_day_part_check CHECK (day_part IN ('full','half'))");
DB::statement('ALTER TABLE leave_details ADD CONSTRAINT leave_details_amount_pos_check CHECK (amount_minutes > 0)');
DB::statement('ALTER TABLE leave_details ADD CONSTRAINT leave_details_dates_check CHECK (end_date >= start_date)');
```
- [ ] **Step 2: Schema test** — CHECK rejects `day_part='bogus'`, `amount_minutes=0`, `end_date < start_date` (raw inserts); round-trip.
- [ ] **Step 3: Run — FAIL.**
- [ ] **Step 4: Model + factory + relation + resource** — `LeaveDetail` (`HasUuids`? no own id — PK is `request_id`, not uuid-generated; use `$incrementing=false`, `$keyType='string'`, `protected $primaryKey='request_id'`, no `HasUuids`; mirror `AttendanceAdjustmentDetail`). Casts `start_date`/`end_date` date, `amount_minutes` integer. `Request::leaveDetail(): HasOne` on `request_id`. In `RequestResource::toArray`, branch:

```php
'detail' => match ($this->type) {
    RequestType::AttendanceAdjustment => $this->attendanceAdjustmentDetail === null ? null : [/* existing shape */],
    RequestType::Leave => $this->leaveDetail === null ? null : [
        'leave_type_id' => $this->leaveDetail->leave_type_id,
        'start_date' => $this->leaveDetail->start_date->toDateString(),
        'end_date' => $this->leaveDetail->end_date->toDateString(),
        'day_part' => $this->leaveDetail->day_part,
        'amount_minutes' => $this->leaveDetail->amount_minutes,
    ],
},
```
- [ ] **Step 5: Run — PASS**, `… pest tests/Feature/Schema tests/Arch`.
- [ ] **Step 6: Commit** `git commit -m "Leave: leave_details 1:1 table + model + RequestResource detail branch"`

---

## Task 7: `SubmitLeaveRequest` + `POST /leave/requests` + is_active guard

**Files:** Create `app/Domain/Leave/LeaveDays.php`, `app/Actions/Leave/SubmitLeaveRequest.php` (+Input), `app/Http/Controllers/Leave/SubmitLeaveRequestController.php`, `app/Http/Requests/SubmitLeaveRequestRequest.php`, `app/Exceptions/Domain/LeaveTypeInactive.php`; Modify `app/Actions/Leave/GrantLeave.php`/`GrantController.php` (is_active), `routes/api.php`; Test `tests/Feature/Leave/SubmitLeaveRequestTest.php`.

**Interfaces produced:** `LeaveDays::scheduledWorkingDays(Employee, string $start, string $end): list<string>` (the dates in range that are scheduled working days, via `ScheduleResolver`). `SubmitLeaveRequest::execute(SubmitLeaveRequestInput): Request`. `POST /leave/requests`.

- [ ] **Step 1: `LeaveDays` + test** — iterate `CarbonPeriod($start,$end)`; for each date, `(new ScheduleResolver)->resolve($employee, $date)`; include the date when `! $resolved->isRestDay && $resolved->scheduledMinutes > 0`. Test: a Mon–Fri schedule over a Mon–Sun range returns the 5 weekdays.
- [ ] **Step 2: Failing submit test** — an employee (with a manager) requests `full` leave over a 3-working-day range on a `deducts_balance` type → a `pending` `Request` + a `leave_details` row with `amount_minutes = 3 × office.minutes_per_leave_day`; a requester with NO manager → the request is created `manager_approved`; an inactive leave type → 422 (`LeaveTypeInactive`); a `requires_attachment` type with no file → 422; a range with zero scheduled working days → 422; a foreign-office leave type → 404.
- [ ] **Step 3: Run — FAIL.**
- [ ] **Step 4: Implement** — `SubmitLeaveRequestRequest`: `authorize():true`; `leave_type_id`/ids `uuid` shape-only, `start_date`/`end_date` `date`, `end_date` `after_or_equal:start_date`, `day_part` `Rule::in(['full','half'])`, `note` `required|string`, `attachment` `nullable|file|mimes:pdf,jpg,jpeg,png|max:10240`. Controller resolves the employee (`$request->user()->employee` or `NotAnEmployee`), resolves the leave type scoped to `employee.current_office_id` (404 if not), computes minutes, calls the action, returns `RequestResource` 201. `SubmitLeaveRequest::execute` (one transaction):
  - guard `leaveType.is_active` else `throw new LeaveTypeInactive` (422); guard `leaveType.requires_attachment ⇒ attachment present` else 422.
  - `$days = LeaveDays::scheduledWorkingDays(...)`; `count($days) === 0 ⇒ 422`.
  - `$perDay = LeaveUnit::toMinutes(1, $dayPart === 'half' ? 'half_shift' : 'day', $office->minutes_per_leave_day)`; `$amount = count($days) * $perDay`.
  - `$initialState = $employee->current_reports_to_id === null ? RequestState::ManagerApproved : RequestState::Pending;`
  - create `Request` (`type: Leave`, `employee_id`, `state: $initialState`, `note`) + `leave_details` row + optional attachment (media). Return `$request->fresh(['leaveDetail'])`.
  - Fold in the **is_active guard on GrantLeave**: in `GrantController` (M6b-a), after the `deducts_balance` guard add `if (! $leaveType->is_active) throw new LeaveTypeInactive;` (reuse the same exception). Add a `GrantLeaveTest` case (grant into inactive → 422).
- [ ] **Step 5: Route** — `Route::post('/leave/requests', SubmitLeaveRequestController::class);` (authed group).
- [ ] **Step 6: Run — PASS**, `… pest tests/Feature/Leave tests/Arch`.
- [ ] **Step 7: Commit** `git commit -m "Leave: file a leave request (amount from scheduled days, no-manager→manager_approved, is_active guard)"`

---

## Task 8: `LeaveEffect` (debit on final hop) + recompute-on-approval

**Files:** Create `app/Actions/Requests/Effects/LeaveEffect.php`, `app/Exceptions/Domain/InsufficientLeaveBalance.php`, migrations `…_widen_leave_ledger_source.php` + `…_add_leave_recompute_trigger.php`; Modify `app/Actions/Requests/RequestEffectFactory.php`, `app/Domain/Compute/RecomputeTrigger.php`, `tests/Arch/ConventionsTest.php`; Test `tests/Feature/Leave/LeaveEffectTest.php` (+ finish Task 5's two-hop effect assertions).

**Interfaces produced:** `LeaveEffect implements RequestEffect`; `RequestEffectFactory` `Leave => app(LeaveEffect::class)`; `leave_ledger.source` CHECK += `leave_taken`; `RecomputeTrigger::Leave`; `recompute_runs.trigger_type` CHECK += `leave`.

- [ ] **Step 1: Migrations** — drop+re-add `leave_ledger_source_check` to `('manual_grant','leave_taken')`; drop+re-add `recompute_runs`'s `trigger_type` CHECK to include `'leave'`. Add `RecomputeTrigger::Leave = 'leave'`; ensure `RecomputeTrigger` stays in the Arch enum ignore-list (already there from M5b — no change if the whole enum is ignored by name).
- [ ] **Step 2: Failing test** — approving a two-hop `leave` request (drive it `pending → manager_approved → approved` via `ApproveRequest`): at `manager_approved` **no `leave_ledger` row exists**; at `approved` exactly one `debit` row (`source='leave_taken'`, `minutes=amount_minutes`, `request_id`, `created_by`=HR approver), and `LeaveBalances::forEmployee` dropped by that amount. Insufficient balance (amount > balance) → the HR approval throws `InsufficientLeaveBalance` (422), the whole tx rolls back (state stays `manager_approved`, no row). An **event type** (`deducts_balance=false`) approval → state `approved`, **no ledger row**. Assert a `recompute_runs` row (`trigger_type='leave'`) was created (`Bus::fake` + assert dispatched, mirroring the M5b RecomputeRange tests).
- [ ] **Step 3: Run — FAIL.**
- [ ] **Step 4: Implement** `LeaveEffect::applyOnApproval(Request $request, string $approverUserId)`:

```php
$detail = $request->leaveDetail;                 // loaded
$type = $detail->leaveType;                       // BelongsTo
if ($type->deducts_balance) {
    // balance under the employee lock the surrounding ApproveRequest tx already holds
    $balance = LeaveBalances::forEmployee($request->employee)[$type->id] ?? 0;
    if ($detail->amount_minutes > $balance) {
        throw new InsufficientLeaveBalance($type->id, $detail->amount_minutes, $balance);
    }
    LeaveLedger::query()->create([
        'employee_id' => $request->employee_id,
        'leave_type_id' => $type->id,
        'entry_type' => 'debit',
        'minutes' => $detail->amount_minutes,
        'reason' => "Leave request {$request->id} approved",
        'source' => 'leave_taken',
        'request_id' => $request->id,
        'created_by' => $approverUserId,
    ]);
}
// enqueue recompute over the leave span (both balance + event types — compute prices the days)
DB::afterCommit(function () use ($request, $detail): void {
    $pairs = collect(CarbonPeriod::create($detail->start_date, $detail->end_date))
        ->map(fn ($d): array => ['employee_id' => $request->employee_id, 'date' => $d->toDateString()]);
    RecomputeRange::dispatch($pairs, RecomputeTrigger::Leave, $request->id,
        "Leave request {$request->id} approved for employee {$request->employee_id}");
});
```
Register the factory arm. Because the effect runs inside `ApproveRequest`'s transaction, a thrown `InsufficientLeaveBalance` rolls the approval back exactly like `ApplyAttendanceAdjustment`'s target-annulment failure does — no state write.
- [ ] **Step 5: Run — PASS**; also complete Task 5's two-hop effect-deferral assertions now that `LeaveEffect` exists. `… pest tests/Feature/Leave tests/Feature/Requests tests/Arch`.
- [ ] **Step 6: Commit** `git commit -m "Leave: LeaveEffect debits the ledger on final approval + recompute the leave span"`

---

## Task 9: Compute reads approved leave → `leave_with_pay`

**Files:** Create `app/Domain/Leave/LeaveDayLookup.php` (or extend `LeaveDays`) migration `…_add_leave_with_pay_summary_kind.php`; Modify `app/Domain/Pay/SummaryLineKind.php`, `app/Domain/Compute/DailyComputationInput.php`, `app/Domain/Compute/DailyComputation.php`, `app/Actions/Compute/ComputeDailySummary.php`, `tests/Arch/ConventionsTest.php` (if flagged); Test `tests/Feature/Compute/LeaveWithPayTest.php`.

**Interfaces produced:** `SummaryLineKind::LeaveWithPay = 'leave_with_pay'`; `DailyComputationInput->onApprovedLeave: bool`; `ComputeDailySummary` resolves it and `DailyComputation` emits the line.

- [ ] **Step 1: Migration** — drop+re-add `daily_summary_lines` `dsl_kind_check` to include `'leave_with_pay'`.
- [ ] **Step 2: Failing test** — an employee with a scheduled Mon–Fri day, an **approved** full-day `leave` request covering that date, and **no punches** → `ComputeDailySummary::execute` produces a `leave_with_pay` line of `scheduled_minutes` at `applied_bp = 10000` (100%). A day WITH punches on an approved-leave date → priced from punches (no `leave_with_pay`). A rest day covered by leave → no line (leave is only on working days). A `pending`/`manager_approved` leave (not yet approved) → NO `leave_with_pay` (only `approved` counts).
- [ ] **Step 3: Run — FAIL.**
- [ ] **Step 4: Implement**:
  - `SummaryLineKind`: add `case LeaveWithPay = 'leave_with_pay';` (+ TS mirror in Task 10).
  - `LeaveDayLookup::isOnApprovedLeave(Employee $e, string $date): bool` — exists an `approved` `Request` of `type=leave` for `$e` whose `leaveDetail` span covers `$date`. (Domain query on Eloquent; add to Arch ignore-list if flagged.)
  - `DailyComputationInput`: add `public bool $onApprovedLeave` (readonly ctor arg).
  - `ComputeDailySummary::execute`: after resolving `$schedule`, compute `$onApprovedLeave = LeaveDayLookup::isOnApprovedLeave($employee, $date);` and pass it into the input. **Persist a `leave_with_pay` line even when no pay_rule version priced it?** No — `leave_with_pay` is a flat 100% independent of the pay_rules matrix, so it is a *configured* line and should persist regardless of `$payRule`. Adjust the "only persist priced lines when a pay_rule exists" gate: keep it for the punch-derived lines, but a `leave_with_pay` line (flat 10000, no rule needed) persists unconditionally. Simplest: compute `$lines = $payRule !== null ? $computed->lines : array_filter($computed->lines, fn($l) => $l->kind === SummaryLineKind::LeaveWithPay);` and set `rule_version_id` non-null only when a non-leave line exists. **Confirm this nuance with the reviewer** — a leave day with no configured pay-rule still shows leave_with_pay, with `rule_version_id` null.
  - `DailyComputation::computeUnworkedDay`: when `$in->onApprovedLeave && ! $in->isRestDay && $in->scheduledMinutes > 0`, emit `new ComputedLine(SummaryLineKind::LeaveWithPay, $in->scheduledMinutes, 10000)` (100% — a leave-with-pay minute is your normal day; not routed through the premium matrix). This branch takes precedence over the `holiday_unworked` branch (you don't double-pay a leave day that's also a paid holiday — leave wins; document it). Only the no-punches path (`computeUnworkedDay`) needs this — a day with punches never reaches here.
- [ ] **Step 5: Run — PASS**, `… pest tests/Feature/Compute tests/Arch`.
- [ ] **Step 6: Commit** `git commit -m "Compute: price an approved full-day leave day as leave_with_pay at 100%"`

---

## Task 10: Frontend data layer

**Files:** Modify `src/lib/api.ts`, `src/lib/keys.ts`; Create `src/hooks/useSubmitLeaveRequest.ts` (+test).

**Interfaces produced:** `RequestState` += `'manager_approved'`; `RequestType` += `'leave'`; `RequestDetail` union; `SummaryLineKind` += `'leave_with_pay'`; `LeaveRequestInput`; `api.leave.submitRequest` (multipart); `useSubmitLeaveRequest`.

- [ ] **Step 1** — widen the TS unions and add the leave detail shape as a discriminated union on `RequestRecord.type`:

```ts
export type RequestState = 'pending' | 'manager_approved' | 'approved' | 'rejected' | 'cancelled'
export type RequestType = 'attendance_adjustment' | 'leave'
export type AttendanceAdjustmentDetail = { operation: ...; target_log_id: ...; direction: ...; punched_at: ... }
export type LeaveRequestDetail = { leave_type_id: string; start_date: string; end_date: string; day_part: 'full'|'half'; amount_minutes: number }
export type RequestDetail = AttendanceAdjustmentDetail | LeaveRequestDetail | null
export type LeaveRequestInput = { leave_type_id: string; start_date: string; end_date: string; day_part: 'full'|'half'; note: string; attachment?: File | null }
export type SummaryLineKind = ... | 'leave_with_pay'
```
- [ ] **Step 2** — `api.leave.submitRequest(input)` builds `FormData` (no `Content-Type`, mirroring `api.adjustments.submit`) POST `/leave/requests`.
- [ ] **Step 3** — `useSubmitLeaveRequest` (mutation → `api.leave.submitRequest`; onSuccess invalidate `keys.requests.mine()` + `keys.leave.myBalances()`) + `.test.tsx` (mock `@/lib/api`, assert call + invalidation).
- [ ] **Step 4** — `… npm test -- useSubmitLeaveRequest && npm run typecheck` PASS.
- [ ] **Step 5: Commit** `git commit -m "Leave (web): request wire types (state/type/detail union) + submit hook"`

---

## Task 11: Frontend — leave request form, card, two-hop state

**Files:** Create `src/components/domain/LeaveRequestForm.tsx` (+test); Modify `src/app/(app)/me/leave/page.tsx` (entry point), `src/components/domain/RequestCard.tsx`, and the `Tag` rendering for `manager_approved` in `/me/requests` + the queues.

- [ ] **Step 1: `LeaveRequestForm` + test** — mirror `CorrectionForm`: an active-in-office leave-type `Select`, a date range (`start`/`end`), a `full`/`half` select, required note, optional attachment; it shows the **computed cost** (scheduled days × per-day → readable days, client-side approximation is fine for display; the server is authoritative) and the **current balance** for the selected type; submit disabled until required fields present; on success `onDone`. Test: selecting a type shows its balance; submit calls `useSubmitLeaveRequest` with the right payload; disabled-until-valid.
- [ ] **Step 2** — wire a "Request leave" button on `/me/leave` opening the form.
- [ ] **Step 3: `RequestCard`** — add `TYPE_LABEL.leave = 'Leave'` and a `summarizeLeave(detail)` case ("SIL · Aug 10–12 · 3 days"). Render a `manager_approved` state as a distinct `Tag` (e.g. "Awaiting HR") wherever request state is shown (`RequestCard`, `/me/requests`). Component test for the leave summary + the new tag.
- [ ] **Step 4** — `… npm test -- LeaveRequestForm RequestCard && npm run typecheck && npm run build` PASS.
- [ ] **Step 5: Commit** `git commit -m "Leave (web): request form, RequestCard leave summary, manager_approved tag"`

---

## Task 12: e2e + docs

**Files:** Create `scripts/e2e-leave.sh`; Modify `docs/02-data-model.md`, `03-api.md`, `05-rbac.md`, `06-roadmap.md`, `features.md`.

- [ ] **Step 1: `scripts/e2e-leave.sh`** (mirror `e2e-requests.sh`/`e2e-leave-foundation.sh`, `set -euo pipefail`, non-zero on any failed assertion). Flow: as `hr.manila`, ensure `employee.manila` (MNL-0002) has a granted SIL/VL balance (grant 10 days). As `employee.manila`, `POST /leave/requests` a full-day range of 3 scheduled working days on that type → assert `state=pending`, a `leave_details` amount of 3×480. Assert it's in the manager's `GET /team/approvals` and NOT the office `GET /office/approvals`. As `manager.manila`, `POST /requests/{id}/approve` → assert `state=manager_approved`, **no ledger debit yet** (balance unchanged), and now in `/office/approvals` not `/team/approvals`. As `hr.manila`, `POST /requests/{id}/approve` → assert `state=approved`, the balance dropped by 3×480, and `/me/attendance` (as the employee) shows `leave_with_pay` on those days. A second run: reject at HR → balance untouched. RUN LIVE, exit 0, paste output into the report.
- [ ] **Step 2: Docs** — `02-data-model.md`: the two-hop machine + `manager_decided_*` + `leave_details` + `leave_with_pay` kind + the `leave_taken` source. `03-api.md`: `POST /leave/requests`, the two-hop decision flow on `/requests/{id}/approve` (who decides which hop), the `manager_approved` state, `InsufficientLeaveBalance`/`LeaveTypeInactive` error codes. `05-rbac.md`: the manager-hop / HR-hop routing. `06-roadmap.md`: M6b-b done → M6 complete; M6c (overtime) next; note half-day-compute + leave-with-pay-export deferred to M7. `features.md`: a "Requesting leave (M6b-b)" section (file leave, manager approves, HR approves, balance debits, the day shows as paid leave).
- [ ] **Step 3: Full gate** — backend `… pest`; web `npm test && typecheck && build`; both green.
- [ ] **Step 4: Commit** `git commit -m "Leave: e2e-leave.sh + docs; M6b-b complete, M6 complete"`

---

## Self-review — spec coverage

- Two-hop states + `manager_decided_*` → T1. `Leave` type + `requiresHrStep()` → T2. State-aware per-hop authority (manager hop1, HR hop2, two-people rule) → T3. Hop-aware queues → T4. Deferred effect + hop transition + reject-hop-gate → T5. `leave_details` + resource branch → T6. `SubmitLeaveRequest` (amount from scheduled days, no-manager→manager_approved, inactive/attachment/zero-day guards, is_active-on-grant) → T7. `LeaveEffect` (debit final hop, InsufficientLeaveBalance, event-no-debit, recompute) + `leave_taken` source + `RecomputeTrigger::Leave` → T8. `leave_with_pay` compute + `LeaveDayLookup` → T9. Frontend union+hook → T10; form+card+state → T11. e2e+docs → T12.
- Append-only ledger / no-debit-before-approved (T5/T8), 404-not-403 (T3/T7), integer minutes, envelope — all honored. Deferred (half-day compute, leave-with-pay export, accrual/carryover/cash-out, N-step chains) have no task, as intended.
- **Dependency note for execution:** T5's two-hop *effect-deferral* assertions need T6+T8's `leave` type/effect to exist; T5 delivers the transition logic (provable single-hop), and T8's step 5 completes the two-hop effect assertions. T9's `leave_with_pay` persistence tweak (persist a leave line even with no pay-rule) is flagged for reviewer confirmation.
