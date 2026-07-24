<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

final class ScheduleOverride extends Model
{
    use HasUuids, LogsActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'is_rest' => 'bool',
            'start_minute' => 'int',
            'end_minute' => 'int',
            'break_minutes' => 'int',
        ];
    }

    public function newUniqueId(): string
    {
        return Str::uuid7()->toString();
    }

    /** @return array<int,string> */
    public function uniqueIds(): array
    {
        return ['id'];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['employee_id', 'date', 'is_rest', 'start_minute', 'end_minute', 'break_minutes'])
            ->logOnlyDirty()
            ->useLogName('schedule_override');
    }
}
