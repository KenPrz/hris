<?php

declare(strict_types=1);

/*
| Shared seed helpers for the Requests feature tests (ApprovalQueuesTest, ...).
| Deliberately NOT named *Test.php so PHPUnit's directory discovery never picks it up as
| a test file — the same convention already used by tests/Feature/Compute/support.php and
| tests/Feature/Attendance/Support/*.php. Each consuming test file pulls this in with
| `require_once __DIR__.'/support.php';` rather than redeclaring these functions itself:
| PHP fatally errors on a duplicate top-level function definition the moment both files
| are loaded in the same process (e.g. a full `pest tests/Feature` run), so the helpers
| live in exactly one place.
*/

use App\Models\Employee;
use App\Models\Office;
use App\Models\User;

if (! function_exists('makeManagerReportStranger')) {
    /**
     * A manager (employee+user) in an office, a direct report of theirs, and an unrelated
     * employee in the same office who reports to nobody in this fixture.
     *
     * @return array{0: Employee, 1: Employee, 2: Employee}
     */
    function makeManagerReportStranger(): array
    {
        $office = Office::factory()->create();

        $managerUser = User::factory()->create();
        $manager = Employee::factory()->for($managerUser)->create(['current_office_id' => $office->id]);

        $report = Employee::factory()->create([
            'current_office_id' => $office->id,
            'current_reports_to_id' => $manager->id,
        ]);

        $stranger = Employee::factory()->create(['current_office_id' => $office->id]);

        return [$manager, $report, $stranger];
    }
}

if (! function_exists('makeHrAdmin')) {
    /**
     * An HR admin — a user with an employee record inside the office they administer,
     * wired through the real `hr_admin_offices` pivot via hrAdminOffices()->attach().
     *
     * @return array{0: User, 1: Office}
     */
    function makeHrAdmin(): array
    {
        $office = Office::factory()->create();

        $hrUser = User::factory()->create();
        Employee::factory()->for($hrUser)->create(['current_office_id' => $office->id]);
        $hrUser->hrAdminOffices()->attach($office->id);

        return [$hrUser, $office];
    }
}
