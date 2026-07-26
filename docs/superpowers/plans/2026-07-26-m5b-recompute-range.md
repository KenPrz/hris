# M5b — RecomputeRange Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a holiday / pay-rule / schedule config edit lands, enqueue an **audited** batch of recomputes over exactly the affected **existing** `daily_attendance_summaries` — refreshing derived history without ever mutating the append-only punch ledger. Completes M5.

**Architecture:** A `RecomputeDay` queued job (`(employee, date)` → `ComputeDailySummary::execute`, skipping `locked` summaries) dispatched by a `RecomputeRange` service as a `Bus::batch`, with a `recompute_runs` audit row per run. Per-config-type resolvers turn a config change into the set of existing-summary pairs; each config-change action fires its resolver after commit. Bounded to existing rows; idempotency (M5a's action deletes-and-reinserts under an employee-row lock) makes over-inclusion safe.

**Tech Stack:** Laravel 13 / PHP 8.5 / PostgreSQL 18 · database queue · Pest (real Postgres, `Bus::fake`/queued-job assertions).

**Mirror the merged patterns**, changing only the stated delta:
- Schema + model (HasUuids, LogsActivity, live-constraint schema test): `backend/app/Models/DailyAttendanceSummary.php`, `PayRule.php`; `backend/tests/Feature/Schema/DailyAttendanceSummarySchemaTest.php`
- The idempotent compute action (persists context, locks the employee row): `backend/app/Actions/Compute/ComputeDailySummary.php`
- The after-commit trigger idiom: `backend/app/Actions/Attendance/RecordPunch.php` (`DB::afterCommit(...)` inside the transaction)
- The config-change actions to wire (open each): `backend/app/Actions/Holidays/*.php`, `backend/app/Actions/PayRules/CreatePayRule.php`, `backend/app/Actions/Schedules/*.php`
- The night-window read to fix: `backend/app/Domain/Attendance/EffectivePunches.php` (its `windowMinutes` + the class docblock recording the overlap)
- Enum + Arch ignore-list: `backend/app/Domain/Pay/SummaryLineKind.php` + `backend/tests/Arch/ConventionsTest.php`

## Global Constraints

- `declare(strict_types=1);` every PHP file in `app/`+`tests/`. Actions final, own their transaction, no HTTP. Domain framework-agnostic.
- **Integer minutes / basis points, never floats. No pesos.**
- **The append-only ledger is read alongside, never over** — a recompute rewrites `daily_attendance_summaries`, NEVER an `attendance_logs` row. Tests assert the punch rows are byte-identical before/after a recompute.
- uuid v7 PKs (`->default(DB::raw('uuidv7()'))`), uuid FKs; models `HasUuids` + `newUniqueId()`→`Str::uuid7()->toString()` + `uniqueIds()`. String columns + backed enums + CHECK; never native PG enums. activity_log morph uuid.
- The **database queue** is configured (`QUEUE_CONNECTION=database`). Jobs `implements ShouldQueue`, `use Batchable`.
- **Recompute only EXISTING summaries** in scope. **Over-inclusion is safe** (idempotency); missing an affected summary is not.
- `LogsActivity` on the audit row. Tests run against **real PostgreSQL, never SQLite**; two suites: `./vendor/bin/pest` and `./vendor/bin/pest --testsuite=Arch`.
- **Commit messages carry no attribution trailers.**

---

### Task 1: `office_id` on `daily_attendance_summaries` + persist it

**Files:**
- Create: `backend/database/migrations/2026_07_31_000001_add_office_id_to_daily_attendance_summaries.php`
- Modify: `backend/app/Models/DailyAttendanceSummary.php` (fillable/relation), `backend/app/Actions/Compute/ComputeDailySummary.php` (persist the resolved office)
- Test: `backend/tests/Feature/Compute/ComputeDailySummaryTest.php` (assert `office_id` snapshotted)

**Interfaces:**
- Produces: `daily_attendance_summaries.office_id` (uuid FK offices, nullable) — the office the day was resolved against; `DailyAttendanceSummary::office()`.

- [ ] **Step 1: Write the failing test** — add to `ComputeDailySummaryTest`: an ordinary punched day's computed summary has `office_id` equal to the employee's effective office on that date.

```php
it('snapshots the resolved office_id on the summary', function (): void {
    // reuse the file's existing seed helper for an employee with an office + schedule + a pay_rules version
    [$employee, $office] = seedComputableEmployee(); // or the file's equivalent
    recordPunchPair($employee, '2026-08-03', 480, 1020);
    $summary = app(\App\Actions\Compute\ComputeDailySummary::class)->execute($employee, '2026-08-03');
    expect($summary->office_id)->toBe($office->id);
});
```

- [ ] **Step 2: Run, expect failure** (`office_id` column/attribute missing).
- [ ] **Step 3: Migration** — `$table->foreignUuid('office_id')->nullable()->after('date')->constrained('offices')->nullOnDelete(); $table->index(['office_id', 'date']);` (the index serves the holiday resolver's `office_id = O AND date = D`).
- [ ] **Step 4: Model** — add `office_id` to `$fillable` (or the guarded scheme it uses) and `public function office(): BelongsTo { return $this->belongsTo(Office::class); }`.
- [ ] **Step 5: Persist it** — in `ComputeDailySummary::execute`, the `$officeId` is already resolved (`$employmentRecord?->office_id ?? $employee->current_office_id`); add `'office_id' => $officeId,` to the `DailyAttendanceSummary::create([...])` payload (capture `$officeId` in the transaction closure's `use`).
- [ ] **Step 6: Run the compute tests → PASS.** Step 7: Arch. Step 8: Commit `git commit -m "Compute: snapshot office_id on the daily summary (for scoped recompute)"`.

---

### Task 2: `recompute_runs` audit table + model

**Files:**
- Create: `backend/database/migrations/2026_07_31_000002_create_recompute_runs_table.php`, `backend/app/Models/RecomputeRun.php`, `backend/app/Domain/Compute/RecomputeTrigger.php` (string enum)
- Test: `backend/tests/Feature/Schema/RecomputeRunSchemaTest.php`

**Interfaces:**
- Produces: `RecomputeRun` (HasUuids + LogsActivity `recompute_run`; casts `trigger_type`→`RecomputeTrigger`, `pair_count`→int); `RecomputeTrigger` enum (`Holiday`,`PayRule`,`ShiftTemplate`,`ScheduleAssignment`,`ScheduleOverride`,`OfficeDefault` → `holiday`/`pay_rule`/`shift_template`/`schedule_assignment`/`schedule_override`/`office_default`).

- [ ] **Step 1: Failing schema test** (mirror `DailyAttendanceSummarySchemaTest`): a run persists; `trigger_type` outside the 6 values rejected (throwing raw insert); `status` outside `queued|completed|failed` rejected; `pair_count >= 0` CHECK.
- [ ] **Step 2: Run, expect failure. Step 3: Migration:**

```php
Schema::create('recompute_runs', function (Blueprint $table): void {
    $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
    $table->text('trigger_type');
    $table->uuid('trigger_id')->nullable();
    $table->text('reason');
    $table->integer('pair_count');
    $table->text('batch_id')->nullable();
    $table->text('status')->default('queued');
    $table->foreignUuid('caused_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestampsTz();
});
DB::statement("ALTER TABLE recompute_runs ADD CONSTRAINT recompute_runs_trigger_type_check CHECK (trigger_type IN ('holiday','pay_rule','shift_template','schedule_assignment','schedule_override','office_default'))");
DB::statement("ALTER TABLE recompute_runs ADD CONSTRAINT recompute_runs_status_check CHECK (status IN ('queued','completed','failed'))");
DB::statement('ALTER TABLE recompute_runs ADD CONSTRAINT recompute_runs_pair_count_check CHECK (pair_count >= 0)');
```

- [ ] **Step 4: `RecomputeTrigger` enum** (6 string cases) + add it to the Arch enum ignore-list (mirror `SummaryLineKind`). **Step 5: `RecomputeRun` model** — HasUuids + LogsActivity (`recompute_run`, logOnly trigger_type/trigger_id/reason/pair_count/status), cast `trigger_type`→`RecomputeTrigger`, `pair_count`→int, newUniqueId/uniqueIds.
- [ ] **Step 6: Schema test → PASS. Step 7: Arch. Step 8: Commit** `git commit -m "Recompute: recompute_runs audit table + trigger enum"`.

---

### Task 3: `RecomputeDay` queued job

**Files:**
- Create: `backend/app/Jobs/RecomputeDay.php`
- Test: `backend/tests/Feature/Compute/RecomputeDayTest.php`

**Interfaces:**
- Consumes: `ComputeDailySummary`, `DailyAttendanceSummary`, `Employee`.
- Produces: `RecomputeDay($employeeId, $date)` — a `ShouldQueue`+`Batchable` job whose `handle(ComputeDailySummary $action)` recomputes that day, **skipping a `locked` summary**.

- [ ] **Step 1: Failing tests (real Postgres):**
  - running `RecomputeDay` for an `(employee, date)` with an existing summary re-runs the compute (e.g. after a holiday is added out-of-band, the recomputed summary reflects it) — assert the summary changed.
  - a summary with `status = 'locked'` is NOT recomputed: seed a locked summary with known values, run the job, assert the row is unchanged (still `locked`, same lines).
  - the job is idempotent (running twice → one summary, identical).

```php
it('skips a locked summary', function (): void {
    [$employee] = seedComputableEmployee();
    // create a locked summary directly with sentinel values
    $locked = \App\Models\DailyAttendanceSummary::create([/* ...employee, date, day_type ordinary, ... */ 'status' => 'locked', 'worked_minutes' => 999, 'is_incomplete' => false]);
    (new \App\Jobs\RecomputeDay($employee->id, $locked->date->toDateString()))->handle(app(\App\Actions\Compute\ComputeDailySummary::class));
    expect($locked->fresh()->status)->toBe('locked')->and($locked->fresh()->worked_minutes)->toBe(999);
});
```

- [ ] **Step 2: Run, expect failure. Step 3: Implement:**

```php
final class RecomputeDay implements ShouldQueue
{
    use Batchable, Queueable;

    public function __construct(public readonly string $employeeId, public readonly string $date) {}

    public function handle(ComputeDailySummary $action): void
    {
        if ($this->batch()?->cancelled()) { return; }

        $existing = DailyAttendanceSummary::query()
            ->where('employee_id', $this->employeeId)->whereDate('date', $this->date)->first();

        // A locked period's numbers are frozen (M7 cutoffs). Never recompute over a lock.
        if ($existing?->status === 'locked') { return; }

        $employee = Employee::query()->find($this->employeeId);
        if ($employee === null) { return; }

        $action->execute($employee, $this->date);
    }
}
```

- [ ] **Step 4: Tests → PASS. Step 5: Arch. Step 6: Commit** `git commit -m "Recompute: RecomputeDay job — recompute one day, never over a lock"`.

---

### Task 4: `RecomputeRange` — dispatch mechanism (dedup + Bus::batch + audit lifecycle)

**Files:**
- Create: `backend/app/Domain/Compute/RecomputeRange.php`
- Test: `backend/tests/Feature/Compute/RecomputeRangeTest.php`

**Interfaces:**
- Consumes: `RecomputeDay`, `RecomputeRun`, `RecomputeTrigger`.
- Produces: `RecomputeRange::dispatch(iterable $pairs, RecomputeTrigger $trigger, ?string $triggerId, string $reason, ?string $causedBy = null): ?RecomputeRun` — dedups the pairs, creates a `recompute_runs` row, dispatches a `Bus::batch` of `RecomputeDay`, links the `batch_id`, and marks the run `completed` on batch finish. Returns `null` (a clean no-op) when there are zero pairs. `$pairs` is an iterable of `['employee_id' => string, 'date' => 'Y-m-d']`.

- [ ] **Step 1: Failing tests (`Bus::fake`):**
  - given 3 pairs, one duplicated → a `Bus::batch` of exactly 2 `RecomputeDay` jobs is dispatched (dedup), and a `recompute_runs` row exists with `pair_count = 2`, the batch id set, `status` `queued` (or `completed` after `Bus::fake`'s batch runs — assert what's deterministic).
  - **empty pairs → returns null, dispatches NOTHING, creates NO run** (clean no-op).
  - the run records the `trigger_type`/`trigger_id`/`reason`.

```php
it('dispatches a deduped batch of RecomputeDay and audits it', function (): void {
    Bus::fake();
    $run = RecomputeRange::dispatch(
        [['employee_id' => 'e1', 'date' => '2026-08-21'], ['employee_id' => 'e1', 'date' => '2026-08-21'], ['employee_id' => 'e2', 'date' => '2026-08-21']],
        RecomputeTrigger::Holiday, 'holiday-uuid', 'Holiday created for Manila on 2026-08-21',
    );
    Bus::assertBatched(fn (\Illuminate\Bus\PendingBatch $b) => $b->jobs->count() === 2);
    expect($run)->not->toBeNull()->and($run->pair_count)->toBe(2)->and($run->trigger_type)->toBe(RecomputeTrigger::Holiday);
});

it('is a clean no-op for zero pairs', function (): void {
    Bus::fake();
    $run = RecomputeRange::dispatch([], RecomputeTrigger::Holiday, 'h', 'r');
    Bus::assertNothingBatched();
    expect($run)->toBeNull()->and(RecomputeRun::count())->toBe(0);
});
```

- [ ] **Step 2: Run, expect failure. Step 3: Implement** — normalize + dedup the pairs (`collect($pairs)->unique(fn ($p) => $p['employee_id'].'|'.$p['date'])`); if empty return null; create the `RecomputeRun` (status `queued`, `pair_count`); build `RecomputeDay` jobs; `Bus::batch($jobs)->name("recompute:{$trigger->value}")->then(fn () => $run->update(['status' => 'completed']))->catch(fn () => $run->update(['status' => 'failed']))->dispatch()`; set `$run->batch_id = $batch->id; $run->save();`. Return `$run`. (final class, no HTTP.)
- [ ] **Step 4: Tests → PASS. Step 5: Arch. Step 6: Commit** `git commit -m "Recompute: RecomputeRange — deduped audited Bus::batch dispatch"`.

---

### Task 5: The affected-set resolvers

**Files:**
- Create: `backend/app/Domain/Compute/AffectedSummaries.php` (the per-config-type resolvers)
- Test: `backend/tests/Feature/Compute/AffectedSummariesTest.php`

**Interfaces:**
- Consumes: `DailyAttendanceSummary` (with `office_id`, Task 1), `ShiftTemplate`/`ScheduleAssignment`/`Employee` (to map a template → its employees).
- Produces: static methods each returning `list<array{employee_id: string, date: string}>` from EXISTING summaries: `forHoliday(string $officeId, array $dates)`, `forPayRule(string $effectiveFrom)`, `forShiftTemplate(string $templateId)`, `forEmployee(string $employeeId)` (assignment/override), `forOffice(string $officeId)`.

- [ ] **Step 1: Failing tests** — seed summaries across two offices/dates/employees, then:
  - `forHoliday($manilaId, ['2026-08-21'])` → only Manila summaries on 2026-08-21 (a Cebu summary same date, and a Manila summary on another date, both excluded).
  - `forPayRule('2026-06-01')` → only summaries with `date >= 2026-06-01` (an earlier one excluded).
  - `forShiftTemplate($tid)` → summaries of employees assigned that template (employee assignment, department assignment, and an office whose default is it — cover at least the employee-assignment path; note the union), excluding an unrelated employee's summaries.
  - `forEmployee($eid)` → that employee's existing summaries only.
  - `forOffice($oid)` → that office's summaries only.
  - each returns pairs from existing rows only (a config change with no existing summaries → `[]`).

- [ ] **Step 2: Run, expect failure. Step 3: Implement each** as a scoped `DailyAttendanceSummary` query returning `['employee_id','date' (toDateString)]` pairs:
  - `forHoliday`: `where('office_id',$officeId)->whereIn('date',$dates)`.
  - `forPayRule`: `whereDate('date','>=',$effectiveFrom)`.
  - `forShiftTemplate`: resolve the employee ids on the template — employees with a `ScheduleAssignment` to `$templateId` (employee-target), employees in departments with a `ScheduleAssignment` to it (department-target), employees in offices whose `default_shift_template_id = $templateId` — union the ids, then `whereIn('employee_id', $ids)`. (Over-inclusion is safe, so "all of those employees' existing summaries" is correct; you need not bound the dates.)
  - `forEmployee`: `where('employee_id',$employeeId)`.
  - `forOffice`: `where('office_id',$officeId)`.
  Each: `->get(['employee_id','date'])->map(fn ($s) => ['employee_id' => $s->employee_id, 'date' => $s->date->toDateString()])->all()`.
- [ ] **Step 4: Tests → PASS. Step 5: Arch. Step 6: Commit** `git commit -m "Recompute: affected-summary resolvers per config type"`.

---

### Task 6: Wire RecomputeRange into the config-change actions

**Files:**
- Modify: `backend/app/Actions/Holidays/{CreateHoliday,UpdateHoliday,DeleteHoliday,CloneHolidays}.php`; `backend/app/Actions/PayRules/CreatePayRule.php`; `backend/app/Actions/Schedules/{CreateShiftTemplate,UpdateShiftTemplate,DeleteShiftTemplate,CreateScheduleAssignment,DeleteScheduleAssignment,CreateScheduleOverride,UpdateScheduleOverride,DeleteScheduleOverride,SetOfficeDefaultTemplate}.php`
- Test: `backend/tests/Feature/Compute/RecomputeTriggerTest.php`

**Interfaces:**
- Consumes: `RecomputeRange`, `AffectedSummaries`.

- [ ] **Step 1: Failing tests (a representative subset, `Bus::fake` or run the batch):**
  - creating a holiday for an office+date where a seeded employee HAS a summary → a `RecomputeRun` with `trigger_type holiday` and that pair is dispatched; a holiday for a date with no existing summaries → no run (no-op).
  - creating a `pay_rules` version effective F → a `RecomputeRun` `pay_rule` for the summaries `>= F`.
  - creating a schedule override for an employee with an existing summary that date → a `RecomputeRun` `schedule_override`.
  - **end-to-end (no `Bus::fake` — run it):** seed a Manila employee with a worked Aug-21 summary computed as `ordinary`; create the Aug-21 special-non-working holiday for Manila; run the queued batch; assert the summary's `day_type` is now `special_non_working` and its worked line `applied_bp` is 13000 (flipped 100%→130%); a Cebu employee's summary is untouched; **and the employee's `attendance_logs` rows are byte-identical** (assert the punch rows' ids/punched_at/etc. unchanged — the ledger was never mutated).
- [ ] **Step 2: Run, expect failure. Step 3: Wire each action** — after its own transaction commits, `DB::afterCommit(fn () => RecomputeRange::dispatch(AffectedSummaries::forX(...), RecomputeTrigger::X, $id, $reason, $actorId))`. Pass the identifying context: holidays → `office_id` + the affected date(s); pay_rule → `effective_from`; shift-template → the template id; assignment/override → the target employee id (a department assignment → resolve its employees, or pass via `forEmployee` per employee — keep it simple, over-inclusion safe); office-default → the office id. Thread the actor where the action has it (`created_by`/request user), else null. Register the `DB::afterCommit` INSIDE each action's existing `DB::transaction` so it fires post-commit (mirror RecordPunch).
- [ ] **Step 4: The end-to-end + subset tests → PASS. Step 5: existing Holiday/PayRule/Schedule suites STILL green (you touched their actions). Step 6: Arch. Step 7: Full suite. Step 8: Commit** `git commit -m "Recompute: enqueue an audited recompute after every config-change commit"`.

---

### Task 7: Fix the consecutive-night-shift window overlap in `EffectivePunches`

**Files:**
- Modify: `backend/app/Domain/Attendance/EffectivePunches.php`
- Test: extend `backend/tests/Feature/Attendance/EffectivePunchesTest.php`

**Interfaces:**
- Consumes: `ScheduleResolver` (for the previous day's window end).

- [ ] **Step 1: Failing test** — an employee on a schedule where consecutive days are the SAME cross-midnight night shift (e.g. every day 22:00→06:00, `endMinute` 1800), with a punch pair on each of two consecutive dates. Assert `forDate(dateN)` returns exactly dateN's two punches and `forDate(dateN+1)` returns exactly dateN+1's two — NO punch appears in both (before the fix, dateN's window `[00:00, 30:00)` and dateN+1's `[00:00, 30:00)` overlap, so a punch just after midnight is claimed by both).
- [ ] **Step 2: Run, expect failure. Step 3: Implement** — when computing dateN's business-day window, bound its START at the previous day's resolved window END: resolve `dateN-1`'s schedule; if its `endMinute > 1440` (it ran past midnight into dateN), start dateN's window at `(prevEndMinute - 1440)` minutes past dateN's local midnight instead of 0 — so dateN's window is `[prevEnd − 1440, max(1440, endMinute))` and the two windows tile without overlap. Keep the existing single-day / non-night behavior (prev day not a cross-midnight shift → start at 0). Update the class docblock's M5b note to say it's now handled.
- [ ] **Step 4: Test → PASS** (and the existing EffectivePunches tests, incl. the single cross-midnight case, stay green). **Step 5: Arch. Step 6: Commit** `git commit -m "Recompute: tile consecutive night-shift windows so no punch is double-claimed"`.

---

### Task 8: Docs, e2e, and the full gate

**Files:**
- Modify: `docs/02-data-model.md`, `docs/06-roadmap.md` (M5b + **M5 complete**), `docs/features.md`
- Create: `scripts/e2e-recompute.sh`
- Modify: `backend/database/seeders/CompanySeeder.php` (only if needed to make the e2e's holiday-flip demonstrable)

- [ ] **Step 1: `scripts/e2e-recompute.sh`** — mirror `scripts/e2e-holidays.sh`. Walk (live, needs a queue worker OR run the batch synchronously via `QUEUE_CONNECTION=sync` for the script): as `hr.manila@hris.test`, confirm a seeded Manila employee has a computed Aug-21 summary; if Aug-21 isn't already a holiday in the seed, create it via `POST /office/holidays` (special_non_working); process the queue (`php artisan queue:work --stop-when-empty` in the container, or rely on sync); `GET /me/attendance/summary` (as the employee) and assert the Aug-21 summary flipped to `special_non_working` at 13000bp; assert (via `psql`) a `recompute_runs` row exists for the holiday; assert the `attendance_logs` rows are unchanged. `bash -n` clean; run live only if the stack is up.
- [ ] **Step 2: Docs** — `02-data-model.md`: `recompute_runs` + the `office_id` column + the RecomputeRange mechanism (existing-summaries-only, idempotent over-inclusion, locked-skip, the append-only-ledger-never-mutated guarantee). `06-roadmap.md`: an **M5b Status: complete** block with real counts + **mark M5 (the compute engine) complete**; note M6 (Requests & approvals) is next. `features.md`: a config edit refreshes affected computed days automatically, audited, without touching the punch record. Verify every claim against code.
- [ ] **Step 3: Full gate** — `cd backend && ./vendor/bin/pest && ./vendor/bin/pest --testsuite=Arch`; `cd ../frontend/web && npm run lint && npm test && npm run typecheck && npm run build`; `cd /home/haru/projects/hris && make test`. Report real counts.
- [ ] **Step 4: Commit** `git commit -m "Recompute: docs, e2e, M5b status; M5 the compute engine is complete"`.

## Done When

An HR holiday edit for Manila enqueues an audited recompute (`recompute_runs` row + a `Bus::batch` of `RecomputeDay` jobs) that flips exactly the affected existing Manila summaries 100% → 130% and leaves Cebu's and every raw `attendance_logs` row **byte-identical**; a new `pay_rules` version re-prices every existing summary on/after its effective date; a `locked` summary is skipped; a config change with no existing affected summaries is a clean no-op; two consecutive night shifts count each punch exactly once. **No `attendance_logs` row is ever mutated.** Full suite green, `scripts/e2e-recompute.sh` passes live. **M5 — the compute engine — is complete.**
