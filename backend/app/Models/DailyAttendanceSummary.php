<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Pay\DayType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

final class DailyAttendanceSummary extends Model
{
    use HasUuids, LogsActivity;

    protected $fillable = [
        'employee_id', 'date', 'day_type', 'is_rest_day', 'scheduled_minutes',
        'is_art82_exempt', 'rule_version_id', 'worked_minutes', 'late_minutes',
        'undertime_minutes', 'status', 'is_incomplete', 'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'day_type' => DayType::class,
            'is_rest_day' => 'boolean',
            'scheduled_minutes' => 'integer',
            'is_art82_exempt' => 'boolean',
            'worked_minutes' => 'integer',
            'late_minutes' => 'integer',
            'undertime_minutes' => 'integer',
            'is_incomplete' => 'boolean',
            'computed_at' => 'datetime',
        ];
    }

    public function newUniqueId(): string { return Str::uuid7()->toString(); }

    /** @return array<int,string> */
    public function uniqueIds(): array { return ['id']; }

    /** @return HasMany<DailySummaryLine> */
    public function lines(): HasMany { return $this->hasMany(DailySummaryLine::class, 'summary_id'); }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'employee_id', 'date', 'day_type', 'is_rest_day', 'scheduled_minutes',
                'is_art82_exempt', 'rule_version_id', 'worked_minutes', 'late_minutes',
                'undertime_minutes', 'status', 'is_incomplete', 'computed_at',
            ])
            ->logOnlyDirty()
            ->useLogName('daily_attendance_summary');
    }
}
