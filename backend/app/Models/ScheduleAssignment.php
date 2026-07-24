<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

final class ScheduleAssignment extends Model
{
    use HasUuids, LogsActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
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

    /** @return BelongsTo<ShiftTemplate, $this> */
    public function shiftTemplate(): BelongsTo
    {
        return $this->belongsTo(ShiftTemplate::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['shift_template_id', 'employee_id', 'department_id', 'effective_from'])
            ->logOnlyDirty()
            ->useLogName('schedule_assignment');
    }
}
