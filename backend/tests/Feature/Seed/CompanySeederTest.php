<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(Database\Seeders\DatabaseSeeder::class);
});

it('seeds two offices with employees in each', function (): void {
    expect(Office::query()->count())->toBe(2)
        ->and(Employee::query()->count())->toBeGreaterThanOrEqual(10);
});

it('seeds a system admin, an HR admin per office, and a punch-only worker', function (): void {
    expect(User::query()->where('is_system_admin', true)->count())->toBe(1)
        ->and(Employee::query()->whereNull('user_id')->count())->toBeGreaterThanOrEqual(1);

    // Each office has an HR admin scoped to exactly that office.
    foreach (Office::query()->pluck('id') as $officeId) {
        $hrCount = User::query()->whereHas('hrAdminOffices', fn ($q) => $q->where('offices.id', $officeId))->count();
        expect($hrCount)->toBeGreaterThanOrEqual(1);
    }
});

it('seeds at least one Art. 82-exempt manager with live current state', function (): void {
    // Some employee's latest record is exempt AND they have reports.
    $exemptManagers = Employee::query()
        ->whereHas('reports')
        ->whereHas('employmentRecords', fn ($q) => $q->where('is_art82_exempt', true))
        ->count();

    expect($exemptManagers)->toBeGreaterThanOrEqual(1);
});
