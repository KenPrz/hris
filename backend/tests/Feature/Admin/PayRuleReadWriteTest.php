<?php

declare(strict_types=1);

use App\Models\PayRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/*
| M4c Task 4: sysadmin-gated list + create pay-rule endpoints. Establishes the
| floor-validation flow (proposed matrix -> StatutoryFloor -> 422
| pay_rate_below_floor), the duplicate-effective_from 409, and the completeness
| check that guarantees StatutoryFloor always sees a full five-cell matrix.
*/

/** @return array<string, mixed> The full valid body: all five DayTypes at their floor values. */
function validPayRulePayload(string $effectiveFrom): array
{
    return [
        'effective_from' => $effectiveFrom,
        'overtime_ordinary_bp' => 12500,
        'overtime_premium_bp' => 13000,
        'night_diff_bp' => 11000,
        'day_rates' => [
            ['day_type' => 'ordinary', 'worked_bp' => 10000, 'worked_rest_bp' => 13000, 'unworked_bp' => 0],
            ['day_type' => 'special_working', 'worked_bp' => 10000, 'worked_rest_bp' => 13000, 'unworked_bp' => 0],
            ['day_type' => 'special_non_working', 'worked_bp' => 13000, 'worked_rest_bp' => 15000, 'unworked_bp' => 0],
            ['day_type' => 'regular_holiday', 'worked_bp' => 20000, 'worked_rest_bp' => 26000, 'unworked_bp' => 10000],
            ['day_type' => 'double_regular_holiday', 'worked_bp' => 30000, 'worked_rest_bp' => 39000, 'unworked_bp' => 20000],
        ],
    ];
}

function sysAdmin(): User
{
    return User::factory()->create(['is_system_admin' => true]);
}

// --- Create -----------------------------------------------------------------

it('lets a system admin create a floor-valid version, and logs it', function (): void {
    $admin = sysAdmin();
    Sanctum::actingAs($admin);

    $response = $this->postJson('/api/v1/admin/pay-rules', validPayRulePayload('2026-01-01'))
        ->assertCreated();

    $payRuleId = $response->json('data.id');

    expect($response->json('data.day_rates'))->toHaveCount(5)
        ->and($response->json('data.effective_from'))->toBe('2026-01-01')
        ->and($response->json('data.overtime_ordinary_bp'))->toBe(12500)
        ->and($response->json('data.overtime_premium_bp'))->toBe(13000)
        ->and($response->json('data.night_diff_bp'))->toBe(11000);

    $this->assertDatabaseHas('pay_rules', [
        'id' => $payRuleId,
        'effective_from' => '2026-01-01',
        'created_by' => $admin->id,
    ]);

    $this->assertDatabaseCount('pay_rule_day_rates', 5);

    $activity = Activity::query()->where('subject_id', $payRuleId)->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($admin->id)
        ->and($activity->subject_id)->toBe($payRuleId)
        ->and($activity->subject_type)->toBe(PayRule::class);
});

it('forbids a non-system-admin (403, not 404 — nothing to enumerate)', function (): void {
    Sanctum::actingAs(User::factory()->create(['is_system_admin' => false]));

    $this->getJson('/api/v1/admin/pay-rules')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');

    $this->postJson('/api/v1/admin/pay-rules', validPayRulePayload('2026-01-01'))
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');

    $this->assertDatabaseCount('pay_rules', 0);
});

it('refuses a below-floor cell with 422 pay_rate_below_floor naming it', function (): void {
    Sanctum::actingAs(sysAdmin());

    $payload = validPayRulePayload('2026-01-01');
    $payload['day_rates'] = collect($payload['day_rates'])->map(function (array $rate): array {
        if ($rate['day_type'] === 'regular_holiday') {
            $rate['worked_bp'] = 15000; // below the 20000 floor
        }

        return $rate;
    })->all();

    $this->postJson('/api/v1/admin/pay-rules', $payload)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'pay_rate_below_floor')
        ->assertJsonPath('error.details.violations.0.multiplier', 'worked.regular_holiday.not_rest')
        ->assertJsonPath('error.details.violations.0.proposed_bp', 15000)
        ->assertJsonPath('error.details.violations.0.floor_bp', 20000);

    $this->assertDatabaseCount('pay_rules', 0);
});

it('409s a duplicate effective_from', function (): void {
    Sanctum::actingAs(sysAdmin());

    $this->postJson('/api/v1/admin/pay-rules', validPayRulePayload('2026-01-01'))->assertCreated();

    $this->postJson('/api/v1/admin/pay-rules', validPayRulePayload('2026-01-01'))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'pay_rule_exists');

    $this->assertDatabaseCount('pay_rules', 1);
});

it('400s day_rates missing a DayType', function (): void {
    Sanctum::actingAs(sysAdmin());

    $payload = validPayRulePayload('2026-01-01');
    array_pop($payload['day_rates']); // now only 4 entries — size:5 fails first

    $this->postJson('/api/v1/admin/pay-rules', $payload)
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('400s day_rates with a duplicated DayType (still size 5, so the completeness after-hook catches it)', function (): void {
    Sanctum::actingAs(sysAdmin());

    $payload = validPayRulePayload('2026-01-01');
    // Replace the last entry with a duplicate of the first, keeping size 5 so the
    // shape rule passes and only the withValidator completeness check can catch it.
    $payload['day_rates'][4] = $payload['day_rates'][0];

    $this->postJson('/api/v1/admin/pay-rules', $payload)
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'validation_failed');

    $this->assertDatabaseCount('pay_rules', 0);
});

it('400s day_rates that is not size 5', function (): void {
    Sanctum::actingAs(sysAdmin());

    $payload = validPayRulePayload('2026-01-01');
    $payload['day_rates'][] = $payload['day_rates'][0]; // now 6 entries

    $this->postJson('/api/v1/admin/pay-rules', $payload)
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'validation_failed');
});

// --- List ---------------------------------------------------------------

it('lists versions ordered effective_from desc', function (): void {
    Sanctum::actingAs(sysAdmin());

    $this->postJson('/api/v1/admin/pay-rules', validPayRulePayload('2026-01-01'))->assertCreated();
    $this->postJson('/api/v1/admin/pay-rules', validPayRulePayload('2026-06-01'))->assertCreated();
    $this->postJson('/api/v1/admin/pay-rules', validPayRulePayload('2026-03-01'))->assertCreated();

    $response = $this->getJson('/api/v1/admin/pay-rules')->assertOk();

    expect($response->json('data.*.effective_from'))->toBe(['2026-06-01', '2026-03-01', '2026-01-01']);
    expect($response->json('data.0.day_rates'))->toHaveCount(5);
});
