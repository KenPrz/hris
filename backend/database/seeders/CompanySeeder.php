<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Attendance\RecordPunch;
use App\Actions\Attendance\RecordPunchInput;
use App\Actions\Employees\CreateEmployee;
use App\Actions\Employees\CreateEmployeeInput;
use App\Actions\Employees\ProvisionUser;
use App\Actions\Employees\ProvisionUserInput;
use App\Actions\Employees\RecordEmploymentChangeInput;
use App\Domain\Attendance\PunchDirection;
use App\Domain\Attendance\PunchSource;
use App\Models\AttendanceLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Office;
use App\Models\Organization;
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
            'is_system_admin' => true,
        ]);
        $actor = $sysAdmin->id;

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
        );

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
     * given — a null $login leaves user_id null (the punch-only worker).
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
    ): Employee {
        $employee = app(CreateEmployee::class)->execute(new CreateEmployeeInput(
            employeeNo: $employeeNo,
            organizationId: $organization->id,
            hiredAt: $hiredAt,
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
    }
}
