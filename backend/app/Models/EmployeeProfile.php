<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Profile\BloodType;
use App\Domain\Profile\Gender;
use App\Domain\Profile\MaritalStatus;
use Database\Factories\EmployeeProfileFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * The 1:1 personnel file. No HasUuids: the primary key IS employee_id, supplied by the
 * caller, so there is no id to generate.
 */
final class EmployeeProfile extends Model
{
    /** @use HasFactory<EmployeeProfileFactory> */
    use HasFactory, LogsActivity;

    protected $primaryKey = 'employee_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'gender' => Gender::class,
            'marital_status' => MaritalStatus::class,
            'blood_type' => BloodType::class,
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Derived, never stored — a written age is wrong the day after it is written, the same
     * reasoning as Employee::full_name being composed in one place.
     *
     * Computed in the employee's CURRENT OFFICE timezone, not now(). APP_TIMEZONE is UTC by
     * rule (01-architecture.md), so a naive now() rolls an age over up to eight hours early
     * in Manila. Falls back to Asia/Manila when the employee has no office yet — the same
     * default `offices.timezone` carries.
     */
    protected function age(): Attribute
    {
        return Attribute::make(get: function (): ?int {
            if ($this->birth_date === null) {
                return null;
            }

            $timezone = $this->employee?->currentOffice?->timezone ?? 'Asia/Manila';

            // Both sides must be anchored to the SAME timezone before diffing — birth_date's
            // date-only cast carries no zone of its own (it lands at UTC midnight), so mixing
            // it with a Manila "now" produces an eight-hour skew that rounds the year down on
            // the birthday itself. Re-anchor the calendar date to the office zone instead.
            $birthDate = Carbon::parse($this->birth_date->toDateString(), $timezone);

            return (int) $birthDate->diffInYears(Carbon::now($timezone)->startOfDay());
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->useLogName('employee_profile')
            ->logOnlyDirty();
    }
}
