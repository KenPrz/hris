<?php

declare(strict_types=1);

use App\Models\LeaveType;
use App\Models\Office;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(Database\Seeders\DatabaseSeeder::class);
});

it('grants leave.manage to the HR Admin role', function (): void {
    $role = Role::findByName('HR Admin');

    expect($role->hasPermissionTo('leave.manage'))->toBeTrue();
});

it('seeds the statutory leave-type set for every office', function (): void {
    expect(Office::query()->count())->toBe(2);

    foreach (Office::query()->get() as $office) {
        $sil = LeaveType::query()->where('office_id', $office->id)->where('code', 'sil')->first();
        expect($sil)->not->toBeNull()
            ->and($sil->deducts_balance)->toBeTrue()
            ->and($sil->is_cash_convertible)->toBeTrue()
            ->and($sil->is_paid)->toBeTrue();

        $eventCodes = ['maternity', 'paternity', 'solo_parent', 'vawc', 'magna_carta'];
        foreach ($eventCodes as $code) {
            $type = LeaveType::query()->where('office_id', $office->id)->where('code', $code)->first();
            expect($type)->not->toBeNull("expected leave type '{$code}' for office {$office->name}")
                ->and($type->deducts_balance)->toBeFalse()
                ->and($type->is_paid)->toBeTrue()
                ->and($type->is_cash_convertible)->toBeFalse();
        }

        foreach (['vl', 'sl'] as $code) {
            $type = LeaveType::query()->where('office_id', $office->id)->where('code', $code)->first();
            expect($type)->not->toBeNull("expected leave type '{$code}' for office {$office->name}")
                ->and($type->deducts_balance)->toBeTrue()
                ->and($type->is_paid)->toBeTrue();
        }
    }
});

it('defaults every office to 480 minutes per leave day', function (): void {
    foreach (Office::query()->pluck('minutes_per_leave_day') as $minutes) {
        expect($minutes)->toBe(480);
    }
});
