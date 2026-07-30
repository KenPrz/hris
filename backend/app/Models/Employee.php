<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

final class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory, HasUuids, LogsActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'hired_at' => 'date',
            'separated_at' => 'date',
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['id'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Office, $this> */
    public function currentOffice(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'current_office_id');
    }

    /** @return BelongsTo<Department, $this> */
    public function currentDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'current_department_id');
    }

    /** The current manager, via the cache. @return BelongsTo<Employee, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'current_reports_to_id');
    }

    /** Direct reports, via the cache. @return HasMany<Employee, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(Employee::class, 'current_reports_to_id');
    }

    /** @return HasMany<EmploymentRecord, $this> */
    public function employmentRecords(): HasMany
    {
        return $this->hasMany(EmploymentRecord::class);
    }

    /** The 1:1 personnel file (M10a). @return HasOne<EmployeeProfile, $this> */
    public function profile(): HasOne
    {
        return $this->hasOne(EmployeeProfile::class);
    }

    /** Composes first/middle/last/suffix, collapsing extra whitespace from a null middle/suffix. */
    protected function fullName(): Attribute
    {
        return Attribute::make(get: fn (): string => trim(preg_replace('/\s+/', ' ', trim("{$this->first_name} {$this->middle_name} {$this->last_name} {$this->name_suffix}"))));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['employee_no', 'first_name', 'middle_name', 'last_name', 'name_suffix', 'organization_id', 'hired_at', 'separated_at'])
            ->useLogName('employee')
            ->logOnlyDirty();
    }
}
