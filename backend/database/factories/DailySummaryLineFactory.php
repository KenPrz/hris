<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Pay\SummaryLineKind;
use App\Models\DailyAttendanceSummary;
use App\Models\DailySummaryLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DailySummaryLine> */
final class DailySummaryLineFactory extends Factory
{
    protected $model = DailySummaryLine::class;

    /** One regular daytime line at the statutory ordinary floor (10000bp = 100%). */
    public function definition(): array
    {
        return [
            'summary_id' => DailyAttendanceSummary::factory(),
            'kind' => SummaryLineKind::RegularDay,
            'minutes' => 540,
            'applied_bp' => 10000,
        ];
    }
}
