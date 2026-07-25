<?php

declare(strict_types=1);

use App\Domain\Pay\StatutoryFloor;

// tests/Unit is deliberately unbooted (see tests/Pest.php) so the pure value objects it
// covers are provable without a container. This file is the one exception: it needs
// config('hris.pay_floors') as its floor fixture, so it opts this file back into the
// booted TestCase rather than duplicating the floor matrix as a second, driftable literal.
uses(Tests\TestCase::class);

function floors(): array
{
    return config('hris.pay_floors'); // the real floor matrix (Task 1)
}

/** A proposed matrix exactly at floor. */
function atFloor(): array
{
    $f = floors();

    return ['worked' => $f['worked'], 'unworked' => $f['unworked'],
        'overtime_ordinary' => $f['overtime_ordinary'], 'overtime_premium' => $f['overtime_premium'],
        'night_diff' => $f['night_diff']];
}

it('reports no violations when every cell is at or above floor', function (): void {
    expect(StatutoryFloor::violations(atFloor(), floors()))->toBe([]);
    $above = atFloor();
    $above['worked']['regular_holiday'] = [25000, 30000];
    expect(StatutoryFloor::violations($above, floors()))->toBe([]);
});

it('reports a worked cell below floor, naming it', function (): void {
    $p = atFloor();
    $p['worked']['regular_holiday'] = [15000, 26000]; // 150% < 200% floor
    $v = StatutoryFloor::violations($p, floors());
    expect($v)->toHaveCount(1)
        ->and($v[0]->multiplier)->toBe('worked.regular_holiday.not_rest')
        ->and($v[0]->proposedBp)->toBe(15000)->and($v[0]->floorBp)->toBe(20000);
});

it('reports an unworked cell and a scalar below floor', function (): void {
    $p = atFloor();
    $p['unworked']['regular_holiday'] = 5000;   // < 10000 floor
    $p['night_diff'] = 10000;                    // < 11000 floor
    $keys = array_map(fn ($x) => $x->multiplier, StatutoryFloor::violations($p, floors()));
    expect($keys)->toContain('unworked.regular_holiday')->toContain('night_diff');
});
