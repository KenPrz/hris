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
