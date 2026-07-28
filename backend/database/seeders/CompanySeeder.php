<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Attendance\RecordPunch;
use App\Actions\Attendance\RecordPunchInput;
use App\Actions\Compute\ComputeDailySummary;
use App\Actions\Employees\CreateEmployee;
use App\Actions\Employees\CreateEmployeeInput;
use App\Actions\Employees\ProvisionUser;
use App\Actions\Employees\ProvisionUserInput;
use App\Actions\Employees\RecordEmploymentChangeInput;
use App\Domain\Attendance\PunchDirection;
use App\Domain\Attendance\PunchSource;
use App\Domain\Pay\DayType;
use App\Models\AttendanceLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\Organization;
use App\Models\PayRule;
use App\Models\ScheduleAssignment;
use App\Models\ScheduleOverride;
use App\Models\ShiftTemplate;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * A believable two-office Philippine company to develop and demo against: Manila (HQ) and
 * Cebu (branch), deliberately far apart so a scope leak shows up as a test failure rather
 * than a subtle production bug. It seeds one of each of the four scopes so you can log in
 * as any of them — a System Admin who sees everyone, an HR Admin per office who sees only
 * their office, a manager who sees their direct reports, and a rank-and-file employee who
 * sees only themselves — plus the two edge cases M1/M2 care about: an Art. 82-exempt
 * manager (so the exemption has live data) and a punch-only worker with no login (so the
 * nullable-user path is exercised, not assumed). See docs/02-data-model.md, docs/05-rbac.md.
 *
 * Every employee's current_* cache is populated the one legal way — through
 * RecordEmploymentChange, wrapped by CreateEmployee — never by a direct write. An arch
 * test forbids any other writer, and this seeder honours it.
 *
 * Development only. Fixed, obvious passwords; never run this anywhere real.
 */
final class CompanySeeder extends Seeder
{
    /** One dev password for every seeded login. Printed at the end. */
    private const string PASSWORD = 'password';

    /** Near the NCR daily minimum, in centavos — matches EmploymentRecordFactory. */
    private const int RANK_AND_FILE_RATE = 61000;

    private const int SENIOR_RATE = 85000;

    private const int MANAGER_RATE = 150000;

    private const int HR_RATE = 110000;

    public function run(): void
    {
        $org = Organization::create([
            'name' => config('hris.organization_name'),
            'legal_name' => config('hris.organization_name').', Inc.',
            'timezone' => 'Asia/Manila',
        ]);

        $manila = Office::create([
            'organization_id' => $org->id,
            'name' => 'Manila HQ',
            'code' => 'MNL',
            'timezone' => 'Asia/Manila',
            // Manila enforces an office network so M3's flag-not-reject path has live data:
            // a self-service punch from off this /24 lands `flagged` (never refused), while a
            // manual HR backfill — which carries no request IP — stays `verified`. Cebu has
            // no allowlist, so its punches are `verified` unconditionally. A documentation
            // range (RFC 5737 TEST-NET-3), never a real network. See scripts/e2e-timekeeping.sh.
            'ip_allowlist' => ['203.0.113.0/24'],
        ]);
        $cebu = Office::create([
            'organization_id' => $org->id,
            'name' => 'Cebu Branch',
            'code' => 'CEB',
            'timezone' => 'Asia/Manila',
        ]);

        // A couple of real 2026 PH holidays for Manila (M4a), so `/office/holidays` isn't
        // empty on a fresh `make dev` — Cebu deliberately gets none, so the office-scoped
        // list has something real to prove it's scoped, not just empty everywhere. Written
        // directly through the model, the same way `department()` below calls
        // `Department::create` directly — holidays have no cache to keep in sync, so there
        // is no single-writer rule here to honour the way `RecordEmploymentChange` has one.
        Holiday::create([
            'office_id' => $manila->id,
            'date' => '2026-08-21',
            'day_type' => DayType::SpecialNonWorking,
            'name' => 'Ninoy Aquino Day',
        ]);
        Holiday::create([
            'office_id' => $manila->id,
            'date' => '2026-12-30',
            'day_type' => DayType::RegularHoliday,
            'name' => 'Rizal Day',
        ]);

        // M4b: both offices get the same "Standard Mon–Fri" shift template — Mon-Fri
        // 08:00-18:00 with an hour's break, Sat/Sun rest — set as each office's own
        // default so `/office/schedules` and the resolved read are non-empty on a fresh
        // `make dev` even before any assignment exists. Written directly through the
        // model, the same as `Holiday::create` above: templates/days have no cache to
        // keep in sync, so there is no single-writer rule here to honour.
        $manilaShiftTemplate = $this->standardMondayToFridayTemplate($manila);
        $manila->update(['default_shift_template_id' => $manilaShiftTemplate->id]);

        $cebuShiftTemplate = $this->standardMondayToFridayTemplate($cebu);
        $cebu->update(['default_shift_template_id' => $cebuShiftTemplate->id]);

        // M6b-a: each office's leave-type catalog (the PH statutory set + company VL/SL),
        // config only — accrual is deferred, so nothing is granted here. See seedLeaveTypes().
        $this->seedLeaveTypes($manila);
        $this->seedLeaveTypes($cebu);

        $manilaOps = $this->department($manila, 'Operations', 'OPS');
        $manilaPeople = $this->department($manila, 'People & Culture', 'PPL');
        $cebuOps = $this->department($cebu, 'Operations', 'OPS');
        $cebuSupport = $this->department($cebu, 'Customer Support', 'SUP');

        // System Admin — a flag (Gate::before), never a spatie role (docs/05-rbac.md). It is
        // also the actor that onboarded everyone, so it is the created_by on every seeded
        // employment record.
        $sysAdmin = User::create([
            'name' => 'Sofia Reyes',
            'email' => 'sysadmin@hris.test',
            'password' => Hash::make(self::PASSWORD),
        ]);
        // is_system_admin is guarded against mass assignment (see User), so it is set
        // explicitly here — the one deliberate grant of the most powerful flag in the system.
        $sysAdmin->forceFill(['is_system_admin' => true])->save();
        $actor = $sysAdmin->id;

        // M4c: one default pay-rule version, effective from the start of 2026, so M5 has
        // a version to read and `/admin/pay-rules` is non-empty on a fresh `make dev`.
        $this->seedDefaultPayRuleVersion($actor);

        // --- Manila ---

        // The Art. 82-exempt manager, with direct reports below them.
        $manilaManager = $this->onboard(
            employeeNo: 'MNL-0001',
            organization: $org,
            office: $manila,
            department: $manilaOps,
            hiredAt: '2021-01-04',
            manager: null,
            art82Exempt: true,
            baseRateCents: self::MANAGER_RATE,
            actorId: $actor,
            login: ['name' => 'Rosa Bautista', 'email' => 'manager.manila@hris.test'],
            name: 'Rosa Bautista',
        );

        // Three rank-and-file reports. The first one is the "plain employee" credential.
        $miguel = $this->onboard(
            employeeNo: 'MNL-0002',
            organization: $org,
            office: $manila,
            department: $manilaOps,
            hiredAt: '2022-03-01',
            manager: $manilaManager,
            art82Exempt: false,
            baseRateCents: self::SENIOR_RATE,
            actorId: $actor,
            login: ['name' => 'Miguel Santos', 'email' => 'employee.manila@hris.test'],
            name: 'Miguel Santos',
        );

        // M4b: an employee-level assignment for Miguel, effective from the start of 2026 —
        // so ScheduleResolver has an `employee` source to fall back to before the office
        // default, and the resolved read on `/office/schedules` is non-empty for him from
        // the first month a fresh `make dev` looks at. Same office/template, so nothing
        // about this changes what he's scheduled to work — it demonstrates the assignment
        // layer sitting in front of the office default that's already covering him.
        ScheduleAssignment::create([
            'shift_template_id' => $manilaShiftTemplate->id,
            'employee_id' => $miguel->id,
            'effective_from' => '2026-01-01',
            'created_by' => $actor,
        ]);

        // One rest-day-swap override for demonstration: 2026-08-01 is a Saturday (a rest
        // day under the standard template above); this flips it to a working day the same
        // 08:00-18:00 shape as his usual weekday, so the resolved calendar shows a real
        // `source: "override"` cell without the seed needing to invent a stranger shape.
        ScheduleOverride::create([
            'employee_id' => $miguel->id,
            'date' => '2026-08-01',
            'is_rest' => false,
            'start_minute' => 480,
            'end_minute' => 1080,
            'break_minutes' => 60,
            'note' => 'Rest-day swap: covering a Saturday shift',
            'created_by' => $actor,
        ]);

        // A seeded in/out pair so scripts/e2e-adjustments.sh has a real target_log_id to
        // void without first having to punch and discover one live. Written through
        // RecordPunch (the one arch-guarded writer), source=manual, recorded_by the
        // system admin actor above — never a raw insert, same rule the append-only
        // ledger holds everywhere else. ip_address is null (a manual entry carries no
        // request IP, same as the HR-manual-backfill path), so it lands `verified`
        // regardless of Manila's ip_allowlist. Fixed on 2026-01-15, well clear of
        // whatever "today" the e2e or timekeeping's own script runs against.
        $this->seedPunch($miguel, PunchDirection::In, '2026-01-15T00:00:00Z', $actor);
        $this->seedPunch($miguel, PunchDirection::Out, '2026-01-15T09:00:00Z', $actor);

        // M5a: RecordPunch's after-commit hook (Task 6) computes a daily_attendance_summary
        // synchronously, so every seeded punch above already left one behind — the Jan 15
        // pair above becomes a `regular_day` line at 10000bp, the statutory ordinary-day
        // floor. These two additions give the compute engine a second, more interesting
        // proof point on a fresh `make dev`: Aug 21, 2026 is both a scheduled working Friday
        // (the standard template's Mon-Fri window, no override touches it) AND the
        // special-non-working holiday seeded above, so Miguel working it prices at 13000bp
        // (`App\Domain\Pay\PayMultiplier::forWorkedTime`), not his ordinary 10000bp. Rosa —
        // the Art. 82-exempt manager onboarded above — punching that SAME holiday shows the
        // exemption short-circuiting even a holiday premium: her line still prices at
        // 10000bp. Full 08:00-18:00 Manila (00:00-10:00 UTC), the same shape as the standard
        // template, so neither punch produces overtime.
        $this->seedPunch($miguel, PunchDirection::In, '2026-08-21T00:00:00Z', $actor);
        $this->seedPunch($miguel, PunchDirection::Out, '2026-08-21T10:00:00Z', $actor);

        $this->seedPunch($manilaManager, PunchDirection::In, '2026-08-21T00:00:00Z', $actor);
        $this->seedPunch($manilaManager, PunchDirection::Out, '2026-08-21T10:00:00Z', $actor);

        // A full, deliberately varied CURRENT-month attendance history for Miguel (the
        // `employee.manila` login) — and the exempt manager on the sample holiday — so
        // `/me/attendance` and the M5 compute breakdown are populated the moment you log in,
        // rather than only the fixed Jan/Aug demo dates above. See the method.
        $this->seedCurrentMonthAttendance($manila, $miguel, $manilaManager, $actor);

        $this->onboard(
            employeeNo: 'MNL-0003',
            organization: $org,
            office: $manila,
            department: $manilaOps,
            hiredAt: '2022-09-12',
            manager: $manilaManager,
            art82Exempt: false,
            baseRateCents: self::RANK_AND_FILE_RATE,
            actorId: $actor,
            login: ['name' => 'Andrea Cruz', 'email' => 'andrea.manila@hris.test'],
            name: 'Andrea Cruz',
        );
        $this->onboard(
            employeeNo: 'MNL-0004',
            organization: $org,
            office: $manila,
            department: $manilaOps,
            hiredAt: '2023-02-20',
            manager: $manilaManager,
            art82Exempt: false,
            baseRateCents: self::RANK_AND_FILE_RATE,
            actorId: $actor,
            login: ['name' => 'Paolo Villanueva', 'email' => 'paolo.manila@hris.test'],
            name: 'Paolo Villanueva',
        );

        // The punch-only worker: an employment record (so the cache is populated and they
        // appear in scope queries) but no user_id — the nullable-login path, made real.
        $this->onboard(
            employeeNo: 'MNL-0005',
            organization: $org,
            office: $manila,
            department: $manilaOps,
            hiredAt: '2023-06-05',
            manager: $manilaManager,
            art82Exempt: false,
            baseRateCents: self::RANK_AND_FILE_RATE,
            actorId: $actor,
            login: null,
            name: 'Jerome Salazar',
        );

        // Manila HR Admin: the verb set comes from the spatie 'HR Admin' role; the scope
        // comes from an hr_admin_offices row for Manila only.
        $manilaHr = $this->onboard(
            employeeNo: 'MNL-0006',
            organization: $org,
            office: $manila,
            department: $manilaPeople,
            hiredAt: '2021-05-17',
            manager: null,
            art82Exempt: false,
            baseRateCents: self::HR_RATE,
            actorId: $actor,
            login: ['name' => 'Carmen Lim', 'email' => 'hr.manila@hris.test'],
            name: 'Carmen Lim',
        );
        $manilaHr->user->assignRole('HR Admin');
        $manilaHr->user->hrAdminOffices()->attach($manila->id);

        // --- Cebu ---

        $cebuManager = $this->onboard(
            employeeNo: 'CEB-0001',
            organization: $org,
            office: $cebu,
            department: $cebuOps,
            hiredAt: '2021-08-02',
            manager: null,
            art82Exempt: true,
            baseRateCents: self::MANAGER_RATE,
            actorId: $actor,
            login: ['name' => 'Ramon Delgado', 'email' => 'manager.cebu@hris.test'],
            name: 'Ramon Delgado',
        );
        $this->onboard(
            employeeNo: 'CEB-0002',
            organization: $org,
            office: $cebu,
            department: $cebuOps,
            hiredAt: '2022-04-11',
            manager: $cebuManager,
            art82Exempt: false,
            baseRateCents: self::SENIOR_RATE,
            actorId: $actor,
            login: ['name' => 'Liza Fernandez', 'email' => 'employee.cebu@hris.test'],
            name: 'Liza Fernandez',
        );
        $this->onboard(
            employeeNo: 'CEB-0003',
            organization: $org,
            office: $cebu,
            department: $cebuOps,
            hiredAt: '2023-01-09',
            manager: $cebuManager,
            art82Exempt: false,
            baseRateCents: self::RANK_AND_FILE_RATE,
            actorId: $actor,
            login: ['name' => 'Noel Aquino', 'email' => 'noel.cebu@hris.test'],
            name: 'Noel Aquino',
        );

        $cebuHr = $this->onboard(
            employeeNo: 'CEB-0004',
            organization: $org,
            office: $cebu,
            department: $cebuSupport,
            hiredAt: '2021-11-23',
            manager: null,
            art82Exempt: false,
            baseRateCents: self::HR_RATE,
            actorId: $actor,
            login: ['name' => 'Grace Tan', 'email' => 'hr.cebu@hris.test'],
            name: 'Grace Tan',
        );
        $cebuHr->user->assignRole('HR Admin');
        $cebuHr->user->hrAdminOffices()->attach($cebu->id);

        $this->printCredentials();
    }

    /**
     * A seeded punch, written the one legal way: through `RecordPunch`, never a raw
     * insert. Manual-shaped (`source: manual`, no IP/geo) so it lands `verified`
     * unconditionally. Gives an M3.6 adjustment (a void or amend) a real
     * `target_log_id` to point at without the e2e script having to punch and discover
     * one first.
     */
    private function seedPunch(Employee $employee, PunchDirection $direction, string $punchedAtUtc, string $actorId): AttendanceLog
    {
        return app(RecordPunch::class)->execute(new RecordPunchInput(
            employeeId: $employee->id,
            direction: $direction,
            source: PunchSource::Manual,
            punchedAt: Carbon::parse($punchedAtUtc),
            recordedBy: $actorId,
            ipAddress: null,
            deviceId: null,
            geoLat: null,
            geoLng: null,
        ));
    }

    /**
     * A full, deliberately varied CURRENT-month attendance history for one employee, so
     * `/me/attendance` and the M5 compute breakdown are worth opening on a fresh `make dev`
     * (the design choice: a full, varied month, anchored around today). Anchored to the
     * office's own local "today", never a fixed calendar date, so the data always lands in
     * the month the calendar opens on. Today itself is left unpunched on purpose — so the
     * first thing you can do as this employee is click "Clock in" live.
     *
     * One day of each shape the compute engine can produce:
     *   - ordinary days                          → regular_day @ 10000bp (ordinary floor)
     *   - one overtime day (clock out 21:00)     → regular_day + overtime_day
     *   - one night shift (22:00–06:00 override) → regular_night (the +10% differential)
     *   - one rest day worked (no override)      → the rest-day premium (base 8h + rest OT)
     *   - one special-non-working holiday worked → 13000bp (the exempt manager: flat 10000)
     *   - one incomplete day (clock in, no out)  → zero worked, is_incomplete
     *
     * The night shift's out-punch lands on the NEXT calendar date. RecordPunch only ever
     * recomputes a punch's OWN office-local date (see its docblock), so the out-punch
     * computes the next day while the shift's originating date is left at one unpaired
     * punch — so that date is recomputed explicitly once both punches exist, aimed at the
     * business day the cross-midnight out-punch actually belongs to.
     */
    private function seedCurrentMonthAttendance(Office $office, Employee $employee, Employee $exemptManager, string $actorId): void
    {
        $timezone = $office->timezone;
        $today = Carbon::now($timezone)->startOfDay();
        $monthStart = $today->copy()->startOfMonth();

        // Weekdays and weekend dates from the 1st through YESTERDAY (today stays open for a
        // live clock-in).
        $workdays = [];
        $restDates = [];
        for ($d = $monthStart->copy(); $d->lt($today); $d->addDay()) {
            if ($d->isWeekend()) {
                $restDates[] = $d->toDateString();
            } else {
                $workdays[] = $d->toDateString();
            }
        }

        if ($workdays === []) {
            // The month's first workday hasn't passed yet (run on a 1st/weekend); nothing to
            // populate. The fixed Aug-21 holiday demo above still gives the engine live data.
            return;
        }

        // Assign the special scenarios to the most recent workdays — indexing from the end
        // is robust to whatever date this runs on; every other workday stays ordinary.
        $count = count($workdays);
        $overtimeDate = $workdays[$count - 1];
        $incompleteDate = $count >= 2 ? $workdays[$count - 2] : null;
        $nightDate = $count >= 3 ? $workdays[$count - 3] : null;
        // A worked holiday on an early workday, kept clear of the three special days above.
        $holidayDate = $count >= 6 ? $workdays[2] : null;

        // The holiday must exist before a punch on it is computed. Direct create (like the
        // fixed Manila holidays above), not the CreateHoliday action, so no recompute is
        // dispatched — the punches below price against it synchronously.
        if ($holidayDate !== null) {
            Holiday::create([
                'office_id' => $office->id,
                'date' => $holidayDate,
                'day_type' => DayType::SpecialNonWorking,
                'name' => 'Founding Anniversary (dev sample)',
            ]);
        }

        // The night-shift override must exist before the FOLLOWING day's punches are
        // recorded, so that day's window is bounded and never double-claims the 06:00 out.
        if ($nightDate !== null) {
            ScheduleOverride::create([
                'employee_id' => $employee->id,
                'date' => $nightDate,
                'is_rest' => false,
                'start_minute' => 1320, // 22:00
                'end_minute' => 1800,   // 06:00 the next day
                'break_minutes' => 60,
                'note' => 'Night shift (dev sample): 22:00–06:00',
                'created_by' => $actorId,
            ]);
        }

        // Punch the month in ascending date order.
        foreach ($workdays as $date) {
            if ($date === $incompleteDate) {
                // A missed clock-out: one punch, no pair — the day the engine flags incomplete.
                $this->seedLocalPunch($employee, PunchDirection::In, $timezone, $date, '08:00', $actorId);

                continue;
            }

            if ($date === $nightDate) {
                $this->seedLocalPunch($employee, PunchDirection::In, $timezone, $date, '22:00', $actorId);
                $this->seedLocalPunch($employee, PunchDirection::Out, $timezone, $date, '06:00', $actorId, addDays: 1);

                continue;
            }

            // Ordinary, overtime, and the worked holiday share the plain in/out shape: the
            // later clock-out is what makes the overtime bucket, the holiday's day_type is
            // what reprices its hours.
            $out = $date === $overtimeDate ? '21:00' : '18:00';
            $this->seedLocalPunch($employee, PunchDirection::In, $timezone, $date, '08:00', $actorId);
            $this->seedLocalPunch($employee, PunchDirection::Out, $timezone, $date, $out, $actorId);
        }

        // One rest day actually worked — no override, so it stays a rest day and prices at
        // the rest-day premium — on the most recent weekend date in range.
        if ($restDates !== []) {
            $restWorked = end($restDates);
            $this->seedLocalPunch($employee, PunchDirection::In, $timezone, $restWorked, '08:00', $actorId);
            $this->seedLocalPunch($employee, PunchDirection::Out, $timezone, $restWorked, '18:00', $actorId);
        }

        // Recompute the night shift's originating date now that both its punches exist (see
        // the method docblock) — the same ComputeDailySummary the punch path calls.
        if ($nightDate !== null) {
            app(ComputeDailySummary::class)->execute($employee, $nightDate);
        }

        // The Art. 82-exempt manager works the same sample holiday, so the current month
        // also shows the exemption live: the premium collapses to a flat 100% for them.
        if ($holidayDate !== null) {
            $this->seedLocalPunch($exemptManager, PunchDirection::In, $timezone, $holidayDate, '08:00', $actorId);
            $this->seedLocalPunch($exemptManager, PunchDirection::Out, $timezone, $holidayDate, '18:00', $actorId);
        }
    }

    /**
     * A punch at a wall-clock time in the office's own timezone, written through RecordPunch
     * (the one arch-guarded writer), source=manual so it lands `verified` regardless of any
     * office ip_allowlist — the same shape as seedPunch(), but expressed in local time so
     * the seed reads as "08:00" rather than a hand-converted UTC offset. `addDays` carries
     * the out-punch of a cross-midnight shift onto the next calendar day.
     */
    private function seedLocalPunch(Employee $employee, PunchDirection $direction, string $timezone, string $date, string $time, string $actorId, int $addDays = 0): AttendanceLog
    {
        $instant = Carbon::parse("{$date} {$time}", $timezone)->addDays($addDays);

        return app(RecordPunch::class)->execute(new RecordPunchInput(
            employeeId: $employee->id,
            direction: $direction,
            source: PunchSource::Manual,
            punchedAt: $instant,
            recordedBy: $actorId,
            ipAddress: null,
            deviceId: null,
            geoLat: null,
            geoLng: null,
        ));
    }

    /**
     * "Standard Mon–Fri": Mon-Fri 08:00-18:00 (480-1080 minutes) with an hour's break,
     * Sat/Sun rest — the same shape `ResolvedScheduleTest`/`ShiftTemplateReadWriteTest`
     * build by hand, given a name here so it reads as a real template on the screen
     * rather than the tests' bare "Template"/"Office".
     */
    private function standardMondayToFridayTemplate(Office $office): ShiftTemplate
    {
        $template = ShiftTemplate::create([
            'office_id' => $office->id,
            'name' => 'Standard Mon–Fri',
        ]);

        foreach (range(0, 6) as $weekday) {
            $isWeekend = $weekday >= 5; // Weekday::Saturday = 5, Weekday::Sunday = 6
            $template->days()->create([
                'weekday' => $weekday,
                'is_rest' => $isWeekend,
                'start_minute' => $isWeekend ? null : 480,
                'end_minute' => $isWeekend ? null : 1080,
                'break_minutes' => $isWeekend ? null : 60,
            ]);
        }

        return $template;
    }

    /**
     * The one default pay-rule version: every cell set to exactly the statutory floor
     * (`config('hris.pay_floors')`), effective `2026-01-01`, `created_by` the System
     * Admin. Read from config rather than re-hardcoded, so this can never drift from the
     * same Labor Code minimums `CreatePayRule`/`StatutoryFloor` validate every write
     * against. Written directly through `Model::create`, the same as `Holiday::create`
     * and `standardMondayToFridayTemplate()` above — a pay rule has no cache to keep in
     * sync, so there is no single-writer rule here to honour.
     */
    private function seedDefaultPayRuleVersion(string $actorId): void
    {
        $floors = config('hris.pay_floors');

        $payRule = PayRule::create([
            'effective_from' => '2026-01-01',
            'overtime_ordinary_bp' => $floors['overtime_ordinary'],
            'overtime_premium_bp' => $floors['overtime_premium'],
            'night_diff_bp' => $floors['night_diff'],
            'note' => 'Statutory floor matrix (DOLE minimums) — the initial version.',
            'created_by' => $actorId,
        ]);

        foreach (DayType::cases() as $dayType) {
            [$notRestBp, $restBp] = $floors['worked'][$dayType->value];

            $payRule->dayRates()->create([
                'day_type' => $dayType->value,
                'worked_bp' => $notRestBp,
                'worked_rest_bp' => $restBp,
                'unworked_bp' => $floors['unworked'][$dayType->value],
            ]);
        }
    }

    /**
     * The per-office leave-type catalog: config only, no grants — accrual (and the ledger
     * rows it would produce) is deferred past M6b-a, so every balance starts empty. Written
     * directly through `LeaveType::create`, the same as `Holiday::create` above — a leave
     * type has no cache to keep in sync, so there is no single-writer rule here to honour.
     *
     * SIL is the one statutory type that already banks a balance and can be cashed out
     * (Art. 95). The five event types (Maternity, Paternity, Solo Parent, VAWC, Magna
     * Carta) are entitlements keyed to an event, not a balance — `deducts_balance: false` —
     * so M6b-b's ledger never debits them. VL/SL are company benefits (not statutory), given
     * the same balance shape as SIL so a real company's two most common leave types exist
     * on a fresh `make dev`.
     */
    private function seedLeaveTypes(Office $office): void
    {
        LeaveType::create([
            'office_id' => $office->id,
            'name' => 'Service Incentive Leave',
            'code' => 'sil',
            'is_paid' => true,
            'deducts_balance' => true,
            'is_cash_convertible' => true,
            'max_carryover_minutes' => null,
            'is_active' => true,
        ]);

        $eventTypes = [
            ['name' => 'Maternity Leave', 'code' => 'maternity'],
            ['name' => 'Paternity Leave', 'code' => 'paternity'],
            ['name' => 'Solo Parent Leave', 'code' => 'solo_parent'],
            ['name' => 'VAWC Leave', 'code' => 'vawc'],
            ['name' => 'Magna Carta Special Leave', 'code' => 'magna_carta'],
        ];
        foreach ($eventTypes as $eventType) {
            LeaveType::create([
                'office_id' => $office->id,
                'name' => $eventType['name'],
                'code' => $eventType['code'],
                'is_paid' => true,
                'deducts_balance' => false,
                'is_cash_convertible' => false,
                'max_carryover_minutes' => null,
                'is_active' => true,
            ]);
        }

        $companyBalanceTypes = [
            ['name' => 'Vacation Leave', 'code' => 'vl'],
            ['name' => 'Sick Leave', 'code' => 'sl'],
        ];
        foreach ($companyBalanceTypes as $balanceType) {
            LeaveType::create([
                'office_id' => $office->id,
                'name' => $balanceType['name'],
                'code' => $balanceType['code'],
                'is_paid' => true,
                'deducts_balance' => true,
                'is_cash_convertible' => false,
                'max_carryover_minutes' => null,
                'is_active' => true,
            ]);
        }
    }

    private function department(Office $office, string $name, string $code): Department
    {
        return Department::create([
            'office_id' => $office->id,
            'name' => $name,
            'code' => $code,
        ]);
    }

    /**
     * Onboard one employee the way the API does: CreateEmployee inserts the immutable
     * identity and records the first employment through RecordEmploymentChange, which is
     * the sole writer of the current_* cache. A login is provisioned only when one is
     * given — a null $login leaves user_id null (the punch-only worker). $name is a plain
     * "First Last" and is split on the first space — the seeded roster has no middle
     * names or suffixes to carry.
     *
     * @param  array{name: string, email: string}|null  $login
     */
    private function onboard(
        string $employeeNo,
        Organization $organization,
        Office $office,
        Department $department,
        string $hiredAt,
        ?Employee $manager,
        bool $art82Exempt,
        int $baseRateCents,
        string $actorId,
        ?array $login,
        string $name,
    ): Employee {
        [$firstName, $lastName] = explode(' ', $name, 2);

        $employee = app(CreateEmployee::class)->execute(new CreateEmployeeInput(
            employeeNo: $employeeNo,
            organizationId: $organization->id,
            hiredAt: $hiredAt,
            firstName: $firstName,
            middleName: null,
            lastName: $lastName,
            nameSuffix: null,
            firstEmployment: new RecordEmploymentChangeInput(
                employeeId: '', // overwritten by CreateEmployee once the employee exists
                effectiveFrom: $hiredAt,
                officeId: $office->id,
                departmentId: $department->id,
                reportsToId: $manager?->id,
                employmentType: 'regular',
                isArt82Exempt: $art82Exempt,
                baseRateCents: $baseRateCents,
                actorId: $actorId,
            ),
            actorId: $actorId,
        ));

        if ($login !== null) {
            app(ProvisionUser::class)->execute(new ProvisionUserInput(
                employeeId: $employee->id,
                email: $login['email'],
                password: self::PASSWORD,
                name: $login['name'],
            ));
        }

        return $employee->refresh();
    }

    private function printCredentials(): void
    {
        $this->command?->newLine();
        $this->command?->info('Seeded the Manila/Cebu company. Dev logins (POST /api/v1/login):');
        $this->command?->table(
            ['Email', 'Password', 'Scope'],
            [
                ['sysadmin@hris.test', self::PASSWORD, 'System Admin — sees everyone'],
                ['hr.manila@hris.test', self::PASSWORD, 'HR Admin — Manila office only'],
                ['hr.cebu@hris.test', self::PASSWORD, 'HR Admin — Cebu office only'],
                ['manager.manila@hris.test', self::PASSWORD, 'Manager — sees direct reports (Art. 82-exempt)'],
                ['employee.manila@hris.test', self::PASSWORD, 'Employee — sees only themselves'],
            ],
        );
        $this->command?->comment('MNL-0005 is a punch-only worker: an employment record, no login.');
        $this->command?->comment('employee.manila has a full, varied current-month attendance history (overtime, a night shift, a rest day worked, a worked holiday, and one incomplete day) — open /me/attendance to see the M5 breakdown. Today is left unpunched so you can clock in live.');
    }
}
