<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HRIS configuration
|--------------------------------------------------------------------------
|
| Config is what engineers change and deploy; the database is what admins
| change at runtime. Nothing lives in both. See docs/04-backend-conventions.md.
|
| One HRIS-specific addition to that rule: some database-owned values still
| have a code-owned floor, because the Labor Code sets one. Pay multipliers
| are rows (DOLE reissues advisories); the statutory minimum each row is
| validated against is a constant here. See docs/06-roadmap.md.
|
| Money is integer centavos, worked time is integer minutes, and pay
| multipliers are integer basis points. See docs/01-architecture.md.
|
*/

return [

    'version' => env('HRIS_VERSION', 'dev'),

    // ISO-4217. Fixed at setup — changing it is a data migration, not a setting.
    'currency' => env('HRIS_CURRENCY'),

    // The operating company. Per-office identity lives on `offices` (M2).
    'organization_name' => env('HRIS_ORGANIZATION_NAME'),

    // The DOLE statutory TIME constants (Arts. 83, 87), in integer minutes. Same rule as
    // `pay_floors` below: the Labor Code sets these, not an admin, so they are config
    // rather than columns. Both were previously derived from the resolved shift template,
    // which is a per-office admin setting — so an office could move a statutory boundary by
    // editing a shift.
    'meal_break' => [
        // Art. 83 / IRR Book III Rule I s.7: a meal period is owed after five consecutive
        // hours. The assumed-break policy deducts only above this span — NOT above the
        // scheduled day, which made worked minutes non-monotonic in the out-punch: with a
        // 540-minute schedule, punching out at 540 gross kept all 540 while punching out at
        // 541 kept 481. Leaving earlier paid more.
        'applies_over_minutes' => 300,
    ],

    'overtime' => [
        // Art. 83: eight hours is the normal working day, and work beyond it is overtime. A
        // shift template scheduled LONGER than this does not move the boundary — a
        // 540-minute template was pricing the ninth hour at 100% instead of 125%. A
        // legally compressed workweek (D.O. 02-04, e.g. 4x10) genuinely does begin overtime
        // later, but that needs an offices.is_compressed_workweek flag, not this value.
        'statutory_threshold_minutes' => 480,
    ],

    // The DOLE statutory pay-rate FLOORS (Arts. 86-94), in integer basis points. The Labor
    // Code sets these, not an admin; a pay_rules write is refused below any of them. These
    // are the same minimums PayMultiplier encodes today — M5 reconciles PayMultiplier to
    // read these.
    'pay_floors' => [
        'worked' => [
            'ordinary' => [10000, 13000],
            'special_working' => [10000, 13000],
            'special_non_working' => [13000, 15000],
            'regular_holiday' => [20000, 26000],
            'double_regular_holiday' => [30000, 39000],
        ],
        'unworked' => [
            'ordinary' => 0,
            'special_working' => 0,
            'special_non_working' => 0,
            'regular_holiday' => 10000,
            'double_regular_holiday' => 20000,
        ],
        'overtime_ordinary' => 12500,
        'overtime_premium' => 13000,
        'night_diff' => 11000,
    ],

];
