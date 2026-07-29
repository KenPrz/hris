<?php

declare(strict_types=1);

use App\Models\Department;
use App\Models\Office;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

it('has a nullable archived_at on offices and departments', function (): void {
    expect(Schema::hasColumn('offices', 'archived_at'))->toBeTrue();
    expect(Schema::hasColumn('departments', 'archived_at'))->toBeTrue();
});

it('writes an activity log entry when an office is created and changed', function (): void {
    $office = Office::factory()->create();
    $office->update(['name' => 'Renamed HQ']);

    expect(Activity::query()->where('log_name', 'office')->where('subject_id', $office->id)->exists())->toBeTrue();
});

it('writes an activity log entry for organization and department changes', function (): void {
    $org = Organization::factory()->create();
    $org->update(['name' => 'Renamed Co']);
    $dept = Department::factory()->create();
    $dept->update(['name' => 'Renamed Dept']);

    expect(Activity::query()->where('log_name', 'organization')->where('subject_id', $org->id)->exists())->toBeTrue()
        ->and(Activity::query()->where('log_name', 'department')->where('subject_id', $dept->id)->exists())->toBeTrue();
});
